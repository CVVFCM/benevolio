<?php

declare(strict_types=1);

namespace App\State;

/**
 * What App\State\DeclarationState and App\State\DeclarationActionState share: the same
 * four cases, read the same way.
 *
 * Two enums rather than one, because they are two state machines — finite binds a
 * machine to an enum, and a declaration and its lines move separately (see
 * App\State\Listener\DeclarationTransitionGuard). The behaviour is the same, so it is
 * written once, here; a using enum declares its cases and its transitions.
 *
 * **TRAP when adding a state:** isDecided() stays validated-or-refused, spelled out.
 * `SUBMITTED !== $this` once called an unconfirmed line decided, the moment a state
 * existed before SUBMITTED. And a new case needs a label() and a badgeStyle() arm — the
 * `match` makes PHP throw on a forgotten one, and the maps below follow ::cases().
 *
 * @phpstan-require-implements \BackedEnum
 */
trait DeclarationLifecycle
{
    /**
     * The volunteer has not clicked the confirmation link yet, so the association has
     * nothing to act on.
     */
    public function isAwaitingConfirmation(): bool
    {
        return self::AWAITING_CONFIRMATION === $this;
    }

    /**
     * A treasurer has ruled, one way or the other. Not the same as "not submitted": an
     * unconfirmed one is undecided too, so callers asking "can a verdict be applied" must
     * check isAwaitingConfirmation() as well.
     */
    public function isDecided(): bool
    {
        return self::VALIDATED === $this || self::REFUSED === $this;
    }

    public function label(): string
    {
        return match ($this) {
            self::AWAITING_CONFIRMATION => 'En attente de confirmation',
            self::SUBMITTED => 'Soumise',
            self::VALIDATED => 'Validée',
            self::REFUSED => 'Refusée',
        };
    }

    public function badgeStyle(): string
    {
        return match ($this) {
            self::AWAITING_CONFIRMATION => 'secondary',
            self::SUBMITTED => 'warning',
            self::VALIDATED => 'success',
            self::REFUSED => 'danger',
        };
    }

    /**
     * label => value, for a ChoiceField's setChoices().
     *
     * @return array<string, string>
     */
    public static function choices(): array
    {
        $choices = [];
        foreach (self::cases() as $state) {
            $choices[$state->label()] = $state->value;
        }

        return $choices;
    }

    /**
     * value => Bootstrap style, for a ChoiceField's renderAsBadges(). Built from ::cases()
     * so a new state cannot be left without a badge.
     *
     * @return array<string, string>
     */
    public static function badgeStyles(): array
    {
        $styles = [];
        foreach (self::cases() as $state) {
            $styles[$state->value] = $state->badgeStyle();
        }

        return $styles;
    }
}
