# Phase 27: Manual-Handling & Display-Lift House Rules - Research

**Researched:** 2026-08-25
**Domain:** Laravel service-layer refactor — centralising a safety-critical numeric policy (display-lift team size) across five statement points and one new blocking validation gate, in a codebase with two live document renderers and two generation entry points.
**Confidence:** HIGH — every claim below is grounded in direct code reads of this repository (file:line cited throughout) plus the phase's own CONTEXT.md/REQUIREMENTS.md/ROADMAP.md, which are locked decisions, not hypotheses. No external library research was needed — this phase touches zero new dependencies.

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**Display-lift team size — RULE-02 amended by 21CAV house decision.**

> WARNING: This phase's governing requirement was changed during discussion. RULE-02 as
> written in REQUIREMENTS.md:74 says "All displays are two-operative team lifts
> regardless of panel size. Never four-operative; never conditional on screen size."
> It is sourced from house-rules.md:8-11, which this milestone declares the winner in
> any app-vs-skill disagreement (ROADMAP.md:13). The user was shown that conflict
> explicitly, including that a size ladder is the exact defect the 21CQ30960
> professional review raised, and settled it deliberately. The amended position below
> is what Phase 27 builds.

- **D-01 (BLOCKING pre-planning edit — CONFIRMED ALREADY DONE, see note below):** The
  settled 21CAV position is a **two-operative floor with a ladder above it**:
  - **2 operatives minimum** for every display up to and including 90 inches
  - **3 operatives minimum** above 90 inches
  - **Never 1 operative** for a display, at any size. **Never 4 operatives**, at any size.

  RULE-02's four-operative ban and its "mechanical aids are additional, never a
  substitute" clause both survive intact. Only its "regardless of panel size" clause is
  amended. **Verified during this research session: REQUIREMENTS.md:74 and ROADMAP.md
  Phase 27 success criteria 1 and 3 already carry the amended text as of 2026-08-25 —
  the required pre-planning edit has been completed.** house-rules.md is confirmed
  unedited (the skill stays a clean upstream reference).

- **D-02:** Above 90 inches the third operative is **required, not an allowance**.
  Generated text reads as a minimum team size. A panel-lift trolley or other mechanical
  aid does **not** discharge the third operative — the user was offered an aid-satisfiable
  variant and rejected it. This preserves RULE-02's "additional, not instead of" principle
  at the new band boundary.

  Consequence: the existing ">=65in -> Two persons may lift if using a panel-lift
  trolley" wording (RamsComplianceUpgradeService.php:1261-1262) is an aid-as-substitute
  construction. At 65-90 inches the floor is 2 anyway so it is harmless, but the same
  construction must not be carried above 90.

- **D-03:** The bands and the sentences they produce live in **one PHP class** (working
  name DisplayLiftPolicy). Every stating point reads it — suggestHandlingMethod(),
  the MethodStatementService:461 string, the hazard-library seeder's Manual handling
  control text, SafetyProfileService, **and GATE-09 itself**. The gate and the
  generator must resolve the team size through the same call, so a future edit cannot
  make them disagree.

  Rejected: bands in config/rams_tier1.php — Phase 26 deliberately moved safety content
  out of that file (D-01/26), and putting it back partially reverses that. Rejected:
  bands in the DB hazard control text alone — Section 6.7 needs per-item output, not one
  paragraph.

  Precedent: app/Services/Rams/LegacyHazardNameFoldMap.php (Phase 26, Plan 26-08) is
  the established shape — a version-controlled PHP class holding a settled 21CAV
  position, consumed at a single choke point by every path that needs it.

- **D-04:** **Worksheets are in scope.** SafetyProfileService::LARGE_DISPLAY_INCHES = 55
  (app/Services/Worksheet/SafetyProfileService.php:23) is removed and the room warning
  reads from the same class. Effects: every display produces a lift warning (today a
  43-inch produces none), and the >90 band is stated on worksheets too. Rationale: two
  engineers reading two 21CAV documents about the same job must not be told different
  team sizes.

  Note the asymmetry — GATE-09's scope was not extended by this decision. See
  Claude's Discretion.

- **D-05:** When the display's size **cannot be resolved**, take the 2-operative floor
  **silently** — no confirmation flag, no gate error. The inch-parsing regex at
  RamsComplianceUpgradeService.php:1237-1240 returns null for descriptions like
  "Samsung QM98 commercial display" or a bare part number.

  The user was shown, and declined, three alternatives: flagging it needs_confirmation
  via Phase 26's D-06 mechanism, erroring in GATE-09, and falling back to weight_kg
  metadata. **Accepted residual risk, recorded deliberately:** a >90-inch panel whose
  description carries no parseable inch number ships a 2-operative instruction, and
  nothing surfaces it. Do not re-engineer this away without asking.

### Claude's Discretion

The user chose not to discuss these. The planner decides, within the stated constraints.

- **RULE-12 — mount/bracket rows inheriting display text.** Root cause is located and
  precise: in suggestHandlingMethod() the display branch (:1254) is evaluated
  **before** the mount/bracket branch (:1270), so a line reading "double-arm wall
  mount for 65 inch display" matches on the word "display" and returns display handling
  text — exactly the 21CQ30960 Section 6.7 defect. Reordering the branches is the
  minimum fix.

  **Constraint:** RULE-12's actual wording is stronger than reordering — "derive from
  actual equipment weight, manufacturer handling requirements and route assessment —
  never from screen diagonal alone." The RAMS path today has no weight input at all;
  SafetyProfileService reads a weight_kg tag that the RAMS path ignores, and its
  coverage on real quote lines is **unverified**. The planner must either (a) verify
  weight_kg coverage and use it, or (b) fix the ordering, satisfy RULE-12's "mount rows
  must not inherit display text" clause, and record the derive-from-weight clause as
  explicitly deferred with a reason. Do not silently narrow RULE-12 to the ordering fix
  without saying so.

  **This research's finding (see Summary and Research Priority 1 in the body of this
  document): weight_kg coverage on real quote-line data reaching the RAMS path is
  effectively zero — verified, not assumed.** The planner should take option (b): fix
  the ordering and defer the weight-derivation clause explicitly, per the Deferred
  Ideas item below.

  **Constraint:** house-rules.md:18-19 gives the settled non-display position — "wall
  mounts and rack rails are usually two-person, small brackets and video bar mounts
  single-person." Non-display items are NOT covered by D-01's two-operative floor;
  single-person is correct for a small bracket. D-01 governs displays only.

- **RULE-03 — wall-mount removal statement.** ROADMAP success criterion 2 allows either
  the method statement or a hazard control. Needs a deterministic strip-out/decommission
  signal; EquipmentClassifierService::textIndicatesDrilling() is the established
  precedent for deriving a real signal rather than hardcoding false (Plan 26-07).

  **This research's finding: a ready-made deterministic signal already exists** —
  HazardIncludeWhenResolver's tier-2 keyword signal strip_out_or_decommission
  (app/Services/Rams/HazardIncludeWhenResolver.php:83-88), already wired to the
  "Decommissioning and WEEE" hazard. Combined with the existing scope_items.decommission
  bucket, this is reusable without inventing a new signal.

  **Constraint:** the required sequence is fixed by house-rules.md:13-16 — controlled
  to the lowest practicable height, **one operative each side**, before release from the
  mount, and stated explicitly as the highest-risk lift on a strip-out. Note "one
  operative each side" is a two-operative construction and is consistent with D-01's
  floor. **No AI** decides whether this statement applies (CLAUDE.md:12 — AI is never
  allowed to invent scope; carried forward from Phase 26's D-05 correction).

- **GATE-09 — enforcement mechanism.** **There is no gate framework in this codebase.**
  GATE-03 and GATE-08 are marked shipped (REQUIREMENTS.md:52-53, quick task 260817-r5e)
  but were delivered as generator fixes that make references resolve — not as
  error-raising checks. A grep for gate/validator classes across app/ returns only
  LegacyHazardNameFoldMap.php and a test, both incidental. GATE-09 therefore
  **establishes the mechanism GATE-06/07/11/12/13/14 will reuse** — that is a bigger
  decision than one gate, and the planner should treat it as such.

  **This research's finding: the codebase already has a working, wired,
  proven-in-production blocking-error mechanism** — BuildRamsDocumentJob::handle()'s
  catch (\Throwable $e) sets status=FAILED + error_message, rendered on
  rams/index.blade.php:448-450. GATE-09 should throw a RuntimeException (or a
  dedicated subclass) as the final step of RamsComplianceUpgradeService::upgrade(),
  reusing this existing surface rather than building a new one. See Architecture
  Patterns section, Pattern 2, in the body of this document.

  Open sub-decisions left to planning: whether "errors" blocks generation, raises a
  blocking banner on the RAMS review screen, or both; whether it runs at generation
  time, review time, or both; and whether GATE-09 policing extends to worksheet output
  (D-04 wired the numbers to worksheets but did not extend the gate).

  **Constraint:** GATE-09 must resolve team size via D-03's shared class, never its own
  copy of the bands. **Constraint:** per D-05, an unresolvable display size is not a
  gate error.

- **Reversibility.** Not raised in discussion, but Phase 26's specifics establish it as
  a standing project condition: this milestone is validated **on live against
  production data**, so a bad safety position must be one .env edit from off, not a git
  revert plus redeploy. RAMS_HAZARD_LIBRARY_TIERING (Phase 26) and RAMS_UNIFIED_COMPOSER
  are the precedents. **Strong steer:** gate GATE-09's erroring behaviour behind its own
  flag. A gate that blocks generation is exactly the kind of change that needs an
  instant off switch on live.

### Deferred Ideas (OUT OF SCOPE)

- **Weight-driven manual handling (RULE-12's full clause)** — deriving handling text
  from actual equipment weight, manufacturer handling requirements and route assessment
  rather than screen diagonal. Blocked on unverified weight_kg coverage across real
  quote lines. **This research confirms coverage is effectively zero on the RAMS
  path** — this becomes its own future phase; RULE-12 ships partially, which must be
  stated explicitly in the plan, not glossed.
- **Flagging unresolvable display sizes for confirmation** — D-05 accepted silent
  2-operative fallback. Revisit only if a live pack is found stating 2 operatives for a
  panel that is actually above 90 inches.
- **Extending GATE-09 to worksheet output** — D-04 wired the worksheet to the shared
  band numbers but left the gate RAMS-scoped.
- **A general gate framework** — GATE-09 establishes the mechanism, but formalising it
  for the remaining six gates (06, 07, 11, 12, 13, 14) is milestone-level work, not
  Phase 27's.
- **Structured job-scope capture** (strip-out flag, building age, operative count) —
  carried forward unresolved from Phase 26. Would make RULE-03's trigger deterministic
  by data rather than by text inference.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| RULE-02 | Display lifts follow a two-operative floor with one band above it (min 2 to 90 inches, min 3 above 90, never 1, never 4); mechanical aids additional never substitute | Confirmed both generation paths converge on RamsComplianceUpgradeService::upgrade() — one edit to suggestHandlingMethod() (via new DisplayLiftPolicy) reaches both. Confirmed exact ladder to remove (:1254-1266) and the aid-as-substitute wording to remove (:1261-1262). Confirmed REQUIREMENTS.md/ROADMAP.md already carry the amended text. |
| RULE-03 | Wall-mount removal stated explicitly as highest-risk strip-out lift | Confirmed a ready-made deterministic signal exists (HazardIncludeWhenResolver's strip_out_or_decommission tier-2 keyword signal plus the scope_items.decommission bucket), reusable without new capture fields or AI. |
| RULE-12 | Manual-handling controls derive from actual weight/manufacturer guidance/route assessment, never screen diagonal alone; mount/bracket rows must not inherit display text | Confirmed root cause (branch order in suggestHandlingMethod(), :1254 before :1270) and confirmed, via direct trace of the equipment/survey data pipeline, that weight_kg/display_size_in coverage on real RAMS-path quote-line data is effectively zero — the weight-derivation clause must be explicitly deferred, per CONTEXT.md's own instruction not to silently narrow the requirement. |
| GATE-09 | Errors on any display lift not conforming to RULE-02's bands; unresolvable size is not an error | Confirmed no gate framework exists, but confirmed a working blocking-error mechanism already does (BuildRamsDocumentJob::handle() catch leads to STATUS_FAILED/error_message leads to rams/index.blade.php). Recommended mechanism: a DisplayLiftPolicy::violatesPolicy() re-check as the final step of RamsComplianceUpgradeService::upgrade(), throwing a RuntimeException, gated behind a new RAMS_DISPLAY_LIFT_GATE env flag mirroring RAMS_HAZARD_LIBRARY_TIERING. |
</phase_requirements>


## Summary

This phase centralises a single numeric policy (D-01: 2-operative floor to 90″ inclusive, 3-operative minimum above 90″, never 1, never 4) into one PHP class and makes every place that currently states a display-lift team size read it. The good news, confirmed by tracing the call graph: **both real generation entry points already converge on a single shared method**, `RamsComplianceUpgradeService::upgrade()`, called identically from `RamsBuilderService::runFromReview():284` and `RamsBuilderService::runPipeline():881` (and transitively from `buildFromForm()`, which calls `pipeline()` → `runPipeline()`). Unlike Phase 26's hazard-resolver problem — which had a third, silently-unwired path — there is no dual-path risk here for the primary target (`suggestHandlingMethod()` lives inside `deriveMaterialHandling()`, itself one of `upgrade()`'s ordered steps). Fixing `RamsComplianceUpgradeService` fixes both entry points in one edit.

The second major finding answers CONTEXT.md's biggest open question directly: **`weight_kg` / `display_size_in` coverage on real RAMS-path quote-line data is effectively zero.** `SafetyProfileService`'s "metadata-first" tags (`weight_kg`, `display_size_in`, `mounting_position`, `is_rack_chassis`) are never populated on the quote `equipment_list` items that flow into it — those items are raw quote line-item dicts (`description`, `qty`, `category`) with no such keys anywhere in the ingestion pipeline (`QuoteWerksImportService`, `ExtractQuoteJob`). The one place `display_size_in` genuinely exists in the app is `SiteSurveyRoom` (a per-room survey field, one display size per room, populated only when a site survey was completed) — and it is never merged back onto individual equipment items before reaching `SafetyProfileService::profileRoom()`. The RAMS render path (`RamsComplianceUpgradeService::suggestHandlingMethod()`) has zero weight input of any kind — it parses inches from free-text descriptions only. RULE-12's "derive from actual equipment weight" clause is therefore **not implementable from real data today** and must be explicitly deferred (CONTEXT.md's own suggested deferral path), with the reordering fix (RULE-12's "mount rows must not inherit display text" clause) implemented in full.

Third: **there is no gate-error mechanism precedent anywhere in this codebase, and none is needed** — the app already has one. `BuildRamsDocumentJob::handle()` wraps every generation call (`buildFromForm()`, `buildFromReview()`) in `catch (\Throwable $e)`, sets `status = STATUS_FAILED`, `error_message = $e->getMessage()`, and that message is rendered on `resources/views/rams/index.blade.php:448-450`. A `\RuntimeException` thrown at the end of `RamsComplianceUpgradeService::upgrade()` (a genuine "pre-render guard," a pattern that already exists once in this file at `RamsBuilderService.php:~871`) is caught, surfaced, and blocks persistence/render — for free, on both paths, with an existing UI surface. This is a materially lower-risk design than inventing a new validation framework.

Fourth: the deterministic strip-out signal RULE-03 needs **already exists** — `HazardIncludeWhenResolver`'s tier-2 keyword signal `strip_out_or_decommission` (matching `strip-out`, `strip out`, `decommission`, `removal of existing`, `de-install` against the scope narrative) is already wired to the "Decommissioning and WEEE" hazard. Combined with the existing `$data['scope_items']['decommission']` bucket (populated before `upgrade()` runs on both paths) and display/mount keyword matching already used elsewhere in this file, a fully deterministic "this job strips out a wall-mounted display" signal is buildable with zero new capture fields and zero AI.

**Primary recommendation:** Build `DisplayLiftPolicy` as a stateless PHP class (bands + sentence templates), consume it from `suggestHandlingMethod()`, `MethodStatementService:461`, `HazardTemplateSeeder`'s Manual handling row, and `SafetyProfileService`; add GATE-09 as a final step inside `RamsComplianceUpgradeService::upgrade()` that independently re-validates the resolved team sizes against the same policy and throws on violation, gated behind a new `RAMS_DISPLAY_LIFT_GATE` env flag following the `RAMS_HAZARD_LIBRARY_TIERING` precedent exactly.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Display-lift team-size policy (bands + sentences) | API / Backend (new `DisplayLiftPolicy` class in `app/Services/Rams/`) | — | Pure deterministic business logic; no UI, no persistence. Single source of truth per D-03. |
| §6.7 Material Handling table content | API / Backend (`RamsComplianceUpgradeService::deriveMaterialHandling()`) | Database/Storage (`generated_data` JSON, denormalised) | Derivation is compute-time; storage is the frozen snapshot Phase 26 established (no retro-fix of issued RAMS). |
| Manual handling hazard control text | Database/Storage (`hazard_templates` seeded row, DB is runtime store per Phase 26 D-01) | API / Backend (seeder is version-controlled source) | Matches Phase 26's established split: git seeder = source of truth, DB = what the resolver reads at generation time. |
| Method-statement fallback string | API / Backend (`MethodStatementService::fallbackPhases()`) | — | Static fallback, only reached when the AI call fails validation twice; still a real generation path. |
| Worksheet safety-profile warning | API / Backend (`SafetyProfileService`) | — | Same tier as the RAMS side; both are Laravel service classes, no separate rendering tier. |
| GATE-09 enforcement | API / Backend (inside `RamsComplianceUpgradeService::upgrade()`, exception-based) | Frontend Server / Blade (surfaced via `error_message` on `rams/index.blade.php`) | The check must run where the data is assembled (backend) but its failure mode is a status flag rendered server-side in an existing Blade view — no new tier needed. |
| RULE-03 strip-out signal | API / Backend (reuse of `HazardIncludeWhenResolver` signal vocabulary + `scope_items['decommission']`) | — | Deterministic text/data matching, same tier as the existing tier-2 hazard resolution it reuses. |

## Standard Stack

Not applicable in the conventional sense — this phase adds **zero new external packages, zero new npm/composer dependencies**. It is a pure internal refactor of existing PHP service classes plus one new PHP class (`DisplayLiftPolicy`). Skip the Package Legitimacy Audit section for this reason (see below).

### Alternatives Considered (for the "one shared source" mechanism, D-03)

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| A new standalone `DisplayLiftPolicy` class | Bands as `config/rams_tier1.php` entries | Rejected explicitly in CONTEXT.md D-03 — Phase 26 deliberately moved safety content *out* of that config file; putting bands back partially reverses the Phase 26 inversion. |
| A new standalone `DisplayLiftPolicy` class | Bands embedded only in the DB hazard control text | Rejected in CONTEXT.md D-03 — §6.7 needs per-item structured output (qty, item, sentence), not one paragraph; a DB string alone can't drive GATE-09's independent re-check either. |
| GATE-09 as new validation framework | Laravel `FormRequest` validation rule | Doesn't fit — this isn't user input validation, it's a post-derivation consistency check on internally-computed data, running deep in a service pipeline, not at a controller boundary. |

## Package Legitimacy Audit

Not applicable. This phase installs no new packages (PHP or otherwise) — it is an internal service-class refactor using only classes already present in `composer.json`. No `slopcheck`/registry verification is required.

## Architecture Patterns

### System Architecture Diagram

```
                         ┌─────────────────────────────────────────┐
                         │   Two generation entry points            │
                         │                                           │
  Quote review approved  │  RamsBuilderService::runFromReview()     │
  ──────────────────────▶│    :131  reviewedToRisk() (hazards)      │
                         │    :284  RamsComplianceUpgradeService::  │
                         │           upgrade($data)  ◀───────────┐  │
                         └───────────────────────────────────────┼──┘
                                                                  │
  Manual form / regenerate /                                     │
  buildFromQuote() (fresh parse)  ┌───────────────────────────┐  │
  ──────────────────────────────▶ │ RamsBuilderService::       │  │
                                  │  runPipeline() :809         │  │
                                  │   :881  RamsComplianceUp-   │  │
                                  │          gradeService::     │  │
                                  │          upgrade($data) ────┼──┘
                                  └───────────────────────────┘
                                                │
                                                ▼
                         ┌──────────────────────────────────────────┐
                         │  RamsComplianceUpgradeService::upgrade()   │
                         │  (ordered pipeline of static methods)      │
                         │                                            │
                         │  ... fillMissingHazardControls()           │
                         │  ... addProjectSpecificRisks() (gated OFF  │
                         │        by default — RAMS_HAZARD_LIBRARY_   │
                         │        TIERING=true)                       │
                         │  ... deriveMaterialHandling()               │
                         │        └─▶ suggestHandlingMethod()          │
                         │              └─▶ DisplayLiftPolicy (NEW)    │
                         │  ... crossReferenceMethodStatementRisks()   │
                         │  [NEW] enforceDisplayLiftGate($data) ───────┼──▶ throws \RuntimeException
                         │        └─▶ DisplayLiftPolicy::validate()    │      on band violation
                         └──────────────────────────────────────────┘        (GATE-09)
                                                │  (no exception)
                                                ▼
                         $record->update(['generated_data' => $data])
                                                │
                                                ▼
                         $this->renderer->render($data, $record)
                              └─▶ DocxBuilderService::buildRiskAssessment()
                                    (LIVE primary renderer)
                                    ├─ buildMaterialHandling()  → §6.7 table
                                    └─ hazard section           → Manual handling row

                         (parallel LIVE PDF: resources/views/pdf/rams.blade.php
                          — RAMS_UNIFIED_COMPOSER defaults false, rams-v2.blade.php
                          and RamsDocumentComposer/MethodStatementComposer are NOT live)

  On \RuntimeException anywhere in the above:
    BuildRamsDocumentJob::handle() catch(\Throwable $e)
      → RamsDocument.status = FAILED, error_message = $e->getMessage()
      → rendered on resources/views/rams/index.blade.php:448-450 (existing UI)
```

### Recommended Project Structure

No new directories. One new file:

```
app/Services/Rams/
├── DisplayLiftPolicy.php          # NEW — D-03's single shared source
├── LegacyHazardNameFoldMap.php    # existing — the shape to follow (Phase 26)
├── RamsComplianceUpgradeService.php  # modified — suggestHandlingMethod(),
│                                       deriveMaterialHandling(), + new
│                                       enforceDisplayLiftGate() gate step
├── HazardIncludeWhenResolver.php  # unmodified — reused read-only for the
│                                       strip_out_or_decommission signal shape
```

### Pattern 1: Single static policy class, consumed at every choke point

**What:** A stateless class with named static methods returning a small value object (min/max persons, sentence, worksheet warning text) for a given resolved inch size or `null` (unresolved).
**When to use:** Exactly D-03's shape — this is what `LegacyHazardNameFoldMap` already does for hazard-name folding, and this phase should mirror it structurally.
**Example (recommended shape, not yet in the codebase):**
```php
// Source: pattern modelled on app/Services/Rams/LegacyHazardNameFoldMap.php
namespace App\Services\Rams;

final class DisplayLiftPolicy
{
    public const MIN_OPERATIVES_FLOOR = 2;
    public const MIN_OPERATIVES_ABOVE_90 = 3;
    public const BAND_THRESHOLD_INCHES = 90;

    /** @return array{min_persons:int, sentence:string} */
    public static function forSize(?float $inches): array
    {
        if ($inches !== null && $inches > self::BAND_THRESHOLD_INCHES) {
            return [
                'min_persons' => self::MIN_OPERATIVES_ABOVE_90,
                'sentence' => 'Team lift — minimum 3 persons required for displays above 90″. '
                    . 'Mechanical aids are used in addition, never as a substitute for the third operative.',
            ];
        }

        // D-05: unresolvable size ($inches === null) takes the floor silently.
        return [
            'min_persons' => self::MIN_OPERATIVES_FLOOR,
            'sentence' => 'Team lift (2 persons minimum). Mechanical aids used in addition where available, not instead of the second person.',
        ];
    }

    /** GATE-09: independent re-validation, never trusts the same call path that produced the text. */
    public static function violatesPolicy(int $statedPersons, ?float $inches): bool
    {
        if ($statedPersons < 2 || $statedPersons > 3) {
            return true; // never 1, never 4+
        }
        if ($inches !== null && $inches > self::BAND_THRESHOLD_INCHES && $statedPersons < self::MIN_OPERATIVES_ABOVE_90) {
            return true; // 2 persons stated above 90" is a violation
        }
        return false;
    }
}
```

### Pattern 2: Post-derivation gate as the final step of an existing pipeline (GATE-09's mechanism)

**What:** Rather than inventing a validation framework, add one more ordered step to `RamsComplianceUpgradeService::upgrade()`'s existing method chain (`app/Services/Rams/RamsComplianceUpgradeService.php:24-45`) that scans the already-derived `$data['material_handling_derived']['items']` (and, per D-04, the worksheet-analogous data if reachable at this point) for any stated team-size number, re-resolves the same item's inch size independently, and throws when `DisplayLiftPolicy::violatesPolicy()` is true.
**When to use:** This is the correct mechanism for GATE-09 in this codebase, established by tracing the actual exception-propagation path (see `<code_context>` below), not invented from a generic pattern.
**Example:**
```php
// Source: pattern to add inside app/Services/Rams/RamsComplianceUpgradeService.php::upgrade()
public static function upgrade(array $ramsData): array
{
    // ...existing ordered steps...
    $ramsData = self::deriveMaterialHandling($ramsData);
    // ...
    if (config('rams_tier1.display_lift_gate_enabled', true)) {
        self::enforceDisplayLiftGate($ramsData); // throws \RuntimeException on violation
    }
    return $ramsData;
}
```
This is caught by the existing `catch (\Throwable $e)` in `BuildRamsDocumentJob::handle()` (`app/Jobs/BuildRamsDocumentJob.php:~176-190`), which already sets `status = STATUS_FAILED` and `error_message = $e->getMessage()`, rendered at `resources/views/rams/index.blade.php:448-450`. **No new UI, no new exception class hierarchy beyond one `\RuntimeException` subclass, is strictly required** — though a dedicated `DisplayLiftGateException extends \RuntimeException` is recommended for testability (assert the exception type, not just message substring) and gives the long-dead `App\Exceptions\RamsGenerationException` (0 callers anywhere in the codebase today) a legitimate first use if the planner prefers reusing it instead of a new class.

### Anti-Patterns to Avoid

- **Regex-scanning rendered prose for team-size violations.** GATE-09 should validate the *structured* intermediate data (`material_handling_derived.items[].handling_method` alongside the resolved inch size that produced it) at the point of derivation, not parse the final DOCX/PDF text after rendering. Rendering happens after `generated_data` is already persisted; a post-render check can't stop persistence and is harder to unit test.
- **Trusting "it's the same function so it can't disagree."** If GATE-09 is implemented as literally the same code path that produces the sentence, reverting the fix on a fixture (ROADMAP's required test methodology) would not surface a violation, because both the generator and the "check" would move together. GATE-09 must independently re-derive/re-validate against `DisplayLiftPolicy::violatesPolicy()`, not merely assume `DisplayLiftPolicy::forSize()` can't misbehave.
- **Fixing only `RamsComplianceUpgradeService` and assuming coverage.** CONTEXT.md's canonical_refs list four locations; this research found the seeder text is one of them (confirmed still unamended — see Common Pitfalls) and surfaced two additional **dead** locations (`RiskMatrixService`, `fillMissingHazardControls`'s generic fallback) that must NOT be edited (wasted effort) and one **gated-off** location (`addProjectSpecificRisks`) that needs no numeric-band edit because its wording is already generic ("team lift for items over 20 kg", no specific person count).

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Deterministic "job includes strip-out of a wall-mounted display" detection | A new capture field, a new AI classifier, or a new keyword list from scratch | `HazardIncludeWhenResolver::TIER2_KEYWORD_SIGNALS['strip_out_or_decommission']` (`app/Services/Rams/HazardIncludeWhenResolver.php:83-88`) intersected with `$data['scope_items']['decommission']` item descriptions matching display/mount keywords (reuse `EquipmentClassifierService::ACTIVITY_MAP['display_installation']['keywords']`, `app/Services/EquipmentClassifierService.php:26-33`) | The signal already exists, is already wired to a hazard (Decommissioning and WEEE), and is proven live (Phase 26 round-3 verification confirmed it fires correctly on 21CQ30960, a real strip-out job). Reinventing it risks a second, subtly different vocabulary — exactly the class of bug Phase 26 spent three rounds fixing for hazard names. |
| A generation-time blocking error | A new validator framework, a new middleware, a new `FormRequest` | A `\RuntimeException` thrown inside `RamsComplianceUpgradeService::upgrade()`, caught by the existing `BuildRamsDocumentJob::handle()` `catch (\Throwable $e)` (`app/Jobs/BuildRamsDocumentJob.php`) | This mechanism already exists and is already wired to a UI surface (`error_message` on `rams/index.blade.php:448-450`). Building a parallel mechanism duplicates effort and risks the two disagreeing about what "blocked" means. |
| A live/rollback safety switch | A brand-new env-var naming scheme | `env('RAMS_DISPLAY_LIFT_GATE', true)` read via `config('rams_tier1.display_lift_gate_enabled')`, following `RAMS_HAZARD_LIBRARY_TIERING`'s exact shape (`config/rams_tier1.php:56`) | Established, proven-on-live precedent (Phase 26's rollback proof, `26-VERIFICATION.md` "Rollback proof" section, executed successfully 2026-08-25). A different shape adds cognitive load for zero benefit. |

**Key insight:** Every mechanism this phase needs — the deterministic signal, the blocking-error path, the rollback flag — already exists in this codebase in a proven, live-verified form for an adjacent problem (Phase 26's hazard inversion). The work here is almost entirely "read the existing pattern, apply it to display-lift team size," not "design something new."

## Common Pitfalls

### Pitfall 1: Deleting `SafetyProfileService::LARGE_DISPLAY_INCHES` alone does not satisfy D-04

**What goes wrong:** D-04 requires "every display produces a lift warning (today a 43-inch produces none)." Simply removing the `>= 55` threshold check does not fix this, because `roomContainsLargeDisplay()`'s keyword fallback (`app/Services/Worksheet/SafetyProfileService.php:66-72`) only matches a **hardcoded, fixed list** of inch sizes in the item name: `55|65|70|75|85|86|98|100`. A 43″ or 32″ display is invisible to this regex regardless of what the threshold constant is set to.
**Why it happens:** The regex was written to catch known common panel sizes appearing in real quote descriptions, not to parse an arbitrary inch number the way `RamsComplianceUpgradeService::suggestHandlingMethod()`'s regex does (`app/Services/Rams/RamsComplianceUpgradeService.php:1238`, a general `(\d+(?:\.\d+)?)\s*(?:″|"|inch|...)` pattern).
**How to avoid:** When implementing D-04, either (a) replace the fixed-list regex with the same general inch-parsing pattern `suggestHandlingMethod()` already uses (arguably the cleanest fix — one shared parsing routine, matching D-03's spirit), or (b) since D-01's floor applies to "every display... regardless of panel size," consider whether `roomContainsLargeDisplay()` should stop gating on a resolved size at all and instead fire whenever an item is classified as a display (any size) — mirroring the house-rule's actual wording. Either approach is a bigger edit than deleting one constant; plan for it explicitly.
**Warning signs:** `tests/Unit/Services/Worksheet/SafetyProfileServiceTest.php::test_small_display_does_not_fire_two_person_lift` (fixture: "Samsung 32\" LCD Monitor") currently asserts NO warning fires. Under D-01's unconditional floor this assertion's premise needs re-examination — the planner must decide explicitly whether a 32″ item is a "display" under the house rule or a small-panel exclusion (mirroring `suggestHandlingMethod()`'s `$isSmallPanel` logic, `RamsComplianceUpgradeService.php:1245-1249`, which SafetyProfileService currently has no equivalent of). Flagging as an **Open Question** below rather than silently deciding it.

### Pitfall 2: The hazard seeder's Manual handling control text is the UNAMENDED RULE-02 wording, already shipped live

**What goes wrong:** `database/seeders/HazardTemplateSeeder.php:118` currently reads: *"Team lift required for all displays — minimum two operatives for every panel size."* This is Phase 26's (correct, at the time) porting of the **original, unamended** RULE-02 text from `house-rules.md`. It is now stale relative to D-01's floor-plus-ladder amendment and is **live on production** (confirmed present in RAMS 98's Manual handling hazard row per `26-VERIFICATION.md` round 3).
**Why it happens:** Phase 26 ran before the RULE-02 amendment (2026-08-25, during Phase 27 discussion) existed. The seeder was correct against the requirement it had at the time.
**How to avoid:** This row's `controls` array (`database/seeders/HazardTemplateSeeder.php:111-124`) must be edited to read from `DisplayLiftPolicy` (or have its "minimum two operatives for every panel size" bullet replaced with floor-plus-ladder wording) as part of this phase's seeder update, then **re-seeded on live** the same way Phase 26 did (`php artisan db:seed --class=HazardTemplateSeeder --force`, upsert-by-name, non-destructive per D-03/26).
**Warning signs:** Any RAMS regenerated after this phase's code deploy but before the re-seed runs will still show the old wording in the hazard row (though `material_handling_derived`/§6.7 will show the new wording) — the "two documents disagree" defect D-04 exists specifically to prevent. Sequence the seeder run immediately after code deploy, mirroring Phase 26's deploy runbook.

### Pitfall 3: There are two dead/inert "manual handling team size" texts that must NOT be edited under the mistaken belief they are live

**What goes wrong:** A naive `grep` for "persons" / "team lift" surfaces `app/Services/RiskMatrixService.php:39` (*"Team lift required for screens and equipment over 40″ — minimum two persons"*) and `app/Services/Rams/RamsComplianceUpgradeService.php`'s `fillMissingHazardControls()` (`:535-540`, generic "team lift for items over 20 kg" bullet). Both look like RULE-02 violation sites on first read.
**Why it happens:** `RiskMatrixService::HAZARD_LIBRARY` (`app/Services/RiskMatrixService.php:20-45`) has **zero callers anywhere in `app/`** (confirmed by full-codebase grep — the only match is the class declaration itself). It is dead legacy code, superseded by the DB-backed `HazardTemplate` system. `fillMissingHazardControls()`'s "manual handling" default bullets (`:523-590`) only fire when a hazard's `controls` array is **already empty** — a guard that Phase 26's seeded hazards never trigger, because every seeded hazard ships with a populated controls array. It is reachable in theory but effectively inert against the current data.
**How to avoid:** Do not spend a plan task "fixing" `RiskMatrixService.php` — it produces no output anywhere in the live system. `fillMissingHazardControls()`'s generic bullets contain no specific person-count number and are not a RULE-02 violation as written, so they don't need a `DisplayLiftPolicy` read either — but note them in the plan's audit trail as "considered, confirmed not applicable" so a future reviewer doesn't re-raise them as a missed location.
**Warning signs:** None in production output — that's exactly the signal these are dead. Verify with `grep -rn "RiskMatrixService" app --include=*.php` (expect exactly 1 hit, the class file itself) before investing any fix effort there.

### Pitfall 4: `RamsDocumentComposer` / `MethodStatementComposer` / `rams-v2.blade.php` are NOT live — don't spend fix effort proving correctness there

**What goes wrong:** `app/Support/Rams/SectionComposers/MethodStatementComposer.php` and `app/Support/Rams/RamsDocumentComposer.php` also touch material-handling data shapes (`weight_kg`, `handling_method`). These are consumed by `DocxBuilderServiceV2` and `PdfService`'s alternate path, both gated behind `RAMS_UNIFIED_COMPOSER` (`config/rams.php:43`, `env('RAMS_UNIFIED_COMPOSER', false)`), which Phase 26's research (and this research's re-check) confirms is **unset/false in production**. `resources/views/pdf/rams-v2.blade.php` is likewise not the live PDF template — `resources/views/pdf/rams.blade.php` is.
**Why it happens:** The codebase carries a parallel "unified composer" rewrite that was never flipped on in production.
**How to avoid:** Any "the text now reads X" verification for this phase's success criteria must be proven through `DocxBuilderService::buildRiskAssessment()` (the live DOCX path) — this is Phase 26's own established rule, restated here because it applies identically to Phase 27's material-handling changes. If time permits, apply the same `DisplayLiftPolicy` read to the composer path for consistency (D-03 says "every stating point"), but do not treat it as proof of anything for the live success criteria.
**Warning signs:** A plan or verification step that only checks `rams-v2.blade.php` or the composer's DTO output proves nothing about what engineers actually receive from `dash.21stcav.com`'s real download link.

### Pitfall 5: The AI-generated (non-fallback) method statement can theoretically paraphrase a team size that GATE-09 never sees structured

**What goes wrong:** `MethodStatementPrompt.php`'s system message does not instruct the AI to avoid stating a specific person-count for lifts; it only forbids inventing equipment/site details and forbids self-authoring risk cross-references (`app/Core/AI/Prompts/MethodStatementPrompt.php:70-85`). The AI writes free-form installation-sequence prose per room and could plausibly write "mount the display using a team of three" in a paraphrase of the hazard-register context it's given, independent of `DisplayLiftPolicy`.
**Why it happens:** The prompt's scope is "structure the narrative," and CLAUDE.md's constraint is "AI never invents scope" — a specific numeric team-size claim in free prose is a grey area this phase does not explicitly close (it's about the *deterministic* statement points, not an open-ended AI text-scan).
**How to avoid:** Out of strict scope for this phase (CONTEXT.md's canonical_refs list four specific deterministic locations, not "scan all AI output"), but worth an explicit **Open Question** for the planner: should GATE-09 additionally regex-scan the AI-generated method-statement phases for a stray team-size number as defence-in-depth? Recommend deferring unless a live pack is found doing this (mirrors D-05's own "accepted residual risk, revisit if evidence found" pattern).
**Warning signs:** A live-regenerated RAMS where §6.6 (Method Statement) states a team size the register/§6.7 doesn't agree with, despite all four deterministic locations being correctly fixed.

## Code Examples

### The single shared upgrade() choke point both entry points already use

```php
// Source: app/Services/RamsBuilderService.php:284 (inside runFromReview())
$data = RamsComplianceUpgradeService::upgrade($data);
$data = $this->tier1Defaults->injectDefaultsIntoRamsData($data);
$record->update([/* ...including 'generated_data' => $data */]);
$path = $this->renderer->render($data, $record);
```
```php
// Source: app/Services/RamsBuilderService.php:881 (inside runPipeline())
$data = RamsComplianceUpgradeService::upgrade($data);
$data = $this->tier1Defaults->injectDefaultsIntoRamsData($data);
$record->update([/* ... */]);
$path = $this->renderer->render($data, $record);
```
Identical shape, identical call. `buildFromForm()` (`app/Services/RamsBuilderService.php:108-123`) calls `pipeline()` which calls `runPipeline()` — so all three public entry points converge on exactly two private methods, both of which call `upgrade()`.

### The existing blocking-error precedent (guard pattern) inside `runPipeline()`

```php
// Source: app/Services/RamsBuilderService.php:~868-872 — an existing "pre-render guard"
if (empty($data) || ! array_key_exists('method_statement', $data)) {
    throw new \RuntimeException(
        'Pre-render guard failed: generated_data is empty or method_statement key is missing.'
    );
}
```
GATE-09 should be a second guard of this same shape, placed after `deriveMaterialHandling()` inside `upgrade()` (reachable from both `runPipeline()` and `runFromReview()` without duplicating the check).

### The existing job-level catch that surfaces any thrown exception as a blocking error

```php
// Source: app/Jobs/BuildRamsDocumentJob.php:~176-192
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
    // ...
}
```
```blade
{{-- Source: resources/views/rams/index.blade.php:448-450 --}}
@if ($doc->error_message)
    <span class="error-detail" title="{{ $doc->error_message }}">
        {{ Str::limit($doc->error_message, 120) }}
    </span>
@endif
```

### The existing rollback-flag precedent to copy exactly

```php
// Source: config/rams_tier1.php:56
'hazard_tiering_enabled' => env('RAMS_HAZARD_LIBRARY_TIERING', true),
```
Recommended new entry, same file, same shape: `'display_lift_gate_enabled' => env('RAMS_DISPLAY_LIFT_GATE', true),` — default ON (gate errors as designed), one `.env` edit to disable on live if a false positive is found.

### The existing deterministic strip-out signal (reuse, do not reinvent)

```php
// Source: app/Services/Rams/HazardIncludeWhenResolver.php:83-88
'strip_out_or_decommission' => [
    'strip-out',
    'strip out',
    'decommission',
    'removal of existing',
    'de-install',
],
```
```php
// Source: app/Services/RamsBuilderService.php:277-280 (both runFromReview() and,
// via RamsDataBuilderService::buildScopeItems(), runPipeline()) — already-available
// data at the point RamsComplianceUpgradeService::upgrade() runs
$data['scope_items'] = [
    'decommission' => (array) ($reviewedData['decommission_items'] ?? $data['scope_items']['decommission'] ?? []),
    // ...
];
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|---------------|--------|
| `≥85″ → 4 persons`, `≥65″ → 3 persons` ladder in `suggestHandlingMethod()` | 2-op floor to 90″, 3-op minimum above 90″ (D-01) | This phase (2026-08-25 decision) | Removes the exact defect the 21CQ30960 professional review raised; the "≥65″ → panel-lift trolley discharges the second person" wording (`RamsComplianceUpgradeService.php:1261-1262`) must not be carried into the >90″ band per D-02. |
| `LARGE_DISPLAY_INCHES = 55` size-conditional worksheet warning | Unconditional floor for every display, ladder above 90″ (D-04) | This phase | Every display now produces a warning, including ones under 55″ — see Pitfall 1 for why a naive constant deletion is insufficient. |
| RULE-02 sourced verbatim from `house-rules.md` ("regardless of panel size") | Deliberate 21CAV app-side override (floor + one band) | 2026-08-25, recorded in `REQUIREMENTS.md:74` and `27-CONTEXT.md` D-01 | The skill file itself is unedited; the app now deliberately diverges from its own upstream source of truth on this one clause only. |

**Deprecated/outdated:**
- The `≥85″/≥65″` team-size ladder in `RamsComplianceUpgradeService::suggestHandlingMethod()` — superseded by D-01, must be removed entirely, not merely relabelled.
- `SafetyProfileService::LARGE_DISPLAY_INCHES` — removed per D-04, see Pitfall 1 for the accompanying regex fix this requires.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `RiskMatrixService::HAZARD_LIBRARY` is fully dead code with zero live callers | Common Pitfalls #3 | Verified by direct grep (`grep -rn "RiskMatrixService" app --include=*.php` → 1 hit, the class declaration). Confidence HIGH, not really an assumption, but flagged because a stale binding via service-container magic (`app()->bind` on an interface) was not exhaustively ruled out — a targeted grep for `RiskMatrixService::class` and `bind(` in `AppServiceProvider.php` would close this fully if the planner wants zero residual doubt. |
| A2 | `fillMissingHazardControls()`'s "manual handling" fallback bullets are unreachable against Phase-26-seeded hazards because every seeded hazard ships with a non-empty `controls` array | Common Pitfalls #3 | If a future data path ever produces a "Manual handling" hazard row with an empty `controls` array (e.g. a malformed AI-extracted hazard name that fuzzy-matches but loses its controls), this generic fallback would fire with wording that doesn't reference `DisplayLiftPolicy`. Low risk, but worth a defensive test if the planner wants full coverage. |
| A3 | The AI-generated (non-fallback) method statement will not independently state a numeric team size that contradicts `DisplayLiftPolicy` in practice, given current prompt instructions | Common Pitfalls #5 | If wrong, GATE-09 as designed (checking structured `material_handling_derived` only) would not catch an AI-authored contradiction in §6.6 prose. Recorded as an explicit Open Question, not silently assumed away. |

## Open Questions

1. **Does D-01's floor apply to a small monitor / room-scheduling panel the same way it applies to a genuine display?**
   - What we know: `suggestHandlingMethod()` already excludes small scheduling/touch/booking/control panels ≤14″ from the manual-handling row entirely (`RamsComplianceUpgradeService.php:1245-1249`). `SafetyProfileService::roomContainsLargeDisplay()` has no equivalent exclusion — it just checks size, and its keyword regex currently only matches specific large sizes anyway.
   - What's unclear: Whether D-04's "every display produces a lift warning" is meant to apply the same small-panel exclusion, or whether it's intentionally broader on the worksheet side (worksheets are aimed at engineers doing physical install work, where even a 32″ monitor is a two-handed carry).
   - Recommendation: Decide explicitly in planning and record the decision; do not let it default silently one way or the other. Suggest mirroring `suggestHandlingMethod()`'s exclusion for consistency, since D-04's stated rationale is "two engineers reading two documents... must not be told different team sizes" — consistency argues for the same exclusion logic on both sides.

2. **Should GATE-09 also scan the AI-generated method-statement prose for a stray team-size mention?**
   - What we know: The AI prompt does not instruct against stating a specific person-count, and nothing currently validates AI-authored §6.6 text against `DisplayLiftPolicy`.
   - What's unclear: Whether this is a live risk (no evidence found yet in production output) or purely theoretical.
   - Recommendation: Defer, per D-05's own precedent of accepting a residual risk deliberately rather than over-engineering a defence against an unobserved failure mode. Revisit only if a live-generated pack is found stating a contradictory team size in free prose.

3. **Is `RamsComplianceUpgradeService::fillMissingHazardControls()`'s generic "manual handling" default (`app/Services/Rams/RamsComplianceUpgradeService.php:535-540`) worth wiring to `DisplayLiftPolicy` even though it's currently unreachable against seeded data?**
   - What we know: It only fires on a hazard row with empty `controls`, which Phase 26's seeded hazards never produce.
   - What's unclear: Whether a future data-quality bug (partial AI extraction, seeder drift) could ever produce that state.
   - Recommendation: Low priority; a one-line defensive wire-up (reading `DisplayLiftPolicy`'s generic sentence instead of the current static bullet) costs little and removes a latent inconsistency, but is not required to satisfy any of the four ROADMAP success criteria. Planner's discretion.

## Environment Availability

Skipped — this phase has no external tool/service/runtime dependency beyond the existing Laravel/PHP/MySQL stack already running in production (per `STACK.md`, unchanged by this phase). No new CLI tools, no new services, no new package managers.

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.5.3 (`composer.json`), Laravel test base classes |
| Config file | `phpunit.xml` (testsuites: `Unit` → `tests/Unit`, `Feature` → `tests/Feature`) |
| Quick run command | `php artisan test --filter=DisplayLift` (once new test classes exist) or `vendor/bin/phpunit --filter=DisplayLift` |
| Full suite command | `php artisan test` (or the `composer test` script defined in `composer.json:63`) — baseline at time of research: 2265+ passed (per `26-VERIFICATION.md`), 1 pre-existing unrelated flake (`QueueRecoverCommandTest`) |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| RULE-02 | `DisplayLiftPolicy::forSize()` returns 2-op floor ≤90″, 3-op minimum >90″, never resolves to 1 or 4 | unit | `php artisan test --filter=DisplayLiftPolicyTest` | ❌ Wave 0 — new class + new test |
| RULE-02 | `suggestHandlingMethod()` no longer emits the `≥85″/≥65″` ladder or the panel-lift-trolley-discharges-second-person wording | unit | `php artisan test --filter=RamsComplianceUpgradeServiceTest` | ❌ Wave 0 — extend or create; no existing test asserts the old ladder (confirmed by grep — no test currently pins the 4-person/3-person specific strings) |
| RULE-03 | A strip-out-of-wall-mounted-display job produces the explicit removal-sequence statement | feature | `php artisan test --filter=WallMountRemovalStatementTest` | ❌ Wave 0 — new fixture-driven test, model on `tests/Unit/Services/Rams/AccessEquipmentContradictionTest.php`'s revert/restore methodology |
| RULE-12 | Mount/bracket description no longer inherits display handling text (branch reordering) | unit | `php artisan test --filter=RamsComplianceUpgradeServiceTest` | ❌ Wave 0 — no existing test currently pins the mount-inherits-display bug; must be written to prove the fix is non-vacuous (assert failure before fix, pass after, per this codebase's own established non-vacuity convention — see `260817-r5e SUMMARY.md`'s "revert each fix and observe failure" pattern) |
| GATE-09 | Throws on 1-op, 4-op, or 2-op-above-90″ fixtures; does not throw on an unresolvable size (D-05) | unit | `php artisan test --filter=DisplayLiftGateTest` | ❌ Wave 0 — new test, must include a revert-the-fix-and-observe-failure pass per ROADMAP's explicit success-criterion methodology |
| GATE-09 | Fires identically via both `runFromReview()` and `runPipeline()` | feature | `php artisan test --filter=RamsBuilderServiceTest` (extend existing) | ⚠️ Partial — `RamsBuilderServiceTest` exists (used in Phase 26, per `26-08` summary) and is the natural home for a dual-path assertion, mirroring Phase 26's own hard-learned lesson that a single-path test is insufficient |
| D-04 (worksheet parity) | `SafetyProfileService` produces the same team-size wording as the RAMS path for the same input | unit | `php artisan test --filter=SafetyProfileServiceTest` (extend existing) | ⚠️ Existing file `tests/Unit/Services/Worksheet/SafetyProfileServiceTest.php` will need `test_small_display_does_not_fire_two_person_lift` revisited per Pitfall 1/Open Question 1 |

### Sampling Rate
- **Per task commit:** `php artisan test --filter=<TouchedClass>Test`
- **Per wave merge:** `php artisan test` (full suite)
- **Phase gate:** Full suite green before `/gsd:verify-work`, PLUS the live-regeneration proof of 21CQ30960 (ROADMAP success criterion 4) — this cannot be automated-test-only, per this milestone's own standing "validated on live" decision (Phase 26 precedent, carried forward in `27-CONTEXT.md` `<specifics>`).

### Wave 0 Gaps
- [ ] `tests/Unit/Services/Rams/DisplayLiftPolicyTest.php` — new, covers the policy class itself (bands, never-1, never-4, D-05 unresolvable-size floor)
- [ ] `tests/Unit/Services/Rams/DisplayLiftGateTest.php` — new, GATE-09's throw/no-throw behaviour, including the revert-and-restore non-vacuity proof the ROADMAP explicitly requires
- [ ] Extend `tests/Unit/Services/Rams/RamsComplianceUpgradeServiceCacheTest.php` or create a sibling test file asserting `suggestHandlingMethod()`'s new bands and RULE-12's branch-order fix (no existing test currently pins either the old ladder or the mount-inherits-display bug — both are currently silent regressions waiting to happen without a new test)
- [ ] Extend `tests/Feature/Rams/DocxBuilderPdfParityTest.php` (already exercises `material_handling_derived` → DOCX §6.7, `:441-456`) to assert the new band wording renders correctly through the LIVE `DocxBuilderService` path specifically
- [ ] No test framework install needed — PHPUnit is already fully configured

## Security Domain

`security_enforcement` is not set to `false` in `.planning/config.json` (key absent → treat as enabled), so this section is included for completeness, though this phase is a low-relevance domain for ASVS-style controls — it changes safety-document *content generation logic*, not authentication, session handling, or externally-facing input surfaces.

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | No | Unchanged — this phase touches no auth code |
| V3 Session Management | No | Unchanged |
| V4 Access Control | No | Existing `RamsDocumentPolicy` authorization (`$this->authorize('update', $rams)`) already gates all generation-triggering routes; this phase adds no new route |
| V5 Input Validation | Marginal | The `material_handling_items.*.weight_kg` field (`RamsController.php:368`, engineer free-text input, `max:20` chars) is unrelated to this phase's `DisplayLiftPolicy` — it is a manually-typed override that already wins over derived content (`DocxBuilderService::buildMaterialHandling()`, "user-specified items first" per its own docblock) and needs no new validation for this phase |
| V6 Cryptography | No | Not applicable |

### Known Threat Patterns for this stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| A malformed or adversarial quote-line description crashing `suggestHandlingMethod()`'s regex (ReDoS-adjacent) | Denial of Service | Existing regex (`RamsComplianceUpgradeService.php:1238`) is already bounded/simple (no catastrophic backtracking pattern); `DisplayLiftPolicy::forSize()` should accept a nullable float, never re-run regex itself, keeping the attack surface unchanged from today |
| GATE-09 throwing on legitimate but unusual real project data, silently blocking a real client deliverable with no visibility | Denial of Service (self-inflicted) | This is why the `RAMS_DISPLAY_LIFT_GATE` env-flag rollback (mirroring `RAMS_HAZARD_LIBRARY_TIERING`) is not optional — it is the accepted mitigation for exactly this risk, per CONTEXT.md's "Reversibility" discretion item and the milestone's live-validation posture |

## Sources

### Primary (HIGH confidence — direct code reads, this repository, 2026-08-25)
- `app/Services/Rams/RamsComplianceUpgradeService.php` — full read of `upgrade()` (`:24-45`), `suggestHandlingMethod()` (`:1231-1290`), `deriveMaterialHandling()` (`:1086-1213`), `fillMissingHazardControls()` (`:523-590`), `addProjectSpecificRisks()` (`:611-700+`), `addCdmDutyHolders()` (`:1057-1075`)
- `app/Services/RamsBuilderService.php` — full trace of `buildFromForm()`, `buildFromQuote()`, `pipeline()`, `runPipeline()` (`:809-900`), `runFromReview()` (`:131-330`), confirming both call `RamsComplianceUpgradeService::upgrade()`
- `app/Jobs/BuildRamsDocumentJob.php` — full read, confirming the single job-level `catch (\Throwable $e)` → `STATUS_FAILED` + `error_message` mechanism covers both `buildFromForm()` and `buildFromReview()`
- `app/Services/Worksheet/SafetyProfileService.php` — full read, confirming `weight_kg`/`display_size_in` "metadata-first" claim and its actual keyword-fallback regex limits
- `app/Services/WorksheetGeneratorService.php` — traced `$room['equipment']` provenance back to raw quote `equipment_list`, confirming no structured tags reach `SafetyProfileService`
- `app/Core/Modules/Projects/ProjectDataService.php` — confirmed `display_size_in` is a per-room `SiteSurveyRoom` field, never merged onto per-item equipment dicts
- `app/Services/Rams/HazardIncludeWhenResolver.php` — full read, confirming `strip_out_or_decommission` tier-2 signal already exists and is wired
- `app/Services/RiskMatrixService.php` + full-codebase grep — confirmed dead code (zero live callers)
- `app/Services/MethodStatementService.php` (`:116`, `:424-480`) + `app/Services/MethodStatementGeneratorService.php` (`:176-200`) — confirmed the fallback-string call chain
- `app/Core/AI/Prompts/MethodStatementPrompt.php` — confirmed no team-size instruction either way in the AI prompt
- `database/seeders/HazardTemplateSeeder.php:108-124` — confirmed the Manual handling row's current (unamended) wording
- `.planning/quick/20260817-rams-generator-defects/SUMMARY.md` — confirmed GATE-03/GATE-08 were generator fixes, not error-raising gates; confirmed `RAMS_UNIFIED_COMPOSER` production posture
- `.planning/phases/26-hazard-library-structural-inversion/26-VERIFICATION.md` — confirmed live rollback-flag precedent, confirmed the three legacy manual-handling defects already logged as "Phase 27 scope, not fixed by Phase 26"
- `config/rams_tier1.php`, `config/rams.php` — confirmed exact env-flag shape to replicate
- `.planning/reference/21cav-rams-skill/references/house-rules.md` — the unamended settled position (RULE-03/RULE-12 constraints still authoritative)

### Secondary (MEDIUM confidence)
- None used — no WebSearch/Context7/external documentation lookups were needed for this phase (zero new libraries, zero framework-version questions).

### Tertiary (LOW confidence)
- None.

## Metadata

**Confidence breakdown:**
- Standard stack: N/A — no new stack introduced
- Architecture: HIGH — every claim traced through actual code, both generation paths independently verified to converge on one method
- Pitfalls: HIGH — each pitfall confirmed by direct grep/read, not inferred; the two "dead code" claims verified by exhaustive caller search
- Gate mechanism: HIGH — the exception-propagation path was traced end-to-end from `RamsComplianceUpgradeService::upgrade()` through `BuildRamsDocumentJob::handle()` to the Blade view that renders `error_message`

**Research date:** 2026-08-25
**Valid until:** No expiry driver — this is an internal-codebase research pass, not dependent on external library versions. Re-verify only if Phase 28/29/30/31 land first and touch any of the same files (`RamsComplianceUpgradeService.php` is flagged in ROADMAP as "the single most-touched file across this milestone").
