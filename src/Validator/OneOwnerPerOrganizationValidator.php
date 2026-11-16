<?php

declare(strict_types=1);

namespace App\Validator;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * @see OneOwnerPerOrganization
 */
final class OneOwnerPerOrganizationValidator extends ConstraintValidator
{
    public function __construct(
        private readonly UserRepository $users,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof OneOwnerPerOrganization) {
            throw new UnexpectedValueException($constraint, OneOwnerPerOrganization::class);
        }

        if (null === $value) {
            return;
        }

        if (!$value instanceof User) {
            throw new UnexpectedValueException($value, User::class);
        }

        $organization = $value->getOrganization();

        // An owner without an organization is the Expression constraint's message on
        // User::$organization; repeating it here would say the same thing twice.
        if (!$value->isOwner() || null === $organization) {
            return;
        }

        $owner = $this->users->findOwnerOf($organization);

        if (null === $owner || $owner->getId()->equals($value->getId())) {
            return;
        }

        $this->context->buildViolation($constraint->message)
            ->setParameter('{{ owner }}', $owner->getEmail())
            ->atPath('roles')
            ->addViolation();
    }
}
