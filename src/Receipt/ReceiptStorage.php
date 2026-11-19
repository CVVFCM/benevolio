<?php

declare(strict_types=1);

namespace App\Receipt;

use App\Entity\Person;
use App\Receipt\Exception\ReceiptAlreadyStoredException;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\String\Slugger\SluggerInterface;

use function sprintf;

/**
 * Where a receipt PDF lives.
 *
 * `<association id>/<year>/cerfa-firstname-lastname-<number>.pdf`, under the `receipts/`
 * prefix the storage itself carries (see config/packages/flysystem.yaml) — so the prefix can
 * move without rewriting the paths already recorded on App\Entity\Receipt.
 *
 * **The association is in the key, and it has to be.** Every association shares one bucket
 * and numbers its receipts from 0001, so without it two associations with a volunteer of the
 * same name wrote the same object: the second replaced the first one's PDF, and the first
 * then handed out — and mailed — a receipt bearing another association's identity. Its id
 * rather than its slug: a slug can be edited, and the objects would stay behind.
 *
 * **The number is in the key too.** Re-running a year issues a new receipt rather than
 * replacing the old one, so a name-only key would leave the earlier row pointing at a PDF
 * that had been overwritten. Two volunteers sharing a name are separated by the same token.
 *
 * **Nothing is ever overwritten.** store() refuses a key that already holds an object; with
 * the two rules above that should never happen, which is the reason to refuse it loudly.
 *
 * Receipts issued before the association joined the key keep the path recorded on their
 * row (`<year>/cerfa-…`) and are read from there: read() takes the stored path as it is.
 *
 * The directory is the **civil year the receipt covers**, which is also what the document
 * says — not the year it was issued in: a 2025 receipt produced in January 2026 files under
 * 2025.
 */
final readonly class ReceiptStorage
{
    /**
     * Of the slugged name alone. With the association id, the year and the number around
     * it, the whole path stays well inside Receipt::$storagePath's 255 characters.
     */
    private const int MAX_NAME_LENGTH = 120;

    public function __construct(
        #[Autowire(service: 'receipts.storage')]
        private FilesystemOperator $filesystem,
        private SluggerInterface $slugger,
    ) {
    }

    /**
     * @throws ReceiptAlreadyStoredException when the key already holds a PDF
     */
    public function store(int $year, Person $person, string $number, string $pdf): string
    {
        $path = $this->pathFor($year, $person, $number);

        if ($this->filesystem->fileExists($path)) {
            throw ReceiptAlreadyStoredException::at($path);
        }

        $this->filesystem->write($path, $pdf);

        return $path;
    }

    public function read(string $path): string
    {
        return $this->filesystem->read($path);
    }

    private function pathFor(int $year, Person $person, string $number): string
    {
        // Slugged, because a name reaches this from a public form: accents, spaces and
        // anything else a volunteer might type must not decide an object key.
        //
        // And cut: transliteration lengthens some letters (« ß » becomes « ss »), so two
        // hundred typed characters can slug to four hundred — past the column the path is
        // recorded in, which failed that volunteer's receipt and stopped the run for
        // everyone after them. The number keeps the key unique whatever is cut.
        $name = $this->slugger
            ->slug(sprintf('%s %s', $person->getFirstName(), $person->getLastName()))
            ->lower()
            ->truncate(self::MAX_NAME_LENGTH);

        return sprintf(
            '%s/%d/cerfa-%s-%s.pdf',
            // The volunteer's own association: a Person belongs to exactly one, so the
            // key cannot be handed another's by a caller.
            $person->getOrganization()->getId()->toRfc4122(),
            $year,
            $name,
            $number,
        );
    }
}
