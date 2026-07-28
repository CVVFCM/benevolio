<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The vehicle's *puissance fiscale*, as the brackets of the volunteer mileage
 * scale (*barème kilométrique bénévole*) actually distinguishes them.
 *
 * The scale groups everything at 3 CV and below, then 4, 5 and 6 CV, then
 * everything at 7 CV and above — so storing the raw number from the carte grise
 * would only mean mapping it back to a bracket later.
 *
 * NO euro rates here. The scale is re-published every year and its own scale for
 * volunteers is lower than the one for salaried employees; the rates belong with
 * the valuation lot, keyed by financial year, not baked into an enum.
 */
enum FiscalPower: string
{
    case THREE_CV_OR_LESS = '3_cv_or_less';
    case FOUR_CV = '4_cv';
    case FIVE_CV = '5_cv';
    case SIX_CV = '6_cv';
    case SEVEN_CV_OR_MORE = '7_cv_or_more';

    public function label(): string
    {
        return match ($this) {
            self::THREE_CV_OR_LESS => '3 CV et moins',
            self::FOUR_CV => '4 CV',
            self::FIVE_CV => '5 CV',
            self::SIX_CV => '6 CV',
            self::SEVEN_CV_OR_MORE => '7 CV et plus',
        };
    }

    /**
     * @return array<string, string> label => value, for form and EasyAdmin choices
     */
    public static function choices(): array
    {
        $choices = [];
        foreach (self::cases() as $case) {
            $choices[$case->label()] = $case->value;
        }

        return $choices;
    }
}
