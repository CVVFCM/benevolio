<?php

declare(strict_types=1);

namespace App\Validator;

use Attribute;
use Symfony\Component\Validator\Constraint;

/**
 * Refuses an exercice whose dates overlap another of the same association.
 *
 * A class constraint rather than an Assert\Callback on the entity, because deciding
 * this needs a database lookup and an entity must not reach for a repository. The
 * validator is an ordinary service, so it gets FiscalYearRepository injected.
 *
 * Why it matters: a contribution belongs to an exercice by date, so two overlapping
 * exercices would each claim the same lines and both totals would be wrong, with
 * nothing on either page to say so.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class FiscalYearDoesNotOverlap extends Constraint
{
    public string $message = 'Cet exercice chevauche « {{ clash }} » ({{ range }}). Les exercices d\'une association ne peuvent pas se recouvrir.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
