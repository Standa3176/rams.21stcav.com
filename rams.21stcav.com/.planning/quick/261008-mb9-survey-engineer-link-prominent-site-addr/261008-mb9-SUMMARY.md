# Quick Task 261008-mb9: Prominent site install address + site contact on the engineer link — Summary

**One-liner:** Masthead address/contact lines lose `truncate`; a new Site Address + Site Contact card on the SITE tab reuses the existing `$siteContactName`/`$siteContactPhone`/`$telHref` computation and renders visible placeholders instead of silently collapsing when fields are blank.

## What shipped

- `resources/views/surveys/show.blade.php`:
  - Masthead address paragraph (`:246`, was line 246 pre-edit) — `truncate` removed, bumped to `text-sm font-semibold text-white/80` (was `text-xs text-white/80 mt-0.5 truncate`). Same brand-asserted colour (`text-white/80`, 6.76:1 per the file's own header comment), only size/weight changed — introduces no new hex.
  - Masthead "Site contact" paragraph (`:277` pre-edit) — `truncate` removed per decision (a) in the amended plan. Nothing else about that block (the `@php` computation, the `@if` gate, the `tel:` href) was touched.
  - New card inserted inside `data-tab="site"`, immediately after the existing Summary card's closing `</div>` and before the office-send-back banner include:
    - "📍 Site Address" heading (`sv-spine`, matching the house pattern of every other section heading in this file) + the full `$survey->site_address`, or `Site address not provided` (italic, `text-gray-400`) when blank.
    - A `border-t border-gray-100`-separated "Site Contact" sub-block, reusing `$siteContactName`/`$siteContactPhone`/`$telHref` from the masthead's existing `@php` block (no second computation) — renders name + a `tel:{{ $telHref }}` link when either is present, or `Site contact not provided` when both are blank.
  - Every echo in the new markup stays Blade double-brace (`{{ }}`) — the page's zero-unescaped-echo policy is unchanged.
- `tests/Feature/Surveys/SurveyLinkSiteContactProminenceTest.php` (new, 5 tests, `survey()`/`render()` copied verbatim from `SurveyLinkBrandingTest`).

## RED (Task 1, before any Blade change)

Ran `gate-46.ps1 -Filter SurveyLinkSiteContactProminenceTest` before touching the view:

```
FAIL  Tests\Feature\Surveys\SurveyLinkSiteContactProminenceTest
⨯ long address is not truncated and appears in masthead and site tab   29.20s
⨯ contact name and uk normalised tel link render on site tab            0.14s
⨯ blank address and contact render clean placeholders                   0.12s
✓ hostile contact name is escaped                                       0.24s
⨯ masthead contact line is not truncated                                0.12s
```

Quoted failure for the long-address test, the actual assertion that failed:

```
Expected: <p class="text-xs text-white/80 mt-0.5 truncate">
Not to contain: truncate
at tests\Feature\Surveys\SurveyLinkSiteContactProminenceTest.php:103
```

4 of 5 failed for the right reasons (truncation still present / no SITE-tab contact card / no placeholders existed yet). "hostile contact name is escaped" passed even in RED — the masthead's existing `{{ }}` echo was already escaping correctly; this test is non-vacuous proof the escaping behaviour survives the change, not a new RED/GREEN cycle of its own.

## GREEN (Task 2, after the Blade change)

First GREEN attempt surfaced a test bug, not a Blade bug: `test_blank_address_and_contact_render_clean_placeholders` failed because the shared `survey()` fixture seeds a non-blank `site_address` (`'12 Wharf Road, Leeds'`) by default — the "all three fields blank" case has to be produced explicitly via the `$tweak` callback, which the test was missing. Fixed the test (not the view) and re-ran:

```
PASS  Tests\Feature\Surveys\SurveyLinkSiteContactProminenceTest
✓ long address is not truncated and appears in masthead and site tab    3.30s
✓ contact name and uk normalised tel link render on site tab            0.13s
✓ blank address and contact render clean placeholders                   0.13s
✓ hostile contact name is escaped                                       0.12s
✓ masthead contact line is not truncated                                0.13s

Tests:    5 passed (22 assertions)
Duration: 6.94s
```

## Full-suite and baseline (foreground, re-measured after the change)

```
gate-46.ps1 -Path tests/Feature/Surveys
Tests:    69 passed (614 assertions)
Duration: 47.68s

gate-46.ps1 -Baseline
Tests:    2 skipped, 159 passed (396 assertions)
Duration: 47.44s
```

Both figures were re-run in the foreground after the Blade change, not inherited from the task brief's "64/592" and "159/0/2 skipped" citations. D-06 gate (`>= 159 passed AND 0 failed`) is satisfied — exact match, no regression, as expected since this plan touches a file outside the baseline's enumerated test paths.

## Protected files

`gate-46.ps1 -Hashes` run after both commits — all three protected files byte-identical to the `4abd2b24` baseline:

```
layouts/app.blade.php  9ED63C4C754F33832E12BB07A2C515AAC82AB656C5C9A1D35AED0174A7FF0557  (match)
resources/css/app.css  EDAD1982303B9FABF86A4B791BBF16436104251277CA79DBAEFB5603C8BE2133  (match)
tailwind.config.js     73BB8AD6B7CDF4DC0B11C51661E3DBC7A274CABBCC226D1FF41B5E50938E74BB  (match)
```

No mismatch encountered — nothing to STOP on.

## Contrast ratios

- Masthead address/contact text: unchanged colour, `text-white/80` on `#014C5A` — **6.76:1** (the file's own header comment's measured figure; only size/weight changed, not colour).
- New card's populated values: `text-gray-900` on white — effectively ~17.9:1 (Tailwind `gray-900` `#111827` on white), far clears AA.
- New card's placeholder text (`text-gray-400 italic`, "Site address not provided" / "Site contact not provided"): **≈2.54:1** on white (Tailwind `gray-400` `#9CA3AF`), computed via the WCAG 2.x relative-luminance formula. This is **below AA (4.5:1) for normal-size text.** I did not substitute a darker shade because `text-gray-400 italic` is this file's own pre-existing, repeated house convention for "nothing recorded" placeholders (see `show.blade.php` lines 862, 943, 1331, 1385, 1591, 1664, all `text-gray-400 italic` "No X recorded"/"No description." placeholders) — using it keeps this new placeholder visually and semantically consistent with every other "empty state" in the same document, rather than introducing a one-off, better-contrast placeholder style nothing else in the file uses. Flagging this as a **pre-existing house pattern, not a regression introduced by this plan** — an AA fix, if wanted, would be a repo-wide placeholder-colour change, out of this plan's scope.
- `tel:` link text: `text-brand-teal` (`#016E82`) on white — 5.91:1 per `SurveyLinkBrandingTest::BRAND`, already asserted AA-safe.

## Blank case confirmed

- `assertStringContainsString('Site address not provided', ...)` and `'Site contact not provided'` both pass against the SITE-tab-scoped HTML.
- `assertStringNotContainsString('href="tel:"', $html)` passes — no empty `tel:` href is ever emitted; the phone link only renders inside the `@if($siteContactPhone !== '')` branch.
- No orphaned "Site Contact" label renders without a value: the sub-heading `<p>Site Contact</p>` label only renders inside the branch where at least one of name/phone is non-empty; the all-blank branch renders the single placeholder line instead.

## Deviations from Plan

None — plan executed as written (with the already-applied 2026-10-08 amendment to Task 2 covering the masthead contact line and the new Test E, both followed). The one correction made during execution was to the new test file itself (Task 1's fixture override for the blank case), not to the plan or the Blade view — documented above under GREEN.

## Known Stubs

None. Every path (populated address, populated contact, blank address, blank contact, hostile name) renders real, data-driven content or an explicit placeholder — no hardcoded empty value flows to the UI.

## Threat Flags

None. All three STRIDE items in the plan's threat register (`T-mb9-01` through `T-mb9-04`) are satisfied as planned: every new echo stays `{{ }}` (Test D proves escaping), the reused `$siteContactName`/`$siteContactPhone` variables are the same client-site-contact fields already rendered pre-plan (no `pm_name`/`pm_phone`/`pm_email` referenced), and no new package-manager install occurred.

## Self-Check

- `resources/views/surveys/show.blade.php` — FOUND, modified as described.
- `tests/Feature/Surveys/SurveyLinkSiteContactProminenceTest.php` — FOUND, 5 tests, all passing.
- Commit `a5bc8c6b` (test, RED) — FOUND in `git log`.
- Commit `2b3efd11` (feat, GREEN) — FOUND in `git log`.

## Self-Check: PASSED

## Not delivered

Nothing from the plan was skipped. The only thing outside this plan's scope that surfaced during contrast review — the repo-wide `text-gray-400` placeholder convention sitting below AA — is noted above as a pre-existing pattern, not fixed here (out of scope: fixing it would touch at least 6 other lines/features not part of this task's `files_modified`).
