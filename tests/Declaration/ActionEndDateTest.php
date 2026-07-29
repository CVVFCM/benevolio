<?php

declare(strict_types=1);

namespace App\Tests\Declaration;

use App\Entity\DeclarationAction;
use App\Factory\DeclarationActionFactory;
use App\Factory\OrganizationFactory;
use App\Form\Declaration\ActionDraft;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * A volunteer declares what they have already given, so an action must be over.
 *
 * The start date alone was not enough: five consecutive days from last Friday
 * still ends in the future. The rule lives on the form DTO *and* on the entity, so
 * both are checked here — the entity copy is what stops fixtures, the back-office
 * and any future import from bypassing it.
 */
final class ActionEndDateTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->validator = self::getContainer()->get(ValidatorInterface::class);
    }

    #[Test]
    public function end_date_is_the_start_plus_the_span_minus_one(): void
    {
        // A one-day action ends the day it starts.
        self::assertSame(
            '2026-05-10',
            DeclarationAction::endDateFor(new DateTimeImmutable('2026-05-10'), 1)->format('Y-m-d'),
        );
        self::assertSame(
            '2026-05-14',
            DeclarationAction::endDateFor(new DateTimeImmutable('2026-05-10'), 5)->format('Y-m-d'),
        );
    }

    #[Test]
    public function a_draft_whose_span_reaches_into_the_future_is_rejected(): void
    {
        $draft = $this->draft(new DateTimeImmutable('-2 days'), 10);

        $violations = $this->validator->validate($draft, groups: [ActionDraft::GROUP]);

        self::assertGreaterThan(0, $violations->count());
        self::assertSame('consecutiveDays', $violations->get(0)->getPropertyPath());
        self::assertStringContainsString('pas terminée', (string) $violations->get(0)->getMessage());
    }

    #[Test]
    public function a_draft_ending_today_is_accepted(): void
    {
        // Started two days ago, three days long: today is the last day.
        $draft = $this->draft(new DateTimeImmutable('-2 days'), 3);

        // Asserts on this rule alone. The bare draft trips other constraints (it
        // has no task), and counting all violations would make the test pass
        // or fail for reasons that have nothing to do with the end date.
        self::assertSame([], $this->consecutiveDaysMessages(
            $this->validator->validate($draft, groups: [ActionDraft::GROUP]),
        ));
    }

    /**
     * The entity copy of the rule — the one fixtures and the admin cannot skip.
     */
    #[Test]
    public function an_entity_whose_span_reaches_into_the_future_is_rejected(): void
    {
        $action = DeclarationActionFactory::new()
            ->for(OrganizationFactory::createOne())
            ->create(['date' => new DateTimeImmutable('-1 day'), 'consecutiveDays' => 30]);

        $violations = $this->validator->validate($action);

        self::assertGreaterThan(0, $violations->count());
        self::assertSame('consecutiveDays', $violations->get(0)->getPropertyPath());
    }

    #[Test]
    public function a_finished_entity_passes(): void
    {
        $action = DeclarationActionFactory::new()
            ->for(OrganizationFactory::createOne())
            ->create(['date' => new DateTimeImmutable('-10 days'), 'consecutiveDays' => 3]);

        self::assertCount(0, $this->validator->validate($action));
    }

    /**
     * @param iterable<\Symfony\Component\Validator\ConstraintViolationInterface> $violations
     *
     * @return list<string>
     */
    private function consecutiveDaysMessages(iterable $violations): array
    {
        $messages = [];

        foreach ($violations as $violation) {
            if ('consecutiveDays' === $violation->getPropertyPath()) {
                $messages[] = (string) $violation->getMessage();
            }
        }

        return $messages;
    }

    private function draft(DateTimeImmutable $date, int $consecutiveDays): ActionDraft
    {
        $draft = new ActionDraft();
        $draft->task = null;
        $draft->title = 'Régate du printemps';
        $draft->date = $date;
        $draft->consecutiveDays = $consecutiveDays;
        $draft->workHours = '4.00';

        return $draft;
    }
}
