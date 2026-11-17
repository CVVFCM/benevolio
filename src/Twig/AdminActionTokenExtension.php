<?php

declare(strict_types=1);

namespace App\Twig;

use App\Security\AdminActionToken;
use Stringable;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `admin_action_token(action.name, entity.primaryKeyValue)`: the token a
 * #[RequiresActionToken] button posts, with the id built by AdminActionToken::id() — the
 * same call App\Security\ActionTokenListener checks against.
 */
final class AdminActionTokenExtension extends AbstractExtension
{
    public function __construct(
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('admin_action_token', $this->token(...)),
        ];
    }

    public function token(string $actionName, string|Stringable $entityId): string
    {
        return $this->csrfTokenManager->getToken(AdminActionToken::id($actionName, $entityId))->getValue();
    }
}
