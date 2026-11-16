<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\OrganizationRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * The public home page: what Benevolio is, for an association considering it, and the
 * associations that chose to be listed, each linking to its declaration form.
 *
 * Anonymous, and kept that way on purpose: nothing here reads the security token or
 * starts a session, so the lazy firewall stays asleep and the response can be cached
 * publicly. Touch `app.user` in the template and every visitor gets a private response.
 *
 * The contact address is the platform's sender, MAIL_FROM — the one address this
 * deployment is already configured to own.
 */
#[Route(path: '/', name: 'home', methods: [Request::METHOD_GET])]
final readonly class HomeController
{
    /** Short: an association ticking « Visible sur la page d'accueil » expects to see it soon. */
    private const int MAX_AGE = 300;

    public function __construct(
        private Environment $twig,
        private OrganizationRepository $organizations,
        #[Autowire('%app.mail_from%')]
        private string $contactEmail,
    ) {
    }

    public function __invoke(): Response
    {
        return new Response($this->twig->render('home.html.twig', [
            'organizations' => $this->organizations->findListedOnHomepage(),
            'contact_email' => $this->contactEmail,
        ]))
            ->setPublic()
            ->setMaxAge(self::MAX_AGE);
    }
}
