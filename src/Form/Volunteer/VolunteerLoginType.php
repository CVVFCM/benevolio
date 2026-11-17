<?php

declare(strict_types=1);

namespace App\Form\Volunteer;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * « Se connecter »: an address to mail a link to.
 *
 * @extends AbstractType<array{email: string|null}>
 */
final class VolunteerLoginType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('email', EmailType::class, [
            'label' => 'Adresse électronique',
            'help' => 'Celle que vous indiquez dans vos déclarations.',
            'attr' => ['autocomplete' => 'email'],
            'constraints' => [
                new Assert\NotBlank(message: 'Indiquez votre adresse électronique.'),
                new Assert\Email(message: 'Cette adresse électronique n\'est pas valide.'),
            ],
        ]);
    }
}
