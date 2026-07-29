<?php

declare(strict_types=1);

namespace App\Accounting;

/**
 * The *Plan comptable général* accounts this application books to.
 *
 * Numbers and labels are those of **règlement ANC 2018-06**, which is what applies to
 * associations since 2020. That matters more than it looks: 2018-06 **swapped** two
 * numbers from the old règlement 99-01, where 870 was *Bénévolat* and 875 *Dons en
 * nature*. It is now the other way round. Anything citing 870 for volunteer hours is
 * quoting the superseded text.
 *
 * Two families, accounted for completely differently:
 *
 * - **Classe 8** — contributions volontaires en nature. Off balance sheet, presented
 *   at the foot of the compte de résultat as two columns of equal totals (art. 211-3).
 *   Donated hours live here, and are **never receiptable**.
 * - **Classe 6/7/4** — a real flow. An abandon de frais is a genuine donation and
 *   **is** receiptable, which is what makes a CERFA possible.
 */
enum PcgAccount: string
{
    /** Débit side of bénévolat valorisé. */
    case PERSONNEL_BENEVOLE = '864';

    /** Crédit side of bénévolat valorisé. NOT 870 — see the class docblock. */
    case BENEVOLAT = '875';

    /** The charge by nature, when a volunteer's travel is booked before being waived. */
    case VOYAGES_ET_DEPLACEMENTS = '6251';

    /**
     * The volunteer's own tiers account. Art. 141-4 requires the waiver to be booked
     * against this, so the debt the association owed the volunteer is what the
     * donation extinguishes — the abandon is not credited out of nowhere.
     */
    case FRAIS_DES_BENEVOLES = '4681';

    /** Where a waived expense lands as a resource. Receiptable. */
    case ABANDONS_DE_FRAIS = '75412';

    /** The official label, as printed in règlement ANC 2018-06. */
    public function label(): string
    {
        return match ($this) {
            self::PERSONNEL_BENEVOLE => 'Personnel bénévole',
            self::BENEVOLAT => 'Bénévolat',
            self::VOYAGES_ET_DEPLACEMENTS => 'Voyages et déplacements',
            self::FRAIS_DES_BENEVOLES => 'Frais des bénévoles',
            self::ABANDONS_DE_FRAIS => 'Abandons de frais par les bénévoles',
        };
    }

    /**
     * Whether this account sits in classe 8 — off balance sheet, and outside the
     * compte de résultat proper.
     */
    public function isOffBalanceSheet(): bool
    {
        return match ($this) {
            self::PERSONNEL_BENEVOLE, self::BENEVOLAT => true,
            self::VOYAGES_ET_DEPLACEMENTS, self::FRAIS_DES_BENEVOLES, self::ABANDONS_DE_FRAIS => false,
        };
    }

    /** `864 — Personnel bénévole`, for a ledger column. */
    public function display(): string
    {
        return $this->value.' — '.$this->label();
    }
}
