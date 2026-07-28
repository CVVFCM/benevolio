<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\OrganizationRepository;
use App\Security\Role;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

use function assert;
use function count;
use function is_string;
use function sprintf;

/**
 * Creates a back-office account from the command line.
 *
 * The first account on a fresh deployment has to be born somewhere other than the
 * backoffice, which already requires an account to reach. After that it stays
 * useful: adding a colleague without handing them the platform CRUD.
 *
 * The password is only ever read from a hidden prompt — deliberately no --password
 * option, which would put a live credential in shell history, in `ps` output and
 * in the CI logs of anyone who scripted it.
 *
 * NOTE: validation includes Assert\NotCompromisedPassword, which calls the
 * haveibeenpwned API. It is disabled in the test environment (see
 * config/packages/validator.yaml) but active in production, so this command needs
 * egress when run against a real cluster.
 */
#[AsCommand(
    name: 'app:user:create',
    description: 'Crée un compte du back-office',
)]
final class CreateUserCommand extends Command
{
    private const string ROLE_ADMIN = 'admin';
    private const string ROLE_SUPER_ADMIN = 'super-admin';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly OrganizationRepository $organizations,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Adresse électronique, qui sert d\'identifiant')
            ->addOption(
                'role',
                null,
                InputOption::VALUE_REQUIRED,
                sprintf('« %s » ou « %s »', self::ROLE_ADMIN, self::ROLE_SUPER_ADMIN),
                self::ROLE_ADMIN,
            )
            ->addOption(
                'organization',
                null,
                InputOption::VALUE_REQUIRED,
                'Raccourci de l\'association — obligatoire pour un administrateur d\'association',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $roleOption = $input->getOption('role');
        assert(is_string($roleOption));

        $role = match ($roleOption) {
            self::ROLE_ADMIN => Role::ADMIN,
            self::ROLE_SUPER_ADMIN => Role::SUPER_ADMIN,
            default => null,
        };

        if (null === $role) {
            $io->error(sprintf(
                'Rôle inconnu « %s ». Valeurs acceptées : %s, %s.',
                $roleOption,
                self::ROLE_ADMIN,
                self::ROLE_SUPER_ADMIN,
            ));

            return Command::FAILURE;
        }

        $slug = $input->getOption('organization');
        assert(null === $slug || is_string($slug));

        // A platform super-admin belongs to no association: App\Tenant\UserTenantResolver
        // reads the association off the account to arm the tenant filter, so attaching
        // one here would quietly scope the whole platform backoffice to it. The
        // Expression constraint on User permits the combination — it only guards the
        // reverse — so it has to be refused here.
        if (Role::SUPER_ADMIN === $role && null !== $slug) {
            $io->error('Un administrateur de la plateforme n\'est rattaché à aucune association.');

            return Command::FAILURE;
        }

        $organization = null;
        if (null !== $slug) {
            $organization = $this->organizations->findOneBy(['slug' => $slug]);

            if (null === $organization) {
                $io->error(sprintf('Aucune association avec le raccourci « %s ».', $slug));

                return Command::FAILURE;
            }
        }

        $password = $io->askHidden('Mot de passe');
        if (!is_string($password) || '' === $password) {
            $io->error('Mot de passe vide.');

            return Command::FAILURE;
        }

        $email = $input->getArgument('email');
        assert(is_string($email));

        $user = new User();
        $user->setEmail($email);
        $user->grant($role);
        $user->setOrganization($organization);
        // Validated, not stored: the length and breach constraints hang off
        // $plainPassword, so it has to be set before validate() and cleared after.
        $user->setPlainPassword($password);

        $violations = $this->validator->validate($user);
        if (count($violations) > 0) {
            foreach ($violations as $violation) {
                $io->error(sprintf('%s : %s', $violation->getPropertyPath(), (string) $violation->getMessage()));
            }

            return Command::FAILURE;
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $user->erasePlainPassword();

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(sprintf('Compte « %s » créé (%s).', $user->getEmail(), $role->label()));

        return Command::SUCCESS;
    }
}
