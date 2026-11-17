<?php

declare(strict_types=1);

namespace App\Controller\Volunteer;

use App\Exception\UnknownOrganizationException;
use App\Form\Volunteer\VolunteerLoginType;
use App\Repository\PersonRepository;
use App\Tenant\TenantContext;
use App\ValueObject\Email;
use App\Volunteer\CurrentVolunteer;
use App\Volunteer\VolunteerLinkMailer;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

use function is_string;
use function mb_strtolower;

/**
 * « Se connecter » for volunteers: mails a link to the address typed.
 *
 * To ANY address: a first link is how a volunteer account begins. The page answers the
 * same whatever happens — known address, new one, over the limit — so it reveals nothing
 * about who volunteers here.
 */
#[Route(
    path: '/a/{organizationSlug}/connexion',
    name: 'volunteer_login',
    methods: [Request::METHOD_GET, Request::METHOD_POST],
)]
final readonly class RequestLinkController
{
    public function __construct(
        private Environment $twig,
        private FormFactoryInterface $formFactory,
        private TenantContext $tenantContext,
        private CurrentVolunteer $currentVolunteer,
        private PersonRepository $people,
        private VolunteerLinkMailer $mailer,
        private RateLimiterFactoryInterface $volunteerLinkLimiter,
        private RateLimiterFactoryInterface $volunteerLinkIpLimiter,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $organization = $this->tenantContext->tryGetOrganization() ?? throw new UnknownOrganizationException();

        if (null !== $this->currentVolunteer->get()) {
            return new RedirectResponse($this->urlGenerator->generate('declaration', ['organizationSlug' => $organization->getSlug()]));
        }

        $form = $this->formFactory->create(VolunteerLoginType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $typed = $form->get('email')->getData();
            $email = new Email(is_string($typed) ? $typed : '');

            // Counted before the lookup, so the limit tells nothing either.
            $key = $organization->getId()->toRfc4122().'|'.mb_strtolower($email->value);
            if ($this->volunteerLinkLimiter->create($key)->consume()->isAccepted()
                && $this->volunteerLinkIpLimiter->create((string) $request->getClientIp())->consume()->isAccepted()) {
                $this->mailer->send($organization, $email, $this->people->findOneByEmail($email));
            }

            return new Response($this->twig->render('volunteer/link_sent.html.twig', ['organization' => $organization]));
        }

        return new Response(
            $this->twig->render('volunteer/login.html.twig', ['organization' => $organization, 'form' => $form->createView()]),
            $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK,
        );
    }
}
