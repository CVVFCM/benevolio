<?php

declare(strict_types=1);

namespace App\Controller\Volunteer;

use App\Entity\Organization;
use App\Entity\Person;
use App\Exception\UnknownOrganizationException;
use App\Form\Volunteer\VolunteerProfileDraft;
use App\Form\Volunteer\VolunteerProfileType;
use App\Repository\PersonRepository;
use App\Tenant\TenantContext;
use App\ValueObject\Email;
use App\Volunteer\CurrentVolunteer;
use App\Volunteer\VolunteerLogin;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

use function is_array;
use function is_string;

/**
 * « Mon profil ».
 *
 * For a logged-in volunteer: name and address shown, read-only — they change through a
 * confirmed declaration, so the identity on a CERFA has always been stated on one — and the
 * distance and vehicle editable.
 *
 * Right after a first link, before any Person exists: the same page asks for the name and
 * address too, and saving it creates the Person and logs them in. Nobody else reaches it:
 * without a logged-in volunteer or a proven address, it sends to « Se connecter ».
 */
#[Route(
    path: '/a/{organizationSlug}/profil',
    name: 'volunteer_profile',
    methods: [Request::METHOD_GET, Request::METHOD_POST],
)]
final readonly class ProfileController
{
    public function __construct(
        private Environment $twig,
        private FormFactoryInterface $formFactory,
        private TenantContext $tenantContext,
        private CurrentVolunteer $currentVolunteer,
        private PersonRepository $people,
        private VolunteerLogin $login,
        private EntityManagerInterface $entityManager,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $organization = $this->tenantContext->tryGetOrganization() ?? throw new UnknownOrganizationException();
        $person = $this->currentVolunteer->get();

        if (null !== $person) {
            return $this->edit($request, $organization, $person);
        }

        $proven = $this->provenEmail($request, $organization);

        if (null === $proven) {
            return new RedirectResponse($this->urlGenerator->generate('volunteer_login', ['organizationSlug' => $organization->getSlug()]));
        }

        return $this->create($request, $organization, $proven['email'], $proven['rememberMe']);
    }

    private function edit(Request $request, Organization $organization, Person $person): Response
    {
        $draft = VolunteerProfileDraft::of($person);
        $form = $this->formFactory->create(VolunteerProfileType::class, $draft);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $person->updateVehicle($draft->distanceKm, $draft->fiscalPower, $draft->electricVehicle);
            $this->entityManager->flush();
            $this->flash($request, 'Votre profil est enregistré.');

            return new RedirectResponse($this->urlGenerator->generate('volunteer_profile', ['organizationSlug' => $organization->getSlug()]));
        }

        return $this->render($organization, $form->createView(), $person, null, $form->isSubmitted());
    }

    private function create(Request $request, Organization $organization, string $email, bool $rememberMe): Response
    {
        $draft = new VolunteerProfileDraft();
        $form = $this->formFactory->create(VolunteerProfileType::class, $draft, ['with_identity' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $address = new Email($email);
            // Declared anonymously in the meantime, or a second tab: never two Persons.
            $person = $this->people->findOneByEmail($address)
                ?? new Person($organization, (string) $draft->firstName, (string) $draft->lastName, $address, $draft->address());
            $person->updateVehicle($draft->distanceKm, $draft->fiscalPower, $draft->electricVehicle);
            $this->entityManager->persist($person);

            $this->login->login($person, $rememberMe);
            $this->entityManager->flush();
            $request->getSession()->remove(LinkCheckController::PROVEN_EMAIL);
            $this->flash($request, 'Votre espace bénévole est créé.');

            return new RedirectResponse($this->urlGenerator->generate('declaration', ['organizationSlug' => $organization->getSlug()]));
        }

        return $this->render($organization, $form->createView(), null, $email, $form->isSubmitted());
    }

    /**
     * @return array{email: string, rememberMe: bool}|null
     */
    private function provenEmail(Request $request, Organization $organization): ?array
    {
        if (!$request->hasPreviousSession()) {
            return null;
        }

        $proven = $request->getSession()->get(LinkCheckController::PROVEN_EMAIL);

        if (!is_array($proven)
            || ($proven['organization'] ?? null) !== $organization->getId()->toRfc4122()
            || !is_string($proven['email'] ?? null)) {
            return null;
        }

        return ['email' => $proven['email'], 'rememberMe' => true === ($proven['rememberMe'] ?? false)];
    }

    private function render(Organization $organization, \Symfony\Component\Form\FormView $form, ?Person $person, ?string $newEmail, bool $submitted): Response
    {
        return new Response(
            $this->twig->render('volunteer/profile.html.twig', [
                'organization' => $organization,
                'form' => $form,
                'person' => $person,
                'new_email' => $newEmail,
            ]),
            $submitted ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK,
        );
    }

    private function flash(Request $request, string $message): void
    {
        $session = $request->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('success', $message);
        }
    }
}
