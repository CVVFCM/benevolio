# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

- **Volunteers** of French associations *loi 1901* — the primary audience. They fill in
  paperwork once or twice a year, usually at home, on a phone as often as a laptop, and are
  often not comfortable with forms or accounting. They declare the time they gave and the
  expenses they paid, with or without an account in their association's volunteer space.
- **Association treasurers and admins**, in the `/admin` back office: they confirm, validate
  or refuse declarations, keep the rates on each exercice, read the ledger and issue the tax
  receipts.
- **The platform super-admin**, in `/platform`: creates and manages the associations
  themselves.

## Product Purpose

Turn volunteers' donated time and waived expenses into correct legal paperwork. Valued hours
(bénévolat valorisé, off-balance-sheet class 8, never receiptable) are kept apart from
expenses a volunteer waives (abandon de frais), which become a tax receipt, CERFA n°11580\*05.
A multi-tenant SaaS: one association uses it today, the model assumes many. Anyone can declare
without an account.

Success: a volunteer declares in one sitting without help, and the association gets bookable
entries and receipts it can defend to an auditor.

## Positioning

Legal correctness over generic volunteer management. The vocabulary, the accounting
treatment and the receipt follow French law (PCG, ANC 2018-06, CGI art. 200), and the product
refuses to blur what the law keeps apart — a valued hour never reaches a receipt.

## Operating Context

- The public form (`/a/{association}/declaration`) runs in three steps: contact details,
  volunteer actions, attestation. A declaration can hold up to 50 actions; 10 to 20 is common.
- An anonymous declaration is final only once the volunteer confirms it from an emailed link.
- Receipts are issued once a year per civil year, from the back office.

## Capabilities and Constraints

- **French only.** UI written in French; code identifiers in English.
- **Works without JavaScript.** Every public page is server-rendered and usable without
  scripts; JavaScript only enhances (progressive enhancement).
- **No third-party fonts or CDNs** (CNIL ruling): system font stack, assets served from the
  application itself.
- **Strict Content-Security-Policy** on public pages: scripts from `'self'` with a nonce, no
  inline scripts without it, no `data:` modules.
- **Light and dark themes**, following the system preference or the visitor's choice.

## Brand Commitments

- Name: **benevolio**. Tone: plain, respectful French, like an official document that does not
  talk down to the volunteer.
- **Free** for associations and volunteers. **Published by the CVVFCM** (Club de Voile des
  Vieilles-Forges de Charleville-Mézières, https://www.cvvfcm.fr/).
- Mark: `assets/images/brand/mark.svg` — a « b » and a brass dot on a deep-sea tile.
- Served on `benevolat.cvvfcm.fr` for now; a dedicated domain is an open decision.

## Evidence on Hand

- Landing screenshots in `assets/images/landing/` (taken from development fixtures, fake names).
- The official CERFA form in `resources/cerfa/`.
- Four guides under `/guides` (reçu fiscal pour abandon de frais, valorisation du bénévolat,
  barème kilométrique, CERFA 11580), each citing official sources, and a FAQ on the home page.
- No testimonials, customer quotes, figures or press exist; do not invent them.

## Product Principles

1. The law decides the shape of the data; the interface explains it, never bends it.
2. A volunteer must finish alone: every field says what it means in their words.
3. Nothing depends on JavaScript to work; scripts only make it easier.
4. Never claim what the application cannot know (e.g. a volunteer's mileage elsewhere).

## Accessibility & Inclusion

Target: **RGAA / WCAG 2.2 AA** on the public pages. Keyboard and screen-reader use are first
class (error summary focused on a refused step, every error linked to its field).
