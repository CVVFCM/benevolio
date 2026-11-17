<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Task;
use App\Factory\DeclarationActionFactory;
use App\Factory\OrganizationFactory;
use App\Factory\TaskFactory;
use App\Tests\Support\LogsInAdmins;
use App\Tests\Support\ReadsAdminPages;
use App\Tests\Support\ReloadsEntities;
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
final class TaskAdminTest extends WebTestCase
{
    use Factories;
    use LogsInAdmins;
    use ReadsAdminPages;
    use ReloadsEntities;
    use ResetDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    #[Test]
    public function the_list_shows_only_this_associations_tasks(): void
    {
        $mine = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $theirs = OrganizationFactory::createOne(['slug' => 'les-voiles']);
        TaskFactory::new()->for($mine)->create(['name' => 'Chantier naval']);
        TaskFactory::new()->for($theirs)->create(['name' => 'Secret des voiles']);
        $this->loginAsAdminOf($mine);

        $this->client->request('GET', '/admin/task');

        self::assertResponseIsSuccessful();
        $text = $this->bodyText();
        self::assertStringContainsString('Chantier naval', $text);
        self::assertStringNotContainsString('Secret des voiles', $text);
    }

    /**
     * The refusal has to arrive as a sentence a treasurer can act on, not as
     * EasyAdmin's developer-facing 409 page.
     */
    #[Test]
    public function deleting_a_task_in_use_is_refused_with_a_readable_message(): void
    {
        $organization = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $action = DeclarationActionFactory::new()->for($organization)->create();
        $typeInUse = $action->getTask();
        $this->loginAsAdminOf($organization);

        $this->deleteTask($typeInUse);

        $text = $this->bodyText();
        self::assertStringContainsString('ne peut pas être supprimé', $text);
        self::assertStringContainsString('Proposé aux bénévoles', $text);

        // And it is still there.
        $this->entityManager()->clear();
        self::assertNotNull($this->entityManager()->getRepository(Task::class)->find($typeInUse->getId()));
    }

    #[Test]
    public function an_unused_task_can_be_deleted(): void
    {
        $organization = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $unused = TaskFactory::new()->for($organization)->create(['name' => 'Jamais utilisé']);
        $this->loginAsAdminOf($organization);

        $this->deleteTask($unused);

        $this->entityManager()->clear();
        self::assertNull($this->entityManager()->getRepository(Task::class)->find($unused->getId()));
    }

    /**
     * Goes through the real delete form, CSRF token and all — the point is to
     * exercise the controller, not to call deleteEntity() directly.
     */
    private function deleteTask(Task $task): void
    {
        $crawler = $this->client->request(
            'GET',
            '/admin/task/'.$task->getId()->toRfc4122(),
        );
        self::assertResponseIsSuccessful();

        // EasyAdmin keeps one hidden confirmation form on the page and repoints it
        // at the right URL in JavaScript, so the token is not inside a per-action
        // form to select by action.
        $token = self::deleteTokenIn($crawler);
        self::assertNotSame('', $token);

        $this->client->request(
            'POST',
            '/admin/task/'.$task->getId()->toRfc4122().'/delete',
            ['token' => $token],
        );

        if ($this->client->getResponse()->isRedirect()) {
            $this->client->followRedirect();
        }
    }
}
