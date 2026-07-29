<?php

declare(strict_types=1);

namespace App\Form\Declaration;

use App\Entity\Task;
use App\Enum\FiscalPower;
use App\Repository\TaskRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One row of the actions collection.
 *
 * @extends AbstractType<ActionDraft>
 */
final class ActionDraftType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('task', EntityType::class, [
                'class' => Task::class,
                'label' => 'Tâche effectuée',
                'placeholder' => 'Choisissez…',
                'choice_label' => static fn (Task $type): string => $type->getName(),
                // Retired types stay readable on old actions but must not be
                // offered on new ones. The tenant scope is the filter's job.
                'query_builder' => static fn (TaskRepository $repository) => $repository->activeQueryBuilder(),
            ])
            ->add('title', TextType::class, [
                'label' => 'Intitulé de l\'événement',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Ce que vous avez fait',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('date', DateType::class, [
                'label' => 'Date',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('consecutiveDays', IntegerType::class, [
                'label' => 'Nombre de jours consécutifs',
                'help' => 'À partir de la date ci-dessus.',
            ])
            ->add('workHours', NumberType::class, [
                'label' => 'Total des heures',
                // Model stays a decimal string so the exact value reaches the
                // DECIMAL(5,2) column without going through a float.
                'input' => 'string',
                'scale' => 2,
                'html5' => true,
                'attr' => ['step' => '0.25', 'min' => '0'],
            ])
            ->add('journeys', IntegerType::class, [
                'label' => 'Nombre de trajets',
                'help' => 'Trajets simples : un aller-retour compte pour 2.',
            ])
            ->add('distanceKm', IntegerType::class, [
                'label' => 'Distance d\'un trajet (km)',
                'help' => 'Aller simple, de votre domicile au lieu de l\'événement.',
            ])
            ->add('ownVehicle', CheckboxType::class, [
                'label' => 'Je suis venu avec mon véhicule personnel',
                'required' => false,
            ])
            ->add('fiscalPower', EnumType::class, [
                'class' => FiscalPower::class,
                'label' => 'Puissance fiscale du véhicule',
                'placeholder' => 'Choisissez…',
                'required' => false,
                'help' => 'Uniquement si vous êtes venu avec votre véhicule.',
                'choice_label' => static fn (FiscalPower $power): string => $power->label(),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ActionDraft::class,
            'label' => false,
        ]);
    }
}
