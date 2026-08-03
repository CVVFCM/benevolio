<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\FiscalYear;
use App\Entity\FiscalYearTaskRate;
use App\Entity\Task;
use App\Repository\TaskRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function assert;

/**
 * One "this task is worth this much, this year" line.
 *
 * An override, not a requirement: most associations set one default rate on the exercice and
 * never create any of these. `FiscalYear::hourlyRateCentsFor()` is the single place the
 * fallback lives.
 *
 * @extends AbstractType<FiscalYearTaskRate>
 */
final class FiscalYearTaskRateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('task', EntityType::class, [
                'label' => 'Tâche',
                'class' => Task::class,
                'choice_label' => 'name',
                // SCOPED BY HAND, and it has to be: this form is reached from /admin, where the
                // Doctrine tenant filter is armed — but EntityType builds its own query and
                // Task is TenantAware, so the filter does cover it. The explicit organization
                // clause is belt and braces for the day someone reaches this from a context
                // where the filter is off (a command, a test), where an unscoped list would
                // offer another association's tasks as choices.
                'query_builder' => static function (TaskRepository $tasks) use ($options): QueryBuilder {
                    // setRequired() below means the resolver has already refused a call without
                    // it; the ?? is what tells PHPStan that, since it only sees array<string, mixed>.
                    $fiscalYear = $options['fiscal_year'] ?? null;
                    assert($fiscalYear instanceof FiscalYear);

                    return $tasks->createQueryBuilder('task')
                        ->andWhere('task.organization = :organization')
                        ->setParameter('organization', $fiscalYear->getOrganization()->getId(), 'uuid')
                        ->orderBy('task.name', 'ASC');
                },
                'placeholder' => 'Choisir une tâche',
            ])
            // MoneyType with divisor 100, so the treasurer types 15,50 and the entity keeps
            // 1550 — amounts are integer cents everywhere, and no float touches the path.
            ->add('hourlyRateCents', MoneyType::class, [
                'label' => 'Taux horaire',
                'currency' => 'EUR',
                'divisor' => 100,
                'help' => 'Remplace le taux par défaut de l\'exercice, pour cette tâche seulement.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'data_class' => FiscalYearTaskRate::class,
                // FiscalYearTaskRate's constructor requires its exercice and its task, so the
                // form cannot simply `new` one for a row the user just added. This builds it
                // from what the row has bound, which is also what registers it on the exercice's
                // collection — see the constructor.
                'empty_data' => static function (FormInterface $form): ?FiscalYearTaskRate {
                    $task = $form->get('task')->getData();
                    $fiscalYear = $form->getConfig()->getOption('fiscal_year');

                    if (!$task instanceof Task || !$fiscalYear instanceof FiscalYear) {
                        // An empty row: the user added a line and left it blank. Returning null
                        // lets the collection's own validation report it, rather than
                        // constructing a rate with no task.
                        return null;
                    }

                    return new FiscalYearTaskRate($fiscalYear, $task);
                },
            ])
            ->setRequired('fiscal_year')
            ->setAllowedTypes('fiscal_year', FiscalYear::class);
    }
}
