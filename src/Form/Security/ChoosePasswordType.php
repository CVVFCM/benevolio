<?php

declare(strict_types=1);

namespace App\Form\Security;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * « Choisissez votre mot de passe ». Unmapped: the plaintext never touches the
 * entity, ChoosePasswordController hashes it straight from the form.
 *
 * @extends AbstractType<array{plainPassword: string}>
 */
final class ChoosePasswordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('plainPassword', RepeatedType::class, [
            'type' => PasswordType::class,
            'first_options' => [
                'label' => 'choose_password.password',
                'help' => 'choose_password.password.help',
                'help_translation_parameters' => ['%min%' => User::PASSWORD_MIN_LENGTH],
                'attr' => ['autocomplete' => 'new-password'],
            ],
            'second_options' => [
                'label' => 'choose_password.repeat',
                'attr' => ['autocomplete' => 'new-password'],
            ],
            'invalid_message' => 'choose_password.mismatch',
            'constraints' => [
                new Assert\NotBlank(),
                new Assert\Length(min: User::PASSWORD_MIN_LENGTH),
            ],
        ]);
    }
}
