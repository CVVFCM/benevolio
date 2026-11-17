<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Organization;
use App\Entity\OrganizationSignature;
use App\Factory\OrganizationFactory;
use App\Tests\Support\LogsInAdmins;
use App\Tests\Support\ReadsAdminPages;
use App\ValueObject\Address;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\DomCrawler\Field\FormField;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

use function file_put_contents;
use function sprintf;
use function str_repeat;
use function sys_get_temp_dir;

/**
 * « Mon association », the one CRUD under /admin on an entity the tenant filter does not
 * cover.
 *
 * That is what most of this class is about. Organization *is* the tenant, so
 * OrganizationFilter has nothing to scope, and the only thing between one association's
 * admin and another association's record is the check in
 * App\Controller\Admin\MyOrganizationCrudController — on the detail page, on the edit page
 * and on the save.
 */
final class MyOrganizationTest extends WebTestCase
{
    use Factories;
    use LogsInAdmins;
    use ReadsAdminPages;
    use ResetDatabase;

    private const string FIXTURE = __DIR__.'/../../../resources/fixtures/organization-signature.png';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    #[Test]
    public function an_admin_sees_their_own_association(): void
    {
        $organization = OrganizationFactory::new()->withCerfaIdentity()->create(['name' => 'Les Jardins Partagés']);
        $this->loginAsAdminOf($organization);

        $this->client->request('GET', self::detailUrl('/admin/my-organization', $organization->getId()));

        self::assertResponseIsSuccessful();
        $text = $this->bodyText();
        self::assertStringContainsString('Les Jardins Partagés', $text);
        self::assertStringContainsString('W083001234', $text);
    }

    /**
     * The association's address ends up in App\ValueObject\Address when a receipt is
     * made, so the form holds it to the same rules — here, five digits for a French
     * postcode — instead of saving what the receipt run will later choke on.
     */
    #[Test]
    public function an_address_the_receipt_could_not_carry_is_refused_on_the_form(): void
    {
        $organization = OrganizationFactory::new()->withCerfaIdentity()->create();
        $this->loginAsAdminOf($organization);

        $crawler = $this->client->request('GET', self::detailUrl('/admin/my-organization', $organization->getId()).'/edit');
        $form = $crawler->selectButton('Enregistrer')->form([
            'Organization[addressPostcode]' => '7500',
            'Organization[addressStreet]' => str_repeat('a', Address::STREET_MAX_LENGTH + 1),
        ]);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSelectorTextContains('body', 'Un code postal français comporte 5 chiffres.');

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $stored = $entityManager->getRepository(Organization::class)->find($organization->getId());
        self::assertSame('08000', $stored?->getAddressPostcode());
    }

    /**
     * Outside France there is no five-digit rule to apply.
     */
    #[Test]
    public function a_foreign_postcode_is_accepted(): void
    {
        $organization = OrganizationFactory::new()->withCerfaIdentity()->create();
        $this->loginAsAdminOf($organization);

        $crawler = $this->client->request('GET', self::detailUrl('/admin/my-organization', $organization->getId()).'/edit');
        $this->client->submit($crawler->selectButton('Enregistrer')->form([
            'Organization[addressPostcode]' => '1000',
            'Organization[addressCity]' => 'Bruxelles',
            'Organization[addressCountry]' => 'BE',
        ]));

        self::assertResponseRedirects();
    }

    /**
     * With no signature the page says what that means for receipts, rather than showing an
     * empty row a treasurer has to interpret.
     */
    #[Test]
    public function an_association_chooses_to_appear_on_the_home_page(): void
    {
        $organization = OrganizationFactory::new()->withCerfaIdentity()->create(['slug' => 'jardins']);
        $this->loginAsAdminOf($organization);

        $crawler = $this->client->request('GET', self::detailUrl('/admin/my-organization', $organization->getId()).'/edit');
        $form = $crawler->selectButton('Enregistrer')->form();
        $checkbox = $form['Organization[listedOnHomepage]'];
        self::assertInstanceOf(ChoiceFormField::class, $checkbox);
        $checkbox->tick();
        $this->client->submit($form);

        self::assertResponseRedirects();
        $this->client->request('GET', '/');
        self::assertSelectorExists('a[href="/a/jardins/declaration"]');
    }

    #[Test]
    public function an_association_without_a_signature_is_told_what_that_means(): void
    {
        $organization = OrganizationFactory::createOne();
        $this->loginAsAdminOf($organization);

        $this->client->request('GET', self::detailUrl('/admin/my-organization', $organization->getId()));

        self::assertStringContainsString(
            'Aucune signature enregistrée',
            $this->bodyText(),
        );
    }

    #[Test]
    public function the_bare_url_lands_on_the_admins_own_association(): void
    {
        $organization = OrganizationFactory::createOne();
        $this->loginAsAdminOf($organization);

        $this->client->request('GET', '/admin/my-organization');
        $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString($organization->getId()->toRfc4122(), $this->client->getRequest()->getUri());
    }

    #[Test]
    public function another_associations_record_is_not_readable(): void
    {
        $theirs = OrganizationFactory::createOne();
        $this->loginAsAdminOf(OrganizationFactory::createOne());

        // Nothing filters Organization, so this is the controller's own check being tested.
        // The identity map would otherwise hand back a row the SQL never had to return.
        $id = $theirs->getId();
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $this->client->request('GET', '/admin/my-organization/'.$id->toRfc4122());

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function another_associations_record_is_not_editable(): void
    {
        $theirs = OrganizationFactory::createOne();
        $this->loginAsAdminOf(OrganizationFactory::createOne());

        $id = $theirs->getId();
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $this->client->request('GET', '/admin/my-organization/'.$id->toRfc4122().'/edit');

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function an_association_that_does_not_exist_is_a_404(): void
    {
        $this->loginAsAdminOf(OrganizationFactory::createOne());

        $this->client->request('GET', '/admin/my-organization/'.Uuid::v7()->toRfc4122());

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * The slug addresses the public volunteer URLs, so an association cannot change its
     * own: the links already handed to volunteers would stop working.
     */
    #[Test]
    public function the_slug_cannot_be_changed_from_here(): void
    {
        $organization = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $this->loginAsAdminOf($organization);

        $crawler = $this->client->request('GET', self::detailUrl('/admin/my-organization', $organization->getId()).'/edit');
        self::assertResponseIsSuccessful();

        $slug = $crawler->filter('input[name$="[slug]"]');
        self::assertCount(1, $slug);
        self::assertNotNull($slug->attr('disabled'));
    }

    #[Test]
    public function uploading_a_signature_stores_it(): void
    {
        $organization = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $this->loginAsAdminOf($organization);

        $crawler = $this->client->request('GET', self::detailUrl('/admin/my-organization', $organization->getId()).'/edit');
        $form = $crawler->selectButton('Enregistrer')->form();
        $this->field($form, 'signatureUpload', FileFormField::class)->upload(self::FIXTURE);
        $this->client->submit($form);

        self::assertResponseRedirects();

        // The page after the upload, which is where this used to break: Organization is
        // reachable from the security token, ContextListener serializes that token into the
        // session on every response, and an UploadedFile left on the entity made every
        // subsequent response a 500 ("Serialization of 'UploadedFile' is not allowed").
        //
        // Twice, because « Enregistrer » returns to the index, and the index redirects to
        // this association's own page — there being no list to return to.
        $this->client->followRedirect();
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            $organization->getId()->toRfc4122(),
            $this->client->getRequest()->getUri(),
        );

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $reloaded = $entityManager->getRepository(Organization::class)->find($organization->getId());
        self::assertNotNull($reloaded);
        self::assertInstanceOf(OrganizationSignature::class, $reloaded->getSignature());
        self::assertSame('image/png', $reloaded->getSignature()->getMimeType());
    }

    /**
     * Something that is not an image is refused, and nothing is stored.
     *
     * The check lives in Organization::validateSignature() rather than on the upload, for
     * the serialization reason above — so it is worth proving through the form that it
     * still catches what #[Assert\Image] would have.
     */
    #[Test]
    public function a_file_that_is_not_an_image_is_refused(): void
    {
        $organization = OrganizationFactory::createOne(['slug' => 'les-jardins']);
        $this->loginAsAdminOf($organization);

        $notAnImage = sys_get_temp_dir().'/not-an-image.png';
        file_put_contents($notAnImage, 'CECI N\'EST PAS UNE IMAGE');

        $crawler = $this->client->request('GET', self::detailUrl('/admin/my-organization', $organization->getId()).'/edit');
        $form = $crawler->selectButton('Enregistrer')->form();
        $this->field($form, 'signatureUpload', FileFormField::class)->upload($notAnImage);
        $this->client->submit($form);

        self::assertResponseIsUnprocessable();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        self::assertCount(0, $entityManager->getRepository(OrganizationSignature::class)->findAll());
    }

    #[Test]
    public function ticking_the_box_removes_the_signature(): void
    {
        $organization = OrganizationFactory::new()->withSignature()->create(['slug' => 'les-jardins']);
        $this->loginAsAdminOf($organization);

        $crawler = $this->client->request('GET', self::detailUrl('/admin/my-organization', $organization->getId()).'/edit');
        $form = $crawler->selectButton('Enregistrer')->form();
        $this->field($form, 'signatureCleared', ChoiceFormField::class)->tick();
        $this->client->submit($form);

        self::assertResponseRedirects();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $reloaded = $entityManager->getRepository(Organization::class)->find($organization->getId());
        self::assertNotNull($reloaded);
        self::assertNull($reloaded->getSignature());
        // orphanRemoval, so the row is gone rather than merely detached.
        self::assertCount(0, $entityManager->getRepository(OrganizationSignature::class)->findAll());
    }

    /**
     * One form field, narrowed. Form::offsetGet is typed as "field, or an array of fields",
     * so PHPStan cannot know that a file input is a single FileFormField.
     *
     * @template T of FormField
     *
     * @param class-string<T> $type
     *
     * @return T
     */
    private function field(Form $form, string $name, string $type): FormField
    {
        $field = $form[sprintf('%s[%s]', $form->getName(), $name)];
        self::assertInstanceOf($type, $field);

        return $field;
    }
}
