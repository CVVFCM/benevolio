<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\FiscalYear;
use App\Entity\FiscalYearMileageRate;
use App\Enum\FiscalPower;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One bracket of the barème kilométrique for one exercice.
 *
 * An override of the exercice's default rate, per puissance fiscale.
 * `FiscalYear::milliEurosPerKmFor()` is the single place the fallback lives.
 *
 * @extends AbstractType<FiscalYearMileageRate>
 */
final class FiscalYearMileageRateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('fiscalPower', EnumType::class, [
                'label' => 'Puissance fiscale',
                'class' => FiscalPower::class,
                'choice_label' => static fn (FiscalPower $power): string => $power->label(),
                'placeholder' => 'Choisir une puissance',
            ])
            // NOT a MoneyType: the value is in millièmes d'euro per kilometre, and MoneyType
            // would read it as cents — 529 would be shown as 5,29 € instead of 0,529 €/km. The
            // published barème has three decimals, which is why this unit exists at all.
            ->add('milliEurosPerKm', IntegerType::class, [
                'label' => 'Barème (millièmes d\'euro / km)',
                'help' => 'Saisissez 529 pour 0,529 €/km. Barème 2025 (arrêté du 27 mars 2023) : '
                    .'3 CV et moins 529, 4 CV 606, 5 CV 636, 6 CV 665, 7 CV et plus 697.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'data_class' => FiscalYearMileageRate::class,
                // Same reason as the task rate: the constructor requires its exercice and its
                // bracket, so a newly added row has to be built from what it bound.
                'empty_data' => static function (FormInterface $form): ?FiscalYearMileageRate {
                    $power = $form->get('fiscalPower')->getData();
                    $fiscalYear = $form->getConfig()->getOption('fiscal_year');

                    if (!$power instanceof FiscalPower || !$fiscalYear instanceof FiscalYear) {
                        return null;
                    }

                    return new FiscalYearMileageRate($fiscalYear, $power);
                },
            ])
            ->setRequired('fiscal_year')
            ->setAllowedTypes('fiscal_year', FiscalYear::class);
    }
}
