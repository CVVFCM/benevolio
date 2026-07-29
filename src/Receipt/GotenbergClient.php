<?php

declare(strict_types=1);

namespace App\Receipt;

use RuntimeException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function rtrim;
use function sprintf;

/**
 * The one call this application makes to Gotenberg: HTML in, PDF out.
 *
 * Hand-rolled on http-client rather than through sensiolabs/gotenberg-bundle. The bundle
 * is not auto-registered — installing it left `config/bundles.php` untouched — and
 * adopting its builder API to make a single multipart POST is more surface than the
 * request deserves. The HTTP contract is two lines of it, and owning them means the
 * failure modes below can be handled in terms this application cares about.
 *
 * The route is `/forms/chromium/convert/html`, and **the entry file must be named
 * `index.html`**. Gotenberg rejects anything else with a bare 400 that does not say why,
 * which is a good half-hour if you have not met it before.
 */
final readonly class GotenbergClient
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $baseUri,
    ) {
    }

    /**
     * @param string $entryFileName must be `index.html`; see the class docblock
     * @param string $html          the document to convert
     *
     * @return string the PDF
     */
    public function htmlToPdf(string $entryFileName, string $html): string
    {
        $formData = new FormDataPart([
            'files' => new DataPart($html, $entryFileName, 'text/html'),
            // WITHOUT THIS THE PAGE IS LETTER. Gotenberg defaults Chromium to
            // 8.5×11in and ignores `@page { size: A4 }` in the document, so the layer
            // comes back 612×792pt. qpdf then scales that onto the A4 form and every
            // value lands several millimetres from its line — visible on the page,
            // invisible to a test that only counts pages.
            'preferCssPageSize' => 'true',
        ]);

        try {
            $response = $this->httpClient->request(
                'POST',
                sprintf('%s/forms/chromium/convert/html', rtrim($this->baseUri, '/')),
                [
                    // A FormDataPart, not a plain body array. http-client does not turn
                    // an array containing a DataPart into multipart/form-data on its own —
                    // it sends url-encoded and Gotenberg answers 415.
                    //
                    // Gotenberg reads the *filename* from the part, not the field name,
                    // which is why the entry file has to be called index.html.
                    'headers' => $formData->getPreparedHeaders()->toArray(),
                    'body' => $formData->bodyToIterable(),
                ],
            );

            $pdf = $response->getContent();
        } catch (TransportException $e) {
            // Gotenberg being unreachable is an infrastructure fault, and the message
            // should say so rather than surfacing as a generic HTTP error on a page about
            // tax receipts.
            throw new RuntimeException(sprintf('Gotenberg at "%s" could not be reached.', $this->baseUri), previous: $e);
        }

        if ('' === $pdf) {
            throw new RuntimeException('Gotenberg returned an empty document.');
        }

        return $pdf;
    }
}
