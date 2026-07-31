<?php

declare(strict_types=1);

namespace App\Tests\Organization;

use App\Entity\OrganizationSignature;
use App\Exception\SignatureImageException;
use App\Organization\SignatureFactory;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use function base64_decode;
use function copy;
use function file_put_contents;
use function getimagesizefromstring;
use function imagecolorallocate;
use function imagecreatetruecolor;
use function imagefill;
use function imagefilledrectangle;
use function imagejpeg;
use function imagepng;
use function ob_get_clean;
use function ob_start;
use function str_repeat;
use function strlen;
use function sys_get_temp_dir;
use function uniqid;

use const UPLOAD_ERR_INI_SIZE;

/**
 * Turning an uploaded file into a stored signature.
 *
 * The one rule worth holding: **what is accepted is not what is stored**. 16 MB comes in so a
 * treasurer never has to think about it; what lands in the database is something a receipt PDF
 * and an email attachment can carry.
 *
 * Runs against the real Imagine/GD driver — a mocked ImagineInterface would assert that this
 * class calls methods, which is not the property anyone cares about.
 */
final class SignatureFactoryTest extends KernelTestCase
{
    private const string FIXTURE = __DIR__.'/../../resources/fixtures/organization-signature.png';

    private SignatureFactory $factory;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->factory = self::getContainer()->get(SignatureFactory::class);
    }

    #[Test]
    public function it_stores_an_uploaded_image(): void
    {
        $signature = $this->factory->fromUploadedFile($this->upload());

        self::assertSame('image/png', $signature->getMimeType());
        self::assertSame('signature.png', $signature->getOriginalFilename());
        self::assertStringStartsWith('data:image/png;base64,iVBOR', $signature->toDataUri());
    }

    /**
     * A scan larger than the CERFA can use is **scaled down**, not refused. That is the whole
     * point of accepting 16 MB.
     */
    #[Test]
    public function a_large_image_is_scaled_down(): void
    {
        $signature = $this->factory->fromBytes(self::png(2400, 1200));

        $stored = base64_decode($signature->getBase64(), true);
        self::assertNotFalse($stored);
        $size = getimagesizefromstring($stored);
        self::assertNotFalse($size);

        // Long side at the ceiling, aspect ratio kept, PNG so transparency survives.
        //
        // Dimensions and not bytes: a real scan shrinks by orders of magnitude, but these
        // fixtures are one flat colour and PNG compresses 2400 × 1200 of it *tighter* than
        // 1000 × 500 — asserting "smaller file" would assert a property of the fixture.
        self::assertSame(SignatureFactory::STORED_MAX_EDGE_PX, $size[0]);
        self::assertSame(500, $size[1]);
        // PNG in, PNG out: transparency has to survive, or the form's own printed rules end up
        // behind a white rectangle.
        self::assertSame('image/png', $signature->getMimeType());
    }

    /**
     * A JPEG stays a JPEG, and that is deliberate.
     *
     * Re-encoding a photograph as PNG is what this did first: a 3 000 × 3 000 photo of a signed
     * page came out at ~650 KB as PNG against ~120 KB as JPEG, and every receipt would carry the
     * difference. A JPEG has no alpha to protect, so its own format is the cheap, lossless-enough
     * choice.
     */
    #[Test]
    public function a_large_jpeg_stays_a_jpeg_and_gets_much_smaller(): void
    {
        $source = self::jpeg(3000, 3000);

        $signature = $this->factory->fromBytes($source);

        $stored = base64_decode($signature->getBase64(), true);
        self::assertNotFalse($stored);
        $size = getimagesizefromstring($stored);
        self::assertNotFalse($size);

        self::assertSame('image/jpeg', $signature->getMimeType());
        self::assertSame(SignatureFactory::STORED_MAX_EDGE_PX, $size[0]);
        // A photograph really does shrink here, unlike the flat-colour fixtures above.
        self::assertLessThan(strlen($source) / 2, strlen($stored));
    }

    /**
     * An image already small enough is kept byte for byte — re-encoding costs quality for
     * nothing.
     */
    #[Test]
    public function a_small_image_is_kept_untouched(): void
    {
        $bytes = self::png(600, 400);

        $signature = $this->factory->fromBytes($bytes);

        self::assertSame($bytes, base64_decode($signature->getBase64(), true));
    }

    /**
     * Refused on PIXELS, which the byte cap does not cover: PNG compresses flat artwork so well
     * that a few-kilobyte file can hold an enormous canvas, and a decoder allocates four bytes
     * per pixel the moment it reads one.
     */
    #[Test]
    public function too_many_pixels_is_refused_before_decoding(): void
    {
        // 5 000 × 4 000 = 20 megapixels, past the 16 this will decode.
        $this->expectException(SignatureImageException::class);

        try {
            $this->factory->fromBytes(self::png(5000, 4000));
        } catch (SignatureImageException $e) {
            self::assertStringContainsString('pixels', $e->userMessage);

            throw $e;
        }
    }

    #[Test]
    public function something_that_is_not_an_image_is_refused(): void
    {
        try {
            $this->factory->fromBytes(str_repeat('x', 2048));
            self::fail('Expected the factory to refuse bytes that are not an image.');
        } catch (SignatureImageException $e) {
            self::assertStringContainsString('image', $e->userMessage);
        }
    }

    /**
     * An upload PHP threw away arrives with an EMPTY path — over `upload_max_filesize`, an
     * unwritable temp directory, a transfer cut short. Reading it throws from inside the MIME
     * guesser (*"The "" file does not exist or is not readable."*), which used to be a 500.
     *
     * Symfony's FileType adds its own error for this and **does not clear the data**, so the
     * broken file still arrives here and this has to check for itself.
     */
    #[Test]
    public function an_upload_php_rejected_is_refused_not_read(): void
    {
        $file = new UploadedFile('', 'signature.png', 'image/png', UPLOAD_ERR_INI_SIZE, test: true);

        try {
            $this->factory->fromUploadedFile($file);
            self::fail('Expected the factory to refuse an upload PHP had already discarded.');
        } catch (SignatureImageException $e) {
            self::assertStringContainsString('16 Mo', $e->userMessage);
        }
    }

    #[Test]
    public function the_shipped_fixture_is_within_the_accepted_size(): void
    {
        $signature = $this->factory->fromUploadedFile($this->upload());

        self::assertLessThan(
            OrganizationSignature::MAX_FILE_SIZE,
            $signature->getApproximateByteSize(),
        );
    }

    /**
     * A real PNG of the given dimensions — Imagine and GD are what read these, so a hand-made
     * header would prove nothing. One flat colour, so even 20 megapixels weighs a few kilobytes.
     *
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

    /**
     * A JPEG with enough variation that it compresses like a photograph rather than like flat
     * artwork — a single colour would compress to nothing and prove the opposite of the point.
     *
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    private static function jpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);

        for ($x = 0; $x < $width; $x += 7) {
            for ($y = 0; $y < $height; $y += 7) {
                imagefilledrectangle($image, $x, $y, $x + 6, $y + 6, (int) imagecolorallocate(
                    $image,
                    ($x * 7) % 256,
                    ($y * 13) % 256,
                    (($x + $y) * 3) % 256,
                ));
            }
        }

        ob_start();
        imagejpeg($image, null, 95);

        return (string) ob_get_clean();
    }

    private function upload(?string $contents = null): UploadedFile
    {
        // A real file on disk, because UploadedFile reads the path.
        $path = sys_get_temp_dir().'/'.uniqid('signature-', true).'.png';

        if (null === $contents) {
            copy(self::FIXTURE, $path);
        } else {
            file_put_contents($path, $contents);
        }

        return new UploadedFile($path, 'signature.png', test: true);
    }
}
