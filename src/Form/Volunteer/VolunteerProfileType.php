<?php

declare(strict_types=1);

namespace App\Form\Volunteer;

use App\Enum\FiscalPower;
use App\Form\Declaration\Step\PersonStepType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * « Mon profil ». With `with_identity` — the first login, before the Person exists — it
 * also asks for the name and address, as the declaration form does but without the
 * email: the link has already proved it.
 *
 * @extends AbstractType<VolunteerProfileDraft>
 */
final class VolunteerProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if (true === ($options['with_identity'] ?? false)) {
            $builder->add('identity', PersonStepType::class);
            $builder->get('identity')->remove('email');
        }

        $builder
            ->add('distanceKm', IntegerType::class, [
                'label' => 'Distance de votre domicile à l\'association (km)',
                'help' => 'Aller simple. Chaque nouvelle action commencera avec cette distance, à corriger quand l\'événement a lieu ailleurs.',
                'required' => false,
            ])
            ->add('fiscalPower', EnumType::class, [
                'class' => FiscalPower::class,
                'label' => 'Puissance fiscale de votre véhicule',
                'placeholder' => 'Pas de véhicule',
                'required' => false,
                'choice_label' => static fn (FiscalPower $power): string => $power->label(),
            ])
            ->add('electricVehicle', CheckboxType::class, [
                'label' => 'Mon véhicule est électrique',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => VolunteerProfileDraft::class,
            'with_identity' => false,
            'validation_groups' => static fn (\Symfony\Component\Form\FormInterface $form): array => true === $form->getConfig()->getOption('with_identity')
                ? ['Default', VolunteerProfileDraft::IDENTITY]
                : ['Default'],
        ]);
        $resolver->setAllowedTypes('with_identity', 'bool');
    }
}
