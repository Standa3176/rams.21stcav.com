---
name: 260726-rf2-rams-output-quality
status: complete
started: 2026-07-26
branch: feat/worksheet-classifier-universal
scope: 4 output-quality fixes flagged by user in Tilda RAMS Rev 1.0 PDF review
---

## Trigger

User inspected the freshly-generated Tilda RAMS (21CQ29531-05-OPS,
project 88, record_id 92) and flagged 4 issues visible in the PDF.

## Tasks

### Task 1 — Bump MethodStatementPrompt::maxTokens 3500 → 8000

Prod log: `Claude response was truncated (max_tokens reached)`. Both
attempts truncated → static 5-phase fallback fired → generic method
statement shipped instead of the per-room / kit-specific narrative
from 260725-rd1 Task 3.

### Task 2 — Split client contact name + email onto two lines

`resources/views/pdf/rams.blade.php:365` concatenates with " | ".
Rendered on both cover and emergency Site Contact rows.

### Task 3 — Segregate decommission / retained from new_install

`RamsDisplayPatchService` funnels every hardware row into
`scope_items['new_install']`. PDF template correctly renders 3 buckets
but the data isn't being routed.

### Task 4 — Extract parenthesised room suffix into Room column

Six Crestron 10.1" scheduling panels on Tilda page 6 show room blank
because QW puts the room name in "(Vanilla)" / "(Poppy)" / etc.
trailing parens where section-header grouping doesn't apply.

## Non-goals

- Per-room prompt chunking (smarter fix for Task 1 that would let us
  drop the ceiling back down) — deferred pending real telemetry.
- Fixing "Existing" prefix in the review-form equipment list — the
  routing happens at display-patch time; QW extract still shows the
  full description with prefix so PMs can see what's being decommed.
- Vehicle Regs blank — my 260726-fx5 mirror only surfaces if
  `programme.site_vehicles` was populated. Project 88 hasn't got any
  yet; user confirmed no fix needed for now.

## Commits (target)

1. `fix(rams): bump MethodStatementPrompt maxTokens 3500 → 8000`
2. `fix(rams-pdf): render client contact as name + email on separate lines`
3. `fix(rams): segregate decommission/retained from new_install + extract parenthesised room suffix`

## Test plan

- Manual: regenerate Tilda RAMS. Confirm all 4 fixes visually in the PDF.
- Unit: existing MethodStatement filter (46 tests) still green.
- Lint: `php -l` on 3 touched files.

## Deploy

`git pull && optimize:clear && config:cache && queue:restart`.

**`queue:restart` is mandatory** — the BuildRamsDocumentJob worker
holds classes in memory (bit us in 260726-fx5 hotfix).
