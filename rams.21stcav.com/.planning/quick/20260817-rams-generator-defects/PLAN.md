---
quick_id: 260817-r5e
slug: rams-generator-defects
date: 2026-08-17
status: planned
---

# Quick Task 260817-r5e — RAMS generator defects found in the 21CQ30960 Rev 1.0 review

## Source

A full professional review of a real generated RAMS (project 21CQ30960-OPS, Volkswagen Blakelands, Rev 1.0, 26 pages) identified a set of defects. Each item below was then traced to code. **These are the code-fixable items only** — H&S library content in `config/rams_tier1.php` is deliberately excluded pending the user's sign-off (see "Out of scope").

## Item 1 — Every method-statement step carries TWO "Associated Risks" lines

### Evidence

In the generated document every step in §6.6 ends with two lines carrying **different** RA references, one plain and one italic. Step 1: `RA01, RA02, RA03, RA04, RA05, RA06` then `RA01, RA03, RA09, RA10, RA11`.

### Cause — two independent producers, neither aware of the other

| Source | Behaviour |
|---|---|
| `app/Core/AI/Prompts/MethodStatementPrompt.php:75,229` | instructs the **AI** to emit `"Associated Risks: …"` as the last bullet of every phase |
| `app/Services/Rams/RamsComplianceUpgradeService.php:897` | **independently** generates its own line from `$matchedIds` |

Both run. The AI's list is model-chosen; the compliance service's is deterministically matched.

### Task 1

Pick one source of truth and remove the other. **Prefer the deterministic `RamsComplianceUpgradeService` output** — a model-chosen risk cross-reference on a safety document is exactly the kind of thing that should not be improvised, and the prompt already constrains the AI to "only reference RA-IDs that appear in the supplied risk list", which is a weaker guarantee than deriving them.

If the compliance service becomes the sole producer, the prompt must stop asking for the line AND the builder must strip any the model emits anyway (models do not reliably follow negative instructions).

**Acceptance criteria:**
- A generated method-statement phase contains exactly ONE `Associated Risks:` line
- The line's RA-IDs all exist in the document's own risk register — assert this, since dangling references are the failure mode that matters
- A test asserts no phase produces two such lines, and fails if either producer is reinstated

## Item 2 — Equipment schedule labels reused kit as "NEW INSTALLATION", duplicates rows, shows bogus areas

### Evidence

The schedule lists every item under a single `NEW INSTALLATION` banner including kit explicitly being reused from the Willen decommission; the 75" display + mount and the 55" rows each appear twice; and the "Room / Area" column shows **`Hardware`** — a category name, not a room.

### Cause — a fallback branch, plus upstream data

`app/Services/DocxBuilderService.php` has correct `DECOMMISSION / RETAINED / NEW INSTALLATION` buckets driven by `$scopeItems` (~lines 780-822). An `else` branch at ~823 dumps **all raw quote line items under a single "NEW INSTALLATION" header** when those buckets are empty. `RamsFormRequest` declares `new_install_items` as `nullable`, so an unpopulated form silently triggers the fallback.

That single branch produces all three symptoms: wrong activity label, duplicate rows (the quote genuinely lists the same item under two areas), and the raw `area` passed straight through.

### Task 2a — Make the fallback honest

**File:** `app/Services/DocxBuilderService.php` (~823), and the equivalent path in `resources/views/pdf/rams-v2.blade.php` (~1240) if it shares the behaviour — check both.

**Action:** When falling back to raw quote line items, do NOT assert `NEW INSTALLATION`. Label the section neutrally (e.g. `EQUIPMENT SCHEDULE`) and the per-row activity column neutrally, because the generator genuinely does not know the activity in that path. Deduplicate identical item rows, summing or grouping quantity rather than emitting the same item twice. Suppress a `Room / Area` value that is not a real room (see 2b) rather than printing a category name.

Asserting "NEW INSTALLATION" against reused kit is worse than saying nothing — it tells an engineer to install equipment that is being recovered from another room.

**Acceptance criteria:**
- The fallback path emits no `NEW INSTALLATION` claim
- Identical items are not emitted as duplicate rows
- A test covers the fallback path with a duplicate-item, category-as-area fixture

### Task 2b — Stop category names landing in `area` at import

**File:** `app/Core/Modules/QuoteImport/QuoteWerksImportService.php` — `NON_ROOM_SECTION_PATTERNS` (~line 44)

**Action:** The list already reroutes fake-room section headers (`professional services`, `labour`, `delivery`, `consumables`, `summary`, `room booking panels`). Add anchored patterns for the remaining canonical **category names** that can appear as QW section headers and are not rooms — `hardware` being the one proven in this quote. Use the same `/^\s*…\s*$/i` anchoring the existing entries use so a genuine room like "Hardware Store" is unaffected.

Map `hardware` to `null` (clear the area, preserve the classifier's category) — it is a grouping header, not a services/consumables reroute.

**Acceptance criteria:**
- A QW line whose section header is `Hardware` yields an empty `area`, and its category is left to the classifier
- A room genuinely named e.g. "Hardware Store" is untouched
- Existing `QuoteWerksImportServiceTest` reroute tests still pass

## Item 3 — Access-equipment contradiction

### Evidence

§6.4 states *"Podium steps excluded — working height does not require a working platform"*, while RA01's controls list podium steps and Step 8 instructs operatives to remove them. The document contradicts itself, on a work-at-height control.

### Task 3

**Files:** `app/Services/DocxBuilderService.php` (~948) and `resources/views/pdf/rams-v2.blade.php` (~939) — both carry the access-equipment label map. Find where the "excluded — working height does not require a working platform" clause is emitted.

**Action:** Do not emit a categorical exclusion claim for an access-equipment type that is referenced elsewhere in the same document. Either omit unselected equipment silently, or reconcile against the hazard controls. The editorialising justification ("working height does not require a working platform") is a safety assertion the generator is not entitled to make from a form checkbox.

**Acceptance criteria:**
- No generated document asserts an access-equipment type is excluded while also listing it as a control or in a method step
- A test covers the contradiction case

## Item 4 — Product identifiers paraphrased by the method-statement AI

### Evidence

The quote distinguishes **graphite** Rally mic pods (reused from Willen → GND and Nadin tables) from new **white** pods (CV ceiling). The generated method statement calls the reused ones white throughout, so an engineer picking to it takes the wrong item.

### Task 4

**File:** `app/Core/AI/Prompts/MethodStatementPrompt.php`

**Action:** The prompt already enforces make+model naming ("steps MUST name specific equipment from the supplied list", 260725-rd1). Strengthen it so **product identifiers are reproduced verbatim** — colour, variant and supply-status qualifiers included — and paraphrase or merging of two similarly-named items is explicitly forbidden. Where the supplied list contains two variants of the same product, the prompt must state they are distinct items.

**Acceptance criteria:**
- The prompt instructs verbatim reproduction of item strings and forbids merging variants
- A prompt-content test asserts the rule is present (consistent with the existing `MethodStatementPromptTest` approach)
- Note in the SUMMARY that this reduces but cannot eliminate model paraphrase — the deterministic equipment schedule remains the authoritative pick list

## Constraints

- PHPUnit 11, NOT Pest. Lint every touched PHP file with `"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l <file>`.
- **Blade files need `blade.compiler->compileString()` checking, not just `php -l`** — a JS comment referencing a component silently broke compilation of the shared chat drawer in task 260817-jsg and `php -l` passed it.
- No migration. No new packages.
- Local-edit-then-upload (Phase 21 D-13) → `php artisan optimize:clear` after upload.
- Do not make live AI calls in tests.

## Out of scope — awaiting user sign-off

All `config/rams_tier1.php` hazard-library content: FFP2→FFP3, RA07 "Confined Spaces" retitle, missing asbestos and vehicle/plant hazard rows, RA01 initial score, out-of-scope standards (BS EN 60849, BS 8492, BS EN 60825-1, HSG 47) and COSHH inventory padding. These appear in **every** RAMS the system produces and are H&S judgements for the competent person, not the developer. Do not touch that file.

Also out of scope: the two-engineer vs four-operative lift conflict (a resourcing/cost decision), and the CDM duty-holder table wording.
