<?php

declare(strict_types=1);

namespace App\Receipt;

use App\Accounting\ContributionValuator;
use App\Entity\Declaration;
use App\Entity\FiscalYear;
use App\Repository\FiscalYearRepository;

/**
 * Whether a validated declaration may be given a CERFA, and for how much.
 *
 * A receipt is refused far more readily than it is issued, and every refusal here is an
 * ordinary outcome rather than a failure — the association simply has paperwork to do, or
 * there is genuinely nothing to receipt. What must never happen is a document going out
 * that overstates a deduction or lacks the identity that makes it valid: CGI art. 1740 A
 * penalises amounts wrongly stated at 25%.
 *
 * **Only the abandon de frais counts.** Donated hours are a contribution volontaire en
 * nature — off balance sheet, and they open no right to a deduction whatsoever. Summing
 * them into the receipt is the single worst mistake this class could make, which is why
 * the amount comes from `mileageCents` alone and never from a total.
 */
final readonly class ReceiptEligibility
{
    public function __construct(
        private FiscalYearRepository $fiscalYears,
        private ContributionValuator $valuator,
    ) {
    }

    public function assess(Declaration $declaration): ReceiptAssessment
    {
        if (null !== $declaration->getReceipt()) {
            return ReceiptAssessment::refused(
                'Un reçu a déjà été émis pour cette déclaration.',
            );
        }

        $organization = $declaration->getOrganization();

        // Without the SIREN/RNA the document is not valid, so no document is produced.
        // Leaving the line blank would post something that fails a check.
        if (null === $organization->getSirenOrRna()) {
            return ReceiptAssessment::refused(
                'L\'association n\'a pas de numéro SIREN ou RNA : un reçu sans ce numéro '
                .'ne serait pas valable.',
            );
        }

        if (null === $organization->getPostalAddress()) {
            return ReceiptAssessment::refused(
                'L\'adresse postale de l\'association est incomplète.',
            );
        }

        $fiscalYear = $this->fiscalYearFor($declaration);

        if (null === $fiscalYear) {
            return ReceiptAssessment::refused(
                'Aucun exercice comptable ne couvre la date de cette déclaration : sans '
                .'barème pour la période, aucun montant ne peut être établi.',
            );
        }

        $amountCents = $this->waivedExpensesCents($declaration, $fiscalYear);

        // Hours only. There is nothing receiptable, and a receipt for 0 € would invite a
        // volunteer to claim a deduction they are not owed.
        if (0 === $amountCents) {
            return ReceiptAssessment::refused(
                'Cette déclaration ne comporte aucun frais abandonné : le temps donné '
                .'n\'ouvre pas droit à un reçu fiscal.',
            );
        }

        return ReceiptAssessment::issue($fiscalYear, $amountCents);
    }

    /**
     * The exercice covering the declaration, decided by its **first** action's date.
     *
     * A declaration's lines all belong to one exercice in practice, and
     * FiscalYear::contains() settles a straddling action on its start date — so taking
     * the earliest line is the same answer, reached without asking the question twice.
     */
    private function fiscalYearFor(Declaration $declaration): ?FiscalYear
    {
        $earliest = null;

        foreach ($declaration->getActions() as $action) {
            $date = $action->getDate();

            if (null === $earliest || $date < $earliest) {
                $earliest = $date;
            }
        }

        if (null === $earliest) {
            return null;
        }

        return $this->fiscalYears->findForDate($declaration->getOrganization(), $earliest);
    }

    /**
     * The waived travel expenses, in cents. **Never the hours.**.
     */
    private function waivedExpensesCents(Declaration $declaration, FiscalYear $fiscalYear): int
    {
        $total = 0;

        foreach ($declaration->getActions() as $action) {
            $total += $this->valuator->valueWithin($action, $fiscalYear)->mileageCents;
        }

        return $total;
    }
}
