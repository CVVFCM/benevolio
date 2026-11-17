<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Uid\Uuid;

/**
 * The CSRF token id of a back-office action that changes something from a button:
 * validating or refusing a line or a declaration.
 *
 * EasyAdmin's renderAsForm() emits a POST form and no token, so these actions carry
 * their own, rendered by templates/admin/action/post_with_token.html.twig and checked
 * by the controller. The id names the action AND the row, so a token minted for one
 * cannot be replayed on another.
 *
 * The template rebuilds the same string from PREFIX, the action's name and the row's
 * primary key; the controllers call id(). Change one and change the other.
 */
final readonly class AdminActionToken
{
    public const string PREFIX = 'admin-action-';

    /** The request field the token travels in — EasyAdmin's own name for it. */
    public const string FIELD = 'token';

    public static function id(string $actionName, Uuid $entityId): string
    {
        return self::PREFIX.$actionName.'-'.$entityId->toRfc4122();
    }
}
