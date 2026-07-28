<?php

declare(strict_types=1);

namespace App\Form\Declaration\Step;

use App\Form\Declaration\DeclarationDraft;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Step 3: the two legal statements, both mandatory.
 *
 * The wording is the legally meaningful part and is deliberately not
 * paraphrased. `required => false` at the HTML level with Assert\IsTrue on the
 * DTO is intentional: the browser's own "please tick this box" bubble cannot be
 * styled or translated, and it would hide the real message.
 *
 * @extends AbstractType<DeclarationDraft>
 */
final class LegalStepType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('accuracyAttested', CheckboxType::class, [
                'label' => 'J\'atteste que les informations saisies sont exactes et sincères.',
                'required' => false,
            ])
            ->add('expensesWaived', CheckboxType::class, [
                'label' => 'Je confirme renoncer au remboursement des frais engagés détaillés ci-avant.',
                'required' => false,
                'help' => 'Cette renonciation est ce qui permet à l\'association d\'établir un reçu fiscal pour vos frais.',
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
