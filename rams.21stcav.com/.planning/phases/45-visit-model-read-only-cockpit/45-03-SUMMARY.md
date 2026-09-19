---
phase: 45-visit-model-read-only-cockpit
plan: 03
subsystem: cockpit-foundation
tags: [feature-flag, design-tokens, css, fonts, vite]
requires: [45-01]
provides:
  - "config('cockpit.enabled') — the cockpit kill-switch, default FALSE"
  - "resources/css/cav-tokens.css — the --cav-* brand palette on .cav-brand"
  - "resources/css/cockpit.css — .cav-cockpit layout, imports the tokens + Poppins"
  - "vite input 'resources/css/cockpit.css' (per-page bundle)"
  - "self-hosted Poppins 400/600 via @fontsource/poppins"
affects: [45-06, 45-08, 46, 47, 48, 49, 50, 51]
tech-stack:
  added: ["@fontsource/poppins@5.3.0 (OFL-1.1, no scripts, no dependencies)"]
  patterns:
    - "tokens on a CLASS (.cav-brand), never :root — opt-in theming"
    - "two-file split: palette file + layout file, joined by @import"
    - "flag default FALSE + a test that reads the config FILE, not config()"
key-files:
  created:
    - config/cockpit.php
    - tests/Unit/CockpitFlagDefaultTest.php
    - resources/css/cav-tokens.css
    - resources/css/cockpit.css
  modified:
    - .env.example
    - vite.config.js
    - package.json
    - package-lock.json
decisions:
  - "COCKPIT_ENABLED defaults false and the deploy order is written into config/cockpit.php"
  - "cav-tokens.css and cockpit.css stay two files so phases 46-51 can take the palette alone"
  - "@fontsource/poppins installed autonomously on the completed 45-RESEARCH.md audit"
metrics:
  duration: ~35 min
  completed: 2026-09-19
---

# Phase 45 Plan 03: Cockpit Flag, Brand Tokens and Stylesheet Summary

The cockpit's preconditions now exist without a single byte changing on any existing page: a
kill-switch that defaults false and documents its own five-step deploy order, the `--cav-*` brand
palette in a file of its own declared on `.cav-brand`, a `.cav-cockpit` stylesheet that imports
that palette plus self-hosted Poppins 400/600, and one new per-page Vite input.

## What Was Built

| Task | What | Commit |
|------|------|--------|
| 1 | `config/cockpit.php` + `.env.example` entry + `tests/Unit/CockpitFlagDefaultTest.php` | `56627da7` |
| 2 | `@fontsource/poppins` install, `cav-tokens.css`, `cockpit.css`, Vite input | `f0efa001` |

### Task 1 — the flag

`config/cockpit.php` returns `['enabled' => env('COCKPIT_ENABLED', false)]`. One global boolean, no
per-project/per-user/per-role scoping (45-CONTEXT.md, and every flag precedent in this app). The
block comment above it records **why** false, in this repo's own terms: the cockpit renders against
a `visits` table that is empty until `visits:backfill --apply` has run, so arming it in the same
deploy would show every PM an empty spine — the "content gate defaulting ON is a deploy-order trap"
failure already written down at `config/rams_tier1.php:119-134`. The deploy order is stated
explicitly: ship code (flag absent) → `visits:backfill` dry-run → `visits:backfill --apply` →
a separate one-line `.env` flip → `config:clear`.

`tests/Unit/CockpitFlagDefaultTest.php` (2 tests, 7 assertions, green) pins the default **without
touching `config()`**, which a `config:cache` artefact could poison:

1. a regex over the raw file source asserting the literal `env('COCKPIT_ENABLED', false)`, and
2. `require config_path('cockpit.php')` with `COCKPIT_ENABLED` scrubbed from `putenv`, `$_ENV` and
   `$_SERVER`, asserting the evaluated value is `false`.

A third assertion pins the shape (`['enabled']` only, boolean) so nobody quietly adds scoping.

### Task 2 — tokens, stylesheet, font, Vite

**`resources/css/cav-tokens.css`** — `.cav-brand` is the only selector in the file. **29 custom-property
declarations**: the 22 palette tokens from UI-SPEC's three tables (15 structural, 3 accent, 4
neutral-state) plus the 7-step spacing scale `--cav-s1`..`--cav-s7`. Every UI-SPEC override is
carried and annotated with its contrast ratio: teal `#01889F` is fills/marks only (4.18:1 fails AA
for text), all teal text and the masthead fill are `--cav-teal-dark #016E82`, `--cav-wait` is
`#767676` not the sketch's `#D8D8D8`, chip text is `--cav-mid` (6.72:1) not `--cav-light` (4.09:1,
fails), `--cav-light` is documented as white-surfaces-only.

**`resources/css/cockpit.css`** — in order: `@import '@fontsource/poppins/400.css'`,
`@import '@fontsource/poppins/600.css'`, `@import './cav-tokens.css'`, then all `.cav-cockpit`
layout. Everything is `cav-`-prefixed and scoped under `.cav-cockpit`, so no rule in it can reach an
existing page even if it were loaded there. Four states by shape (filled circle / gold diamond with
its mandatory 1.5px `#8A6D13` outline / hollow circle / 16px rounded square). Opacity appears only
on non-text marks. 13px floor throughout; summary padding computes to ≥44px. `:focus-visible` rings
on `<summary>` and links; `prefers-reduced-motion` kills the arrow transition. No button, form
control, panel, scrim or clickable-row styles exist — the read-only fence is enforced by absence.

**Vite** — `'resources/css/cockpit.css'` added as an input after `spike/main.jsx`, with a comment in
the file's existing style. `cav-tokens.css` is deliberately **not** an input: it is imported, not
entry-loaded.

## Verification

- `artisan test --filter=CockpitFlagDefaultTest` → **2 passed (7 assertions)**.
- `npm run build` → **`✓ built in 29.46s`**, emitting `public/build/assets/cockpit-BLrY6Sj8.css`
  (11.98 kB, gzip 2.54 kB) plus self-hosted Poppins 400/600 woff2+woff subsets. `manifest.json` CSS
  entries are exactly `resources/css/app.css` and `resources/css/cockpit.css` — `cav-tokens.css`
  correctly has no entry of its own.
- The built bundle contains one `.cav-brand{...}` block with all **29** declarations inlined, which
  proves the `@import` resolved at build time rather than becoming a runtime request.
- **The three protected files are byte-identical to 45-BASELINE.md** (`Get-FileHash -Algorithm
  SHA256` on working-tree bytes, i.e. like-for-like with how the baseline was taken):

  | File | SHA256 | Matches baseline |
  |---|---|---|
  | `resources/views/layouts/app.blade.php` | `9ED63C4C754F33832E12BB07A2C515AAC82AB656C5C9A1D35AED0174A7FF0557` | ✅ |
  | `resources/css/app.css` | `EDAD1982303B9FABF86A4B791BBF16436104251277CA79DBAEFB5603C8BE2133` | ✅ |
  | `tailwind.config.js` | `73BB8AD6B7CDF4DC0B11C51661E3DBC7A274CABBCC226D1FF41B5E50938E74BB` | ✅ |

  `git diff --name-only 4abd2b24 -- <those three>` is also empty.

## Package Legitimacy

`@fontsource/poppins@5.3.0` installed on the authority of **`45-RESEARCH.md` § Package Legitimacy
Audit** (performed 2026-09-19 via live `npm view`, verdict **[VERIFIED]**). Re-confirmed against the
installed tree after install: `version 5.3.0`, `scripts: null`, `dependencies: null`,
`license OFL-1.1` — i.e. no postinstall hook and zero transitive surface, same repository and
licence as the already-shipping `@fontsource-variable/inter`. The declared fallback (ship on the
`'Poppins','Segoe UI',Arial` chain; never a Google Fonts `<link>`) was **not needed**. `npm audit`
reports 20 pre-existing vulnerabilities in the tree; none is attributable to this package, which has
no dependencies, and they are out of scope for this plan.

## Deviations from Plan

### Auto-fixed issues

**1. [Rule 1 - Bug] Phone two-row summary would have overlapped count and status**

- **Found during:** Task 2, writing the `≤480px` block.
- **Issue:** the first draft placed both `.cav-count` and `.cav-status` in a single
  `grid-area: meta`, which stacks them in the same cell — they would have rendered on top of each
  other. That would have destroyed exactly the at-rest disclosure the user-signed-off deviation
  exists to protect.
- **Fix:** replaced the named-areas block with explicit per-child `grid-column`/`grid-row`
  placement over `16px minmax(0,1fr) auto`: row 1 = pip/tick + title + arrow, row 2 = count (start)
  + status (end). `minmax(0,1fr)` on the title keeps 320px free of horizontal scroll.
- **Files modified:** `resources/css/cockpit.css`
- **Commit:** `f0efa001`

### Documented findings (no code change)

**2. UI-SPEC says "Total: 21 tokens"; its three tables actually list 22.**

Structural 15 + accent 3 + neutral-state 4 = 22, not 21. Every token in the tables was written
(omitting one to hit a headline number would be strictly worse), plus the 7 spacing tokens, so
`cav-tokens.css` carries **29 `--cav-*` declarations**. The plan's gate (`>= 21`) passes either way.
Flagging it so 45-08 does not read 29-vs-21 as drift: the *arithmetic in the spec's summary line* is
off by one, the token set is complete and faithful.

**3. STATE.md / ROADMAP.md / REQUIREMENTS.md were intentionally not written by this plan.**

Plan 45-02 is executing concurrently in wave 2 and would write the same lines of the same files;
two agents updating the plan counter and the requirements table at once is a lost-update race, not
a merge. VIS-05 is also only partly delivered here — the cockpit view itself is Plan 45-06 — so
marking it complete now would be false. Left to the orchestrator to reconcile after the wave.

## Known Stubs

None. Nothing in this plan renders to a user: there is no route, no controller and no view (Plan
45-06 owns those, by design), so there is no surface that could display a placeholder. The
stylesheet is loaded by nothing yet, which is the intended end state of this plan.

## Threat Flags

None. No network endpoint, auth path, file-access pattern or schema change was introduced. The two
boundaries in the plan's register were both handled as specified: the npm dependency is audited and
carries no install-time script, and the `.env` → config boundary defaults closed with a test
pinning it.

## Self-Check: PASSED

- `config/cockpit.php` — FOUND
- `tests/Unit/CockpitFlagDefaultTest.php` — FOUND
- `resources/css/cav-tokens.css` — FOUND
- `resources/css/cockpit.css` — FOUND
- `public/build/assets/cockpit-BLrY6Sj8.css` — FOUND (build output, gitignored)
- commit `56627da7` — FOUND
- commit `f0efa001` — FOUND
