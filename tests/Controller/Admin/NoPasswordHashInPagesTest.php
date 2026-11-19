<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\User;
use App\Factory\OrganizationFactory;
use App\Factory\UserFactory;
use App\Tests\Support\LogsInAdmins;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

use function dirname;
use function sprintf;

/**
 * No page of either back office carries a password hash, anywhere in its source.
 *
 * The « État » column was once a field on `password` with a formatValue(): the cell read
 * « Actif », and EasyAdmin's text template printed the field's raw value — the hash — in
 * the `title` attribute beside it. So these tests read the whole response, not its text.
 */
final class NoPasswordHashInPagesTest extends WebTestCase
{
    use Factories;
    use LogsInAdmins;
    use ResetDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    #[Test]
    public function an_association_admin_never_receives_a_colleagues_hash(): void
    {
        $organization = OrganizationFactory::createOne();
        $owner = UserFactory::new()->owner($organization)->create(['email' => 'chef@asso.test']);
        $admin = UserFactory::new()->admin($organization)->create(['email' => 'collegue@asso.test']);
        $this->loginAs($admin);

        foreach (['/admin/user', sprintf('/admin/user/%s', $owner->getId())] as $url) {
            $this->client->request(Request::METHOD_GET, $url);

            self::assertResponseIsSuccessful();
            $this->assertNoHashOf($url, $owner, $admin);
            self::assertStringContainsString('Actif', (string) $this->client->getResponse()->getContent());
        }
    }

    #[Test]
    public function the_platform_never_prints_a_hash_either(): void
    {
        $organization = OrganizationFactory::createOne();
        $owner = UserFactory::new()->owner($organization)->create(['email' => 'chef@asso.test']);
        $superAdmin = $this->loginAsSuperAdmin();

        foreach (['/platform/user', sprintf('/platform/user/%s', $owner->getId())] as $url) {
            $this->client->request(Request::METHOD_GET, $url);

            self::assertResponseIsSuccessful();
            $this->assertNoHashOf($url, $owner, $superAdmin);
            self::assertStringContainsString('Actif', (string) $this->client->getResponse()->getContent());
        }
    }

    /**
     * The mistake is one line long, so it is refused at the source too: a field bound to
     * `password` prints the hash whatever its formatValue() returns.
     */
    #[Test]
    #[DataProvider('crudControllers')]
    public function no_crud_field_is_bound_to_the_password(string $path): void
    {
        self::assertDoesNotMatchRegularExpression(
            '/::new\(\s*[\'"](?:password|plainPassword)[\'"]/',
            (string) file_get_contents($path),
            'A field on the password prints its hash in the page: read User::hasPassword() through a template instead.',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function crudControllers(): iterable
    {
        $files = new Finder()->files()->name('*CrudController.php')->in(dirname(__DIR__, 3).'/src/Controller');

        foreach ($files as $file) {
            yield $file->getRelativePathname() => [$file->getPathname()];
        }
    }

    private function assertNoHashOf(string $url, User ...$users): void
    {
        $source = (string) $this->client->getResponse()->getContent();

        foreach ($users as $user) {
            $hash = (string) $user->getPassword();

            self::assertNotSame('', $hash, 'The account needs a password for this test to mean anything.');
            self::assertStringNotContainsString($hash, $source, sprintf('%s carries the hash of %s.', $url, $user->getEmail()));
            // Escaped for an attribute, a `$` is unchanged — but check the encoded form too.
            self::assertStringNotContainsString(htmlspecialchars($hash), $source);
        }
    }
}
