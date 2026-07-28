<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\EventType;
use App\Factory\DeclarationActionFactory;
use App\Factory\EventTypeFactory;
use App\Factory\OrganizationFactory;
use App\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * The one back-office section where an admin genuinely creates rows.
 *
 * The interesting behaviour is what happens at the edges: a type in use cannot be
 * deleted (the database refuses, and the admin must see a sentence rather than a
 * 500), and one association never sees another's list.
 */
final class EventTypeAdminTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    #[Test]
    public function the_list_shows_only_this_associations_types(): void
    {
        $mine = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $theirs = OrganizationFactory::createOne(['slug' => 'les-voiles']);
        EventTypeFactory::new()->for($mine)->create(['name' => 'Chantier naval']);
        EventTypeFactory::new()->for($theirs)->create(['name' => 'Secret des voiles']);
        $this->client->loginUser(UserFactory::new()->admin($mine)->create());

        $this->client->request('GET', '/admin/event-type');

        self::assertResponseIsSuccessful();
        $text = $this->client->getCrawler()->filter('body')->text();
        self::assertStringContainsString('Chantier naval', $text);
        self::assertStringNotContainsString('Secret des voiles', $text);
    }

    /**
     * The refusal has to arrive as a sentence a treasurer can act on, not as
     * EasyAdmin's developer-facing 409 page.
     */
    #[Test]
    public function deleting_a_type_in_use_is_refused_with_a_readable_message(): void
    {
        $organization = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $action = DeclarationActionFactory::new()->for($organization)->create();
        $typeInUse = $action->getEventType();
        $this->client->loginUser(UserFactory::new()->admin($organization)->create());

        $this->deleteEventType($typeInUse);

        $text = $this->client->getCrawler()->filter('body')->text();
        self::assertStringContainsString('ne peut pas être supprimé', $text);
        self::assertStringContainsString('Proposé aux bénévoles', $text);

        // And it is still there.
        $this->entityManager()->clear();
        self::assertNotNull($this->entityManager()->getRepository(EventType::class)->find($typeInUse->getId()));
    }

    #[Test]
    public function an_unused_type_can_be_deleted(): void
    {
        $organization = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $unused = EventTypeFactory::new()->for($organization)->create(['name' => 'Jamais utilisé']);
        $this->client->loginUser(UserFactory::new()->admin($organization)->create());

        $this->deleteEventType($unused);

        $this->entityManager()->clear();
        self::assertNull($this->entityManager()->getRepository(EventType::class)->find($unused->getId()));
    }

    /**
     * Goes through the real delete form, CSRF token and all — the point is to
     * exercise the controller, not to call deleteEntity() directly.
     */
    private function deleteEventType(EventType $eventType): void
    {
        $crawler = $this->client->request(
            'GET',
            '/admin/event-type/'.$eventType->getId()->toRfc4122(),
        );
        self::assertResponseIsSuccessful();

        // EasyAdmin keeps one hidden confirmation form on the page and repoints it
        // at the right URL in JavaScript, so the token is not inside a per-action
        // form to select by action.
        $token = $crawler
            ->filter('#action-confirmation-form input[name="token"]')
            ->attr('value');
        self::assertNotNull($token);

        $this->client->request(
            'POST',
            '/admin/event-type/'.$eventType->getId()->toRfc4122().'/delete',
            ['token' => $token],
        );

        if ($this->client->getResponse()->isRedirect()) {
            $this->client->followRedirect();
        }
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
