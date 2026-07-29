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
| **bénévolat valorisé** — donated hours, valued at a rate | Off-balance-sheet, PCG class 8: debit **864** *Personnel bénévole* / credit **875** *Bénévolat* (ANC règlement 2018-06) | **No.** Donated time is never receiptable. |
| **abandon de frais** — expenses the volunteer paid and waives reimbursement of (mileage, tolls, supplies) | A real flow: debit the charge by nature (**6251** *Voyages et déplacements*) / credit **4681** *Frais des bénévoles*, then debit **4681** / credit **75412** *Abandons de frais par les bénévoles* (art. 141-4) | **Yes** — this is what generates a CERFA. |
| **dons en nature** — goods or services given | Off-balance-sheet **870** *Dons en nature* / **871** *Prestations en nature*, or **754x** when there is a real flow | Depends on which of the two it is. |

**CORRECTED — this project had two of these wrong.** Règlement ANC 2018-06 **swapped**
two numbers from the old 99-01, where 870 was *Bénévolat* and 875 *Dons en nature*. It
is now the other way round: **bénévolat credits 875**, and 870 is *dons en nature*.
Anything citing 864/870 for volunteer hours — including earlier versions of this
file — is quoting the superseded text. The codes live in
`App\Accounting\PcgAccount`, so no account number is written as a bare string.

The receipt is **CERFA n°11580\*05**, form **2041-RD**. Numbering must be
continuous per financial year and never reused.

Two consequences that are easy to get wrong:

- Valuing volunteer hours and issuing a tax receipt are **not** the same
  pipeline. Do not let a valued hour reach a receipt.
- **There is no separate volunteer mileage scale any more.** Art. 21 of loi
  n° 2022-1157 amended CGI art. 200, 1 ter so that from revenus 2022 a volunteer uses
  the **general salaried barème** (CGI ann. IV art. 6 B, *arrêté du 27 mars 2023*)
  — by puissance fiscale **and** distance band. The old flat 0,324 €/km bénévole
  rate is abolished, and earlier versions of this file were wrong to call it a lower
  scale of its own.
  **BOFiP BOI-IR-RICI-250-20 is stale**: still the 12/09/2012 version, still printing
  the old flat rate. Cite it for the conditions (§170), the justificatifs (§210) and
  the renonciation wording (§240) — never for figures.
- The barème is **piecewise**, and only its first band is modelled: bands above
  5 000 km use a different formula with an additive constant, keyed to the volunteer's
  *cumulative* kilometres for the year. `App\Accounting\ContributionValuation`
  carries a `beyondFirstBand` flag and the ledger page says the figure is understated
  rather than presenting one it cannot stand behind.

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
| `workHours` | `DECIMAL(5,2)`, in hours. Donated time → 864/875, never receiptable. Totals are summed in exact integer hundredths (`getWorkHoursInHundredths()`), not as floats; ext-bcmath is not installed. |
| `distanceKm` | Kilometres of **one journey, one way**. |
| `journeys` | Number of **one-way** journeys — a return trip is two. Total distance is `distanceKm × journeys`. |
| `fiscalPower` | An enum of the *barème* brackets (≤3 CV, 4, 5, 6, ≥7 CV), because the scale distinguishes only those. Required exactly when `ownVehicle` is true. **No euro rates anywhere**: the scale is republished yearly and belongs with valuation, keyed by financial year. |
| `consecutiveDays` | The action may span several days from `date`. The action must be **over**: `DeclarationAction::endDateFor()` is the shared arithmetic, applied on the DTO *and* the entity, and it normalises to midnight — an end date of "today at 17:30" would otherwise read as later than "today". |
| `task` | The *tâche effectuée* — a per-association **entity**, not an enum, carrying an optional hourly rate. See below. |

**Tasks are rows, not code.** `Task` is `TenantAware`; each association
manages its own list in `/admin`. `App\Organization\DefaultTasks` seeds five
starters (*Travaux, Régate, Encadrement, Arbitrage, Autre*) and is called from the
**two** places an organization is born — the platform CRUD and
`OrganizationFactory`. A `postPersist` listener would be one place instead of two,
but persisting from inside `postPersist` needs a second flush and is fragile; the
cost is that a third creation path would silently skip seeding, which
`DefaultTasksTest` exists to catch.

It was called `EventType` until it carried a rate, at which point "type of event"
became the wrong noun: a rate belongs to a kind of *work*, not a kind of gathering.
The occasion keeps its own free-text title on the line (`DeclarationAction::$title`),
which is why *"Intitulé de l'événement"* is still the right label there.

Deleting a task an action references is refused by the FK: a filed declaration must
not lose the label it was filed under. Retire one with `active` instead — it
vanishes from new forms and still renders on old actions.

### Money — cents for amounts, millièmes for a mileage rate

**Every monetary *amount* is an integer number of cents**, named `…Cents`. Never a
float, and not a DECIMAL: an amount is multiplied by hours already summed in integer
hundredths, and `ext-bcmath` is not installed, so integers are the only way the
arithmetic stays exact. EasyAdmin's `MoneyField` stores cents natively.

**A mileage *rate* is in millièmes d'euro per kilometre**, named `milliEurosPerKm`.
The published figures have three decimals — 0,529 €/km for 3 CV et moins — so cents
would round the law. This is the one exception to "always cents", and it exists because
a rate is not an amount.

`App\Accounting\ContributionValuator` does the arithmetic, and **only the final
division rounds**, half away from zero — the *arrondi au centime* an accountant expects.
`intdiv()` alone truncates, which would shave a centime off roughly half of all
valuations and always in the association's favour, which is the direction an auditor
notices.

### Rates live on the exercice, never on the Task or the Organization

`App\Entity\FiscalYear` owns them, because they change: the barème is republished by
the state and an association revisits what an hour is worth. A rate on the `Task` would
silently rewrite years already closed — lot 5 did exactly that and lot 6 undid it.

Each rate is a **default plus optional per-type overrides**, held as rows
(`FiscalYearTaskRate`, `FiscalYearMileageRate`) rather than columns, so the override set
stays sparse and adding a `FiscalPower` bracket needs no migration.
`FiscalYear::hourlyRateCentsFor()` and `::milliEurosPerKmFor()` are **the only places
the fallback lives** — reaching for the raw override gets the default when one exists,
which is a wrong figure rather than a missing one.

**Two exercices of one association must not overlap**, or a contribution would fall in
both and be counted twice. That needs a repository lookup, so it is
`App\Validator\FiscalYearDoesNotOverlapValidator` and not an `Assert\Callback` — an
entity must not reach for a repository. Adjacent years are fine.

A contribution belongs to an exercice by its **own start date**
(`FiscalYear::contains()`), so a line spanning a year boundary belongs wholly to the
year it began in. A line no
exercice covers is stored and listed but **unvalued**: without a barème for the period
there is no figure to state.

The ledger (`/admin/fiscal-year/{id}/ledger`) lists **validated lines only** — an
unruled line is not bookable — grouped by volunteer, which is the unit a CERFA is
issued for and the unit the barème's distance bands are keyed to.

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

### Both state machines start unconfirmed

`DeclarationAction` starts in `awaiting_confirmation` too, not `submitted` — it used
to claim to be *soumise* before the volunteer had clicked anything.
`App\State\Listener\DeclarationConfirmationCascade` listens for the declaration's
`confirm` and moves every line with it.

**TRAP when adding a state to either enum:** `isDecided()` must stay
*validated-or-refused*, spelled out. It was `SUBMITTED !== $this` on the action, which
the moment a state existed *before* SUBMITTED started calling an unconfirmed line
decided. Same reason `DeclarationActionCrudController` builds its badge map from
`::cases()` rather than by hand — the hardcoded version would have left the new state
with no badge and nothing to say so.

**TRAP in fixtures and tests:** the cascade only moves the lines that exist when the
declaration is confirmed, so a line attached to an **already-confirmed** declaration
stays unconfirmed forever, and `DeclarationTransitionGuard` then makes the whole
declaration quietly undecidable. Production cannot hit this — `DeclarationSubmitter`
writes every line first — but the convenient test order does. Either create the lines
before confirming, or use `DeclarationActionFactory::confirmed()`.

## Theme, and the one inline script

`app.css` themes through `data-theme` on `<html>`, with `@media
(prefers-color-scheme: dark)` as the `auto` case. The media-query block is
`:root:not([data-theme="light"])` — **the `:not()` is load-bearing**: without it an
explicit *light* choice on a dark OS would lose, and the switcher would only appear
to work one way.

Dark values live once, as `--dark-*` on `:root`; the two blocks below hold only the
mapping, so tuning a colour stays a one-line change.

`base.html.twig` carries **the only inline script in the project**, and it has to be
inline and blocking: the choice lives in `localStorage`, so only JavaScript knows it,
and anything deferred runs after first paint — which is a white flash on every page
for anyone who chose dark. `assets/controllers/theme_controller.js` handles the
clicks; `auto` **removes** the key rather than storing the word, so the OS keeps
answering. The storage key `benevolio-theme` appears in both and must match.

The switcher ships `hidden` and its controller reveals it, so it never shows where it
would not work. It covers **the public pages and login only** — EasyAdmin has its own
switcher inside `/admin` and `/platform`.

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
- `src/State/` — finite state enums, and `Listener/` for their guards and cascades
- `src/Repository/` — Doctrine repositories
- `src/Controller/` — invokable controllers; `Admin/` and `Platform/` hold the two
  EasyAdmin dashboards, `Public/` the anonymous volunteer pages
- `src/Form/` — form types and the DTOs they bind to
- `src/Declaration/` — the declaration use cases (`DeclarationSubmitter`,
  `DeclarationConfirmer`, `DeclarationDecider`) and their exceptions
- `src/Organization/` — organization-level services (`DefaultTasks`)
- `src/Tenant/` — tenant contract, resolvers, context, request listener
- `src/Doctrine/Filter/` — the tenant SQL filter; `src/Doctrine/Type/` — DBAL types
- `src/Security/` — roles, voters
- `src/Factory/` — Foundry factories (used by both tests and fixtures)
- `src/Exception/` — `ExceptionInterface` and shared exceptions
- `src/Command/` — the console commands that bootstrap a fresh instance; see
  *Deploying*
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

## The CERFA — generating the receipt

Validating a declaration issues **CERFA n°11580\*05, form 2041-RD**, files it in object
storage and emails it to the volunteer. `App\State\Listener\ReceiptOnValidation` reacts
to the transition, not to the call site, and dispatches `IssueReceipt`.

**Only the *abandon de frais* goes in the amount box.** Donated hours are off balance
sheet and open no right to a deduction, so summing them in would overstate what the
volunteer may claim — CGI art. 1740 A penalises amounts wrongly stated at 25%. The mail
says so in as many words, because the volunteer gave the hours and is the one who would
put the wrong figure on a tax return.

**`App\Receipt\ReceiptEligibility` refuses more often than it issues**, and every
refusal is an ordinary outcome recorded in French on the declaration — "no receipt"
alone reads as a fault rather than as paperwork to finish. It refuses without a
SIREN/RNA or a postal address (the document would not be valid), when no exercice
covers the dates (no
barème, so no figure to state), and when nothing was waived.

Numbers come from `ReceiptNumberAllocator`: `2026-0001`, continuous per exercice and
**never reused**, from a counter on `FiscalYear` taken under a `PESSIMISTIC_WRITE` lock.
A counter and not `MAX(number) + 1`, so deleting a receipt cannot free its number. The
number and the receipt commit in one transaction — an allocated number that never became
a receipt is a gap in a sequence that must be continuous.

The object key is `<year>/cerfa-firstname-lastname.pdf`, which **can collide**: two
volunteers sharing a name, or one receipted twice in an exercice, overwrite each other.
That was a deliberate naming choice; `App\Entity\Receipt` is what keeps it from losing
anything, since every number, amount, date and printed identity stays in the database.

### How the PDF is made, and why it is not simpler

`Twig → Gotenberg → qpdf --overlay`, and each step is forced:

- **The official form has no form fields.** `pdfinfo` says `Form: none` — it was
  flattened by PDF24 and Ghostscript — so there is nothing to fill, and the values
  must be stamped.
- **Gotenberg cannot stamp.** It converts HTML, and `pdfengines/merge` concatenates
  pages rather than superimposing them. So Gotenberg renders a transparent layer and qpdf
  presses it on, which keeps the form vector.
- **TRAP — Gotenberg defaults to Letter** and ignores `@page { size: A4 }` in the
  document. Without `preferCssPageSize`, the layer comes back 612×792 and qpdf scales it
  onto a 595×842 page: every value drifts 7–10 mm off its line. Obvious on the page,
  invisible to a test that only counts pages — so `ReceiptGeneratorTest` measures the
  page box.
- **TRAP — the entry file must be called `index.html`**, or Gotenberg answers 400
  without saying why. And a `DataPart` inside a `body` array is *not* multipart; it
  needs a `FormDataPart`, or Gotenberg answers 415.
- The layer is **two pages**, because the form is: the organisation block is on page 1,
  the donor, the amount and the boxes on page 2. qpdf maps overlay page *n* onto form
  page *n*, so a one-page layer silently loses half the receipt.

Coordinates live in `App\Receipt\CerfaLayout`, measured with `pdftotext -bbox` and
converted from points. A revision of the form moves all of them — see
`resources/cerfa/README.md`, and **look at the result**, because a test can prove a value
is present but not that it landed on the right line.

`App\Receipt\AmountInWords` is the only place that touches `NumberFormatter`, with one
documented suppression: PHPStan resolves it to Symfony's polyfill and forbids it, while
ext-intl is what actually runs — and the polyfill has no `SPELLOUT` at all. ICU is worth
that friction, because French is where hand-rolling breaks: *quatre-vingts* but
*quatre-vingt-un*, *soixante et onze* but *soixante-douze*, and *zéro euro* singular.

### Testing it

`ReceiptGeneratorTest` and `IssueReceiptTest` hit **real Gotenberg and real s3mock** —
mocking them would prove none of the seams above. Both need `make up`.

**TRAP in mail assertions:** the mailer records a `MessageEvent` when a message is queued
*and* again when it is sent, so `getEvents()->getMessages()` returns the same mail twice.
Filter on `!$event->isQueued()` — an `assertCount(1)` on the messages fails against
perfectly correct behaviour.

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

App served at `https://localhost`. `composer reset` (so `make reset`) leaves three
logins: **`admin@example.com` / `!ChangeMe!`** — a platform super-admin created by
the reset script itself — plus the fixture accounts
`super-admin@benevolio.test` and `admin@cvvfcm.test`, whose password is in
`App\Factory\UserFactory::DEFAULT_PASSWORD`. The association's public form is at
`/a/cvvfcm/declaration`.

**Password rules are deliberately weak**, and this applies in production too:
minimum `User::PASSWORD_MIN_LENGTH` (8) characters, and nothing else.
`Assert\NotCompromisedPassword` was removed — it called haveibeenpwned on every
write, which meant the account commands needed network egress and a well-known
development password could not be used. Re-adding it means re-adding
`not_compromised_password: false` for the test environment at the same time; see
`config/packages/validator.yaml`.

**Mail sent in development is caught by Mailpit at `http://localhost:8025`** —
nothing leaves the machine. It is declared in `compose.override.yaml`, which is
dev-only, so a deployment built from `compose.yaml` never ships a mail trap. Ports
are overridable with `MAILPIT_SMTP_PORT` / `MAILPIT_HTTP_PORT`.

## Deploying

Production is `https://benevolat.cvvfcm.fr`, on the shared OKE cluster, in the
`benevolio` namespace, alongside meteoprint. `.github/workflows/cd.yaml` builds and
deploys on every push to `main` — CI runs on the same push and **does not gate it**,
so a red merge still ships. The nets are the migration Job (`--all-or-nothing`) and
`helm upgrade --wait --rollback-on-failure`.

The image is **GHCR**, `ghcr.io/cvvfcm/benevolio/app`, public, authenticated with
the workflow's own `GITHUB_TOKEN`. Not Docker Hub: the name is nested and Docker
Hub only understands `namespace/repo`. Deploys pin `image.tag` to the commit sha,
so a rollback is `helm upgrade --set image.tag=<older sha>` and never waits on a
moving tag.

**Secrets come from two places, and the split matters.** Infrastructure ones are
**organization** secrets shared with the other repos — `OCI_*`, `OKE_KUBECONFIG`,
`CLOUDFLARE_API_TOKEN`. Application ones belong to this repo's **`prod`
environment** — `APP_SECRET`, `DATABASE_URL`, `MAILER_DSN`. Never move an
application secret up to the organization: it would hand every other repo the keys
to this database.

**The database is external, and the chart cannot run its own.** It used to bundle
the Bitnami PostgreSQL subchart; that is gone, along with `helm dependency update`
and the `benevolio-pg-auth` Secret CD used to create. The entire connection is one
`DATABASE_URL` DSN from the `prod` environment. There is deliberately no second
code path to keep working.

**Both DSNs are shape-checked at render time, not merely required.** Being
non-empty was not enough: `MAILER_DSN` was once set to the whole environment line,
`MAILER_DSN=brevo+api://…`, which passed `required`, deployed green, satisfied
every probe, and then threw *"The mailer DSN must contain a scheme"* on the
volunteer's first page load — a 500 on the only page the public uses, while
`/admin` and `/health` stayed fine. `templates/secret.yaml` now refuses a
`mailerDsn` without a scheme and a `database.url` that is not `postgresql://`, and
names the offending prefix. Keep that guard on any DSN added later.

**`secrets.mailerDsn` is `required` in the chart, on purpose.** Since a declaration
is only final once the volunteer opens an emailed link, an instance that cannot
send mail is an instance in which nothing can be declared. Symfony's default
`null://null` would swallow every message without an error, so the chart refuses to
render instead of letting the failure be silent.

**`MAIL_FROM` is the platform's own address**, `benevolat@cvvfcm.fr` by default,
configured deployment-wide (`app.mailFrom`, `MAIL_FROM` in `.env`) and **not** per
association. `DeclarationConfirmationMailer` puts the association's name in the
display name and its address nowhere: the platform sends on their behalf, and a
spoofed sender domain lands the mail in spam. It is a literal, deliberately not
derived from `host` — this address has to be a *verified sender* at the relay, and
one the chart invented for itself would not be, so the mail would be accepted here
and dropped there. The template refuses an empty value and refuses a
`Name <addr>` form.

**Kubernetes probes `/health`** (`App\Controller\HealthController`), and it is the
only route outside a tenant or the backoffice. There is nothing else safe to probe:
`/` is not routed at all, so the chart's original probe on it 404'd forever and the
pod never turned ready. The endpoint **does not touch the database** — the same URL
backs the liveness probe, and failing it on a database blip would restart every pod
over something the application does not control. Do not "improve" it into a tenant
URL either: `/a/<slug>/declaration` cannot answer before an organization exists (so
a first deployment could never go ready), it pins one association into a chart
meant for many, and on liveness, unticking that association's *active* box would
take production down.

### Bootstrapping a fresh instance

A new database has no association and no account, so `/admin` cannot be logged into
and `/a/<slug>/declaration` 404s. Fixtures are not an option — they are dev-only and
carry demo data. Two commands in `src/Command/`, run once:

```bash
kubectl -n benevolio exec -it deploy/benevolio-web -- \
  php bin/console app:organization:create "Nom complet de l'association" son-slug
kubectl -n benevolio exec -it deploy/benevolio-web -- \
  php bin/console app:user:create vous@exemple.org --role=super-admin
# an association's own admin instead:
#   … app:user:create tresorier@exemple.org --organization=son-slug
```

`app:organization:create` is the **third** creation path for an `Organization`, so
it calls `DefaultTasks::createFor()` explicitly — see the warning under *Tasks are
rows, not code*. `app:user:create` reads the password from a hidden prompt, or from
`--password` for scripts that cannot answer one — `composer reset` uses it to seed
the development account. **`--password` puts a live credential in shell history
and in `ps`**, so it is for throwaway accounts; use the prompt for anything real.

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
