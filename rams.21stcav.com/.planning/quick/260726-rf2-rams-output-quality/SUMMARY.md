---
name: 260726-rf2-rams-output-quality
status: complete
completed: 2026-07-26
branch: feat/worksheet-classifier-universal
commits:
  - d1a5398  # maxTokens bump
  - 7f3fe6d  # client contact split
  - c206a4a  # scope_items segregation + room extraction
migrations: 0
npm_build: false
tests: 46/46 MethodStatement filter green
---

## Trigger

User inspected the freshly-generated Tilda RAMS PDF (21CQ29531-05-OPS
Rev 1.0, project 88, record_id 92) and flagged four output-quality
issues.

## What shipped

### Commit `d1a5398` — MethodStatementPrompt maxTokens 3500 → 8000

Prod log 2026-07-26 16:15:06 showed `Claude response was truncated
(max_tokens reached)` on Tilda's first attempt. The retry also
truncated, so `MethodStatementService::generate()` fell back to the
static 5-phase template documented in CLAUDE.md. RAMS shipped with
generic boilerplate — no room names, no kit-specific make+model, no
RA-ID cross-refs (all the 260725-rd1 Task 3 improvements were lost).

Root cause: prompt cap was set for 9 steps × 8 bullets × ~30 words
(~2 880 tokens) with 3 500 as headroom. 260725-rd1 Task 3 added
per-room granularity + kit-specific detail + RA-ID cross-references,
which multiply token count by rooms × equipment. Tilda has 3 rooms +
104 equipment items + 7 hazards — comfortably past 3 500.

Bumped to 8 000 — headroom for ~15 rooms × 9 phases while staying
inside Claude Sonnet's 8 192 per-response ceiling. Follow-up: smarter
per-room chunking (regenerate in batches then stitch) deferred pending
real-project telemetry on the 8 000 cap.

### Commit `7f3fe6d` — Client contact rendered as name + email on separate lines

Pre-fix `resources/views/pdf/rams.blade.php` built the string as
`Wesley Jones | standa3176@googlemail.com` — pipe-joined, one line.
Visible on cover + emergency Site Contact rows.

Fix: build with an explicit `<br>` between name and email, pre-escape
both parts, switch the two render sites to `{!! !!}` so the break is
preserved. XSS-safe — `e()` applied before the string is built.

### Commit `c206a4a` — Segregate decommission/retained + extract parenthesised room suffix

**Bug 1** — every scope_items row labelled "NEW INSTALLATION" including
"Existing display screen — deinstall and return to client" rows on
Tilda pages 8-9. The PDF blade template correctly renders 3 buckets
(DECOMMISSION & HANDBACK / EXISTING — RETAINED / NEW INSTALLATION);
the data just wasn't being segregated by RamsDisplayPatchService.

Fix: while mapping quote equipment into `scope_items`, route by
name + notes:
- "to be retained" / "retained" → `retained[]` (wins over "Existing "
  prefix — retain-for-reuse overrides the shared prefix).
- Starts with "Existing " OR contains "deinstall" / "de-install" /
  "return to client" / "removal" → `decommission[]`
- Everything else → `new_install[]`

Existing `decommission[]` / `retained[]` arrays preserved when routing
bucket is empty (protects pre-existing data on regen).

**Bug 2** — six Crestron 10.1" scheduling touch screens on Tilda page 6
rendered with blank Room / Area:
```
Crestron 10.1" ... (Vanilla)      [blank room]
Crestron 10.1" ... (Poppy)         [blank room]
Crestron 10.1" ... (Kalonji)       [blank room]
Crestron 10.1" ... (Nutmeg)        [blank room]
Crestron 10.1" ... (Project Room)  [blank room]
Crestron 10.1" ... (Cardamon)      [blank room]
```
QW puts the room name in a trailing `(RoomName)` paren where
section-header grouping doesn't apply.

Fix: when mapping and room is empty, extract ` (RoomName)` from end of
description into `room` and strip from name. Sanity guard rejects
specy suffixes (digits, units, `kg`, `mm`, `hz`, `v`, `amp`, `rev`,
colons) so `(300 W)` or `(Rev A)` stay in the name.

Both bugs are transient display patches — `$pkg->extracted_data` is
untouched, only `$gd['scope_items']` mutates.

## Tests

- 46/46 `MethodStatement` filter green.
- `RamsDisplayPatchService` still has no dedicated unit tests; existing
  routing behaviour unchanged for pure new_install items.
- `php -l` clean on all 3 touched files.

## Deploy

**No migration. No npm build.**

```bash
sudo -u stcav bash    # if needed
cd /home/stcav/rams.21stcav.com
git pull
php artisan optimize:clear
php artisan config:cache
php artisan queue:restart
```

**`queue:restart` is critical** — BuildRamsDocumentJob runs in the
queue worker; without a restart the worker keeps using the old
MethodStatementPrompt / RamsDisplayPatchService in memory (this bit
us in 260726-fx5 hotfix).

## Sanity checks after deploy

1. **maxTokens** — regenerate Tilda 21CQ29531-05-OPS → §6 Method of
   Works should now name specific rooms (Oregano/Cinnamon/Saffron),
   cite specific kit (Sony QM85, Crestron 4-Series), and end each
   phase with "Associated Risks: RA01, RA02, ..." Old log had
   `MethodStatementPrompt attempt 1 failed — Claude response was
   truncated`; new log should show no truncation warning.
2. **Client contact** — cover page (p1) + emergency Site Contact
   (p23-ish) show name on one line and email on the next.
3. **Scope items** — "Existing X — deinstall and return to client"
   rows on Tilda land under DECOMMISSION & HANDBACK banner (not NEW
   INSTALLATION); "Existing Shure P300 DSP — to be retained" lands
   under EXISTING — RETAINED.
4. **Parenthesised rooms** — the six Crestron 10.1" touch screens on
   Tilda have Room = VANILLA / POPPY / KALONJI / NUTMEG / PROJECT ROOM
   / CARDAMON.

## Deviations from PLAN.md

None — 4 planned fixes, 4 shipped, 3 atomic commits.

## Related

- **260725-rd1 Task 3** — added the per-room / kit-specific / RA-ID
  prompt content whose extra tokens outstripped the 3 500 cap.
- **260726-fx4** — same-day predecessor that shipped `site_conditions`
  into the prompt (further growing the input side of the budget).
- **260726-fx5** — same-day predecessor + hotfix; taught us that
  `queue:restart` is mandatory after any RamsDisplayPatchService change.
