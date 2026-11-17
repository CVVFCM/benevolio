<?php

declare(strict_types=1);

namespace App\Tests\State;

use App\State\DeclarationActionState;
use App\State\DeclarationState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The behaviour both state enums take from App\State\DeclarationLifecycle, checked on
 * each: a trait is only as right as the cases of the enum using it.
 */
final class DeclarationLifecycleTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string<DeclarationState|DeclarationActionState>}>
     */
    public static function enums(): iterable
    {
        yield 'declaration' => [DeclarationState::class];
        yield 'line' => [DeclarationActionState::class];
    }

    /**
     * @param class-string<DeclarationState|DeclarationActionState> $enum
     */
    #[Test]
    #[DataProvider('enums')]
    public function only_a_verdict_is_decided(string $enum): void
    {
        $decided = array_values(array_filter($enum::cases(), static fn (DeclarationState|DeclarationActionState $state): bool => $state->isDecided()));

        self::assertSame([$enum::VALIDATED, $enum::REFUSED], $decided);
        self::assertFalse($enum::AWAITING_CONFIRMATION->isDecided());
    }

    /**
     * @param class-string<DeclarationState|DeclarationActionState> $enum
     */
    #[Test]
    #[DataProvider('enums')]
    public function only_the_first_state_awaits_confirmation(string $enum): void
    {
        $awaiting = array_values(array_filter($enum::cases(), static fn (DeclarationState|DeclarationActionState $state): bool => $state->isAwaitingConfirmation()));

        self::assertSame([$enum::AWAITING_CONFIRMATION], $awaiting);
    }

    /**
     * @param class-string<DeclarationState|DeclarationActionState> $enum
     */
    #[Test]
    #[DataProvider('enums')]
    public function every_state_has_a_choice_and_a_badge(string $enum): void
    {
        $values = array_map(static fn (DeclarationState|DeclarationActionState $state): string => $state->value, $enum::cases());

        self::assertSame($values, array_values($enum::choices()));
        self::assertSame($values, array_keys($enum::badgeStyles()));
        self::assertSame('Soumise', array_search($enum::SUBMITTED->value, $enum::choices(), true));
        self::assertSame('success', $enum::badgeStyles()[$enum::VALIDATED->value] ?? null);
    }
}
