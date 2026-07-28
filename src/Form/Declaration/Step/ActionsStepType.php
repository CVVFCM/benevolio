<?php

declare(strict_types=1);

namespace App\Form\Declaration\Step;

use App\Form\Declaration\ActionDraft;
use App\Form\Declaration\ActionDraftType;
use App\Form\Declaration\DeclarationDraft;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Step 2: the contributed actions.
 *
 * The rows are added and removed client-side by assets/controllers/form_collection_controller.js,
 * which clones the CollectionType prototype. `allow_add` and `allow_delete` are
 * what make that possible; without JavaScript the volunteer still gets the one
 * row Symfony renders, which is enough to submit a valid declaration.
 *
 * @extends AbstractType<DeclarationDraft>
 */
final class ActionsStepType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('actions', CollectionType::class, [
            'label' => false,
            'entry_type' => ActionDraftType::class,
            'entry_options' => ['label' => false],
            'allow_add' => true,
            'allow_delete' => true,
            'by_reference' => false,
            // Keeps $actions a list after deletions, so the DTO's list<ActionDraft>
            // type stays honest and the Stimulus indices do not go sparse.
            'keep_as_list' => true,
            'prototype' => true,
            'prototype_data' => new ActionDraft(),
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
