<?php

declare(strict_types=1);

namespace App\Receipt;

use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Twig\Environment;

use function file_get_contents;
use function sprintf;
use function sys_get_temp_dir;

/**
 * Turns a set of receipt values into the finished CERFA PDF.
 *
 * Three stages, and the middle one is the reason for the other two:
 *
 *   1. Twig renders a transparent two-page A4 layer holding nothing but the values.
 *   2. Gotenberg converts that HTML to PDF.
 *   3. `qpdf --overlay` presses it onto the official form.
 *
 * **Gotenberg cannot do step 3.** It converts HTML and its pdfengines/merge route
 * concatenates pages; there is no stamp operation. And the form cannot be *filled*
 * either — it carries no form fields, having been flattened by PDF24 and Ghostscript.
 * So overlaying is not a preference, it is the only route that keeps the official
 * document intact underneath.
 *
 * Returns the PDF as a string. Where it is stored, and under what name, is not this
 * class's business.
 */
final readonly class ReceiptGenerator
{
    /**
     * Gotenberg requires the entry file of an HTML conversion to be called exactly
     * this; anything else is rejected with a 400 that does not say so.
     */
    private const string GOTENBERG_ENTRY_FILE = 'index.html';

    public function __construct(
        private Environment $twig,
        private GotenbergClient $gotenberg,
        private Filesystem $filesystem,
        private string $formPath,
        private string $qpdfBinary = 'qpdf',
    ) {
    }

    /**
     * @param array<string, string> $values keyed by the field names in CerfaLayout
     */
    public function generate(array $values): string
    {
        $html = $this->twig->render('receipt/cerfa_overlay.html.twig', [
            'fields' => CerfaLayout::FIELDS,
            'values' => $values,
            'ticks' => self::tickFields(),
            'layout_font_size' => CerfaLayout::FONT_SIZE,
        ]);

        $layer = $this->gotenberg->htmlToPdf(self::GOTENBERG_ENTRY_FILE, $html);

        return $this->stamp($layer);
    }

    /**
     * The fields whose value is a tick rather than text, so the template can weight
     * them differently. Derived from the values rather than listed twice.
     *
     * @return list<string>
     */
    private static function tickFields(): array
    {
        return [
            'categoryGeneralInterest',
            'categoryAssociation1901',
            'article200',
            'natureVolunteerExpenses',
        ];
    }

    /**
     * Presses the layer onto the official form with qpdf.
     *
     * Both operands go through temporary files: qpdf reads and writes paths, not
     * streams, and the official form must not be modified in place — it is a shipped
     * resource, and the next receipt needs it pristine.
     */
    private function stamp(string $layer): string
    {
        $layerPath = $this->filesystem->tempnam(sys_get_temp_dir(), 'cerfa-layer-', '.pdf');
        $outputPath = $this->filesystem->tempnam(sys_get_temp_dir(), 'cerfa-out-', '.pdf');

        try {
            $this->filesystem->dumpFile($layerPath, $layer);

            $process = new Process([
                $this->qpdfBinary,
                '--overlay', $layerPath,
                '--',
                $this->formPath,
                $outputPath,
            ]);
            $process->run();

            // qpdf exits 3 on warnings — a malformed but recoverable input — and still
            // writes a usable file. Treating that as failure would refuse receipts over
            // a cosmetic complaint about a form we ship ourselves; treating a real
            // failure as success would post an empty PDF to a volunteer.
            if (!$process->isSuccessful() && 3 !== $process->getExitCode()) {
                throw new RuntimeException(sprintf('qpdf could not stamp the receipt onto the CERFA (exit %d): %s', (int) $process->getExitCode(), $process->getErrorOutput()));
            }

            $stamped = @file_get_contents($outputPath);

            if (false === $stamped || '' === $stamped) {
                throw new RuntimeException('qpdf reported success but produced no PDF.');
            }

            return $stamped;
        } finally {
            // Receipts name volunteers and state donation amounts; nothing of the sort
            // is left in the system temporary directory.
            $this->filesystem->remove([$layerPath, $outputPath]);
        }
    }
}
