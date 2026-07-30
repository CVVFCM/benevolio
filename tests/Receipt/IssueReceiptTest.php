<?php

declare(strict_types=1);

namespace App\Tests\Receipt;

use App\Entity\Declaration;
use App\Entity\Organization;
use App\Entity\Receipt;
use App\Enum\FiscalPower;
use App\Factory\DeclarationActionFactory;
use App\Factory\DeclarationFactory;
use App\Factory\FiscalYearFactory;
use App\Factory\OrganizationFactory;
use App\State\DeclarationState;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Finite\StateMachine;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Email;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Validating a declaration issues the CERFA — or refuses to, for a reason.
 *
 * End to end on purpose, from the state transition through to the object in storage and
 * the mail: this lot's risk is in the seams, and every one of them is crossed here.
 * Gotenberg and s3mock have to be up, which `make up` and CI both do.
 *
 * The refusals matter as much as the issue. A receipt is a tax document, and the
 * expensive mistake is producing one that should not exist.
 */
final class IssueReceiptTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private EntityManagerInterface $entityManager;
    private StateMachine $stateMachine;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->stateMachine = self::getContainer()->get(StateMachine::class);
    }

    #[Test]
    public function validating_a_declaration_with_waived_travel_issues_a_receipt(): void
    {
        $organization = $this->organizationWithIdentity();
        $declaration = $this->declarationWithTravel($organization);

        $this->validate($declaration);

        $receipt = $this->reload($declaration)->getReceipt();
        self::assertNotNull($receipt);
        self::assertSame('2026-0001', $receipt->getNumber());
        // 68 km at the 5 CV rate of 0,636 = 43,248 € → 43,25 €.
        self::assertSame(4325, $receipt->getAmountCents());
        self::assertSame('2026/cerfa-camille-berthier.pdf', $receipt->getStoragePath());
    }

    /**
     * The name and address are snapshots, so a volunteer moving house later cannot alter
     * a receipt already issued.
     */
    #[Test]
    public function the_receipt_records_the_identity_as_printed(): void
    {
        $organization = $this->organizationWithIdentity();
        $declaration = $this->declarationWithTravel($organization);

        $this->validate($declaration);

        $receipt = $this->reload($declaration)->getReceipt();
        self::assertNotNull($receipt);
        self::assertSame('Camille Berthier', $receipt->getVolunteerName());
        self::assertStringContainsString('rue des Tilleuls', $receipt->getVolunteerAddress());
    }

    #[Test]
    public function the_pdf_is_written_to_storage(): void
    {
        $organization = $this->organizationWithIdentity();
        $declaration = $this->declarationWithTravel($organization);

        $this->validate($declaration);

        $path = '2026/cerfa-camille-berthier.pdf';
        $storage = self::getContainer()->get('receipts.storage');
        self::assertTrue($storage->fileExists($path));
        // A real PDF, not an empty object.
        self::assertStringStartsWith('%PDF', $storage->read($path));
    }

    #[Test]
    public function the_volunteer_is_sent_the_receipt_as_an_attachment(): void
    {
        $organization = $this->organizationWithIdentity();
        $declaration = $this->declarationWithTravel($organization);

        $this->validate($declaration);

        // Read from the message logger rather than through MailerAssertionsTrait, which
        // is a WebTestCase facility; this test drives the state machine, not a request.
        $logger = self::getContainer()->get('mailer.message_logger_listener');

        // Sent events, not getMessages(): the mailer records a MessageEvent when the
        // message is queued AND again when it is sent, so getMessages() returns the same
        // mail twice and an assertCount(1) on it fails against correct behaviour.
        $sent = array_values(array_filter(
            $logger->getEvents()->getEvents(),
            static fn (MessageEvent $event): bool => !$event->isQueued(),
        ));
        self::assertCount(1, $sent, 'Exactly one mail: the receipt, to the volunteer.');

        $message = $sent[0]->getMessage();
        self::assertInstanceOf(Email::class, $message);
        self::assertStringContainsString('2026-0001', (string) $message->getSubject());
        self::assertCount(1, $message->getAttachments());
        // The mail must say the hours are not covered; the volunteer gave them and is the
        // one who would otherwise put a wrong figure on a tax return.
        self::assertStringContainsString(
            'n\'ouvre pas droit',
            (string) $message->getTextBody(),
        );
    }

    /**
     * Donated hours are never receiptable, so a declaration of nothing but hours gets no
     * document — and the treasurer is told why rather than left guessing.
     */
    #[Test]
    public function hours_alone_are_refused_with_a_reason(): void
    {
        $organization = $this->organizationWithIdentity();
        $declaration = $this->declarationWithHoursOnly($organization);

        $this->validate($declaration);

        $reloaded = $this->reload($declaration);
        self::assertNull($reloaded->getReceipt());
        self::assertNotNull($reloaded->getReceiptWithheldReason());
        self::assertStringContainsString('aucun frais abandonné', $reloaded->getReceiptWithheldReason());
    }

    /**
     * A receipt without the SIREN/RNA is not a valid document, so none is produced.
     */
    #[Test]
    public function a_missing_siren_is_refused_with_a_reason(): void
    {
        $organization = $this->organizationWithIdentity();
        $organization->setSirenOrRna(null);
        $this->entityManager->flush();
        $declaration = $this->declarationWithTravel($organization);

        $this->validate($declaration);

        $reloaded = $this->reload($declaration);
        self::assertNull($reloaded->getReceipt());
        self::assertStringContainsString('SIREN', (string) $reloaded->getReceiptWithheldReason());
    }

    /**
     * Without an exercice there is no barème, so there is no figure to state.
     */
    #[Test]
    public function travel_outside_every_exercice_is_refused(): void
    {
        $organization = $this->organizationWithIdentity();
        // 2019 — long before the only exercice created below.
        $declaration = $this->declarationWithTravel($organization, '2019-06-21');

        $this->validate($declaration);

        $reloaded = $this->reload($declaration);
        self::assertNull($reloaded->getReceipt());
        self::assertStringContainsString('exercice', (string) $reloaded->getReceiptWithheldReason());
    }

    #[Test]
    public function two_receipts_never_share_a_number(): void
    {
        $organization = $this->organizationWithIdentity();
        $first = $this->declarationWithTravel($organization);
        $second = $this->declarationWithTravel($organization);

        $this->validate($first);
        $this->validate($second);

        self::assertSame('2026-0001', $this->reload($first)->getReceipt()?->getNumber());
        self::assertSame('2026-0002', $this->reload($second)->getReceipt()?->getNumber());
        self::assertCount(2, $this->entityManager->getRepository(Receipt::class)->findAll());
    }

    private function organizationWithIdentity(): Organization
    {
        $organization = OrganizationFactory::new()->withCerfaIdentity()->create();

        FiscalYearFactory::new()
            ->for($organization)
            ->calendarYear(2026)
            ->withPublishedBareme()
            ->create();

        return $organization;
    }

    private function declarationWithTravel(Organization $organization, string $date = '2026-06-21'): Declaration
    {
        $declaration = DeclarationFactory::new()->for($organization)->confirmed()->create([
            'person' => \App\Factory\PersonFactory::new()->with([
                'organization' => $organization,
                'firstName' => 'Camille',
                'lastName' => 'Berthier',
                'address' => new \App\ValueObject\Address('5', 'rue des Tilleuls', '08000', 'Charleville-Mézières', 'FR'),
            ]),
        ]);

        DeclarationActionFactory::new()->forDeclaration($declaration)->confirmed()->create([
            'date' => new DateTimeImmutable($date),
            'workHours' => '5.50',
            'ownVehicle' => true,
            'fiscalPower' => FiscalPower::FIVE_CV,
            'journeys' => 2,
            'distanceKm' => 34,
        ]);

        return $this->reload($declaration);
    }

    private function declarationWithHoursOnly(Organization $organization): Declaration
    {
        $declaration = DeclarationFactory::new()->for($organization)->confirmed()->create();

        DeclarationActionFactory::new()->forDeclaration($declaration)->confirmed()->create([
            'date' => new DateTimeImmutable('2026-03-15'),
            'workHours' => '7.25',
            'ownVehicle' => false,
            'fiscalPower' => null,
            'journeys' => 0,
            'distanceKm' => 0,
        ]);

        return $this->reload($declaration);
    }

    /**
     * Through the state machine, so the listener fires exactly as it does in the
     * back office. Writing the column would test nothing.
     */
    private function validate(Declaration $declaration): void
    {
        foreach ($declaration->getActions() as $action) {
            $this->stateMachine->apply($action, \App\State\DeclarationActionState::TRANSITION_VALIDATE);
        }

        $this->stateMachine->apply($declaration, DeclarationState::TRANSITION_VALIDATE);
        $this->entityManager->flush();
    }

    private function reload(Declaration $declaration): Declaration
    {
        $id = $declaration->getId();
        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Declaration::class)->find($id);
        self::assertNotNull($reloaded);

        return $reloaded;
    }
}
