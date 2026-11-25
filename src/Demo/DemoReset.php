<?php

declare(strict_types=1);

namespace App\Demo;

use App\Accounting\PublishedBareme;
use App\Declaration\ConfirmationToken;
use App\Declaration\DeclarationConfirmer;
use App\Declaration\DeclarationDecider;
use App\Declaration\DeclarationSubmitter;
use App\Demo\Exception\DemoResetRefusedException;
use App\Entity\Activity;
use App\Entity\Declaration;
use App\Entity\DeclarationAction;
use App\Entity\FiscalYear;
use App\Entity\FiscalYearMileageRate;
use App\Entity\Organization;
use App\Entity\OrganizationSignature;
use App\Entity\Person;
use App\Entity\Receipt;
use App\Entity\Task;
use App\Entity\User;
use App\Enum\ActivityType;
use App\Enum\FiscalPower;
use App\Organization\DefaultTasks;
use App\Receipt\ReceiptStorage;
use App\Repository\OrganizationRepository;
use App\Repository\UserRepository;
use App\Security\Role;
use App\User\OwnerAssigner;
use App\ValueObject\Address;
use App\ValueObject\Email;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemException;
use LogicException;
use Psr\Clock\ClockInterface;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

use function array_map;
use function base64_encode;
use function count;
use function file_get_contents;
use function sprintf;

/**
 * Puts the public demo back as it was (#62): run every night by `app:demo:reset`, and once
 * to create it — the same code path, so a fresh instance and a used one end up identical.
 *
 * A FULL restore, not only the declarations: the demo's login is public, so anything an
 * admin can change, a visitor has. The association's identity, its tasks, activities and
 * exercices, its admins (invited ones deleted, the owner's password put back), and the
 * declarations, volunteers and receipts are all replaced by the seed below. The row of the
 * association itself is kept, so its id — in every receipt key — and its receipt counter
 * survive: numbers are never handed out twice, even here.
 *
 * Built from entities and the use cases (confirm, validate, refuse), never from the Foundry
 * factories, which are a development dependency the production image does not have. A
 * fifth creation path for an Organization, so it seeds DefaultTasks and calls OwnerAssigner
 * explicitly, like the other four.
 *
 * **Refuses to touch an association that does not look like the demo** — one with accounts,
 * none of them the demo's. DEMO_ORGANIZATION_SLUG set to a real slug would otherwise wipe
 * a real association at 3:00.
 */
final readonly class DemoReset
{
    /** The development stand-in signature, already 600 px; see resources/fixtures/README.md. */
    private const string SIGNATURE = '/resources/fixtures/organization-signature.png';

    public function __construct(
        private DemoAssociation $demo,
        private EntityManagerInterface $entityManager,
        private OrganizationRepository $organizations,
        private UserRepository $users,
        private UserPasswordHasherInterface $passwordHasher,
        private OwnerAssigner $ownerAssigner,
        private DefaultTasks $defaultTasks,
        private DeclarationConfirmer $confirmer,
        private DeclarationDecider $decider,
        private ReceiptStorage $receiptStorage,
        private ClockInterface $clock,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    /**
     * @throws DemoResetRefusedException when there is no demo, or the slug names something else
     */
    public function reset(): DemoResetReport
    {
        if (!$this->demo->isEnabled()) {
            throw DemoResetRefusedException::disabled();
        }

        $organization = $this->organizations->findOneBy(['slug' => $this->demo->getSlug()]);
        $created = null === $organization;
        $this->refuseUnlessDemo($organization);

        $receiptPaths = null === $organization ? [] : $this->receiptPaths($organization);

        $organization = $this->entityManager->wrapInTransaction(
            function () use ($organization): Organization {
                if (null === $organization) {
                    $organization = new Organization();
                    $organization->setSlug($this->demo->getSlug());
                    $this->entityManager->persist($organization);
                } else {
                    $organization = $this->wipe($organization);
                }

                $this->restoreIdentity($organization);
                $this->restoreOwner($organization);
                $this->seed($organization);
                $this->entityManager->flush();

                return $organization;
            },
        );

        // After the commit: an object deleted before a rollback would leave a row pointing
        // at nothing. A failure here leaves litter, not a broken receipt.
        $undeleted = [];
        foreach ($receiptPaths as $path) {
            try {
                $this->receiptStorage->delete($path);
            } catch (FilesystemException) {
                $undeleted[] = $path;
            }
        }

        return new DemoResetReport($organization, $created, count($receiptPaths), $undeleted);
    }

    private function refuseUnlessDemo(?Organization $organization): void
    {
        $account = $this->users->findOneBy(['email' => DemoAssociation::ADMIN_EMAIL]);

        if (null !== $account && (null === $organization || $account->getOrganization() !== $organization)) {
            throw DemoResetRefusedException::accountTakenElsewhere(DemoAssociation::ADMIN_EMAIL);
        }

        if (null !== $organization && null === $account && [] !== $this->users->findBy(['organization' => $organization])) {
            throw DemoResetRefusedException::notTheDemo($organization->getSlug());
        }
    }

    /**
     * @return list<string>
     */
    private function receiptPaths(Organization $organization): array
    {
        return array_map(
            static fn (Receipt $receipt): string => $receipt->getStoragePath(),
            $this->entityManager->getRepository(Receipt::class)->findBy(['organization' => $organization]),
        );
    }

    /**
     * Deletes everything a visitor may have made, in the order the foreign keys allow:
     * receipts hold their Person (NO ACTION); a Person cascades to its declarations and their
     * lines; lines hold their task and activity, and a declaration the admin who confirmed it.
     * An exercice cascades to its rates.
     *
     * Bulk DQL, which bypasses the unit of work: the EntityManager is cleared afterwards and
     * the association read again, or the stale collections would be flushed back.
     */
    private function wipe(Organization $organization): Organization
    {
        $id = $organization->getId();

        foreach ([Receipt::class, Person::class, FiscalYear::class, Activity::class, Task::class] as $class) {
            $this->entityManager
                ->createQuery(sprintf('DELETE FROM %s e WHERE e.organization = :organization', $class))
                ->setParameter('organization', $id, 'uuid')
                ->execute();
        }

        $this->entityManager
            ->createQuery('DELETE FROM '.User::class.' u WHERE u.organization = :organization AND u.email <> :email')
            ->setParameter('organization', $id, 'uuid')
            ->setParameter('email', DemoAssociation::ADMIN_EMAIL)
            ->execute();

        $this->entityManager->clear();

        return $this->organizations->find($id) ?? throw new LogicException('The demo association vanished during its reset.');
    }

    private function restoreIdentity(Organization $organization): void
    {
        $organization
            ->setName(DemoAssociation::NAME)
            ->setActive(true)
            // Reached from /demo, not from the list of real associations.
            ->setListedOnHomepage(false)
            // An RNA number of the right shape, invented — like every value here.
            ->setSirenOrRna('W000000000')
            ->setObjet('Démonstration de Benevolio. Association fictive : aucune donnée réelle.')
            ->setAddressNumber('1')
            ->setAddressStreet('rue de la Démonstration')
            ->setAddressPostcode('08000')
            ->setAddressCity('Charleville-Mézières')
            ->setAddressCountry('FR')
            ->setSignature($this->signature());
    }

    private function signature(): OrganizationSignature
    {
        $path = $this->projectDir.self::SIGNATURE;
        $contents = @file_get_contents($path);

        if (false === $contents) {
            throw new RuntimeException(sprintf('The demo signature is missing from %s.', $path));
        }

        return new OrganizationSignature('image/png', base64_encode($contents), 'signature-demo.png');
    }

    private function restoreOwner(Organization $organization): void
    {
        $owner = $this->users->findOneBy(['email' => DemoAssociation::ADMIN_EMAIL]);

        if (null === $owner) {
            $owner = new User();
            $owner->setEmail(DemoAssociation::ADMIN_EMAIL);
            $owner->setOrganization($organization);
            $this->entityManager->persist($owner);
        }

        $owner->setRoles([Role::ADMIN->value]);
        // Every other account was just deleted, so this one becomes the owner again.
        $this->ownerAssigner->assignIfFirst($owner);
        $owner->setPassword($this->passwordHasher->hashPassword($owner, DemoAssociation::ADMIN_PASSWORD));
        // Any session or link opened under a password a visitor chose is dead.
        $owner->invalidateLoginLinks();
    }

    /**
     * The sample: what a treasurer would find after a few weeks. Dates relative to today, so
     * the demo never ages; exercices for this calendar year and the last, so every line —
     * none older than two months — is priced.
     */
    private function seed(Organization $organization): void
    {
        $tasks = [];
        foreach ($this->defaultTasks->createFor($organization) as $task) {
            $tasks[$task->getName()] = $task;
        }

        $today = $this->clock->now()->setTime(0, 0);
        $year = (int) $today->format('Y');
        $this->fiscalYear($organization, $year - 1);
        $this->fiscalYear($organization, $year);

        $tournament = $this->activity($organization, 'Tournoi de printemps', ActivityType::EVENT, $today->modify('-45 days'), $today->modify('-44 days'), 2, '12.00');
        $openDay = $this->activity($organization, 'Journée portes ouvertes', ActivityType::EVENT, $today->modify('-12 days'), null, 1, '6.00');
        $upkeep = $this->activity($organization, 'Entretien du matériel', ActivityType::ACTION, null, null, null, null);
        // Still to come: the form offers it once it has begun.
        $this->activity($organization, 'Fête de fin de saison', ActivityType::EVENT, $today->modify('+20 days'), null, null, null);

        // Confirmed, waiting for a verdict: the first thing to try.
        $camille = $this->declaration($organization, 'Camille', 'Martin', 'camille.martin', '4', 'rue des Tilleuls');
        $this->line($camille, $this->task($tasks, 'Encadrement'), $tournament, null, $today->modify('-45 days'), 2, '12.00', 4, 38, FiscalPower::FIVE_CV);
        $this->line($camille, $this->task($tasks, 'Travaux'), $upkeep, null, $today->modify('-8 days'), 1, '3.00', 0, 0, null);
        $this->confirmer->confirmBySubmission($camille);

        // Validated, in an electric car: in the écritures, and ready for a receipt.
        $lucas = $this->declaration($organization, 'Lucas', 'Bernard', 'lucas.bernard', '17', 'avenue de la Gare');
        $this->line($lucas, $this->task($tasks, 'Autre'), $openDay, null, $today->modify('-12 days'), 1, '6.00', 2, 25, FiscalPower::FOUR_CV, true);
        $this->confirmer->confirmBySubmission($lucas);
        $this->decider->validateAll($lucas);

        // A mixed basket: one line accepted, one refused.
        $ines = $this->declaration($organization, 'Inès', 'Petit', 'ines.petit', '9', 'place Ducale');
        $accepted = $this->line($ines, $this->task($tasks, 'Travaux'), $upkeep, null, $today->modify('-30 days'), 1, '4.00', 2, 12, FiscalPower::SIX_CV);
        $refused = $this->line($ines, $this->task($tasks, 'Autre'), null, 'Réunion de bureau', $today->modify('-29 days'), 1, '2.00', 0, 0, null);
        $this->confirmer->confirmBySubmission($ines);
        $this->decider->validateLine($accepted);
        $this->decider->refuseLine($refused);

        // Hours only, as a passenger: valued in the books, never on a receipt.
        $hugo = $this->declaration($organization, 'Hugo', 'Durand', 'hugo.durand', '3', 'rue du Moulin');
        $this->line($hugo, $this->task($tasks, 'Arbitrage'), $tournament, null, $today->modify('-45 days'), 2, '14.00', 4, 38, null);
        $this->confirmer->confirmBySubmission($hugo);
        $this->decider->validateAll($hugo);

        // Never confirmed by its volunteer: « Confirmer à la place du bénévole » has something
        // to act on. Filed on the form, the demo confirms at once, so this is the only one.
        $chloe = $this->declaration($organization, 'Chloé', 'Moreau', 'chloe.moreau', '22', 'boulevard Gambetta');
        $this->line($chloe, $this->task($tasks, 'Travaux'), null, 'Rangement du local', $today->modify('-3 days'), 1, '2.50', 0, 0, null);
        $chloe->issueConfirmationToken(ConfirmationToken::generate(), $this->clock->now()->modify(DeclarationSubmitter::TOKEN_LIFETIME));
    }

    /**
     * @param array<string, Task> $tasks
     */
    private function task(array $tasks, string $name): Task
    {
        return $tasks[$name] ?? throw new LogicException(sprintf('DefaultTasks no longer creates « %s »; update the demo seed.', $name));
    }

    private function fiscalYear(Organization $organization, int $year): void
    {
        $fiscalYear = new FiscalYear($organization);
        $fiscalYear
            ->setName((string) $year)
            ->setBeginsOn(new DateTimeImmutable(sprintf('%d-01-01', $year)))
            ->setEndsOn(new DateTimeImmutable(sprintf('%d-12-31', $year)));

        foreach (PublishedBareme::MILLI_EUROS_PER_KM_2025 as $power => $milliEuros) {
            // Attaches itself to the exercice, which cascades the persist.
            new FiscalYearMileageRate($fiscalYear, FiscalPower::from($power))->setMilliEurosPerKm($milliEuros);
        }

        $this->entityManager->persist($fiscalYear);
    }

    private function activity(
        Organization $organization,
        string $name,
        ActivityType $type,
        ?DateTimeImmutable $beginsOn,
        ?DateTimeImmutable $endsOn,
        ?int $defaultDays,
        ?string $defaultWorkHours,
    ): Activity {
        $activity = new Activity($organization);
        $activity
            ->setName($name)
            ->setType($type)
            ->setBeginsOn($beginsOn)
            ->setEndsOn($endsOn)
            ->setDefaultDays($defaultDays)
            ->setDefaultWorkHours($defaultWorkHours);
        $this->entityManager->persist($activity);

        return $activity;
    }

    private function declaration(Organization $organization, string $firstName, string $lastName, string $mailbox, string $number, string $street): Declaration
    {
        // example.org: a domain reserved for examples, which no mail would reach anyway.
        $person = new Person(
            $organization,
            $firstName,
            $lastName,
            new Email($mailbox.'@example.org'),
            new Address($number, $street, '08000', 'Charleville-Mézières', 'FR'),
        );
        $this->entityManager->persist($person);

        $declaration = new Declaration($organization, $person, true, true);
        $this->entityManager->persist($declaration);

        return $declaration;
    }

    /**
     * Must run before the declaration is confirmed: the cascade moves only the lines that
     * exist at that moment (see AGENTS.md, *The two state machines*).
     */
    private function line(
        Declaration $declaration,
        Task $task,
        ?Activity $activity,
        ?string $title,
        DateTimeImmutable $date,
        int $days,
        string $workHours,
        int $journeys,
        int $distanceKm,
        ?FiscalPower $ownVehicle,
        bool $electric = false,
    ): DeclarationAction {
        $action = new DeclarationAction(
            $declaration,
            $task,
            $activity,
            $title,
            null,
            $date,
            $days,
            $journeys,
            $distanceKm,
            null !== $ownVehicle,
            $ownVehicle,
            $workHours,
            $electric,
        );
        // Persisted with the flush that confirms it; the declaration cascades it too.
        $this->entityManager->persist($action);

        return $action;
    }
}
