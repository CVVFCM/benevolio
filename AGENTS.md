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

**Bénévolat credits 875, not 870.** Règlement ANC 2018-06 swapped two numbers from the
old 99-01, where 870 was *Bénévolat* and 875 *Dons en nature*. Anything citing 864/870
for volunteer hours is quoting the superseded text. The codes live in
`App\Accounting\PcgAccount`, so no account number is written as a bare string.

The receipt is **CERFA n°11580\*05**, form **2041-RD**. Numbering is continuous and
never reused — see *The CERFA*.

Two consequences that are easy to get wrong:

- Valuing volunteer hours and issuing a tax receipt are **not** the same
  pipeline. Do not let a valued hour reach a receipt.
- **There is no separate volunteer mileage scale any more.** Art. 21 of loi
  n° 2022-1157 amended CGI art. 200, 1 ter so that from revenus 2022 a volunteer uses
  the **general salaried barème** (CGI ann. IV art. 6 B, *arrêté du 27 mars 2023*)
  — by puissance fiscale **and** distance band. The old flat 0,324 €/km bénévole
  rate is abolished.
  **BOFiP BOI-IR-RICI-250-20 is stale**: still the 12/09/2012 version, still printing
  the old flat rate. Cite it for the conditions (§170), the justificatifs (§210) and
  the renonciation wording (§240) — never for figures.
- The barème is **piecewise**, and this application models **only the first band, with no
  cumulative tracking at all** — deliberately, and it must not be added back. Bands above
  5 000 km use another formula keyed to the volunteer's cumulative kilometres **across
  every association they drive for**, which nothing here can see: one association's
  records are not the volunteer's total. A flag computed from local kilometres would claim
  knowledge that does not exist. The band-1 rate is applied to what is declared, and the
  volunteer's own situation is theirs to settle.
- **An electric vehicle's allowance is « majorée de 20 % »**, on every bracket — the
  barème says so since revenus 2021, with no exception for small powers (the 1 CV
  exclusion belonged to the abolished bénévole scale). The percentage is held beside each
  rate, on the exercice and on each bracket (`defaultElectricBonusPercent`,
  `FiscalYearMileageRate::$electricBonusPercent`, 20 by default), and travels with it in
  `MileageRate`. It is applied to the **amount, in the same single division**: 0,529 × 1,2
  is 0,6348 €/km, which no millième holds.

**Volunteers are not `User`s.** They may declare anonymously, identifying themselves on
the first step of the public form under `/a/{organizationSlug}/declaration`, or log in to
their association's volunteer space with a mailed link — see *Volunteer accounts*. Only
back-office staff have passwords.

### The declaration model

A **`Declaration`** is one submission of that form: a `Person`, a set of
`DeclarationAction` lines, and the two legal statements. **Both statements are
mandatory**, so every declaration carries the waiver — which is what makes the
declared expenses a donation at all.

A single `DeclarationAction` carries *both* kinds of contribution, accounted for
differently, so never sum them together:

| Field | Meaning |
|---|---|
| `workHours` | `DECIMAL(5,2)`, in hours. Donated time → 864/875, never receiptable. Read as `App\ValueObject\WorkHours` (`getHours()`, `Declaration::getTotalHours()`), in exact integer hundredths, not as floats; ext-bcmath is not installed. |
| `distanceKm` | Kilometres of **one journey, one way**. |
| `journeys` | Number of **one-way** journeys — a return trip is two. Total distance is `distanceKm × journeys`. |
| `fiscalPower` | An enum of the *barème* brackets (≤3 CV, 4, 5, 6, ≥7 CV), because the scale distinguishes only those. Required exactly when `ownVehicle` is true. **No euro rates anywhere**: the scale is republished yearly and belongs with valuation, keyed by financial year. |
| `electricVehicle` | The volunteer's own vehicle is electric, and the barème is « majoré » — see below. Forced false without `ownVehicle`, as `fiscalPower` is forced null. |
| `consecutiveDays` | The action may span several days from `date`. The action must be **over**: `DeclarationAction::endDateFor()` is the shared arithmetic, applied on the DTO *and* the entity, and it normalises to midnight — an end date of "today at 17:30" would otherwise read as later than "today". |
| `task` | The *tâche effectuée* — a per-association **entity**, not an enum. Its hourly rate lives on the exercice, not on it. See below. |
| `activity` / `title` | The occasion: one of the association's events or actions (`App\Entity\Activity`), **or** — « Autre » — a free-text title. Exactly one is set; print `getLabel()`. See *Activities*. |

**Tasks are rows, not code.** `Task` is `TenantAware`; each association
manages its own list in `/admin`. `App\Organization\DefaultTasks` seeds five
starters (*Travaux, Régate, Encadrement, Arbitrage, Autre*) and is called
**explicitly** from the **three** places an organization is born — the platform CRUD,
`app:organization:create` and `OrganizationFactory`. A `postPersist` listener would be
one place instead of three, but persisting from inside `postPersist` needs a second
flush and is fragile; the cost is that a fourth creation path would silently skip
seeding, which `DefaultTasksTest` exists to catch.

A task is a kind of *work*, not a kind of gathering: the occasion is the line's activity
or, failing one, its own free-text title — see *Activities*.

Deleting a task an action references is refused by the FK: a filed declaration must
not lose the label it was filed under. Retire one with `active` instead — it
vanishes from new forms and still renders on old actions.

### Activities — the occasion, from the association's own list (#44)

`App\Entity\Activity` is an event or an action of the association (`ActivityType`), with an
optional begin and end date, managed from « Événements et actions » in `/admin`. `TenantAware`,
built like `Task`, and retired the same way: `active`, and a delete the FK refuses once a line
uses it. It carries no rate — a rate belongs to the kind of work (`Task`), not to where it
was done.

- **A line is named by an activity or by a title, never both.** `DeclarationAction`'s
  constructor drops a title given beside an activity and refuses a line with neither, and
  `title` is null on a linked line. **Everything prints `getLabel()`** — never `getTitle()`,
  which is empty for half the lines.
- **The name is read live**, by decision: renaming an activity renames it on the lines
  already filed. Nothing is copied onto the line.
- **Unique by (name, begin date)** per association: a yearly event repeats its name, two
  undated ones may not. Two partial unique indexes, because a plain one treats NULL dates
  as distinct; `UniqueEntity(ignoreNull: false)` says it in French first.
- **Dates: none, a begin alone, or both.** An end without a begin is refused.
- **The public form offers the active ones that have begun**
  (`ActivityRepository::offeredQueryBuilder()`): a line must be over to be declared, so an
  event still to come has nothing to declare against. Old ones stay until unticked. The dates
  decide nothing else — a line's own date is not checked against them.
- The select groups « Événements » then « Actions », dated ones most recent first, each
  written with its dates (`App\Form\Declaration\ActivityChoiceLabel`). Its empty choice is
  « Autre », which requires the title (`ActionDraft::validateIsNamed()`).
  `activity_title_controller.js` hides the title while an activity is chosen; without
  JavaScript it stays, and what is typed is dropped. An association with no activity to
  offer renders no select at all.
- **`Activity::$type` is left uninitialised on purpose** — no default, so an admin chooses;
  the getter answers null until then. Hence the one `property.uninitialized` suppression.
- Nothing is seeded for a new association, and lines filed before #44 keep their title.

**Defaults (#46).** An activity may carry `defaultDays` and `defaultWorkHours` — what it usually
asks of a volunteer, the hours being the total over those days. Both optional, independent of
its dates, and held to a line's own limits. They are a **browser convenience and nothing
else**: the select's options carry `data-days` / `data-hours`, and
`activity_defaults_controller.js` writes the days, the hours and **two journeys a day** into
the line. It fills on the select's **change, never on connect** — a draft restored from the
session, or a step redisplayed with errors, holds what the volunteer typed — and a choice
then **overwrites** the three fields, by decision. « Autre » and an activity with no figure
leave them alone. The server fills nothing in: `DeclarationSubmitter` never reads them.

**Summary (#47).** `App\Accounting\ActivitySummaryBuilder` adds up, per activity, the
**validated** lines given for it — hours, **valued** kilometres (own vehicle, so they reconcile
with the amount), the number of volunteers, and the **two amounts side by side, never a
total** (`ActivitySummary` has no sum, like `ContributionValuation`). It covers **all time**:
an activity has no exercice, and each line is priced by the exercice of its own date. A line
no exercice covers counts its hours, kilometres and volunteer and adds no amount. The list of
activities shows the two amounts and the volunteers, the detail page all five.

- These columns are not properties of `Activity`. Each is a virtual field **naming its own
  template** (`admin/field/computed.html.twig`), or EasyAdmin prints « Inaccessible ».
- **The summaries are memoised on the request, not on the controller.** A CRUD controller is
  a service and outlives the request in worker mode; a property there would show one
  association's figures to the next.

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

**The domain hands these out as value objects**, in `src/ValueObject/`: `Money` (cents),
`HourlyRate` (cents per hour), `MileageRate` (millièmes per km), `WorkHours` (hundredths).
Each holds one integer and writes itself the French way — comma, narrow no-break space
between thousands, no-break space before the unit: « 1 234,56 € », « 0,636 €/km »,
« 7,50 h ». **Nothing else formats a figure**: templates use the `euros` and `hours`
filters, PHP calls `format()`, and the CERFA box uses `Money::formatBare()` (« 1234,56 »,
the form prints « euros » itself). The database columns and the valuator's arithmetic stay
on plain integers; the int getters forms bind to (`getHourlyRateCents()`,
`getMilliEurosPerKm()`, `getAmountCents()` for `MoneyField`) stay beside the typed ones.

### Rates live on the exercice, never on the Task or the Organization

`App\Entity\FiscalYear` owns them, because they change: the barème is republished by
the state and an association revisits what an hour is worth. A rate on the `Task` would
silently rewrite years already closed.

Each rate is a **default plus optional per-task or per-bracket overrides**, held as rows
(`FiscalYearTaskRate`, `FiscalYearMileageRate`) rather than columns, so the override set
stays sparse and adding a `FiscalPower` bracket needs no migration.
`FiscalYear::hourlyRateFor()` and `::mileageRateFor()` are **the only places
the fallback lives** — reaching for the raw override gets the default when one exists,
which is a wrong figure rather than a missing one.

**Overrides are entered on the exercice's own form**, as editable `CollectionField`s
(`App\Form\FiscalYearTaskRateType`, `FiscalYearMileageRateType`). There is deliberately no
CRUD for either: a rate means nothing apart from the exercice it belongs to. Two things that
are not obvious:

- Both rate entities **require their exercice in the constructor**, which a CollectionType
  cannot supply — so each entry type takes a `fiscal_year` option and builds the entity in
  `empty_data`. The controller reads that off the `AdminContext`, or makes a fresh one on the
  "new exercice" page.
- The task list is **scoped explicitly**, not left to `OrganizationFilter`. `EntityType` builds
  its own query, and `FiscalYearTaskRate` is not `TenantAware`; an unscoped list would offer
  another association's tasks as choices, and picking one would file a rate against a foreign
  row.
- Both entities have a `__toString()`, and it is load-bearing: EasyAdmin labels each collection
  row with it, and without one every existing rate reads as
  `App\Entity\FiscalYearMileageRate #019fb875-…` on the form.

Editing a rate on an exercice already in use is **allowed** — correcting a mistyped rate is the
common case. `Crud::PAGE_EDIT`'s help says what it changes: the écritures and the displayed
amounts are recomputed, and **receipts already issued are not**, because their amount is frozen
in the database at issuance. Regenerating the year from « Reçus fiscaux » is what re-aligns them.

**Two exercices of one association must not overlap**, or a contribution would fall in
both and be counted twice. That needs a repository lookup, so it is
`App\Validator\FiscalYearDoesNotOverlapValidator` and not an `Assert\Callback` — an
entity must not reach for a repository. Adjacent years are fine.

A contribution belongs to an exercice by its **own start date**
(`FiscalYear::contains()`; the repository's SQL mirrors it), so a line spanning a year
boundary belongs wholly to the year it began in. A line no exercice covers is stored and
listed but **unvalued**: without a barème for the period there is no figure to state.

### Two accounting pages, and what each is for

Both list **validated lines only** — an unruled line is not bookable.

`/admin/fiscal-year/{id}/ledger` is the **écriture the association books**:
`App\Accounting\LedgerSummary`, one centralising entry per family, dated on the exercice's
**close** (the movements happened all year; the écriture recording them is passed at
closing), plus a balance per compte. This is what a treasurer copies into a journal, and
it is what they asked for — the per-volunteer detail is not what goes into the accounts.

`…/ledger/detail` is that detail, grouped by volunteer — what justifies an individual reçu
fiscal. **It is not the receipt's own period**: a receipt covers a civil year and this covers
the exercice, so the two never share a query. See `App\Receipt\YearlyReceiptRun`.

The summary shows the two families as **two tables, never one**, so nothing invites a
combined total — the figure `Ledger` and `LedgerSummary` both exist to prevent. Its
kilometre count is the **valued** kilometres only: a journey as a passenger is declared but
waives nothing, and counting it would leave a reader unable to reconcile the kilometres
against the amount beside them.

`LedgerSummary::balances()` returns `list<AccountBalance>`, not an array keyed by account
number: **PCG codes are numeric strings and PHP turns the key `'864'` into the integer
864**, so a keyed array of them cannot be typed honestly at PHPStan max, let alone read back
by code that thinks in strings.

### What a volunteer types stays on the declaration until it is confirmed

The public form is anonymous and recognises a returning volunteer by **email alone**. If a
submission overwrote that volunteer's name and address at once, anyone who knew their
email could rewrite the identity printed on their next CERFA without clicking anything.

So a `Declaration` carries the name and address **as typed**
(`submittedFirstName`, `submittedLastName`, embedded `submittedAddress`).
`DeclarationSubmitter` never touches an existing `Person`;
`App\State\Listener\ApplySubmittedIdentityOnConfirmation` copies the typed identity to the
`Person` on the `confirm` transition — so the volunteer's click and the manual
confirmation both apply it, and nothing else does.

**The click is a button, not the link.** Opening the emailed address shows the name and
address the declaration states — with a warning when they differ from the record — and one
button; see *Double opt-in*. A link that confirmed on a GET let a mail scanner apply an
identity typed by anyone who knew the volunteer's email. The mail greets nobody by name for
the same reason: it would vouch for a declaration its reader may not have made.

- The columns are **never null**: the constructor starts them from the `Person`, and
  `declareIdentity()` overrides them. Nullable "pending" columns cannot work — Doctrine
  hydrates an embeddable without its constructor, and `Address` has no nullable property.
- `/admin` shows « Coordonnées saisies » on the declaration, with a warning while they
  differ from the record (`Declaration::hasPendingIdentityChange()`): confirming by hand
  is what applies them, so the admin must see what they are vouching for.
- A **new** email still creates its `Person` at submission, with what was typed. There is
  no earlier record to protect, and `Declaration::$person` is not nullable.

### Double opt-in — a declaration is not final when it is submitted

Submitting stores the declaration in **`awaiting_confirmation`** and emails the
volunteer a link. Until they open it, the association cannot act on it. The click
also proves the address works, which is what a CERFA receipt will have to be sent
to.

**Except for a logged-in volunteer**: their login link already proved the address, so
`DeclarationSubmitter::submitAs()` files the declaration under their `Person` and confirms
it at once through `DeclarationConfirmer::confirmBySubmission()` — same `confirm`
transition, so the lines cascade and the typed identity is applied — and sends no mail.

- **The emailed URL confirms nothing; a button does** — as for both login links, and for
  the same reason: mail scanners fetch every URL in a message before its reader does.
  `DeclarationConfirmController` answers the GET with `DeclarationConfirmer::look()`, which
  changes nothing, and a page carrying one button, « Confirmer ma déclaration », posting to
  the same address. The POST calls `confirm()` and redirects back to the GET, which then
  reads « confirmée ». No CSRF token: the address is the credential. Both answers send
  `Referrer-Policy: no-referrer` and `Cache-Control: no-store`.
- The token lives on `Declaration` (`confirmationToken`, its expiry, `confirmedAt`),
  is valid **7 days**, and is **kept after use** rather than cleared — it can only
  ever cause one confirmation, and keeping it is what lets a second visit land on a
  success page instead of a 404.
- Stored unhashed, deliberately: hashing needs a separate plaintext selector to
  stay findable, and the threat it defends against already has the declarations.
- `DeclarationConfirmer` returns one of three outcomes plus "unknown", each with
  its own page — and `look()` a fourth answer, « still to confirm », which is the
  button page. **A repeat click is a success, not an error.**
- `Declaration` is `TenantAware`, so a token cannot be redeemed through another
  association's URL — the filter refuses to find it. Tested.
- There is **no resend** yet. A mistyped address means refilling the form.
- **An admin can confirm in the volunteer's stead** — « Confirmer à la place du
  bénévole », detail page only, for a mail that never arrived. Not a normal path, and
  the modal says so: it skips what the click proves. So it records **who**
  (`Declaration::$confirmedBy`, null = the volunteer clicked) and the detail page shows
  it. `DeclarationConfirmer::confirmManually()` uses the same `confirm` transition, so
  the lines cascade as usual; it **ignores token expiry**, since an expired link is
  the usual way the problem gets noticed. It is a **POST with a token**, like the
  verdicts (see *Every button that changes something carries a token*): this must not be
  reachable from a pasted or prefetched link. The modal body is set
  through `data-action-confirmation-content`, which EasyAdmin's script reads for any
  action although only DELETE sets it natively.

### The two state machines

`Declaration` and each `DeclarationAction` run the same machine, separately:

    awaiting_confirmation --confirm--> submitted --validate--> validated
                                                 --refuse----> refused

Both start unconfirmed: a line is not *soumise* before the volunteer has clicked
anything. `App\State\Listener\DeclarationConfirmationCascade` listens for the
declaration's `confirm` and moves every line with it. `validate` and `refuse` name
`submitted` as their only source, so an unconfirmed declaration is undecidable without
any guard.

`isDecided()` means validated-or-refused. Awaiting confirmation is *undecided but not
actionable*, so anything asking "can a verdict be applied" must check
`isAwaitingConfirmation()` too — `DeclarationDecider` does.

Nothing structural stops the two machines disagreeing, so
`App\State\Listener\DeclarationTransitionGuard` blocks the whole-declaration verdict
until every line already agrees with it. **Known consequence, accepted:** a genuinely
mixed basket — one line validated, one refused — has no terminal state and stays
*soumise*. If that becomes a problem the fix is a `partially_validated` state or a derived
status, **not** weakening the guard. `{refused, submitted}` is *not* mixed: refusing the
rest is still a coherent verdict, and `DeclarationDecider::refuseAll()` does it.

`DeclarationDecider` is the only thing that applies a bulk verdict. It refuses an
impossible one **up front** rather than discovering it mid-loop: a transaction would roll
the database back, but the in-memory entities would already be mutated.

Both enums share their behaviour through **`App\State\DeclarationLifecycle`**:
`isAwaitingConfirmation()`, `isDecided()`, `label()`, `badgeStyle()`, and the two maps
EasyAdmin wants, `choices()` and `badgeStyles()`, built from `::cases()`. Each enum keeps
only its cases and its transitions. A state added to one must be added to the other, or
the trait's `match` arms stop matching.

**TRAP when adding a state:** `isDecided()` must stay *validated-or-refused*, spelled
out. `SUBMITTED !== $this` would call an unconfirmed line decided the moment a state
exists *before* SUBMITTED. `tests/State/DeclarationLifecycleTest` pins both enums.

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

`base.html.twig` carries **the only inline script in the project** (with the CSP nonce —
see *Security headers*), and it has to be inline and blocking: the choice lives in `localStorage`, so only JavaScript knows it,
and anything deferred runs after first paint — which is a white flash on every page
for anyone who chose dark. `assets/controllers/theme_controller.js` handles the
clicks; `auto` **removes** the key rather than storing the word, so the OS keeps
answering. The storage key `benevolio-theme` appears in both and must match.

The switcher ships `hidden` and its controller reveals it, so it never shows where it
would not work. It covers **the public pages and login only** — EasyAdmin has its own
switcher inside `/admin` and `/platform`.

## Guided tours (#50, #51)

A first visit to the declaration form, to « Mes déclarations » or to the `/admin` dashboard
opens a short tour: **driver.js** (importmap, vendored, served from `'self'`) highlights one
element at a time with a text beside it. `assets/controllers/tour_controller.js` holds the
mechanics; **the steps and their French texts are in Twig**, one partial per tour
(`templates/public/tour/`, `templates/admin/tour/`), rendered as the controller's JSON value.

- **One tour per page**: each form step (`declaration-person`, `-actions`, `-legal`),
  `volunteer-declarations`, and `admin`. A public page sets `tour` (the partial) and
  `tour_autostart` at its top; `public/layout.html.twig` then puts the controller on the
  whole page and adds « Aide » to the header, which replays it.
- **Steps point at `data-tour-step="…"` hooks**, not at styling classes, so a CSS refactor
  does not silently empty a tour. The admin menu entries carry `tour-*` classes instead,
  because `UrlMenuItem` has no `setHtmlAttribute()`. Moving or renaming a hooked element
  means updating its tour: `TourTest` and `DashboardTourTest` fail when a step points at
  nothing — apart from the steps a page legitimately lacks (« Se connecter » when logged in,
  the activity select with no activity), which the controller leaves out before counting.
- **TRAP — driver.js renders a step's title and description as HTML.** Authored strings
  only; nothing a person typed, not even an association's name.
- **Seen once a year, per browser**: `localStorage` key `benevolio-tour:<id>`, holding the
  date it opened (closing it early counts as seen). No storage, no auto-open — it would
  otherwise open on every page view. Nothing is stored server-side, by decision.
- **When it does not open by itself**: a form step redisplayed with its errors (the error
  summary comes first), the form for a logged-in volunteer (« Mes déclarations » still opens:
  only logged-in volunteers see it), any screen under 40rem, and the admin dashboard for a
  super-admin impersonating. The admin tour opens on the dashboard only, never on a CRUD page.
- **Its stylesheet is listed in `importmap.php` and linked, never imported** from JavaScript
  (see *Security headers*). Listed so that AssetMapper downloads it into `assets/vendor/`
  with the script — and so every page's import map carries an **inert** entry for it, a
  `data:application/javascript` URL nothing imports. `ContentSecurityPolicyTest` allows
  exactly that entry (`LINKED_NOT_IMPORTED`) and still refuses any other `data:` module; a
  new linked-only stylesheet goes in that list. It is linked in the public layout before
  `app.css`, and in `/admin` through `configureAssets()` before `admin.css`. Both files restyle `.tour` (the `popoverClass`) with their own colours, so the
  popover follows each theme.
- **`/admin` has its own entrypoint, `admin`** (`assets/admin.js`), registering the tour
  controller only. `app.js` starts every controller in `assets/controllers/`, which EasyAdmin
  pages have no use for.
- « Aide » and « Revoir la visite guidée » ship `hidden` and the controller reveals them, as
  the theme switcher does. **`.button[hidden]`** is restated in `app.css`: `.button`'s
  `display` would otherwise outrank the browser's own `[hidden]` rule.

## The action navigator (#54)

The actions step lists every line by number and name, each a link to its panel
(`templates/public/declaration/_action_nav.html.twig`, `assets/controllers/action_nav_controller.js`):
a column beside the form from 72rem, where the step's `<main>` widens (`main_class` in
`public/layout.html.twig`), and a bar stuck to the top of the form below that, « Action 3 sur 12 »,
opening the list. One `<details>`, kept open on a wide screen by the controller.

- **Rendered by the server, kept live by the browser.** Without JavaScript the links work and the
  bar reads « 12 actions »; with it the list follows lines added, removed and renamed, and marks
  the one in view (`aria-current="location"`). The entry markup is a `<template>` rendered from the
  same macro as the list, so the two cannot drift.
- **A line's name**: the chosen event or action, else its title, else its task, else « Sans titre »
  — the same order in Twig and in the controller.
- **A refused line is the server's verdict**, marked until the next submit: `data-invalid` on its
  fieldset, from `_action_errors.html.twig`. **Not `vars.valid`**: a line removed in the browser
  leaves a gap in the indexes, the collection renumbers the rest, and their errors land on forms it
  never submitted — which read as valid. `DeclarationActionNavTest` posts such a gap.
- Each panel's `id` is its form id; the prototype's carries `__name__`, replaced on clone.
- The design was shaped with Impeccable; `PRODUCT.md` is its record of the product, and the
  public look is the incumbent one in `app.css`.

## Security headers, and the script policy

Two layers, because two things can answer a request:

- **Caddy, on every response** (`.infra/docker/php/Caddyfile`): `X-Content-Type-Options`,
  `Referrer-Policy`, and `Content-Security-Policy: frame-ancestors 'none'` as a **default**
  (`?`), so the back offices cannot be framed. **HSTS is not in the Caddyfile**: the same
  file serves `https://localhost`, where it would pin every local project to HTTPS for a
  year. The chart adds it (`CADDY_SERVER_EXTRA_DIRECTIVES` in the ConfigMap).
- **Symfony, on every page outside `/admin` and `/platform`**
  (`App\Security\ContentSecurityPolicyListener`): `script-src 'self' 'nonce-…'`, plus
  `object-src`, `base-uri` and `frame-ancestors`. EasyAdmin's pages are full of inline
  scripts and get no script policy.

What that asks of a public template:

- **Every `<script>` carries `nonce="{{ csp_nonce() }}"`**, the import map included
  (`importmap('app', {nonce: csp_nonce()})`). One without it does not run, and only the
  browser console says so. `ContentSecurityPolicyTest` checks every script on four pages.
- **Never `import` a stylesheet from JavaScript.** AssetMapper maps it to a
  `data:application/javascript,` module, which the policy refuses — and allowing `data:`
  scripts would allow any injected `<script src="data:…">`. `app.css` is a `<link>` in
  `base.html.twig`.
- The nonce is per request and lives in `App\Security\ContentSecurityPolicy`, tagged
  `kernel.reset` for the same worker-mode reason as `TenantContext`.
- The listener reads no token, so the home page keeps its public cache.

In development the web profiler appends its own nonce and `'unsafe-inline'` to the header;
browsers ignore `'unsafe-inline'` once a nonce is present.

### Deferred — do not assume these exist

Not built yet, by explicit decision: exporting the écritures to accounting software,
resending a confirmation link, purging declarations never confirmed, transferring
ownership of an association, and changing an account's email. When you add one, update this file.

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
- **driver.js** (importmap), for the guided tours — see *Guided tours*.
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

**The account is never a fallback for an address.** `UserTenantResolver` returns nothing
for a request whose route carries `organizationSlug`: when that slug is unknown or the
association inactive, the answer is "no tenant" and the page is a 404 — otherwise a
logged-in admin opening a mistyped public address would get their own association's form
and file the declaration there. A resolver cannot claim a request
and answer "none" — the next one is tried regardless — so any future strategy that must
not stand in for another has to say so itself, the same way.

### Non-negotiables

- **Every new business entity uses `TenantAwareTrait` and ships an isolation
  test.** `tests/Tenant/OrganizationFilterTest.php` is the pattern. This is the
  one test that must never be skipped.
- **`Organization` and `User` are not `TenantAware`.** `Organization` *is* the
  tenant; filtering `User` would break authentication, because the user provider
  runs before any tenant is known.
- **`MyOrganizationCrudController` is on a non-`TenantAware` entity**, so no filter
  protects it: another association's UUID in the URL would be editable. It checks the
  instance against `TenantContext` on `detail()`, on `edit()` *and* in `updateEntity()`;
  `tests/Controller/Admin/MyOrganizationTest` covers the first two, and the third is the
  same check once more before writing. Anything similar must do the same.
- **`DeclarationAction` is not `TenantAware`**, by decision: it is reached through its
  `Declaration`, which is. (`FiscalYearTaskRate` and `FiscalYearMileageRate` are the same
  case, reached through their exercice and edited only on its form.) The filter therefore
  does *not* touch a line, and anything querying it directly must scope itself. Three
  places do, covered by `tests/Controller/Admin/DeclarationActionIsolationTest`:
  `createIndexQueryBuilder()` joins the declaration (index, and the autocomplete that
  shares it), `Crud::setEntityPermission()` runs `DeclarationActionVoter` (detail), and
  the custom transition actions check for themselves — because
  **`setEntityPermission()` does not cover custom CRUD actions**: EasyAdmin marks
  the entity inaccessible and hands the action a `null` instance instead of
  refusing the request. If you ever give this entity an `organization` FK, delete
  all three and the voter with them.
- **`User` in `/admin` is scoped the same way, by hand.** It cannot be
  `TenantAware` (see above), so `App\Controller\Admin\UserCrudController` does what
  `DeclarationActionCrudController` does: `createIndexQueryBuilder()` filters on the
  current organization (index and autocomplete), `setEntityPermission()` runs
  `UserVoter::VIEW` (detail, delete), and the custom « Renvoyer l'invitation » action
  and `deleteEntity()` check the voter themselves. `UserVoter::VIEW` also answers
  **yes to a `null` subject**, because EasyAdmin checks the entity permission on the
  « new » page before any instance exists. Covered by
  `tests/Controller/Admin/UserIsolationTest`.
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
is attached to no organization, so nothing arms it. Keep it that way: **an account is
one or the other, never both** — a super-admin given an `Organization` would scope the
whole platform to it. `app:user:create` refuses that combination.

EasyAdmin registers **every** CRUD controller under **every** dashboard by
default. The `deniedControllers` list on `App\Controller\Admin\DashboardController`
is what keeps the platform CRUDs off `/admin`; there is a test for it. Any CRUD
added to `/admin` must be tenant-scoped. `/platform` uses `allowedControllers`, a
whitelist, so a new `/admin` CRUD does not appear there by accident.

An association edits its own record — CERFA identity and signature — from
« Mon association » in `/admin`; the super-admin still edits everything from
`/platform`. The slug is read-only on the association's side: it addresses the public
volunteer URLs, and changing it invalidates links already handed out.

### Back-office accounts — owner, invitations, login links

An association manages its own admins from « Administrateurs » in `/admin`
(`App\Controller\Admin\UserCrudController`). **Nobody types a password for someone
else**, anywhere: creating an account takes an email address, and its holder is mailed
a link to choose their own. `/platform` works the same way; only `app:user:create`
still takes a password, because bootstrapping and `composer reset` cannot wait on mail.

- **Owner.** The first account of an association is its owner: `Role::OWNER`, with
  `ROLE_OWNER: [ROLE_ADMIN]` in the hierarchy. An owner holds **both** stored roles.
  `App\User\OwnerAssigner::assignIfFirst()` grants it, and is called **explicitly** from
  the three creation paths (both user CRUDs and the command), not from a Doctrine
  listener — the `DefaultTasks` reasoning. A fourth creation path must call it too.
  `App\Validator\OneOwnerPerOrganization` refuses a second; `/platform`'s roles
  checkbox offers « Responsable » so a super-admin can repair ownership by hand.
- **Owner and admin have the same rights except one: deleting.** `UserVoter::DELETE`
  lets only the owner delete, never the owner account itself, and nobody their own.
  There is no edit (the email is the login) and no batch delete (a second road the
  rules would have to be enforced on twice).
- **`User::$password` is nullable.** Null is « Invitation en attente »: the account
  exists and cannot log in with a password. « Renvoyer l'invitation » is offered only
  in that state — an active account uses « Mot de passe oublié ».
- **The link is Symfony's `login_link`, not symfonycasts/reset-password-bundle**, which
  has no tagged release supporting Symfony 8 (only `dev-main`). Swap if one appears.
  `App\User\LoginLinkMailer` sends both mails: **invitation 7 days, forgotten
  password 1 hour**, per link via `createLoginLink($user, null, $lifetime)`. Both are
  single-use — see below.
- **It injects `security.authenticator.login_link_handler.main` by id.** The
  autowired `LoginLinkHandlerInterface` is firewall-aware: it picks the firewall from
  the current request and throws without one — so in a test, or any CLI path.
- **A link works once.** It is signed with `[id, email, password, loginLinkVersion]`, and
  `LoginLinkSuccessHandler` bumps `User::$loginLinkVersion` on every link login: the link
  just used, and any other still in a mailbox for that account, are dead. Setting a
  password kills them too. Not Symfony's `max_uses`, which needs a cache shared by every
  pod. Someone who uses their link and leaves without choosing a password asks for another
  through « Mot de passe oublié », which mails accounts that have none.
- **The emailed URL logs nobody in; a button does.** `login_link.check_post_only` makes the
  firewall accept the link on a POST only. `LoginLinkCheckController` answers the GET with
  one button, « Continuer », posting the link's own parameters back. Mail scanners fetch
  every URL in a message before its reader does, and would otherwise burn a single-use
  link. That page sends `Referrer-Policy: no-referrer`: its URL is the credential.
- **No « remember me » from a link.** Symfony's login-link authenticator attaches the
  remember-me badge to every login and enables it on a `_remember_me` parameter, query
  string included. `App\Security\NoRememberMeOnLoginLink` switches it off — a request
  carrying only that cookie has no session, so no « must choose a password » flag.
- **`User` is `EquatableInterface`, and compares the password itself.** Symfony's own
  check skips the password when the session's copy has none, i.e. for every session an
  invitation opened: two people on one invitation both stayed logged in after the first
  chose a password. `isEqualTo()` deliberately ignores `loginLinkVersion` — using a link
  bumps it, and comparing it would log out the session the link just opened.
- **A link logs in, then forces « Choisissez votre mot de passe ».**
  `App\Security\LoginLinkSuccessHandler` sets a session flag and redirects there;
  `App\Security\ChoosePasswordEnforcer` (kernel.request, priority 0, after the
  firewall) redirects every request back until the password is saved — for an account
  with no password, **or** with the flag (a forgotten-password link logs in an account
  that has one). Saving calls `Security::login($user, 'form_login')`, because the new
  hash would otherwise read as "user changed" and log them out on the next request.
- **TRAP — the enforcer returns before touching the token when there is no previous
  session.** Reading the token wakes the lazy firewall, which marks every response
  private; `/health` caught it. `App\Tenant\UserTenantResolver` runs on every request
  too and has the same guard (plus the remember-me cookie, which it must still honour).
  It lacked one until Symfony 8.1.11 started counting that read: the home page and
  `/health` went `private, must-revalidate` in production, and only `HomeTest` and
  `HealthTest` said so. **Anything running on every request must not read the token
  unconditionally.**
- « Mot de passe oublié » (`/mot-de-passe-oublie`) answers **the same page whatever
  the address**, so it does not reveal who has an account — including over its rate
  limit, which is counted before the account is looked up.

### Rate limits — keyed on the address, not the visitor

`config/packages/rate_limiter.yaml`:

- **`forgot_password`**: three reset mails per address per hour.
- **`declaration_recipient`**: five confirmation mails per address, per association, per
  day. The public form mails whatever address is typed; the sixth submission answers 429
  and files nothing.
- **`volunteer_link`**: three volunteer login links per address, per association, per
  hour, counted before the address is looked up. « Se connecter » answers the same page
  over the limit.
- A declaration holds at most `DeclarationDraft::MAX_ACTIONS` (50) lines.

**Per visitor too**, on top of per address: `forgot_password_ip` and `volunteer_link_ip`
(ten an hour) and `declaration_ip` (twenty anonymous declarations a day), each answered
like its per-address twin. And `/login` has Symfony's **`login_throttling`**: five failed
attempts a minute per account and visitor, twenty-five per visitor.

Per visitor is sound because **`Request::getClientIp()` is the real visitor in
production**, checked: the Gateway overwrites `X-Forwarded-For` with the client's address
(a forged one sent by the visitor is dropped), the pod sees the Gateway's private address,
and `SYMFONY_TRUSTED_PROXIES=private_ranges` trusts it. No Cloudflare address appears in
the chain. If a proxy is ever put in front that appends rather than overwrites, these limits
would throttle strangers together, or become spoofable — check again.

The counts live in the `cache.rate_limiter` pool, on disk, so they hold across requests —
and across tests. A test that submits either form uses
`App\Tests\Support\ResetsRateLimiters` in `setUp()`.

### Volunteer accounts — one association, a mailed link, no password

A volunteer logs in to ONE association's space, `/a/{organizationSlug}/…`: « Mon profil »,
« Mes déclarations », « Mes reçus ». The account is their `Person`, which implements
`UserInterface` (`ROLE_VOLUNTEER`) on its own firewall, `volunteer` (pattern `^/a/`).

- **Two firewalls, one browser.** `volunteer` sits before `main` with its own provider
  (`App\Security\VolunteerProvider`, by id, tenant filter suspended — it runs before the
  filter is armed, and in worker mode the EntityManager may still carry the previous
  request's), its own remember-me cookie `REMEMBERME_VOLUNTEER` on path `/a/` (signed with
  the email: a volunteer has no password), and a logout that leaves the back-office session
  alone. A treasurer can be both at once.
- **One login is one association.** The firewall covers every association's prefix, so a
  token for A is still present on B's pages. There it means nothing:
  **`App\Volunteer\CurrentVolunteer::get()`** returns the volunteer only when their `Person`
  belongs to the URL's association, and every controller and template asks it
  (`current_volunteer()` in Twig) — never `app.user` or the token. The token is not cleared
  on B's pages: that would log them out of A.
- **The link is our own, not `login_link`.** It must work for an address with no `Person`
  yet. `App\Volunteer\VolunteerLink` signs `/a/{slug}/connexion/lien?email=…&version=…`
  with `UriSigner`, for one hour. `version` is `Person::$loginLinkVersion` (0 without a
  Person); following a link bumps it — `App\Volunteer\VolunteerLogin` — so a link works once.
- **« Se connecter » mails any address**, the same page whatever happens: a first link is
  how an account begins. Following it from an unknown address stores the proven address in
  the session, and « Mon profil » asks for the name and address once and creates the
  `Person`. Afterwards the profile edits only the distance (one way, home → association)
  and the vehicle; the identity changes through a confirmed declaration, as for anyone.
- **The emailed URL logs nobody in; a button does**, as for back-office links, and the page
  sends `Referrer-Policy: no-referrer`. Login is `Security::login()` with
  `App\Security\VolunteerLinkAuthenticator`, which supports no request on its own.
- **Logged in, the email is the account's**: shown read-only on the form, and
  `DeclarationSubmitter::submitAs()` ignores what is typed.
- **Logged in, the form opens pre-filled** (#36): `DeclarationDraft::startingFrom()` puts
  the name and address on the first step, and `ActionDraft::startingFrom()` the profile's
  distance, fiscal power and electric flag on each line — the first one, and through the
  collection's `prototype_data` every line added. « Véhicule personnel » stays unticked:
  nothing is valued until the volunteer says they drove. Never from a typed email.
- **TRAP — FormFlow restores the draft over the data you pass.** An unfinished declaration
  lives in the session (`SessionDataStorage`) and replaces the pre-filled draft when the form
  is built; one started before logging in would open with nothing filled. So a
  `PRE_SET_DATA` listener in `DeclarationFlowType` fills the restored draft's **blanks**
  (`fillBlanksFrom()`), **once** — `DeclarationDraft::$prefilledFor` remembers it, or a line
  set to 0 km would be refilled with the profile distance on every step. The same listener
  re-reads each line's `Task`: the session copy is detached, and the task select refuses an
  unmanaged entity (going back to the actions step was a 500).
- Receipts are listed and downloaded only for their own `Person`; another volunteer's is a
  404. Tests: `tests/Controller/Volunteer/VolunteerAccountTest`.

### Impersonation — « Se connecter en tant que »

A platform super-admin can act as an association's account, from the « Administrateurs »
list in `/platform`. It is Symfony's `switch_user` on the main firewall, with three things
added, and each closes a hole the stock feature leaves open:

- **CSRF.** `switch_user` reads `_switch_user` from the query string, a POST body or a
  header, and checks no token: any page a super-admin opened could switch their session.
  `App\Security\ImpersonationListener` (on `SwitchUserEvent`) refuses anything but a POST
  carrying a token for `ImpersonationListener::CSRF_TOKEN_ID`, **in and out**. EasyAdmin's
  own « exit impersonation » menu entry is a GET link, so
  `Admin\DashboardController::configureUserMenu()` drops it; the banner is the way out.
- **Who.** The firewall's `role` is `App\Security\Voter\ImpersonationVoter::IMPERSONATE`,
  checked against the target: a super-admin, not already impersonating, acting as an account
  attached to an association — never another super-admin, never themselves.
- **No password on someone's behalf.** `ChoosePasswordEnforcer` ignores a `SwitchUserToken`
  (an invited account can be looked at), and `access_control` refuses `/mot-de-passe` to
  `IS_IMPERSONATOR`.

Both forms post to **`/platform`**, the dashboard route, because it is the one platform
route accepting POST: `platform_user_index` is GET-only and the router answers 405 before
the firewall sees `_switch_user`. Entering redirects to `/admin` (`target_route`); exiting
lands back on `/platform`. Exiting **from an `/admin` URL** would land the restored
super-admin in a back office with no association to show.

The banner is `templates/bundles/EasyAdminBundle/layout.html.twig`, styled in `admin.css`.
**Every start and exit is logged on the `impersonation` channel**, and that is the only
trace, by decision — nobody is notified. Production writes the channel straight to stderr;
the main handler is `fingers_crossed`, and buffered there the lines would only appear when an
error followed them.

**TRAP — EasyAdmin's actions dropdown ignores an action's template.** It renders its own
`<a>` or `<form>`, so a custom template carrying a CSRF token is silently bypassed and the
action posts without it. Both user CRUDs call `showEntityActionsInlined()` for that reason,
and `ActionTokenGuardTest` checks every CRUD with a token-carrying button.

### EasyAdmin prints three things raw — escape what people typed

EasyAdmin treats three values as HTML and prints them unescaped. All three were fed text
someone typed, and each was a stored XSS:

- **The string a `formatValue()` callback returns.** `CommonPostConfigurator` wraps it in a
  Twig `Markup`, overwriting the escaping `TextField` had already applied. A volunteer's
  address — typed on the anonymous public form — ran as script when a treasurer opened the
  declaration. **A `formatValue()` returning typed text must `htmlspecialchars()` it**; one
  returning a number or an enum label needs nothing.
- **Every flash message** (`{{ flash_message|raw }}` in the vendor template).
  `templates/bundles/EasyAdminBundle/flash_messages.html.twig` is a copy that escapes, so no
  `addFlash()` has to remember. A flash therefore cannot carry a link or bold text. On an
  EasyAdmin upgrade, re-diff the copy against the vendor file.
- **The dashboard title.** `/admin` uses the association's name, which any of its admins can
  edit — and a platform super-admin impersonating that association loads it too, with the
  exit-impersonation form and its token in the page. `Admin\DashboardController` escapes it.

**And a field's raw value goes into a `title` attribute.** EasyAdmin's text template prints
`<span title="{{ field.value }}">` beside the formatted value, so `formatValue()` hides nothing:
« État » was once a `TextField` on `password` reading « Actif », and every listed account's hash
sat in the page source, for any admin of the association to read. **No field is bound to
`password`.** The status reads `User::hasPassword()` through `admin/field/user_status.html.twig`;
`NoPasswordHashInPagesTest` reads the whole response of both back offices, and refuses such a
field in any CRUD controller.

`tests/Controller/Admin/NoRawUserTextTest` seeds one association with a marker tag in every
free-text field and walks every `/admin` page and flash. **A new page under `/admin` goes in
its list**, and a new free-text field in its `setUp()`.

### Every button that changes something carries a token

As GET links, any page a treasurer had open, or a mail scanner following a link, could
have ruled on a declaration — and validated lines feed the tax receipts. So every row
button that changes something (« Valider », « Refuser », « Valider tout », « Refuser
tout », « Confirmer à la place du bénévole », « Renvoyer l'invitation ») is a POST form,
`templates/admin/action/post_with_token.html.twig`, and its CRUD method is marked
**`#[App\Security\RequiresActionToken]`**. Nothing else checks the token:

- The template renders it with `admin_action_token(action.name, entity.primaryKeyValue)`;
  `App\Security\ActionTokenListener` (kernel.controller_arguments) checks it before the
  method runs. Both build the id with `AdminActionToken::id()` — the method's name plus
  the row id, so a token minted for one row decides no other.
- A bad token sends the user back to the page they clicked on (a same-origin `Referer`,
  else the CRUD's index) with « La page a expiré ». Nothing has changed.
- The check runs **before** the action's own tenant check. Isolation tests therefore post a
  real token, from `ReadsAdminPages::mintActionToken()`, or they would only prove the
  listener.
- Every CRUD with such a button calls `showEntityActionsInlined()`, for the reason given
  under *Impersonation*: the dropdown renders its own form and drops the token.

`tests/Controller/Admin/ActionTokenGuardTest` fails when a POST route naming a row
(`{entityId}`) runs a custom action without the attribute, or when its CRUD shows actions
in the dropdown. A new button needs the template, the attribute and the inlined actions;
the test enforces the last two.

## Layout

- `src/Entity/` — domain model
- `src/ValueObject/` — value objects: `Address`, `Email` (self-validating; `Address` is
  also an `#[ORM\Embeddable]`), and the figures `Money`, `HourlyRate`, `MileageRate`,
  `WorkHours` — see *Money*
- `src/Enum/` — closed business sets (`FiscalPower`, `ActivityType`)
- `src/State/` — finite state enums, the `DeclarationLifecycle` trait they share, and
  `Listener/` for their guards and cascades
- `src/Repository/` — Doctrine repositories
- `src/Controller/` — invokable controllers; `Admin/` and `Platform/` hold the two
  EasyAdmin dashboards, `Public/` the anonymous volunteer pages, `Security/` login
  links and passwords
- `src/Form/` — form types and the DTOs they bind to
- `src/Declaration/` — the declaration use cases (`DeclarationSubmitter`,
  `DeclarationConfirmer`, `DeclarationDecider`) and their exceptions
- `src/Accounting/` — valuation and the two ledger pages (`ContributionValuator`,
  `Ledger`, `LedgerSummary`, `PcgAccount`)
- `src/Receipt/` — the CERFA: the yearly run, eligibility, numbering, PDF and storage
- `src/Organization/` — organization-level services (`DefaultTasks`, `SignatureFactory`)
- `src/User/` — back-office account services (`OwnerAssigner`, `LoginLinkMailer`)
- `src/Volunteer/` — volunteer accounts (`CurrentVolunteer`, `VolunteerLink`,
  `VolunteerLogin`, `VolunteerLinkMailer`); their pages are in `src/Controller/Volunteer/`
- `src/Tenant/` — tenant contract, resolvers, context, request listener
- `src/Doctrine/Filter/` — the tenant SQL filter; `src/Doctrine/Type/` — DBAL types
- `src/Security/` — roles, voters, the CSP and action-token listeners
- `src/Validator/` — constraints that need a service (`FiscalYearDoesNotOverlap`,
  `OneOwnerPerOrganization`)
- `src/Twig/` — the `euros`/`hours` filters, `csp_nonce()`, `admin_action_token()`
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

## The public home page

`/` (`App\Controller\HomeController`, `templates/home.html.twig`) presents the product
to an association considering it, in French, and lists the associations that opted in,
each linking to its declaration form.

- **Opt-in, per association**: `Organization::$listedOnHomepage`, off by default, ticked
  from « Mon association » or `/platform`. An inactive association is never listed,
  whatever the flag says — its form would 404. The migration that added it ticked CVVFCM.
- **Cached publicly for five minutes.** That only holds while nothing on the page reads
  the security token: `app.user` or `is_granted()` in the template wakes the lazy firewall
  and makes every response private. `HomeTest` asserts the header and the absence of a
  session cookie.
- **The contact link is `mailto:` `%app.mail_from%`** — the one address the deployment
  already owns. A separate contact address would be a new chart value.
- **Screenshots are static**, `assets/images/landing/*.webp`: captured from the dev
  fixtures (fake names) at 1280 × 800 in the light theme, converted with Pillow. They go
  stale when the screens change; retake them the same way rather than editing them.
- **Logging in lands on `/espace`, not `/`.** `form_login`'s `default_target_path` is
  `backoffice` (`App\Controller\BackofficeController`), the one place that sends a
  super-admin to `/platform` and anyone else to `/admin`. Saving a chosen password and
  the home page's « Espace administrateur » link go through the same route.

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
- A refused step opens with **one summary** (`templates/public/declaration/_error_summary.html.twig`):
  every error of the step, each linking to its field's `id`, lines labelled « Action N — ».
  It is the only `role="alert"` on the page, and the `error-summary` controller focuses it.
  An error on a group (the list, a whole line) has no field to point at and is listed
  without a link.

`DeclarationSubmitter` is the boundary where the value objects are built. Their
assertions should be unreachable there, because the form validated the same rules
and reported them per field; reaching one means the two rule sets have drifted,
which is when a loud failure is wanted.

## The CERFA — generating the receipt

**A receipt covers one volunteer for one CIVIL YEAR, and nothing issues one automatically.**
`App\Receipt\YearlyReceiptRun` is the only thing that creates a `Receipt`, called from
« Reçus fiscaux » in the back office (`ReceiptCrudController::chooseYear` → `generate`) or
from `app:receipts:generate <year> --organization=<slug>`.

**Never one receipt per declaration.** A volunteer files several declarations a year and
carries **one** figure to their income-tax return (CGI art. 200 applies to the *revenus* of
a calendar year), and no receipt can be complete before the year has ended. `Declaration`
has no receipt relation; do not add a per-declaration hook.

**The civil year is not the exercice.** An association may run September to August; the
ledger follows that, the receipt does not. Each line is priced by the exercice covering
**its own date** (`ContributionValuator::value()`), so the rates follow the books while the
total follows the tax year. A line no exercice covers has no barème: it is left out of the
amount and **counted in the report**, never priced at zero.

**Only the *abandon de frais* goes in the amount box.** Donated hours are off balance
sheet and open no right to a deduction, so summing them in would overstate what the
volunteer may claim — CGI art. 1740 A penalises amounts wrongly stated at 25%. The mail
says so in as many words, because the volunteer gave the hours and is the one who would
put the wrong figure on a tax return.

**Refusals are ordinary outcomes, and they are reported.**
`App\Receipt\ReceiptEligibility` answers the association-level question **once per run** —
no SIREN/RNA, no postal address, an address `App\ValueObject\Address` rejects — and
nothing is attempted at all. A volunteer who waived nothing is skipped with a reason. Both
land on the report page and in the command's output: a run that quietly did nothing for
half the association would look exactly like one that worked.

**The association's address is held to `Address`'s rules where it is typed.** It is stored
as loose columns on `Organization` (a nullable embeddable cannot be hydrated) and only
becomes an `Address` when a receipt is made, so the columns carry `Address`'s lengths and
its French-postcode rule as constraints, by reference to its constants. Every part stays
optional: half an address is "incomplete", which is a refusal and not an error.

**A receipt that cannot be made stops the run, and the run still reports.** Gotenberg,
qpdf, storage or the database failing for one volunteer ends the run there:
`ReceiptRunOutcome` has four endings — issued, skipped, **failed**, **not attempted** — and
the page and the command print all of them, the command exiting non-zero. Stopping rather
than carrying on is deliberate: what fails here is almost always down for everyone, and
the rolled-back transaction has closed the EntityManager. Letting the exception out would
be worse than either — a 500 with receipts already issued and mailed, and nothing saying
which. **Running the year again re-issues everyone**, the volunteers already served
included (next paragraph); there is no "resume".

**The date printed is the last day of waived travel, capped at 31 December.** A line
belongs to the year it starts in, so one begun on 30 December for three days is on that
year's receipt; its real last day is in January, and a receipt must not state a donation
dated outside the year it covers. `WaivedYear` does the capping.

**An inactive association can still issue receipts**, by decision: `app:receipts:generate`
looks the slug up without the *active* filter. An association that stopped taking
declarations may still owe last year's receipts.

**Re-running a year issues new receipts with new numbers**, and the previous ones stay in
the database — that is what a rectificatif is. The object key carries the number, so the
earlier PDF survives too.

Numbers come from `ReceiptNumberAllocator`: `0001`, **one continuous series per association**
whatever year the receipt covers, from a counter on `Organization` taken under a
`PESSIMISTIC_WRITE` lock. On the association and not on a period, because an exercice
running September to August cannot number a January-to-December document. A counter and not
`MAX(number) + 1`, so deleting a receipt cannot free its number. The number, the object and
the row commit in one transaction, and there is **one transaction per volunteer** — a
failure on the twelfth must not undo the first eleven.

The object key is `receipts/<association id>/<year>/cerfa-firstname-lastname-<number>.pdf` —
`receipts/` from the flysystem prefix, in the bucket `benevolio`.

- **The association's id is in the key, and must stay there.** Every association shares the
  bucket and numbers from `0001`, so a key without it collided as soon as two associations had
  a volunteer of the same name: the later PDF replaced the earlier one, whose row then served —
  and re-mailed — another association's receipt. The row was tenant-scoped all along; the bytes
  it points to were not. The id, not the slug, which can be edited.
- **The number** is what keeps a reissue from overwriting the earlier PDF, and separates two
  volunteers sharing a name inside one association.
- **`ReceiptStorage::store()` refuses a key that already holds an object**
  (`ReceiptAlreadyStoredException`). Unreachable by construction, and refused for that reason.
- **The slugged name is cut** (`MAX_NAME_LENGTH`): transliteration lengthens some letters, and
  a name slugging past the 255-character column failed its receipt and stopped the run.
- Receipts issued before the id joined the key keep the path on their row
  (`<year>/cerfa-…`) and are read from there. Nothing was moved.

**The mail goes out inside the run**, one per volunteer, in series. That is an accepted cost:
fifty volunteers means fifty PDFs and fifty mails in one request, which is why
`app:receipts:generate` exists and why moving the run onto a Messenger transport is the
obvious escape if it ever stops fitting.

The command asks for confirmation, and **`--no-interaction` demands `--force`**. A
confirmation defaulting to "no" answers itself when nothing is on the terminal, so without
that guard the command would exit 0 having issued nothing — the worst outcome for a step a
deployment script is relying on.

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

### The signature

`CerfaLayout::IMAGES` positions images, `FIELDS` positions text; they cannot share a shape,
because an image is given a box (`[x, y, max width, max height]`) and a string is given a
baseline. A `max-inline-size`/`max-block-size` pair with no width or height keeps the
aspect ratio, so a wide scanned signature and a square stamp both fit.

The « Date et signature » box is at **x 106.9→184.1 mm, y 216.2→233.4 mm**, found by pixel
scan of a render — it is vector, so `pdftotext` reports nothing for it. The date already
occupies x 109→128.2, so the image goes to its right.

The signature is embedded as a `data:` URI. It has to be: Gotenberg renders the layer in a
container that cannot reach this application, so a URL comes back as a broken image on a
receipt already sent.

**A signature is optional.** `ReceiptEligibility` does not ask for one, and an association
without one gets the same document as before, to sign by hand.

**TRAP — you cannot count images per page after `--overlay`.** qpdf wraps each stamped
page's content in a Form XObject, and its `--json` per-page image list only reports a page's
*direct* resources, so after stamping it reports none at all — even the form's own logos.
`ReceiptGeneratorTest` therefore asserts the page on the *layer* and counts
`/Subtype /Image` objects in the QDF form of the finished file.

`App\Receipt\AmountInWords` is the only place that touches `NumberFormatter`, with one
documented suppression: PHPStan resolves it to Symfony's polyfill and forbids it, while
ext-intl is what actually runs — and the polyfill has no `SPELLOUT` at all. ICU is worth
that friction, because French is where hand-rolling breaks: *quatre-vingts* but
*quatre-vingt-un*, *soixante et onze* but *soixante-douze*, and *zéro euro* singular.

### S3 configuration

The variables are the ordinary ones — **`S3_ENDPOINT`, `S3_REGION`, `S3_BUCKET`, `S3_KEY`,
`S3_SECRET`** — matching cvvfcm-v4, so anything that already knows how to configure an S3
bucket for that project configures this one.

**One bucket, `benevolio`**, with the receipts under a `receipts/` **prefix declared on the
storage** rather than built into the keys. So `Receipt::$storagePath` holds
`<year>/cerfa-….pdf` and keeps meaning the same thing if the layout ever moves, and whatever
else the application has to file later goes in a sibling directory instead of a second bucket.

**Their defaults live in `config/packages/flysystem.yaml`**, as `env(...)` parameters, not in
`.env`. `.env` is tracked, so a value there is a setting pretending to be configuration that
every environment then has to override. Only `S3_ENDPOINT` has no default: development sets it
to the s3mock container (`compose.override.yaml`), production to the Scaleway region endpoint.

**Do not "helpfully" pass these through `compose.yaml` as `${S3_KEY:-}`.** An empty variable
that is *set* is not an absent one: Symfony resolves `%env(S3_KEY)%` to `''` and the signing
code then refuses to build a request, so the YAML default would be silently dead.

**In production the chart supplies all five**, and refuses to render without the credentials —
see *Deploying*. That is not belt-and-braces: because the defaults above are the development
ones, an unset key does not fail loudly, it signs a request to AWS with the literal key
`s3mock`.

### Testing it

`ReceiptGeneratorTest`, `YearlyReceiptRunTest`, `ReceiptAdminTest` and
`GenerateReceiptsCommandTest` hit **real Gotenberg and real s3mock** — mocking them would
prove none of the seams above. All of them need `make up` locally.

**CI runs them too.** `ci.yaml` starts `gotenberg/gotenberg:8` and `adobe/s3mock` as service
containers, and three details are what make that work:

- The steps run **on the runner**, not inside a container on the services' network, so the
  DSNs are `127.0.0.1:3000` / `:9090` and not service names.
- **`qpdf` is installed with apt.** The Dockerfile installs it for the app image; the runner
  has neither that image nor the binary, and without it every receipt test fails on a missing
  command rather than on anything to do with receipts.
- **Neither image ships curl**, so a `--health-cmd` on the service would fail forever and the
  job would never start. A step polls both instead, and asserts the bucket exists —
  `initialBuckets` is silently ignored under a wrong name, and the first PUT would 404
  mid-test.

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
make qa           # Everything CI runs, except hadolint and the image build
make clean        # Stop containers, remove all data/volumes/vendor
```

Run `bin/console` and `composer` **inside the php container** (`make cli`, or
`docker compose exec php …`). The entrypoint waits for the DB and applies pending
migrations on container start.

App served at `https://localhost`. `composer reset` (so `make reset`) leaves three
logins: **`admin@example.com` / `!ChangeMe!`** — **the owner of CVVFCM**, created by
the reset script itself through `app:user:create`, so it lands in `/admin` where the
features are and can delete other admins (the fixtures deliberately create no owner,
which is what lets the command make this one the owner) — plus the
fixture accounts `super-admin@benevolio.test` (the platform, `/platform`) and
`admin@cvvfcm.test`, whose password is in
`App\Factory\UserFactory::DEFAULT_PASSWORD`. The association's public form is at
`/a/cvvfcm/declaration`.

**Password rules are deliberately weak**, and this applies in production too:
minimum `User::PASSWORD_MIN_LENGTH` (8) characters, and nothing else — on
« Choisissez votre mot de passe » as in the command.
There is no `Assert\NotCompromisedPassword`: it calls haveibeenpwned on every write, so
the account commands would need network egress and a well-known development password
could not be used. Adding it means adding
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

**Deploys queue, they are never cancelled** (`cancel-in-progress: false`). A run cancelled
mid-`helm upgrade` leaves the release in `pending-upgrade`, and every later deploy then fails
with *"another operation (install/upgrade/rollback) is in progress"*. If it happens anyway:
`helm history benevolio`, then `helm rollback benevolio <last deployed revision>` — and note
that the rollback restores that revision's **image** too, so re-run the latest CD at once.

The image is **GHCR**, `ghcr.io/cvvfcm/benevolio/app`, public, authenticated with
the workflow's own `GITHUB_TOKEN`. Not Docker Hub: the name is nested and Docker
Hub only understands `namespace/repo`. Deploys pin `image.tag` to the commit sha,
so a rollback is `helm upgrade --set image.tag=<older sha>` and never waits on a
moving tag.

**Secrets come from two places, and the split matters.** Infrastructure ones are
**organization** secrets shared with the other repos — `OCI_*`, `OKE_KUBECONFIG`,
`CLOUDFLARE_API_TOKEN`, and `SCW_ACCESS_KEY` / `SCW_SECRET_KEY` for Object Storage,
which cvvfcm-v4 already uses under those names. Application ones belong to this
repo's **`prod` environment** — `APP_SECRET`, `MAILER_DSN`. Never
move an application secret up to the organization: it would hand every other repo
the keys to this database.

**Object storage and Gotenberg are chart-configured, and both refuse to render when
they are not.** `app.s3Endpoint` / `s3Region` / `s3Bucket` land in the ConfigMap,
`secrets.s3Key` / `s3Secret` in the Secret, and CD fills the last two from the
Scaleway organization secrets. They are `required` because the defaults are the
development ones and an unset key would not fail — see *S3 configuration*. The bucket
must already exist; nothing in the chart creates it.

The chart ships **its own Gotenberg** (`gotenberg.enabled`, ClusterIP only —
it renders whatever HTML it is handed, so it must never be exposed).
`GOTENBERG_DSN` is derived from that Service unless `app.gotenbergDsn` points
somewhere else; with neither, rendering fails at template time rather than on the
first validated declaration.

**The database is CloudNativePG, declared by the chart.** The operator is installed
cluster-wide (`cnpg-system`) and is not part of this chart; `templates/database-cluster.yaml`
declares one `Cluster`, `benevolio-db`.

- **No database credential goes through CD.** The operator generates the owner's password
  into the Secret `benevolio-db-app`, and the web pods and the migration Job build
  `DATABASE_URL` from its `uri` key plus `serverVersion` (`benevolio.database.env` in
  `_helpers.tpl`). Kubernetes expands `$(VAR)` only from variables declared *earlier* in
  the same `env` list, so that order is load-bearing.
- **`helm.sh/resource-policy: keep`** on the Cluster: `helm uninstall` leaves the data.
  Deleting it is a deliberate `kubectl delete cluster benevolio-db`.
- **One instance, 1 Gi, on `longhorn-cnpg`** — one Longhorn replica, `strict-local`, so the
  data lives on one node's disk and nothing else. Sized for ~1 000 declarations and ~100
  accounts: the data is tens of megabytes, and what would fill the volume is WAL, which
  `database.parameters` caps (`max_wal_size` defaults to 1 GB, and CNPG's own
  `wal_keep_size` of 512 MB is for standbys this cluster does not have). The class allows
  expansion, so growing is a change to `database.storage.size`.
- **`database.postgresMajor` drives three things** — the server image, the `serverVersion`
  Doctrine is told, and the `pg_dump` the backup runs (which must not be older than the
  server). Change it and all three move together; a major upgrade of the data itself is
  still a CNPG operation, not a values change.
- **The backup is a daily `pg_dump`**, not CNPG's physical backup (`database.backup`,
  `templates/database-backup-cronjob.yaml`): 03:00 Europe/Paris, custom format, uploaded with
  rclone to `<s3Bucket>/backups/` with the receipts' credentials, and pruned after **7 days**.
  With one replica on one disk, **it is the only copy that survives losing that node**. The
  prune runs inside the prefix, which the chart refuses to render empty — the receipts share
  the bucket. **A `403 AccessDenied` from rclone** (on `ListObjectsV2` and `PutObject`) is
  the Scaleway key lacking permission on the bucket, not the chart: the job uses the
  receipts' key — grant it Object Storage access in the bucket's project. Restore:
  `pg_restore --clean --if-exists --no-owner --no-acl -d "$URI" benevolio-….dump`.

**`secrets.mailerDsn` is required, and shape-checked at render time.** A declaration is
only final once the volunteer opens an emailed link, so an instance that cannot send mail
is one in which nothing can be declared — and Symfony's default `null://null` would
swallow every message without an error. Non-empty is not enough either: a value pasted as
the whole line, `MAILER_DSN=brevo+api://…`, deploys green, satisfies every probe, and
throws *"The mailer DSN must contain a scheme"* on the public form only.
`templates/secret.yaml` refuses a `mailerDsn` without a scheme and names the offending
prefix. Keep that guard on any DSN added later.

**`MAIL_FROM` is the platform's own address**, `benevolat@cvvfcm.fr` by default,
configured deployment-wide (`app.mailFrom`, `MAIL_FROM` in `.env`) and **not** per
association. `DeclarationConfirmationMailer` puts the association's name in the
display name and its address nowhere: the platform sends on their behalf, and a
spoofed sender domain lands the mail in spam. It is a literal, deliberately not
derived from `host` — this address has to be a *verified sender* at the relay, and
one the chart invented for itself would not be, so the mail would be accepted here
and dropped there. The template refuses an empty value and refuses a
`Name <addr>` form.

**The access log redacts the URLs that are credentials** (`log` block of the Caddyfile):
the `hash` of a back-office login link and the `_hash` of a volunteer's (one pattern
catches both), and the token of a declaration's confirmation link — in the
request URI and in the `Referer` header, where the same URL reappears on the next request.
Anyone able to read the pod's log could otherwise replay an invitation nobody had opened
yet. A new link that authenticates by its URL needs adding to that regexp.

**Kubernetes probes `/health`** (`App\Controller\HealthController`), not `/`, which
queries the database. The endpoint **does not touch the database** — the same URL
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
The first account created for an association this way becomes its owner, who can
then invite the others from « Administrateurs » without anyone touching a password.

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
- **UI is French; code identifiers are English.** User-facing strings are **written in
  place, in French** — in templates, controllers and constraint messages. The application
  is French-only and the legal vocabulary has no useful translation. `translations/*.fr.xlf`
  holds the keys that already exist (`messages` for the app, `admin` for EasyAdmin); a
  template that reads from it keeps doing so, but nothing is moved there. Symfony ships
  `validators.fr.xlf` and `security.fr.xlf`; do not duplicate those.

### Uploaded files

There is no VichUploader, and no upload directory. The one uploaded file — the
association's signature — is stored **in the database**, in `organization_signature`, as
base64 in a TEXT column. It is consumed as a `data:` URI anyway (Gotenberg cannot fetch a
URL from this application), so bytes on disk would buy nothing and a container filesystem
loses them at the next deployment.

- **`Organization` holds the *owning* side** of the one-to-one. That row is hydrated on
  every request to resolve the tenant; an owning to-one reads only `signature_id` and the
  image loads lazily. The inverse side would cost an extra query on every request.
- **Never hold an `UploadedFile` on an entity.** `Organization` is reachable from the
  security token through `User`, and `ContextListener` serializes that token into the
  session on **every** response: an `UploadedFile` in the graph throws *"Serialization of
  'UploadedFile' is not allowed"* and turns every response after the upload into a 500.
  `signatureUpload` is therefore a virtual property — getter and setter only, which is all
  PropertyAccess needs — and the setter converts and forgets.
- **An upload PHP threw away must not reach the conversion.** Over `upload_max_filesize`,
  unwritable temp dir, connection cut: the `UploadedFile` arrives with an **empty path**, and
  `getMimeType()` on it throws *"The "" file does not exist or is not readable."* — a 500 where
  the user should be told their file was too big. Symfony's `FileType` adds the right form
  error but **does not clear the data** (it only nulls values that are not file uploads at
  all), so the setter has to check `isValid()` itself.
- **16 MB accepted, ~1 000 px stored.** `App\Organization\SignatureFactory` — a service, using
  **Imagine with its GD driver** (aliased in `services.yaml`; ext-imagick is not installed) —
  scales anything longer than `STORED_MAX_EDGE_PX` and re-encodes it. Stored as uploaded, a
  16 MB scan would be 21 MB of base64 in the row, the same again inside every overlay, a receipt
  PDF per volunteer past what most relays accept as an attachment, and a request over PHP's
  memory limit. An image already small enough is kept **byte for byte**.
- **Re-encoded in its own format, not always PNG.** PNG is right for line art and for anything
  with transparency, and wrong for a photograph: a 3 000 × 3 000 photo of a signed page came out
  at ~650 KB as PNG against ~145 KB as JPEG, measured. A JPEG has no alpha to protect. A PNG
  must stay PNG or the form's own printed rules end up behind a white rectangle.
- **The conversion is a service, and the entity holds only the result.** No statics, no image
  code on `Organization`, and no `UploadedFile` anywhere near it. The two form fields are
  **unmapped**; `MyOrganizationCrudController::createEditFormBuilder()` adds a `POST_SUBMIT`
  listener that calls the factory and puts a refusal on the file field. POST_SUBMIT and not
  `updateEntity()`: an error added there would come after the form was already judged valid.
- **`MAX_PIXELS` is a separate guard, and the byte cap does not cover it.** PNG compresses flat
  artwork enormously, so a few-kilobyte file can hold a 12 000 × 12 000 canvas, and a decoder
  allocates 4 bytes per pixel *the moment it decodes*. Checked before decoding; refusing is a
  message, discovering it inside GD is a fatal error mid-request.
- `ext-gd` is required (Dockerfile and CI both install it), `upload_max_filesize`/`post_max_size`
  are raised in `.infra/docker/php/conf.d`, and `memory_limit` is 256M for the decode. The
  Dockerfile copies that file into the image; without it production runs on PHP's bare
  defaults and a 2 MB signature comes back as an empty field. **TRAP:** a `#` comment
  containing parentheses is a **syntax error** in php.ini, and PHP then ignores the whole
  file — comments there start with `;`.
- Raster only. An SVG is markup and has no business in a stamped PDF.

### State machines (finite)

A state enum implements `Finite\State` and declares its own transitions; behaviour lives
on the enum as methods rather than as state tests in domain code. Guards and side effects
are PSR-14 listeners on `CanTransitionEvent` / `PostTransitionEvent`, i.e. ordinary
Symfony listeners. The two declaration machines are described under *The two state
machines*.

**Entities have no `setState()`.** Finite writes the state property through
reflection, so a setter would exist only as a way to bypass the machine and its
guard. Go through `Finite\StateMachine::apply()`.

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
  `MockHttpClient` for outbound HTTP. Dates are relative to today (`-30 days`), never
  fixed, so a test does not expire.
- **Functional**: `WebTestCase` through the real HTTP entry point.
- **All test data comes from Foundry factories** in `src/Factory/`, never
  hand-built entities.
- Cover at minimum the happy path, the branches that change behaviour, and input
  validation / error handling.
- Every tenant-scoped entity gets an isolation test. See above.

Test method names are `snake_case` (`.php-cs-fixer.dist.php` overrides
`@Symfony` for this — long descriptive names read better that way).

Harness traps:

- `tests/bootstrap.php` forces `APP_ENV=test` **before** loading `.env`. PHPUnit
  applies its `<server>` block *after* the bootstrap file, so `.env`'s
  `APP_ENV=dev` would otherwise win and the kernel would boot in dev.
- **Create the client before any factory.** In a `WebTestCase`, Foundry boots the kernel
  on first persist, and `WebTestCase` refuses to build a client once the kernel is
  booted. Create it in `setUp()`.
- **Do not put a Doctrine entity in a `FormFlow` DTO and expect it to stay
  managed.** The draft lives in the session between steps and `SessionDataStorage`
  deep-clones it, which detaches the entity — persisting it then fails with "A new
  entity was found through the relationship". `DeclarationSubmitter` re-fetches the
  task for exactly this reason, which also re-checks the tenant.
- **Clear the EntityManager before a request that reads what you just built.**
  `WebTestCase` shares its EntityManager with the requests it makes. Foundry's
  `createMany()` leaves the *inverse* side stale — three rows created, one element in the
  collection — and a cross-tenant row already in the identity map reaches the controller
  without the tenant filter ever seeing a query. A real request always gets a fresh
  EntityManager, so this is a harness artefact — but it makes tests lie: an isolation test
  once passed while the real request answered 500.
- **A test helper that reads the database must disable the tenant filter.** The
  filter is request-scoped, so after a request to another association's URL a plain
  `findAll()` returns nothing — the filter working correctly, and the helper
  reporting a falsehood.

Both are handled once, in `tests/Support/`: **`ReloadsEntities`** (`reload()`,
`findOne()`, `findAllOf()` — every read clears the EntityManager and switches the
filter off), **`LogsInAdmins`**, **`ReadsAdminPages`** (page text, detail URLs, the
tokens buttons post) and **`WalksDeclarationFlow`** (the public form, step by step).
Use them rather than writing a private copy.
For factories: `PersonFactory::for()`, and `DeclarationActionFactory::withWaivedTravel()`
/ `onFoot()` for the two shapes of line most tests need.

Also: `Foundry`'s `defaults()` is evaluated whether or not the caller overrides
the keys it sets. Creating an entity in there to make two attributes consistent
produces one orphan per object built — hence `DeclarationActionFactory::for()` /
`forDeclaration()` rather than a guess in `defaults()`.

And a **factory instance** used for two keys is created twice: one
`OrganizationFactory::new()` handed to both a declaration and its person files them under
two associations. When two defaults must agree, share a `memoize(...)`d value — lazy, so
nothing is created when the caller overrides both (see `DeclarationFactory`).

To test what happens when Gotenberg goes down part-way, `App\Tests\Support\FailingGotenberg`
swaps in a client that renders N documents and then cannot connect. `GotenbergClient` is
public **in the test environment only** (`when@test` in `services.yaml`) for that: the
container otherwise inlines it where nothing can replace it.

Run `make reset-test` once, then `make test`.

`phpunit.dist.xml` raises `memory_limit` to 512M. The suite peaks near 100 MB against PHP's
128 MB default, mostly booting kernels, and one run has already died mid-test with *"Allowed
memory size exhausted"*. Set there rather than on a command line, so CI gets it too.

## Linters — MANDATORY

All produced code MUST pass every check CI runs
(`.github/workflows/ci.yaml`). A change that fails any of them is not finished.
`make qa` runs all of them except hadolint.

- **PHPStan** — level max + bleedingEdge + strict-rules, symfony, doctrine and
  phpunit extensions: `make stan`
- **PHP CS Fixer** and **Twig CS Fixer**: `make cs` / `make cs-check`
- **PHPUnit**: `make test`
- Container / YAML / Twig / translations / schema drift: `make lint`
- **ESLint**, **StyleLint**: `npm run lint:js`, `npm run lint:css`
- **hadolint** on the `Dockerfile`

**There is no PHPStan baseline, and none is to be added.** When a rule is
provably wrong, use a narrow inline `@phpstan-ignore` with a comment explaining
why — see `App\Receipt\AmountInWords`, where PHPStan resolves `NumberFormatter` to
Symfony's polyfill while ext-intl is what actually runs. PHPStan also reports an ignore
that no longer matches anything, so a suppression cannot outlive its reason.

`config/reference.php` is auto-generated and excluded from php-cs-fixer;
formatting it would be undone on the next regeneration.
