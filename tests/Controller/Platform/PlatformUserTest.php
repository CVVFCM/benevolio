<?php

declare(strict_types=1);

namespace App\Tests\Controller\Platform;

use App\Entity\Organization;
use App\Entity\User;
use App\Factory\OrganizationFactory;
use App\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * The platform's account CRUD: no password typed for anyone, an invitation instead,
 * and the first account of an association made its owner.
 */
final class PlatformUserTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    #[Test]
    public function creating_an_association_admin_invites_them_and_makes_the_first_one_owner(): void
    {
        $organization = OrganizationFactory::createOne(['name' => 'Les Jardins']);
        $this->client->loginUser(UserFactory::new()->superAdmin()->create());

        $crawler = $this->client->request(Request::METHOD_GET, '/platform/user/new');
        self::assertSelectorNotExists('input[type="password"]');

        $this->submitNewUser($crawler, 'premier@jardins.test', ['ROLE_ADMIN'], $organization);

        self::assertResponseRedirects();

        $user = $this->findUser('premier@jardins.test');
        self::assertTrue($user->isOwner());
        self::assertFalse($user->hasPassword());

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(TemplatedEmail::class, $email);
        self::assertEmailAddressContains($email, 'To', 'premier@jardins.test');
    }

    #[Test]
    public function a_second_owner_is_refused(): void
    {
        $organization = OrganizationFactory::createOne();
        UserFactory::new()->owner($organization)->create(['email' => 'premier@jardins.test']);
        $this->client->loginUser(UserFactory::new()->superAdmin()->create());

        $crawler = $this->client->request(Request::METHOD_GET, '/platform/user/new');
        $this->submitNewUser($crawler, 'second@jardins.test', ['ROLE_ADMIN', 'ROLE_OWNER'], $organization);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSelectorTextContains('body', 'premier@jardins.test');
        self::assertEmailCount(0);
    }

    /**
     * Raw values rather than DomCrawler fields: the organization is an autocomplete
     * select whose options are fetched by JavaScript, so the crawler sees none.
     *
     * @param list<string> $roles
     */
    private function submitNewUser(Crawler $crawler, string $email, array $roles, Organization $organization): void
    {
        $form = $crawler->selectButton('Créer')->form();
        $values = ['User' => [
            'email' => $email,
            'roles' => $roles,
            // The autocomplete widget nests its value one level down.
            'organization' => ['autocomplete' => $organization->getId()->toRfc4122()],
            '_token' => $form->getValues()['User[_token]'] ?? '',
        ]];

        $this->client->request($form->getMethod(), $form->getUri(), $values);
    }

    private function findUser(string $email): User
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $user = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user);

        return $user;
    }
}
