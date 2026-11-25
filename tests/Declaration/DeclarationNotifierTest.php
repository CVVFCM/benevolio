<?php

declare(strict_types=1);

namespace App\Tests\Declaration;

use App\Accounting\ContributionValuator;
use App\Declaration\ConfirmationToken;
use App\Declaration\DeclarationConfirmer;
use App\Declaration\DeclarationDecider;
use App\Declaration\DeclarationNotifier;
use App\Entity\Declaration;
use App\Entity\DeclarationAction;
use App\Entity\Organization;
use App\Factory\DeclarationActionFactory;
use App\Factory\DeclarationFactory;
use App\Factory\FiscalYearFactory;
use App\Factory\OrganizationFactory;
use App\Factory\PersonFactory;
use App\Factory\UserFactory;
use App\Repository\UserRepository;
use App\State\DeclarationState;
use App\Tests\Support\ReloadsEntities;
use App\ValueObject\Email as EmailAddress;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\AbstractLogger;
use Stringable;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

use function array_map;
use function array_values;
use function sprintf;

/**
 * The two alerts of #61, driven through the use cases that send them: what matters is
 * WHEN each goes out and to whom, which the notifier alone does not decide.
 */
final class DeclarationNotifierTest extends KernelTestCase
{
    use Factories;
    use ReloadsEntities;
    use ResetDatabase;

    private DeclarationConfirmer $confirmer;
    private DeclarationDecider $decider;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->confirmer = self::getContainer()->get(DeclarationConfirmer::class);
        $this->decider = self::getContainer()->get(DeclarationDecider::class);
    }

    #[Test]
    public function confirming_tells_every_active_admin_of_the_association_in_one_message(): void
    {
        $organization = OrganizationFactory::createOne(['name' => 'Les Jardins']);
        UserFactory::new()->owner($organization)->create(['email' => 'presidente@jardins.test']);
        UserFactory::new()->admin($organization)->create(['email' => 'tresorier@jardins.test']);
        // Neither of these has anything to read: one has never opened the application,
        // the other belongs elsewhere.
        UserFactory::new()->admin($organization)->invited()->create(['email' => 'invite@jardins.test']);
        UserFactory::new()->admin(OrganizationFactory::createOne())->create(['email' => 'ailleurs@example.test']);
        UserFactory::new()->superAdmin()->create(['email' => 'plateforme@example.test']);

        [$declaration, $token] = $this->awaitingDeclaration($organization, 'Camille', 'Martin');

        $this->confirmer->confirm($token);

        self::assertEmailCount(1);
        $email = self::sentEmail(0);
        self::assertSame(
            ['presidente@jardins.test', 'tresorier@jardins.test'],
            array_map(static fn (Address $to): string => $to->getAddress(), $email->getTo()),
        );
        self::assertSame('Nouvelle déclaration de Camille Martin · Les Jardins', $email->getSubject());
        self::assertSame(['Les Jardins'], array_map(static fn (Address $from): string => $from->getName(), $email->getFrom()));

        $url = sprintf('/admin/declaration/%s', $declaration->getId()->toRfc4122());
        foreach ([(string) $email->getTextBody(), (string) $email->getHtmlBody()] as $body) {
            self::assertStringContainsString($url, $body);
            self::assertStringContainsString('Camille Martin', $body);
            self::assertStringContainsString('Corvée de printemps', $body);
            // The volunteer's own coordinates stay in the back office.
            self::assertStringNotContainsString($declaration->getPerson()->getEmail()->value, $body);
            // No em-dash in anything a person reads.
            self::assertStringNotContainsString('—', $body);
        }
    }

    #[Test]
    public function a_repeat_click_tells_nobody_again(): void
    {
        $organization = OrganizationFactory::createOne();
        UserFactory::new()->admin($organization)->create();
        [, $token] = $this->awaitingDeclaration($organization);

        $this->confirmer->confirm($token);
        $this->confirmer->confirm($token);

        self::assertEmailCount(1);
    }

    #[Test]
    public function a_manual_confirmation_tells_nobody(): void
    {
        $organization = OrganizationFactory::createOne();
        [$declaration] = $this->awaitingDeclaration($organization);
        // After it: the helper clears the EntityManager, which would detach the account.
        $admin = UserFactory::new()->admin($organization)->create();

        $this->confirmer->confirmManually($declaration, $admin);

        self::assertSame(DeclarationState::SUBMITTED, $declaration->getState());
        self::assertEmailCount(0);
    }

    #[Test]
    public function an_association_without_an_active_admin_gets_no_message(): void
    {
        $organization = OrganizationFactory::createOne();
        UserFactory::new()->admin($organization)->invited()->create();
        [, $token] = $this->awaitingDeclaration($organization);

        $this->confirmer->confirm($token);

        self::assertEmailCount(0);
    }

    #[Test]
    public function validating_everything_tells_the_volunteer_with_the_waived_amount(): void
    {
        $organization = OrganizationFactory::createOne(['name' => 'Les Jardins', 'slug' => 'les-jardins']);
        FiscalYearFactory::new()->for($organization)->withPublishedBareme()->create();
        $declaration = $this->confirmedDeclaration($organization, 'camille@example.test');
        // 68 km at 0,636 €/km: 43,25 €. Dated today so the current exercice covers it.
        DeclarationActionFactory::new()->forDeclaration($declaration)->confirmed()->withWaivedTravel()
            ->create(['title' => 'Régate de printemps', 'date' => new DateTimeImmutable('today')]);
        DeclarationActionFactory::new()->forDeclaration($declaration)->confirmed()->onFoot()
            ->create(['title' => 'Peinture du hangar', 'date' => new DateTimeImmutable('today')]);

        $this->decider->validateAll($this->reload($declaration));

        self::assertEmailCount(1);
        $email = self::sentEmail(0);
        self::assertEmailAddressContains($email, 'To', 'camille@example.test');
        self::assertSame('Votre déclaration de bénévolat a été traitée · Les Jardins', $email->getSubject());

        foreach ([(string) $email->getTextBody(), (string) $email->getHtmlBody()] as $body) {
            self::assertStringContainsString('Acceptées', $body);
            self::assertStringNotContainsString('Refusées', $body);
            self::assertStringContainsString('Régate de printemps', $body);
            self::assertStringContainsString('Peinture du hangar', $body);
            self::assertStringContainsString("Abandon de frais retenu : 43,25\u{a0}€", $body);
            self::assertStringContainsString('indicatif', $body);
            self::assertStringContainsString('/a/les-jardins/mes-declarations', $body);
            self::assertStringNotContainsString('—', $body);
        }
    }

    /**
     * A mixed basket never leaves `submitted`, and it is finished all the same: the
     * volunteer hears at the last line, once, with both lists.
     */
    #[Test]
    public function ruling_line_by_line_tells_the_volunteer_at_the_last_line_only(): void
    {
        $organization = OrganizationFactory::createOne();
        $declaration = $this->confirmedDeclaration($organization);
        DeclarationActionFactory::new()->forDeclaration($declaration)->confirmed()->onFoot()
            ->create(['title' => 'Tonte des pelouses']);
        DeclarationActionFactory::new()->forDeclaration($declaration)->confirmed()->onFoot()
            ->create(['title' => 'Buvette du tournoi']);
        $declaration = $this->reload($declaration);

        self::assertTrue($this->decider->validateLine($this->line($declaration, 'Tonte des pelouses')));
        self::assertEmailCount(0);

        self::assertTrue($this->decider->refuseLine($this->line($declaration, 'Buvette du tournoi')));
        self::assertEmailCount(1);
        self::assertSame(DeclarationState::SUBMITTED, $declaration->getState());

        $text = (string) self::sentEmail(0)->getTextBody();
        self::assertMatchesRegularExpression('/Acceptées.*Tonte des pelouses.*Refusées.*Buvette du tournoi/s', $text);
        // Hours only, and no exercice: nothing was waived, so no amount is stated at all.
        self::assertStringNotContainsString('Abandon de frais', $text);

        // A line already ruled on changes nothing and says nothing.
        self::assertFalse($this->decider->validateLine($this->line($declaration, 'Buvette du tournoi')));
        self::assertEmailCount(1);
    }

    /**
     * Every line validated one by one leaves « Valider tout » available, to move the
     * declaration itself. The volunteer was told at the last line.
     */
    #[Test]
    public function a_bulk_verdict_after_every_line_was_ruled_on_tells_nobody_again(): void
    {
        $declaration = $this->confirmedDeclaration(OrganizationFactory::createOne());
        DeclarationActionFactory::new()->forDeclaration($declaration)->confirmed()->many(2)->create();
        $declaration = $this->reload($declaration);

        foreach ($declaration->getActions() as $action) {
            $this->decider->validateLine($action);
        }
        self::assertEmailCount(1);

        $this->decider->validateAll($declaration);

        self::assertSame(DeclarationState::VALIDATED, $declaration->getState());
        self::assertEmailCount(1);
    }

    #[Test]
    public function refusing_everything_states_no_amount(): void
    {
        $organization = OrganizationFactory::createOne();
        FiscalYearFactory::new()->for($organization)->withPublishedBareme()->create();
        $declaration = $this->confirmedDeclaration($organization);
        DeclarationActionFactory::new()->forDeclaration($declaration)->confirmed()->withWaivedTravel()
            ->create(['date' => new DateTimeImmutable('today')]);

        $this->decider->refuseAll($this->reload($declaration));

        self::assertEmailCount(1);
        $text = (string) self::sentEmail(0)->getTextBody();
        self::assertStringContainsString('Refusées', $text);
        self::assertStringNotContainsString('Acceptées', $text);
        self::assertStringNotContainsString('Abandon de frais', $text);
    }

    /**
     * No exercice covers the line, so there is no barème to price it with: the mail says
     * so, where a bare total would silently leave the journey out.
     */
    #[Test]
    public function an_accepted_journey_no_exercice_covers_is_reported_as_unpriced(): void
    {
        $declaration = $this->confirmedDeclaration(OrganizationFactory::createOne());
        DeclarationActionFactory::new()->forDeclaration($declaration)->confirmed()->withWaivedTravel()->create();

        $this->decider->validateAll($this->reload($declaration));

        $text = (string) self::sentEmail(0)->getTextBody();
        self::assertStringContainsString("Un déplacement accepté n'a pas encore de montant", $text);
        self::assertStringNotContainsString('Abandon de frais retenu', $text);
    }

    /**
     * The verdict was committed before anything was sent: a relay that is down costs the
     * alert, never the action, and leaves a trace.
     */
    #[Test]
    public function a_relay_that_refuses_the_message_is_logged_and_not_raised(): void
    {
        $organization = OrganizationFactory::createOne();
        UserFactory::new()->admin($organization)->create();
        $declaration = $this->confirmedDeclaration($organization);
        DeclarationActionFactory::new()->forDeclaration($declaration)->validated()->create();
        $declaration = $this->reload($declaration);

        $logger = new class extends AbstractLogger {
            /** @var list<array{mixed, string, array<mixed>}> */
            public array $records = [];

            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message, $context];
            }
        };
        $mailer = new class implements MailerInterface {
            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                throw new TransportException('Connection refused.');
            }
        };

        $notifier = new DeclarationNotifier(
            $mailer,
            self::getContainer()->get(UserRepository::class),
            self::getContainer()->get(ContributionValuator::class),
            self::getContainer()->get(UrlGeneratorInterface::class),
            $logger,
            'benevolat@example.test',
        );

        $notifier->notifyConfirmed($declaration);
        $notifier->notifyDecided($declaration);

        self::assertCount(2, $logger->records);
        self::assertSame(['error', 'error'], array_map(static fn (array $record): mixed => $record[0], $logger->records));
        self::assertSame(
            ['confirmed', 'decided'],
            array_map(static fn (array $record): mixed => $record[2]['alert'] ?? null, $logger->records),
        );
    }

    /**
     * A declaration as the public form leaves it: lines written first, then a token.
     *
     * @return array{Declaration, string}
     */
    private function awaitingDeclaration(Organization $organization, string $firstName = 'Alex', string $lastName = 'Durand'): array
    {
        $person = PersonFactory::new()->for($organization)->create(['firstName' => $firstName, 'lastName' => $lastName]);
        $declaration = DeclarationFactory::new()->forPerson($person)->create();
        DeclarationActionFactory::new()->forDeclaration($declaration)->create(['title' => 'Corvée de printemps']);

        $token = ConfirmationToken::generate();
        $declaration = $this->reload($declaration);
        $declaration->issueConfirmationToken($token, new DateTimeImmutable('+7 days'));
        $this->entityManager()->flush();

        return [$declaration, $token->value];
    }

    private function confirmedDeclaration(Organization $organization, string $email = 'benevole@example.test'): Declaration
    {
        $person = PersonFactory::new()->for($organization)->create(['email' => new EmailAddress($email)]);

        return DeclarationFactory::new()->forPerson($person)->confirmed()->create();
    }

    private function line(Declaration $declaration, string $title): DeclarationAction
    {
        foreach ($declaration->getActions() as $action) {
            if ($title === $action->getLabel()) {
                return $action;
            }
        }

        self::fail(sprintf('No line titled « %s ».', $title));
    }

    private static function sentEmail(int $index): Email
    {
        $messages = array_values(self::getMailerMessages());
        self::assertArrayHasKey($index, $messages);
        $message = $messages[$index];
        self::assertInstanceOf(Email::class, $message);

        return $message;
    }
}
