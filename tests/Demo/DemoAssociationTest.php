<?php

declare(strict_types=1);

namespace App\Tests\Demo;

use App\Demo\DemoAssociation;
use App\Entity\Organization;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DemoAssociationTest extends TestCase
{
    #[Test]
    public function it_recognises_the_association_named_by_the_deployment_only(): void
    {
        $demo = new DemoAssociation('demo');

        self::assertTrue($demo->isEnabled());
        self::assertTrue($demo->is(new Organization()->setSlug('demo')));
        self::assertFalse($demo->is(new Organization()->setSlug('cvvfcm')));
        self::assertFalse($demo->is(null));
    }

    #[Test]
    public function an_empty_slug_disables_it_for_everyone(): void
    {
        $demo = new DemoAssociation('');

        self::assertFalse($demo->isEnabled());
        // An association whose slug were somehow empty is still not the demo.
        self::assertFalse($demo->is(new Organization()));
    }
}
