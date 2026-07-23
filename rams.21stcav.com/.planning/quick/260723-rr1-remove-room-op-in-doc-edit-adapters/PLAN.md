---
name: 260723-rr1-remove-room-op-in-doc-edit-adapters
description: Add `remove_room` operation to RamsEditAdapter + WorksheetEditAdapter so the AI-chat drawer can honour "exclude room X" instructions instead of silently mapping them to `add_exclusion`.
status: in-progress
tasks: 3
---

# Add `remove_room` op to Document Edit adapters

## Why

Diagnosed 2026-07-23: user typed "exclude Saffron room, will be done later" in the RAMS AI-chat drawer. AI acknowledged, proposed a change, applied it, regenerated the RAMS. Saffron still appeared in every section.

Root cause: **the RamsEditAdapter has no `remove_room` operation.** The AI matched user intent to the closest available op — `add_exclusion` — which just appends free text to the doc's **scope-exclusions clause** (where you'd normally write "Cable containment", "Ceiling grid removal"). It does NOT remove the room from `reviewed_data['room_overviews'][]`, so downstream generators (method statement, hazards, per-room equipment, works description) still include Saffron.

Same shape of bug on Worksheet — supports add_blocker / add_tool / update_room_summary etc., but no room-removal. O&M has no per-room structure (contacts + maintenance items only) and Cable is cable-level not room-level, so both are out of scope.

## What's changing

**Task 1 — RamsEditAdapter**
- Add `'remove_room'` to `allowedOperations()`.
- Add `remove_room` entry to `operationSchemas()` with `args: { room: 'string — the room name to exclude, case-insensitive match' }` and a `notes` line pointing at "use this instead of add_exclusion when the user says 'exclude Saffron', 'skip Saffron', 'don't include Saffron' etc — add_exclusion appends free text to the scope-exclusions clause, it does NOT remove the room from generation."
- Add `'remove_room' => $this->applyRemoveRoom($payload, $op)` case to the `applyOperation()` match.
- Implement `applyRemoveRoom()`: `array_filter($payload['reviewed_data']['room_overviews'], fn($r) => mb_strtolower(trim($r['room'] ?? '')) !== mb_strtolower(trim($op['room'] ?? '')))`, wrap in `array_values`, return `['ok' => true, 'payload' => $payload]`. Return `invalid_op` if `room` is empty. Return an idempotent success (no-op) if the named room isn't present — chat retries shouldn't error.
- Unit test `tests/Unit/Services/DocumentEdits/RamsEditAdapter_RemoveRoomTest.php`:
  - Exact-match removal filters the room out
  - Case-insensitive match works (`"SAFFRON"` request removes `"Saffron"` in data)
  - Whitespace-tolerant match works
  - Empty room arg returns `invalid_op`
  - Unknown room returns `ok: true` with unchanged payload (idempotent)
  - Removing one of many rooms leaves the others intact and reindexed (0-based array)

**Task 2 — WorksheetEditAdapter**
- Same shape as Task 1, but on `payload['rooms']` (Worksheet stores rooms directly under `generated_data['rooms'][]`, not under a `room_overviews` wrapper — see `WorksheetEditAdapter.php:166`).
- Filter by `mb_strtolower(trim($r['name'] ?? ''))` since Worksheet room entries key on `name`, not `room` (verify from existing code at line 282: `indexRoomsByName`).
- Unit test parallel to Task 1's — same 6 cases, adjusted for the `name` field.

**Task 3 — Commit + push + STATE.md + SUMMARY.md**
- Two atomic commits (one per adapter), commit prefix `feat(doc-edit):`.
- Push to `live` and `origin`.
- STATE.md row + SUMMARY.md matching the pattern from prior quick tasks.
- On the server as stcav: `git pull && php artisan optimize:clear && php artisan config:cache`. No migrations, no npm build.

## Explicit non-goals

- **O&M**: no per-room structure to filter — its adapter reads contacts + maintenance items, not rooms. If a future O&M PROJECT has per-room contacts, extend then.
- **Cable schedule**: cable rows have `from_location` / `to_location`, not a room-level scope. Filtering by room name would need a text-match on location fields, which is fuzzy enough to be a separate quick task.
- **Change-preview UI**: making the diff preview say "Saffron will be removed — 4 rooms → 3 rooms" is a UX improvement worth doing but is a larger separate change touching the drawer JS. Follow-up.
- **Fixing case-sensitive room-name lookup in `RoomOverviewSummaryService`**: same class of bug but different code path, tracked separately. Not blocking this fix.

## Gates

- `php -l` clean on both edited PHP files.
- Both unit tests green (`vendor/bin/phpunit --filter RemoveRoom`).
- Existing RAMS + Worksheet edit-adapter tests unaffected.
- No changes to `allowedOperations()` returning a shape other adapters depend on — the addition is additive.

## Deploy notes

- No migrations.
- Server: `git pull && php artisan optimize:clear && php artisan config:cache`. No npm build.
- Post-deploy sanity test: on a completed RAMS with ≥2 rooms, open the chat drawer, type "exclude <room name>, will be handled later." Expected: preview shows a `remove_room` change, apply removes the room from the regenerated document.
