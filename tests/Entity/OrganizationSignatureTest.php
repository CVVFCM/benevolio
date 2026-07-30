<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Organization;
use App\Entity\OrganizationSignature;
use App\Factory\OrganizationFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

use function copy;
use function count;
use function file_get_contents;
use function file_put_contents;
use function str_repeat;
use function strlen;
use function sys_get_temp_dir;
use function uniqid;

/**
 * The signature: how a file becomes one, and what happens to the one it replaces.
 *
 * The upload path is the interesting part, because it is where a request object turns into
 * something stored — App\Factory\OrganizationFactory deliberately bypasses it, so nothing
 * else covers it.
 */
final class OrganizationSignatureTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private const string FIXTURE = __DIR__.'/../../resources/fixtures/organization-signature.png';

    #[Test]
    public function an_uploaded_image_becomes_the_stored_signature(): void
    {
        $organization = new Organization();
        $organization->setSignatureUpload($this->upload());

        $signature = $organization->getSignature();
        self::assertInstanceOf(OrganizationSignature::class, $signature);
        self::assertSame('image/png', $signature->getMimeType());
        self::assertSame('signature.png', $signature->getOriginalFilename());
        self::assertStringStartsWith('data:image/png;base64,iVBOR', (string) $organization->getSignatureDataUri());
    }

    /**
     * An untouched file input submits null, which means "keep it" — not "delete it".
     */
    #[Test]
    public function submitting_no_file_leaves_the_signature_alone(): void
    {
        $organization = new Organization();
        $organization->setSignatureUpload($this->upload());
        $stored = $organization->getSignature();

        $organization->setSignatureUpload(null);

        self::assertSame($stored, $organization->getSignature());
    }

    #[Test]
    public function clearing_removes_the_signature(): void
    {
        $organization = new Organization();
        $organization->setSignatureUpload($this->upload());

        $organization->setSignatureCleared(true);

        self::assertNull($organization->getSignature());
        self::assertNull($organization->getSignatureDataUri());
    }

    /**
     * Replacing a signature must not leave the old row in the table: that is what
     * orphanRemoval on the association is for, and nothing else would notice it missing.
     */
    #[Test]
    public function replacing_a_signature_deletes_the_previous_row(): void
    {
        $organization = OrganizationFactory::new()->withSignature()->create();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $organization->setSignatureUpload($this->upload());
        $entityManager->flush();
        $entityManager->clear();

        self::assertCount(1, $entityManager->getRepository(OrganizationSignature::class)->findAll());
    }

    /**
     * The size cap is enforced by validation, not by the column: 1 MB, because PHP's own
     * upload_max_filesize is 2M by default and nothing raises it in the image.
     */
    #[Test]
    public function an_oversized_file_is_refused(): void
    {
        $organization = new Organization();
        $organization->setName('Les Jardins');
        $organization->setSlug('les-jardins');
        // Not an image at all, and over the cap: both constraints should have something
        // to say, and either refusal is the right outcome.
        $organization->setSignatureUpload($this->upload(contents: str_repeat('x', OrganizationSignature::MAX_FILE_SIZE + 1)));

        $violations = self::getContainer()->get(ValidatorInterface::class)->validate($organization);

        self::assertGreaterThan(0, count($violations));
    }

    /**
     * Guards the fixture the rest of this class leans on.
     */
    #[Test]
    public function the_shipped_fixture_is_a_png_within_the_cap(): void
    {
        $contents = file_get_contents(self::FIXTURE);

        self::assertNotFalse($contents);
        self::assertStringStartsWith("\x89PNG", $contents);
        self::assertLessThan(OrganizationSignature::MAX_FILE_SIZE, strlen($contents));
    }

    private function upload(?string $contents = null): UploadedFile
    {
        // A real file on disk, because UploadedFile reads the path: getMimeType() guesses
        // from the contents, which is the behaviour being relied on.
        $path = sys_get_temp_dir().'/'.uniqid('signature-', true).'.png';

        if (null === $contents) {
            copy(self::FIXTURE, $path);
        } else {
            file_put_contents($path, $contents);
        }

        return new UploadedFile($path, 'signature.png', test: true);
    }
}
