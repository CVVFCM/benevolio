<?php

declare(strict_types=1);

namespace App\Controller\Security;

use App\Entity\User;
use App\Form\Security\ChoosePasswordType;
use App\Security\LoginLinkSuccessHandler;
use App\Security\Role;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

use function assert;
use function is_string;

/**
 * « Choisissez votre mot de passe » — the page every login link leads to, and
 * which App\Security\ChoosePasswordEnforcer keeps the account on until it is done.
 *
 * Logs the user in again after saving. The new hash makes the session's token
 * stale, and the security layer would read that as "the user changed" on the next
 * request and log them out — the opposite of what they just did. Through
 * form_login, because that is the authenticator a password login uses.
 */
#[Route(
    path: '/mot-de-passe',
    name: 'choose_password',
    methods: [Request::METHOD_GET, Request::METHOD_POST],
)]
final readonly class ChoosePasswordController
{
    public function __construct(
        private Environment $twig,
        private FormFactoryInterface $formFactory,
        private Security $security,
        private UserPasswordHasherInterface $passwordHasher,
        private EntityManagerInterface $entityManager,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            // access_control requires full authentication, and only App\Entity\User
            // can authenticate.
            throw new LogicException('Choosing a password without an App\Entity\User.');
        }

        $form = $this->formFactory->create(ChoosePasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = $form->get('plainPassword')->getData();
            assert(is_string($plainPassword));

            $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
            $this->entityManager->flush();

            $request->getSession()->remove(LoginLinkSuccessHandler::SESSION_FLAG);
            $this->security->login($user, 'form_login');

            $session = $request->getSession();
            if ($session instanceof FlashBagAwareSessionInterface) {
                $session->getFlashBag()->add('success', 'Votre mot de passe est enregistré.');
            }

            return new RedirectResponse($this->urlGenerator->generate(
                $user->hasRole(Role::SUPER_ADMIN) ? 'platform' : 'admin',
            ));
        }

        return new Response(
            $this->twig->render('security/choose_password.html.twig', [
                'form' => $form->createView(),
                'user' => $user,
                'first_time' => !$user->hasPassword(),
            ]),
            $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK,
        );
    }
}
