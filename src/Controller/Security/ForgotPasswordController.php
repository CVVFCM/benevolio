<?php

declare(strict_types=1);

namespace App\Controller\Security;

use App\Form\Security\ForgotPasswordType;
use App\Repository\UserRepository;
use App\User\LoginLinkMailer;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

use function assert;
use function is_string;

/**
 * « Mot de passe oublié » — mails a one-hour login link to a back-office account.
 *
 * The answer is the same whether the address has an account or not, so the page
 * cannot be used to find out who administers what. Not rate-limited, by decision
 * for now: anyone can make the platform mail an address that has an account.
 */
#[Route(
    path: '/mot-de-passe-oublie',
    name: 'forgot_password',
    methods: [Request::METHOD_GET, Request::METHOD_POST],
)]
final readonly class ForgotPasswordController
{
    public function __construct(
        private Environment $twig,
        private FormFactoryInterface $formFactory,
        private UserRepository $users,
        private LoginLinkMailer $mailer,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $form = $this->formFactory->create(ForgotPasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email = $form->get('email')->getData();
            assert(is_string($email));

            $user = $this->users->findOneBy(['email' => $email]);

            if (null !== $user) {
                $this->mailer->sendPasswordReset($user);
            }

            return new Response($this->twig->render('security/forgot_password_sent.html.twig'));
        }

        return new Response(
            $this->twig->render('security/forgot_password.html.twig', ['form' => $form->createView()]),
            $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK,
        );
    }
}
