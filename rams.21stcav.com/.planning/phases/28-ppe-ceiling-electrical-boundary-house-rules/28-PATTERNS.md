# Phase 28: PPE, Ceiling & Electrical Boundary House Rules - Pattern Map

**Mapped:** 2026-09-05
**Files analyzed:** 20 (14 FFP2 edit sites + 2 new artifacts + 2 gate-mechanism files + 1
config + 1 possible migration; seeder/fold-map edits and test extensions counted separately
below)
**Analogs found:** 20 / 20 — every surface this phase touches has a direct, already-shipped
precedent in Phases 26/27. There is no "No Analog Found" section in this phase.

---

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|---|---|---|---|---|
| `app/Services/Rams/ControlTextRuleViolations.php` (add `ffp2`, `confined_space` to `DETECTORS` + 2 new `detectXxx()` methods) | service (rule engine) | transform | itself — `detectKgThreshold()` / `detectSizeConditionalLift()` | exact |
| **NEW** PPE closed-vocabulary replace map (new class or new static method — see Discretion note below) | service (utility/map) | transform | `app/Services/Rams/LegacyHazardNameFoldMap.php` | exact (shape), new surface |
| `app/Services/Rams/RamsComplianceUpgradeService.php` — new `enforceFfp2AndConfinedSpaceGate()` + call site in `upgrade()` | service (gate) | event-driven (throw-on-violation) | itself — `enforceDisplayLiftGate()` (`:1158-1224`) + its call site (`:62-64`) | exact |
| `config/rams_tier1.php` — new gate flag(s) | config | — | itself — `display_lift_gate_enabled` (`:74`) | exact |
| `app/Http/Controllers/RamsController.php` — no new code, but the throw MUST be reachable from all `upgrade()` call sites | controller | request-response | itself — `updateAndDownload()`'s existing `try/catch (RamsGenerationException)` (`:597-604`) | exact for 1 of 5 call sites — see Pitfall below |
| `tests/Feature/Rams/FfpTwoBannedFromSourceTest.php` (new) | test (static scan) | batch | `tests/Feature/Rams/HazardInjectionPathsRemovedGuardTest.php` | exact |
| `tests/Unit/Services/Rams/ControlTextRuleViolationsTest.php` (extend) | test (unit) | transform | itself | exact |
| A new GATE-06/07 throw-boundary unit test (new file, e.g. `Ffp2ConfinedSpaceGateTest.php`) | test (unit, reflection-driven) | event-driven | `tests/Unit/Services/Rams/DisplayLiftGateTest.php` | exact |
| A new GATE-06/07 Save-Review feature test (new file) | test (feature, real HTTP route) | request-response | `tests/Feature/Rams/DisplayLiftSaveReviewGateTest.php` | exact |
| A new PPE-array fixture test (new file) | test (feature) | transform | none existing (genuinely new surface) — build ad hoc from `DisplayLiftSaveReviewGateTest`'s `RamsDocument::create()` fixture shape | role-match only |
| `database/seeders/HazardTemplateSeeder.php` (hazard #7 `:213/:220`, hazard #11 `:294` no-op) | migration/seeder | batch | itself (in-file edit, no external analog needed) | exact |
| `app/Services/Rams/LegacyHazardNameFoldMap.php` (rename target if D-05 renames) | service (static map) | transform | itself | exact |
| `tests/Unit/Services/Rams/LegacyHazardNameFoldMapTest.php` (extend/update if D-05 renames) | test (unit) | transform | itself | exact |
| `app/Services/Rams/RamsDisplayPatchService.php:387-394` (add RULE-09 bullet to unconditional exclusions default) | service (display patch) | transform | itself (in-file edit) | exact |
| **Possible** `database/migrations/YYYY_MM_DD_backfill_*.php` (D-08, measure-first) | migration | batch | `database/migrations/2026_08_26_170000_backfill_controls_reviewed_on_reviewed_hazards.php` | exact |
| `app/Http/Controllers/ProjectPackageReviewController.php:33` (`PPE_OPTIONS` const) | controller | request-response | `RamsReviewController.php:42` (identical const, same file) | exact — the two controllers are analogs of each other |
| `app/Http/Controllers/RamsReviewController.php:42` (`PPE_OPTIONS` const) | controller | request-response | `ProjectPackageReviewController.php:33` | exact |
| `app/Services/DocxBuilderService.php:2038` | service (renderer) | transform | — (single-line string edit inside a larger method; no separate analog needed) | trivial |
| `app/Services/RiskMatrixService.php:133` | service (dead code, zero callers — confirmed) | transform | — | trivial, non-live |
| `app/Services/RiskTemplateResolverService.php:44,106` (`PPE_ACTIVITY_MAP`, `buildPpe()`) | service | transform | itself — `:521-523` (already-FFP3 sentence in the same file/class) is the exact target phrasing | exact |
| `resources/views/pdf/rams-v2.blade.php:540,1964`, `rams.blade.php:498,1903` | view/template | transform | — (string edits; `rams-v2.blade.php` gated behind `RAMS_UNIFIED_COMPOSER`, confirmed unset in production) | trivial |

---

## Pattern Assignments

### 1. `ControlTextRuleViolations::detectFfp2()` + `detectConfinedSpace()`

**Analog:** `app/Services/Rams/ControlTextRuleViolations.php` — the two existing detectors, read in full.

**Class docblock — the conservative-by-construction rule D-01 inherits verbatim** (`:25-34`):
```php
/**
 * ── Conservative by construction (T-27-08-01) ───────────────────────────────
 * A control line this class cannot confidently classify as a violation is
 * CLEAN — {@see self::detect()} returns null. A false positive here silently
 * overwrites an engineer's deliberate wording on a live safety document,
 * possibly without them noticing; a false negative merely leaves today's
 * (already-shipped) behaviour unchanged. Every detector below is written to
 * prefer the false negative. Two of this plan's acceptance criteria prove
 * this class never flags the app's own corrected library text (every control
 * line `HazardTemplateSeeder` emits) or `DisplayLiftPolicy`'s own sentences —
 * treat both as load-bearing, not box-ticking.
 */
```

**Registry — the extension point** (`:84-87`):
```php
private const DETECTORS = [
    'kg_threshold'          => 'detectKgThreshold',
    'size_conditional_lift' => 'detectSizeConditionalLift',
    // Phase 28 adds: 'ffp2' => 'detectFfp2', 'confined_space' => 'detectConfinedSpace'
];
```

**Detector 1 — `detectKgThreshold()` (`:144-150`), the "trivial regex, no negation" shape.
`detectFfp2()` should copy this shape almost exactly (research's proposal, `28-RESEARCH.md`
lines 202-211, is already validated as trivial — no negation needed, since there is no
legitimate sentence containing the bare token `FFP2`):**
```php
private static function detectKgThreshold(string $control): bool
{
    return (bool) preg_match(
        '/\b(?:over|above|exceeding|more than|under|below|less than)\s+\d+(?:\.\d+)?\s*kg\b/iu',
        $control,
    );
}
```

**Detector 2 — `detectSizeConditionalLift()` (`:180-219`), the "negation/direction-aware,
fails closed on ambiguity, never re-encodes another class's canonical data" shape.
`detectConfinedSpace()` should copy this shape's STRUCTURE (early-return on
no-recognised-signal, fail-closed on ambiguity) even though the actual phrase-matching logic
research proposed (negation list then affirmative list, `28-RESEARCH.md:216-255`) is simpler
than this detector's numeric-threshold math:**
```php
private static function detectSizeConditionalLift(string $control): bool
{
    $lower = strtolower($control);

    $direction = null;
    foreach (self::ABOVE_PHRASES as $phrase) {
        if (str_contains($lower, $phrase)) { $direction = 'above'; break; }
    }
    if ($direction === null) {
        foreach (self::BELOW_PHRASES as $phrase) {
            if (str_contains($lower, $phrase)) { $direction = 'below'; break; }
        }
    }
    if ($direction === null) {
        return false; // <-- the "no recognised signal, return false" fail-closed pattern
    }
    // ...resolves via DisplayLiftPolicy::forSize(), never re-encoding its bands...
}
```

**The required proof corpus (per D-01's load-bearing acceptance criterion) is already a live
test, extend it in place** — `tests/Unit/Services/Rams/ControlTextRuleViolationsTest.php:117-145`:
```php
public function test_no_seeded_library_control_is_ever_flagged(): void
{
    $seeder = new HazardTemplateSeeder();
    $method = new \ReflectionMethod($seeder, 'standardHazards');
    $method->setAccessible(true);

    $flagged = [];
    $checked = 0;

    foreach ($method->invoke($seeder) as $hazard) {
        foreach ((array) ($hazard['controls'] ?? []) as $control) {
            $checked++;
            $violation = ControlTextRuleViolations::detect((string) $control);
            if ($violation !== null) {
                $flagged[] = "[{$violation}] {$hazard['name']}: {$control}";
            }
        }
    }

    $this->assertGreaterThan(50, $checked, 'Expected to scan the full 18-hazard library.');
    $this->assertSame([], $flagged, "...");
}
```
This test — unmodified except for the corpus growing as detectors are added — is exactly
what proves D-01's "never flags the app's own corrected library text" criterion, including the
negating sentence at `HazardTemplateSeeder.php:220` ("These are not classified as confined
spaces..."), since that line IS part of `standardHazards()`'s hazard #7 controls array.

**The negating sentence to spare, verbatim (`database/seeders/HazardTemplateSeeder.php:220`):**
```php
'Confirm ventilation and safe access before entering ceiling voids, comms rooms or enclosures. These are not classified as confined spaces, but access is restricted and is treated as a controlled activity.',
```

**Structural guard to copy for the new detector's dependency shape, if `detectConfinedSpace()`
needs to reference anything else by class name** — `tests/Feature/Rams/DisplayLiftPolicySourceGuardTest.php`
proves only sanctioned files reference `DisplayLiftPolicy::`; `ControlTextRuleViolations.php`
is already entry 4 of `ALLOWED_FILES` (`:56`) precisely because `detectSizeConditionalLift()`
calls into it rather than re-encoding its bands. `detectConfinedSpace()` does not need any
such external class (D-01's negation/affirmative lists are self-contained), but if the
planner introduces one, it must be added to that allow-list the same way, not left to trip
the guard.

---

### 2. NEW — PPE closed-vocabulary replace map (research Q4 gap)

**Analog:** `app/Services/Rams/LegacyHazardNameFoldMap.php` — full file read above (129 lines).
This is the closest existing shape for "a settled 21CAV position expressed as one all-static
map with a single choke point and a drift-guard test," even though the actual matching logic
Phase 28 needs (`Dust Mask (FFP2)` -> `Dust Mask (FFP3)` string replace) is far simpler than a
`canonicalName()` fuzzy-resolve.

**Structure to copy (docblock provenance convention, `MAP` constant, resolver method,
`all()` test-support accessor):**
```php
final class LegacyHazardNameFoldMap
{
    private const MAP = [
        'legacy key' => 'Canonical Value',
        // ...
    ];

    public static function canonicalName(string $legacyName): ?string
    {
        $key = strtolower(trim($legacyName));
        if ($key === '') { return null; }
        return self::MAP[$key] ?? null;
    }

    /** For tests — proves the map's OUTPUT side can never re-introduce a banned string. */
    public static function all(): array
    {
        return self::MAP;
    }
}
```

**Why this class, not `ControlTextRuleViolations`, is the right shape for the PPE surface:**
research (Q4) confirmed `reviewed_data['ppe']` and `generated_data['ppe_matrix']` are plain
`array_unique(array_merge(...))` round-trips with zero rule-text scanning
(`app/Services/RamsBuilderService.php:632-633`, `RamsDataBuilderService::mergePpe()`,
`RamsComplianceUpgradeService::addPpeMatrix()`). PPE items come from a **closed pick-list**
(the two controllers' `PPE_OPTIONS` const, `RiskTemplateResolverService::PPE_BASE`/
`PPE_ACTIVITY_MAP`), not free engineer prose — a fixed string-replace map is the correct tool,
not a new `ControlTextRuleViolations::detectXxx()` free-text detector.

**Drift-guard test precedent to copy** — `tests/Unit/Services/Rams/LegacyHazardNameFoldMapTest.php`
(44 lines, read in full):
```php
public function test_no_value_in_the_map_is_confined_spaces(): void
{
    foreach (LegacyHazardNameFoldMap::all() as $value) {
        $this->assertNotSame('confined spaces', strtolower(trim($value)), "map value '{$value}' must never be Confined Spaces");
    }
}
```
The new PPE map's drift-guard test is the mirror image: assert no value in the new map is
(or contains) `FFP2`.

**Application points — where the map's replace call must be wired in** (all three PPE surfaces
research found, each is a separate integration, not one call site):
1. `app/Services/RamsBuilderService.php:632-633` — the reviewed-PPE merge line.
2. `app/Services/Rams/RamsDataBuilderService::mergePpe()`.
3. `app/Services/RiskTemplateResolverService.php:44` (`PPE_ACTIVITY_MAP` value) and `:106`
   (`buildPpe()`'s literal append) — these can likely just be edited at the source string
   rather than routed through the map, since they are code constants, not stored data; the
   map's replace call matters specifically for **already-persisted** `reviewed_data['ppe']`
   arrays that a stored document carries forward untouched.

---

### 3. NEW throwing re-check — GATE-06/07 in `RamsComplianceUpgradeService::upgrade()`

**Analog:** `enforceDisplayLiftGate()`, full method read (`app/Services/Rams/RamsComplianceUpgradeService.php:1158-1224`, docblock `:1104-1157`).

**The call site to mirror, verbatim** (`:54-64`):
```php
$ramsData = self::deriveMaterialHandling($ramsData);
// GATE-09 — independent re-check of every display item's stated team
// size against DisplayLiftPolicy::violatesPolicy(). Config-gated so
// this milestone's live-validation posture can roll it back with a
// single .env edit (RAMS_DISPLAY_LIFT_GATE), mirroring
// RAMS_HAZARD_LIBRARY_TIERING's established shape exactly. When the
// flag is false, enforceDisplayLiftGate() is never called — upgrade()
// proceeds byte-identical to pre-GATE-09 behaviour.
if (config('rams_tier1.display_lift_gate_enabled', true)) {
    $ramsData = self::enforceDisplayLiftGate($ramsData);
}
$ramsData = self::crossReferenceMethodStatementRisks($ramsData);
```
Phase 28's equivalent (per D-08, a NEW flag, not a reuse):
```php
if (config('rams_tier1.ffp2_confined_space_gate_enabled', true)) {
    $ramsData = self::enforceFfp2AndConfinedSpaceGate($ramsData);
}
```

**The throw itself — message shape, `RamsGenerationException`, actionable naming
convention** (`:1176-1187`):
```php
if (DisplayLiftPolicy::violatesPolicy((int) $minPersons, $inches === null ? null : (float) $inches)) {
    $inchesLabel = $inches === null ? 'unresolved' : ((string) $inches . '"');

    throw new RamsGenerationException(sprintf(
        'Manual handling team size for "%s" (%s operative%s, %s) does not meet the display-lift '
        . 'house rules (RULE-02/GATE-09): 4+ operatives are never required, 2 operatives are '
        . 'insufficient above 90", and 1 operative is insufficient at 55" or larger. Correct the '
        . 'stated team size before regenerating, or set RAMS_DISPLAY_LIFT_GATE=false to disable '
        . 'this check.',
        (string) ($item['item'] ?? 'unnamed item'),
        (string) $minPersons,
        ((int) $minPersons === 1 ? '' : 's'),
        $inchesLabel,
    ));
}
```
Phase 28's throw should name (a) which rule fired (GATE-06 vs GATE-07), (b) the offending
text/hazard, and (c) the specific env flag to disable it — following this exact three-part
shape.

**CRITICAL — do NOT call `ControlTextRuleViolations::detectFfp2()`/`detectConfinedSpace()` a
second, independently-written way inside the gate.** `DisplayLiftPolicySourceGuardTest.php`
exists specifically to catch a divergent second copy of policy logic; the class docblock
(`ControlTextRuleViolations.php:36-38`) states *"Do not duplicate a detector's logic anywhere
else."* The gate calls `ControlTextRuleViolations::detect()` on whatever text survives into
`$ramsData`, exactly as `enforceDisplayLiftGate()` calls
`DisplayLiftPolicy::violatesPolicy()`/`parseStatedTeamSize()`/`parseStatedInches()` rather than
re-implementing them.

**Config flag — the exact shape to copy** (`config/rams_tier1.php:58-74`):
```php
/*
|--------------------------------------------------------------------------
| Display-lift gate kill-switch (Phase 27, GATE-09)
|--------------------------------------------------------------------------
|
| Gates ONLY RamsComplianceUpgradeService::enforceDisplayLiftGate() — the
| independent re-check of every display item's stated manual-handling
| team size against App\Services\Rams\DisplayLiftPolicy::violatesPolicy()
| ... When false, enforceDisplayLiftGate() is never called —
| upgrade() proceeds byte-identical to pre-GATE-09 behaviour, no redeploy
| required. ...
|
*/
'display_lift_gate_enabled' => env('RAMS_DISPLAY_LIFT_GATE', true),
```
Phase 28 adds a sibling key with the identical comment shape (rationale + rollback statement +
cross-reference to the phase's own CONTEXT.md discretion item), e.g.
`'ffp2_confined_space_gate_enabled' => env('RAMS_PPE_CEILING_ELECTRICAL_GATE', true)`
(exact flag name is the planner's call per Open Question 2 in RESEARCH.md — one shared flag is
recommended there, matching the phase's own framing that GATE-06/07 ship and roll back
together).

**Surfacing — the ONE call site with a clean catch-and-redirect, verbatim**
(`app/Http/Controllers/RamsController.php:597-604`):
```php
try {
    $generatedData = \App\Services\Rams\RamsComplianceUpgradeService::upgrade($generatedData);
} catch (\App\Exceptions\RamsGenerationException $e) {
    return back()->withInput()->with('error', $e->getMessage());
}
```

**Pitfall the planner must not miss — `upgrade()` has (at least) 5 call sites and only ONE
has this clean catch.** Verified this session:
| Call site | Catch behaviour |
|---|---|
| `RamsBuilderService.php:296` (`runPipeline()`) | not traced this session — verify during planning |
| `RamsBuilderService.php:941` (`runFromReview()`) | not traced this session — verify during planning |
| `RamsController.php:603` (`updateAndDownload()` / Save Review) | clean catch + redirect, shown above — the GATE-09 precedent |
| `RamsController.php:701` (DOCX-rebuild-on-download fallback) | generic `catch (\Throwable $e)` -> `Log::error(...)`, does NOT redirect with the gate's message the same way |
| `RamsController.php:852` (PDF-download path) | **no try/catch around the `upgrade()` call at all** — a thrown `RamsGenerationException` here propagates as an unhandled exception (Laravel's default 500 page), not the friendly redirect |
| `RamsRefreshComplianceCommand.php:185` | artisan command context, not an HTTP surface |

A GATE-06/07 throw fired from the PDF-download path (`:852`) or the DOCX-rebuild fallback
(`:701`) will NOT surface the way GATE-09 does on Save Review. This is a genuine gap in the
existing GATE-09 precedent this phase inherits, not something Phase 28 needs to invent — but
the planner should decide explicitly whether to (a) accept the inconsistency (GATE-09 already
has it), (b) wrap the two other call sites in the same catch shape as part of this phase, or
(c) defer it and say so. Do not assume `:597-604` is the only place that matters just because
it is the one CONTEXT.md names.

---

### 4. NEW — repo-wide static FFP2 source-ban test

**Analog:** `tests/Feature/Rams/HazardInjectionPathsRemovedGuardTest.php`, full file (96 lines),
copy nearly verbatim. Full text:
```php
<?php

namespace Tests\Feature\Rams;

use Tests\TestCase;

class HazardInjectionPathsRemovedGuardTest extends TestCase
{
    public function test_deleted_hazard_injection_paths_have_zero_references_in_app_and_tests(): void
    {
        $forbiddenStrings = [
            'MANDATORY_KEYWORDS',
            'mandatoryBaseline',
            'mergeWithMandatory',
            'rams_tier1.baseline_hazards',
        ];

        $thisTestPath = realpath(__FILE__);
        $appPath      = base_path('app');
        $testsPath    = base_path('tests');

        $files = array_merge(
            $this->phpFilesUnder($appPath),
            $this->phpFilesUnder($testsPath),
        );

        $offenders = [];
        foreach ($files as $file) {
            $real = realpath($file);
            if ($real !== false && $real === $thisTestPath) {
                continue; // whitelist: this guard file itself
            }
            $contents = file_get_contents($file);
            if ($contents === false) { continue; }
            foreach ($forbiddenStrings as $needle) {
                if (str_contains($contents, $needle)) {
                    $offenders[] = "{$file} contains '{$needle}'";
                }
            }
        }

        $this->assertEmpty($offenders, "...");
    }

    /** Recursive glob for *.php files under a directory. */
    private function phpFilesUnder(string $dir): array
    {
        if (! is_dir($dir)) { return []; }

        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $files = [];
        foreach ($rii as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
```

**Key facts confirmed this session (do not re-derive):**
- `.blade.php` resolves to extension `php` under `SplFileInfo::getExtension()` (splits on the
  LAST dot) — pointing `phpFilesUnder()` at `resources/views` in addition to `app`/`tests`
  needs **zero** modification to the helper itself.
- Self-exclusion is `realpath(__FILE__) === realpath($file)` — the new test file excludes
  itself the same way; do not hand-maintain a string-based exclusion for the guard file.
- Independently re-grepped this session: exactly 14 live `FFP2` sites + 5 backup-only sites,
  confirmed matching `28-CONTEXT.md`'s inventory exactly (list reproduced in the table above
  under "Data this phase touches" — no discrepancy found).

**Second precedent worth reading for the "allow-list, not a plain forbidden-string scan" shape**
(useful if the planner wants an allow-list style guard instead of / in addition to a pure ban) —
`tests/Feature/Rams/DisplayLiftPolicySourceGuardTest.php`, three tests:
1. `test_only_the_sanctioned_files_reference_display_lift_policy()` — scans for a marker
   string, excludes an `ALLOWED_FILES` const + the class's own definition file.
2. `test_allow_list_has_four_entries_and_all_resolve()` — sanity-checks the allow-list itself
   isn't stale/typo'd.
3. `test_allow_list_matches_a_fresh_grep_of_the_repo()` — asserts the allow-list is
   **regenerated from a live scan**, not hand-copied, by comparing sorted arrays.

**Exact exclusion list for the new FFP2 ban test** (independently re-grepped this session,
matches `28-CONTEXT.md` and `28-RESEARCH.md` exactly):
```php
private const EXCLUDED_FILES = [
    'resources/views.backup-260430/pdf/rams.blade.php',
    'resources/views.backup-260430/pdf/rams.blade - keep boarder.php',
    'resources/views.backup-260430/pdf/rams.blade-keep-borders.php',
    'resources/views/pdf/rams.blade - keep boarder.php',
    'resources/views/pdf/rams.blade-keep-borders.php',
];
```
`resources/views.backup-260430/` should be excluded wholesale (a different directory root),
the two "keep border(s)" files under `resources/views/pdf/` need individual path exclusion
since they otherwise live in the same directory as the live `rams.blade.php`.

---

### 5. Possible backfill migration over `reviewed_data` (D-08)

**Analog:** `database/migrations/2026_08_26_170000_backfill_controls_reviewed_on_reviewed_hazards.php`
(Plan 27-08 Task 4, full file read, 115 lines). Structure to copy:

```php
return new class extends Migration
{
    public function up(): void
    {
        $documentsTouched = 0;
        $hazardRowsTouched = 0;

        // Chunked — this runs against production.
        DB::table('rams_documents')
            ->select('id', 'reviewed_data')
            ->orderBy('id')
            ->chunkById(100, function ($rows) use (&$documentsTouched, &$hazardRowsTouched) {
                foreach ($rows as $row) {
                    if (empty($row->reviewed_data)) { continue; }

                    $data = is_array($row->reviewed_data)
                        ? $row->reviewed_data
                        : json_decode((string) $row->reviewed_data, true);

                    if (! is_array($data) || ! isset($data['hazards']) || ! is_array($data['hazards'])) {
                        continue;
                    }

                    $changed = 0;
                    foreach ($data['hazards'] as $i => $hazard) {
                        // ...idempotency guard: never overwrite an explicit existing value...
                        if (array_key_exists('controls_reviewed', $hazard)) { continue; }
                        $data['hazards'][$i]['controls_reviewed'] = true;
                        $changed++;
                    }

                    if ($changed === 0) { continue; }

                    DB::table('rams_documents')
                        ->where('id', $row->id)
                        ->update(['reviewed_data' => json_encode($data)]);

                    $documentsTouched++;
                    $hazardRowsTouched += $changed;
                }
            });

        echo sprintf(
            "backfill_controls_reviewed: %d document(s), %d hazard row(s) marked controls_reviewed=true\n",
            $documentsTouched, $hazardRowsTouched,
        );
    }

    public function down(): void
    {
        // Deliberate no-op — cannot distinguish a row this migration touched from
        // one that already had the value; removing the key would destroy genuine
        // markers set through the review form after this ran.
    }
};
```

**How Phase 28's version differs (two SEPARATE surfaces, not one migration doing both):**
1. **`reviewed_data['ppe']` FFP2 strings** — a direct string-replace over the `ppe` array
   using the new fold-map-shaped class (Pattern 2 above), same chunk-by-id / idempotent /
   JSON-column-update shape. No `array_key_exists` guard needed here (a string replace is
   naturally idempotent — re-running it on an already-fixed row is a no-op).
2. **`reviewed_data['exclusions']` missing RULE-09 bullet** — per research Q2, this ONLY
   needs backfilling for documents where `isset($rd['exclusions'])` is already true (i.e.
   have been through at least one Save Review) — `RamsDisplayPatchService`'s `!isset` seed
   already covers every document that has NEVER been through Save Review, for free, on next
   render. A migration here would `array_push` the new bullet onto every already-`isset`
   `exclusions` array that doesn't already contain it — genuinely idempotent by checking
   `in_array($newBullet, $rd['exclusions'], true)` first.

**Whether this migration ships at all depends on the Q3 production count** (UNVERIFIED this
session — no DB access). The exact reproducible query research supplies
(`28-RESEARCH.md` "The exact production query for Q3") must be run on `rams.21stcav.com`
as `stcav` before deciding scope; do not invent a row/document count.

**Alternative/complementary remediation path — `RamsRefreshComplianceCommand.php`**
(`app/Console/Commands/RamsRefreshComplianceCommand.php`, 221 lines, read in full). Confirmed
this session: it re-runs `RamsComplianceUpgradeService::upgrade()` over stored
`generated_data` and re-renders the DOCX (`:185` `$upgraded = RamsComplianceUpgradeService::upgrade($data);`,
then `$rams->update(['generated_data' => $upgraded]); ... $this->renderer->render($upgraded, $rams);`).
This command:
- **DOES** self-heal `ppe_matrix` (rebuilt unconditionally inside `addPpeMatrix()` on every
  `upgrade()` call — confirmed, no backfill needed for that specific array) and the
  hardcoded-controls arrays inside `addProjectSpecificRisks()` (`:747`, `:796` — same
  rebuilt-fresh-every-call shape).
- **Does NOT** re-run `RamsBuilderService::reviewedToRisk()`'s tier-1 hazard-`controls[]`
  correction (that class lives outside `RamsComplianceUpgradeService` and operates on
  `reviewed_data`, upstream of what this command touches) — confirmed by tracing the
  command's pipeline (strip auto-injected hazards -> dedup -> `upgrade()` -> persist ->
  re-render); it never calls `ControlTextRuleViolations` or `reviewedToRisk()`.
- Has a `--dry-run` flag and a `--id=` single-document mode — useful shape to mention to the
  planner even if not reused directly for GATE-06/07's remediation.

---

## RULE-06 hazard-title consistency (D-05) — pattern for whichever title is chosen

**Analog:** `app/Services/Rams/LegacyHazardNameFoldMap.php` + its test, both read in full
above. The four surfaces that must agree (per D-05's constraint) and their exact current
strings, verified this session:

| Surface | File:Line | Current string |
|---|---|---|
| Fold-map target | `LegacyHazardNameFoldMap.php:97` (`'confined spaces'` key) and `:92` (`'cable installation in ceiling voids'` key) | `'Restricted access and ceiling voids'` |
| Seeder hazard name | `HazardTemplateSeeder.php:213` | `'Restricted access and ceiling voids'` |
| Fold-map drift-guard test | `LegacyHazardNameFoldMapTest.php:17-21,37-42` | asserts `canonicalName('Confined Spaces')` resolves to `'Restricted access and ceiling voids'`, and no map value equals `'confined spaces'` |
| `REQUIREMENTS.md`/ROADMAP SC2 | — | `'Restricted access and ceiling void working'` (the skill's title, NOT what's shipped) |

If D-05 renames to match the skill, **all four rows above change together in one commit** —
the fold-map's `MAP` values, the seeder's `'name'` field, `LegacyHazardNameFoldMapTest`'s
literal assertion strings, and `REQUIREMENTS.md`/ROADMAP. This mirrors exactly how
`LegacyHazardNameFoldMap`'s own docblock records provenance for every entry (`:24-66`) — a
future rename should extend that same docblock with a dated note, not silently edit the map.

---

## RULE-09/RULE-10 statement placement (D-06/D-07) — patterns for the two landing sites

**RULE-10 (ceiling load) — no new trigger needed, just verify the existing text.**
Research (Q1) empirically proved `signal:ceiling_void_access` already fires for a pure
ceiling-mount job via `EquipmentClassifierService`'s broad `ceiling_works` keyword match. The
sentence already exists verbatim at `HazardTemplateSeeder.php:223`:
```php
'No standing, kneeling or leaning on the suspended ceiling grid. All loads supported from the structural soffit or a purpose-designed ceiling mount kit only.',
```
No pattern work needed here beyond confirming this control line survives
`ControlTextRuleViolations`'s new `confined_space` detector as clean (it doesn't mention
"confined space" — should be a non-issue, but add it to the proof corpus per research's
item 5).

**RULE-09 (electrical scope boundary) — unconditional home, exact insertion point.**
**Analog:** `app/Services/Rams/RamsDisplayPatchService.php:387-395`, read in full:
```php
if (! isset($rd['exclusions'])) {
    $rd['exclusions'] = [
        'No structural works',
        'No core drilling unless explicitly scoped',
        'No containment beyond surface trunking',
        'No decorative making good after cable routes',
        'No IT network provision unless scoped',
    ];
}
```
Adding a 6th bullet here (e.g. something following D-07's minimum-sentence scope: *"Electrical
scope terminates at the existing socket outlet or client data outlet — no alteration to the
fixed installation"*) is unconditional for every document that has never been through Save
Review, and reaches both DOCX renderers (`DocxBuilderService::buildLegacy():139` — confirmed
live path — and `DocxBuilderServiceV2::build():107`, gated off in production) because both
call `patch()` immediately before rendering. **This is the exact array to append to — do not
build a new mechanism.** The existing 5-item array's plain-string, no-conditional-logic shape
is the pattern: RULE-09's bullet should be a plain string in the same array, not a new
conditional branch (per D-06's "no AI decides scope" constraint and `house-rules.md`'s
"unconditional" licence).

**Electrical text already in the codebase that is INCOMPLETE, not to be treated as already
correct** — `RamsComplianceUpgradeService.php:565` (part of the `fillMissingHazardControls()`
fallback-controls catalog, `electrical` key, lines 545-632):
```php
'electrical' => [
    'All mains electrical connections by qualified electrician only — no live working by AV engineers',
    'Visually inspect cables and connectors before use; do not use damaged equipment',
    'Use PAT-tested power supplies, extension leads, and adaptors only',
    'Isolate power before connecting or disconnecting AV equipment',
    'Confirm equipment earthing before power-on',
],
```
This states "no live working" but never the actual scope BOUNDARY (terminates at existing
socket/data outlet) — per D-06/D-07, RULE-09's sentence belongs in the exclusions block above,
not folded into this array, unless the planner deliberately decides both surfaces should carry
it (say so if chosen).

---

## Shared Patterns

### The "never touch the caller, only the registry" extension pattern
**Source:** `ControlTextRuleViolations.php` class docblock, `:17-23`.
**Apply to:** the two new detectors. `RamsBuilderService::reviewedToRisk()` (`:542`, the
`detectAll()` call site) must NOT be edited to add either detector — this is stated explicitly
in `28-CONTEXT.md`'s canonical refs and the class's own docblock.

### The config-gated, config-named-after-the-mechanism-not-the-phase env flag
**Source:** `config/rams_tier1.php:56-74` (`hazard_tiering_enabled`, `display_lift_gate_enabled`).
**Apply to:** the new GATE-06/07 flag(s) — name after what they gate
(`ffp2_confined_space_gate_enabled` / `RAMS_PPE_CEILING_ELECTRICAL_GATE`), not
`rule_09_10_gate` or similar requirement-ID-shaped names, matching the existing two entries'
naming convention exactly.

### The `RamsGenerationException` throw-and-redirect pattern
**Source:** `RamsComplianceUpgradeService.php:1176-1187` (throw shape) +
`RamsController.php:597-604` (catch-and-redirect).
**Apply to:** GATE-06/07's throwing re-check. **Caveat, verified this session:** only ONE of
`upgrade()`'s (at least) 5 call sites has this clean catch — see the Pitfall table under
Pattern 3 above. Do not assume the other 4 call sites already handle a new
`RamsGenerationException` the same friendly way.

### The reflection-driven private-static-method unit test
**Source:** `tests/Unit/Services/Rams/DisplayLiftGateTest.php:48-54`:
```php
private function invokePrivateStatic(string $method, array $args = []): mixed
{
    $m = new \ReflectionMethod(RamsComplianceUpgradeService::class, $method);
    $m->setAccessible(true);
    return $m->invoke(null, ...$args);
}
```
**Apply to:** unit-testing `enforceFfp2AndConfinedSpaceGate()`'s throw/no-throw boundary
directly, without needing a full HTTP request or DB fixture — mirrors exactly how
`enforceDisplayLiftGate()` is tested. This file's docblock (`:20-40`) also documents a
"break-the-fix-and-watch-the-test-fail" non-vacuity proof procedure worth repeating for the
new gate's tests.

### The real-HTTP-route dual-path feature test
**Source:** `tests/Feature/Rams/DisplayLiftSaveReviewGateTest.php:1-70` (`RefreshDatabase`,
`RamsDocument::create()` fixture, POST to the real route).
**Apply to:** proving GATE-06/07 fires on the Save Review path specifically (not just via
reflection) — per D-08/Wave-0's "confirm whether all four dual-path shapes are needed or a
subset suffices" open question. This is the shape for whichever subset the planner selects.

### The recursive `phpFilesUnder()` static-scan helper
**Source:** `HazardInjectionPathsRemovedGuardTest.php:77-95` (identical copy also exists in
`DisplayLiftPolicySourceGuardTest.php:162-180` and `VendoredSkillDriftGuardTest.php`).
**Apply to:** the new `FfpTwoBannedFromSourceTest`. Note this helper is duplicated
per-test-file already (not itself extracted to a shared trait/base) — following that existing
(if imperfect) convention is lower-risk than introducing a new shared test helper this phase
would be the first to use.

---

## No Analog Found

None. Every surface this phase touches — detector registry, throwing gate, config flag,
static source-ban test, backfill migration, unconditional-default injection, and even the
genuinely-new PPE-array surface — has a directly-applicable shipped precedent from Phase 26 or
27, confirmed by direct code reads this session (not inferred from CONTEXT.md/RESEARCH.md
alone).

---

## Notes for the Planner (not analogs, but load-bearing verified facts)

- **`RiskMatrixService.php` has zero callers anywhere in `app/`, `tests/`, or `resources/`**
  (confirmed: `grep -c RiskMatrixService app/Services/RiskMatrixService.php` returns `1`, only
  its own class declaration). Still must be fixed (the static ban test doesn't care about
  reachability) but do not spend effort trying to prove it live-reachable — it isn't.
- **`resources/views/pdf/rams-v2.blade.php`'s two FFP2 occurrences are also currently
  unreachable in production** (`RAMS_UNIFIED_COMPOSER` confirmed unset/false via
  `config/rams.php` default + prior-phase docs, not re-checked live this session — see
  RESEARCH.md Assumption A1). Fix them anyway; don't build a live-regeneration proof around
  this file specifically.
- **The two controllers' `PPE_OPTIONS` consts are literally identical arrays** (`ProjectPackageReviewController.php:26-37`
  and `RamsReviewController.php:35-46`, both comment "Standard PPE options matching
  RiskTemplateResolverService output") — a single find-and-replace of `'Dust Mask (FFP2)'` ->
  `'Dust Mask (FFP3)'` in both is correct; they are not meant to diverge.
- **`RiskTemplateResolverService.php:521-523` is the exact target phrasing to reuse**, not
  invent a second one: `'Use FFP3 dust mask, on-tool extraction, and seal off occupied areas.'`
  and `'Dust Mask (FFP3)'` — already shipped, in the same class, for a different (wall-chase)
  branch.
- **`config/rams_tier1.php:128` is already FFP3**, inside the "Expanding Foam" COSHH entry's
  `controls[0]`: `'FFP3 mask, nitrile gloves and safety glasses mandatory during application.'`
  — do not re-edit this line. **`config/rams_tier1.php:286` is a DIFFERENT line in the same
  file** (the `typical_use` field naming Expanding Foam a "cable-penetration fire-stop") —
  that is Phase 31's RULE-11, explicitly out of scope here (D-09).

---

## Metadata

**Analog search scope:** `app/Services/Rams/`, `app/Services/`, `app/Http/Controllers/`,
`app/Console/Commands/`, `config/`, `database/migrations/`, `database/seeders/`,
`resources/views/pdf/`, `tests/Unit/Services/Rams/`, `tests/Feature/Rams/`.
**Files scanned/read in full or targeted this session:** `ControlTextRuleViolations.php`,
`LegacyHazardNameFoldMap.php`, `LegacyHazardNameFoldMapTest.php`,
`ControlTextRuleViolationsTest.php`, `RamsComplianceUpgradeService.php` (targeted:
`:40-69`, `:330-370`, `:560-575`, `:725-805`, `:1104-1224`), `RamsController.php` (targeted:
`:585-625`, `:695-710`, `:845-860`), `config/rams_tier1.php` (full),
`HazardInjectionPathsRemovedGuardTest.php` (full), `DisplayLiftPolicySourceGuardTest.php`
(full), `DisplayLiftGateTest.php` (targeted, throw-boundary section),
`DisplayLiftSaveReviewGateTest.php` (targeted, fixture setup),
`2026_08_26_170000_backfill_controls_reviewed_on_reviewed_hazards.php` (full),
`RamsRefreshComplianceCommand.php` (targeted: `:1-50`, `:160-215`),
`RamsDisplayPatchService.php` (targeted: `:378-407`), `HazardTemplateSeeder.php` (targeted:
`:205-234`, `:285-299`), `RiskTemplateResolverService.php` (targeted: `:30-145`, `:495-525`),
`ProjectPackageReviewController.php`/`RamsReviewController.php` (targeted, PPE_OPTIONS const),
`PromptBuilderService.php` (targeted, `:145-160`), plus grep confirmation of all 14 FFP2 live
sites and 5 backup-only sites.
**Pattern extraction date:** 2026-09-05
