<?php

declare(strict_types=1);

namespace App\Validator;

use Attribute;
use Symfony\Component\Validator\Constraint;

/**
 * Refuses a change to a closed exercice's name, dates or rates.
 *
 * The CRUD renders those fields disabled once an exercice is closed, but **a disabled input is a
 * courtesy, not a control**: a hand-built POST reaches the entity all the same. This is what
 * actually holds "closed means frozen", which is in turn what makes a figure printed on a reçu
 * fiscal defensible — see App\State\FiscalYearState.
 *
 * A class constraint rather than an `Assert\Callback` on the entity, because deciding it needs
 * Doctrine's change set: an entity cannot tell on its own whether it differs from the row it was
 * loaded from, and refusing every submit of a closed exercice — even one that changed nothing —
 * would be a worse answer.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class FiscalYearIsNotFrozen extends Constraint
{
    public string $message = 'Cet exercice est clôturé : son nom, ses dates et ses taux ne peuvent plus être modifiés. Réouvrez-le d\'abord — ce qui n\'est possible que si aucun reçu fiscal n\'a été émis à partir de ses taux.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
