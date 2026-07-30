<?php

declare(strict_types=1);

namespace App\Tests\Receipt;

use App\Receipt\CerfaLayout;
use App\Receipt\GotenbergClient;
use App\Receipt\ReceiptGenerator;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Process;
use Twig\Environment;

use function base64_encode;
use function count;
use function file_get_contents;
use function file_put_contents;
use function filesize;
use function is_string;
use function json_decode;
use function round;
use function sprintf;
use function strlen;
use function sys_get_temp_dir;
use function trim;

/**
 * Generating the CERFA, for real: Gotenberg renders the layer and qpdf stamps it.
 *
 * Deliberately not mocked. The whole difficulty of this lot lives in the seam between
 * three tools — an HTML layer, a converter that cannot stamp, and a stamper that cannot
 * render — and a test with a fake Gotenberg would prove none of it. It needs
 * `make up` to have brought Gotenberg up, which CI does too.
 *
 * What it can prove: the layer carries the right values, and the result is still the
 * two-page A4 form with something added. What it cannot prove is that a value landed on
 * the right *line* — only looking does that, which is why
 * resources/cerfa/README.md tells you to look after changing a coordinate.
 */
final class ReceiptGeneratorTest extends KernelTestCase
{
    #[Test]
    public function the_overlay_layer_carries_every_value(): void
    {
        $html = $this->renderOverlay(self::values());

        // The amount and the words beside it, which is what a reader checks first.
        self::assertStringContainsString('43,25', $html);
        self::assertStringContainsString('Quarante-trois euros et vingt-cinq centimes', $html);
        self::assertStringContainsString('2026-0001', $html);
        self::assertStringContainsString('W083001234', $html);
        self::assertStringContainsString('BERTHIER', $html);

        // Positions come from CerfaLayout, so a coordinate change shows up here.
        self::assertStringContainsString('left: 150mm', $html);
    }

    /**
     * The two boxes that decide what kind of receipt this is. Without them the document
     * says a donation was made but not that it was waived volunteer expenses.
     */
    #[Test]
    public function the_layer_ticks_the_boxes_that_matter(): void
    {
        $html = $this->renderOverlay(self::values());

        // « Frais engagés par les bénévoles … » at its measured position.
        self::assertStringContainsString('left: 12mm', $html);
        self::assertStringContainsString('top: 188.7mm', $html);
        // « Association loi 1901 ».
        self::assertStringContainsString('top: 120.4mm', $html);
        self::assertStringContainsString('class="v tick"', $html);
    }

    /**
     * An absent value leaves the form's own dotted rule showing, rather than printing
     * the word "null" onto a tax receipt.
     */
    #[Test]
    public function an_absent_value_prints_nothing(): void
    {
        $values = self::values();
        unset($values['organizationObjet']);

        $html = $this->renderOverlay($values);

        // The objet's own line is at 88.2mm; nothing should be placed there.
        self::assertStringNotContainsString('top: 88.2mm', $html);
    }

    #[Test]
    public function it_stamps_the_layer_onto_the_real_form(): void
    {
        $pdf = $this->generator()->generate(self::values());

        self::assertStringStartsWith('%PDF', $pdf);

        $path = sys_get_temp_dir().'/cerfa-generated-test.pdf';
        file_put_contents($path, $pdf);

        // Still the official document: two pages, not a one-page layer that replaced it.
        self::assertSame('2', $this->qpdf(['--show-npages', $path]));

        // AND STILL A4. Gotenberg defaults to Letter and ignores the document's own
        // @page size, so the layer has to ask for preferCssPageSize — otherwise qpdf
        // scales a 612×792 overlay onto a 595×842 page and every value drifts. Counting
        // pages does not catch that; measuring the box does.
        self::assertStringContainsString(
            '595 x 842',
            $this->qpdf(['--check', '--no-warn', $path], allowWarnings: true).$this->mediaBox($path),
        );

        // And bigger than the form alone, so something really was added.
        self::assertGreaterThan(
            (int) filesize(__DIR__.'/../../resources/cerfa/2041-rd_11580-05.pdf'),
            strlen($pdf),
        );

        // qpdf is satisfied the result is a well-formed PDF, not merely non-empty.
        // --check prints a report rather than staying quiet on success, so the assertion
        // is on what it concluded.
        self::assertStringContainsString(
            'No syntax or stream encoding errors found',
            $this->qpdf(['--check', '--no-warn', $path], allowWarnings: true),
        );
    }

    /**
     * The signature reaches **page 2** of the layer, and page 1 stays clean.
     *
     * Asserted on the layer and not on the finished receipt on purpose: `qpdf --overlay`
     * wraps each stamped page's content in a Form XObject, and qpdf's per-page image list
     * only reports images in a page's *direct* resources — so after stamping it reports
     * none at all, even for the form's own logos. The page a signature landed on is
     * therefore only visible here. That it survives the stamping is the next test.
     */
    #[Test]
    public function the_layer_puts_the_signature_on_page_two_only(): void
    {
        $layer = $this->gotenberg()->htmlToPdf(
            'index.html',
            $this->renderOverlay(self::values(), ['signature' => self::signatureDataUri()]),
        );

        self::assertSame([0, 1], $this->imagesPerPage($layer));
    }

    /**
     * And it survives being pressed onto the official form.
     *
     * One more image object than the same receipt without a signature — the form itself
     * carries two, so an absolute count would prove nothing.
     */
    #[Test]
    public function the_stamped_receipt_carries_the_signature(): void
    {
        $unsigned = $this->imageObjectCount($this->generator()->generate(self::values()));
        $signed = $this->imageObjectCount(
            $this->generator()->generate(self::values(), ['signature' => self::signatureDataUri()]),
        );

        self::assertSame($unsigned + 1, $signed);
    }

    /**
     * An association with no signature gets exactly the document it got before.
     */
    #[Test]
    public function an_association_without_a_signature_gets_an_unsigned_form(): void
    {
        $html = $this->renderOverlay(self::values(), images: []);

        self::assertStringNotContainsString('<img', $html);
    }

    #[Test]
    public function the_signature_sits_inside_the_measured_box(): void
    {
        $html = $this->renderOverlay(self::values(), ['signature' => self::signatureDataUri()]);

        // The box is x 106.9-184.1mm, y 216.2-233.4mm, and the date run already occupies
        // x 109-128.2mm. These are CerfaLayout::IMAGES' numbers, so moving them shows here.
        self::assertStringContainsString('left: 134mm', $html);
        self::assertStringContainsString('top: 217.5mm', $html);
        self::assertStringContainsString('max-inline-size: 48mm', $html);
        self::assertStringContainsString('max-block-size: 14mm', $html);
    }

    /**
     * @return array<string, string>
     */
    private static function values(): array
    {
        return [
            'receiptNumber' => '2026-0001',
            'organizationName' => 'Club de Voile des Vieilles-Forges',
            'sirenOrRna' => 'W083001234',
            'organizationAddressStreet' => 'chemin du Lac',
            'organizationAddressPostcode' => '08000',
            'organizationAddressCity' => 'Charleville-Mézières',
            'organizationAddressCountry' => 'France',
            'organizationObjet' => 'Pratique et enseignement de la voile',
            'categoryGeneralInterest' => CerfaLayout::TICK,
            'categoryAssociation1901' => CerfaLayout::TICK,
            'volunteerLastName' => 'BERTHIER',
            'volunteerFirstName' => 'Camille',
            'volunteerAddressStreet' => 'rue des Tilleuls',
            'volunteerAddressPostcode' => '08000',
            'volunteerAddressCity' => 'Charleville-Mézières',
            'volunteerAddressCountry' => 'France',
            'amount' => '43,25',
            'amountInWords' => 'Quarante-trois euros et vingt-cinq centimes',
            'donationDay' => '21',
            'donationMonth' => '06',
            'donationYear' => '2026',
            'article200' => CerfaLayout::TICK,
            'natureVolunteerExpenses' => CerfaLayout::TICK,
            'signatureDay' => '29',
            'signatureMonth' => '07',
            'signatureYear' => '2026',
        ];
    }

    private function generator(): ReceiptGenerator
    {
        $container = self::getContainer();

        return new ReceiptGenerator(
            $container->get(Environment::class),
            $this->gotenberg(),
            new Filesystem(),
            __DIR__.'/../../resources/cerfa/2041-rd_11580-05.pdf',
        );
    }

    private function gotenberg(): GotenbergClient
    {
        return new GotenbergClient(HttpClient::create(), self::gotenbergDsn());
    }

    /**
     * The fixture signature, as it reaches the overlay: a `data:` URI.
     *
     * The real file, not a one-pixel stand-in — a PNG the browser refuses to decode would
     * silently produce a blank box, and the point of these tests is to catch exactly that.
     */
    private static function signatureDataUri(): string
    {
        $contents = file_get_contents(__DIR__.'/../../resources/fixtures/organization-signature.png');
        self::assertNotFalse($contents);

        return 'data:image/png;base64,'.base64_encode($contents);
    }

    /**
     * How many images each page carries, from qpdf's own JSON.
     *
     * @return list<int>
     */
    private function imagesPerPage(string $pdf): array
    {
        $path = sys_get_temp_dir().'/cerfa-images-test.pdf';
        file_put_contents($path, $pdf);

        $json = json_decode($this->qpdf(['--json=latest', '--json-key=pages', $path], allowWarnings: true), true);
        self::assertIsArray($json);
        self::assertArrayHasKey('pages', $json);
        self::assertIsArray($json['pages']);

        $counts = [];
        foreach ($json['pages'] as $page) {
            self::assertIsArray($page);
            self::assertIsArray($page['images'] ?? null);
            $counts[] = count($page['images']);
        }

        return $counts;
    }

    /**
     * How many image objects the whole file holds.
     *
     * Read off the QDF form, where objects are written uncompressed, because this has to
     * see inside the Form XObjects `--overlay` creates — which is exactly what the
     * per-page listing above cannot do.
     */
    private function imageObjectCount(string $pdf): int
    {
        $path = sys_get_temp_dir().'/cerfa-image-objects-test.pdf';
        file_put_contents($path, $pdf);

        $count = preg_match_all(
            '#/Subtype\s*/Image#',
            $this->qpdf(['--qdf', '--object-streams=disable', $path, '-'], allowWarnings: true),
        );
        self::assertNotFalse($count);

        return $count;
    }

    /**
     * @param array<string, string> $values
     * @param array<string, string> $images
     */
    private function renderOverlay(array $values, array $images = []): string
    {
        return self::getContainer()->get(Environment::class)->render(
            'receipt/cerfa_overlay.html.twig',
            [
                'fields' => CerfaLayout::FIELDS,
                'values' => $values,
                'images' => CerfaLayout::IMAGES,
                'image_values' => $images,
                'ticks' => ['categoryGeneralInterest', 'categoryAssociation1901', 'article200', 'natureVolunteerExpenses'],
                'layout_font_size' => CerfaLayout::FONT_SIZE,
            ],
        );
    }

    /**
     * Where Gotenberg is, from the environment the container was booted with.
     */
    private static function gotenbergDsn(): string
    {
        $dsn = $_SERVER['GOTENBERG_DSN'] ?? null;

        return is_string($dsn) && '' !== $dsn ? $dsn : 'http://gotenberg:3000';
    }

    /**
     * The page box in points, as "595 x 842" for A4 portrait.
     *
     * Read from qpdf's QDF form, which writes the page dictionaries out uncompressed —
     * so /MediaBox is plain text. The alternative, walking `--json`, is a pile of mixed
     * arrays for one pair of numbers.
     */
    private function mediaBox(string $path): string
    {
        $qdf = $this->qpdf(['--qdf', '--object-streams=disable', $path, '-'], allowWarnings: true);

        if (1 !== preg_match('/MediaBox\s*\[\s*[\d.]+\s+[\d.]+\s+([\d.]+)\s+([\d.]+)/', $qdf, $matches)) {
            self::fail('No /MediaBox found in the generated PDF.');
        }

        return sprintf('%d x %d', (int) round((float) $matches[1]), (int) round((float) $matches[2]));
    }

    /**
     * @param list<string> $arguments
     */
    private function qpdf(array $arguments, bool $allowWarnings = false): string
    {
        $process = new Process(['qpdf', ...$arguments]);
        $process->run();

        // 3 is qpdf's "warnings only", which --check reports for perfectly usable files.
        if (!$process->isSuccessful() && !($allowWarnings && 3 === $process->getExitCode())) {
            self::fail('qpdf failed: '.$process->getErrorOutput().$process->getOutput());
        }

        return trim($process->getOutput());
    }
}
