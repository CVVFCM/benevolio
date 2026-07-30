<?php

declare(strict_types=1);

namespace App\Receipt;

use App\Entity\FiscalYear;
use RuntimeException;

/**
 * The answer to "may this declaration have a receipt, and for how much".
 *
 * A refusal carries its reason in French, because the reason is shown to the treasurer
 * on the declaration — "no receipt" on its own would look like a bug rather than like
 * paperwork to finish.
 */
final readonly class ReceiptAssessment
{
    private function __construct(
        public bool $isIssuable,
        public ?FiscalYear $fiscalYear,
        public int $amountCents,
        public ?string $refusalReason,
    ) {
    }

    public static function issue(FiscalYear $fiscalYear, int $amountCents): self
    {
        return new self(true, $fiscalYear, $amountCents, null);
    }

    public static function refused(string $reason): self
    {
        return new self(false, null, 0, $reason);
    }

    /**
     * The exercice, for a caller that has already checked isIssuable.
     */
    public function fiscalYear(): FiscalYear
    {
        return $this->fiscalYear ?? throw new RuntimeException('No fiscal year on a refused assessment; check isIssuable first.');
    }

    public function refusalReason(): string
    {
        return $this->refusalReason ?? throw new RuntimeException('No refusal reason on an issuable assessment.');
    }
}
