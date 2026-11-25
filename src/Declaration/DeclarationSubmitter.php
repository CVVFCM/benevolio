<?php

declare(strict_types=1);

namespace App\Declaration;

use App\Declaration\Exception\ActivityNoLongerAvailableException;
use App\Declaration\Exception\TaskNoLongerAvailableException;
use App\Entity\Activity;
use App\Entity\Declaration;
use App\Entity\DeclarationAction;
use App\Entity\Organization;
use App\Entity\Person;
use App\Entity\Task;
use App\Form\Declaration\ActionDraft;
use App\Form\Declaration\DeclarationDraft;
use App\Repository\ActivityRepository;
use App\Repository\PersonRepository;
use App\Repository\TaskRepository;
use App\ValueObject\Address;
use App\ValueObject\Email;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

use function assert;

/**
 * Turns a finished DeclarationDraft into persisted entities.
 *
 * This is the boundary where the value objects are built. Their constructors
 * assert their own invariants, which by this point should be unreachable — the
 * form already validated the same rules and reported them per field. Reaching an
 * InvalidValueObjectException here means the two sets of rules have drifted
 * apart, which is exactly when we want a loud failure rather than a bad row.
 */
final readonly class DeclarationSubmitter
{
    /**
     * How long a volunteer has to click the link. Long enough to survive a weekend
     * and a spam folder; short enough that a stale link is not left live for months.
     */
    public const string TOKEN_LIFETIME = '+7 days';

    public function __construct(
        private PersonRepository $people,
        private TaskRepository $tasks,
        private ActivityRepository $activities,
        private EntityManagerInterface $entityManager,
        private DeclarationConfirmationMailer $mailer,
        private DeclarationConfirmer $confirmer,
        private DeclarationNotifier $notifier,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Persists the declaration and emails its confirmation link.
     *
     * The mail is sent AFTER the transaction commits. Sending inside it would mean
     * a link that arrives for a declaration the database then rolled back — and
     * with a synchronous transport, a mail server hiccup would lose the whole
     * declaration.
     */
    public function submit(Organization $organization, DeclarationDraft $draft): Declaration
    {
        $token = ConfirmationToken::generate();

        $declaration = $this->entityManager->wrapInTransaction(
            function () use ($organization, $draft, $token): Declaration {
                $declaration = $this->createDeclaration($organization, $draft);
                $declaration->issueConfirmationToken(
                    $token,
                    $this->clock->now()->modify(self::TOKEN_LIFETIME),
                );

                return $declaration;
            },
        );

        $this->mailer->send($declaration, $token);

        return $declaration;
    }

    /**
     * Files a declaration for a volunteer logged in to this association, and confirms it.
     *
     * No confirmation mail: following the login link already proved the address, which is
     * all the mail would prove. The `confirm` transition runs as on a click — the lines move
     * with it and the typed identity reaches the Person (ApplySubmittedIdentityOnConfirmation).
     * The email typed is not looked at: the declaration is the volunteer's.
     */
    public function submitAs(Person $volunteer, DeclarationDraft $draft): Declaration
    {
        $declaration = $this->entityManager->wrapInTransaction(
            function () use ($volunteer, $draft): Declaration {
                $declaration = $this->createDeclaration($volunteer->getOrganization(), $draft, $volunteer);
                $this->confirmer->confirmBySubmission($declaration);

                return $declaration;
            },
        );

        // Confirmed as it was filed, so this is when the association hears of it. After the
        // commit, like the confirmation mail in submit().
        $this->notifier->notifyConfirmed($declaration);

        return $declaration;
    }

    private function createDeclaration(Organization $organization, DeclarationDraft $draft, ?Person $volunteer = null): Declaration
    {
        $person = $volunteer ?? $this->findOrCreatePerson($organization, $draft);

        $declaration = new Declaration(
            $organization,
            $person,
            $draft->accuracyAttested,
            $draft->expensesWaived,
        );
        // What was typed lives on the declaration until it is confirmed; see
        // Declaration::$submittedFirstName for why it must not reach the Person here.
        $declaration->declareIdentity((string) $draft->firstName, (string) $draft->lastName, $this->buildAddress($draft));
        $this->entityManager->persist($declaration);

        foreach ($draft->actions as $action) {
            // The constructor attaches itself to the declaration, and the
            // OneToMany cascades the persist.
            $this->createAction($declaration, $action);
        }

        $this->entityManager->flush();

        return $declaration;
    }

    /**
     * A volunteer is recognised by (organization, email). PersonRepository relies
     * on the Doctrine tenant filter for the organization half, which the request
     * listener has already armed — this only ever runs in an HTTP context.
     */
    private function findOrCreatePerson(Organization $organization, DeclarationDraft $draft): Person
    {
        $email = new Email((string) $draft->email);
        $address = $this->buildAddress($draft);
        $firstName = (string) $draft->firstName;
        $lastName = (string) $draft->lastName;

        $person = $this->people->findOneByEmail($email);

        if (null !== $person) {
            // Left exactly as it is. They may have moved, or corrected their name —
            // or this may be someone else typing their email. Only the confirmation
            // link tells the two apart, so the change waits for it.
            return $person;
        }

        $person = new Person($organization, $firstName, $lastName, $email, $address);
        $this->entityManager->persist($person);

        return $person;
    }

    private function buildAddress(DeclarationDraft $draft): Address
    {
        return new Address(
            $draft->addressNumber,
            (string) $draft->addressStreet,
            (string) $draft->addressPostcode,
            (string) $draft->addressCity,
            (string) $draft->addressCountry,
        );
    }

    /**
     * Re-reads the task from the database.
     *
     * NOT redundant. The flow keeps its draft in the session between steps, and
     * SessionDataStorage deep-clones it — which detaches any Doctrine entity it
     * holds. Passing that copy straight to the action makes Doctrine treat it as a
     * brand-new entity ("A new entity was found through the relationship …") and
     * the whole submission dies.
     *
     * Re-fetching also earns something: the lookup goes through the tenant-filtered
     * repository, so a type belonging to another association — or one deleted while
     * the volunteer was filling the form — cannot slip through.
     */
    private function resolveTask(?Task $detached): Task
    {
        assert(null !== $detached);

        return $this->tasks->find($detached->getId())
            ?? throw TaskNoLongerAvailableException::forId($detached->getId());
    }

    /**
     * Same re-read as resolveTask(), for the same two reasons. Null stays null: the
     * line is then named by its title.
     */
    private function resolveActivity(?Activity $detached): ?Activity
    {
        if (null === $detached) {
            return null;
        }

        return $this->activities->find($detached->getId())
            ?? throw ActivityNoLongerAvailableException::forId($detached->getId());
    }

    private function createAction(Declaration $declaration, ActionDraft $draft): DeclarationAction
    {
        assert(null !== $draft->date);

        return new DeclarationAction(
            $declaration,
            $this->resolveTask($draft->task),
            $this->resolveActivity($draft->activity),
            $draft->title,
            '' === $draft->description ? null : $draft->description,
            $draft->date,
            $draft->consecutiveDays,
            $draft->journeys,
            $draft->distanceKm,
            $draft->ownVehicle,
            $draft->fiscalPower,
            // Normalise to the two decimals the column holds, so "7.5" and "7.50"
            // are stored identically.
            number_format((float) $draft->workHours, 2, '.', ''),
            $draft->electricVehicle,
        );
    }
}
