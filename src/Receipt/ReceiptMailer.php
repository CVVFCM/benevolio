<?php

declare(strict_types=1);

namespace App\Receipt;

use App\Entity\Receipt;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

use function sprintf;

/**
 * Sends the volunteer their CERFA.
 *
 * Same shape as App\Declaration\DeclarationConfirmationMailer: the association's name in
 * the display name, the platform's own address as the sender, because the platform sends
 * on their behalf and a spoofed sender domain lands in spam.
 *
 * The body says plainly that the receipt covers **only** the expenses waived, and that
 * donated hours give no deduction. The volunteer is the person most likely to assume
 * otherwise — they gave the hours — and they are the one who would put a wrong figure on
 * a tax return.
 */
final readonly class ReceiptMailer
{
    public function __construct(
        private MailerInterface $mailer,
        #[Autowire('%app.mail_from%')]
        private string $from,
    ) {
    }

    public function send(Receipt $receipt, string $pdf): void
    {
        $organization = $receipt->getOrganization();
        $person = $receipt->getPerson();

        $email = new TemplatedEmail()
            ->from(new Address($this->from, $organization->getName()))
            ->to(new Address($person->getEmail()->value, $person->getFullName()))
            ->subject(sprintf(
                'Votre reçu fiscal %d n° %s — %s',
                $receipt->getYear(),
                $receipt->getNumber(),
                $organization->getName(),
            ))
            ->htmlTemplate('emails/receipt.html.twig')
            ->textTemplate('emails/receipt.txt.twig')
            ->context([
                'receipt' => $receipt,
                'organization' => $organization,
                'person' => $person,
            ])
            // Attached rather than linked: the volunteer needs to keep this for their
            // tax return, and a link would eventually stop working.
            ->attach($pdf, sprintf('recu-fiscal-%d-%s.pdf', $receipt->getYear(), $receipt->getNumber()), 'application/pdf');

        $this->mailer->send($email);
    }
}
