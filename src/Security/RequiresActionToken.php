<?php

declare(strict_types=1);

namespace App\Security;

use Attribute;

/**
 * On a CRUD method reached by a button that changes something: the request must carry the
 * token rendered by templates/admin/action/post_with_token.html.twig, or nothing runs.
 *
 * Checked once, by App\Security\ActionTokenListener, before the controller is called —
 * so no action can forget to, and tests/Controller/Admin/ActionTokenGuardTest fails when
 * a POST action lacks the attribute.
 *
 * The action declaring it must use post_with_token.html.twig, and its CRUD must call
 * showEntityActionsInlined(): EasyAdmin's dropdown renders its own form and drops the
 * token.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class RequiresActionToken
{
}
