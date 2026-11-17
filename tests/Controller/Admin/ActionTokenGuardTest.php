<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Security\RequiresActionToken;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;

use function explode;
use function in_array;
use function is_string;
use function method_exists;
use function sprintf;
use function str_contains;
use function str_starts_with;

/**
 * Every row button that changes something is guarded by App\Security\RequiresActionToken —
 * not by each action remembering to check.
 *
 * "A row button that changes something" is a POST route under /admin or /platform that
 * names a row (`{entityId}`) and is a custom action: not one of EasyAdmin's built-ins, which
 * carry their own token. A form page such as « Générer les reçus » names no row and is
 * protected by its form's token.
 */
final class ActionTokenGuardTest extends KernelTestCase
{
    #[Test]
    public function every_row_action_that_posts_requires_a_token(): void
    {
        $actions = $this->rowActionsThatPost();
        self::assertNotSame([], $actions, 'No row action found: the route filter below has drifted.');

        foreach ($actions as [$route, $class, $method]) {
            self::assertNotSame(
                [],
                new ReflectionMethod($class, $method)->getAttributes(RequiresActionToken::class),
                sprintf('%s::%s() (route "%s") posts and lacks #[RequiresActionToken].', $class, $method, $route),
            );
        }
    }

    /**
     * EasyAdmin's actions dropdown renders its own form and ignores an action's template,
     * so a token-carrying button placed there posts without its token.
     */
    #[Test]
    public function every_crud_with_such_an_action_shows_its_actions_inlined(): void
    {
        $classes = [];
        foreach ($this->rowActionsThatPost() as [, $class]) {
            $classes[$class] = true;
        }

        foreach (array_keys($classes) as $class) {
            $controller = self::getContainer()->get($class);
            self::assertInstanceOf(AbstractCrudController::class, $controller);

            self::assertFalse(
                $controller->configureCrud(Crud::new())->getAsDto()->showEntityActionsAsDropdown(),
                sprintf('%s must call showEntityActionsInlined(): its buttons carry a token.', $class),
            );
        }
    }

    /**
     * @return list<array{string, class-string, string}> route name, controller class, method
     */
    private function rowActionsThatPost(): array
    {
        self::bootKernel();
        $routes = self::getContainer()->get(RouterInterface::class)->getRouteCollection();

        $found = [];
        foreach ($routes as $name => $route) {
            $path = $route->getPath();
            $controller = $route->getDefault('_controller');

            if (!str_starts_with($path, '/admin') && !str_starts_with($path, '/platform')) {
                continue;
            }
            if (!in_array(Request::METHOD_POST, $route->getMethods(), true) || !str_contains($path, '{entityId}')) {
                continue;
            }
            if (!is_string($controller) || !str_contains($controller, '::')) {
                continue;
            }

            [$class, $method] = explode('::', $controller, 2);
            if (!class_exists($class)) {
                continue;
            }

            // EasyAdmin's built-in actions guard themselves — delete with its own token,
            // edit and new with their form's — even where a CRUD overrides one, as
            // MyOrganizationCrudController does edit().
            if (method_exists(AbstractCrudController::class, $method)) {
                continue;
            }

            $found[] = [$name, $class, $method];
        }

        return $found;
    }
}
