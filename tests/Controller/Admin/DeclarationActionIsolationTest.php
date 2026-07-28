<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\DeclarationAction;
use App\Entity\Organization;
use App\Factory\DeclarationActionFactory;
use App\Factory\DeclarationFactory;
use App\Factory\OrganizationFactory;
use App\Factory\UserFactory;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * The gate for the one business entity that is NOT TenantAware.
 *
 * DeclarationAction has no organization FK, so App\Doctrine\Filter\OrganizationFilter
 * does not scope it and the back-office does the work by hand in two places:
 * a joined index query builder, and a voter for single-record pages. Two manual
 * mechanisms instead of one automatic one is exactly the sort of thing that rots
 * silently, so every one of them is asserted here.
 *
 * The day DeclarationAction gains TenantAwareTrait, this file can shrink to
 * nothing — the filter would cover all of it.
 */
final class DeclarationActionIsolationTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    /**
     * Mechanism 1: createIndexQueryBuilder() joins the declaration.
     */
    #[Test]
    public function the_list_shows_only_the_current_organizations_actions(): void
    {
        $mine = $this->actionFor($this->organization('les-jardins'), 'Mon action');
        $theirs = $this->actionFor($this->organization('les-voiles'), 'Leur action');
        $this->loginAsAdminOf($mine->getOrganization());

        $this->client->request('GET', '/admin/declaration-action');

        self::assertResponseIsSuccessful();
        $text = $this->client->getCrawler()->filter('body')->text();
        self::assertStringContainsString('Mon action', $text);
        self::assertStringNotContainsString('Leur action', $text);
        self::assertSame('Leur action', $theirs->getTitle());
    }

    /**
     * Mechanism 2: the voter, on a page that never touches the query builder.
     * This is the leak the query builder alone would not close — an admin who was
     * handed another tenant's UUID.
     */
    #[Test]
    public function the_detail_page_of_another_organizations_action_is_forbidden(): void
    {
        $mine = $this->actionFor($this->organization('les-jardins'), 'Mon action');
        $theirs = $this->actionFor($this->organization('les-voiles'), 'Leur action');
        $this->loginAsAdminOf($mine->getOrganization());

        $this->client->request('GET', '/admin/declaration-action/'.$theirs->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function the_detail_page_of_an_own_action_is_allowed(): void
    {
        $mine = $this->actionFor($this->organization('les-jardins'), 'Mon action');
        $this->loginAsAdminOf($mine->getOrganization());

        $this->client->request('GET', '/admin/declaration-action/'.$mine->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Mon action', $this->client->getCrawler()->filter('body')->text());
    }

    /**
     * The state transitions are custom CRUD actions, and they also fetch by id —
     * so they inherit the voter through setEntityPermission(). Without it an admin
     * could decide another tenant's line.
     */
    #[Test]
    public function another_organizations_action_cannot_be_validated(): void
    {
        $mine = $this->actionFor($this->organization('les-jardins'), 'Mon action');
        $theirs = $this->actionFor($this->organization('les-voiles'), 'Leur action');
        $this->loginAsAdminOf($mine->getOrganization());

        $this->client->request('GET', '/admin/declaration-action/'.$theirs->getId()->toRfc4122().'/validate');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /**
     * Declaration IS TenantAware, so this one is the filter's job — asserted so a
     * regression there is not mistaken for the manual scoping above.
     */
    #[Test]
    public function the_declaration_list_is_scoped_by_the_doctrine_filter(): void
    {
        $mine = $this->actionFor($this->organization('les-jardins'), 'Mon action');
        $this->actionFor($this->organization('les-voiles'), 'Leur action');
        $this->loginAsAdminOf($mine->getOrganization());

        $this->client->request('GET', '/admin/declaration');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->client->getCrawler()->filter('table tbody tr'));
    }

    private function organization(string $slug): Organization
    {
        return OrganizationFactory::createOne(['slug' => $slug]);
    }

    private function actionFor(Organization $organization, string $title): DeclarationAction
    {
        return DeclarationActionFactory::createOne([
            'declaration' => DeclarationFactory::new()->for($organization),
            'title' => $title,
        ]);
    }

    private function loginAsAdminOf(Organization $organization): void
    {
        $this->client->loginUser(UserFactory::new()->admin($organization)->create());
    }
}
