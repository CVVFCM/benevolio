<?php

declare(strict_types=1);

namespace App\Validator;

use Attribute;
use Symfony\Component\Validator\Constraint;

/**
 * Refuses a second owner in the same association.
 *
 * A class constraint with a validator service, like FiscalYearDoesNotOverlap: it
 * needs a repository lookup, which an entity must not do itself. The owner is only
 * ever granted by App\User\OwnerAssigner, which checks first — this is what stops
 * the platform back office, where the role is a checkbox, from creating a second.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class OneOwnerPerOrganization extends Constraint
{
    public string $message = 'L\'association a déjà un responsable : {{ owner }}.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
