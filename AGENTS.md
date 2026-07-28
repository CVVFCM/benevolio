# AGENTS.md

Guidance for working in this repository.

## What this is

**benevolio** — a SaaS for French *associations loi 1901* to manage volunteer
contributions, and to turn them into accounting entries and tax receipts.

It is multi-tenant from the ground up. Only one association uses it today; the
model does not assume that.

## Domain — read this before writing business code

The whole point of the application is legal paperwork, so the vocabulary is
legal, French, and not interchangeable. Three things a volunteer can give look
similar and are accounted for completely differently:

| Business term | Accounting treatment | Tax receipt? |
|---|---|---|
| **bénévolat valorisé** — donated hours, valued at a rate | Off-balance-sheet, PCG class 8: debit **864** *Personnel bénévole* / credit **870** *Bénévolat* (ANC règlement 2018-06) | **No.** Donated time is never receiptable. |
| **abandon de frais** — expenses the volunteer paid and waives reimbursement of (mileage, tolls, supplies) | A real donation: **754x** | **Yes** — this is what generates a CERFA. |
| **dons en nature** — goods or services given | Off-balance-sheet **871**/**875**, or **754** when it is a real donation with a receipt | Depends on which of the two it is. |

The receipt is **CERFA n°11580\*05**, form **2041-RD**. Numbering must be
continuous per financial year and never reused.

Two consequences that are easy to get wrong:

- Valuing volunteer hours and issuing a tax receipt are **not** the same
  pipeline. Do not let a valued hour reach a receipt.
- The volunteer mileage scale (*barème kilométrique bénévole*) is its own, lower
  scale — not the general one used for salaried employees.

**Volunteers have no account.** They are not `User`s. They identify themselves on
a public form under `/a/{organizationSlug}/…` with an email one-time code. Only
back-office staff log in.

### Deferred — do not assume these exist

Not built yet, by explicit decision: accounting entries and their export, tax
receipts, the `Contribution` entity and its state machine, mission types and
valuation rates, and the public volunteer form. When you add them, update this
file.

## Stack

- **PHP 8.5**, Symfony 8.1.*
- **Doctrine ORM 3** + migrations, **PostgreSQL 18**
- **EasyAdmin 5** for the back-offices, plain **Symfony Forms** for the public
  volunteer surface
- **yohang/finite** for state machines — *not* `symfony/workflow`
- **FrankenPHP** (Caddy) runtime — `Dockerfile`, `.infra/docker/php/Caddyfile`
- **AssetMapper** + importmap (`assets/`, `importmap.php`); no Node build step
- **PHPUnit 13** + **Zenstruck Foundry** + **dama/doctrine-test-bundle**

Stimulus is allowed but **not installed**. Add `symfony/stimulus-bundle` when a
real interaction needs it, not before.

## Multi-tenancy — the rule that matters most

`Organization` is the tenant. Every business entity belongs to exactly one.

```php
final class Contribution implements TenantAware   // src/Tenant/TenantAware.php
{
    use TenantAwareTrait;                          // gives it the mapping + getter

    public function __construct(Organization $organization /* … */)
    {
        $this->organization = $organization;
    }
}
```

`App\Doctrine\Filter\OrganizationFilter` adds a `WHERE organization_id = …` to
every query on a `TenantAware` entity, and to nothing else. It is declared
`enabled: false` and armed per request by `App\Tenant\TenantRequestListener`
(kernel.request priority 4 — after the router, so route attributes exist, and
after the firewall, so the token exists).

Two resolvers run in priority order, first match wins:

1. `UrlPrefixTenantResolver` — the `{organizationSlug}` route attribute, for the
   anonymous public forms.
2. `UserTenantResolver` — the logged-in account, for `/admin`.

### Non-negotiables

- **Every new business entity uses `TenantAwareTrait` and ships an isolation
  test.** `tests/Tenant/OrganizationFilterTest.php` is the pattern. This is the
  one test that must never be skipped.
- **`Organization` and `User` are not `TenantAware`.** `Organization` *is* the
  tenant; filtering `User` would break authentication, because the user provider
  runs before any tenant is known.
- **The filter is OFF in CLI** — console commands, migrations, fixtures. That is
  deliberate (a migration must touch every tenant), but any command acting on
  behalf of one association must scope its own queries.
- **`TenantContext` is request-scoped state in a shared service.** It implements
  `ResetInterface` and is tagged `kernel.reset` in `services.yaml`. Under
  FrankenPHP worker mode the container survives between requests, so anything
  request-scoped needs the same treatment or it leaks across tenants.

### Two back-offices, on purpose

- `/admin` — one association. Tenant filter armed.
- `/platform` — manages the associations themselves. `ROLE_SUPER_ADMIN` only.

`/platform` runs unfiltered not by bypassing the filter but because a super-admin
is attached to no organization, so nothing arms it. Keep it that way: a
super-admin must never be given an `Organization`.

EasyAdmin registers **every** CRUD controller under **every** dashboard by
default. The `deniedControllers` list on `App\Controller\Admin\DashboardController`
is what keeps the platform CRUDs off `/admin`; there is a test for it. Any CRUD
added to `/admin` must be tenant-scoped.

## Layout

- `src/Entity/` — domain model
- `src/Repository/` — Doctrine repositories
- `src/Controller/` — invokable controllers; `Admin/` and `Platform/` hold the
  two EasyAdmin dashboards
- `src/Tenant/` — tenant contract, resolvers, context, request listener
- `src/Doctrine/Filter/` — the tenant SQL filter
- `src/Security/` — roles, voters
- `src/Factory/` — Foundry factories (used by both tests and fixtures)
- `src/Exception/` — `ExceptionInterface` and shared exceptions
- `src/DataFixtures/` — dev dataset
- `config/packages/` — per-bundle config
- `migrations/`, `templates/`, `translations/`, `assets/`
- `.infra/` — Caddyfile, entrypoint, TLS certs, Helm chart

Add `src/ValueObject/` when the domain needs it. There is no `src/Bridge/` and no
third-party integration; if one arrives, give it its own namespace then.

## Running (Docker, via Makefile)

**Use the Makefile, not raw `docker compose`** (except `docker compose logs`).

```bash
make run          # First run: TLS + pull + build + up + DB reset + assets. Then just starts containers
make up           # Start containers only
make reset        # Reset/create the database, migrate, load fixtures
make cli          # Bash shell in the php container
make test         # PHPUnit
make reset-test   # Reset/create the test DB (run once)
make stan         # PHPStan
make cs           # Fix code style (php-cs-fixer + twig-cs-fixer + eslint + stylelint)
make cs-check     # Same, read-only — what CI runs
make lint         # lint:container, lint:yaml, lint:twig, lint:xliff, schema:validate
make qa           # Everything CI runs
make clean        # Stop containers, remove all data/volumes/vendor
```

Run `bin/console` and `composer` **inside the php container** (`make cli`, or
`docker compose exec php …`). The entrypoint waits for the DB and applies pending
migrations on container start.

App served at `https://localhost`. Fixture accounts:
`super-admin@benevolio.test` and `admin@association-demo.test`, password in
`App\Factory\UserFactory::DEFAULT_PASSWORD`.

## Conventions

- `services.yaml` uses autowire + autoconfigure; classes in `src/` are
  auto-registered. `src/Controller/` additionally carries the
  `controller.service_arguments` tag.
- Doctrine ORM 3: **attributes** for mapping, never annotations.
- **Entity ids are UUID v7**, generated in the constructor
  (`Uuid::v7()`), stored in PostgreSQL's native `uuid` column. The
  `uuid` DBAL type is registered in `config/packages/doctrine.yaml`.
- **Entities take no constructor arguments** when EasyAdmin manages them — it
  instantiates with `new $fqcn()` before binding the form. Required fields are
  guarded by validation constraints, not by the constructor signature.
- Avoid PostgreSQL reserved words as table names (`User` maps to `app_user`).
- **Exceptions**: every custom exception extends the closest native PHP exception
  (`\RuntimeException`, `\LogicException`, …) **and** implements
  `App\Exception\ExceptionInterface`, so callers can catch any project error
  through that interface.
- **Route names** are bare and meaningful — no `app_` prefix, it carries no
  information.
- **HTTP verbs** use `Request::METHOD_*`, never string literals.
- **UI is French.** Code identifiers are English. User-facing strings live in
  `translations/*.fr.xlf` (`messages` for the app, `admin` for EasyAdmin);
  Symfony already ships `validators.fr.xlf` and `security.fr.xlf`, so do not
  duplicate those.

### State machines (finite)

The state enum implements `Finite\State` and declares its own transitions:

```php
enum ContributionState: string implements State
{
    case DRAFT = 'draft';
    case SUBMITTED = 'submitted';

    public static function getTransitions(): array
    {
        return [new Transition('submit', [self::DRAFT], self::SUBMITTED)];
    }

    public function isEditable(): bool { return self::DRAFT === $this; }
}
```

Put behaviour on the enum as methods rather than testing the state in domain
code. Guards and side effects are PSR-14 listeners on `CanTransitionEvent` /
`PostTransitionEvent`, i.e. ordinary Symfony listeners.

`FiniteBundle` is **deliberately not registered**: `yohang/finite` 2.0.0
overrides `Bundle::registerCommands()` and calls `Application::add()`, which
Symfony 8 removed in favour of `addCommand()` — registering it breaks every
`bin/console` call. `config/packages/finite.yaml` declares the services by hand
instead, giving the same feature set (the `StateMachine` service, the
`finite_can` / `finite_reachable_transitions` Twig functions, and
`finite:state-machine:dump`). Delete that file and register the bundle once
upstream is Symfony 8 compatible.

### Controllers

Application controllers do **not** extend `AbstractController`. They are
`final`, invokable (one action per class), and receive collaborators by
constructor injection (`Environment`, `UrlGeneratorInterface`, …), returning
`Response` directly. With no base class, you set the status code yourself — see
`App\Controller\LoginController`, which returns 401 on a failed login and would
otherwise answer 200 for a rejected credential. Set 422 by hand on an invalid
submitted form.

**Documented exception:** EasyAdmin dashboards and CRUD controllers must extend
`AbstractDashboardController` / `AbstractCrudController`, which extend
`AbstractController`. That is unavoidable and applies only to `src/Controller/Admin/`
and `src/Controller/Platform/`.

### Code quality and typing

Use every quality keyword the language offers:

- `declare(strict_types=1);` in **every** PHP file.
- Classes are **`final` by default**. Exceptions: Doctrine entities (lazy-loading
  proxies), interfaces, EasyAdmin base classes, and deliberate extension points.
- **`readonly`** on every constructor-promoted property and any property assigned
  once. When *all* of a class's state is read-only, declare the whole class
  `final readonly` and drop the per-property `readonly` — value objects, DTOs,
  stateless services and controllers. Not possible when extending a
  non-readonly parent (form types, native exceptions): keep per-property
  `readonly` there. Entities are mutable and are not `readonly`.
- **Full type coverage**: every parameter, return and property natively typed;
  `mixed` only when unavoidable.
- Document **array shapes** with PHPDoc generics (`list<T>`, `array<K, V>`,
  `array{…}`) wherever a bare `array` appears — PHPStan at level max requires it.
- Typehint the **narrowest interface** (`TokenStorageInterface` over the
  SecurityBundle `Security` helper, `UrlGeneratorInterface`, …). It is also what
  keeps classes testable without a container.
- Native **backed enums** for closed sets (see `App\Security\Role`).
- **Typed class constants**: `public const int MAX = 8;`.
- Generic parent classes need their template documented:
  `@extends AbstractCrudController<Organization>`,
  `@extends ServiceEntityRepository<User>`.

## Testing — MANDATORY

**Every new PHP feature ships with PHPUnit coverage.** A feature without tests is
not finished — same rule as the linters.

- **Unit/integration**: `KernelTestCase`, with a real repository and database.
  `MockHttpClient` for outbound HTTP, `MockClock` for time.
- **Functional**: `WebTestCase` through the real HTTP entry point.
- **All test data comes from Foundry factories** in `src/Factory/`, never
  hand-built entities.
- Cover at minimum the happy path, the branches that change behaviour, and input
  validation / error handling.
- Every tenant-scoped entity gets an isolation test. See above.

Test method names are `snake_case` (`.php-cs-fixer.dist.php` overrides
`@Symfony` for this — long descriptive names read better that way).

Two traps, both already handled but worth knowing:

- `tests/bootstrap.php` forces `APP_ENV=test` **before** loading `.env`. PHPUnit
  applies its `<server>` block *after* the bootstrap file, so `.env`'s
  `APP_ENV=dev` would otherwise win and the kernel would boot in dev.
- In a `WebTestCase`, call `self::createClient()` **before** any factory. Foundry
  boots the kernel on first persist, and `WebTestCase` refuses to build a client
  once the kernel is booted. Create the client in `setUp()`.

`tests/Fixtures/Entity/TenantProbe.php` is a throwaway `TenantAware` entity,
mapped in the test environment only, that exists so the isolation test can run a
real Doctrine query. **Delete it** once a real tenant-scoped business entity can
carry that test.

Run `make reset-test` once, then `make test`.

## Linters — MANDATORY

All produced code MUST pass every check CI runs
(`.github/workflows/ci.yaml`). A change that fails any of them is not finished.
`make qa` runs the lot.

- **PHPStan** — level max + bleedingEdge + strict-rules, symfony, doctrine and
  phpunit extensions: `make stan`
- **PHP CS Fixer** and **Twig CS Fixer**: `make cs` / `make cs-check`
- **PHPUnit**: `make test`
- Container / YAML / Twig / translations / schema drift: `make lint`
- **ESLint**, **StyleLint**: `npm run lint:js`, `npm run lint:css`
- **hadolint** on the `Dockerfile`

**There is no PHPStan baseline, and none is to be added.** When a rule is
provably wrong, use a narrow inline `@phpstan-ignore` with a comment explaining
why — see `src/Kernel.php`, where `getAllowedEnvs()` overrides a trait-private
framework hook that PHPStan cannot see being called.

`config/reference.php` is auto-generated and excluded from php-cs-fixer;
formatting it would be undone on the next regeneration.
