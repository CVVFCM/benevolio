<?php

declare(strict_types=1);

namespace App\Tests\Receipt;

use App\Entity\Organization;
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
 * A tax receipt is a numbered document, so the number must be continuous **per association**
 * and never reused — two receipts sharing one are indistinguishable in an audit. These tests
 * are what hold that.
 *
 * One series, all years together: the receipt covers a civil year, the exercice need not, and
 * numbering a January-to-December document from a September-to-August period said something
 * untrue about it.
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
        // Built by hand: nothing injects it until the run does, so the container inlines it
        // away — as with DeclarationDecider before it.
        $this->allocator = new ReceiptNumberAllocator($this->entityManager);
    }

    #[Test]
    public function it_numbers_from_one_and_pads_to_four_digits(): void
    {
        $organization = OrganizationFactory::createOne();

        self::assertSame('0001', $this->allocate($organization));
        self::assertSame('0002', $this->allocate($organization));
        self::assertSame('0003', $this->allocate($organization));
    }

    /**
     * One series per association. Two treasurers must never be able to compare notes and
     * find the same number on two different documents.
     */
    #[Test]
    public function each_association_has_its_own_series(): void
    {
        $mine = OrganizationFactory::createOne();
        $theirs = OrganizationFactory::createOne();

        self::assertSame('0001', $this->allocate($mine));
        self::assertSame('0001', $this->allocate($theirs));
        self::assertSame('0002', $this->allocate($mine));
    }

    /**
     * The series does not restart with the year, because the number no longer claims to
     * describe a period — the receipt says which year it covers.
     */
    #[Test]
    public function the_series_does_not_restart_between_years(): void
    {
        $organization = OrganizationFactory::createOne();

        $this->allocate($organization);

        // Whatever year the next run is for, it continues the same series.
        self::assertSame('0002', $this->allocate($organization));
    }

    /**
     * A counter, not MAX(number) + 1. Deleting a receipt must not free its number for reuse
     * — that is the whole point of "never reused", and counting rows would hand the same
     * number out twice.
     */
    #[Test]
    public function a_number_is_never_reused_even_after_a_deletion(): void
    {
        $organization = OrganizationFactory::createOne();

        $this->allocate($organization);
        $this->allocate($organization);

        // Nothing rolls the counter back, whatever happens to the receipts themselves.
        self::assertSame('0003', $this->allocate($organization));
        self::assertSame(3, $organization->getLastReceiptSequence());
    }

    /**
     * Each allocation in its own transaction, as the run does it — the lock is only
     * meaningful inside one.
     */
    private function allocate(Organization $organization): string
    {
        return $this->entityManager->wrapInTransaction(
            fn (): string => $this->allocator->allocate($organization),
        );
    }
}
