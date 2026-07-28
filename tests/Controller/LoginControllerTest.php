<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Factory\OrganizationFactory;
use App\Factory\UserFactory;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * NOTE on ordering: the client is created in setUp(), before any factory runs —
 * see App\Tests\Controller\DashboardAccessTest.
 */
final class LoginControllerTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;
    private const string PASSWORD = 'a-long-enough-password';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    #[Test]
    public function it_renders_the_login_form(): void
    {
        $crawler = $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form input[name="_username"]');
        self::assertSelectorExists('form input[name="_password"]');
        // The firewall requires the "authenticate" CSRF token.
        self::assertSelectorExists('form input[name="_csrf_token"]');
        self::assertSame('Connexion', $crawler->filter('form h2')->text());
    }

    #[Test]
    public function it_rejects_bad_credentials_with_a401_and_an_error_message(): void
    {
        $this->createAdmin();

        $this->client->request('GET', '/login');
        $this->client->submitForm('Se connecter', [
            '_username' => 'admin@example.test',
            '_password' => 'wrong-password',
        ]);
        $this->client->followRedirect();

        // The controller sets the status by hand — there is no AbstractController
        // to do it, and a rejected credential must not answer 200.
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSelectorExists('[role="alert"]');
    }

    #[Test]
    public function it_logs_an_organization_admin_in_and_resumes_the_requested_url(): void
    {
        $this->createAdmin();

        $this->client->request('GET', '/admin');
        // Anonymous access to the backoffice sends you to the login form.
        self::assertResponseRedirects('http://localhost/login');

        $this->client->followRedirect();
        $this->client->submitForm('Se connecter', [
            '_username' => 'admin@example.test',
            '_password' => self::PASSWORD,
        ]);

        // Successful authentication resumes the originally requested URL.
        self::assertResponseRedirects('http://localhost/admin');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    private function createAdmin(): void
    {
        UserFactory::new()
            ->admin(OrganizationFactory::createOne())
            ->withPassword(self::PASSWORD)
            ->create(['email' => 'admin@example.test']);
    }
}
