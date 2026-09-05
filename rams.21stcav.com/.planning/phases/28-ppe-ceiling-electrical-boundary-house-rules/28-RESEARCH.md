# Phase 28: PPE, Ceiling & Electrical Boundary House Rules - Research

**Researched:** 2026-09-05
**Domain:** Laravel 12 RAMS generation pipeline — hazard-content correction, deterministic
document-content gating, static source-code hygiene tests
**Confidence:** MEDIUM-HIGH (code-level findings are HIGH confidence, directly traced and in
several cases empirically executed against the real codebase; live-production quantities in Q3
are UNVERIFIED — no route to the production MySQL DB from this research session)

## Answers

**Q1 — Does `signal:ceiling_void_access` fire for a ceiling-MOUNTED job (not void entry)?**
**VERIFIED, YES.** `HazardIncludeWhenResolver::TIER2_ACTIVITY_SIGNALS['ceiling_void_access'] =
['ceiling_works']`, and `EquipmentClassifierService` sets the `ceiling_works` activity from a
broad equipment-description keyword list (`ceiling`, `projector`, `ceiling speaker`, `ceiling
mount`, `recessed`, `pendant`, `in-ceiling`, `suspended`, `overhead`, `flush mount`, `drop`,
`canopy`, `hanging`) — none of which require literal void entry. Empirically proven by executing
the real production code via `artisan tinker` against the real seeded `hazard_templates` table
with a synthetic "ceiling mounted projector" item: the resolver returned hazard "Restricted
access and ceiling voids" (tier `deterministic`), whose controls include RULE-10's exact
ceiling-load sentence. **No new trigger is needed for RULE-10 — it already fires.** Caveat: the
same hazard row also carries void-entry-specific controls (asbestos register check, head-torch
void inspection, tile removal) that are over-inclusive for a pure ceiling-mount (no entry) job —
see Common Pitfalls.

**Q2 — Where can RULE-09's electrical scope boundary land, and is it reachable on both entry
points?**
**VERIFIED.** The DOCX exclusions block (`DocxBuilderService.php:870-912`) reads
`reviewed_data['exclusions']` first, falling back to `generated_data['exclusions']`, and prints
"No exclusions declared for this project." if both are empty — it is **not unconditional on its
own**. The actual unconditional home already in production is
`RamsDisplayPatchService::patch():387-392`, which seeds a hardcoded 5-item default list into
`reviewed_data['exclusions']` **only when the key is entirely unset** (`!isset`). Both DOCX
render paths — `DocxBuilderService::buildLegacy()` (`:139`, **the live path**) and
`DocxBuilderServiceV2::build()` (`:107`, gated behind `RAMS_UNIFIED_COMPOSER`, confirmed **unset
in production**, default `false`) — call `patch()` immediately before rendering, so adding a 6th
bullet to that array reaches both the legacy and V2 renderers identically, and also reaches the
review-form GET page (`RamsController::review()` also calls `patch()`). This is the unconditional
home D-06 licenses. **Gap:** once a document has been through Save Review even once,
`reviewed_data['exclusions']` becomes permanently `isset` (even if saved as `[]`), and
`RamsDisplayPatchService`'s seed never fires again for that document — a newly-added bullet will
not retroactively appear on any already-reviewed document (see Q3/backfill).

**Q3 — How many existing documents does GATE-06/07 bite, and is a backfill needed?**
**UNVERIFIED — no reachable database.** Local dev DB (SQLite) has 0 `RamsDocument` rows; this
session has no SSH/DB credentials for `rams.21stcav.com`. The exact command to run on production
(as `stcav`) is given below. Two separate backfill questions exist, not one: (1) hazard
**control-line** FFP2/confined-space text self-heals on next regeneration once the two new
`ControlTextRuleViolations` detectors are added — tier 1 replaces violating control lines
**regardless of `controls_reviewed`**, so no migration is needed for that surface, only a
regeneration event; (2) the flat `reviewed_data['ppe']` array and the `exclusions` array do
**not** self-heal — they are round-tripped verbatim from stored data with no rule-scan at all
(see Q4), so a document whose `ppe` array already contains the literal string `Dust Mask
(FFP2)`, or whose `exclusions` array was already persisted before RULE-09's bullet is added, will
carry the defect on every future render until a migration or engineer edit fixes it.

**Q4 — What is the right detector shape, and where does the PPE array fit?**
**Confined-space detector is genuinely negation-aware and non-trivial; the FFP2 hazard-control
detector is trivial; but a THIRD, previously-unscoped surface — the `ppe`/`ppe_matrix` arrays —
needs a different (simpler, closed-vocabulary) fix, not the free-text detector at all.**
`ControlTextRuleViolations` (`app/Services/Rams/ControlTextRuleViolations.php`) is an all-static
ordered-registry class exactly as CONTEXT.md describes, consumed only by
`RamsBuilderService::reviewedToRisk()`'s tier-1 branch over hazard **`controls[]`** text. It has
**no path into the `ppe` array at all** — `RamsDataBuilderService::mergePpe()` and
`RamsBuilderService`'s reviewed-PPE line (`:632-633`) are plain
`array_unique(array_merge(...))`/`array_filter` with zero rule-text scanning. A stored `Dust Mask
(FFP2)` PPE string will survive forever regardless of source fixes, unless the planner adds a
**separate, much simpler** deterministic string-replace map for the closed PPE vocabulary (not a
`detectXxx()` free-text detector — PPE items come from a fixed pick-list, not engineer prose).
Separately, `RamsComplianceUpgradeService::addPpeMatrix()` hardcodes a `ppe_matrix` array fresh
on **every** `upgrade()` call (not read from stored data) — fixing its two literal `FFP2`
occurrences in source is sufficient; it self-heals with no backfill. ACOP L101 has zero existing
occurrences anywhere in app code — the only realistic route by which it (or "confined space"
prose) enters a live document today is the **AI extraction prompt itself**
(`PromptBuilderService.php:152`), which literally hands the model "confined spaces" as an example
hazard category to look for in drawings — a live, non-legacy source the detector's proof corpus
must include, not just the seeder/library text.

**Q5 — What does the repo-wide static FFP2 source test look like, and what's the exact
exclusion list?**
**VERIFIED — direct precedent exists and should be copied nearly verbatim.**
`tests/Feature/Rams/HazardInjectionPathsRemovedGuardTest.php` is exactly this shape: a static
substring scan over every `.php` file under given directories, asserting a forbidden string
appears in zero of them, with a self-exclusion for the guard file. `.blade.php` files count as
`.php` under PHP's `SplFileInfo::getExtension()`, so the same recursive-glob helper naturally
covers `resources/views/`. Independently re-grepped (not trusted from CONTEXT.md alone) and
**confirmed exactly 14 live sites + 5 backup-only sites**, matching CONTEXT.md's inventory
exactly. Zero occurrences of `FFP2` exist anywhere under `tests/`, so the new ban test has no
existing fixture to conflict with.

---

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|---|---|---|---|
| Hazard control-line house-rule detection (`ffp2`, `confined_space`) | API/Backend (`ControlTextRuleViolations`) | — | Single choke point per D-03; consumed by `RamsBuilderService::reviewedToRisk()` |
| PPE-array house-rule correction (`Dust Mask (FFP2)` string) | API/Backend (new, closed-vocabulary replace map) | — | Distinct data surface from hazard controls; no existing scanning mechanism touches it |
| Independent throwing re-check (GATE-06/GATE-07) | API/Backend (`RamsComplianceUpgradeService::upgrade()`) | — | Mirrors GATE-09's `enforceDisplayLiftGate()`; `upgrade()` is the one function reachable from every render path |
| Electrical scope boundary / ceiling-load statement placement | API/Backend (`RamsDisplayPatchService`, hazard seeder) | Database/Storage (`hazard_templates`, `rams_documents.reviewed_data`) | Both are safety text that must be deterministically derived or unconditional, never AI-decided (CLAUDE.md:12) |
| Repo-wide static FFP2 source ban | Build/CI (PHPUnit static-scan test) | — | Catches reintroduction on paths the live proof job never exercises |
| Review-form pre-fill / round-trip of exclusions & PPE | Frontend Server (Blade `review.blade.php` + `RamsController::updateAndDownload()`) | — | Engineer-visible, editable; owns the persistence boundary between "unconditional default" and "engineer-owned text" |

---

## Standard Stack

No new external packages. This phase is entirely internal PHP/Laravel service and test code
within the existing app. **Package Legitimacy Audit: not applicable — no packages installed.**

---

## Existing Code Insights (verified this session)

### The two generation entry points, and every render call site
`RamsComplianceUpgradeService::upgrade()` is called from, at minimum:
- `RamsBuilderService.php:296` — inside `runPipeline()`
- `RamsBuilderService.php:941` — inside `runFromReview()`
- `RamsController.php:603` — `updateAndDownload()` (Save Review), wrapped in a
  `try/catch (RamsGenerationException)` that redirects back with the message instead of a 500
- `RamsController.php:701` — DOCX-rebuild-on-download fallback
- `RamsController.php:852` — PDF-download path
- `RamsRefreshComplianceCommand.php:185` — a standalone artisan command that re-runs `upgrade()`
  over stored `generated_data` (this is itself a candidate mechanism for a Q3 remediation pass —
  see Common Pitfalls)

This is the single richest choke point in the codebase and is where the GATE-06/GATE-07 throwing
re-check belongs, mirroring `enforceDisplayLiftGate()`'s placement and env-flag gating exactly
(`config('rams_tier1.display_lift_gate_enabled')` pattern).

### `HazardIncludeWhenResolver` / `EquipmentClassifierService` (Q1 mechanism, full trace)
```
EquipmentClassifierService::classify($equipmentItems)
  → str_contains($description, 'ceiling'|'projector'|'ceiling speaker'|'ceiling mount'|
                                'recessed'|'pendant'|'in-ceiling'|'suspended'|'overhead'|
                                'flush mount'|'drop'|'canopy'|'hanging')
  → activities['ceiling_works'] = true
      ↓
HazardIncludeWhenResolver::TIER2_ACTIVITY_SIGNALS['ceiling_void_access'] = ['ceiling_works']
  → signal:ceiling_void_access MATCHES
      ↓
HazardTemplate "Restricted access and ceiling voids" (include_when = 'signal:ceiling_void_access')
  → included, tier = deterministic
  → controls[] includes RULE-10's exact sentence (HazardTemplateSeeder.php ~:223)
```
Both `runPipeline()` (via `EquipmentClassifierService::classify()` directly) and `runFromReview()`
(via `reviewedToClassified()` reading `reviewed_data['activities'][*]['key']`, which was itself
populated from the SAME classifier's output at extraction/import time —
`ProjectPackageRamsReviewService::activitiesFromClassifier()`,
`RamsExtractionDraftBuilderService::buildActivities()`) carry the `ceiling_works` key forward
unfiltered. **Empirical proof (this session, local `artisan tinker` against the real seeded
library):**
```
activities: ceiling_works,commissioning
matched hazards: ... Restricted access and ceiling voids (tier=deterministic) ...
CEILING HAZARD INCLUDED: Restricted access and ceiling voids
```
This was run against the LOCAL codebase's real service/library code with a synthetic equipment
item, not against live 21CQ30960 data (local DB has 0 `RamsDocument` rows). It proves the
mechanism, not the specific real-project outcome — the planner should still spot-check a live
regeneration per ROADMAP criterion 3/4.

### The exclusions block and its two independent "already isset" traps (Q2 mechanism, full trace)
1. `RamsController::review()` (GET) calls `patchRamsForDisplay()` →
   `RamsDisplayPatchService::patch($rams)`, which seeds
   `reviewed_data['exclusions']` with 5 hardcoded strings **transiently, in-memory, only if
   `!isset($rd['exclusions'])`** — this is what the engineer sees pre-filled in the review form.
2. On submit, `RamsController::updateAndDownload()` sets
   `$reviewedData['exclusions'] = array_values(array_filter($request->input('exclusions', [])))`
   **unconditionally** — even an untouched, all-default submission persists those 5 strings as a
   real, `isset` value in the DB `reviewed_data` JSON column.
3. From that point on, `RamsDisplayPatchService`'s seed condition (`!isset`) is permanently false
   for that document — **the seed never fires again**, by design (this mirrors the "engineer-owned
   once touched" convention used elsewhere in the codebase, e.g. `controls_reviewed`).
4. Both DOCX renderers (`buildLegacy()` line 139, `DocxBuilderServiceV2::build()` line 107) call
   `patch()` before reading `reviewed_data['exclusions']`, and legacy is confirmed the live path
   (`config('rams.unified_composer') = env('RAMS_UNIFIED_COMPOSER', false)`, unset in production
   per multiple existing docs, e.g. `26-RESEARCH.md:89`, ROADMAP "Canonical refs").

**Consequence:** adding RULE-09's sentence as a 6th default bullet in
`RamsDisplayPatchService.php:387-392` is unconditional and reaches every render path — but ONLY
for documents whose `reviewed_data['exclusions']` key has never been set. Any document that has
already been through one Save Review (even a no-op one) is permanently excluded from the new
default and needs either a migration or to be manually re-edited.

### `ControlTextRuleViolations` — the exact shape to extend (Q4)
Read in full (`app/Services/Rams/ControlTextRuleViolations.php`). Structure to copy:
```php
private const DETECTORS = [
    'kg_threshold'          => 'detectKgThreshold',
    'size_conditional_lift' => 'detectSizeConditionalLift',
    // Phase 28 adds:
    'ffp2'            => 'detectFfp2',
    'confined_space'  => 'detectConfinedSpace',
];
```
`detect()` iterates `DETECTORS` and returns the first matching key; `detectAll()` maps
index→key over an array of control lines. Both existing detectors are careful to (a) never
re-encode a canonical value elsewhere (`DisplayLiftPolicy`'s bands), and (b) fail closed —
ambiguous input returns `false`/`null`, never a guess.

**Proposed `ffp2` detector — trivial, as flagged in the research priorities:**
```php
private static function detectFfp2(string $control): bool
{
    return (bool) preg_match('/\bFFP2\b/i', $control);
}
```
No negation awareness needed — there is no legitimate sentence in which the token "FFP2" is
correct (the only "clean" FFP2-adjacent text is the `RiskMatrixService.php:133` hedge "FFP2 or
FFP3", which is itself a defect per D-04, not a sentence this detector needs to spare).

**Proposed `confined_space` detector — genuinely negation-aware, mirroring the class's own
conservative-by-construction convention:**
```php
private const CONFINED_SPACE_NEGATIONS = [
    'not classified as a confined space',
    'not classified as confined spaces',
    'not a confined space',
    'not confined spaces',
    'are not classified as confined',
    'is not classified as confined',
    'not treated as a confined space',
];

private const CONFINED_SPACE_AFFIRMATIVE = [
    'confined space entry',
    'confined space permit',
    'is a confined space',
    'is classified as a confined space',
    'acop l101',
    ' l101',
];

private static function detectConfinedSpace(string $control): bool
{
    $lower = strtolower($control);

    foreach (self::CONFINED_SPACE_NEGATIONS as $phrase) {
        if (str_contains($lower, $phrase)) {
            return false; // the app's own corrected wording — always clean
        }
    }

    foreach (self::CONFINED_SPACE_AFFIRMATIVE as $phrase) {
        if (str_contains($lower, $phrase)) {
            return true;
        }
    }

    // Bare "confined space[s]" with no recognised negation is still an
    // affirmative mislabel (e.g. a hazard row/title that is JUST
    // "Confined Space").
    return str_contains($lower, 'confined space');
}
```
This is a **proposal**, not implemented code — the planner should validate it against the full
corpus below before shipping.

**Required proof corpus (per D-01's load-bearing acceptance criterion, extended from Phase 27):**
1. Every control line `HazardTemplateSeeder::standardHazards()` emits, for all 18 hazards
   (iterate the seeder programmatically, not hand-copy strings — this is exactly how
   `ControlTextRuleViolationsTest::test_no_seeded_library_control_is_ever_flagged()` already
   proves the two existing detectors; add `ffp2`/`confined_space` to that same test).
2. The negating sentence at `HazardTemplateSeeder.php` hazard #7 line ~220 verbatim — must return
   `null`/clean.
3. Every affirmative construction named in D-01: `"confined space"`, `"is a confined space"`,
   `"confined space entry"`, `"confined space permit"`, an L101 citation — must return the
   `confined_space` key.
4. **New, not in CONTEXT.md's list:** a synthetic sentence built from the AI-extraction prompt's
   own example phrasing (`PromptBuilderService.php:152` — "confined spaces" as an example hazard
   category) — since this prompt is a live, non-legacy route by which the model could emit an
   affirmative "Confined Space" hazard name into `extracted_data`/`reviewed_data`.
5. `LegacyHazardNameFoldMap.php:97` — the fold-map TARGET string
   (`'Restricted access and ceiling voids'`) itself must never trip the detector (it doesn't
   contain "confined space", so this should be a non-issue, but assert it explicitly since D-05
   may rename this target).

**The PPE-array gap (not covered by `ControlTextRuleViolations` at all):**
```
reviewed_data['ppe'] (engineer/review-form array, closed pick-list vocabulary)
    ↓ RamsBuilderService.php:632-633 — array_filter(array_map('strval', ...)) — NO rule scan
    ↓ RamsDataBuilderService::mergePpe() — array_unique(array_merge(...)) — NO rule scan
    ↓ generated_data['ppe'] — rendered verbatim in DOCX/PDF
```
Recommend a SEPARATE, much simpler mechanism for this surface: a closed string-replace map
(e.g. `['Dust Mask (FFP2)' => 'Dust Mask (FFP3) — face-fit tested']`), applied wherever
`reviewed_data['ppe']`/`generated_data['ppe']` is assembled — not a new `ControlTextRuleViolations`
detector, since PPE items are not free text. `ppe_matrix` (a THIRD, separate array built fresh
every `upgrade()` call inside `RamsComplianceUpgradeService::addPpeMatrix()`, never read from
stored data) self-heals automatically once its two literal `'Dust mask (FFP2)'` source strings
(`:349`, `:361`) are fixed — no backfill needed for `ppe_matrix` specifically.

### Static source-ban test precedent (Q5)
`tests/Feature/Rams/HazardInjectionPathsRemovedGuardTest.php` — copy this shape:
```php
class FfpTwoBannedFromSourceTest extends TestCase
{
    private const FORBIDDEN = ['FFP2']; // case-sensitive; confirm case-insensitive variant not needed — zero lowercase hits found this session

    private const EXCLUDED_FILES = [
        'resources/views/pdf/rams.blade - keep boarder.php',
        'resources/views/pdf/rams.blade-keep-borders.php',
        // resources/views.backup-260430/ excluded wholesale, see below
    ];

    public function test_ffp2_does_not_appear_in_any_non_backup_source_file(): void
    {
        $dirs = [base_path('app'), base_path('resources/views'), base_path('config'),
                 base_path('database/seeders'), base_path('tests')];
        // recurse each dir, skip resources/views.backup-260430 entirely (different root),
        // skip self::EXCLUDED_FILES, skip this test file itself, assert zero hits.
    }
}
```
**Confirmed exclusion list (independently re-grepped this session, matches CONTEXT.md exactly):**
- `resources/views.backup-260430/pdf/rams.blade.php`
- `resources/views.backup-260430/pdf/rams.blade - keep boarder.php`
- `resources/views.backup-260430/pdf/rams.blade-keep-borders.php`
- `resources/views/pdf/rams.blade - keep boarder.php`
- `resources/views/pdf/rams.blade-keep-borders.php`

`.blade.php` files resolve to extension `php` under PHP's `SplFileInfo::getExtension()` (it
splits on the *last* dot), so the existing `phpFilesUnder()` recursive-glob helper in
`HazardInjectionPathsRemovedGuardTest` needs no modification to also catch Blade files — point it
at `resources/views` in addition to `app`/`tests`. Zero existing test fixtures reference `FFP2`
anywhere (`grep -rl FFP2 tests/` returned empty), so there is no risk of the new test colliding
with legitimate test data.

### Dead-code correction to CONTEXT.md's D-04 inventory
`RiskMatrixService` (the file carrying the "FFP2 or FFP3" hedge at `:133`) has **zero callers**
anywhere in `app/`, `tests/`, or `resources/` — `grep -c RiskMatrixService
app/Services/RiskMatrixService.php` returns `1` (only its own class declaration). It is dead
code, unreachable from any live request. **Still must be fixed** — D-04's static ban test does
not care about reachability — but the planner and reviewer should know this specific site is a
source-hygiene fix, not a currently-live-document risk, unlike the other 13 sites. The same
applies to `resources/views/pdf/rams-v2.blade.php`'s two occurrences (gated behind
`RAMS_UNIFIED_COMPOSER`, confirmed unset in production).

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---|---|---|---|
| Detecting a house-rule-violating control line | A new ad-hoc regex scattered at the point of use | `ControlTextRuleViolations::DETECTORS` registry entry | Single choke point; `reviewedToRisk()` never changes; docblock explicitly names this phase as the intended extension |
| Blocking generation on an undetected/surviving violation | A new exception class or catch mechanism | `RamsGenerationException` + the existing `try/catch` in `RamsController.php:597-604` (and the other 4 `upgrade()` call sites found this session) | GATE-09 already proved this shape on all real render paths |
| Recognising team-size / inch phrases in free text | A second parser | `ControlTextRuleViolations::parseStatedTeamSize()` / `parseStatedInches()` (already shared, moved here in Plan 27-08) | One implementation; a second copy risks silent divergence, exactly what `DisplayLiftPolicySourceGuardTest` exists to prevent |
| A repo-wide "this string must never appear" test | A hand-rolled grep script run manually | Copy `HazardInjectionPathsRemovedGuardTest`'s PHPUnit shape | Already proven, already wired into `phpunit.xml`'s default Feature suite, self-excluding pattern already solved |
| Env-flag rollback for a live-validated safety gate | A new feature-flag mechanism | `config('rams_tier1.display_lift_gate_enabled')` / `env('RAMS_DISPLAY_LIFT_GATE', true)` shape | Established, documented, checked "at every render" pattern per D-08 |

**Key insight:** every mechanism this phase needs already has a shipped precedent in Phases 26/27
— the risk is not "what pattern to invent" but "which of the 3 distinct data surfaces (hazard
`controls[]`, `ppe[]`/`ppe_matrix`, `exclusions[]`) does a given fix actually need to touch,"
since they are NOT unified by any single existing mechanism today.

---

## Common Pitfalls

### Pitfall 1: Assuming `ControlTextRuleViolations` covers the PPE array
**What goes wrong:** A plan adds `ffp2`/`confined_space` to `DETECTORS` and assumes GATE-06 is
now satisfied for "any FFP2 occurrence," but `reviewed_data['ppe']` and
`generated_data['ppe_matrix']` are never routed through this class at all.
**Why it happens:** The class's own docblock says "Phase 28 adds `ffp2` / `confined_space`,"
which reads as a complete instruction but only describes the hazard-`controls[]` surface it was
built for.
**How to avoid:** Explicitly scope a plan task to the PPE array as a separate, simpler
string-replace fix (see Q4 above). Verify with a fixture that sets `reviewed_data['ppe'] =
['Dust Mask (FFP2)']` and asserts the rendered document contains FFP3, not just that hazard
controls are clean.
**Warning signs:** A test suite that only exercises `ControlTextRuleViolations::detectAll()`
directly and never renders a document with an `FFP2`-carrying PPE array.

### Pitfall 2: The over-inclusive ceiling-void hazard on a pure ceiling-mount job
**What goes wrong:** Because `ceiling_void_access` fires from the SAME `ceiling_works` activity
that also drives `mounting_above_reach` and `display_mount_or_rack`, a job that only mounts a
ceiling speaker (never entering the void) gets a hazard row whose controls include void-ENTRY
instructions ("void visually inspected with a head torch," "asbestos register reviewed... before
any void is opened," "tiles removed one at a time") — text that doesn't apply and may read oddly
to a reviewer.
**Why it happens:** The include-when signal is a coarse activity flag, not a distinction between
"mounts on the ceiling surface" and "opens the ceiling void."
**How to avoid:** This is pre-existing behaviour, not a regression this phase introduces — RULE-10
is *satisfied* by it (the ceiling-load sentence does land). Flag it as an Open Question for the
planner rather than silently fixing scope not asked for; splitting the hazard into two
(mount-only vs. void-entry) is more surface than this phase's stated boundary.
**Warning signs:** A professional review flagging "why does this ceiling-speaker-only job talk
about asbestos register review."

### Pitfall 3: Treating `RamsDisplayPatchService`'s seed as reaching already-reviewed documents
**What goes wrong:** Adding RULE-09's sentence to the 5-item default exclusions array and
assuming ROADMAP criterion 3 is satisfied for "a generated RAMS" in general — it is only satisfied
for documents that have **never** been through Save Review.
**Why it happens:** The seed condition is `!isset($rd['exclusions'])`; any prior Save Review,
even a no-op one, permanently sets the key.
**How to avoid:** Quantify (Q3) how many live documents already have a persisted, non-null
`exclusions` key before deciding whether a migration is needed, mirroring the `controls_reviewed`
backfill precedent (Plan 27-08).
**Warning signs:** ROADMAP criterion 4's regeneration proof passing on 21CQ30960 specifically
(which may be a fresh-enough document) while older live documents silently lack the sentence.

### Pitfall 4: Believing the "FFP2 or FFP3" hedge is a live-document risk
**What goes wrong:** Treating `RiskMatrixService.php:133` with the same urgency as the 13 other
live sites, when it has zero callers anywhere in the codebase.
**Why it happens:** It reads exactly like the other live sites and CONTEXT.md's framing ("the
hedge... always gets picked up") doesn't distinguish reachability.
**How to avoid:** Still fix it (D-04's ban test doesn't care about reachability, and dead code
that's never called can become live again by accident), but don't spend verification effort
trying to reach it via a real generation path — it cannot be reached that way today.
**Warning signs:** A plan task that tries to write a dual-path/live-regeneration test specifically
for `RiskMatrixService`, discovers no caller exists, and either invents one (scope creep) or gets
stuck.

### Pitfall 5: Assuming the AI-extraction prompt is out of this phase's scope
**What goes wrong:** GATE-07's proof corpus is built only from `HazardTemplateSeeder` and
`LegacyHazardNameFoldMap` text (the CONTEXT.md-named sources), missing that
`PromptBuilderService.php:152` hands the extraction AI "confined spaces" as an example category —
a live, ongoing source of the exact defect the gate exists to catch, on every AI-extracted RAMS
with drawings supplied.
**Why it happens:** The prompt file isn't in CONTEXT.md's `<canonical_refs>` "Code this phase
changes" list, so it's easy to treat as out of scope.
**How to avoid:** The phase does not need to (and per its boundary, should not) edit the prompt —
but GATE-07's proof corpus and its live-regeneration check should include a fixture reproducing
what this prompt could plausibly cause an AI to emit, since a runtime gate is explicitly meant to
catch defects a static fix on the seeder text does not.
**Warning signs:** GATE-07 is proven only against seeded/legacy text and never against a
synthetic "AI extracted a hazard literally named 'Confined Space'" fixture.

---

## Code Examples

### The two-tier precedence GATE-06/07 will extend (`reviewedToRisk()`)
```php
// Source: app/Services/RamsBuilderService.php (Plan 27-08 shape, read in full this session)
// Tier 1: any detected house-rule violation replaces the WHOLE control list with
// current library text, regardless of controls_reviewed.
$violations = \App\Services\Rams\ControlTextRuleViolations::detectAll($controls);
if (! empty($violations)) {
    $controls = $libraryTemplate->controls; // current library text wins outright
    $controlsReplacedReason = array_values($violations);
} elseif (! $controlsReviewed) {
    // Tier 2: no human ever touched these controls — library default wins.
    $controls = $libraryTemplate->controls;
}
// else: engineer-edited, clean — stands as typed.
```

### GATE-09's throwing shape to mirror for GATE-06/07
```php
// Source: app/Services/Rams/RamsComplianceUpgradeService.php::upgrade() (read this session)
if (config('rams_tier1.display_lift_gate_enabled', true)) {
    $ramsData = self::enforceDisplayLiftGate($ramsData); // throws RamsGenerationException
}
// Phase 28 equivalent, config-gated behind its OWN flag per D-08 (not RAMS_DISPLAY_LIFT_GATE):
if (config('rams_tier1.ffp2_confined_space_gate_enabled', true)) {
    $ramsData = self::enforceFfp2AndConfinedSpaceGate($ramsData);
}
```

### The static-scan test shape to copy (Q5)
```php
// Source: tests/Feature/Rams/HazardInjectionPathsRemovedGuardTest.php (read in full this session)
foreach ($files as $file) {
    $real = realpath($file);
    if ($real !== false && $real === $thisTestPath) { continue; }
    $contents = file_get_contents($file);
    foreach ($forbiddenStrings as $needle) {
        if (str_contains($contents, $needle)) {
            $offenders[] = "{$file} contains '{$needle}'";
        }
    }
}
$this->assertEmpty($offenders, ...);
```

### The exact production query for Q3 (run as `stcav`, read-only)
```bash
cd /path/to/rams.21stcav.com
php artisan tinker --execute="
\$docs = \App\Models\RamsDocument::query()->select('id','status','reviewed_data','generated_data')->get();
\$ffp2 = 0; \$confinedRaw = 0; \$exclusionsSet = 0; \$total = \$docs->count();
foreach (\$docs as \$d) {
    \$blob = json_encode(\$d->reviewed_data ?? []) . json_encode(\$d->generated_data ?? []);
    if (stripos(\$blob, 'FFP2') !== false) { \$ffp2++; }
    if (stripos(\$blob, 'confined space') !== false) { \$confinedRaw++; }
    if (isset(\$d->reviewed_data['exclusions'])) { \$exclusionsSet++; }
}
echo \"total={\$total} ffp2={\$ffp2} confined_space_raw_substring={\$confinedRaw} exclusions_already_set={\$exclusionsSet}\n\";
"
```
Note `confined_space_raw_substring` is a BLUNT count (will include the app's own negating
sentence) — once the `confined_space` detector exists, re-run the count using
`ControlTextRuleViolations::detect()` against each hazard's `controls[]` array specifically, for
an accurate affirmative-only count.

---

## State of the Art

| Old Approach (pre-Phase-27) | Current Approach (this session, verified) | When Changed | Impact |
|---|---|---|---|
| Reviewed hazard controls pass through 1:1, never re-checked against the library | `ControlTextRuleViolations` tier-1 detector registry, always wins over `controls_reviewed` | Plan 27-08, 2026-08-26 | Phase 28's two new detectors plug into an already-proven mechanism |
| No independent re-check of generation output | `RamsGenerationException` + `enforceDisplayLiftGate()`-shaped throw, wired into `RamsComplianceUpgradeService::upgrade()` (reachable from 5+ call sites, verified this session) | Plan 27-03/27-06/27-07 | GATE-06/07 have a proven throw-and-surface path to reuse verbatim |
| `RAMS_UNIFIED_COMPOSER=true` V2 pipeline assumed forthcoming | Still `false` in production as of this session (confirmed via `config/rams.php:43` default + prior-phase docs; live value itself not directly re-confirmed on the VPS this session) | Ongoing since Phase 26 | Any V2-only fix is currently a no-op for real users |

**Deprecated/outdated:** None specific to this phase — no library versions or deprecated APIs are
in play; this is pure internal business logic.

---

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|---|---|---|
| A1 | `RAMS_UNIFIED_COMPOSER` is still `false` in the live production `.env` (not re-checked on the VPS this session, only inferred from `config/rams.php`'s default and prior-phase documentation) | Q2, State of the Art | If it has since been flipped `true`, the V2 exclusions/DTO path becomes the live one — still reaches the same `RamsDisplayPatchService::patch()` seed per this session's trace of `DocxBuilderServiceV2.php:107`, so the Q2 conclusion should hold either way, but this was not independently re-verified live |
| A2 | The proposed `confined_space`/`ffp2` detector regexes (Q4 code examples) are a starting proposal, not validated against the FULL corpus (all 18 seeded hazards' full control text, `LegacyHazardNameFoldMap` history, the AI-prompt-derived fixture) | Q4 | A false positive would silently overwrite an engineer's deliberate wording; a false negative leaves the defect unfixed. Must be proven with the corpus test before shipping, mirroring `ControlTextRuleViolationsTest::test_no_seeded_library_control_is_ever_flagged()` |
| A3 | The Q3 production counts (0 verified) — this session gives the exact query but has not run it against production | Q3 | The planner may under- or over-scope a backfill migration without this number |

**If this table is empty:** N/A — see entries above.

---

## Open Questions

1. **Does the ceiling-void hazard's over-inclusion (Pitfall 2) need splitting into two hazards
   (mount-only vs. void-entry)?**
   - What we know: the current single hazard satisfies RULE-10's "statement lands in output"
     requirement as written.
   - What's unclear: whether a professional reviewer will accept void-entry-specific controls
     appearing on a job that never enters the void.
   - Recommendation: out of this phase's stated boundary (RULE-10 only requires the statement to
     land); note it as a carried-forward observation, do not attempt to split the hazard here.

2. **Should the GATE-06/07 flag be one combined flag or two independent flags?**
   - What we know: D-08 requires a NEW flag (not reusing `RAMS_DISPLAY_LIFT_GATE`), following the
     `RAMS_DISPLAY_LIFT_GATE` shape.
   - What's unclear: CONTEXT.md says "GATE-06 and GATE-07 get their own flag(s)" (plural,
     ambiguous on whether that means one flag for both or one each).
   - Recommendation: one shared flag (e.g. `RAMS_PPE_CEILING_ELECTRICAL_GATE`) is simpler and
     matches the phase's own framing ("ship GATE-06 and GATE-07 in the same phase so neither
     fires against a still-broken default") — they are designed to be rolled back together. A
     planner wanting independent rollback should use two; either is defensible, but say which was
     chosen and why (mirrors D-05's "say which, and why" convention).

3. **Does `RamsRefreshComplianceCommand` (found this session, not named in CONTEXT.md) offer a
   ready-made remediation path for already-stored documents?**
   - What we know: it re-runs `RamsComplianceUpgradeService::upgrade()` over stored
     `generated_data` outside a full regeneration (`RamsRefreshComplianceCommand.php:185`).
   - What's unclear: whether it also re-runs `reviewedToRisk()`'s tier-1 hazard-control
     correction (it operates on `generated_data`, which is downstream of `reviewedToRisk()` —
     likely does NOT re-trigger the `ControlTextRuleViolations` correction, only the `upgrade()`
     pipeline stages).
   - Recommendation: read `RamsRefreshComplianceCommand.php` in full during planning before
     deciding it can substitute for a proper backfill migration on hazard-control text; it is
     confirmed useful for `ppe_matrix` (which `addPpeMatrix()` rebuilds unconditionally inside
     `upgrade()`), less certain for `ppe`/`exclusions`.

---

## Validation Architecture

### Test Framework
| Property | Value |
|---|---|
| Framework | PHPUnit (Laravel 12's `php artisan test` wrapper), config at `phpunit.xml` |
| Config file | `phpunit.xml` — `Unit`/`Feature` suites, `snapshot` group excluded by default |
| Quick run command | `php artisan test --filter=ControlTextRuleViolations` (or the new test class name) |
| Full suite command | `php artisan test` (excludes `@group snapshot`; run those explicitly with `--group=snapshot` when touching DOCX/PDF byte output) |

### Phase Requirements → Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|---|---|---|---|---|
| RULE-01 | FFP3 + face-fit replaces FFP2 at all 14 live sites | unit + static scan | `php artisan test --filter=FfpTwoBannedFromSourceTest` | ❌ Wave 0 |
| RULE-06 | Ceiling hazard title consistent across fold map/seeder/requirement (D-05) | unit | `php artisan test --filter=LegacyHazardNameFoldMapTest` (extend existing) | ✅ (extend) |
| RULE-09 | Electrical scope boundary lands in `exclusions` unconditionally | feature | `php artisan test --filter=ExclusionsComposer` or a new `RamsDisplayPatchServiceTest` | ❌ Wave 0 |
| RULE-10 | Ceiling-load statement lands whenever `ceiling_works` activity present | feature (already provable via `HazardIncludeWhenResolverTest`-style fixture) | `php artisan test --filter=HazardIncludeWhenResolver` | ✅ if such a test exists — verify during planning; not confirmed present this session |
| GATE-06 | Errors on any FFP2 occurrence surviving to generation | feature (revert-and-restore, dual-path, per GATE-09 precedent) | `php artisan test --filter=Ffp2ConfinedSpaceGateTest` | ❌ Wave 0 |
| GATE-07 | Errors on affirmative confined-space mislabel, clean on negation | unit + feature | `php artisan test --filter=ControlTextRuleViolationsTest` (extend) + new gate test | ✅ (extend) / ❌ (gate test, Wave 0) |

### Sampling Rate
- **Per task commit:** `php artisan test --filter=<TouchedClass>`
- **Per wave merge:** `php artisan test` (default suite, excludes `snapshot` group)
- **Phase gate:** Full suite green, plus an explicit `--group=snapshot` run if any DOCX/PDF
  template byte output changed, before `/gsd:verify-work`

### Wave 0 Gaps
- [ ] `tests/Feature/Rams/FfpTwoBannedFromSourceTest.php` (or similarly named) — repo-wide static
      ban, copying `HazardInjectionPathsRemovedGuardTest`'s shape
- [ ] A GATE-06/07 throw-and-surface test suite mirroring `DisplayLiftGateTest` +
      `DisplayLiftDualPathTest` + `DisplayLiftSaveReviewGateTest` + `DisplayLiftPdfSourceTest`
      (four files, ~65 tests, in Phase 27) — confirm during planning whether all four dual-path
      shapes are needed or a subset suffices
- [ ] A fixture/test proving the PPE-array closed-vocabulary fix (Pitfall 1) — no existing test
      touches `reviewed_data['ppe']` → rendered-document FFP3 substitution
- [ ] Confirm whether an existing `HazardIncludeWhenResolverTest`-style test already proves Q1's
      mechanism (`ceiling_works` → `ceiling_void_access`) — not located this session; if absent,
      one is needed to make Q1's finding regression-proof

*(No existing test infrastructure gaps for the fold-map/drift-guard surface — those tests exist
and only need extending, not creating from scratch.)*

---

## Sources

### Primary (HIGH confidence — direct code read + empirical execution, this session)
- `app/Services/Rams/HazardIncludeWhenResolver.php` — full read
- `app/Services/EquipmentClassifierService.php` — full ACTIVITY_MAP read
- `app/Services/RiskTemplateResolverService.php` — full read
- `app/Services/RamsBuilderService.php` — targeted reads (`runFromReview`, `reviewedToClassified`,
  `reviewedToRisk`, `pipeline`/`runPipeline`, `upgrade()` call sites)
- `app/Services/DocxBuilderService.php` — lines 1-150, 600-950 read in full
- `app/Services/Rams/RamsDisplayPatchService.php` — full read
- `app/Support/Rams/SectionComposers/ExclusionsComposer.php` — full read
- `app/Services/Rams/ControlTextRuleViolations.php` — full read
- `app/Http/Controllers/RamsController.php` — targeted reads (`review()`, `updateAndDownload()`,
  `:590-610`, `:690-710`, `:840-860`)
- `app/Services/Rams/RamsComplianceUpgradeService.php` — `upgrade()`, `addPpeMatrix()`
- `app/Services/RiskMatrixService.php` — targeted read + reachability grep
- `app/Services/PromptBuilderService.php` — targeted read (`buildFromFiles()`)
- `tests/Feature/Rams/HazardInjectionPathsRemovedGuardTest.php`,
  `tests/Feature/Rams/DisplayLiftPolicySourceGuardTest.php`,
  `tests/Feature/Rams/VendoredSkillDriftGuardTest.php` — full reads
- `.planning/phases/27-manual-handling-display-lift-house-rules/27-08-PLAN.md`,
  `27-VERIFICATION.md` — full reads
- Empirical: `php artisan tinker` execution against the real seeded `hazard_templates` table and
  real `EquipmentClassifierService`/`HazardIncludeWhenResolver` classes, this session (local
  environment, not production)
- Independent re-grep of all 14 live FFP2 sites + 5 backup sites (cross-verified against
  CONTEXT.md's inventory, exact match)

### Secondary (MEDIUM confidence)
- `.planning/reference/21cav-rams-skill/references/house-rules.md` — read in full (source-of-truth
  document, not application code)
- `.planning/ROADMAP.md`, `.planning/REQUIREMENTS.md`, `.planning/STATE.md` — read for phase
  boundary and cross-phase context

### Tertiary (LOW confidence / UNVERIFIED)
- Production document counts for Q3 (no DB access this session — exact query given above)
- Live value of `RAMS_UNIFIED_COMPOSER` on the actual production `.env` (inferred from config
  default + prior-phase documentation, not re-checked live this session)

---

## Metadata

**Confidence breakdown:**
- Standard stack: N/A — no new packages
- Architecture (Q1, Q2, Q4 mechanism): HIGH — directly traced and, for Q1, empirically executed
  against real code and real seeded data
- Q3 (production quantities): LOW / UNVERIFIED — no DB access this session; exact reproducible
  query provided
- Q5 (static test precedent): HIGH — direct precedent read in full, independently re-verified by
  grep
- Pitfalls: HIGH — each is a directly-traced code gap (PPE array, exclusions round-trip,
  RiskMatrixService reachability), not speculation

**Research date:** 2026-09-05
**Valid until:** 14 days (fast-moving milestone — Phases 26/27 both had same-day corrections to
this exact class of finding; re-verify before planning if more than 2 weeks elapse or if any of
Phases 26/27/29/30/31 land first)
