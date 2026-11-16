<?php

declare(strict_types=1);

namespace App\Tests\Controller\Platform;

use App\Factory\OrganizationFactory;
use App\Factory\UserFactory;
use App\Security\ImpersonationListener;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

use function sprintf;

/**
 * « Se connecter en tant que »: a super-admin acting as an association's account.
 *
 * Symfony's switch_user checks no CSRF, so most of what is pinned here is what
 * App\Security\ImpersonationListener and App\Security\Voter\ImpersonationVoter add:
 * POST with a token only, association accounts only, and no password chosen on
 * someone else's behalf.
 */
final class ImpersonationTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    #[Test]
    public function a_super_admin_acts_as_an_association_admin_and_comes_back(): void
    {
        $organization = OrganizationFactory::createOne(['name' => 'Les Jardins']);
        UserFactory::new()->admin($organization)->create(['email' => 'tresorier@jardins.test']);
        $this->client->loginUser(UserFactory::new()->superAdmin()->create(['email' => 'patron@plateforme.test']));

        $this->impersonate('tresorier@jardins.test');

        self::assertResponseRedirects('/admin');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.impersonation-banner', 'tresorier@jardins.test');
        self::assertStringContainsString('Les Jardins', (string) $this->client->getResponse()->getContent());
        self::assertInstanceOf(SwitchUserToken::class, $this->currentToken());
        // EasyAdmin's own exit is a GET link, which the listener would refuse.
        self::assertSelectorNotExists('a[href*="_switch_user=_exit"]');

        $this->client->submit($this->client->getCrawler()->filter('.impersonation-banner')->form());

        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSame('patron@plateforme.test', $this->currentToken()?->getUserIdentifier());
        self::assertNotInstanceOf(SwitchUserToken::class, $this->currentToken());
    }

    #[Test]
    public function the_action_is_offered_for_association_accounts_only(): void
    {
        UserFactory::new()->admin(OrganizationFactory::createOne())->create(['email' => 'tresorier@jardins.test']);
        UserFactory::new()->superAdmin()->create(['email' => 'collegue@plateforme.test']);
        $this->client->loginUser(UserFactory::new()->superAdmin()->create(['email' => 'patron@plateforme.test']));

        $this->client->request(Request::METHOD_GET, '/platform/user');

        self::assertSelectorExists('input[name="_switch_user"][value="tresorier@jardins.test"]');
        self::assertSelectorNotExists('input[name="_switch_user"][value="collegue@plateforme.test"]');
        self::assertSelectorNotExists('input[name="_switch_user"][value="patron@plateforme.test"]');
    }

    #[Test]
    public function another_super_admin_cannot_be_impersonated(): void
    {
        UserFactory::new()->admin(OrganizationFactory::createOne())->create();
        UserFactory::new()->superAdmin()->create(['email' => 'collegue@plateforme.test']);
        $this->client->loginUser(UserFactory::new()->superAdmin()->create());

        $this->postSwitch('collegue@plateforme.test', $this->validToken());

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertNotInstanceOf(SwitchUserToken::class, $this->currentToken());
    }

    #[Test]
    public function a_link_cannot_switch_the_session(): void
    {
        UserFactory::new()->admin(OrganizationFactory::createOne())->create(['email' => 'tresorier@jardins.test']);
        $this->client->loginUser(UserFactory::new()->superAdmin()->create());

        $this->client->request(Request::METHOD_GET, '/platform?_switch_user=tresorier@jardins.test');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertNotInstanceOf(SwitchUserToken::class, $this->currentToken());
    }

    #[Test]
    public function a_post_without_a_valid_token_cannot_switch_the_session(): void
    {
        UserFactory::new()->admin(OrganizationFactory::createOne())->create(['email' => 'tresorier@jardins.test']);
        $this->client->loginUser(UserFactory::new()->superAdmin()->create());

        $this->postSwitch('tresorier@jardins.test', 'forged');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertNotInstanceOf(SwitchUserToken::class, $this->currentToken());
    }

    /**
     * An invited account has no password, which would normally pin its session to
     * « Choisissez votre mot de passe ». A super-admin looking at it must neither be
     * sent there nor be able to set it.
     */
    #[Test]
    public function an_invited_account_can_be_looked_at_but_its_password_is_not_ours_to_choose(): void
    {
        UserFactory::new()->admin(OrganizationFactory::createOne())->invited()->create(['email' => 'nouveau@jardins.test']);
        $this->client->loginUser(UserFactory::new()->superAdmin()->create());

        $this->impersonate('nouveau@jardins.test');
        $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.impersonation-banner');

        $this->client->request(Request::METHOD_GET, '/mot-de-passe');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    private function impersonate(string $email): void
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/platform/user');
        $form = $crawler->filter(sprintf('input[name="_switch_user"][value="%s"]', $email))->closest('form');
        self::assertNotNull($form);

        $this->client->submit($form->form());
    }

    private function postSwitch(string $email, string $token): void
    {
        $this->client->request(Request::METHOD_POST, '/platform', [
            '_switch_user' => $email,
            ImpersonationListener::CSRF_FIELD => $token,
        ]);
    }

    /**
     * A token the session really holds, read off a rendered action — so a refusal in
     * these tests is the rule being tested, not a missing token. Needs at least one
     * association account on the list.
     */
    private function validToken(): string
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/platform/user');

        return (string) $crawler->filter(sprintf('input[name="%s"]', ImpersonationListener::CSRF_FIELD))->first()->attr('value');
    }

    private function currentToken(): ?TokenInterface
    {
        return self::getContainer()->get('security.untracked_token_storage')->getToken();
    }
}
