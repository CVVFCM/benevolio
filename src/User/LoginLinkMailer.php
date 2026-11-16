<?php

declare(strict_types=1);

namespace App\User;

use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Security\Http\LoginLink\LoginLinkHandlerInterface;

use function sprintf;

/**
 * Emails a back-office account a login link — the only way anyone gets a password
 * here. Nobody types a password for someone else: an account is created without
 * one, the link logs its holder in, and App\Security\ChoosePasswordEnforcer keeps
 * them on « Choisissez votre mot de passe » until they have set their own.
 *
 * Two lifetimes, because the two mails are read differently. An invitation reaches
 * a new colleague who may not open it the same day; a forgotten-password link is
 * clicked within minutes or not at all, and a short window limits what a leaked
 * one is worth.
 *
 * Symfony's login link rather than symfonycasts/reset-password-bundle, which has no
 * tagged release supporting Symfony 8. The link is signed with the account's id,
 * email and password hash (config/packages/security.yaml), so it stops working the
 * moment a password is set or changed.
 */
final readonly class LoginLinkMailer
{
    public const int INVITATION_LIFETIME = 7 * 24 * 3600;
    public const int PASSWORD_RESET_LIFETIME = 3600;

    /** The From display name when there is no association to send on behalf of. */
    private const string PLATFORM_NAME = 'Benevolio';

    public function __construct(
        private MailerInterface $mailer,
        // The main firewall's own handler, not the firewall-aware alias, which
        // picks a firewall from the current request and so fails without one.
        // Lazy, because its SignatureHasher refuses an empty kernel.secret on
        // construction, and EasyAdmin's route loader builds every CRUD controller —
        // so this service — during cache:warmup in the Docker build, where
        // APP_SECRET is deliberately empty.
        #[Autowire(service: 'security.authenticator.login_link_handler.main', lazy: true)]
        private LoginLinkHandlerInterface $loginLinkHandler,
        #[Autowire('%app.mail_from%')]
        private string $from,
    ) {
    }

    public function sendInvitation(User $user): void
    {
        $this->send(
            $user,
            self::INVITATION_LIFETIME,
            sprintf('Votre accès à l\'administration — %s', $this->senderName($user)),
            'emails/invitation',
        );
    }

    public function sendPasswordReset(User $user): void
    {
        $this->send(
            $user,
            self::PASSWORD_RESET_LIFETIME,
            sprintf('Choisissez un nouveau mot de passe — %s', $this->senderName($user)),
            'emails/password_reset',
        );
    }

    private function send(User $user, int $lifetime, string $subject, string $template): void
    {
        $link = $this->loginLinkHandler->createLoginLink($user, null, $lifetime);

        $email = new TemplatedEmail()
            // Same convention as DeclarationConfirmationMailer: the platform's own
            // verified address, with the association's name for display.
            ->from(new Address($this->from, $this->senderName($user)))
            ->to(new Address($user->getEmail()))
            ->subject($subject)
            ->htmlTemplate($template.'.html.twig')
            ->textTemplate($template.'.txt.twig')
            ->context([
                'user' => $user,
                'organization' => $user->getOrganization(),
                'senderName' => $this->senderName($user),
                'loginUrl' => $link->getUrl(),
                'expiresAt' => $link->getExpiresAt(),
            ]);

        $this->mailer->send($email);
    }

    private function senderName(User $user): string
    {
        return $user->getOrganization()?->getName() ?? self::PLATFORM_NAME;
    }
}
