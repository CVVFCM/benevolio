<?php

declare(strict_types=1);

namespace App\Controller\Volunteer;

use App\Entity\Receipt;
use App\Exception\UnknownOrganizationException;
use App\Receipt\ReceiptStorage;
use App\Repository\ReceiptRepository;
use App\Tenant\TenantContext;
use App\Volunteer\CurrentVolunteer;
use League\Flysystem\FilesystemException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;

use function sprintf;

/**
 * « Mes reçus fiscaux »: the logged-in volunteer's receipts, and each one's PDF.
 *
 * A receipt that is not theirs is a 404, the same as one that does not exist: Receipt is
 * TenantAware, so another association's is already invisible, and another volunteer's in
 * the same association must be too.
 */
final readonly class MyReceiptsController
{
    public function __construct(
        private Environment $twig,
        private TenantContext $tenantContext,
        private CurrentVolunteer $currentVolunteer,
        private ReceiptRepository $receipts,
        private ReceiptStorage $storage,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[Route(path: '/a/{organizationSlug}/mes-recus', name: 'volunteer_receipts', methods: [Request::METHOD_GET])]
    public function list(): Response
    {
        $organization = $this->tenantContext->tryGetOrganization() ?? throw new UnknownOrganizationException();
        $person = $this->currentVolunteer->get();

        if (null === $person) {
            return new RedirectResponse($this->urlGenerator->generate('volunteer_login', ['organizationSlug' => $organization->getSlug()]));
        }

        return new Response($this->twig->render('volunteer/receipts.html.twig', [
            'organization' => $organization,
            'person' => $person,
            'receipts' => $this->receipts->findBy(['person' => $person], ['issuedAt' => 'DESC']),
        ]));
    }

    #[Route(path: '/a/{organizationSlug}/mes-recus/{id}', name: 'volunteer_receipt_download', methods: [Request::METHOD_GET])]
    public function download(string $id): Response
    {
        $organization = $this->tenantContext->tryGetOrganization() ?? throw new UnknownOrganizationException();
        $person = $this->currentVolunteer->get();

        if (null === $person) {
            return new RedirectResponse($this->urlGenerator->generate('volunteer_login', ['organizationSlug' => $organization->getSlug()]));
        }

        $receipt = Uuid::isValid($id) ? $this->receipts->find(Uuid::fromString($id)) : null;

        if (!$receipt instanceof Receipt || !$receipt->getPerson()->getId()->equals($person->getId())) {
            throw new NotFoundHttpException('Receipt not found.');
        }

        try {
            $pdf = $this->storage->read($receipt->getStoragePath());
        } catch (FilesystemException) {
            throw new NotFoundHttpException('The PDF of this receipt is not in storage.');
        }

        $response = new Response($pdf, Response::HTTP_OK, ['Content-Type' => 'application/pdf']);
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            sprintf('recu-fiscal-%d-%s.pdf', $receipt->getYear(), $receipt->getNumber()),
        ));

        return $response;
    }
}
