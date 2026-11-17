<?php

declare(strict_types=1);

namespace App\Form\Declaration;

use App\Form\Declaration\Step\ActionsStepType;
use App\Form\Declaration\Step\LegalStepType;
use App\Form\Declaration\Step\PersonStepType;
use App\Repository\TaskRepository;
use App\Tenant\TenantContext;
use App\Volunteer\CurrentVolunteer;
use Symfony\Component\Form\Flow\AbstractFlowType;
use Symfony\Component\Form\Flow\DataStorage\SessionDataStorage;
use Symfony\Component\Form\Flow\FormFlowBuilderInterface;
use Symfony\Component\Form\Flow\Type\FinishFlowType;
use Symfony\Component\Form\Flow\Type\NextFlowType;
use Symfony\Component\Form\Flow\Type\PreviousFlowType;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The public volunteer declaration, as a three-step Symfony form flow.
 *
 * Two things about FormFlowType shape everything here:
 *
 * - `step_property_path` is required, hence DeclarationDraft::$step.
 * - `validation_groups` defaults to `['Default', <current step>]`, which is why
 *   every constraint on DeclarationDraft names its step and none sits in Default.
 *   Step names below MUST match those group names.
 */
final class DeclarationFlowType extends AbstractFlowType
{
    /**
     * Session key prefix. The organization id is appended per instance — see
     * buildFormFlow().
     */
    private const string SESSION_KEY_PREFIX = 'declaration_flow';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly RequestStack $requestStack,
        private readonly CurrentVolunteer $currentVolunteer,
        private readonly TaskRepository $tasks,
    ) {
    }

    public function buildFormFlow(FormFlowBuilderInterface $builder, array $options): void
    {
        $builder
            ->addStep(DeclarationDraft::STEP_PERSON, PersonStepType::class)
            ->addStep(DeclarationDraft::STEP_ACTIONS, ActionsStepType::class)
            ->addStep(DeclarationDraft::STEP_LEGAL, LegalStepType::class);

        // Added individually rather than through NavigatorFlowType, which offers no
        // way to label its buttons — they would render as "Previous"/"Next"/"Finish"
        // in an application that is French-only. FormFlow prunes whichever of these
        // does not apply to the current step, through their include_if option.
        $builder
            ->add('previous', PreviousFlowType::class, [
                'label' => 'Précédent',
                'attr' => ['class' => 'button--quiet'],
            ])
            ->add('next', NextFlowType::class, ['label' => 'Suivant'])
            ->add('finish', FinishFlowType::class, ['label' => 'Enregistrer ma déclaration']);

        // Per-tenant session key. A shared key would let a half-filled declaration
        // started on one association's public form reappear on another's, in the
        // same browser session — the flow keeps its data in the session, and the
        // session is not scoped to the URL prefix.
        // A logged-in volunteer's draft is filled from their profile where it is blank —
        // including a draft restored from the session, started anonymously or before the
        // profile had a distance. PRE_SET_DATA sees the draft as restored. Once per draft:
        // see DeclarationDraft::$prefilledFor.
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            $draft = $event->getData();
            if (!$draft instanceof DeclarationDraft) {
                return;
            }

            // The session storage deep-clones the draft, which detaches the Task each line
            // holds, and the task select refuses an unmanaged entity: going back to the
            // actions step answered 500. Re-read each one — through the tenant-filtered
            // repository, so a task gone in the meantime, or another association's, is
            // simply unset. DeclarationSubmitter re-reads it again at the end.
            foreach ($draft->actions as $action) {
                if (null !== $action->task) {
                    $action->task = $this->tasks->find($action->task->getId());
                }
            }

            $volunteer = $this->currentVolunteer->get();
            if (null !== $volunteer) {
                $draft->fillBlanksFrom($volunteer);
            }
        });

        $builder->setDataStorage(new SessionDataStorage(
            self::SESSION_KEY_PREFIX.'.'.$this->tenantContext->getOrganization()->getId()->toRfc4122(),
            $this->requestStack,
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DeclarationDraft::class,
            'step_property_path' => 'step',
            // The form posts back to the same URL on every step.
            'method' => 'POST',
        ]);
    }
}
