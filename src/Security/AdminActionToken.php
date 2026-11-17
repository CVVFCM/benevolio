<?php

declare(strict_types=1);

namespace App\Security;

use Stringable;

/**
 * The CSRF token id of a back-office button that changes something.
 *
 * EasyAdmin's renderAsForm() emits a POST form and no token, so these actions carry their
 * own: templates/admin/action/post_with_token.html.twig renders it through the
 * `admin_action_token()` Twig function, and App\Security\ActionTokenListener checks it
 * before any controller marked #[RequiresActionToken] runs. Both build the id here.
 *
 * The id names the action AND the row, so a token minted for one cannot be replayed on
 * another. The action's name is the CRUD method's name — what linkToCrudAction() takes.
 */
final readonly class AdminActionToken
{
    public const string PREFIX = 'admin-action-';

    /** The request field the token travels in — EasyAdmin's own name for it. */
    public const string FIELD = 'token';

    public static function id(string $actionName, string|Stringable $entityId): string
    {
        return self::PREFIX.$actionName.'-'.$entityId;
    }
}
