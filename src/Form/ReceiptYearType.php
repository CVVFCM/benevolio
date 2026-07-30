<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function array_combine;
use function array_map;
use function is_array;
use function is_int;
use function sprintf;

/**
 * Which civil year to issue receipts for.
 *
 * Only years the association actually has validated contributions in are offered: a year
 * with nothing in it would produce a run that reported nothing, which reads as a fault.
 *
 * **The current year is allowed, and it is guarded.** A receipt issued in June for the year
 * running covers half a year, and the volunteer would under-declare — so choosing it demands
 * ticking a box that says so. The check is here rather than in the controller because it is a
 * property of the submitted pair (year, acknowledgement), which is exactly what a form
 * validates.
 *
 * @extends AbstractType<array{year: int, partialYearAcknowledged: bool}>
 */
final class ReceiptYearType extends AbstractType
{
    public const string FIELD_YEAR = 'year';
    public const string FIELD_ACKNOWLEDGED = 'partialYearAcknowledged';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Both are setRequired() below, so the resolver has already refused a call without
        // them; the ?? is what tells PHPStan that, since it only sees array<string, mixed>.
        /** @var list<int> $years */
        $years = $options['years'] ?? [];
        /** @var int $currentYear */
        $currentYear = $options['current_year'] ?? 0;

        $builder
            ->add(self::FIELD_YEAR, ChoiceType::class, [
                'label' => 'Année civile',
                // Labels and values are the same thing here, but ChoiceType wants
                // label => value, and the labels say which year is not finished.
                'choices' => array_combine(
                    array_map(
                        static fn (int $year): string => $year >= $currentYear
                            ? sprintf('%d (année en cours)', $year)
                            : (string) $year,
                        $years,
                    ),
                    $years,
                ),
                'placeholder' => false,
                'help' => 'Le reçu porte sur l\'année civile, même si l\'exercice comptable '
                    .'de l\'association ne la suit pas.',
            ])
            ->add(self::FIELD_ACKNOWLEDGED, CheckboxType::class, [
                'label' => 'Je sais que cette année n\'est pas terminée et que les montants '
                    .'seront partiels',
                'required' => false,
                'help' => 'À cocher uniquement si vous avez choisi l\'année en cours.',
            ]);

        // POST_SUBMIT so both fields are bound: the rule is about the pair, and a
        // constraint on either field alone cannot see the other.
        $builder->addEventListener(
            FormEvents::POST_SUBMIT,
            static function (FormEvent $event) use ($currentYear): void {
                $data = $event->getData();

                if (!is_array($data)) {
                    return;
                }

                $year = $data[self::FIELD_YEAR] ?? null;

                if (!is_int($year) || $year < $currentYear) {
                    return;
                }

                if (true === ($data[self::FIELD_ACKNOWLEDGED] ?? false)) {
                    return;
                }

                $event->getForm()->get(self::FIELD_ACKNOWLEDGED)->addError(new FormError(
                    sprintf(
                        'L\'année %d n\'est pas terminée : les montants seront partiels et le '
                        .'bénévole sous-déclarerait. Cochez la case pour émettre malgré tout.',
                        $year,
                    ),
                ));
            },
        );
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                // Not an entity form: the run takes an association and a year, and there is
                // nothing to map onto.
                'data_class' => null,
            ])
            ->setRequired(['years', 'current_year'])
            ->setAllowedTypes('years', 'int[]')
            ->setAllowedTypes('current_year', 'int');
    }
}
