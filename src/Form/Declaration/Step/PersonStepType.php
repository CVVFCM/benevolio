<?php

declare(strict_types=1);

namespace App\Form\Declaration\Step;

use App\Form\Declaration\DeclarationDraft;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CountryType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Step 1: who is declaring.
 *
 * `inherit_data` is essential: a flow step is added as a child form named after
 * the step, and without it these fields would map onto a nested `person` object
 * instead of onto DeclarationDraft itself.
 *
 * @extends AbstractType<DeclarationDraft>
 */
final class PersonStepType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName', TextType::class, [
                'label' => 'Prénom',
                'attr' => ['autocomplete' => 'given-name'],
            ])
            ->add('lastName', TextType::class, [
                'label' => 'Nom',
                'attr' => ['autocomplete' => 'family-name'],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Adresse électronique',
                'help' => 'Elle sert à vous reconnaître d\'une déclaration à l\'autre.',
                'attr' => ['autocomplete' => 'email'],
            ])
            ->add('addressNumber', TextType::class, [
                'label' => 'Numéro',
                'required' => false,
                'help' => 'Facultatif : laissez vide pour un lieu-dit.',
                'attr' => ['autocomplete' => 'address-line1'],
            ])
            ->add('addressStreet', TextType::class, [
                'label' => 'Rue ou lieu-dit',
                'attr' => ['autocomplete' => 'address-line2'],
            ])
            ->add('addressPostcode', TextType::class, [
                'label' => 'Code postal',
                'attr' => ['autocomplete' => 'postal-code', 'inputmode' => 'numeric'],
            ])
            ->add('addressCity', TextType::class, [
                'label' => 'Commune',
                'attr' => ['autocomplete' => 'address-level2'],
            ])
            ->add('addressCountry', CountryType::class, [
                'label' => 'Pays',
                'preferred_choices' => ['FR'],
                'attr' => ['autocomplete' => 'country'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'inherit_data' => true,
            'label' => false,
        ]);
    }
}
