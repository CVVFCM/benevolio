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

use function file_put_contents;
use function filesize;
use function is_string;
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
            'donationDate' => '21/06/2026',
            'article200' => CerfaLayout::TICK,
            'natureVolunteerExpenses' => CerfaLayout::TICK,
            'signatureDate' => '29/07/2026',
        ];
    }

    private function generator(): ReceiptGenerator
    {
        $container = self::getContainer();

        return new ReceiptGenerator(
            $container->get(Environment::class),
            new GotenbergClient(HttpClient::create(), self::gotenbergDsn()),
            new Filesystem(),
            __DIR__.'/../../resources/cerfa/2041-rd_11580-05.pdf',
        );
    }

    /**
     * @param array<string, string> $values
     */
    private function renderOverlay(array $values): string
    {
        return self::getContainer()->get(Environment::class)->render(
            'receipt/cerfa_overlay.html.twig',
            [
                'fields' => CerfaLayout::FIELDS,
                'values' => $values,
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
