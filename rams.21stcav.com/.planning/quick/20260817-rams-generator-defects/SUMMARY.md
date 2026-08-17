---
quick_id: 260817-r5e
slug: rams-generator-defects
date: 2026-08-17
status: complete
commits: f19023a, e76857a, 9f2ed36, 8f5c3cb, 7b2748e
---

# Summary — RAMS generator defects (21CQ30960 Rev 1.0 review)

All four planned items delivered. Full suite: **2132 passed, 2 failed** (both pre-existing, see below).

## What changed

### Task 1 — duplicate "Associated Risks" line (`f19023a`)

Deterministic producer kept, AI producer removed, model output stripped defensively.

- `MethodStatementPrompt.php:91,250` — the rd1 instruction to emit the line is now an explicit prohibition
- `MethodStatementPrompt.php:201` — risk-register header changed from "use these RA-IDs verbatim" to "context only — do NOT cite". Hazards still supplied, so steps still address the right ones
- `RamsComplianceUpgradeService.php:955-961` + `isAssociatedRisksLine():1020` — strips model-authored bullets before deriving. Anchored to bullet start so *"Brief the team on the associated risks…"* survives

**Extra defect found and fixed (`:920-927`) — dangling RA references.** The producer keyed RA-IDs off `$hazard['id']`, but both renderers label the Ref column by array **position** (`DocxBuilderService.php:1221`, `rams-v2.blade.php:1393` — `'RA' . str_pad($index + 1, 2, '0')`). These agree only while ids run 1..N in order, and diverge as soon as `RamsDataBuilderService.php:~425` drops an unlabelled hazard while preserving survivors' ids. Derivation is now index-based. Without this the "all cited RA-IDs exist in the register" criterion was unmeetable.

### Task 2a — equipment-schedule fallback (`9f2ed36`)

New `app/Support/Rams/EquipmentScheduleFallback.php`; wired into `DocxBuilderService.php:835-846`, `rams.blade.php:458,1181-1197`, `rams-v2.blade.php:517,1260-1277`.

- No activity claim — header `EQUIPMENT SCHEDULE`, activity cell `Not specified`
- Identical item+area rows collapse with quantities summed; same item in *different* rooms stays separate; non-numeric qty ("Lot") passes through rather than being totalled into fiction
- Grouping-header values in `area` blanked (shares 2b's list) — this is what repairs already-imported packages like 21CQ30960

**Plan was wrong about the file set.** It named `DocxBuilderService.php` and `rams-v2.blade.php`. A **third** copy of the branch lives in `resources/views/pdf/rams.blade.php`, and that is the **live** one: `PdfService.php:66-78` routes to `pdf.rams-v2` only when `RAMS_UNIFIED_COMPOSER=true`, and `config/rams.php:43` defaults it `false` with no `.env` override. Fixing only the two named files would have left the live PDF unchanged. All three fixed and covered.

### Task 2b — category names in `area` (`e76857a`)

New `app/Support/Quote/NonRoomAreaLabels.php` owns the list. Added, all anchored `/^\s*…\s*$/i`: `hardware`→null, `hardware supply only`→null, `cables`→`cables`, `service contracts`→`service_contracts`, `customer supplied`→`customer_supplied`, `options`→null, `unknown`→null.

`options` / `hardware supply only` map to null deliberately — `EquipmentCategoryClassifier`'s docblock states `hardware_supply_only` is manual-selection-only because nothing in a description reliably signals it. Clearing the fake room without making a commercial decision is the conservative half.

The list was duplicated between `QuoteWerksImportService` and `PackagesReclassifyEquipmentCommand` under a *"keep in sync"* comment, and renderers couldn't reach it at all. Both now read `NonRoomAreaLabels::PATTERNS` — which is what let 2a reuse it at render time without a third copy.

### Task 3 — access-equipment contradiction (`8f5c3cb`)

**Plan was wrong about the location.** `DocxBuilderService.php:948` / `rams-v2.blade.php:939` are the engineer-survey-findings label map, unrelated. The sentence is generated in `RamsComplianceUpgradeService::addAccessEquipmentDetail()`.

Both editorialising sentences removed. `:410-449` + new `accessEquipmentReferencedElsewhere():466` — a platform-class type is dropped only when nothing else in the document references it (scan covers hazard names, controls, phase titles, steps). When RA01's controls or Step 8 *do* reference podium steps, the line stays and no claim is made either way.

`addAccessEquipmentDetail()` moved in `upgrade():28-37` to run after `fillMissingHazardControls()` — that method is what injects the *"Use appropriate access equipment (podium steps, tower, or MEWP)"* control the reconciliation must see. Nothing between old and new position reads `access_equipment_detail`, so the move is otherwise inert.

### Task 4 — product-identifier fidelity (`7b2748e`)

`MethodStatementPrompt.php:89-90,241-242` — reproduce item strings verbatim incl. colour/finish/size/variant/supply-status; never merge variants; a *Decommission*/*Retained* item is a different physical unit from a same-named *New install* item.

**Honest limit:** this reduces but cannot eliminate paraphrase. It is an instruction to a probabilistic system with no post-generation check. The deterministic §4 equipment schedule remains the authoritative pick list — and after 2a it is now honest about what it doesn't know. Guaranteeing the graphite/white distinction in §6.6 prose needs a post-generation validator diffing step text against supplied item strings. Not attempted; logged as follow-up.

## Test evidence

New: `MethodStatementAssociatedRisksTest` (4), `AccessEquipmentContradictionTest` (4), `EquipmentScheduleFallbackTest` (8, data-provider over both PDF templates + DOCX). Updated: `MethodStatementPromptTest`, `QuoteWerksImportServiceTest` (30 pass incl. the 7 pre-existing 260725-qw3 reroute tests).

**Non-vacuity proven by reverting each fix and observing failure**, then restoring:

- Task 1 → 3 failed / 1 passed (incl. *"RA04 is a DANGLING reference"*). The 1 pass is the anti-over-strip guard, correctly unaffected.
- Task 2a → 7 failed / 1 passed. The 1 pass is the anti-over-merge guard (same item, two rooms), correctly unaffected.
- Task 3 → checked both ways: reconciliation disabled → 2 failed; exclusion sentences re-added → 3 failed.

Each renderer assertion first asserts the fixture actually reached the schedule, so a silent non-render cannot produce a green test.

Both blade files verified with `blade.compiler->compileString()` piped through `php -l` on the *compiled* output — not just `php -l` on source (the 260817-jsg lesson).

## Two pre-existing failures — NOT from this task

1. `QueueRecoverCommandTest::unhealthy queue runs restart and drain plan` — documented production finding in `20260817-green-the-suite/SUMMARY.md` Item 5 (`EXIT_MEMORY_LIMIT` conflated with `EXIT_RECOVERY_FAILED`).
2. `CableScheduleRegenerationTest::regenerate button hidden when user lacks update permission` — **new observation.** Verified not caused here: `git diff --name-only 1b0fc1a HEAD` touches no cable, policy, gate or authz file. Likely obsoleted by `e82c725 feat(260525-pyu): relax resource policies to any authenticated user` — if any authenticated user now has update permission, "lacks permission" is no longer a reachable state. **Do not force this green.** It is either a stale assertion or a real authz regression showing a control to users who cannot use it; that distinction needs deciding, not patching. Logged as its own task.

## Files to upload to live

```
app/Core/AI/Prompts/MethodStatementPrompt.php
app/Services/Rams/RamsComplianceUpgradeService.php
app/Services/DocxBuilderService.php
app/Core/Modules/QuoteImport/QuoteWerksImportService.php
app/Console/Commands/PackagesReclassifyEquipmentCommand.php
app/Support/Quote/NonRoomAreaLabels.php            ← NEW
app/Support/Rams/EquipmentScheduleFallback.php     ← NEW
resources/views/pdf/rams.blade.php
resources/views/pdf/rams-v2.blade.php
```
Then `php artisan optimize:clear` (view + config caches). No migration.

⚠️ **Check live `.env` for `RAMS_UNIFIED_COMPOSER`.** Local is unset → `pdf.rams` is live. If production sets it `true`, `pdf.rams-v2` is the live path instead. Both are fixed, so either way is correct — but it determines which template to verify in the browser.

## Browser verification (outstanding)

Regenerate 21CQ30960-OPS and check: §6.6 one `Associated Risks` line per step, every RA-ID present in §5; §4 no NEW INSTALLATION claim, 75"/55" rows once with summed qty, Room/Area blank not "Hardware"; §6.4 no "Podium steps excluded" sentence. Then confirm a RAMS whose scope buckets **are** populated still shows DECOMMISSION / RETAINED / NEW INSTALLATION banners unchanged.

## Not done / follow-ups

- `config/rams_tier1.php` untouched — H&S library awaiting competent-person sign-off (FFP3, RA07 retitle, asbestos + vehicle/plant rows, RA01 score, out-of-scope standards, COSHH padding)
- QW import route still doesn't filter `notes`/`terms`/`terms and conditions` as non-rooms, though the PDF route (`QuoteParserService::isNonRoomSectionTitle():~2944`) always has. Flagged rather than silently widened
- Non-fallback schedule branches still print `$item['room']` unfiltered — those values are PM-curated at review; suppression was scoped to the fallback path
- Post-generation item-string validator (Task 4 limit above)
- `CableScheduleRegenerationTest` decision (above)
