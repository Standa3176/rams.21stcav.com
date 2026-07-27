---
name: 260727-fx6-taxonomy-unblock-tilda
status: pending
scope: Config-file additions to unblock Tilda worksheet (project 88, worksheet 14). Throwaway — rules get ported into DB seed at phase 260727-wt1 Plan 01.
estimated: 30 mins
depends_on: none (independent — runs before phase 260727-wt1 or in parallel)
---

## Trigger

Tilda worksheet (project 88, worksheet 14) rendered fragmented — 19
items skipped from the kit list because the current Crestron
manufacturer rule in `config/worksheet_taxonomy.php` doesn't cover the
scheduling-panel / Automate VX / AirMedia / SurgeX / IV-CAMERA-4K-PTZ
families.

## Why throwaway

Phase 260727-wt1 (RAMS taxonomy catalogue and learning) moves this
data to a DB table with review-page write-through. The config
additions in this task will be **ported into the Plan 01 DB seeder**
and become dead code when Plan 02 flips the kill switch. Kept as a
quick task because Tilda can't wait for the 2-day phase to land.

## Fix scope

Extend `config/worksheet_taxonomy.php`:

1. **Crestron manufacturer rule (line 121)** — add keywords:
   - `scheduling`, `touch screen`, `room booking`, `tss-`, `tsw-`
     (scheduling panels → `control`)
   - `airmedia`, `am-3100`, `am-tx3` (AirMedia → `control`)
   - `automate vx`, `automeasure`, `voice-activated camera` (VX
     switcher accessories → `video_conferencing`)
   - `camera`, `ptz`, `1 beyond`, `p20`, `p12` (Crestron cameras →
     `video_conferencing`, split from control rule if needed)
   - `multisurface mount kit`, `mount kit` (→ `mount_inherit`)

2. **New manufacturer rule** for SurgeX / TrippLite / APC power
   conditioning:
   - Manufacturer: `surgex`, `tripp lite`, `tripplite`, `apc`
   - Keywords: `surge protector`, `power conditioner`, `sequencing`,
     `pdu`
   - Category: `rack`

3. **Tier 3 keyword extensions:**
   - Add `room scheduling`, `booking panel`, `scheduling touch` →
     `control`
   - Add `wireless presentation`, `byod`, `airmedia` → `control`

## Tasks

### Task 1 — Config edits

Edit `config/worksheet_taxonomy.php` per fix scope above. Single
commit.

### Task 2 — Regression test

Run existing worksheet tests. Add `WorksheetTaxonomyTest` cases
covering the 19 Tilda unclassified items — each should classify to a
non-sentinel category.

### Task 3 — Regenerate Tilda worksheet

Local: run `php artisan tinker` → `\App\Jobs\BuildWorksheetJob::dispatchSync(...)` for worksheet 14 or equivalent. Compare against
`worksheet_14_20260727_094120_251765 (1).docx` — QA warnings section
should shrink from 19 items → 0 (or close to it).

Live: after commit, PM regenerates via UI.

## Constraints

- Config edit only — no code changes.
- No taxonomy category additions — every new rule maps to one of the
  6 canonical categories.
- Tests + `php -l` clean.

## Commits (target)

1. `fix(worksheet): extend taxonomy for Crestron/SurgeX/AirMedia families (260727-fx6)`
2. `test(worksheet): coverage for the 19 previously-unclassified Tilda items (260727-fx6)`

## Deliverable check

At close:
- Tilda regenerate: 0 unclassified items in QA warnings.
- Existing worksheet suite green.

## Deprecation note

When phase 260727-wt1 Plan 01 seeds the DB catalogue from this config,
these rules get ported in. When Plan 02 flips
`WORKSHEET_TAXONOMY_DB=true` on live, these config additions become
inert. When the post-phase quick task
`260803-wt1-remove-config-fallback` lands, the entire
`config/worksheet_taxonomy.php` file is demoted to a comment-only
reference or deleted.
