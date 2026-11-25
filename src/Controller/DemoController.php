<?php

declare(strict_types=1);

namespace App\Controller;

use App\Demo\DemoAssociation;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * « Essayer la démo » (#62): the demo association's login, its public form, and the warning
 * that everything typed there is seen by everyone and erased every night.
 *
 * A 404 when the deployment has no demo. Anonymous and publicly cached, like the home page:
 * nothing here reads the token or the database.
 */
#[Route(path: '/demo', name: 'demo', methods: [Request::METHOD_GET])]
final readonly class DemoController
{
    public function __construct(
        private Environment $twig,
        private DemoAssociation $demo,
    ) {
    }

    public function __invoke(): Response
    {
        if (!$this->demo->isEnabled()) {
            throw new NotFoundHttpException('Pas de démo sur cette instance.');
        }

        return new Response($this->twig->render('site/demo.html.twig', [
            'demo_slug' => $this->demo->getSlug(),
            'admin_email' => DemoAssociation::ADMIN_EMAIL,
            'admin_password' => DemoAssociation::ADMIN_PASSWORD,
        ]))
            ->setPublic()
            ->setMaxAge(GuideController::MAX_AGE);
    }
}
