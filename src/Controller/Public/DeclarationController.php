<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Declaration\DeclarationSubmitter;
use App\Form\Declaration\DeclarationDraft;
use App\Form\Declaration\DeclarationFlowType;
use App\Tenant\TenantContext;
use Symfony\Component\Form\Flow\FormFlowInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

use function assert;

/**
 * The public, account-less declaration form.
 *
 * The {organizationSlug} placeholder is not cosmetic: it is the route attribute
 * App\Tenant\UrlPrefixTenantResolver reads, and it is the only way a tenant can
 * be resolved for an anonymous visitor. Renaming it silently breaks tenancy.
 *
 * Does not extend AbstractController, per the project convention — so the 422 on
 * an invalid step is set by hand.
 */
#[Route(
    path: '/a/{organizationSlug}/declaration',
    name: 'declaration',
    methods: [Request::METHOD_GET, Request::METHOD_POST],
)]
final readonly class DeclarationController
{
    public function __construct(
        private Environment $twig,
        private FormFactoryInterface $formFactory,
        private DeclarationSubmitter $submitter,
        private TenantContext $tenantContext,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        // An unknown or deactivated slug resolves no tenant. On a public URL that
        // is a 404, not the LogicException TenantContext::getOrganization() would
        // raise — a visitor must not see a stack trace for a mistyped address.
        $organization = $this->tenantContext->tryGetOrganization();

        if (null === $organization) {
            throw new NotFoundHttpException('Cette association n\'existe pas ou n\'accepte plus de déclarations.');
        }

        // A data object is required up front: the flow reads the current step off
        // it before any submission, and the session storage replaces it with the
        // stored draft if there is one.
        $flow = $this->formFactory->create(DeclarationFlowType::class, new DeclarationDraft());
        assert($flow instanceof FormFlowInterface);

        $flow->handleRequest($request);

        if ($flow->isFinished()) {
            $draft = $flow->getData();
            assert($draft instanceof DeclarationDraft);

            $declaration = $this->submitter->submit($organization, $draft);

            // The confirmation page needs to name the address the link went to, and
            // an email address does not belong in a URL — it ends up in chat
            // windows, logs and referrers. A flash carries it exactly once.
            $session = $request->getSession();
            if ($session instanceof Session) {
                $session->getFlashBag()->add(
                    DeclarationConfirmationController::PENDING_EMAIL_FLASH,
                    $declaration->getPerson()->getEmail()->value,
                );
            }

            return new RedirectResponse($this->urlGenerator->generate('declaration_confirmation', [
                'organizationSlug' => $organization->getSlug(),
            ]));
        }

        // An invalid submission must not answer 200; getStepForm() returns the same
        // form in that case, so the status has to be decided before it moves on.
        $status = $flow->isSubmitted() && !$flow->isValid()
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        // Advances to the next step when a navigation button was clicked and the
        // current step validated; otherwise re-renders the current one.
        $stepForm = $flow->getStepForm();

        return new Response(
            $this->twig->render('public/declaration/flow.html.twig', [
                'flow' => $stepForm->createView(),
                'organization' => $organization,
            ]),
            $status,
        );
    }
}
