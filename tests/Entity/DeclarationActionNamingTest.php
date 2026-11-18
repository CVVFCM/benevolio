<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\DeclarationAction;
use App\Factory\ActivityFactory;
use App\Factory\DeclarationActionFactory;
use App\Factory\DeclarationFactory;
use App\Factory\OrganizationFactory;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * A line is named by an activity or by a title — exactly one (#44).
 */
final class DeclarationActionNamingTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function an_activity_names_the_line_and_drops_a_title_given_beside_it(): void
    {
        $organization = OrganizationFactory::createOne();
        $activity = ActivityFactory::new()->for($organization)->create(['name' => 'Régate']);

        $line = DeclarationActionFactory::new()->for($organization)->create(['activity' => $activity, 'title' => 'Ignoré']);

        self::assertNull($line->getTitle());
        self::assertSame('Régate', $line->getLabel());
        self::assertSame('Régate', (string) $line);
    }

    #[Test]
    public function without_an_activity_the_title_names_the_line(): void
    {
        $line = DeclarationActionFactory::new()->for(OrganizationFactory::createOne())->create(['title' => 'Coup de main']);

        self::assertNull($line->getActivity());
        self::assertSame('Coup de main', $line->getLabel());
    }

    #[Test]
    public function a_line_with_neither_is_refused(): void
    {
        $organization = OrganizationFactory::createOne();
        $declaration = DeclarationFactory::new()->for($organization)->create();
        $task = DeclarationActionFactory::new()->forDeclaration($declaration)->create()->getTask();

        $this->expectException(InvalidArgumentException::class);

        new DeclarationAction($declaration, $task, null, '  ', null, new DateTimeImmutable('-10 days'), 1, 0, 0, false, null, '1.00');
    }
}
