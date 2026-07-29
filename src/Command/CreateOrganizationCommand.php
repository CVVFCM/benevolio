<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Organization;
use App\Organization\DefaultTasks;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Validator\Validator\ValidatorInterface;

use function assert;
use function count;
use function is_string;
use function sprintf;

/**
 * Creates an association from the command line.
 *
 * This exists because a freshly deployed instance has an empty database: no
 * organization means no public form to fill and nothing for an admin account to
 * be attached to. The platform backoffice cannot help, since reaching it already
 * requires an account. So the very first association is created here, over
 * `kubectl exec`, and every later one can be too.
 *
 * THIS IS THE THIRD CREATION PATH for an Organization, after the platform CRUD
 * and OrganizationFactory — exactly the situation App\Organization\DefaultTasks
 * warns about. Hence the explicit createFor() call below: an association without
 * tasks has a public form that offers no choices at all.
 */
#[AsCommand(
    name: 'app:organization:create',
    description: 'Crée une association et sa liste de tâches par défaut',
)]
final class CreateOrganizationCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
        private readonly DefaultTasks $defaultTasks,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Nom complet de l\'association')
            ->addArgument('slug', InputArgument::REQUIRED, 'Raccourci utilisé dans les URL publiques (/a/<slug>/…)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Both are InputArgument::REQUIRED, so the console has already refused an
        // invocation without them; the assertions are only there to type the mixed.
        $name = $input->getArgument('name');
        $slug = $input->getArgument('slug');
        assert(is_string($name) && is_string($slug));

        $organization = new Organization();
        $organization->setName($name);
        $organization->setSlug($slug);

        // Validate rather than let the database complain: the slug regex and the
        // length limits live on the entity, and their messages are already written
        // for a human.
        $violations = $this->validator->validate($organization);
        if (count($violations) > 0) {
            foreach ($violations as $violation) {
                $io->error(sprintf('%s : %s', $violation->getPropertyPath(), (string) $violation->getMessage()));
            }

            return Command::FAILURE;
        }

        $this->entityManager->persist($organization);
        $tasks = $this->defaultTasks->createFor($organization);
        $this->entityManager->flush();

        $io->success(sprintf(
            'Association « %s » créée avec %d tâches.',
            $organization->getName(),
            count($tasks),
        ));
        $io->writeln(sprintf('Formulaire public : /a/%s/declaration', $organization->getSlug()));

        return Command::SUCCESS;
    }
}
