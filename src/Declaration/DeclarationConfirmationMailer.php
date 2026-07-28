<?php

declare(strict_types=1);

namespace App\Declaration;

use App\Entity\Declaration;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function sprintf;

/**
 * Sends the one email a declaration produces: the confirmation link, with a recap
 * of what was declared.
 *
 * One message, not two. A separate "thank you" alongside a "please confirm" is
 * noise, and it muddles the single action the volunteer has to take.
 */
final readonly class DeclarationConfirmationMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        #[Autowire('%app.mail_from%')]
        private string $from,
    ) {
    }

    public function send(Declaration $declaration, ConfirmationToken $token): void
    {
        $organization = $declaration->getOrganization();
        $person = $declaration->getPerson();

        $url = $this->urlGenerator->generate(
            'declaration_confirm',
            [
                'organizationSlug' => $organization->getSlug(),
                'token' => $token->value,
            ],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $email = new TemplatedEmail()
            // The association's name in the From, its own address nowhere: the
            // platform sends on its behalf, and a spoofed sender domain would land
            // the mail in spam.
            ->from(new Address($this->from, $organization->getName()))
            ->to(new Address($person->getEmail()->value, $person->getFullName()))
            ->subject(sprintf('Confirmez votre déclaration de bénévolat — %s', $organization->getName()))
            ->htmlTemplate('emails/declaration_confirmation.html.twig')
            ->textTemplate('emails/declaration_confirmation.txt.twig')
            ->context([
                'declaration' => $declaration,
                'organization' => $organization,
                'person' => $person,
                'confirmationUrl' => $url,
            ]);

        $this->mailer->send($email);
    }
}
