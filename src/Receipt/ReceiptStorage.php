<?php

declare(strict_types=1);

namespace App\Receipt;

use App\Entity\FiscalYear;
use App\Entity\Person;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\String\Slugger\SluggerInterface;

use function sprintf;

/**
 * Where a receipt PDF lives.
 *
 * `<year>/cerfa-firstname-lastname.pdf`, as asked. **The key can collide**: two volunteers
 * sharing a name, or one volunteer receipted twice in an exercice, overwrite each other.
 * That was a deliberate choice about naming, and App\Entity\Receipt is what keeps it from
 * losing anything — every number, amount and date stays in the database even when the
 * object is replaced.
 *
 * The directory is the exercice's **first** year, not the issue date's: a receipt issued
 * in January 2027 for the 2026 exercice belongs with 2026, and an exercice that straddles
 * the calendar still files in one place.
 */
final readonly class ReceiptStorage
{
    public function __construct(
        #[Autowire(service: 'receipts.storage')]
        private FilesystemOperator $filesystem,
        private SluggerInterface $slugger,
    ) {
    }

    public function store(FiscalYear $fiscalYear, Person $person, string $pdf): string
    {
        $path = $this->pathFor($fiscalYear, $person);

        $this->filesystem->write($path, $pdf);

        return $path;
    }

    public function read(string $path): string
    {
        return $this->filesystem->read($path);
    }

    public function pathFor(FiscalYear $fiscalYear, Person $person): string
    {
        // Slugged, because a name reaches this from a public form: accents, spaces and
        // anything else a volunteer might type must not decide an object key.
        $name = $this->slugger
            ->slug(sprintf('%s %s', $person->getFirstName(), $person->getLastName()))
            ->lower();

        return sprintf('%s/cerfa-%s.pdf', $fiscalYear->getBeginsOn()->format('Y'), $name);
    }
}
