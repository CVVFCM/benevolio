<?php

declare(strict_types=1);

namespace App\Receipt;

use App\Entity\Person;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\String\Slugger\SluggerInterface;

use function sprintf;

/**
 * Where a receipt PDF lives.
 *
 * `<year>/cerfa-firstname-lastname-<number>.pdf`, under the `receipts/` prefix the storage
 * itself carries (see config/packages/flysystem.yaml) — so the prefix can move without
 * rewriting the paths already recorded on App\Entity\Receipt.
 *
 * **The number is in the key, and that is what makes the key safe.** Re-running a year
 * issues a new receipt rather than replacing the old one, so a name-only key would leave the
 * earlier row pointing at a PDF that had been overwritten. Two volunteers sharing a name are
 * separated by the same token. The collision risk lot 7 accepted is closed.
 *
 * The directory is the **civil year the receipt covers**, which is also what the document
 * says — not the year it was issued in: a 2025 receipt produced in January 2026 files under
 * 2025.
 */
final readonly class ReceiptStorage
{
    public function __construct(
        #[Autowire(service: 'receipts.storage')]
        private FilesystemOperator $filesystem,
        private SluggerInterface $slugger,
    ) {
    }

    public function store(int $year, Person $person, string $number, string $pdf): string
    {
        $path = $this->pathFor($year, $person, $number);

        $this->filesystem->write($path, $pdf);

        return $path;
    }

    public function read(string $path): string
    {
        return $this->filesystem->read($path);
    }

    public function pathFor(int $year, Person $person, string $number): string
    {
        // Slugged, because a name reaches this from a public form: accents, spaces and
        // anything else a volunteer might type must not decide an object key.
        $name = $this->slugger
            ->slug(sprintf('%s %s', $person->getFirstName(), $person->getLastName()))
            ->lower();

        return sprintf('%d/cerfa-%s-%s.pdf', $year, $name, $number);
    }
}
