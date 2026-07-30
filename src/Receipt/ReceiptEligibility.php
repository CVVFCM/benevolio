<?php

declare(strict_types=1);

namespace App\Receipt;

use App\Entity\Organization;

/**
 * Whether an association is in a position to issue reçus fiscaux at all.
 *
 * Association-level only, and answered **once per run** rather than once per volunteer: a
 * missing SIREN is not fifty refusals, it is one thing to go and fix. Whether a particular
 * volunteer has anything to receipt is arithmetic, and belongs to
 * App\Receipt\YearlyReceiptRun which is doing the adding up.
 *
 * A refusal is an ordinary outcome, not a failure: the association simply has paperwork to
 * do. What must never happen is a document going out that lacks the identity making it
 * valid — CGI art. 1740 A penalises amounts wrongly stated at 25%, and a receipt nobody can
 * trace back to a real association is worse than no receipt.
 */
final readonly class ReceiptEligibility
{
    /**
     * The reason this association cannot issue receipts, or null when it can.
     *
     * French, because it is shown to the treasurer as it is.
     */
    public function refusalFor(Organization $organization): ?string
    {
        // Without the SIREN/RNA the document is not valid, so no document is produced.
        // Leaving the line blank would post something that fails a check.
        if (null === $organization->getSirenOrRna()) {
            return 'L\'association n\'a pas de numéro SIREN ou RNA : un reçu sans ce numéro '
                .'ne serait pas valable. Renseignez-le dans « Mon association ».';
        }

        if (null === $organization->getPostalAddress()) {
            return 'L\'adresse postale de l\'association est incomplète. '
                .'Complétez-la dans « Mon association ».';
        }

        return null;
    }
}
