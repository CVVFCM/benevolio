<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Declaration;
use App\Entity\Receipt;
use App\Message\IssueReceipt;
use App\Receipt\ReceiptEligibility;
use App\Receipt\ReceiptGenerator;
use App\Receipt\ReceiptMailer;
use App\Receipt\ReceiptNumberAllocator;
use App\Receipt\ReceiptStorage;
use App\Receipt\ReceiptValues;
use App\Repository\DeclarationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

/**
 * Issues the CERFA for a validated declaration: decide, number, generate, store, send.
 *
 * Runs on the `sync` transport today, so this happens inside the request that validated
 * the declaration. Everything here is written so that moving it to a real transport is a
 * routing change and nothing more — hence taking an id and re-reading the declaration.
 *
 * **A refusal is not a failure.** Nothing waived, no exercice, no SIREN: the reason is
 * recorded on the declaration and the handler returns. Throwing would retry forever
 * against a condition only a human can clear.
 *
 * A genuine fault — Gotenberg down, the bucket unreachable — does throw, because that is
 * worth retrying. The number is allocated inside the same transaction as the receipt, so
 * a failure mid-way does not burn one.
 */
#[AsMessageHandler]
final readonly class IssueReceiptHandler
{
    public function __construct(
        private DeclarationRepository $declarations,
        private ReceiptEligibility $eligibility,
        private ReceiptNumberAllocator $numbers,
        private ReceiptGenerator $generator,
        private ReceiptStorage $storage,
        private ReceiptMailer $mailer,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(IssueReceipt $message): void
    {
        $declaration = $this->declarations->find($message->declarationId);

        if (!$declaration instanceof Declaration) {
            // Deleted between the transition and here. Nothing to issue, nothing wrong.
            return;
        }

        $assessment = $this->eligibility->assess($declaration);

        if (!$assessment->isIssuable) {
            $declaration->withholdReceipt($assessment->refusalReason());
            $this->entityManager->flush();

            return;
        }

        $fiscalYear = $assessment->fiscalYear();
        $issuedAt = $this->clock->now();

        // The number and the receipt commit together: an allocated number that never
        // became a receipt would be a gap in a sequence that must be continuous.
        $receipt = $this->entityManager->wrapInTransaction(
            function () use ($declaration, $fiscalYear, $assessment, $issuedAt): Receipt {
                $number = $this->numbers->allocate($fiscalYear);
                $values = ReceiptValues::from($declaration, $number, $assessment->amountCents, $issuedAt);

                $pdf = $this->generator->generate($values->forOverlay(), $values->imagesForOverlay());
                $path = $this->storage->store($fiscalYear, $declaration->getPerson(), $pdf);

                $receipt = new Receipt(
                    $declaration,
                    $fiscalYear,
                    $number,
                    $assessment->amountCents,
                    $path,
                    $values->volunteerName,
                    $values->volunteerAddress,
                    $issuedAt,
                );

                $this->entityManager->persist($receipt);

                return $receipt;
            },
        );

        // Sent after the receipt is committed, and failing to send does not undo it: the
        // document exists and is filed, so the volunteer can be sent it again. Losing the
        // record because an SMTP server blinked would be worse.
        try {
            $this->mailer->send($receipt, $this->storage->read($receipt->getStoragePath()));
        } catch (Throwable $e) {
            $this->logger->error('Receipt {number} was issued but could not be emailed.', [
                'number' => $receipt->getNumber(),
                'exception' => $e,
            ]);
        }
    }
}
