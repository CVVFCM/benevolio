<?php

declare(strict_types=1);

namespace App\Demo\Exception;

use App\Exception\ExceptionInterface;
use RuntimeException;

use function sprintf;

/**
 * The reset would wipe something that is not the demo. Raised before anything is deleted.
 */
final class DemoResetRefusedException extends RuntimeException implements ExceptionInterface
{
    public static function disabled(): self
    {
        return new self('Aucune démo n\'est configurée : DEMO_ORGANIZATION_SLUG est vide.');
    }

    public static function notTheDemo(string $slug): self
    {
        return new self(sprintf(
            'L\'association « %s » a des comptes et aucun n\'est celui de la démo : elle ne ressemble pas à la démo, rien n\'a été effacé. Vérifiez DEMO_ORGANIZATION_SLUG.',
            $slug,
        ));
    }

    public static function accountTakenElsewhere(string $email): self
    {
        return new self(sprintf(
            'Le compte %s existe déjà, rattaché à une autre association ou à la plateforme : rien n\'a été effacé.',
            $email,
        ));
    }
}
