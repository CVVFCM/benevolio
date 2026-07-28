<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Person;
use App\Factory\OrganizationFactory;
use App\ValueObject\Address;
use App\ValueObject\Email;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Proves the two pieces of Doctrine plumbing the value objects depend on:
 *
 * - Address is a `final readonly` #[ORM\Embeddable]. Doctrine hydrates it through
 *   reflection without calling the constructor, and readonly properties can only
 *   be initialised once — so it is worth asserting that a real write/read cycle
 *   actually produces an Address and not a broken object.
 * - Email is stored by App\Doctrine\Type\EmailType in a single column and must
 *   come back as an Email, normalised.
 */
final class PersonPersistenceTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    #[Test]
    public function it_round_trips_the_address_and_email_value_objects(): void
    {
        $person = new Person(
            OrganizationFactory::createOne(),
            'Jean',
            'Dupont',
            new Email('Jean.Dupont@Example.TEST'),
            new Address('12 bis', 'rue des Jardins', '44000', 'Nantes', 'FR'),
        );

        $reloaded = $this->persistAndReload($person);

        // The getters are natively typed, so a failed hydration would surface as a
        // TypeError here rather than as a failed assertInstanceOf.
        // Normalisation happened on the way in and survived.
        self::assertSame('jean.dupont@example.test', $reloaded->getEmail()->value);

        $address = $reloaded->getAddress();
        self::assertSame('12 bis', $address->number);
        self::assertSame('rue des Jardins', $address->street);
        self::assertSame('44000', $address->postcode);
        self::assertSame('Nantes', $address->city);
        self::assertSame('FR', $address->country);
    }

    /**
     * A lieu-dit address has no street number, so the embedded column is NULL —
     * which must not turn the whole embeddable into null.
     */
    #[Test]
    public function it_round_trips_an_address_without_a_street_number(): void
    {
        $person = new Person(
            OrganizationFactory::createOne(),
            'Marie',
            'Martin',
            new Email('marie@example.test'),
            new Address(null, 'Lieu-dit Le Moulin', '44190', 'Clisson', 'FR'),
        );

        $address = $this->persistAndReload($person)->getAddress();

        self::assertNull($address->number);
        self::assertSame('Lieu-dit Le Moulin', $address->street);
    }

    #[Test]
    public function it_updates_the_identity_when_the_person_declares_again(): void
    {
        $person = new Person(
            OrganizationFactory::createOne(),
            'Jean',
            'Dupont',
            new Email('jean@example.test'),
            new Address('12', 'rue des Jardins', '44000', 'Nantes', 'FR'),
        );
        $this->entityManager->persist($person);
        $this->entityManager->flush();

        $person->updateIdentity('Jean-Pierre', 'Dupont', new Address('5', 'quai de la Fosse', '44100', 'Nantes', 'FR'));
        $this->entityManager->flush();
        $id = $person->getId();
        $this->entityManager->clear();

        $reloaded = $this->entityManager->getRepository(Person::class)->find($id);

        self::assertNotNull($reloaded);
        self::assertSame('Jean-Pierre Dupont', $reloaded->getFullName());
        self::assertSame('quai de la Fosse', $reloaded->getAddress()->street);
    }

    #[Test]
    public function it_finds_a_person_by_email_case_insensitively(): void
    {
        $organization = OrganizationFactory::createOne();
        $person = new Person(
            $organization,
            'Jean',
            'Dupont',
            new Email('jean.dupont@example.test'),
            new Address('12', 'rue des Jardins', '44000', 'Nantes', 'FR'),
        );
        $this->entityManager->persist($person);
        $this->entityManager->flush();
        $this->entityManager->clear();

        // The volunteer typing a different case must still match the same Person —
        // that is what makes "one Person per email per Organization" hold.
        self::assertNotNull(
            $this->entityManager->getRepository(Person::class)->findOneByEmail(new Email('Jean.Dupont@EXAMPLE.test')),
        );
    }

    private function persistAndReload(Person $person): Person
    {
        $this->entityManager->persist($person);
        $this->entityManager->flush();
        $id = $person->getId();
        $this->entityManager->clear();

        $reloaded = $this->entityManager->getRepository(Person::class)->find($id);
        self::assertNotNull($reloaded);

        return $reloaded;
    }
}
