---
phase: 45-visit-model-read-only-cockpit
plan: 09
subsystem: presentation-tokens
tags: [design-tokens, accessibility, d-08, values-only]
requires: []
provides:
  - "cockpit-scoped --cav-* tokens resolving to the app's accent-blue family and Inter"
  - "VIS-10 restated as the scoping mechanism, with a dated D-08 amendment"
  - "D-08..D-16 recorded in 45-CONTEXT.md in the gate-recognised form"
affects:
  - "Plans 45-10..45-14 — they now render against settled colour"
  - "Phases 46-51 — they inherit this token set, not the superseded teal one"
tech-stack:
  added: []
  patterns:
    - "class-scoped CSS custom properties (.cav-brand), never the document-root selector"
    - "palette literals copied from the app by line number, not sampled from a screenshot"
key-files:
  created:
    - .planning/phases/45-visit-model-read-only-cockpit/45-09-SUMMARY.md
  modified:
    - resources/css/cav-tokens.css
    - .planning/REQUIREMENTS.md
    - .planning/phases/45-visit-model-read-only-cockpit/45-CONTEXT.md
decisions:
  - "D-08 implemented as sketch README option 1 — retarget cav-tokens.css rather than delete it, keeping the scoping mechanism that is the only genuinely valuable output of 45-03"
  - "All 29 token names retained byte-for-byte; a rename fails silently in cockpit.css"
  - "--cav-radius 3px -> 10px and --cav-radius-sm 2px -> 6px, to match the soft cards sketch 004 renders"
  - "@fontsource/poppins left installed and unused — removing it is a lockfile change plus a rebuild, out of scope"
  - "VIS-10 not marked complete — it spans plans 45-03/45-06/45-09 and the cockpit views are not built yet"
metrics:
  duration: ~35 min
  completed: 2026-09-20
  tasks: 2
  commits: 2
---

# Phase 45 Plan 09: Retarget cockpit tokens to the app's blue and Inter — Summary

Reversed D-07 as a values-only change: `cav-tokens.css` now resolves to the application's own
electric-blue accent family and Inter, sourced from `layouts/app.blade.php` by line number, with
the `.cav-brand` scoping class, all 29 token names and the `@import` chain into `cockpit.css`
untouched — and the three sha256-locked shared-presentation files still byte-identical to
`4abd2b24`.

## Tasks

| # | Task | Commit |
|---|------|--------|
| 1 | Retarget `cav-tokens.css` to the app's blue and Inter | `04d4a9d8` |
| 2 | Amend VIS-10, record D-08..D-16 | `59773e20` |

## What changed

### Task 1 — `resources/css/cav-tokens.css`

Structure, selector, filename and every token **name** are unchanged. Verified mechanically:

```
git show HEAD:./resources/css/cav-tokens.css | grep -oE '^ +--cav-[a-z0-9-]+:'  ... sorted
grep -oE '^ +--cav-[a-z0-9-]+:' resources/css/cav-tokens.css                    ... sorted
diff  ->  identical, 29 tokens
```

That check matters more than it looks: a token **rename** would not error. The `var()` in
`cockpit.css` would fall back to an inherited value and the page would render in the app's defaults
*by accident* rather than *by decision* — indistinguishable from success until someone looks
closely (threat `T-45-09-01`).

#### Retargeted values, with their source in the app

Every colour is copied from the application, **not** sampled from the design screenshot, so the
cockpit matches the app exactly rather than approximating it.

| Token | Was (D-07) | Now | Source |
|---|---|---|---|
| `--cav-teal` | `#01889F` | `#2E7BFF` | `--accent-600`, `app.blade.php:57` (= `tailwind.config.js:60` `brand.teal`) |
| `--cav-teal-dark` | `#016E82` | `#1E5FE0` | `--accent-700`, `app.blade.php:56` |
| `--cav-teal-soft` | `#E6F4F7` | `#DCE9FF` | `--accent-100`, `app.blade.php:59` |
| `--cav-ink` | `#1A1A1A` | `#0F172A` | `--ink-900`, `app.blade.php:37` |
| `--cav-mid` | `#555555` | `#334155` | `--ink-700`, `app.blade.php:38` |
| `--cav-light` | `#767676` | `#64748B` | `--ink-500`, `app.blade.php:39` |
| `--cav-bg` | `#FAFAFA` | `#F7F9FC` | `--paper`, `app.blade.php:46` |
| `--cav-surface` | `#FFFFFF` | `#FFFFFF` | unchanged |
| `--cav-rule` | `#ECECEC` | `#F1F5F9` | `--ink-100`, `app.blade.php:43` |
| `--cav-border` | `#E0E0E0` | `#E2E8F0` | `--ink-200`, `app.blade.php:42` |
| `--cav-focus` | `#016E82` | `#1E5FE0` | `--accent-700`, `app.blade.php:56` |
| `--cav-gold` | `#D4AF37` | `#F59E0B` | `--signal-warning`, `app.blade.php:84` |
| `--cav-gold-soft` | `#FAF4E4` | `#FEF3C7` | `--warning-light`, `app.blade.php:141` |
| `--cav-gold-ink` | `#8A6D13` | `#92400E` | amber status-badge ink, `app.blade.php:457` |
| `--cav-wait` | `#767676` | `#64748B` | `--ink-500`, `app.blade.php:39` |
| `--cav-inferred` | `#C9C9C9` | `#CBD5E1` | `--ink-300`, `app.blade.php:41` |
| `--cav-chip-bg` | `#F3F3F3` | `#F1F5F9` | `--ink-100`, `app.blade.php:43` |
| `--cav-destructive` | `#B3261E` | `#991B1B` | red status-badge ink, `app.blade.php:459` |
| `--cav-font-head` | Verdana stack | `'Inter Variable', 'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif` | `--font-body`, `app.blade.php:150` |
| `--cav-font-body` | Poppins stack | same Inter stack | `--font-body`, `app.blade.php:150` |
| `--cav-radius` | `3px` | `10px` | sketch 004 renders soft cards |
| `--cav-radius-sm` | `2px` | `6px` | sketch 004 chips / icon tiles |
| `--cav-s1..s7` | 4..48px | **unchanged** | re-deriving spacing would have made the diff unreviewable |

The gold family did not disappear — its three token **names** survive because `cockpit.css`
references `--cav-gold` and `--cav-gold-ink`. It **narrowed** to the single amber stage-chip
sketch 004 shows ("Installation phase"), and now carries the app's own amber rather than a brand
gold.

Both font tokens collapse onto one stack: sketch 004 uses a single family and carries hierarchy on
weight and size, not on a second face. **No font is loaded by the cockpit** — Inter Variable is
already self-hosted app-wide by `resources/css/app.css:6`, so the cockpit inherits a warm cache.

#### Contrast ledger — recomputed for the new palette

WCAG 2.1 relative-luminance method. Floor: **body text ≥ 4.5:1**, **non-text marks ≥ 3:1**.

**Body / small text — all PASS**

| Foreground | Background | Ratio | Result |
|---|---|---|---|
| `--cav-ink` `#0F172A` | `--cav-surface` `#FFFFFF` | **17.85:1** | PASS |
| `--cav-ink` `#0F172A` | `--cav-bg` `#F7F9FC` | **16.93:1** | PASS |
| `--cav-mid` `#334155` | `#FFFFFF` | **10.36:1** | PASS |
| `--cav-mid` `#334155` | `--cav-bg` `#F7F9FC` | **9.82:1** | PASS |
| `--cav-mid` `#334155` | `--cav-chip-bg` `#F1F5F9` | **9.45:1** | PASS |
| `--cav-light` `#64748B` | `#FFFFFF` | **4.76:1** | PASS |
| `--cav-light` `#64748B` | `--cav-bg` `#F7F9FC` | **4.51:1** | PASS (thin) |
| `--cav-teal-dark` `#1E5FE0` | `#FFFFFF` | **5.57:1** | PASS |
| `#FFFFFF` | `--cav-teal-dark` `#1E5FE0` | **5.57:1** | PASS |
| `--cav-teal-dark` `#1E5FE0` | `--cav-teal-soft` `#DCE9FF` | **4.55:1** | PASS (thin) |
| `--cav-gold-ink` `#92400E` | `#FFFFFF` | **7.09:1** | PASS |
| `--cav-gold-ink` `#92400E` | `--cav-gold-soft` `#FEF3C7` | **6.37:1** | PASS |
| `--cav-destructive` `#991B1B` | `#FFFFFF` | **7.72:1** | PASS (unused in 45) |

**Non-text marks — all PASS**

| Mark | Background | Ratio | Result |
|---|---|---|---|
| `--cav-teal` `#2E7BFF` (status dot, ring arc, row spine) | `#FFFFFF` | **3.89:1** | PASS (fails the 4.5 text floor — marks only) |
| `--cav-wait` `#64748B` (hollow pip, "Not started" dot) | `#FFFFFF` | **4.76:1** | PASS |
| `--cav-gold` `#F59E0B` (amber chip dot/edge) | `#FFFFFF` | **3.19:1** | PASS |
| `--cav-focus` `#1E5FE0` (2px ring, 2px offset) | `#FFFFFF` | **5.57:1** | PASS |
| `--cav-border` `#E2E8F0`, `--cav-rule` `#F1F5F9`, `--cav-inferred` `#CBD5E1` | `#FFFFFF` | ~1.1–1.3:1 | decorative — state is carried by chip + count + text, so WCAG 1.4.11 is not engaged |

Two rows are **thin passes** and are annotated in the file itself so a future edit cannot drift
them below the floor silently: `--cav-light` on the canvas (4.51:1) and `--cav-teal-dark` on
`--cav-teal-soft` (4.55:1, the "In progress" / "On file" chip).

One pairing is recorded as a **FAIL that the tokens forbid**: `--cav-light` `#64748B` on
`--cav-chip-bg` `#F1F5F9` is **4.34:1**. Chip text is therefore `--cav-mid`, never `--cav-light` —
the same trap the D-07 ledger caught with different numbers.

The D-07 gold `#D4AF37` was 2.10:1 on white and needed a mandatory 1.5px outline to be legible as
a mark at all. `#F59E0B` clears the 3:1 non-text floor unaided, so that outline is no longer
load-bearing — though shape and text still carry the meaning, per the rule below.

**Both hard-won rules survive verbatim in the file's docblock:**
1. **Opacity is never applied to an element containing small text** — `#767676` at 0.7 blends to
   3.03:1 and FAILS.
2. **State is never carried by colour alone** — shape, text and position carry it too, so a
   greyscale screenshot still reads.

#### Docblock rewritten

It previously justified a 21CAV brand refresh. It now records: that D-08 reverses D-07; that
sketch 004 renders the cockpit in the app's own palette inside the real app shell; that `--cav-`
is retained for mechanical reasons and now means **"cockpit-scoped", not "CAV brand"**; and that
**the scoping class is the load-bearing part, not the values** — a token added to the layout's root
block at `app.blade.php:33` would retone every authenticated page and break ROADMAP criterion 4.

### Task 2 — VIS-10 and the decision record

`VIS-10` previously stated a palette *and* a constraint. D-08 falsified the palette half; the
constraint half is the single most important rule in the phase. It now states the **mechanism**:
tokens under a scoping class, never on the shared layout's root block, with the three
shared-presentation files byte-identical to `4abd2b24` (asserted by sha256). A dated amendment note
sits directly beneath it — *"Amended 2026-09-20 by D-08 (sketch 004 accepted); the original
teal/gold/Verdana/Poppins palette is superseded, the scoping constraint is not."* — so the reversal
reads as history rather than as a silent rewrite. The traceability row gained
"palette retargeted by Plan 45-09 (D-08)".

`45-CONTEXT.md` gained `## Decisions — sketch 004 (2026-09-20)`, preceded by one line stating that
D-01..D-06 still hold unchanged and D-07 is REVERSED by D-08, then **D-08 through D-16 copied
verbatim** from the sketch README. D-07 itself is left in place with a REVERSED banner pointing
forward, so a reader who lands on it first is not misled.

## Deviations from Plan

### Auto-fixed issues

**1. [Rule 3 - Blocking] Task 1's verify gate greps the whole file, including prose**

- **Found during:** Task 1
- **Issue:** the verify asserts the file contains no `:root`, `Verdana`, `Poppins`, `01889F`,
  `016E82` or `D4AF37` **anywhere** — including comments. The plan simultaneously requires the
  docblock to explain that tokens must never reach the document-root selector and that the
  superseded palette is superseded. Writing that in plain English fails the gate. The **pre-existing**
  file failed it too, for the same reason.
- **Fix:** the prose was reworded to say the same things without the literals — "the document-root
  selector", "the layout's own root block at `app.blade.php:33`", "the D-07 heading face / body
  face", "its npm package (named in 45-09-SUMMARY.md)". The constraint is stated at least as
  forcefully as before; only the tokens the guard greps for were removed. The superseded hex values
  and font names are recorded **here** instead, and the docblock says so and says why.
- **Files modified:** `resources/css/cav-tokens.css`
- **Commit:** `04d4a9d8`

**2. [Rule 2 - Missing critical] D-07 left unmarked in `45-CONTEXT.md`**

- **Found during:** Task 2
- **Issue:** the plan specifies one line above the new section saying D-07 is reversed. A reader
  arriving at the `### Brand` heading — where D-07 lives, ~30 lines earlier — would have read live
  guidance to build the cockpit in teal with no signal that it had been overturned.
- **Fix:** a blockquote banner directly above D-07 marking it REVERSED by D-08 and pointing to the
  new section. D-07's own text is untouched, as the record of what was decided and shipped in 45-03.
- **Commit:** `59773e20`

**3. Scope note — the plan's D-08..D-15 is D-08..D-16 in the README**

The plan objective says D-08..D-15; the sketch README defines **D-16** as well (the nine-module
ruling, which amends D-11). D-16 was included — omitting it would have left D-11's own
"(AMENDED by D-16)" marker dangling at a decision that does not exist in the phase context. The
verification loop was run over 8..16.

**4. Formatting note — parenthetical qualifiers moved after the colon**

The README writes `- **D-08 (REVERSES D-07):**`, `- **D-11 (AMENDED by D-16):**` and
`- **D-16 (2026-09-20, user ruling):**`. The coverage gate matches only `**D-NN:**`, so those three
were rendered as `- **D-08:** (REVERSES D-07) …`. Wording is otherwise verbatim; no decision text
was restated or reinterpreted.

## Deferred Issues

- **`@fontsource/poppins` is now an unused dependency.** Deliberately left installed — removing it
  is a `package.json` + lockfile change plus a rebuild, out of scope for a values-only plan
  (threat `T-45-09-SC`, disposition *accept*).
- **`resources/css/cockpit.css:46-47` still `@import`s `@fontsource/poppins/400.css` and
  `600.css`.** No token references Poppins any more, so the face is loaded and never used — dead
  weight in the per-page bundle, not a rendering defect. `cockpit.css` is **not** in this plan's
  `files_modified` and the whole point of the plan is that it is values-only, so it was left alone.
  Remove those two lines in whichever later plan next edits `cockpit.css` (45-10/45-11 rebuild the
  cockpit layout against sketch 004), together with the now-stale rule-4 docblock note about
  Verdana weights at `cockpit.css:44-45`.
- **`cockpit.css` carries superseded hard-coded hexes in its comments and a few literals**
  (`#016E82`, `#D4AF37`, `#FAFAFA`, `#fcfcfc` at `:87-89`, `:197`, `:254`, `:264`, `:629`). Same
  reasoning — it is the next plan's file.
- **VIS-10 is not marked complete.** It spans plans 45-03 / 45-06 / 45-09 and the cockpit views do
  not exist yet, so `requirements.mark-complete VIS-10` was deliberately **not** run. Marking it
  here would claim delivery of a page that is still 404ing behind its flag.

## Verification

| Check | Result |
|---|---|
| Task 1 gate (`:root` / `Verdana` / `Poppins` / `01889F` / `016E82` / `D4AF37` absent; `.cav-brand` present) | **PASS** |
| Task 2 gate (D-08..D-16 in `- **D-NN:**` form; `Amended 2026-09-20` in REQUIREMENTS.md) | **PASS** (re-run from a `.ps1` file — the Bash tool strips `$` sigils from `powershell -Command`, which silently mangled the first attempt into a meaningless `PASS`) |
| Token names identical to `HEAD` | **PASS** — 29/29, `diff` empty |
| `git diff --name-only 4abd2b24 -- app.blade.php app.css tailwind.config.js` | **empty — PASS** |
| `Get-FileHash -Algorithm SHA256` on the three protected files | **all three match 45-BASELINE.md exactly** |
| `artisan test tests/Feature/Cockpit/FlagOffBehaviourUnchangedTest.php` | **11 passed (66 assertions)**, including all three byte-identity data sets |

Protected-file hashes, working-tree bytes, compared like-with-like per the baseline's CRLF warning:

```
9ED63C4C754F33832E12BB07A2C515AAC82AB656C5C9A1D35AED0174A7FF0557  resources/views/layouts/app.blade.php
EDAD1982303B9FABF86A4B791BBF16436104251277CA79DBAEFB5603C8BE2133  resources/css/app.css
73BB8AD6B7CDF4DC0B11C51661E3DBC7A274CABBCC226D1FF41B5E50938E74BB  tailwind.config.js
```

No migration was run. No package was installed. `.planning/STATE.md` was not touched — neither
`state.advance-plan` nor `state.update-progress` was invoked.

## Known Stubs

None. This plan ships no markup and no data path.

## Threat Flags

None. No route, input, query or trust boundary is touched.

## Self-Check: PASSED

All four files exist on disk; both task commits (`04d4a9d8`, `59773e20`) are present in
`git log --all`. No tracked file was deleted by either commit. `.planning/STATE.md` is clean.
