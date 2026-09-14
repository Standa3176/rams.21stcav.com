---
phase: 30-structural-validation-gates
reviewed: 2026-09-14T00:00:00Z
depth: deep
files_reviewed: 8
files_reviewed_list:
  - app/Http/Controllers/RamsController.php
  - app/Services/Rams/ControlTextRuleViolations.php
  - app/Services/Rams/HazardIncludeWhenResolver.php
  - app/Services/Rams/RamsComplianceUpgradeService.php
  - app/Services/Rams/StructuralGateVocabulary.php
  - app/Services/RamsBuilderService.php
  - config/rams_tier1.php
  - resources/views/rams/review.blade.php
findings:
  critical: 1
  warning: 2
  info: 0
  total: 3
status: issues
---

# Phase 30: Code Review Report

**Reviewed:** 2026-09-14
**Depth:** deep
**Files Reviewed:** 8
**Status:** issues_found

## Summary

Phase 30 adds five document-consistency gates (GATE-01, GATE-02, GATE-04, GATE-13, GATE-14) to
`RamsComplianceUpgradeService`, a shared signal-matching helper (`StructuralGateVocabulary`), a new
`hot_works_assertion` detector in `ControlTextRuleViolations`, and the `compliance_warnings`
advisory-warning render surface in `resources/views/rams/review.blade.php`. The five kill-switch
flags are correctly independent (each `config('rams_tier1.*_gate_enabled', ...)` check reads its own
key — verified at `RamsComplianceUpgradeService.php:75-167`), GATE-14 has no throw statement anywhere
in its body (verified `enforceMissingRiskRefGate()` at `:2650-2733`), the advisory-warning render
surface at `review.blade.php:400-419` and the per-row `title` attribute at `:1247` use escaped
`{{ }}` output only (no `{!! !!}`), and `compliance_warnings` never reaches
`resources/views/pdf/rams.blade.php`, `pdf/rams-v2.blade.php`, `DocxBuilderService.php`, or
`DocxBuilderServiceV2.php` (grep-confirmed). The `areas_for_gate` mirrors correctly avoid waking the
dormant `ensurePerRoomBullets()` AI path (that method keys on `room_overviews`, never on
`areas_for_gate`).

However, cross-file tracing of `StructuralGateVocabulary::flattenAreas()` against its three live call
sites turned up a genuine fail-open defect (BLOCKER, below): the documented/tested "falls back to
`$data['rooms']`" behaviour is dead code on every document that goes through the live pipeline,
because the Phase 30-02 mirrors always set `areas_for_gate` (even to `[]`) immediately before
`upgrade()` runs. Two further WARNING-level findings concern false-positive/false-negative precision
in the (currently disarmed) GATE-13 permit heuristic and the GATE-01 config vocabulary's signal
mappings.

The two limitations flagged in the review brief as already known/accepted (GATE-13's permit half
being unreachable pre-Phase-31, and GATE-14 having no `manual_handling` signal) were confirmed present
exactly as described and are not repeated here as fresh findings.

## Critical Issues

### CR-01: `flattenAreas()`'s documented `$data['rooms']` fallback is dead code on every live-pipeline document — GATE-02 silently sees nothing whenever `room_overviews` is empty, even when real room data exists

**File:** `app/Services/Rams/StructuralGateVocabulary.php:288`

**Issue:**

```php
public static function flattenAreas(array $data): array
{
    $source = $data['areas_for_gate'] ?? $data['rooms'] ?? null;
    ...
```

`??` only falls through when the left operand is `null`/unset — not when it is a *present but empty*
array. The class's own docblock (`:275-284`) and its unit test
(`StructuralGateVocabularyTest.php:165-176`, `test_flatten_areas_falls_back_to_rooms_when_no_gate_mirror`)
both describe/exercise the fallback only for the case where the `areas_for_gate` *key is entirely
absent* from `$data`.

That is not what happens on a live document. All three Phase 30-02 mirror sites —
`RamsController.php:609`, `RamsBuilderService.php:307` (`runFromReview()`), and
`RamsBuilderService.php:972` (`runPipeline()`) — **unconditionally** set
`$data['areas_for_gate']` immediately before calling `RamsComplianceUpgradeService::upgrade()`,
even when the source (`reviewed_data['room_overviews']`) is empty:

```php
$generatedData['areas_for_gate'] = array_values(array_filter(array_map(
    static fn ($r) => is_array($r) ? trim((string) ($r['room'] ?? '')) : '',
    (array) ($reviewedData['room_overviews'] ?? []),
), static fn (string $s): bool => $s !== ''));
```

When `room_overviews` is empty, this evaluates to `[]` — present, not absent. Once `areas_for_gate`
is `[]`, `flattenAreas()`'s `??` never reaches `$data['rooms']`, **regardless of whether `$data['rooms']`
is itself populated** (e.g. from a site-survey-driven `ProjectContext` — see
`RamsDataBuilderService.php:65-88`, where `$data['rooms'] = $projectContext['rooms']` is set
independently of anything on the review screen).

`room_overviews` (an optional, AI-generated per-room narrative filled in on the review screen) and
`rooms` (the project's structural room list, sourced from the quote/survey) are two independent data
sources. A document can easily have a fully populated `rooms` list and an empty/never-visited
`room_overviews` field. For every such document, on every one of the three real generation/save entry
points, GATE-02 (`enforceAreaCoverageGate()`) will vacuously pass — not because the document has no
areas (the documented, deliberately-accepted vacuous-pass case at `:2170-2174`), but because the gate
was fed `[]` in place of the real room list. This is exactly the "gate reports clean when it cannot
see its data" failure mode the review brief calls out, and it is *wider* than what
`StructuralGatesDualPathTest.php`'s own docblock (`:58-76`) documents: that file only discusses the
`[]` gap for *legacy* documents where the mirror key is entirely absent (sites 4-6,
inherit-by-persistence); it does not identify that the same blind spot also applies to *freshly built
or freshly saved* documents (sites 1-3) whenever `room_overviews` happens to be empty while `rooms` is
not.

**Fix:** Distinguish "mirror absent" from "mirror present but empty," and only skip the `rooms`
fallback when the mirror key is genuinely populated:

```php
public static function flattenAreas(array $data): array
{
    $mirror = $data['areas_for_gate'] ?? null;
    $source = (is_array($mirror) && ! empty($mirror)) ? $mirror : ($data['rooms'] ?? null);

    if (! is_array($source)) {
        return [];
    }
    ...
```

(Or, if an intentionally-empty `room_overviews` really should short-circuit the check, that needs to
be a documented, deliberate design decision — not an accidental `??` precedence artefact — and the
existing docblock/tests need to be corrected to describe the *actual* behaviour rather than the
intended one.)

## Warnings

### WR-01: GATE-13's conditional-marker check uses bare short-word substring matching, which can silently disarm a genuinely unconditional permit requirement

**File:** `app/Services/Rams/RamsComplianceUpgradeService.php:2574` (`permitRuleIsUnconditionalHotWorksRequirement()`)

**Issue:**

```php
foreach (['if ', 'where ', 'should ', 'when ', 'may be required'] as $conditionalMarker) {
    if (str_contains($lower, $conditionalMarker)) {
        return false;
    }
}
```

This is a bare `str_contains` over the *entire* rule line, not scoped to the hot-works clause. A
permit rule that is genuinely unconditional about hot works but happens to contain an unrelated
clause using one of these very common words (e.g. "...a hot-works permit is required. If in doubt,
contact the PM before starting.", or "...required when work begins each day.") will be misclassified
as conditional and GATE-13 will silently pass a document that does, in fact, contradict itself. Given
this gate exists specifically to catch a contradiction that would otherwise ship on a live,
litigation-adjacent safety document, and the marker list is this permissive, the false-negative surface
is broader than the docblock's discussion of the single addressed case (`addPermitAndIsolation()`'s own
"if soldering..." line, `:2555-2559`). Low current severity because `RAMS_HOT_WORKS_GATE` ships
disarmed, but this should be tightened (e.g. require the conditional marker to appear within the same
clause/sentence as the hot-works/permit mention, not merely anywhere in the line) before Phase 31 arms
it.

**Fix:** Split the rule text on sentence boundaries (or a fixed window around the hot-works/permit
match) before checking for a conditional marker, so an unrelated "if"/"when" elsewhere in the line
cannot mask a real unconditional requirement.

### WR-02: GATE-01's `structural_gate_triggers` map three permit/isolation phrases onto signals with no real relationship to the trigger phrase's subject matter

**File:** `config/rams_tier1.php:271-281`

**Issue:**

```php
// Permit-to-work is issued in this business's own wording for
// ceiling void / riser / restricted-area access
// (addPermitAndIsolation() rule text) — ceiling_void_access is the
// closest existing signal, not a purpose-built "permit" signal.
['phrase' => 'permit to work', 'signal' => 'ceiling_void_access', 'label' => 'permit to work'],
// Isolation certificate / hot-works permit both concern electrical
// isolation controls in addPermitAndIsolation()'s own rule text —
// mains_connection is the closest existing signal.
['phrase' => 'isolation certificate', 'signal' => 'mains_connection', 'label' => 'isolation certificate'],
['phrase' => 'hot-works permit', 'signal' => 'mains_connection', 'label' => 'hot-works permit'],
```

The config's own comments acknowledge these are approximations ("closest existing signal, not a
purpose-built... signal"). In practice this means: a document that mentions "permit to work" for a
reason unrelated to ceiling/void access (confined space, hot works, asbestos — any permit-controlled
activity this business issues a generic "permit to work" for) will be checked against the
`ceiling_void_access` hazard/client-responsibility vocabulary. If that document has no ceiling-related
hazard row and no ceiling-related client-responsibility entry — entirely plausible for, say, a
confined-space-only job — GATE-01 throws an "Orphan control" error that asks the engineer to add a
ceiling-void-access hazard or client responsibility, which is the wrong remedy for the actual gap (if
any) in the document. Same shape of mismatch for "isolation certificate"/"hot-works permit" mapped to
`mains_connection`. This is a false-positive risk (the gate demands unrelated content be added, or
blocks issuance, for a document that may already be internally consistent about the permit it
mentions) rather than a fail-open risk, but it directly works against GATE-01's own stated invariant of
never producing "a false positive from a matching failure" (`RamsComplianceUpgradeService.php:2041-2045`).
Because `RAMS_STRUCTURAL_GATES` ships disarmed, this is not yet live, but it should be corrected — or
the vocabulary gap it's compensating for should be closed with a purpose-built signal — before arming.

**Fix:** Either add dedicated `permit_to_work` / `hot_works_or_isolation` signals to
`StructuralGateVocabulary::SUPPORTED_SIGNALS` (and to `HazardIncludeWhenResolver`'s const maps, per
D-07's "one shared vocabulary" rule) with phrase sets that actually describe permit/isolation
documentation, or narrow these three trigger phrases' scope so they only fire when co-located with
genuinely ceiling/mains-related context, rather than reusing an unrelated existing signal purely
because one already exists.

---

_Reviewed: 2026-09-14_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: deep_
