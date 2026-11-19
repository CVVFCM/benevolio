---
name: Benevolio
description: Volunteer hours, expenses and tax receipts for French associations, dressed as the municipal sports hall every club shares.
colors:
  surface: "#eef2f7"
  surface-raised: "#ffffff"
  surface-sunken: "#e0e7f0"
  ink: "#0f1621"
  ink-muted: "#3d4858"
  ink-faint: "#566273"
  rule: "#c8d3e1"
  rule-strong: "#8b9db5"
  court: "#1b56a3"
  court-deep: "#13427f"
  court-line: "rgb(255 255 255 / 88%)"
  on-court: "#ffffff"
  on-court-muted: "#d7e4f6"
  accent-tint: "#e2ebf8"
  tape: "#f5c22e"
  tape-hover: "#ffd75a"
  tape-ink: "#0f1621"
  marker: "#1c7547"
  marker-tint: "#e0f1e7"
  danger: "#b42318"
  danger-tint: "#fde8e4"
  scoreboard: "#0c121b"
  digit: "#ffc83a"
  digit-hot: "#ff5b3d"
  digit-label: "#a9b6c8"
  dark-surface: "#0a111c"
  dark-surface-raised: "#111b2b"
  dark-surface-sunken: "#070c15"
  dark-ink: "#e8eef6"
  dark-ink-muted: "#b3bfcf"
  dark-rule: "#22324b"
  dark-court: "#163f78"
  dark-accent: "#8db7ff"
  dark-marker: "#77d19e"
  dark-danger: "#ff9c8c"
typography:
  display:
    fontFamily: "Big Shoulders Display, Arial Narrow, Roboto Condensed, system-ui, sans-serif"
    fontSize: "clamp(2.75rem, 1.6rem + 4.2vw, 5rem)"
    fontWeight: 850
    lineHeight: 0.92
    letterSpacing: "-0.005em"
  headline:
    fontFamily: "Big Shoulders Display, Arial Narrow, Roboto Condensed, system-ui, sans-serif"
    fontSize: "clamp(2rem, 1.4rem + 2.2vw, 3.25rem)"
    fontWeight: 850
    lineHeight: 1
    letterSpacing: "0em"
  page-title:
    fontFamily: "Big Shoulders Display, Arial Narrow, Roboto Condensed, system-ui, sans-serif"
    fontSize: "clamp(2.25rem, 1.6rem + 2.4vw, 3.5rem)"
    fontWeight: 850
    lineHeight: 1
    letterSpacing: "0em"
  digits:
    fontFamily: "Big Shoulders Display, Arial Narrow, Roboto Condensed, system-ui, sans-serif"
    fontSize: "clamp(2.25rem, 1.4rem + 3vw, 3.75rem)"
    fontWeight: 900
    lineHeight: 1
    fontFeature: "\"tnum\""
  title:
    fontFamily: "Big Shoulders Display, Arial Narrow, Roboto Condensed, system-ui, sans-serif"
    fontSize: "1.5rem"
    fontWeight: 800
    lineHeight: 1.1
    letterSpacing: "0.01em"
  button:
    fontFamily: "Big Shoulders Display, Arial Narrow, Roboto Condensed, system-ui, sans-serif"
    fontSize: "1.125rem"
    fontWeight: 800
    letterSpacing: "0.03em"
  label:
    fontFamily: "Big Shoulders Display, Arial Narrow, Roboto Condensed, system-ui, sans-serif"
    fontSize: "0.9375rem"
    fontWeight: 700
    letterSpacing: "0.08em"
  body:
    fontFamily: "system-ui, -apple-system, Segoe UI, Roboto, Helvetica Neue, Arial, Noto Sans, sans-serif"
    fontSize: "1rem"
    fontWeight: 400
    lineHeight: 1.6
  body-small:
    fontFamily: "system-ui, -apple-system, Segoe UI, Roboto, Helvetica Neue, Arial, Noto Sans, sans-serif"
    fontSize: "0.9375rem"
    fontWeight: 400
    lineHeight: 1.5
  field-label:
    fontFamily: "system-ui, -apple-system, Segoe UI, Roboto, Helvetica Neue, Arial, Noto Sans, sans-serif"
    fontSize: "0.9375rem"
    fontWeight: 600
    lineHeight: 1.35
rounded:
  sm: "2px"
  md: "3px"
spacing:
  "1": "0.25rem"
  "2": "0.5rem"
  "3": "0.75rem"
  "4": "1rem"
  "5": "1.5rem"
  "6": "2rem"
  "7": "3rem"
  "8": "4rem"
components:
  button-primary:
    backgroundColor: "{colors.court}"
    textColor: "{colors.on-court}"
    typography: "{typography.button}"
    rounded: "{rounded.sm}"
    padding: "0.5rem 1.25rem"
    height: "2.75rem"
  button-primary-hover:
    backgroundColor: "{colors.court-deep}"
  button-tape:
    backgroundColor: "{colors.tape}"
    textColor: "{colors.tape-ink}"
    typography: "{typography.button}"
    rounded: "{rounded.sm}"
    padding: "0.5rem 1.25rem"
    height: "2.75rem"
  button-tape-hover:
    backgroundColor: "{colors.tape-hover}"
  button-line:
    textColor: "{colors.on-court}"
    typography: "{typography.button}"
    rounded: "{rounded.sm}"
    padding: "0.5rem 1.25rem"
    height: "2.75rem"
  button-quiet:
    textColor: "{colors.ink}"
    typography: "{typography.button}"
    rounded: "{rounded.sm}"
    padding: "0.5rem 1.25rem"
    height: "2.75rem"
  button-small:
    padding: "0 0.75rem"
    height: "2rem"
  input:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    typography: "{typography.body}"
    rounded: "{rounded.sm}"
    padding: "0.5rem 0.6875rem"
  form-plate:
    backgroundColor: "{colors.surface-raised}"
    textColor: "{colors.ink}"
    rounded: "{rounded.md}"
    padding: "0 2rem 2rem"
  form-plate-band:
    backgroundColor: "{colors.court}"
    textColor: "{colors.on-court}"
    typography: "{typography.title}"
    padding: "0.75rem 2rem"
  scoreboard:
    backgroundColor: "{colors.scoreboard}"
    textColor: "{colors.digit}"
    typography: "{typography.digits}"
    rounded: "{rounded.md}"
    padding: "0.5rem"
  step-current:
    backgroundColor: "{colors.tape}"
    textColor: "{colors.tape-ink}"
    size: "1.75rem"
  step-done:
    backgroundColor: "{colors.accent-tint}"
    textColor: "{colors.court}"
    size: "1.75rem"
  court-field:
    backgroundColor: "{colors.court}"
    textColor: "{colors.on-court}"
    padding: "4rem 0"
---

# Design System: Benevolio

## Overview

**Creative North Star: "Le gymnase"**

The public pages are the municipal sports hall every club shares. A court-blue floor carries white painted lines; around it sits a light surround where people read and fill in forms; above it hangs one dark scoreboard with lit digits. The association's season is the score (hours given, kilometres, receipts), each section of a page is a zone of the court, and the line-marking tapes have jobs. Nothing is decorative paper, nothing is handwritten, and nothing is a stack of soft SaaS cards.

Density follows the mode. Persuasion pages (home, « C'est gratuit ») alternate full-bleed court fields with surround sections at a 68rem measure. Guides open on a court strip and then read in one column at 68ch. Forms sit in a 54rem column on the surround, each step on a white plate whose title is painted on a court-blue band. Everything works without JavaScript, in light and dark, in French only.

This system covers the public surface only: home, pricing, guides, the declaration form, logins, the volunteer space, confirmation and error pages (`assets/styles/app.css`). The back offices (`/admin`, `/platform`, EasyAdmin, `admin.css`) are outside it.

**Key Characteristics:**
- Court-blue fields with white painted lines drawn as shapes behind content, never under text.
- One scoreboard: near-black panel, amber tabular digits, one counter lit red.
- Tapes with jobs: yellow to press and to show where you stand, green for valued hours and done, red for the changed counter and for errors.
- Condensed signage headings (Big Shoulders Display, self-hosted), system-stack body.
- Square, hard shapes: 2px and 3px corners, flat surfaces, no shadows.

## Colors

A cool sports-hall palette: a light blue-grey surround, a saturated court blue, and three marking-tape colours that each mean one thing.

### Primary
- **Court Blue** (`court`): the floor. Full-bleed fields, the forms' header strip, the guide's short-answer panel, the plate bands, and every ordinary button and link off the court (the `accent` token is the same value). **Court Deep** (`court-deep`) is its hover and the header's bottom edge.
- **Painted Line** (`court-line`): white at 88%, for the halfway line, centre circle, three-point arcs, sidelines and the outlined secondary button on the court.

### Secondary
- **Marking Yellow** (`tape`, ink `tape-ink`): the one thing to press on a field, the current step, the current volunteer tab's underline, text selection, and the « expenses » tag in the split. Hover lightens to `tape-hover`.
- **Valued Green** (`marker`, tint `marker-tint`): valued hours and done; success alerts and confirmation marks use the same value (`success`).

### Tertiary
- **Scoreboard** (`scoreboard`): the near-black panel, also the site bar and the guides' closing call. Its **Lit Amber** (`digit`) carries the figures, **Hot Red** (`digit-hot`) the one counter that changed, **Scoreboard Label** (`digit-label`) the captions.
- **Error Red** (`danger`, tint `danger-tint`): field errors, the error summary, invalid lines in the action navigator, the remove-line button.

### Neutral
- **Surround** (`surface`): page background and input fill. **Plate White** (`surface-raised`): form plates, panels, tables. **Sunken** (`surface-sunken`): quiet hovers.
- **Ink** (`ink`), **Ink Muted** (`ink-muted`), **Ink Faint** (`ink-faint`): text, secondary text, help and metadata.
- **Rule** (`rule`) and **Rule Strong** (`rule-strong`): plate borders, table rows, input borders.
- **On Court** (`on-court`) and **On Court Muted** (`on-court-muted`): text on the blue.

### Dark
At night the hall is lit low: the surround goes near-black (`dark-surface`), the floor navy (`dark-court`), links and plain buttons a light blue (`dark-accent`), green and red lighten (`dark-marker`, `dark-danger`). The tapes, the scoreboard and its digits do not change: they stay lit. Dark values are declared once as `--dark-*` and only mapped by the two theme blocks.

### Named Rules
**The Tapes Have Jobs Rule.** Yellow means press here or you are here; green means valued or done; red means just changed or must be corrected. A tape colour is never used for decoration.

**The One Yellow Target Rule.** A field of court carries one yellow button. The site bar's « Espace administrateur » is outlined, not taped, so the hero's button stays the only yellow on the first screen.

**The Lit Scoreboard Rule.** The scoreboard and its digits keep their colours in both themes.

## Typography

**Display Font:** Big Shoulders Display (self-hosted variable woff2, Latin subset, weights 100-900), falling back to Arial Narrow and Roboto Condensed
**Body Font:** the system stack (system-ui, -apple-system, Segoe UI, Roboto, Helvetica Neue, Arial, Noto Sans)

**Character:** gym signage over plain reading text. The condensed grotesk is painted on the floor and lit on the board; the body stays neutral so forms and legal explanations read without effort.

### Hierarchy
- **Display** (850, clamp 2.75-5rem, 0.92, uppercase, max 15ch): the headline painted on the court in the first screen; the pricing headline uses the same voice at clamp 2.5-4.5rem.
- **Headline** (850, clamp 2-3.25rem, 1, uppercase): section headings on the home page.
- **Page title** (850, clamp 2.25-3.5rem, 1, mixed case): `h1` of the forms and the volunteer space; guide titles at clamp 2.25-3.75rem.
- **Digits** (900, clamp 2.25-3.75rem, tabular figures): scoreboard values, units at 0.55em; the pricing « 0 € » at clamp 7-12rem.
- **Title** (800, 1.5rem, 0.01em): plate bands, the association's name in the forms' header (1.375rem). Feature, step and split titles sit at 1.125rem / 650; guide section headings at 1.375rem / 650.
- **Button** (800, 1.125rem, 0.03em, uppercase): every button and button link; 0.875rem in the small size.
- **Label** (700, 0.9375rem, 0.08em, uppercase): scoreboard labels only.
- **Body** (400, 1rem, 1.6): running text; 1.65 and 68ch in guides, 46-62ch for ledes. Help text and errors at 0.8125rem.

### Named Rules
**The Step-Up Rule.** The condensed face sets small: anything set in it is a step larger than the body would be (titles at 1.125rem minimum, buttons at 1.125rem).

**The Signage-Only Rule.** Big Shoulders is for headings, numbers, buttons, tabs and labels. Paragraphs, help text, field labels and table cells stay in the system stack.

**The Real Digits Rule.** Figures that are compared or counted use tabular numerals: scoreboard, step markers, tables, summaries.

## Layout

Three measures: 68rem for site pages (`home__wrap`), 54rem for forms and the volunteer space (`public__main`), 46rem / 68ch for a guide. Court fields bleed to the viewport edges; their content stays on the measure. The spacing scale is 4px-based, eight steps from 0.25rem to 4rem; sections are 4rem apart, plates are padded 2rem, fields 1.5rem apart.

The hero is a two-row grid on the court: the scoreboard across the top, the headline and actions below, with the halfway line at 72% and the centre circle on it, right of the copy. Below 48rem the markings move under the buttons (halfway line across the foot of the field, circle on it). Centred pages (`court--ends`) put the two three-point arcs at the edges and drop them below 64rem. The declaration form gains a sticky action navigator beside it from 72rem. Breakpoints in use: 30, 40, 44, 48, 64 and 72rem.

**The Clear Copy Rule.** Painted lines never pass behind text. Check every new piece of content against the markings at 390px and 1440px.

## Elevation & Depth

Flat. `--shadow-raised` is `none` in both themes, and depth comes from painted shapes: a blue field against the surround, a court-blue band across a white plate, the dark scoreboard on the blue floor, a 4px painted baseline where a zone begins. The only `box-shadow` in the system is the input focus ring, a 3px spread with no blur in `accent-tint` (or `danger-tint` on an invalid field).

**The Painted Not Lifted Rule.** A surface is separated by colour, a 1-3px line or a painted band, never by a blurred shadow.

## Shapes

Rectangles have cut corners, not rounded ones: 2px (`rounded.sm`) for buttons, inputs, tags and line panels, 3px (`rounded.md`) for plates, the scoreboard, the split and alerts. Circles exist only where a gym has them: the painted centre circle and arcs (3px white strokes), the round step markers, the confirmation mark, the error dot. Court lines are drawn as CSS gradients and bordered pseudo-elements at 3px; the field clips them at its edges like a floor at the wall. The FAQ plus/minus is drawn with two gradients, not a glyph.

## Components

### Buttons
Painted, uppercase, in the display face.
- **Shape:** 2px corners, 2.75rem minimum height, 0.5rem 1.25rem padding.
- **Primary:** court blue with white text; hover to court deep (120ms ease-out).
- **Tape:** yellow with ink text, the one thing to press on a field of court; hover lightens. The guide's closing call and the contact key also tape their button.
- **Line:** transparent with a 2px painted white line, for the secondary action on the court; hover adds 12% white.
- **Quiet:** transparent with a `rule-strong` border on the surround; on the court header the border becomes the painted line.
- **Danger:** transparent red text at 0.875rem (remove a line); hover takes the red tint.
- **Small:** 2rem high, 0.875rem text.

### Inputs / Fields
- **Style:** surround fill, 1px `rule-strong` border, 2px corners, 16px text so iOS does not zoom.
- **Focus:** border to the accent plus a 3px `accent-tint` ring.
- **Error:** red border and red ring; the message sits below in 0.8125rem red. A refused step opens with one error summary linking to each field.
- Labels 0.9375rem / 600 above the control; help below in muted 0.8125rem. Checkboxes take the accent colour.

### Form plates and steps
- **Plate:** white, 1px `rule` border, 3px corners, padded 2rem; its title is a court-blue band painted across the top in the title style.
- **Steps:** round 1.75rem markers. Current is filled yellow with ink and its label bolds; done takes `accent-tint` with a blue numeral; to come is an outline in `rule-strong`.
- **Action panels:** white, 1px `rule-strong`, 2px corners, numbered « Action N » by a CSS counter; the travel part is fenced off by a rule.

### Navigation
- **Site bar:** the scoreboard colour, brand in the display face at 1.5rem, links in the display face at 1.125rem / 700, light grey turning white; « Espace administrateur » outlined in 2px white.
- **Forms' header:** a strip of court with a 4px court-deep bottom edge; the association's name in the display face; volunteer tabs in the display face, the current one underlined with a 3px yellow tape.

### Scoreboard (signature)
A near-black panel with a 3px dark border and 3px corners: three counters in a grid, uppercase labels in `digit-label` above amber digits, units at 0.55em, a caption saying the figures are an example. One counter is lit `digit-hot`. `scoreboard_controller.js` counts the values up once on load over 1100ms with a cubic ease-out, only the leading number moving; under `prefers-reduced-motion` or without JavaScript the real text stands as written. The pricing page reuses the panel for « 0 € » at display size, with the fees beneath a 1px rule.

### Court fields (signature)
- **Hero:** halfway line, centre circle (22rem), three-point arc (58rem) from the bottom right.
- **Ends:** two arcs at the page edges for centred pages.
- **Field:** sidelines painted 1.5rem in from top and bottom of a whole section; features become lanes between 2px painted lines.
- **Strip:** a court band under a page title (guides) with a baseline and an 18rem circle clipped at the right.
- **Key:** the closing contact is a painted rectangle with the free-throw half-circle on its top line.

### Split
Two halves of one court separated by a 3px painted halfway line (horizontal below 40rem), so hours and expenses never read as one sum. Each side ends on a tag: green for valued time, yellow for expenses that become a receipt.

### Motion
Two authored moments only: the scoreboard count-up, and a newly added action line settling in (320ms, `cubic-bezier(0.16, 1, 0.3, 1)`, from 0.375rem above at 40% opacity), both off under reduced motion. Everything else is a 120-160ms ease-out on colour or a chevron.

## Do's and Don'ts

### Do:
- **Do** put the page's one primary action on yellow tape, and only one per field of court.
- **Do** draw court markings as 3px `court-line` strokes behind content, and move them clear of text on small screens.
- **Do** set headings, numbers, buttons and tabs in Big Shoulders Display at a step above body size, and keep paragraphs in the system stack.
- **Do** keep corners at 2px or 3px and separate surfaces with colour, painted bands and 1-3px lines.
- **Do** declare dark values once as `--dark-*` and map them in both theme blocks; leave tapes and the scoreboard lit.
- **Do** keep counted figures real text in tabular numerals, labelled as an example when they are one.

### Don't:
- **Don't** let a painted line pass behind text.
- **Don't** use yellow, green or red for anything but their jobs; red is never decoration.
- **Don't** add blurred or offset shadows; `--shadow-raised` stays `none`.
- **Don't** set body copy, field labels or table cells in the condensed face.
- **Don't** load a font from a third-party CDN; faces are self-hosted.
- **Don't** add a second scoreboard to a page, or a second animated moment beside the two above.
- **Don't** use em-dashes in anything a person reads; titles separate with « · ».
