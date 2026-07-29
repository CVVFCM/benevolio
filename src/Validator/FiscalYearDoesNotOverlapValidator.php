<?php

declare(strict_types=1);

namespace App\Validator;

use App\Entity\FiscalYear;
use App\Repository\FiscalYearRepository;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

use function sprintf;

/**
 * @see FiscalYearDoesNotOverlap
 */
final class FiscalYearDoesNotOverlapValidator extends ConstraintValidator
{
    public function __construct(
        private readonly FiscalYearRepository $fiscalYears,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof FiscalYearDoesNotOverlap) {
            throw new UnexpectedValueException($constraint, FiscalYearDoesNotOverlap::class);
        }

        if (null === $value) {
            return;
        }

        if (!$value instanceof FiscalYear) {
            throw new UnexpectedValueException($value, FiscalYear::class);
        }

        $beginsOn = $value->getBeginsOn();
        $endsOn = $value->getEndsOn();

        // An inverted range is already reported by the GreaterThan constraint on
        // endsOn; repeating it here would double the message a treasurer sees.
        if ($endsOn <= $beginsOn) {
            return;
        }

        $clashes = $this->fiscalYears->findOverlapping(
            $value->getOrganization(),
            $beginsOn,
            $endsOn,
            // Excluded, or editing an exercice would always clash with itself.
            $value->getId(),
        );

        if ([] === $clashes) {
            return;
        }

        $clash = $clashes[0];

        $this->context->buildViolation($constraint->message)
            ->setParameter('{{ clash }}', $clash->getName())
            ->setParameter('{{ range }}', sprintf(
                'du %s au %s',
                $clash->getBeginsOn()->format('d/m/Y'),
                $clash->getEndsOn()->format('d/m/Y'),
            ))
            // On beginsOn rather than the class, so the message appears against a
            // field in the form instead of floating at the top.
            ->atPath('beginsOn')
            ->addViolation();
    }
}
