<?php

declare(strict_types=1);

namespace App\Command;

use App\Demo\DemoAssociation;
use App\Demo\DemoReset;
use App\Demo\Exception\DemoResetRefusedException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;

/**
 * Creates the public demo, or puts it back as it was: App\Demo\DemoReset. Run by the chart's
 * CronJob every night at 3:00 UTC, by `composer reset` in development, and once by hand on a
 * fresh instance.
 *
 * Deletes data, so it asks first, and **`--no-interaction` demands `--force`** — for the same
 * reason as app:receipts:generate: a confirmation defaulting to "no" would answer itself in
 * the CronJob and exit 0 having reset nothing.
 */
#[AsCommand(
    name: 'app:demo:reset',
    description: 'Crée l\'association de démonstration, ou la remet dans son état initial',
)]
final class ResetDemoCommand extends Command
{
    public function __construct(
        private readonly DemoAssociation $demo,
        private readonly DemoReset $reset,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'force',
            null,
            InputOption::VALUE_NONE,
            'Réinitialise sans demander confirmation. Obligatoire avec --no-interaction.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->demo->isEnabled()) {
            $io->error('Aucune démo n\'est configurée : DEMO_ORGANIZATION_SLUG est vide.');

            return Command::FAILURE;
        }

        $io->title(sprintf('Démo · %s', $this->demo->getSlug()));
        $io->text('Supprime les déclarations, bénévoles, reçus et administrateurs invités de la démo, puis la remet dans son état initial.');

        if (true !== $input->getOption('force')) {
            if (!$input->isInteractive()) {
                $io->error('Ajoutez --force pour réinitialiser sans confirmation.');

                return Command::FAILURE;
            }

            if (!$io->confirm('Réinitialiser la démo ?', false)) {
                $io->comment('Rien n\'a été modifié.');

                return Command::SUCCESS;
            }
        }

        try {
            $report = $this->reset->reset();
        } catch (DemoResetRefusedException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        foreach ($report->undeletedReceiptPaths as $path) {
            $io->warning(sprintf('PDF non supprimé : %s', $path));
        }

        $io->success(sprintf(
            '%s : %s (%d reçu(s) supprimé(s)).',
            $report->organization->getName(),
            $report->created ? 'créée' : 'réinitialisée',
            $report->deletedReceipts,
        ));

        return Command::SUCCESS;
    }
}
