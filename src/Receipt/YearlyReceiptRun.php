<?php

declare(strict_types=1);

namespace App\Receipt;

use App\Accounting\ContributionValuator;
use App\Entity\FiscalYear;
use App\Entity\Organization;
use App\Entity\Receipt;
use App\Repository\DeclarationActionRepository;
use App\Repository\FiscalYearRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Throwable;

use function array_values;
use function sprintf;

/**
 * Issues every reçu fiscal of one civil year, one per volunteer.
 *
 * This is the only thing that creates a receipt. Nothing reacts to a declaration being
 * validated any more — a receipt is a year of one volunteer's waived expenses, and no single
 * declaration decides it.
 *
 * **Each action is priced by the exercice covering its own date**, through
 * App\Accounting\ContributionValuator. That is what lets a September-to-August exercice
 * coexist with a January-to-December receipt: the rates follow the association's books, the
 * total follows the tax year. A line no exercice covers has no barème and therefore no
 * figure — it is left out of the amount and counted in the report, never priced at zero.
 *
 * **Only the abandon de frais.** Donated hours are a contribution volontaire en nature and
 * open no right to a deduction; including them would overstate what the volunteer may claim,
 * which CGI art. 1740 A penalises at 25% of the amounts wrongly stated.
 *
 * **One transaction per volunteer**, and the mail sent after their commit. A failure on the
 * twelfth volunteer must not undo the first eleven, and a mail relay blinking must not
 * discard a document that exists and is filed — the volunteer can be sent it again.
 */
final readonly class YearlyReceiptRun
{
    public function __construct(
        private DeclarationActionRepository $actions,
        private FiscalYearRepository $fiscalYears,
        private ReceiptEligibility $eligibility,
        private ContributionValuator $valuator,
        private ReceiptNumberAllocator $numbers,
        private ReceiptGenerator $generator,
        private ReceiptStorage $storage,
        private ReceiptMailer $mailer,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function run(Organization $organization, int $year): ReceiptRunReport
    {
        $refusal = $this->eligibility->refusalFor($organization);

        if (null !== $refusal) {
            return ReceiptRunReport::refused($year, $refusal);
        }

        $fiscalYear = $this->fiscalYears->findFirstForCivilYear($organization, $year);

        if (null === $fiscalYear) {
            return ReceiptRunReport::refused($year, sprintf(
                'Aucun exercice comptable ne couvre l\'année %d : sans barème pour la période, '
                .'aucun montant ne peut être établi.',
                $year,
            ));
        }

        // The rates have to be frozen before a document quotes them. See the class docblock.
        if (!$fiscalYear->getState()->allowsReceipts()) {
            return ReceiptRunReport::refused($year, sprintf(
                'L\'exercice « %s » n\'est pas clôturé. Ses taux peuvent encore changer, donc '
                .'aucun reçu ne peut être émis pour %d : clôturez-le d\'abord depuis '
                .'« Exercices comptables ».',
                $fiscalYear->getName(),
                $year,
            ));
        }

        $outcomes = [];

        foreach ($this->waivedByVolunteer($organization, $year, $fiscalYear) as $waived) {
            $outcomes[] = $waived->amountCents > 0
                ? $this->issue($organization, $year, $waived)
                : ReceiptRunOutcome::skipped(
                    $waived->person,
                    'Aucun frais abandonné sur l\'année : le temps donné n\'ouvre pas droit '
                    .'à un reçu fiscal.',
                );
        }

        return ReceiptRunReport::of($year, $outcomes);
    }

    /**
     * The year's validated lines, added up per volunteer.
     *
     * @return list<WaivedYear>
     */
    private function waivedByVolunteer(Organization $organization, int $year, FiscalYear $fiscalYear): array
    {
        /** @var array<string, WaivedYear> $totals */
        $totals = [];

        foreach ($this->actions->findValidatedInCivilYear($organization, $year) as $action) {
            $person = $action->getDeclaration()->getPerson();
            $key = $person->getId()->toRfc4122();

            $totals[$key] ??= new WaivedYear($person);
            // valueWithin(), not value(): every line of the year is priced by the one exercice
            // decided above, whatever exercice happens to cover its own date. There is no
            // "unvalued" line left on this path — the year either has an exercice or the whole
            // run was refused.
            $totals[$key]->add($action, $this->valuator->valueWithin($action, $fiscalYear)->mileageCents);
        }

        return array_values($totals);
    }

    private function issue(Organization $organization, int $year, WaivedYear $waived): ReceiptRunOutcome
    {
        $issuedAt = $this->clock->now();

        // The number, the object and the row commit together: an allocated number that
        // never became a receipt is a gap in a sequence that must be continuous, and a row
        // pointing at an object that was never written is a receipt nobody can produce.
        $receipt = $this->entityManager->wrapInTransaction(
            function () use ($organization, $year, $waived, $issuedAt): Receipt {
                $number = $this->numbers->allocate($organization);

                $values = ReceiptValues::forYear(
                    $organization,
                    $waived->person,
                    $number,
                    $waived->amountCents,
                    $waived->lastWaivedDay(),
                    $issuedAt,
                );

                $pdf = $this->generator->generate($values->forOverlay(), $values->imagesForOverlay());
                $path = $this->storage->store($year, $waived->person, $number, $pdf);

                $receipt = new Receipt(
                    $waived->person,
                    $year,
                    $number,
                    $waived->amountCents,
                    $path,
                    $values->volunteerName,
                    $values->volunteerAddress,
                    $issuedAt,
                );

                $this->entityManager->persist($receipt);

                return $receipt;
            },
        );

        try {
            $this->mailer->send($receipt, $this->storage->read($receipt->getStoragePath()));
        } catch (Throwable $e) {
            // Logged, not thrown: the receipt exists and is filed, and the report says it
            // was issued. Losing the record because an SMTP server blinked would be worse.
            $this->logger->error('Receipt {number} was issued but could not be emailed.', [
                'number' => $receipt->getNumber(),
                'exception' => $e,
            ]);
        }

        return ReceiptRunOutcome::issued($receipt);
    }
}
