<?php

declare(strict_types=1);

namespace App\Declaration;

use App\Accounting\ContributionValuator;
use App\Entity\Declaration;
use App\Entity\DeclarationAction;
use App\Entity\User;
use App\Repository\UserRepository;
use App\State\DeclarationActionState;
use App\ValueObject\Money;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function array_map;
use function sprintf;

/**
 * The two alerts a declaration produces once it exists (#61): the association's admins are
 * told when one is confirmed, and the volunteer when every line of theirs has been ruled on.
 *
 * Neither is a step of anything, unlike the confirmation link: a declaration is confirmed,
 * or ruled on, whether or not these arrive. So they are CALLED AFTER THE COMMIT, by the use
 * cases themselves (DeclarationConfirmer, DeclarationSubmitter, DeclarationDecider) and not
 * from a state-machine listener, which runs inside the transaction and would announce
 * something a rollback then undid. And a relay that refuses the message is logged, never
 * raised: the page must not answer 500 for an action that was recorded.
 */
final readonly class DeclarationNotifier
{
    public function __construct(
        private MailerInterface $mailer,
        private UserRepository $users,
        private ContributionValuator $valuator,
        private UrlGeneratorInterface $urlGenerator,
        private LoggerInterface $logger,
        #[Autowire('%app.mail_from%')]
        private string $from,
    ) {
    }

    /**
     * Tells the association a declaration is waiting for a verdict.
     *
     * One message, every active admin in To, by decision: they see who else was told.
     * An account still on its invitation is left out, since nobody has opened that mailbox
     * for this application yet. Not called for a manual confirmation: the admin who did it
     * is looking at the declaration.
     */
    public function notifyConfirmed(Declaration $declaration): void
    {
        $organization = $declaration->getOrganization();
        $recipients = array_map(
            static fn (User $user): Address => new Address($user->getEmail()),
            $this->users->findActiveOf($organization),
        );

        if ([] === $recipients) {
            return;
        }

        $person = $declaration->getPerson();

        $email = new TemplatedEmail()
            ->from(new Address($this->from, $organization->getName()))
            ->to(...$recipients)
            ->subject(sprintf('Nouvelle déclaration de %s · %s', $person->getFullName(), $organization->getName()))
            ->htmlTemplate('emails/declaration_confirmed.html.twig')
            ->textTemplate('emails/declaration_confirmed.txt.twig')
            ->context([
                'declaration' => $declaration,
                'organization' => $organization,
                'person' => $person,
                'declarationUrl' => $this->urlGenerator->generate(
                    'admin_declaration_detail',
                    ['entityId' => $declaration->getId()->toRfc4122()],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                ),
            ]);

        $this->send($email, $declaration, 'confirmed');
    }

    /**
     * Tells the volunteer what became of their declaration, once no line is left to rule on.
     *
     * The caller decides WHEN, and must call this once: a declaration whose lines were ruled
     * on one by one is finished at the last of them, and « Valider tout » afterwards changes
     * nothing the volunteer has not been told.
     *
     * The amount is the abandon de frais of the accepted lines ONLY, never the valued hours:
     * donated time opens no right to a deduction, and a figure mixing the two is the one a
     * volunteer would copy onto a tax return. It is also only indicative, and the mail says
     * so: a rate corrected on the exercice changes it, and the receipt alone is authoritative.
     */
    public function notifyDecided(Declaration $declaration): void
    {
        $organization = $declaration->getOrganization();
        $person = $declaration->getPerson();

        $accepted = [];
        $refused = [];
        $waived = new Money(0);
        $unvalued = 0;

        foreach ($declaration->getActions() as $action) {
            if (DeclarationActionState::VALIDATED !== $action->getState()) {
                $refused[] = $action;

                continue;
            }

            $amount = $this->waivedAmount($action);
            $accepted[] = ['action' => $action, 'waived' => $amount];

            if (null !== $amount) {
                $waived = $waived->plus($amount);
            } elseif ($action->usesOwnVehicle()) {
                // No exercice covers the line: there is no barème to price it with, and
                // saying so beats printing a total that silently leaves it out.
                ++$unvalued;
            }
        }

        $email = new TemplatedEmail()
            ->from(new Address($this->from, $organization->getName()))
            ->to(new Address($person->getEmail()->value, $person->getFullName()))
            ->subject(sprintf('Votre déclaration de bénévolat a été traitée · %s', $organization->getName()))
            ->htmlTemplate('emails/declaration_decided.html.twig')
            ->textTemplate('emails/declaration_decided.txt.twig')
            ->context([
                'declaration' => $declaration,
                'organization' => $organization,
                'person' => $person,
                'accepted' => $accepted,
                'refused' => $refused,
                'waived' => $waived,
                'unvalued' => $unvalued,
                'spaceUrl' => $this->urlGenerator->generate(
                    'volunteer_declarations',
                    ['organizationSlug' => $organization->getSlug()],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                ),
            ]);

        $this->send($email, $declaration, 'decided');
    }

    /**
     * What the line waives, or null when it waives nothing or cannot be priced.
     */
    private function waivedAmount(DeclarationAction $action): ?Money
    {
        if (!$action->usesOwnVehicle()) {
            return null;
        }

        return $this->valuator->value($action)?->mileage;
    }

    private function send(TemplatedEmail $email, Declaration $declaration, string $alert): void
    {
        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('A declaration alert could not be sent.', [
                'alert' => $alert,
                'declaration' => $declaration->getId()->toRfc4122(),
                'exception' => $e,
            ]);
        }
    }
}
