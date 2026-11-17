<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\Organization;
use App\Factory\DeclarationFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Nothing in the mapping ties a declaration's tenant to its volunteer's, so the
 * factory is the only thing keeping test data honest about it.
 */
final class DeclarationFactoryTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    /**
     * The defaults used to hand one OrganizationFactory to both the declaration and
     * its person. Foundry creates from a factory each time it meets one, so they
     * landed in two different associations — a volunteer filed under a tenant they
     * do not belong to, which production cannot produce.
     */
    #[Test]
    public function a_declaration_built_with_no_arguments_shares_its_volunteers_association(): void
    {
        $declaration = DeclarationFactory::createOne();

        self::assertSame(
            $declaration->getOrganization()->getId()->toRfc4122(),
            $declaration->getPerson()->getOrganization()->getId()->toRfc4122(),
        );
        self::assertSame(1, self::getContainer()->get(EntityManagerInterface::class)->getRepository(Organization::class)->count());
    }
}
