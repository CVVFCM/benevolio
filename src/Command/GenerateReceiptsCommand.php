<?php

declare(strict_types=1);

namespace App\Command;

use App\Receipt\ReceiptRunOutcome;
use App\Receipt\ReceiptRunReport;
use App\Receipt\YearlyReceiptRun;
use App\Repository\OrganizationRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function abs;
use function array_map;
use function assert;
use function intdiv;
use function is_string;
use function preg_match;
use function sprintf;

/**
 * Issues a whole civil year of reçus fiscaux from the command line.
 *
 * The same App\Receipt\YearlyReceiptRun the back office calls. It exists because the run
 * generates a PDF and sends a mail per volunteer inside one request: on a large association
 * that can outlast the browser, and this is how the year gets finished — or rerun — without
 * widening a timeout.
 *
 * **The association is an option, and it is not optional.** The Doctrine tenant filter is
 * OFF in CLI by design (a migration must touch every tenant), so nothing here would scope
 * itself — a run without it would be a run over every association at once. Same rule as
 * App\Command\CreateUserCommand.
 *
 * Rerunning a year issues new receipts with new numbers and leaves the previous ones
 * standing; that is deliberate, and the reason this command asks for confirmation first.
 */
#[AsCommand(
    name: 'app:receipts:generate',
    description: 'Émet les reçus fiscaux d\'une année civile pour une association',
)]
final class GenerateReceiptsCommand extends Command
{
    public function __construct(
        private readonly YearlyReceiptRun $run,
        private readonly OrganizationRepository $organizations,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('year', InputArgument::REQUIRED, 'Année civile, par exemple 2025')
            ->addOption(
                'organization',
                null,
                InputOption::VALUE_REQUIRED,
                'Raccourci de l\'association — obligatoire, le filtre multi-tenant est inactif en CLI',
            )
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Émet sans demander confirmation. Obligatoire avec --no-interaction.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $slug = $input->getOption('organization');
        assert(null === $slug || is_string($slug));

        if (null === $slug) {
            $io->error('Indiquez l\'association avec --organization=<raccourci>.');

            return Command::FAILURE;
        }

        $organization = $this->organizations->findOneBy(['slug' => $slug]);

        if (null === $organization) {
            $io->error(sprintf('Aucune association avec le raccourci « %s ».', $slug));

            return Command::FAILURE;
        }

        $yearArgument = $input->getArgument('year');
        assert(is_string($yearArgument));

        if (1 !== preg_match('/^\d{4}$/', $yearArgument)) {
            $io->error(sprintf('« %s » n\'est pas une année sur quatre chiffres.', $yearArgument));

            return Command::FAILURE;
        }

        $year = (int) $yearArgument;

        $io->title(sprintf('Reçus fiscaux %d — %s', $year, $organization->getName()));
        $io->text([
            'Un reçu par bénévole, sur l\'année civile, envoyé par courriel avec le PDF joint.',
            'Une année déjà traitée reçoit de nouveaux reçus, avec de nouveaux numéros.',
        ]);

        // A confirmation whose default is "no" would answer itself under --no-interaction and
        // the command would report success having issued nothing — the worst outcome for
        // something a script may be relying on. So a non-interactive run demands --force.
        if (true !== $input->getOption('force')) {
            if (!$input->isInteractive()) {
                $io->error('Ajoutez --force pour émettre sans confirmation.');

                return Command::FAILURE;
            }

            if (!$io->confirm(sprintf('Émettre les reçus %d ?', $year), false)) {
                $io->comment('Rien n\'a été émis.');

                return Command::SUCCESS;
            }
        }

        return $this->report($io, $this->run->run($organization, $year));
    }

    private function report(SymfonyStyle $io, ReceiptRunReport $report): int
    {
        if ($report->isRefused()) {
            $io->error($report->refusalReason());

            // A failure, unlike a volunteer with nothing to receipt: something has to be
            // fixed before this can work at all, and a script calling this should notice.
            return Command::FAILURE;
        }

        if ($report->hasNothingToDo()) {
            $io->warning(sprintf('Aucune action validée sur %d : rien à établir.', $report->year));

            return Command::SUCCESS;
        }

        if ([] !== $report->issued()) {
            $io->section('Reçus émis');
            $io->table(
                ['N° d\'ordre', 'Bénévole', 'Montant'],
                array_map(
                    static fn (ReceiptRunOutcome $outcome): array => [
                        $outcome->receipt()->getNumber(),
                        $outcome->person->getFullName(),
                        self::euros($outcome->receipt()->getAmountCents()),
                    ],
                    $report->issued(),
                ),
            );
        }

        if ([] !== $report->skipped()) {
            $io->section('Sans reçu');
            $io->table(
                ['Bénévole', 'Raison'],
                array_map(
                    static fn (ReceiptRunOutcome $outcome): array => [
                        $outcome->person->getFullName(),
                        $outcome->skipReason(),
                    ],
                    $report->skipped(),
                ),
            );
        }

        $io->success(sprintf(
            '%d reçu(s) émis pour un total de %s.',
            $report->issuedCount(),
            self::euros($report->totalCents()),
        ));

        return Command::SUCCESS;
    }

    /**
     * Cents to a readable amount. Integer split, like every other money path here — see
     * App\Twig\AccountingExtension, which does the same for the pages.
     */
    private static function euros(int $cents): string
    {
        return sprintf('%d,%02d €', intdiv($cents, 100), abs($cents % 100));
    }
}
