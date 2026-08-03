<?php

declare(strict_types=1);

namespace App\Validator;

use App\Entity\FiscalYear;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

use function count;

/**
 * @see FiscalYearIsNotFrozen
 */
final class FiscalYearIsNotFrozenValidator extends ConstraintValidator
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (null === $value) {
            return;
        }

        if (!$value instanceof FiscalYear) {
            throw new UnexpectedValueException($value, FiscalYear::class);
        }

        if (!$constraint instanceof FiscalYearIsNotFrozen) {
            throw new UnexpectedValueException($constraint, FiscalYearIsNotFrozen::class);
        }

        // An open exercice is the whole point of being open.
        if ($value->isEditable()) {
            return;
        }

        $unitOfWork = $this->entityManager->getUnitOfWork();

        // Not managed yet — a brand-new exercice, which is OPEN anyway and never reaches here.
        if (!$this->entityManager->contains($value)) {
            return;
        }

        // computeChangeSet() rather than getEntityChangeSet() alone: outside a flush the change
        // set has not been computed yet, so the latter answers an empty array and every change
        // would slip through.
        $unitOfWork->computeChangeSet($this->entityManager->getClassMetadata(FiscalYear::class), $value);
        $changed = $unitOfWork->getEntityChangeSet($value);

        // `state` itself is allowed to move — that is `reopen`, and refusing it here would make a
        // closed exercice impossible to reopen at all.
        unset($changed['state']);

        if (count($changed) > 0) {
            $this->context->buildViolation($constraint->message)
                // On the name, so the message appears at the top of the form rather than as a
                // global error detached from any field.
                ->atPath('name')
                ->addViolation();
        }
    }
}
