<?php

declare(strict_types=1);

namespace App\Volunteer;

use App\Entity\Organization;
use App\Entity\Person;
use App\ValueObject\Email;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

use function sprintf;

/**
 * Mails a volunteer the link to their space. Sent to any address asked for: the first
 * click from a new address is how a volunteer account begins.
 */
final readonly class VolunteerLinkMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private VolunteerLink $link,
        private ClockInterface $clock,
        #[Autowire('%app.mail_from%')]
        private string $from,
    ) {
    }

    public function send(Organization $organization, Email $email, ?Person $person): void
    {
        $expiresAt = $this->clock->now()->modify(VolunteerLink::LIFETIME);

        $message = new TemplatedEmail()
            ->from(new Address($this->from, $organization->getName()))
            ->to(null === $person ? new Address($email->value) : new Address($email->value, $person->getFullName()))
            ->subject(sprintf('Votre lien de connexion — %s', $organization->getName()))
            ->htmlTemplate('emails/volunteer_link.html.twig')
            ->textTemplate('emails/volunteer_link.txt.twig')
            ->context([
                'organization' => $organization,
                'person' => $person,
                'loginUrl' => $this->link->create($organization, $email, $person?->getLoginLinkVersion() ?? 0, $expiresAt),
                'expiresAt' => $expiresAt,
            ]);

        $this->mailer->send($message);
    }
}
