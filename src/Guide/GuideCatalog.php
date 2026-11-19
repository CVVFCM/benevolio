<?php

declare(strict_types=1);

namespace App\Guide;

use DateTimeImmutable;

use function array_find;

/**
 * The guides, in the order /guides lists them. One list read by the pages, the sitemap
 * and llms.txt, so a guide cannot exist in one and be missing from another.
 *
 * Each has a template, templates/guides/<slug>.html.twig, written in French in place.
 * Titles stay under 60 characters and descriptions under 155: longer, search engines cut
 * them.
 */
final readonly class GuideCatalog
{
    /**
     * @return list<Guide>
     */
    public function all(): array
    {
        return [
            new Guide(
                'recu-fiscal-abandon-de-frais',
                'Reçu fiscal pour abandon de frais bénévole',
                'Un bénévole qui renonce au remboursement de ses frais fait un don : conditions, justificatifs, renonciation et reçu fiscal, sources à l\'appui.',
                new DateTimeImmutable('2026-11-19'),
            ),
            new Guide(
                'valoriser-le-benevolat',
                'Valoriser le bénévolat dans les comptes',
                'Comptes 864 et 875, méthode de valorisation des heures, place dans l\'annexe : la valorisation du bénévolat selon le règlement ANC 2018-06.',
                new DateTimeImmutable('2026-11-19'),
            ),
            new Guide(
                'bareme-kilometrique-benevole',
                'Barème kilométrique des bénévoles',
                'Depuis 2022, un bénévole applique le barème kilométrique général, majoré de 20 % pour un véhicule électrique. Barème, calcul et exemple.',
                new DateTimeImmutable('2026-11-19'),
            ),
            new Guide(
                'cerfa-11580',
                'CERFA 11580 : remplir le reçu fiscal pas à pas',
                'Le reçu fiscal des dons aux associations, cadre par cadre : bénéficiaire, donateur, montant, forme et nature du don, numérotation.',
                new DateTimeImmutable('2026-11-19'),
            ),
        ];
    }

    public function find(string $slug): ?Guide
    {
        return array_find($this->all(), static fn (Guide $guide): bool => $guide->slug === $slug);
    }
}
