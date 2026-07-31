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

use function base64_decode;
use function copy;
use function count;
use function file_get_contents;
use function file_put_contents;
use function getimagesizefromstring;
use function imagecolorallocate;
use function imagecreatetruecolor;
use function imagefill;
use function imagepng;
use function ob_get_clean;
use function ob_start;
use function str_repeat;
use function strlen;
use function sys_get_temp_dir;
use function uniqid;

use const UPLOAD_ERR_INI_SIZE;
use const UPLOAD_ERR_PARTIAL;

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
     * Something that is not an image is refused, with a message on the field.
     *
     * The refusal happens while the form binds, in the setter, so it must not throw: it is
     * recorded and turned into a violation. A PDF renamed `.png` would otherwise reach
     * Gotenberg hours later, on a receipt already promised to a volunteer.
     */
    #[Test]
    public function something_that_is_not_an_image_is_refused(): void
    {
        $organization = new Organization();
        $organization->setName('Les Jardins');
        $organization->setSlug('les-jardins');
        $organization->setSignatureUpload($this->upload(contents: str_repeat('x', 2048)));

        $violations = self::getContainer()->get(ValidatorInterface::class)->validate($organization);

        self::assertNull($organization->getSignature());
        self::assertGreaterThan(0, count($violations));
        self::assertStringContainsString('image', (string) $violations->get(0)->getMessage());
    }

    /**
     * A scan larger than the CERFA can use is **accepted and scaled down**, not refused.
     *
     * That is the whole point of accepting 16 MB: the treasurer drops in whatever their
     * scanner produced, and what reaches the database is something a receipt and an email can
     * carry. Stored as-is, a 16 MB signature would be 21 MB of base64 in the row, the same
     * again in every overlay, and an attachment past what most relays accept.
     */
    #[Test]
    public function a_large_image_is_scaled_down_before_it_is_stored(): void
    {
        $organization = new Organization();
        $organization->setSignatureUpload($this->upload(contents: self::png(2400, 1200)));

        $signature = $organization->getSignature();
        self::assertInstanceOf(OrganizationSignature::class, $signature);

        $stored = base64_decode($signature->getBase64(), true);
        self::assertNotFalse($stored);
        $size = getimagesizefromstring($stored);
        self::assertNotFalse($size);

        // Long side at the ceiling, aspect ratio kept, PNG so transparency survives.
        //
        // Dimensions and not bytes: for a real scan the file shrinks by orders of magnitude,
        // but these fixtures are one flat colour, and PNG happens to compress 2400 × 1200 of it
        // tighter than 1000 × 500. Asserting "smaller file" would be asserting a property of
        // the fixture, not of the resizing.
        self::assertSame(OrganizationSignature::STORED_MAX_EDGE_PX, $size[0]);
        self::assertSame(500, $size[1]);
        self::assertSame('image/png', $signature->getMimeType());
    }

    /**
     * An image already small enough is kept byte for byte — re-encoding costs quality for
     * nothing.
     */
    #[Test]
    public function a_small_image_is_stored_untouched(): void
    {
        $bytes = self::png(600, 400);
        $organization = new Organization();
        $organization->setSignatureUpload($this->upload(contents: $bytes));

        $signature = $organization->getSignature();
        self::assertInstanceOf(OrganizationSignature::class, $signature);
        self::assertSame($bytes, base64_decode($signature->getBase64(), true));
    }

    /**
     * Refused on PIXELS, which the byte cap does not cover.
     *
     * PNG compresses flat artwork enormously, so a few-kilobyte file can hold an enormous
     * canvas — and GD allocates four bytes per pixel the moment it decodes one. A check after
     * the decode would come too late to prevent the fatal error it causes.
     */
    #[Test]
    public function an_image_with_too_many_pixels_is_refused(): void
    {
        $organization = new Organization();
        $organization->setName('Les Jardins');
        $organization->setSlug('les-jardins');

        // 5 000 × 4 000 = 20 megapixels, past the 16 this will decode.
        $organization->setSignatureUpload($this->upload(contents: self::png(5000, 4000)));

        $violations = self::getContainer()->get(ValidatorInterface::class)->validate($organization);

        self::assertNull($organization->getSignature());
        self::assertGreaterThan(0, count($violations));
        self::assertStringContainsString('pixels', (string) $violations->get(0)->getMessage());
    }

    /**
     * An upload PHP threw away must not take the request with it.
     *
     * When a file exceeds `upload_max_filesize`, or the temporary directory is unwritable, PHP
     * discards it and hands PHP-land an entry whose `tmp_name` is empty. Symfony's FileType
     * adds a form error for that — and **does not clear the data** (it only nulls values that
     * are not file uploads at all), so the invalid UploadedFile still reaches this setter.
     * Calling getMimeType() on it threw `The "" file does not exist or is not readable.` and
     * turned a too-large signature into a 500.
     */
    #[Test]
    public function an_upload_php_rejected_is_ignored_rather_than_fatal(): void
    {
        $organization = new Organization();

        $organization->setSignatureUpload(new UploadedFile(
            '',
            'signature.png',
            'image/png',
            UPLOAD_ERR_INI_SIZE,
            test: true,
        ));

        // Nothing stored, nothing thrown: FileType has already put "the file was too large" on
        // the field, which is the message the treasurer needs.
        self::assertNull($organization->getSignature());
    }

    /**
     * The same for an upload that failed for any other reason PHP reports.
     */
    #[Test]
    public function a_partial_upload_is_ignored_too(): void
    {
        $organization = new Organization();
        $organization->setSignatureUpload($this->upload());
        $stored = $organization->getSignature();

        $organization->setSignatureUpload(new UploadedFile('', 'signature.png', 'image/png', UPLOAD_ERR_PARTIAL, test: true));

        // And the signature already stored is left alone.
        self::assertSame($stored, $organization->getSignature());
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

    /**
     * A real PNG of the given dimensions — GD is what reads these, so a hand-made header
     * would prove nothing. One flat colour, so even 20 megapixels weighs a few kilobytes.
     */
    /**
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    private static function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);

        imagefill($image, 0, 0, (int) imagecolorallocate($image, 240, 240, 240));

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
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
