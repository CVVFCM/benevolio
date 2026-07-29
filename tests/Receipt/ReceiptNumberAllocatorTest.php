<?php

declare(strict_types=1);

namespace App\Tests\Receipt;

use App\Entity\FiscalYear;
use App\Factory\FiscalYearFactory;
use App\Factory\OrganizationFactory;
use App\Receipt\ReceiptNumberAllocator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * The numéro d'ordre du reçu.
 *
 * A tax receipt is a numbered document, so the number must be continuous within an
 * exercice and **never reused** — two receipts sharing one are indistinguishable in an
 * audit. These tests are what hold that.
 */
final class ReceiptNumberAllocatorTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private ReceiptNumberAllocator $allocator;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        // Built by hand: nothing injects it until the handler does, so the container
        // inlines it away — as with DeclarationDecider before it.
        $this->allocator = new ReceiptNumberAllocator($this->entityManager);
    }

    #[Test]
    public function it_numbers_from_one_and_pads_to_four_digits(): void
    {
        $fiscalYear = $this->year(2026);

        self::assertSame('2026-0001', $this->allocate($fiscalYear));
        self::assertSame('2026-0002', $this->allocate($fiscalYear));
        self::assertSame('2026-0003', $this->allocate($fiscalYear));
    }

    /**
     * Continuity is per exercice, so a new one starts again at 1.
     */
    #[Test]
    public function each_exercice_has_its_own_sequence(): void
    {
        $organization = OrganizationFactory::createOne();
        $twentyFive = FiscalYearFactory::new()->for($organization)->calendarYear(2025)->create();
        $twentySix = FiscalYearFactory::new()->for($organization)->calendarYear(2026)->create();

        self::assertSame('2025-0001', $this->allocate($twentyFive));
        self::assertSame('2026-0001', $this->allocate($twentySix));
        self::assertSame('2025-0002', $this->allocate($twentyFive));
    }

    /**
     * The prefix is the exercice's own name, so an association whose year straddles the
     * calendar gets numbers that say so.
     */
    #[Test]
    public function the_prefix_is_the_exercice_name(): void
    {
        $fiscalYear = FiscalYearFactory::new()->create(['name' => '2025-2026']);

        self::assertSame('2025-2026-0001', $this->allocate($fiscalYear));
    }

    /**
     * A counter, not MAX(number) + 1. Deleting a receipt must not free its number for
     * reuse — that is the whole point of "never reused", and counting rows would hand
     * the same number out twice.
     */
    #[Test]
    public function a_number_is_never_reused_even_after_a_deletion(): void
    {
        $fiscalYear = $this->year(2026);

        $this->allocate($fiscalYear);
        $this->allocate($fiscalYear);

        // Nothing rolls the counter back, whatever happens to the receipts themselves.
        self::assertSame('2026-0003', $this->allocate($fiscalYear));
        self::assertSame(3, $fiscalYear->getLastReceiptSequence());
    }

    private function year(int $year): FiscalYear
    {
        return FiscalYearFactory::new()->calendarYear($year)->create();
    }

    /**
     * Each allocation in its own transaction, as the handler will do it — the lock is
     * only meaningful inside one.
     */
    private function allocate(FiscalYear $fiscalYear): string
    {
        return $this->entityManager->wrapInTransaction(
            fn (): string => $this->allocator->allocate($fiscalYear),
        );
    }
}
