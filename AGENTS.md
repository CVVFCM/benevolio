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

**Volunteers have no account.** They are not `User`s. They identify themselves by
filling the first step of the public form under `/a/{organizationSlug}/declaration`.
Only back-office staff log in.

### The declaration model

A **`Declaration`** is one submission of that form: a `Person`, a set of
`DeclarationAction` lines, and the two legal statements. **Both statements are
mandatory**, so every declaration carries the waiver — which is what makes the
declared expenses a donation at all.

A single `DeclarationAction` carries *both* kinds of contribution, accounted for
differently, so never sum them together:

| Field | Meaning |
|---|---|
| `workHours` | `DECIMAL(5,2)`, in hours. Donated time → 864/870, never receiptable. Totals are summed in exact integer hundredths (`getWorkHoursInHundredths()`), not as floats; ext-bcmath is not installed. |
| `distanceKm` | Kilometres of **one journey, one way**. |
| `journeys` | Number of **one-way** journeys — a return trip is two. Total distance is `distanceKm × journeys`. |
| `fiscalPower` | An enum of the *barème* brackets (≤3 CV, 4, 5, 6, ≥7 CV), because the scale distinguishes only those. Required exactly when `ownVehicle` is true. **No euro rates anywhere**: the scale is republished yearly and belongs with valuation, keyed by financial year. |
| `consecutiveDays` | The action may span several days from `date`. The action must be **over**: `DeclarationAction::endDateFor()` is the shared arithmetic, applied on the DTO *and* the entity, and it normalises to midnight — an end date of "today at 17:30" would otherwise read as later than "today". |
| `eventType` | A per-association **entity**, not an enum. See below. |

**Event types are rows, not code.** `EventType` is `TenantAware`; each association
manages its own list in `/admin`. `App\Organization\DefaultEventTypes` seeds five
starters (*Travaux, Régate, Encadrement, Arbitrage, Autre*) and is called from the
**two** places an organization is born — the platform CRUD and
`OrganizationFactory`. A `postPersist` listener would be one place instead of two,
but persisting from inside `postPersist` needs a second flush and is fragile; the
cost is that a third creation path would silently skip seeding, which
`DefaultEventTypesTest` exists to catch.

Deleting a type an action references is refused by the FK: a filed declaration
must not lose the label it was filed under. Retire one with `active` instead — it
vanishes from new forms and still renders on old actions.

**TRAP — that FK is `ON DELETE NO ACTION`, and the word matters.** `RESTRICT`
refuses the delete just as firmly, but makes PostgreSQL raise SQLSTATE **23001**
(`restrict_violation`), and DBAL's PostgreSQL `ExceptionConverter` only maps
**23503** to `ForeignKeyConstraintViolationException`. Under `RESTRICT` the error
is therefore a generic driver exception that no `catch` in this codebase — nor
EasyAdmin's own, in `AbstractCrudController::delete()` — will ever see, and the
admin gets a 500. `NO ACTION` yields 23503 and the catch works. Any other FK meant
to refuse a delete must use `NO ACTION` for the same reason.

`EventTypeCrudController::deleteEntity()` still overrides EasyAdmin's handling:
left alone, EasyAdmin turns the caught exception into its own 409 page, whose
message tells a *developer* to disable the delete action or add `cascade`, in
English. A treasurer gets a French sentence naming the type instead.

A `Person` is matched by **(organization, email)**, and `Email` lowercases itself
so that holds when the volunteer types a different case next year. Their address
is the *current* one, overwritten by each declaration; if a re-issued receipt ever
needs the address as it was at the time, that snapshot belongs on `Declaration`.

### Double opt-in — a declaration is not final when it is submitted

Submitting stores the declaration in **`awaiting_confirmation`** and emails the
volunteer a link. Until they open it, the association cannot act on it. The click
also proves the address works, which is what a CERFA receipt will have to be sent
to.

- The token lives on `Declaration` (`confirmationToken`, its expiry, `confirmedAt`),
  is valid **7 days**, and is **kept after use** rather than cleared — it can only
  ever cause one confirmation, and keeping it is what lets a second click, or a
  mail client prefetching the link, land on a success page instead of a 404.
- Stored unhashed, deliberately: hashing needs a separate plaintext selector to
  stay findable, and the threat it defends against already has the declarations.
- `DeclarationConfirmer` returns one of three outcomes plus "unknown", each with
  its own page. **A repeat click is a success, not an error.**
- `Declaration` is `TenantAware`, so a token cannot be redeemed through another
  association's URL — the filter refuses to find it. Tested.
- There is **no resend** yet. A mistyped address means refilling the form.
- `DeclarationState::isDecided()` means validated-or-refused. Awaiting confirmation
  is *undecided but not actionable*, so anything asking "can a verdict be applied"
  must check `isAwaitingConfirmation()` too — `DeclarationDecider` does.

### Deferred — do not assume these exist

Not built yet, by explicit decision: accounting entries and their export, tax
receipts, valuation rates and mission types, resending a confirmation link, and
purging declarations never confirmed. When you add them, update this file.

## Stack

- **PHP 8.5**, Symfony 8.1.*
- **Doctrine ORM 3** + migrations, **PostgreSQL 18**
- **EasyAdmin 5** for the back-offices, plain **Symfony Forms** for the public
  volunteer surface
- **yohang/finite** for state machines — *not* `symfony/workflow`
- **FrankenPHP** (Caddy) runtime — `Dockerfile`, `.infra/docker/php/Caddyfile`
- **AssetMapper** + importmap (`assets/`, `importmap.php`); no Node build step
- **PHPUnit 13** + **Zenstruck Foundry** + **dama/doctrine-test-bundle**
- **Mailpit** as the development mail catcher (dev compose only)
- **Stimulus** (`symfony/stimulus-bundle`), installed for the one interaction that
  needed it: adding rows to the actions collection. Its recipe also brought
  `assets/controllers/csrf_protection_controller.js`, which is what makes the
  stateless CSRF token work in a browser — the token renders as a placeholder that
  JavaScript fills from a cookie, falling back to an `Origin` check.
- **symfony/expression-language**, required by `Assert\Expression` and
  `Assert\When`. Without it those constraints throw at validation time rather
  than failing to load, so the gap is invisible until something is validated.

## Multi-tenancy — the rule that matters most

`Organization` is the tenant. Every business entity belongs to exactly one.

```php
class Declaration implements TenantAware            // src/Tenant/TenantAware.php
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
- **`DeclarationAction` is the one documented exception**, by decision: it is
  reached through its `Declaration`, which is tenant-scoped. The filter therefore
  does *not* touch it, and anything querying it directly must scope itself. Three
  places do, and all three are covered by
  `tests/Controller/Admin/DeclarationActionIsolationTest`:
  `createIndexQueryBuilder()` joins the declaration (index and autocomplete),
  `Crud::setEntityPermission()` runs `DeclarationActionVoter` (detail), and the
  custom transition actions check for themselves — because
  **`setEntityPermission()` does not cover custom CRUD actions**: EasyAdmin marks
  the entity inaccessible and hands the action a `null` instance instead of
  refusing the request. If you ever give this entity an `organization` FK, delete
  all three and the voter with them.
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
- `src/ValueObject/` — self-validating value objects (`Address`, `Email`). Also a
  Doctrine mapping, because `Address` is an `#[ORM\Embeddable]`.
- `src/Enum/` — closed business sets (`FiscalPower`)
- `src/State/` — finite state enums, and `Listener/` for their guards
- `src/Repository/` — Doctrine repositories
- `src/Controller/` — invokable controllers; `Admin/` and `Platform/` hold the two
  EasyAdmin dashboards, `Public/` the anonymous volunteer pages
- `src/Form/` — form types and the DTOs they bind to
- `src/Declaration/` — the declaration use cases (`DeclarationSubmitter`,
  `DeclarationConfirmer`, `DeclarationDecider`) and their exceptions
- `src/Organization/` — organization-level services (`DefaultEventTypes`)
- `src/Tenant/` — tenant contract, resolvers, context, request listener
- `src/Doctrine/Filter/` — the tenant SQL filter; `src/Doctrine/Type/` — DBAL types
- `src/Security/` — roles, voters
- `src/Factory/` — Foundry factories (used by both tests and fixtures)
- `src/Exception/` — `ExceptionInterface` and shared exceptions
- `src/DataFixtures/` — dev dataset
- `config/packages/` — per-bundle config
- `templates/form/` — the public form theme
- `templates/emails/` — mail bodies. Inline styles and table layout: mail clients
  strip `<style>` and have no grid, so these share nothing with the site stylesheet.
- `migrations/`, `templates/`, `translations/`, `assets/`
- `.infra/` — Caddyfile, entrypoint, TLS certs, Helm chart

There is no `src/Bridge/` and no third-party integration; if one arrives, give it
its own namespace then.

## The public declaration form

`/a/{organizationSlug}/declaration`, anonymous, built on Symfony 8.1's native
**`FormFlow`** (`Symfony\Component\Form\Flow`). Three things about it decide the
design, and getting any of them wrong fails quietly:

- **`validation_groups` defaults to `['Default', <current step>]`.** So every
  constraint on `DeclarationDraft` and `ActionDraft` names its step, and **nothing
  is in `Default`** — a constraint left there fires on every step, and step 1
  would fail on the still-empty step 2 fields. `tests/Controller/Public/DeclarationFlowTest`
  pins this down explicitly.
- **`step_property_path` is required**, hence `DeclarationDraft::$step`.
- **The session key must include the organization id.** The flow keeps its draft in
  the session and the session is not scoped to the URL prefix, so a shared key
  would surface a half-filled declaration on another association's form in the
  same browser. Also tested.

Other things worth knowing before touching it:

- The route placeholder **must** stay `organizationSlug`: it is what
  `UrlPrefixTenantResolver` reads, and the only way a tenant resolves for a visitor
  with no account. An unknown or inactive slug is a **404**, not the
  `LogicException` that `TenantContext::getOrganization()` would raise.
- Advancing a step renders the next one directly — `FormFlow` does no
  POST-redirect-GET between steps and guards against a POST reload itself. Only the
  final submit redirects.
- `DeclarationDraft` seeds one blank `ActionDraft` in its constructor, or the
  actions step renders no fields at all for a visitor without JavaScript.
- `NavigatorFlowType` cannot label its buttons, so the three are added
  individually; `FormFlow` still prunes whichever does not apply.
- The form is **`novalidate`**. Native validation bubbles cannot be styled or
  translated, appear in the browser's locale rather than the page's, and pre-empt
  the server-side messages — which are the only ones able to express the real
  rules. One error system, in French.
- `templates/form/public_theme.html.twig` owns how a field is composed. Add fields
  through it rather than styling one in place.

`DeclarationSubmitter` is the boundary where the value objects are built. Their
assertions should be unreachable there, because the form validated the same rules
and reported them per field; reaching one means the two rule sets have drifted,
which is when a loud failure is wanted.

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
`super-admin@benevolio.test` and `admin@cvvfcm.test`, password in
`App\Factory\UserFactory::DEFAULT_PASSWORD`. The association's public form is at
`/a/cvvfcm/declaration`.

**Mail sent in development is caught by Mailpit at `http://localhost:8025`** —
nothing leaves the machine. It is declared in `compose.override.yaml`, which is
dev-only, so a deployment built from `compose.yaml` never ships a mail trap. Ports
are overridable with `MAILPIT_SMTP_PORT` / `MAILPIT_HTTP_PORT`.

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

The state enum implements `Finite\State` and declares its own transitions — see
`src/State/DeclarationActionState.php` for the live example. Put behaviour on the
enum as methods rather than testing the state in domain code. Guards and side
effects are PSR-14 listeners on `CanTransitionEvent` / `PostTransitionEvent`, i.e.
ordinary Symfony listeners.

**Entities have no `setState()`.** Finite writes the state property through
reflection, so a setter would exist only as a way to bypass the machine and its
guard. Go through `Finite\StateMachine::apply()`.

### The two declaration machines, and the guard between them

`Declaration` runs `awaiting_confirmation → submitted → validated | refused`;
`DeclarationAction` runs `submitted → validated | refused`. Because `validate` and
`refuse` name `submitted` as their only source, an unconfirmed declaration is
undecidable without any guard. Nothing structural stops them disagreeing, so
`App\State\Listener\DeclarationTransitionGuard` blocks the whole-declaration
verdict until every line already agrees with it.

**Known consequence, accepted:** a genuinely mixed basket — one line validated,
one refused — has no terminal state and stays *soumise*. If that becomes a
problem the fix is a `partially_validated` state or a derived status, **not**
weakening the guard. Note that `{refused, submitted}` is *not* mixed: refusing the
rest is still a coherent verdict, and `DeclarationDecider::refuseAll()` does it.

`DeclarationDecider` is the only thing that applies a bulk verdict. It refuses an
impossible one **up front** rather than discovering it mid-loop: a transaction
would roll the database back, but the in-memory entities would already be mutated,
which is nasty to leave behind on a request that continues.

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

Three harness traps that have already bitten:

- **Do not put a Doctrine entity in a `FormFlow` DTO and expect it to stay
  managed.** The draft lives in the session between steps and `SessionDataStorage`
  deep-clones it, which detaches the entity — persisting it then fails with "A new
  entity was found through the relationship". `DeclarationSubmitter` re-fetches the
  event type for exactly this reason, which also re-checks the tenant.


- **Create the client before any factory.** Foundry boots the kernel on first
  persist and `WebTestCase` refuses to build a client afterwards. Create it in
  `setUp()`.
- **Clear the EntityManager before a request that reads a collection you just
  built.** `WebTestCase` shares its EntityManager with the requests it makes, and
  Foundry's `createMany()` leaves the *inverse* side stale — three rows created,
  one element in the collection. Through the identity map the controller gets that
  same object, so "validate all" once saw one line out of three and the guard was
  satisfied by a collection that did not match the database. A real request always
  gets a fresh EntityManager, so this is a harness artefact — but it makes tests
  lie, which is worse than failing.
- **A test helper that reads the database must disable the tenant filter.** The
  filter is request-scoped, so after a request to another association's URL a plain
  `findAll()` returns nothing — the filter working correctly, and the helper
  reporting a falsehood.

Also: `Foundry`'s `defaults()` is evaluated whether or not the caller overrides
the keys it sets. Creating an entity in there to make two attributes consistent
produces one orphan per object built — `DeclarationActionFactory` learned this the
expensive way and now exposes `for()` / `forDeclaration()` instead.

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
