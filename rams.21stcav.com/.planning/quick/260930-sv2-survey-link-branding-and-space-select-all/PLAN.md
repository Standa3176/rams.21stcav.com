---
phase: quick
plan: 260930-sv2
type: feature
severity: legibility-and-brand
subsystem: site-survey-engineer-link
autonomous: true
---

# Quick Task 260930-sv2: 21st branding on the site-survey engineer link, and a select-all for the spaces step

## Objective

The user, verbatim:

> "for sitesurvey , how do i select either all rooms or a selection for the site
> survey? Link still looks the same as previous ie table have colours not plan
> etc please apply 21st branding and make visually better"

Two things, both measured before being believed.

### 1. There is no select-all on the spaces step

`CockpitDocumentFormPresenter.php:369-375` declares `visit_rooms` as
`TYPE_SPACE_LIST`, rendered at `components/cockpit/doc-form.blade.php:597` as one
checkbox per space and nothing else. Every space is ticked by default (D-02, and
that stays). On an 18-space project, surveying one room is **seventeen manual
unticks**.

### 2. The engineer link is not in the brand

Measured on `resources/views/surveys/show.blade.php`:

| | before |
|---|---|
| Brand teal `#01889F` | absent |
| Brand gold `#D4AF37` | absent |
| What it used instead | `#178A95` teal, `#C9922A` gold, `#0B3C45` dark — all near-misses |
| Brand heading face (Verdana) | absent |
| Brand body face (Poppins) | absent |

And two of those near-misses were **shipped AA failures**:

| pairing | ratio | verdict |
|---|---|---|
| white on `#178A95` (teal buttons, 12 places) | 4.11:1 | FAIL |
| white on `#C9922A` (gold buttons, 3 places) | 2.75:1 | FAIL |
| `text-white/50` at 10px on the band | 3.65:1 | FAIL |

So this is a legibility fix as much as a brand one — which matters, because the
page is used **one-handed, on a phone, in bad light, by someone wearing gloves**.

## Constraints

- **Scope is the survey link only.** `resources/views/worksheets/public-show.blade.php`
  is a separate job and must stay byte-identical.
- **The three sha256-pinned files** (`layouts/app.blade.php`, `resources/css/app.css`,
  `tailwind.config.js`) stay byte-identical to `4abd2b24`. A mismatch is a STOP.
- **No JavaScript in the cockpit region.** The fence bans `<script` and nine
  handler attributes and `<select`; counts stand at 2 / 21 / 9 / 13.
- **Colour means status.** The user's other standing complaint is premature
  colour on status tables. Brand the chrome; leave status colour to mean status.
- **Measure every pairing.** No shipped ratio is assumed.
- Escape every echo — this is an unauthenticated public page. Never render
  `captured_by`, `ip_address` or `user_agent`.

## Tasks

1. Verify the three pins and baseline `tests/Feature/Cockpit` + `tests/Unit/Cockpit`
   BEFORE touching anything.
2. Decide the select-all mechanism against the no-JS fence; implement or report
   that the status quo is better.
3. Rebrand the survey link from the repo's own brand source of truth, computing
   every foreground/background ratio shipped.
4. Render every state of both surfaces and assert on each.
5. Re-run the full gate set, one suite per invocation.

## Success criteria

- A no-JavaScript select-all exists, or its absence is honestly argued.
- Every shipped pairing clears 4.5:1 for small text and 3:1 for non-text marks.
- No decorative colour added to any status-bearing surface.
- `worksheets/public-show.blade.php` untouched; the three pins unchanged.
- cockpit 506+/0, `tests/Feature/Worksheets` 263, `-Filter Survey`,
  `tests/Feature/Documents` 18, D-06 baseline `>= 159 passed AND 0 failed`.
