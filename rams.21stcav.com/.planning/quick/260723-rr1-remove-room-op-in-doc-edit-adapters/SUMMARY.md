---
task: 260723-rr1
title: Add remove_room op to Document Edit adapters (RAMS + Worksheet)
status: complete
date: 2026-07-23
branch: feat/worksheet-classifier-universal
commits:
  - 48ec6ac  # RAMS adapter
  - 6e103e6  # Worksheet adapter
tests: 19 unit / 40 assertions (RemoveRoom-specific) · 120/120 broader Adapter+DocumentEdit filter green
migrations: 0
deploy_steps:
  - git pull
  - php artisan optimize:clear
  - php artisan config:cache
---

## Fixed

The AI chat drawer on the RAMS review page (shipped as `b32c341` on 2026-07-19) opens correctly, receives instructions, confirms them, and regenerates the doc — but "exclude room X" never actually excluded the room.

Trace:
- User: "exclude Saffron room, will be done later"
- AI: mapped to `add_exclusion` — the closest verb it had access to
- `applyAddExclusion` appended `"Saffron"` to `reviewed_data.exclusions[]` (the scope-exclusions clause where you'd write "Cable containment" or "Ceiling grid removal")
- `reviewed_data.room_overviews[]` was left intact
- `RamsBuilderService::buildFromReview()` re-derived per-room content from `room_overviews[]` — Saffron reappeared everywhere

The fix teaches both adapters a first-class `remove_room` operation and — critically — the op's schema notes tell the AI parser to prefer `remove_room` over `add_exclusion` when user says "exclude / skip / don't include / remove X room".

## What changed

**`RamsEditAdapter`** (48ec6ac)
- New op `remove_room` in `allowedOperations()`
- Schema entry in `operationSchemas()` — args: `{room: string}`, notes explicitly warn against reaching for `add_exclusion`
- Match arm in `applyOperation()` dispatch
- `applyRemoveRoom($payload, $op)` — case-insensitive whitespace-tolerant filter on `reviewed_data.room_overviews[]` keyed on `room`. Idempotent (unknown room → `ok:true` no-op). Reindexes with `array_values`.

**`WorksheetEditAdapter`** (6e103e6)
- Same shape as RAMS, but filter target is `generated_data.rooms[]` keyed on `name` (not `room`) — matches the existing `indexRoomsByName` convention
- Adapter did not previously ship `operationSchemas()` — added a partial one containing only `remove_room` (factory tolerates missing schemas per `method_exists` check in `DocumentEditParsingPromptFactory`)

**Tests**
- `tests/Unit/Services/DocumentEdits/RamsEditAdapter_RemoveRoomTest.php` — 9 cases
- `tests/Unit/Services/DocumentEdits/WorksheetEditAdapter_RemoveRoomTest.php` — 10 cases
- Case coverage: exact match, case-insensitive, whitespace-tolerant, array reindex, empty-arg rejection, idempotent unknown-room retry, missing-key edge, schema presence
- Broader `--filter "EditAdapter|DocumentEdit"` run — 120/120 green, no regressions

## Explicit non-goals (deferred to future quick tasks)

- **O&M adapter** — no per-room structure to filter (contacts + maintenance items only)
- **Cable adapter** — cable rows have `from_location` / `to_location`, not room-level scope
- **Change-preview UX** — making the drawer diff say "Saffron removed, 4 rooms → 3 rooms" is worth doing but touches the drawer JS separately
- **`RoomOverviewSummaryService` case-sensitive room-name lookup** — same class of bug, different code path, tracked separately (raised in the same 2026-07-23 diagnostic thread)

## Deploy

- No migrations
- No npm build (pure PHP + a Blade component wasn't touched)
- Server: `git pull && php artisan optimize:clear && php artisan config:cache`
- Post-deploy sanity: pick a completed RAMS with ≥2 rooms, open chat drawer, "exclude <room name>, will be handled later." Change preview should list `remove_room`, apply, and regenerate to one fewer room in every section.

## Related

- **b32c341** — shipped the drawer mount on RAMS review that made this bug reachable
- **260713-sk1** — schematic editor discovery spike (unrelated)
