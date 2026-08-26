# Phase 27: Manual-Handling & Display-Lift House Rules - Pattern Map

**Mapped:** 2026-08-26
**Files analyzed:** 9 (1 new, 8 modified/extended)
**Analogs found:** 9 / 9

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|---|---|---|---|---|
| `app/Services/Rams/DisplayLiftPolicy.php` (NEW) | policy/utility (stateless value class) | transform (deterministic derivation, no I/O) | `app/Services/Rams/LegacyHazardNameFoldMap.php` | exact (named precedent, D-03) |
| `app/Services/Rams/RamsComplianceUpgradeService.php` — `suggestHandlingMethod()` / `deriveMaterialHandling()` | service (static pipeline step) | transform | itself (existing method, in-place edit) | exact — editing established convention |
| `app/Services/Rams/RamsComplianceUpgradeService.php` — new `enforceDisplayLiftGate()` (GATE-09) | service (validation gate step) | transform / control-flow (throws) | `RamsBuilderService.php:869-873` pre-render guard (`\RuntimeException` on bad state) | role-match |
| `app/Services/MethodStatementService.php` (~:461 fallback string) | service (static fallback content) | transform | itself (existing string literal, in-place edit) | exact |
| `app/Services/Worksheet/SafetyProfileService.php` (`LARGE_DISPLAY_INCHES`, `roomContainsLargeDisplay()`) | service (rule-based classifier) | transform | itself (existing method, in-place edit); pattern also cross-referenced against `suggestHandlingMethod()`'s inch-parsing regex | exact |
| `database/seeders/HazardTemplateSeeder.php` (Manual handling row, `:109-127`) | seeder (version-controlled DB content) | batch (idempotent upsert-by-name) | itself (existing seeded row, in-place edit) | exact |
| `tests/Unit/Services/Rams/DisplayLiftPolicyTest.php` (NEW) | test (unit, pure class) | request-response (call → assert) | `tests/Unit/Services/Rams/LegacyHazardNameFoldMapTest.php` | exact |
| `tests/Unit/Services/Rams/DisplayLiftGateTest.php` (NEW) or extension of `RamsComplianceUpgradeService` unit tests | test (unit, throw/no-throw) | event-driven (exception assertion) | `RamsBuilderService.php`'s existing guard + `LegacyHazardNameFoldMapTest`'s assertion style | role-match |
| Extension of `tests/Feature/Rams/ReviewedHazardTieringTest.php`-style dual-path proof (or a new `DisplayLiftDualPathTest.php`) | test (feature, dual entry-point) | event-driven (real job dispatch, real DB) | `tests/Feature/Rams/ReviewedHazardTieringTest.php` (runFromReview path) + `tests/Feature/Rams/ManualRamsCreationTest.php` (runPipeline/buildFromForm path) | exact (two analogs, one per entry point) |
| Structural guard test for GATE-09 (band source-of-truth) | test (structural/allow-list scan) | batch (file-scan assertion) | `tests/Feature/Rams/HazardResolutionPathGuardTest.php` | exact |
| `config/rams_tier1.php` — new `display_lift_gate_enabled` key | config | request-response (read via `config()`) | `hazard_tiering_enabled` entry, same file (`:56`) | exact |

## Pattern Assignments

### `app/Services/Rams/DisplayLiftPolicy.php` (NEW — policy class)

**Analog:** `app/Services/Rams/LegacyHazardNameFoldMap.php` (Phase 26, Plan 26-08) — explicitly named as the precedent in CONTEXT.md D-03.

**Structural shape to mirror** (full file read, 129 lines):
- `final class LegacyHazardNameFoldMap` — no constructor, no instance state, everything `static`. Mirror this: `final class DisplayLiftPolicy` with all-static methods, no instance ever created.
- A single `private const MAP = [...]` holding the settled data (lines 76-98). For `DisplayLiftPolicy` this becomes the band thresholds — but note the analog's shape is a flat associative array; `DisplayLiftPolicy` needs a small ordered list of band rules (threshold → outcome) rather than a lookup table, so the *const* idiom carries over but the *shape* should be an ordered array of band definitions, not a hash map.
- **Provenance docblock is mandatory and extensive** (lines 5-67) — a class-level comment explaining WHY each entry exists, tracing exactly where each number/string came from (git commits, plan IDs, live evidence). For `DisplayLiftPolicy`, this docblock must record: the D-01 correction history (floor-only → floor-plus-ladder → the 55″ single-operative reinstatement), citing `27-CONTEXT.md` D-01 and its correction block by name, so a future reader doesn't mistake the final bands for drift. This is the project's established way of preventing exactly that kind of confusion (see `26-VERIFICATION.md` gap this class was built to prevent).
- **The nullable-or-value return pattern already exists in this class**:
  ```php
  // Source: app/Services/Rams/LegacyHazardNameFoldMap.php:107-116
  public static function canonicalName(string $legacyName): ?string
  {
      $key = strtolower(trim($legacyName));

      if ($key === '') {
          return null;
      }

      return self::MAP[$key] ?? null;
  }
  ```
  This is the direct precedent for "resolve or return null, never guess" — but `DisplayLiftPolicy` needs a **three-way** outcome (no-row / team-size / silent-floor-fallback), not a two-way nullable. Model this as a method returning a small array/DTO, e.g. `forSize(?float $inches): ?array` where `null` means "no manual-handling row at all" (the ≤14″ exclusion) and a non-null array always carries a resolved `min_persons` + `sentence` (covering both genuine bands AND the D-05 silent-2 fallback for unresolvable size — `$inches === null` is NOT the same "no row" case as ≤14″, it must resolve to the 2-person floor, not to null). This is the critical shape distinction CONTEXT.md's correction block calls out: "not two outcomes, three."
- **A public `all()`-style accessor for test introspection**:
  ```php
  // Source: app/Services/Rams/LegacyHazardNameFoldMap.php:118-128
  public static function all(): array
  {
      return self::MAP;
  }
  ```
  Recommend an equivalent for `DisplayLiftPolicy` (e.g. `bands(): array`) so `DisplayLiftPolicyTest` can assert on the band table directly without triggering every boundary via `forSize()` calls one at a time — mirrors how `LegacyHazardNameFoldMapTest::test_no_value_in_the_map_is_confined_spaces()` iterates `all()`.
- **A second, independent validation method for GATE-09**, per D-03 ("the gate and the generator must resolve the team size through the same call, so a future edit cannot make them disagree") and RESEARCH.md's anti-pattern warning ("don't trust it's the same function so it can't disagree"). `LegacyHazardNameFoldMap` has no gate-equivalent (its consumer, `HazardLibraryService::fuzzyMatch()`, doesn't need one), so this piece has no direct analog in that file — RESEARCH.md's own sketch is the best available model:
  ```php
  // Source: 27-RESEARCH.md Pattern 1 (recommended shape, not yet in the codebase)
  public static function violatesPolicy(int $statedPersons, ?float $inches): bool
  {
      if ($statedPersons < 1 || $statedPersons > 3) {
          return true; // never 4+; adjust lower bound per the corrected <55" band
      }
      // ... band-specific checks against the corrected D-01 thresholds (14 / 55 / 90) ...
      return false;
  }
  ```
  **Important:** RESEARCH.md's own sketch predates the D-01 correction (single-operative band below 55″) — the planner must NOT copy its numeric bands verbatim; only the *shape* (independent re-check, boolean violation) is the reusable part.

---

### `app/Services/Rams/RamsComplianceUpgradeService.php` — `suggestHandlingMethod()` (RULE-02 ladder + RULE-12 ordering)

**Analog:** itself — in-place edit of the existing method, same file's own conventions.

**Current structure to edit** (full method read, `:1231-1330`+):
```php
// Source: app/Services/Rams/RamsComplianceUpgradeService.php:1231-1250
private static function suggestHandlingMethod(string $description, int $qty = 1): ?string
{
    $desc = strtolower($description);

    // Extract inch size — "98″", "98\"", "98 inch", "98-inch", "10.1″".
    $inches = null;
    if (preg_match('/(\d+(?:\.\d+)?)\s*(?:″|"|\\\\"|\xE2\x80\xB3|inch|in\b|-inch)/u', $desc, $m)) {
        $inches = (float) $m[1];
    }

    // Small touch / scheduling / control panels are NOT a manual-handling
    // concern — they are sub-2 kg single-hand items. Skip them entirely
    // even though the description contains "screen".
    $isSmallPanel = $inches !== null && $inches <= 14
        && (str_contains($desc, 'scheduling') || str_contains($desc, 'touch panel')
            || str_contains($desc, 'booking panel') || str_contains($desc, 'control panel'));
    if ($isSmallPanel) {
        return null;
    }
```
This `$isSmallPanel` exclusion (≤14″ + keyword) is the exact "no-row" outcome `DisplayLiftPolicy` must also expose (per the corrected D-01, this is NOT the same as the <55″ single-operative band — keep both).

**The ladder being removed** (`:1252-1265`):
```php
// Source: app/Services/Rams/RamsComplianceUpgradeService.php:1252-1265 — TO BE REPLACED
if (str_contains($desc, 'display') || str_contains($desc, ' tv ') || str_contains($desc, 'television')
    || (str_contains($desc, 'screen') && $inches !== null && $inches >= 32)) {
    if ($inches !== null && $inches >= 85) {
        return 'Team lift — minimum 4 persons for ' . rtrim(rtrim((string) $inches, '0'), '.') . '″ size class. '
             . 'Use lifting handles or strap kit. Two persons take the front, two the rear; do not pivot on edges. '
             . 'Use screen protection during transit. Do not lay face-down.';
    }
    if ($inches !== null && $inches >= 65) {
        return 'Team lift — minimum 3 persons recommended for ' . rtrim(rtrim((string) $inches, '0'), '.') . '″. '
             . 'Two persons may lift if using a panel-lift trolley. Use screen protection during transit. Do not lay face-down.';
    }
    return 'Team lift (2 persons minimum). Use screen protection during transit. Do not lay face-down.';
}
```
Replace the body of this `if` block with a call into `DisplayLiftPolicy::forSize($inches)`, using its returned sentence. **D-02 constraint:** do not carry the `>=65in -> "Two persons may lift if using a panel-lift trolley"` aid-as-substitute wording into the new >90″ band — the new class's sentence for that band must state the 3rd operative as a floor, no aid-discharges-it clause.

**RULE-12 root cause — branch order** (`:1267-1279`, the mount/bracket branch the display branch shadows):
```php
// Source: app/Services/Rams/RamsComplianceUpgradeService.php:1267-1279
if (str_contains($desc, 'mount') || str_contains($desc, 'bracket')) {
    if (str_contains($desc, 'multisurface') || str_contains($desc, 'small panel')
        || (str_contains($desc, 'mount') && str_contains($desc, '10.1'))) {
        return null;  // sub-1 kg, single hand
    }
    if (str_contains($desc, 'x-large') || str_contains($desc, 'xl ') || str_contains($desc, 'fusion')
        || str_contains($desc, 'large')) {
        return 'Team lift (2 persons minimum) — heavy display bracket. Pre-stage at install location to avoid double handling.';
    }
    return 'Single person lift for tilting/fixed wall mount. Check weight before lifting.';
}
```
This branch is evaluated **after** the display branch (`:1253`), so a description like "double-arm wall mount for 65 inch display" matches `str_contains($desc, 'display')` first. **Minimum fix (Claude's Discretion, accepted per RESEARCH.md finding):** move this `mount`/`bracket` check **before** the `display`/`tv`/`television`/`screen` check. Do not attempt weight-derivation (RULE-12's stronger clause) — RESEARCH.md confirmed `weight_kg` coverage on real RAMS-path quote-line data is effectively zero; record the deferral explicitly in the plan rather than silently narrowing scope.

**`deriveMaterialHandling()`'s summary statement** (unrelated numeric text, verify it does not also need editing) — `:1206-1210`:
```php
// Source: app/Services/Rams/RamsComplianceUpgradeService.php:1206-1210
'statement'       => $hasHeavy
    ? 'This installation includes heavy or bulky AV equipment requiring manual handling controls. '
      . 'Team lifts (minimum 2 persons) are required for items over 20 kg. '
      . 'Mechanical aids (trolley, lifter) must be used where available. '
      . 'Correct lifting technique must be adopted at all times.'
    : '...'
```
This is a generic weight-based statement (not display-specific), already says "minimum 2" which matches the floor — likely does not need a `DisplayLiftPolicy` read, but flag as considered/confirmed-not-applicable per the `RiskMatrixService`/`fillMissingHazardControls()` pitfall precedent (RESEARCH.md Pitfall 3) so a future reviewer doesn't re-raise it.

**`upgrade()`'s ordered pipeline — where the new gate step slots in:**
```php
// Source: app/Services/Rams/RamsComplianceUpgradeService.php:24-48
public static function upgrade(array $ramsData): array
{
    $ramsData = self::upgradeScopeOfWorks($ramsData);
    // ...
    $ramsData = self::deriveMaterialHandling($ramsData);
    $ramsData = self::crossReferenceMethodStatementRisks($ramsData);
    $ramsData = self::addCdmDutyHolders($ramsData);
    $ramsData = self::cleanTextArtifacts($ramsData);

    return $ramsData;
}
```
GATE-09's new `enforceDisplayLiftGate()` step goes immediately after `deriveMaterialHandling($ramsData)` (it needs `$ramsData['material_handling_derived']['items']` to exist) and before `cleanTextArtifacts()`.

---

### GATE-09 — enforcement mechanism (new `enforceDisplayLiftGate()` + env flag)

**Analog 1 (blocking-error precedent, "pre-render guard" shape):**
```php
// Source: app/Services/RamsBuilderService.php:869-873 (inside runPipeline())
if (empty($data) || ! array_key_exists('method_statement', $data)) {
    throw new \RuntimeException(
        'Pre-render guard failed: generated_data is empty or method_statement key is missing.'
    );
}
```
Mirror this shape exactly for the new gate step: a guard clause that throws `\RuntimeException` (or a dedicated subclass) when the derived data fails validation. Do **not** invent a new validator framework — RESEARCH.md confirms none exists and none is needed.

**Analog 2 (existing dead exception class available for reuse):**
```php
// Source: app/Exceptions/RamsGenerationException.php (full file, 10 lines)
namespace App\Exceptions;

use Exception;

class RamsGenerationException extends Exception
{
    //
}
```
Zero callers anywhere in the codebase today (confirmed by RESEARCH.md's grep). This is a legitimate candidate for GATE-09's thrown exception type if the planner wants a dedicated, catchable class rather than a bare `\RuntimeException` — note it currently extends `Exception`, not `RuntimeException`; if reused, either accept that hierarchy or add a purpose-built `DisplayLiftGateException extends \RuntimeException` alongside it. Either choice must still be caught by the existing `catch (\Throwable $e)` below since that catches everything.

**Analog 3 (the catch/surface mechanism this reuses, already wired to the DB + Blade view):**
```php
// Source: app/Jobs/BuildRamsDocumentJob.php:179-193
} catch (\Throwable $e) {
    Log::error('BuildRamsDocumentJob: Phase B generation failed', [
        'record_id' => $this->ramsDocumentId,
        'error'     => $e->getMessage(),
        'file'      => $e->getFile(),
        'line'      => $e->getLine(),
        'attempt'   => $this->attempts(),
    ]);

    $record->update([
        'status'        => RamsDocument::STATUS_FAILED,
        'error_message' => $e->getMessage(),
    ]);

    throw $e;
}
```
No edit needed here — this already wraps both `buildFromForm()` and `buildFromReview()` (confirmed by RESEARCH.md's trace). GATE-09 needs no new UI, no new exception hierarchy beyond one class — this catch block is the mechanism.

**Analog 4 (env-flag rollback shape to copy exactly):**
```php
// Source: config/rams_tier1.php:56
'hazard_tiering_enabled' => env('RAMS_HAZARD_LIBRARY_TIERING', true),
```
New entry, same file, same shape:
```php
'display_lift_gate_enabled' => env('RAMS_DISPLAY_LIFT_GATE', true),
```
Read at the call site the same way `hazard_tiering_enabled` is read elsewhere in the pipeline (`config('rams_tier1.hazard_tiering_enabled')` pattern) — e.g.:
```php
if (config('rams_tier1.display_lift_gate_enabled', true)) {
    self::enforceDisplayLiftGate($ramsData); // throws on violation
}
```

---

### `app/Services/MethodStatementService.php` (~:461 fallback string)

**Analog:** itself — the surrounding `fallbackPhases()`-style static array of ordered phase/step strings (full excerpt read, `:430-488`).

**Current string to edit:**
```php
// Source: app/Services/MethodStatementService.php:461
'Survey wall substrates, select fixings appropriate to the surface type, and mount displays and screens using two-person lifts, torquing fixings to manufacturer guidance.',
```
This sits inside `'title' => '4. Installation Works'`'s `'steps'` array (`:456-466`), itself one of six ordered phase blocks (`1. Pre-works` through `6. Final Checks and Handover`) each shaped `['title' => ..., 'steps' => [...]]`. Edit only this string's team-size clause to read from `DisplayLiftPolicy`'s generic sentence (or the >90″ band language, if this fallback text needs to express the ladder rather than a flat "two-person" claim — planner's call per D-03 "every stating point reads it"). Keep the surrounding array structure, quoting style (single-quoted, trailing comma), and phase ordering untouched.

---

### `app/Services/Worksheet/SafetyProfileService.php` (D-04, worksheet parity)

**Analog:** itself — full file read (129 lines), `LARGE_DISPLAY_INCHES` constant and `roomContainsLargeDisplay()`.

**Constant to remove:**
```php
// Source: app/Services/Worksheet/SafetyProfileService.php:23-24
public const LARGE_DISPLAY_INCHES = 55;  // ≥ this → two-person lift
public const HEAVY_ITEM_KG        = 25;  // ≥ this → heavy-item warning
```
Only `LARGE_DISPLAY_INCHES` is in scope (D-04); `HEAVY_ITEM_KG` is untouched.

**The method whose regex is insufficient on its own (Pitfall 1 — do not just delete the constant):**
```php
// Source: app/Services/Worksheet/SafetyProfileService.php:62-75
private function roomContainsLargeDisplay(array $items): bool
{
    foreach ($items as $i) {
        if (! is_array($i)) continue;
        $sizeIn = (int) ($i['display_size_in'] ?? 0);
        if ($sizeIn >= self::LARGE_DISPLAY_INCHES) return true;

        // Keyword fallback: scan name for explicit size tokens.
        $name = strtolower((string) ($i['name'] ?? $i['description'] ?? ''));
        if (preg_match('/\b(55|65|70|75|85|86|98|100)\s*[\"”]/u', $name)) return true;
        if (preg_match('/\b(55|65|70|75|85|86|98|100)\s*inch\b/u', $name)) return true;
    }
    return false;
}
```
The hardcoded size list (`55|65|70|75|85|86|98|100`) will not catch a 43″ description regardless of the constant's value. **Replace with the same general inch-parsing pattern `suggestHandlingMethod()` already uses** (`RamsComplianceUpgradeService.php:1238`):
```php
// Source: app/Services/Rams/RamsComplianceUpgradeService.php:1238 — the general-purpose regex to reuse
preg_match('/(\d+(?:\.\d+)?)\s*(?:″|"|\\\\"|\xE2\x80\xB3|inch|in\b|-inch)/u', $desc, $m)
```
This is the metadata-first, keyword-fallback shape this file's own class docblock (`:12-19`) already documents as the intended pattern — the fix stays within the file's established convention, it just needs the *general* regex, not the fixed-list one.

**Open question to resolve during planning, not silently default (RESEARCH.md Open Question 1):** whether the ≤14″ small-panel exclusion from `suggestHandlingMethod()` should be mirrored here too. `roomContainsLargeDisplay()` currently has no equivalent of `$isSmallPanel` — the existing pinned test asserting a 32″ monitor fires no warning must be explicitly revisited (see Test section below), not silently left in a contradictory state.

---

### `database/seeders/HazardTemplateSeeder.php` (Manual handling row)

**Analog:** itself — the seeded row's existing shape, in-place text edit (full excerpt read, `:109-127`).

**Current row (stale wording, live on production per Pitfall 2):**
```php
// Source: database/seeders/HazardTemplateSeeder.php:109-127
// ── 2. Manual handling (tier 2 — signal:display_mount_or_rack) ────
[
    'name'            => 'Manual handling',
    'description'     => 'Moving, lifting and positioning AV displays, mounts and equipment.',
    'pre_likelihood'  => 4,
    'pre_severity'    => 3,
    'post_likelihood' => 2,
    'post_severity'   => 3,
    'controls'        => [
        'Use mechanical aids (sack trucks, lifting trolleys, panel lifter) for items over 20 kg.',
        'Team lift required for all displays — minimum two operatives for every panel size. Mechanical aid used in addition where available.',
        'Removal of a display from an existing wall mount is the highest-risk lift on any strip-out. Load is controlled to the lowest practicable height with one operative each side before release from the mount.',
        'Pre-plan the route and clear all access paths before moving equipment. Passenger lift used between floors — no carrying on stairs.',
        'Wear appropriate gloves and safety footwear at all times.',
        'Conduct a task-specific manual handling assessment prior to every lift. Any operative may stop a lift.',
        'Take regular breaks to avoid fatigue during prolonged lifting tasks. Do not lay displays face-down.',
    ],
    'include_when'    => 'signal:display_mount_or_rack',
],
```
Note the wall-mount removal sentence (RULE-03) is **already present here**, verbatim matching `house-rules.md:13-16`'s required sequence — this row is NOT missing RULE-03's statement, it just needs the "minimum two operatives for every panel size" bullet (2nd control string) rewritten to the floor-plus-ladder wording (or to reference `DisplayLiftPolicy`'s generic sentence). Keep the `'controls'` array's ordering, quoting, and structure (`array<int, string>`) identical — only edit that one string's content.

**Deployment sequencing constraint (Pitfall 2):** this seeder must be re-run on live (`php artisan db:seed --class=HazardTemplateSeeder --force`, upsert-by-name, non-destructive) immediately after the code deploy — same runbook Phase 26 used — or the DB hazard row and the freshly-derived `material_handling_derived` text will disagree for any RAMS regenerated in the gap.

---

## Shared Patterns

### Deterministic-only, no-AI derivation
**Source:** `CLAUDE.md:12`, restated throughout `27-CONTEXT.md`/`27-RESEARCH.md`.
**Apply to:** `DisplayLiftPolicy`, `suggestHandlingMethod()`, RULE-03's strip-out signal, GATE-09. No AI call anywhere in this phase's derivation or enforcement path.

### Static-class, single-choke-point policy
**Source:** `app/Services/Rams/LegacyHazardNameFoldMap.php` (full file — see Pattern Assignments above).
**Apply to:** `DisplayLiftPolicy.php`. All-static, `final class`, one file, extensive provenance docblock, a `?`-nullable resolver method, and a public introspection accessor for tests.

### RULE-03 strip-out signal — reuse, do not reinvent
**Source:** `app/Services/Rams/HazardIncludeWhenResolver.php:83-89`:
```php
'strip_out_or_decommission' => [
    'strip-out',
    'strip out',
    'decommission',
    'removal of existing',
    'de-install',
],
```
Combined with the already-populated data bucket seen at the call site:
```php
// Source: app/Services/RamsBuilderService.php:277-281 (available before upgrade() runs, both entry points)
$data['scope_items'] = [
    'decommission' => (array) ($reviewedData['decommission_items'] ?? $data['scope_items']['decommission'] ?? []),
    'retained'     => (array) ($reviewedData['retained_items']     ?? $data['scope_items']['retained']     ?? []),
    'new_install'  => (array) ($reviewedData['new_install_items']  ?? $data['scope_items']['new_install']  ?? []),
];
```
**Apply to:** RULE-03's wall-mount-removal statement trigger — deterministic, zero new capture fields. The "deterministic real signal, not a hardcoded `false`" pattern precedent is `EquipmentClassifierService::textIndicatesDrilling()`:
```php
// Source: app/Services/EquipmentClassifierService.php:205-216
public function textIndicatesDrilling(string $text): bool
{
    $lower = strtolower($text);

    foreach (self::MOUNT_KEYWORDS as $kw) {
        if (str_contains($lower, $kw)) {
            return true;
        }
    }

    return false;
}
```
A simple keyword-array-driven boolean method — mirror this shape (not the array contents) for any RULE-03 helper method the planner adds.

### Both generation entry points must be proven, not one
**Source:** `27-RESEARCH.md` Summary; `26-VERIFICATION.md`'s hard-learned lesson (Phase 26 reopened twice for exactly this).
```php
// Source: app/Services/RamsBuilderService.php:284 (inside runFromReview())
$data = RamsComplianceUpgradeService::upgrade($data);
```
```php
// Source: app/Services/RamsBuilderService.php:881 (inside runPipeline())
$data = RamsComplianceUpgradeService::upgrade($data);
```
**Apply to:** every test that claims a Phase 27 behavior is "fixed" — must exercise both `runFromReview()` (via `ReviewedHazardTieringTest`'s pattern: real seeded DB, `BuildRamsDocumentJob::handle()`, `Http::fake()` for the AI call) and `runPipeline()`/`buildFromForm()` (via `ManualRamsCreationTest`'s pattern: POST to the manual-creation route, real queue dispatch, real job execution).

### Env-flag rollback (live-validation posture)
**Source:** `config/rams_tier1.php:56`, `env('RAMS_HAZARD_LIBRARY_TIERING', true)`.
**Apply to:** GATE-09's `RAMS_DISPLAY_LIFT_GATE` flag (see GATE-09 section above) — same file, same shape, default `true`.

## No Analog Found

None — every file this phase touches has a direct or near-direct analog already in the codebase (this phase is explicitly a "read the existing pattern, apply it" refactor per RESEARCH.md's closing insight, not new-territory work).

Two locations were investigated and confirmed **out of scope / do-not-touch** (recorded here so the planner doesn't waste effort or later get flagged as a missed location):
- `app/Services/RiskMatrixService.php:39` — dead code, zero live callers (confirmed by exhaustive grep), do not edit.
- `RamsComplianceUpgradeService::fillMissingHazardControls()` generic manual-handling bullets (`:523-590`) — unreachable against Phase-26-seeded hazards (guard only fires on empty `controls`, which seeded hazards never have); low-priority defensive wire-up only, not required for any success criterion.

## Test Pattern Assignments

### Unit test of the new policy class
**Analog:** `tests/Unit/Services/Rams/LegacyHazardNameFoldMapTest.php` (full file, 44 lines) — plain PHPUnit test, `Tests\TestCase` base (not `PHPUnit\Framework\TestCase` directly, unlike `SafetyProfileServiceTest`), no DB, no factories, one `assertSame`/`assertNull`/`assertNotSame` per case, plus one test that iterates the class's `all()` accessor to structurally guard the map's value-side. Mirror this exactly for `DisplayLiftPolicyTest`: no-row (≤14″) case, each band boundary (just-under-55, 55, just-under-90, 90, just-over-90), never-1/never-4 guard, and the D-05 unresolvable-size (`null` input) silent-2 case.

**Secondary analog for the plain-PHPUnit variant:** `tests/Unit/Services/Worksheet/SafetyProfileServiceTest.php` (extends `PHPUnit\Framework\TestCase` directly, no Laravel bootstrap) — relevant because `SafetyProfileServiceTest` itself needs extending for D-04, and its existing pinned assertion:
```php
// Source: tests/Unit/Services/Worksheet/SafetyProfileServiceTest.php:32-38
public function test_small_display_does_not_fire_two_person_lift(): void
{
    $out = $this->svc->profileRoom([], [
        ['name' => 'Samsung 32" LCD Monitor'],
    ]);
    $this->assertEmpty(array_filter($out, fn ($w) => str_starts_with($w, 'Large display')));
}
```
must be explicitly revisited under D-04's unconditional-floor requirement (this is RESEARCH.md's Pitfall 1 / Open Question 1) — do not leave this assertion silently contradicting the new behavior.

### GATE-09 throw/no-throw unit test
**Analog (shape, not content):** `RamsBuilderService.php:869-873`'s existing guard is what GATE-09 mirrors structurally; there is no existing *test* of that specific guard to copy directly, but `LegacyHazardNameFoldMapTest`'s boundary-by-boundary assertion style is the right granularity model. **Non-vacuity requirement** (explicit project convention, cited in RESEARCH.md from `.planning/quick/20260817-rams-generator-defects/SUMMARY.md`'s "revert each fix and observe failure" pattern): the test must be written to fail before the fix and pass after — assert the exception class thrown (`\RuntimeException` or `RamsGenerationException`/new subclass), not merely a message substring, so a later refactor can't silently break the throw while keeping a similar log message.

### Dual-path proof (both `runFromReview()` and `runPipeline()`)
**Analog 1 — `runFromReview()` path:** `tests/Feature/Rams/ReviewedHazardTieringTest.php` (full file read, 543 lines). Key structural elements to mirror:
- `use RefreshDatabase;`, `$this->seed(HazardTemplateSeeder::class);` in `setUp()`.
- `Http::fake([...])` for the Claude call (`fakeClaudeResponse()` helper) — never a live AI call.
- A `regenerate(RamsDocument $rams): RamsDocument` helper that calls `(new BuildRamsDocumentJob($rams->id))->handle(app(RamsBuilderService::class));` — the **same job** the real "Generate RAMS"/regenerate button dispatches, not a direct service call, and tracks generated DOCX paths in `$this->generatedFiles` for `tearDown()` cleanup.
- Fixture builder methods (`makeReviewedData()`, `makeRams()`) returning realistic `reviewed_data` shapes.
- Assertions read `$rebuilt->generated_data[...]` after regeneration — never assert on an intermediate return value.

**Analog 2 — `runPipeline()`/`buildFromForm()` path:** `tests/Feature/Rams/ManualRamsCreationTest.php` (partial read, `:1-70` + test name list). Key structural elements:
- POSTs to the manual RAMS-creation route with a `validFormPayload()` fixture (project ref/name/client/site + `works_description` + `hazards`/`ppe`/`persons_at_risk` arrays).
- Asserts `Bus::fake()`-style job dispatch in one test (`test_store_dispatches_generation_job_instead_of_running_builder_inline`), and in others lets the job actually run and asserts on the persisted `RamsDocument` record/status.
- `fakeClaudeResponse(array $phases)` — same `Http::fake()` pattern as the `runFromReview()` analog, confirming this project's one AI-mocking convention is shared across both entry-point test suites.

Recommendation: either extend both existing files with new Phase-27-specific test methods (band assertions, GATE-09 throw assertions) following their established fixture helpers, or create sibling files (`DisplayLiftReviewedPathTest.php` / `DisplayLiftPipelinePathTest.php`) that copy the same `setUp()`/`regenerate()`/`Http::fake()` scaffolding verbatim — either is consistent with the codebase's convention; do not invent a third scaffolding style.

### Structural guard test (band source-of-truth, one shared class)
**Analog:** `tests/Feature/Rams/HazardResolutionPathGuardTest.php` (full file, 137 lines) — an **allow-list file-scan** test: recursively walks `app/`, and asserts that only a named, hand-verified allow-list of files may contain certain marker strings (`HazardTemplate::`, `->resolveFromSeeds(`, `HazardIncludeWhenResolver`). Also includes a self-check that the allow-list itself is the expected length and every entry resolves to a real file (`test_allow_list_has_seven_entries_and_all_resolve`).
**Apply to:** D-03's "every stating point reads it, no divergent copy" invariant. A Phase 27 structural guard would scan `app/` for a marker like a hardcoded team-size number pattern (e.g. `/minimum \d+ persons?/i` or `/\bteam lift\b.*\b(one|two|three|four)\b/i`) outside the allow-listed callers of `DisplayLiftPolicy`, or — simpler and more directly modeled on the existing test — allow-list only files permitted to reference `DisplayLiftPolicy::` directly (mirroring the exact marker-string + allow-list mechanism), so a future edit can't silently introduce a second copy of the bands.

## Metadata

**Analog search scope:** `app/Services/Rams/`, `app/Services/Worksheet/`, `app/Services/` (root), `app/Jobs/`, `app/Exceptions/`, `config/`, `database/seeders/`, `tests/Unit/Services/Rams/`, `tests/Unit/Services/Worksheet/`, `tests/Feature/Rams/`.
**Files scanned (full or targeted read):** `LegacyHazardNameFoldMap.php` (full), `RamsComplianceUpgradeService.php` (`:1-50`, `:1086-1330`), `MethodStatementService.php` (`:430-488`), `SafetyProfileService.php` (full), `HazardTemplateSeeder.php` (`:95-134`), `HazardIncludeWhenResolver.php` (`:1-110`), `EquipmentClassifierService.php` (`:1-60`, `:205-216`), `RamsBuilderService.php` (`:260-333`, `:855-904`), `BuildRamsDocumentJob.php` (`:160-217`), `RamsGenerationException.php` (full), `config/rams_tier1.php` (full), `LegacyHazardNameFoldMapTest.php` (full), `SafetyProfileServiceTest.php` (`:1-60`), `ReviewedHazardTieringTest.php` (full), `HazardResolutionPathGuardTest.php` (full), `RamsBuilderServiceTest.php` (grep of structure), `ManualRamsCreationTest.php` (`:1-70` + grep of structure).
**Pattern extraction date:** 2026-08-26
