# Phase 30: Structural Validation Gates - Research

**Researched:** 2026-09-13
**Domain:** Laravel/PHP deterministic document-consistency gates inside an existing 2,038-line service
**Confidence:** HIGH on codebase facts (every claim below carries a `file:line` read on 2026-09-13); MEDIUM on the GATE-14 inference recommendation (design judgement, not a verifiable fact); UNKNOWN on live-corpus shape (not measurable from this machine).

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

- **D-01:** All five requirements ship in Phase 30 — GATE-01, GATE-02, GATE-04, GATE-13, GATE-14. The ROADMAP goal line ("the three gates") is **stale**. The planner MUST write the two missing success criteria for GATE-13 and GATE-14 and should correct the goal line in the same pass.
- **D-02:** **GATE-13 ships whole but disarmed**, flipped on in Phase 31. Its COSHH half cannot fire correctly today because `Tier1RamsDefaultsService::injectDefaultsIntoRamsData()` sets `$data['coshh_baseline']` unconditionally (`:81`) from `config/rams_tier1.php`, whose baseline carries Tin/Lead Solder (`:162`) and Rosin Flux (`:173`). Build both halves now with tests; do not split one gate across two phases.
- **D-03:** **All five gates ship disarmed** (`env(..., false)`), flipped after live verification. Deploy order: ship code (flags false) → verify live regeneration → flip each flag as a separate one-line `.env` change.
- **D-04:** *(Claude's discretion — user delegated)* **Three kill-switch flags:**
  - `RAMS_STRUCTURAL_GATES` → GATE-01 + GATE-02 + GATE-04
  - `RAMS_MISSING_RISK_REF_GATE` → GATE-14 alone
  - `RAMS_HOT_WORKS_GATE` → GATE-13 (flips in Phase 31 per D-02)

  Each flag is **new and independent** and must never reuse `RAMS_DISPLAY_LIFT_GATE`, `RAMS_PPE_CEILING_ELECTRICAL_GATE` or `RAMS_CDM_AE_GATE`.
- **D-05:** GATE-01 errors when **either** the hazard row **or** the client-responsibility entry is missing — both are required support. ROADMAP criterion 1 is wrong and should be corrected.
- **D-06:** The trigger vocabulary lives in `config/rams_tier1.php` as **data, not code**, so it is tunable without a deploy.
- **D-07:** Hazard-side matching **reuses Phase 26's signal machinery** — `app/Services/Rams/HazardIncludeWhenResolver.php`. Do not build a second, parallel hazard-matching vocabulary.
- **D-08:** **`clientReqs` does not exist in this application.** Its app equivalent is the **union of two buckets**: `$data['client_responsibilities']` and `client_responsibilities_expanded`. Both buckets are pooled and matched with the **same signal vocabulary** as D-07.

### Claude's Discretion

- **D-04 (flag granularity)** — user answered "You decide".
- **Where the five gate methods live** — the planner may keep them on `RamsComplianceUpgradeService` or extract per-gate classes; either is acceptable provided each gate stays independently flag-gated and independently testable.
- **Error message wording, and first-violation vs collect-all** — planner's call.

### Deferred Ideas (OUT OF SCOPE)

- **GATE-13's arming** — deferred to Phase 31, not dropped.
- **Making the COSHH and standards tables job-conditional** — Phase 31 scope.
- **DATA-01** (`REQUIREMENTS.md:119`) — `scope_items.decommission` never populated. Noted because it means some hazard signals can never fire on real jobs, which could mask or distort GATE-01 matching.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| GATE-01 | Orphan controls — every method step / hazard control referencing a document, permit or hold point has a matching hazard row AND a matching client-responsibility entry | Findings 1, 2, 6; the `client_responsibilities_expanded` mirror gap is the blocking discovery |
| GATE-02 | Every area has at least one method step | Finding 3 — **no usable area enumeration exists inside `upgrade()`'s array today**; a mirror task is mandatory |
| GATE-04 | Residual score ≤ initial score (error); residual severity below initial severity (warn) | Finding 4 — exact field names, exact `RA##` index rule, and a live warn-hit on the committed golden fixture |
| GATE-13 | Hot-works contradiction | Finding 5 — **both halves** false-positive corpus-wide, not just the COSHH half D-02 names |
| GATE-14 | Missing risk references | Finding 7 — the app **already computes** the risks line deterministically; this reframes the gate entirely |
</phase_requirements>

## Summary

Phase 30 is not a "write five validators" phase. Four of the five gates are blocked on a **data-reachability** problem that is structurally identical to the one Plan 27-07 closed for GATE-09: the array `RamsComplianceUpgradeService::upgrade(array $ramsData): array` receives does **not** contain the data three of the five gates need to check. Specifically, `client_responsibilities_expanded` (GATE-01's second required support, per D-08) and any usable area/room enumeration (GATE-02's entire subject) live in `reviewed_data`, not `generated_data`, and are never mirrored across. Writing the gate bodies without the mirrors produces gates that pass vacuously on every real document — the precise failure mode `29-06`'s dual-path test file documents for GATE-12 at initial-build time.

The second structural discovery reframes GATE-14. `RamsComplianceUpgradeService::crossReferenceMethodStatementRisks()` (`:991-1092`) already **strips the model's own "Associated Risks:" line** (`:1043-1046`) and **recomputes** `associated_risks` deterministically from a hard-coded `$keywordRiskMap` (`:1015-1027`). So in this application a method step's risk references are the app's own output, not the AI's. GATE-14 therefore cannot be "check the model cited enough hazards" — it must be an *independent* re-check of the app's own derivation, and it must not reuse `$keywordRiskMap` or it becomes the "gate that re-derives the thing it is checking" anti-pattern 27-RESEARCH.md named and `enforceDisplayLiftGate()`'s docblock (`:1268-1276`) explicitly guards against.

Third, D-02's rationale for shipping GATE-13 disarmed is **stronger than CONTEXT.md states**. The COSHH half is blocked by `Tier1RamsDefaultsService:81` as recorded — but the *permit* half is blocked by a defect one layer deeper: `RamsComplianceUpgradeService::addPermitAndIsolation()` emits `'Hot works permit required if soldering or heat-shrink operations are performed on site'` **unconditionally, on every document, inside `upgrade()` itself** (`:939`). Both halves of GATE-13 would fire corpus-wide if armed. D-02's conclusion is unchanged; its evidence base doubles.

**Primary recommendation:** Sequence this phase as *mirrors first, gates second*. Wave 0 closes the three data-reachability gaps (`client_responsibilities_expanded`, an area list, and the `compliance_warnings` write channel) with dual-path tests proving each gate can actually *see* its subject on all four `upgrade()` surfaces; only then implement the five gate bodies. Keep all five as private static methods on `RamsComplianceUpgradeService` (see Finding 8). Run a read-only corpus measurement (`30-MEASUREMENT.md`) before writing GATE-04's and GATE-14's thresholds, not after.

---

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Gate evaluation (all 5) | Service layer — `app/Services/Rams/` | — | Every shipped gate lives here; `upgrade()` is the single dispatch point (`:38-104`) |
| Gate trigger vocabulary | Config — `config/rams_tier1.php` | — | D-06 requires data-not-code so it is tunable without deploy; `coshh_products`/`standards_references` are the existing precedent |
| Data reachability (mirrors) | Controller (`RamsController`) + `RamsBuilderService` | — | The mirror must happen at each entry point *before* `upgrade()` — exactly where Plan 27-07 put the `material_handling` mirror (`RamsController.php:594`, `RamsBuilderService.php:284`, `:939`) |
| Error surfacing (blocking) | Exception → controller catch → session flash → Blade | — | `RamsGenerationException` caught at `RamsController.php:603`, `:701`, `:857`; already complete, needs only message strings |
| Warning surfacing (advisory) | Service writes `generated_data['compliance_warnings']`; Blade reads it | — | UI-SPEC contract; persisted via the existing `$rams->update([... 'generated_data' => ...])` at `RamsController.php:623` |
| Warning suppression from client-facing output | PDF/DOCX render layer | — | Must never render; safe by construction today because the blades read only named keys |
| Corpus measurement | Read-only artisan/tinker on the live VPS | — | `29-MEASUREMENT.md` precedent — operator-run, zero writes |

---

## Findings

### Finding 1 — `upgrade()`'s six call sites (four HTTP-reachable surfaces + one CLI)

`grep -rn "RamsComplianceUpgradeService::upgrade"` returns exactly six invocation sites:

| # | Site | Path | Array passed |
|---|------|------|--------------|
| 1 | `RamsBuilderService.php:297` | `buildFromReview()` / `runFromReview()` — regenerate from review | `$data` assembled by `RamsDataBuilderService::assemble()` |
| 2 | `RamsBuilderService.php:942` | `buildFromForm()` / `runPipeline()` — initial AI build | same assembler |
| 3 | `RamsController.php:603` | **Save Review** (the path an engineer actually uses) | `$generatedData` = persisted `generated_data`, plus two explicit mirrors |
| 4 | `RamsController.php:701` | DOCX rebuild-on-download | `$rams->generated_data` |
| 5 | `RamsController.php:857` | `downloadPdf()` | `$rams->generated_data` |
| 6 | `RamsRefreshComplianceCommand.php:185` | `rams:refresh-compliance` artisan command | `$data` = persisted `generated_data`, hazard-deduped |

Plus the **live PDF render**, which reads `generated_data` directly (`resources/views/pdf/rams.blade.php:290` `$hazards = $data['hazards']`, `:292` `$ms = $data['method_statement']`) — the fourth surface Plan 27-07 had to close for GATE-09.

**Ordering trap for GATE-13 (HIGH confidence, verified):** on sites 1 and 2, `$this->tier1Defaults->injectDefaultsIntoRamsData($data)` runs **after** `upgrade()` (`RamsBuilderService.php:297` then `:302`; `:942` then `:947`). So `coshh_baseline` is *absent* from the array on the initial-build paths and *present* on sites 3-6 (it was persisted by the previous build). GATE-13's COSHH half therefore fires on the review/download paths but is dormant at initial build. The planner must not assume symmetric behaviour — a dual-path test written only against `buildFromForm()` would pass vacuously.

### Finding 2 — GATE-01's second support bucket is not in the array (BLOCKING)

D-08's union is `client_responsibilities` + `client_responsibilities_expanded`. Only the first is reachable:

- `client_responsibilities` **is** in the pipeline array — set at `RamsDataBuilderService.php:105` (`deriveClientResponsibilities($formData)`), normalised to a flat `string[]` at `:525-528`, rendered at `resources/views/pdf/rams.blade.php:298`. Present in the golden fixture's `generated_data` (5 strings).
- `client_responsibilities_expanded` is written **only into `$reviewedData`** at `RamsController.php:522-536` and defaulted at `RamsDisplayPatchService.php:402`. `grep -rn "\['client_responsibilities_expanded'\]" app/` returns zero writes into any `generated_data`/pipeline array. It is read from `$rams->reviewed_data` at render time (`pdf/rams.blade.php:447`, `pdf/rams-v2.blade.php:486`, `rams/review.blade.php:267`).

**Its shape is not a list.** It is a dict of four fixed buckets plus an `additional` array:
```php
['network_readiness' => ['required' => bool, 'notes' => string],
 'licences' => [...], 'access' => [...], 'power_validation' => [...],
 'additional' => [['item' => string, 'notes' => string], ...]]
```
(`RamsController.php:522-536`). The four fixed buckets have **no free text describing the responsibility** — their human labels live in the Blade (`pdf/rams.blade.php:1588-1593`, `$crExpLabels`). Only `additional[*]['item']` and each bucket's `notes` carry engineer-authored text a trigger vocabulary could match.

**Consequence:** GATE-01 needs (a) a mirror of `reviewed_data['client_responsibilities_expanded']` into the pipeline array before `upgrade()` at the entry points, and (b) an explicit flattening rule stating which sub-fields are searchable (recommend: `additional[*].item`, `additional[*].notes`, each bucket's `notes`, plus a synthetic label for any bucket with `required === true`, sourced from a config map so the Blade's `$crExpLabels` is not duplicated in PHP).

### Finding 3 — GATE-02 has no area enumeration to iterate (BLOCKING, and the answer to open question 2)

Traced concretely. Three candidate fields exist; none works today:

| Candidate | Where set | Reachable from `upgrade()`? | Verdict |
|---|---|---|---|
| `$data['rooms']` | `RamsDataBuilderService.php:88` = `$projectContext['rooms']`; re-guarded at `:531` | Yes, but **only when a site survey exists** (`:66`, `:70` `if (! empty($contextRooms))`) | Site-survey-only; empty on every quote-only RAMS |
| `$data['quote']['room_summaries'][*]['room']` | `buildQuoteSummary()` `:270-273` from `$parsed['rooms']` | Yes — and on the review path `$parsed['rooms']` is derived from `reviewed_data['room_overviews'][*]['room']` (`RamsBuilderService.php:193-196`) | **Returns `[]` whenever `$parsed['equipment']` is empty** (`:243-245`). In the committed golden fixture `generated_data['quote']` is literally `[]`. |
| `$data['room_overviews']` | **Never set.** `grep -n room_overviews app/Services/RamsDataBuilderService.php` returns zero hits | No | `ensurePerRoomBullets()` (`RamsComplianceUpgradeService.php:165-168`) early-returns on every builder path for this reason |

The **authoritative** area list is `reviewed_data['room_overviews'][*]['room']` — the engineer-facing list, written by `RamsReviewController.php:311`, `ProjectPackageReviewController.php:281/888/1193`, `ExtractQuoteJob.php:257`. Fixture confirms the shape: `[{"room": "Main Boardroom", "overview": "...", "works_summary": "- ..."}, {"room": "AV Rack", ...}]`.

**Recommendation for GATE-02:**
1. Mirror `reviewed_data['room_overviews']` into the pipeline array at all entry points (same pattern as `material_handling`), giving the gate a single canonical `$data['room_overviews']` to read. This also un-blocks the long-dormant `ensurePerRoomBullets()` — **flag that as a side effect the planner must decide on deliberately**, because it would newly fire AI bullet conversion on the review path. Safer alternative: mirror under a gate-private key (e.g. `$data['areas_for_gate']`) so nothing else changes behaviour. **Recommend the gate-private key** — it preserves the "keep the migration diff minimal" posture and avoids waking a dormant AI call.
2. **Zero areas passes vacuously, and must.** A form-only / manual RAMS has no room list at all (`ManualRamsCreationTest.php` exercises that path), and `buildQuoteSummary()` legitimately returns `[]`. Erroring on an empty area list would reject every manual RAMS — a false positive on correct output, exactly what ROADMAP criterion 4 forbids. This matches the conservative-by-construction discipline in `ControlTextRuleViolations` and `parseStatedTeamSize()`'s null-skips (`RamsComplianceUpgradeService.php:1289-1295`).
3. **Matching an area to a method step must be name-based**, because method-statement phases carry **no area/room key**. Verified: `RamsDataBuilderService.php:500` normalises each phase to exactly `['title' => string, 'steps' => string[]]`; `crossReferenceMethodStatementRisks()` adds only `associated_risks` and `associated_risks_label`; `pdf/rams.blade.php:1621-1637` renders only `title` / `steps` / `associated_risks_label`. So GATE-02 is "does any phase title or step string contain this area's name (case-folded)". That is a genuine false-positive risk on generic room names ("AV Rack", "Room 1") and is the single strongest argument for measuring the corpus before arming (Finding 9).

**Are there production documents with areas but no method steps?** Cannot be answered from this machine — no live DB access, and `php` is not on this shell's PATH. This is a measurement task, not a research answer. See Finding 9.

### Finding 4 — GATE-04 is fully specified by existing fields, and already has a warn-hit in the committed fixture

Hazard rows are normalised to a fixed 10-key shape at `RamsDataBuilderService.php:440-457`:
`id`, `hazard`, `persons_at_risk`, `pre_likelihood`, `pre_severity`, `controls`, `post_likelihood`, `post_severity`, `score_reviewed`, `controls_reviewed`, `needs_confirmation`. Each score is `max(1, min(5, (int) ...))` — **clamped to 1..5, defaulting to 1 when absent**.

Scores are computed at render: `$preScore = $preL * $preS; $postScore = $postL * $postS;` and the reference label is `'RA' . str_pad($loop->index + 1, 2, '0', STR_PAD_LEFT)` (`pdf/rams.blade.php:1326-1333`). The `RA##` reference is the **row position, not `$h['id']`** — the 260817-r5e correction, documented at `RamsComplianceUpgradeService.php:1000-1006` and mirrored in `DocxBuilderService.php:1221`. GATE-04's error message must use the same index rule or it will name the wrong row.

So:
- **Error:** `post_likelihood * post_severity > pre_likelihood * pre_severity`
- **Warn:** `post_severity < pre_severity`

**Two false-positive vectors, both verified:**
1. The `?? 1` defaults mean a hazard row missing `pre_*` gets an initial score of 1; any populated residual then exceeds it. Recommend skipping rows where either `pre_likelihood` or `pre_severity` was **absent** from the source array (check with `array_key_exists` before normalisation, or skip rows whose pre-score is exactly 1).
2. **The committed golden fixture trips the warn branch today.** `tests/Fixtures/rams/tilda-21cq29531/record.json` hazard 0 — "Working at height for display installation (up to 3m)" — is `pre 3×4=12`, `post 1×3=3`: residual severity 3 < initial severity 4. That is *correct, intended* output (HAZ-03's aligned Working-at-Height scoring; see `tests/Feature/Rams/WorkingAtHeightResidualScoreTest.php`, which asserts the `1x4` residual through the live DOCX path). This is the clearest possible evidence that GATE-04's `s2 < s1` branch **must be a warning and must never be an error** — and it gives the planner a ready-made non-vacuity fixture for the warn path with zero fixture authoring.

### Finding 5 — GATE-13: **both** halves false-positive corpus-wide, not just COSHH

D-02 records the COSHH blocker (`Tier1RamsDefaultsService.php:81`: `$data['coshh_baseline'] = (array) config('rams_tier1.coshh_products', []);` — unconditional, verified). The permit half has the same defect one layer earlier:

```php
// RamsComplianceUpgradeService.php:931-942 — addPermitAndIsolation(), unconditional, inside upgrade()
'Hot works permit required if soldering or heat-shrink operations are performed on site',   // :939
```

Every document that passes through `upgrade()` gets that line. A naive GATE-13 ("asserts no hot works AND requires a hot-works permit") fires on 100% of the corpus.

Mitigating nuance the planner can exploit: the shipped sentence is **conditional** ("*if* soldering or heat-shrink operations are performed"), whereas the 21CQ30960 defect is an *unconditional* `§6.8 requires a hot-works permit for soldering`. A conservative GATE-13 that treats a conditional permit rule as non-contradictory would not fire on `:939`. There is a second permit source: `pdf/rams.blade.php:407-409` and `pdf/rams-v2.blade.php:463-465` derive a `'Hot Works Permit'` row **in the Blade** from `preg_match('/(solder|heat shrink|hot work)/', $scopeBlob)` — that derivation is invisible to `upgrade()` and is a genuine bypass surface the planner should note (a document can display a hot-works permit requirement the gate never sees).

**The "no hot works" assertion side has no app-side source.** `grep -rni "hot work" app/ config/ resources/views/pdf/` returns only the five lines above — none of them asserts *absence*. So the assertion is engineer- or AI-authored free text living in `exclusions` / a hazard row / controls. Detecting it requires the negation-aware discipline already established in `ControlTextRuleViolations::CONFINED_SPACE_NEGATIONS` (`:101-109`) / `CONFINED_SPACE_AFFIRMATIVE` (`:118-125`) — a two-list, negation-first design. **Recommend adding a `hot_works_assertion` detector to that registry** (`DETECTORS` at `:84-89`) rather than a bespoke regex in the gate, so the phase inherits the conservative-by-construction discipline and the existing test file `ControlTextRuleViolationsTest.php`.

### Finding 6 — `HazardIncludeWhenResolver` cannot do what D-07 literally asks, but can do what D-07 means

`resolve(Collection $library, array $signals): Collection` (`:150`) maps over a `Collection<HazardTemplate>` — **Eloquent models, from the database** — and returns the subset whose `include_when` matches the job's signals (`$signals = ['activities' => string[], 'drilling_required' => bool, 'scope_narrative' => string]`). Tier-2 matching is `tier2Matches()` (`:205-223`): drilling flag → `TIER2_DRILLING_SIGNALS`, activity intersect → `TIER2_ACTIVITY_SIGNALS`, keyword contains → `TIER2_KEYWORD_SIGNALS`. Tier-3 is pre-tick-only (`:225-234`).

Two frictions the planner must resolve explicitly:

1. **It answers "which hazards *should* be here", not "is hazard X in this document's register".** GATE-01 needs the latter — a membership test against `$data['hazards'][*]['hazard']` (free-text names written by AI/engineer, e.g. `"Working at height for display installation (up to 3m)"`), which are **not** template names. D-07's workable reading — and the one the CONTEXT.md sentence actually states — is: *a trigger is satisfied on the hazard side when its mapped signal resolves a hazard into the set.* Practically that means reusing the **signal keyword vocabulary** (`TIER2_KEYWORD_SIGNALS`, `TIER3_KEYWORD_PRECHECK` — which already contains an `asbestos` key at `:118+`, exactly the canonical GATE-01 trigger) as the shared matching vocabulary against the document's own hazard names, rather than calling `resolve()`.
2. **`RamsComplianceUpgradeService`'s class docblock says "Deterministic. No AI. No database."** (`:19`). Calling `resolve()` from a gate would need a `HazardTemplate` query and break that invariant. Reusing the **const maps** does not. **Recommend: extract the keyword maps into a shared read-only source (or expose them via public const / a small `HazardSignalVocabulary` value class) and match against them — never call `resolve()` from inside `upgrade()`.** This satisfies D-07's "do not build a second, parallel vocabulary" while preserving the no-DB invariant.

### Finding 7 — GATE-14: the app already writes the risks line, which changes the gate's job (answer to open question 3)

`crossReferenceMethodStatementRisks()` (`:991-1092`) does three things, in order:
1. **Strips** any model-authored `Associated Risks: …` bullet via `isAssociatedRisksLine()` (`:1043-1046`, `:1100-1103`) — with an explicit comment that the AI prompt no longer asks for one but "models ignore negative instructions".
2. **Recomputes** matches with a hard-coded `$keywordRiskMap` (`:1015-1027`), requiring the keyword to appear in **both** the combined phase text **and** the hazard name (`:1057-1063`), with a >4-char word-fragment fallback when nothing matched (`:1067-1077`).
3. Writes `$phase['associated_risks']` (int row indices) and `$phase['associated_risks_label']` (`'Associated Risks: RA01, RA02'`) — the only thing rendered (`pdf/rams.blade.php:1635-1636`).

**The canonical defect traced through this code.** Step 4 "Display & Mount Installation" omitting RA01 Working at Height and RA02 Manual Handling is a *predictable output of `:1057-1063`*, not an AI lapse: the intersection rule requires the literal token to appear on both sides. The hazard named "Manual Handling" contains `manual handling`; a step reading "lift the display onto the wall mount" contains `mount` but not `manual handling`, so no keyword satisfies both sides and the hazard is dropped. Same for "Working at Height" vs a step that says "wall mount" but never "height". **The map is intersection-based; the defect needs implication-based inference.**

**Recommendation: a deterministic implication map, in config, and explicitly NOT `$keywordRiskMap`.**

| Option | Verdict |
|---|---|
| Reuse `$keywordRiskMap` | **Reject.** It is the exact code that produced the defect, and re-running it would be the "gate re-derives what it is checking" anti-pattern `enforceDisplayLiftGate()`'s docblock (`:1268-1276`) exists to warn against — "a violation check that merely re-derived `forSize()`'s own output and compared it would not be a true independent check." |
| Reuse `HazardIncludeWhenResolver`'s tiered maps | **Partial.** `TIER2_KEYWORD_SIGNALS` is scope-narrative vocabulary ("above standing reach", "ceiling void"), not step-action vocabulary. Useful as a *second* source, not sufficient alone. |
| AI inference | **Reject.** `CLAUDE.md`'s AI-usage constraint and the resolver's own docblock (`:29-32`) already rule a model out of deciding which hazards belong on a safety document. Also unbounded false-positive rate, non-reproducible tests, and a per-render cost on a render path. |
| **New deterministic implication map in `config/rams_tier1.php`** | **Recommend.** Shape: `step_phrase => required_hazard_signal`, e.g. `'wall mount'|'onto the bracket'|'lift the display' => manual_handling`; `'above 2m'|'ceiling'|'stepladder'|'podium'|'wall mount' => working_at_height`. The hazard side resolves through the **shared** vocabulary from Finding 6, so D-07's "one place" property holds. D-06 already requires config-resident vocabulary for GATE-01; putting GATE-14's map next to it is consistent and tunable post-deploy. |

**Two must-have safety properties for GATE-14:**
- **Only fire when the implied hazard actually exists in the register.** If a step implies Working at Height and no such hazard row exists, that is GATE-01/HAZ territory, not GATE-14 — GATE-14's spec is "*a method step failing to cite hazards its own text implies*", i.e. the hazard is present but uncited. Firing on an absent hazard would make GATE-14 demand the engineer add a hazard, which is scope invention.
- **Because `associated_risks` is app-generated, a GATE-14 failure is a defect report against `$keywordRiskMap`, not against the engineer.** The error message must say so, or an engineer will receive a blocking error they cannot act on by editing anything on the review screen. This is a real risk to arming: GATE-14 is the one gate whose violations the user **cannot fix from the UI**. The planner should consider whether GATE-14's violation tier should be **warn, not error** — the UI-SPEC warn channel already exists for it, and `REQUIREMENTS.md:75`'s wording ("every method step must cite the hazards its own text implies") does not mandate an error. **This is a genuine open decision the planner must surface, flagged here rather than assumed.**

### Finding 8 — Keep the gates on `RamsComplianceUpgradeService` (answer to open question 4)

Recommend **extend, do not extract**. Reasons, all grounded:

- All four shipped gates are private static methods on this class, dispatched from `upgrade()` (`:62-100`), and all four unit tests (`CdmEmergencyGateTest`, `Ffp2ConfinedSpaceGateTest`, `DisplayLiftGateTest`, `DisplayLiftGateEngineerRowsTest`) reach them via `ReflectionMethod::setAccessible(true)` — a documented, repeated pattern (`CdmEmergencyGateTest.php:50-57`). Independent testability is **already** satisfied without extraction.
- Independent flag-gating is a property of the **dispatch block**, not of class boundaries (`:62`, `:75`, `:97`). Extraction buys nothing here.
- `CLAUDE.md` constraint: "Keep the migration diff minimal — edit in place rather than refactoring." A 5-class extraction plus moving four existing gates for consistency is exactly the refactor that posture forbids.
- The class is 2,038 lines but is already partitioned by numbered `// ===== N. SECTION` banners; five more methods under a `// ===== 13. STRUCTURAL GATES` banner is the established idiom.
- Counter-consideration, stated honestly: the *helper* logic (trigger vocabulary flattening, implication matching) is genuinely reusable and Phase 31's GATE-05/GATE-10 will want it. **Recommend the split at the helper level, not the gate level**: gate bodies stay as private statics on `RamsComplianceUpgradeService`; shared matching helpers go into a new small class (precedent: `ControlTextRuleViolations`, `DisplayLiftPolicy`, `SiteEmergencyResolver` — every existing gate already delegates its *judgement* to a separate, directly-unit-testable class while keeping the *throw* on the service).

### Finding 9 — Corpus measurement: yes, run one; no backfill is needed (answer to open question 4 / CONTEXT open question 4)

**Is a `30-MEASUREMENT.md` pass worth it?** Yes, and more than in Phase 29. Phase 29's measurement answered one binary question (46/54 rows carry a placeholder). Phase 30 has **five** unmeasured false-positive rates, three of which are name-matching heuristics whose rate is unknowable from source:

| Gate | What to count on the live corpus (read-only) | Why it decides arming |
|---|---|---|
| GATE-01 | Documents containing a trigger phrase from the draft config vocabulary; of those, how many lack hazard-side and client-resp-side support | Sets the vocabulary's initial contents (D-06) |
| GATE-02 | Documents with ≥1 `reviewed_data.room_overviews[*].room`; of those, how many have an area name matching no phase title/step | Directly answers "are there production documents with areas but no method steps" |
| GATE-04 | Rows where `post_l*post_s > pre_l*pre_s` (errors) and rows where `post_severity < pre_severity` (warns) | If warns are corpus-wide the panel is noise; if errors are non-zero, arming blocks live regeneration |
| GATE-13 | Documents whose free text asserts absence of hot works | Confirms D-02's blocker empirically |
| GATE-14 | Phases whose implication map predicts a hazard not in `associated_risks` | The highest-variance number in the phase |

Command shape mirrors `29-MEASUREMENT.md` exactly: operator-run `php artisan tinker --execute="..."` on the VPS as `stcav`, `select` + in-memory filter, **zero writes**.

**Does any gate need a backfill migration before its flag can flip? No — and this is a real difference from Phase 29.** Phase 29's GATE-11 needed `2026_09_12_120000_backfill_cdm_contractor_note` because the *content* the gate policed was persisted in 46 rows. Phase 30's five gates police *relationships between sections computed at render time*: `crossReferenceMethodStatementRisks()`, `addPermitAndIsolation()`, `addCdmDutyHolders()` and the hazard score normaliser all recompute on every `upgrade()` run, so a regeneration is the "backfill". The only persisted artefact Phase 30 introduces is `generated_data['compliance_warnings']`, which UI-SPEC explicitly specifies as recomputed-and-overwritten every run, never appended (`30-UI-SPEC.md` data contract) — i.e. deliberately backfill-free. **Recommend the planner state "no backfill migration" as an explicit, reasoned conclusion in the plan, not as an omission.**

### Finding 10 — the warn channel's persistence has a gap UI-SPEC already accepted

UI-SPEC records that `RamsController::review()` (`:307-326`) never calls `upgrade()`, so a document last saved pre-Phase-30 shows no warnings until the next Save Review. Verified independently: the only writes of `generated_data` are `RamsController.php:623` (Save Review), `RamsBuilderService.php` (both build paths), and `RamsRefreshComplianceCommand.php:198`. **Operational consequence for the planner:** `php artisan rams:refresh-compliance` (site 6) is an existing, already-shipped way to populate `compliance_warnings` across the corpus without touching `review()` — worth naming in the plan as the post-deploy verification vehicle, since it also runs the gates and would surface any corpus-wide error before a flag is flipped. Note it **persists** (`:198 $rams->update(['generated_data' => $upgraded])`) and re-renders the DOCX, so it is not read-only; it has a `--dry-run` branch (`:190-197`) that returns before persisting — **use `--dry-run` for measurement.**

---

## Standard Stack

No new dependencies. Everything this phase needs already exists.

### Core
| Component | Location | Purpose | Why standard |
|---|---|---|---|
| `RamsComplianceUpgradeService` | `app/Services/Rams/RamsComplianceUpgradeService.php` | Single gate dispatch point (`:38-104`) | All four shipped gates hang off it |
| `RamsGenerationException` | `app/Exceptions/RamsGenerationException.php` | The only error-surfacing path | Caught at `RamsController.php:603`, `:701`, `:857` |
| `ControlTextRuleViolations` | `app/Services/Rams/ControlTextRuleViolations.php` (419 lines) | Ordered detector registry, `detect()` `:134` / `detectAll()` `:153`, negation-first discipline | The right home for GATE-13's assertion detector |
| `HazardIncludeWhenResolver` const maps | `app/Services/Rams/HazardIncludeWhenResolver.php:48-131` | Shared signal vocabulary (D-07) | Already contains `asbestos` (tier-3) — GATE-01's canonical trigger |
| `config/rams_tier1.php` (326 lines) | flags at `:74`, `:98`, `:134`; data tables at `:162`, `:173` | Three new flag blocks + two new vocabulary tables (D-04/D-06) | The established home for both |

### Alternatives Considered
| Instead of | Could use | Tradeoff |
|---|---|---|
| Private statics on the service | Five `*Gate` classes implementing a shared interface | Cleaner in isolation; contradicts `CLAUDE.md`'s minimal-diff constraint and forces moving four shipped gates for consistency. See Finding 8. |
| Deterministic implication map (GATE-14) | AI inference | Ruled out by `CLAUDE.md`, by `HazardIncludeWhenResolver`'s own docblock (`:29-32`), and by non-reproducible tests |
| Mirror into `$data['room_overviews']` | Mirror into a gate-private key | Gate-private key avoids waking the dormant `ensurePerRoomBullets()` AI path. **Recommended.** |

**Installation:** none. `composer.json` unchanged, `package.json` unchanged.

## Package Legitimacy Audit

**Not applicable — this phase installs zero external packages.** No new Composer or npm dependency is required or recommended; every component listed above is already in the repository (verified: `composer.json` scripts block read, no new library named anywhere in this research). Per `30-UI-SPEC.md` Registry Safety: "If the executor finds itself adding a package, the spec has been misread."

**slopcheck:** not run — no package names to verify. **Packages removed:** none. **Packages flagged:** none.

## Architecture Patterns

### System Architecture Diagram

```
  ┌─ Entry point 1 ── RamsBuilderService::buildFromForm()   (:942)  ─┐
  ├─ Entry point 2 ── RamsBuilderService::buildFromReview() (:297)  ─┤
  ├─ Entry point 3 ── RamsController::updateAndDownload()   (:603)  ─┤   ← the path engineers use
  ├─ Entry point 4 ── RamsController  DOCX rebuild          (:701)  ─┤
  ├─ Entry point 5 ── RamsController::downloadPdf()         (:857)  ─┤
  └─ Entry point 6 ── rams:refresh-compliance               (:185)  ─┘
                                  │
                    ┌─────────────▼──────────────┐
                    │  MIRROR STEP (Wave 0)      │   ← NEW. Without this three gates
                    │  reviewed_data ──▶ $data   │      see an empty array and pass
                    │  • client_responsibilities │      vacuously (Findings 2 & 3)
                    │    _expanded               │
                    │  • room_overviews (areas)  │
                    │  (precedent: material_     │
                    │   handling, Plan 27-07)    │
                    └─────────────┬──────────────┘
                                  │
              RamsComplianceUpgradeService::upgrade(array): array
                                  │
        ┌─────────────────────────┼──────────────────────────────┐
        │  existing steps :39-54  │  addPermitAndIsolation :931   │  ← emits the unconditional
        │                         │  (hot-works permit line :939) │     hot-works line (Finding 5)
        └─────────────────────────┼──────────────────────────────┘
                                  │
   ┌──────────────────────────────▼────────────────────────────────┐
   │  if (config rams_tier1.structural_gates_enabled)   [false]     │
   │      GATE-01 orphan controls ──┐                               │
   │      GATE-02 area coverage ────┤─ throw RamsGenerationException│
   │      GATE-04 residual>initial ─┘                               │
   │      GATE-04 s2<s1 ───────────────▶ $data['compliance_warnings'][]
   │  if (config rams_tier1.missing_risk_ref_gate_enabled) [false]  │
   │      GATE-14 ─────────────────────▶ throw  OR  warn (open Q)   │
   │  if (config rams_tier1.hot_works_gate_enabled)       [false]   │
   │      GATE-13 ─────────────────────▶ throw                      │
   └───────────────┬─────────────────────────────┬─────────────────┘
                   │ throw                       │ return array
                   ▼                             ▼
   catch @ RamsController :603/:701/:857     $rams->update(['generated_data' => ...])  :623
   back()->with('error', $msg)                          │
                   │                     ┌──────────────┴───────────────┐
                   ▼                     ▼                              ▼
   review.blade.php :396-398      review.blade.php warn panel     pdf/rams.blade.php
   .alert .alert-error            .alert .alert-warning           DocxBuilderService
                                  + hazard row ⚠ rail :1202       ✗ MUST NOT render
                                                                    compliance_warnings
```

### Pattern 1: Flag-gated dispatch (mandatory — copy exactly)
**What:** The gate method is called only inside a `config()` check, so `false` means the method is never invoked and `upgrade()` is byte-identical to pre-gate behaviour.
```php
// Source: app/Services/Rams/RamsComplianceUpgradeService.php:97-100
if (config('rams_tier1.cdm_ae_gate_enabled', false)) {
    $ramsData = self::enforceCdmGate($ramsData);
    $ramsData = self::enforceEmergencyGate($ramsData);
}
```
**Note the second argument.** `config('...', false)` — the *default* is repeated at the call site as well as in the config file. Phase 30's three new blocks must all pass `false` as the second argument (D-03), not `true`.

### Pattern 2: Config flag block with a recorded rationale
**Source:** `config/rams_tier1.php:99-134`. Every flag carries a comment block stating (a) exactly which methods it gates, (b) that `false` means byte-identical behaviour, (c) that it is new and never reuses another gate's flag, and (d) **why** the default is what it is. Phase 30's three blocks must each carry the D-03 rationale ("Phase 30's corpus has not been measured, so armed-by-default is not available") and the D-04 independence statement.

### Pattern 3: Throw on first violation, naming the item
```php
// Source: app/Services/Rams/RamsComplianceUpgradeService.php:1204-1213
throw new RamsGenerationException(sprintf(
    'CDM duty-holder field "%s" is still the raw "[To be confirmed]" placeholder (GATE-11/RULE-07). '
    . '... or set RAMS_CDM_AE_GATE=false to disable this check.',
    $field,
));
```
Every shipped message names the offending item **and** names the env flag that disables the check. UI-SPEC's copy contract (`{what is wrong} — {which item} — {what to do}`) is compatible; the planner should keep the "or set `RAMS_...=false`" clause, as all four precedents carry it.

### Pattern 4: Conservative-by-construction detection
**Source:** `ControlTextRuleViolations.php:101-125` (negations checked first, short-circuiting to clean), `:134-144` (`detect()` returns `null` for "cannot confidently classify"), and `RamsComplianceUpgradeService.php:1289-1295` (an unparseable team size **skips** the row). Every text-matching gate in this codebase prefers a miss to a false positive. GATE-02's name matching, GATE-13's assertion detection, and GATE-14's implication map must all inherit this.

### Anti-Patterns to Avoid
- **Re-deriving the thing you are checking.** `enforceDisplayLiftGate()`'s docblock (`:1268-1276`) is the canonical statement: "a violation check that merely re-derived `forSize()`'s own output and compared it would not be a true independent check." This is precisely the trap GATE-14 sits in relative to `$keywordRiskMap` (Finding 7).
- **A gate that cannot see its subject.** Documented twice in this repo already: GATE-09's `material_handling` bypass (Plan 27-07) and GATE-12's dormancy at initial-build time (`CdmEmergencyDualPathGateTest.php` docblock, lines 30-75). Findings 2 and 3 are the same failure class, pre-identified.
- **Second computation in the Blade.** UI-SPEC forbids it: panel and inline rail read one array.
- **Erroring on legitimately-empty data.** Zero areas, zero hazards, missing pre-scores — all normal (form-only/manual RAMS). ROADMAP criterion 4 makes "no false positives against legitimate output" an acceptance criterion.

## Don't Hand-Roll

| Problem | Don't build | Use instead | Why |
|---|---|---|---|
| Negation-aware text detection ("no hot works") | A bespoke regex in the gate | Add a detector to `ControlTextRuleViolations::DETECTORS` (`:84`) with a NEGATIONS/AFFIRMATIVE pair | The two-list, negation-first pattern is already proven and already has a test file |
| `RA##` reference resolution | Re-deriving from `$h['id']` | Row index + 1, per `RamsComplianceUpgradeService.php:1000-1013` | The 260817-r5e dangling-reference fix exists precisely because `id` is not `1..N` after normalisation drops a row |
| Hazard-signal keyword vocabulary | A new keyword list for GATE-01/14 | `HazardIncludeWhenResolver`'s `TIER2_*`/`TIER3_*` const maps (`:48-131`) | D-07 forbids a parallel vocabulary; `asbestos` is already there |
| Error-surfacing plumbing | Anything new | `RamsGenerationException` + the three existing catch sites | UI-SPEC Surface 3: "The only requirement on the executor here is the message string" |
| Warning persistence | An `acknowledged_at` column / ack table / migration | The existing `generated_data` write at `RamsController.php:623` | UI-SPEC: a dismissible warning is the "silently accept" failure `REQUIREMENTS.md:65` forbids |
| Reaching private gate methods in tests | Making them public | `ReflectionMethod::setAccessible(true)` | `CdmEmergencyGateTest.php:50-57` — the established pattern across all four gate test files |

**Key insight:** every "new" mechanism this phase appears to need already has a shipped precedent in this repo. The genuinely new things are exactly two: the `compliance_warnings` array key, and the two `reviewed_data → $data` mirrors.

## Common Pitfalls

### Pitfall 1: Writing gate bodies before the mirrors exist
**What goes wrong:** GATE-01 and GATE-02 pass on every document because the arrays they iterate are empty.
**Why:** `client_responsibilities_expanded` and any area list live in `reviewed_data`, never mirrored into the pipeline array (Findings 2, 3).
**Avoid:** Wave 0 = mirrors + a dual-path test proving the gate *sees* non-empty data on each entry point, before any gate logic ships.
**Warning sign:** a unit test that passes only because the fixture sets the key directly, with no feature test driving it through `RamsController::updateAndDownload()`.

### Pitfall 2: Copy-pasting `env('RAMS_..._GATE', true)`
**What goes wrong:** the gate arms itself on deploy, before the corpus is measured.
**Why:** GATE-06/07/09 legitimately default `true`; that is the visually dominant pattern in the file.
**Avoid:** all three new flags are `env(..., false)` in the config **and** `config('...', false)` at the dispatch site.
**Warning sign:** `config/rams_tier1.php:121` literally documents this as a named trap; the project's own memory records "a content gate defaulting ON is a deploy-order trap."

### Pitfall 3: GATE-04 erroring on `s2 < s1`
**What goes wrong:** blocks the committed golden fixture and HAZ-03's intended Working-at-Height scoring.
**Why:** the fixture's hazard 0 is `pre 3×4` / `post 1×3` (Finding 4).
**Avoid:** `s2 < s1` is **warn only**, always; only `post_score > pre_score` errors.
**Warning sign:** `--group snapshot` fails, or `WorkingAtHeightResidualScoreTest` fails.

### Pitfall 4: GATE-13 firing on the app's own unconditional permit line
**What goes wrong:** every document errors.
**Why:** `addPermitAndIsolation()` `:939` emits the hot-works permit line on every job, inside `upgrade()` itself (Finding 5).
**Avoid:** ship disarmed (D-02 already requires this) **and** design the permit half to ignore conditional wording; add a regression test asserting GATE-13 does **not** fire on a document whose only hot-works permit reference is `:939`'s literal.
**Warning sign:** the gate's own test fixture had to delete `permit_and_isolation` to get a clean pass.

### Pitfall 5: GATE-14 blaming the engineer for the app's derivation
**What goes wrong:** a blocking error the engineer cannot fix from any UI field.
**Why:** `associated_risks` is computed by `crossReferenceMethodStatementRisks()`, not typed by anyone (Finding 7).
**Avoid:** either make GATE-14 warn-tier, or write a message that names `$keywordRiskMap` as the thing to fix. Decide deliberately.
**Warning sign:** the error message tells the user to "add the reference to the step's risks line" — a line the app overwrites on the next run.

### Pitfall 6: Asymmetric gate behaviour between build and review paths
**What goes wrong:** a dual-path test passes on one path and is vacuous on the other.
**Why:** `injectDefaultsIntoRamsData()` runs *after* `upgrade()` on the two builder paths (Finding 1), so `coshh_baseline` is present on sites 3-6 and absent on sites 1-2.
**Avoid:** every gate test must state which of the six sites it exercises and why. `CdmEmergencyDualPathGateTest.php:30-75` is the model for documenting this honestly.

### Pitfall 7: Leaking `compliance_warnings` into the client document
**What goes wrong:** internal review flags appear in a RAMS issued to a client.
**Why:** it rides on `generated_data`, which both PDF blades and both DOCX builders receive.
**Avoid:** safe by construction today (blades read only named keys) — UI-SPEC requires this be an **assertion, not an assumption**.
**Warning sign:** anyone adds a generic `@foreach($data as ...)` dump.

## Code Examples

### Adding a flag-gated gate block to `upgrade()`
```php
// Source pattern: app/Services/Rams/RamsComplianceUpgradeService.php:97-100
// GATE-01/02/04 — structural self-consistency checks. Ships DISARMED
// (defaults false) per 30-CONTEXT.md D-03: Phase 30's corpus has not been
// measured clean. A NEW, INDEPENDENT flag per D-04 — never reuses
// RAMS_DISPLAY_LIFT_GATE, RAMS_PPE_CEILING_ELECTRICAL_GATE or
// RAMS_CDM_AE_GATE. When false none of the three methods is called and
// upgrade() proceeds byte-identical to pre-Phase-30 behaviour.
if (config('rams_tier1.structural_gates_enabled', false)) {
    $ramsData = self::enforceOrphanControlGate($ramsData);   // GATE-01, throws
    $ramsData = self::enforceAreaCoverageGate($ramsData);    // GATE-02, throws
    $ramsData = self::enforceResidualScoreGate($ramsData);   // GATE-04, throws + warns
}
```

### The mirror, at the Save-Review entry point
```php
// Source pattern: app/Http/Controllers/RamsController.php:594 (Plan 27-07's
// material_handling mirror), placed immediately before the upgrade() call at :603.
$generatedData['client_responsibilities_expanded'] = $reviewedData['client_responsibilities_expanded'] ?? [];
$generatedData['areas_for_gate'] = array_values(array_filter(array_map(
    static fn ($r) => is_array($r) ? trim((string) ($r['room'] ?? '')) : '',
    (array) ($reviewedData['room_overviews'] ?? []),
), static fn (string $s): bool => $s !== ''));
```
The equivalent mirror belongs at `RamsBuilderService.php:284` (runFromReview, beside the existing `material_handling` mirror) and `:939` (runPipeline).

### The warn channel write
```php
// Contract from 30-UI-SPEC.md: overwrite wholesale, never append.
$warnings = [];
foreach (array_values((array) ($data['hazards'] ?? [])) as $idx => $h) {
    // ... $preS / $postS derived with the same max(1,min(5,(int)...)) clamp
    //     as RamsDataBuilderService.php:444-448 and pdf/rams.blade.php:1327-1330
    if ($postS < $preS) {
        $warnings[] = [
            'gate'         => 'GATE-04',
            'hazard_index' => $idx,                       // matches $hIdx in review.blade.php:1196
            'hazard'       => (string) ($h['hazard'] ?? ''),
            'message'      => sprintf(
                'residual severity %d is lower than initial severity %d. Controls reduce likelihood, '
                . 'not severity — confirm this is intended.', $postS, $preS),
        ];
    }
}
$data['compliance_warnings'] = $warnings;   // wholesale overwrite, even when empty
```

### Gate unit test shape (reflection into a private static)
```php
// Source: tests/Unit/Services/Rams/CdmEmergencyGateTest.php:50-70
private function invokePrivateStatic(string $method, array $args = []): mixed
{
    $m = new \ReflectionMethod(RamsComplianceUpgradeService::class, $method);
    $m->setAccessible(true);
    return $m->invoke(null, ...$args);
}

public function test_gate_throws_on_violation(): void
{
    $this->expectException(RamsGenerationException::class);
    $this->expectExceptionMessageMatches('/GATE-01/s');
    $this->invokePrivateStatic('enforceOrphanControlGate', [[ /* fixture array */ ]]);
}
```

## State of the Art

| Old approach | Current approach | When changed | Impact on Phase 30 |
|---|---|---|---|
| `RA##` = `$h['id']` | `RA##` = row index + 1 | 260817-r5e (`RamsComplianceUpgradeService.php:1000-1006`) | GATE-04 and GATE-14 must both use the index rule |
| Model authors the "Associated Risks" line | App strips it and recomputes | 260817-r5e (`:1034-1046`) | Reframes GATE-14 entirely (Finding 7) |
| Gates default armed (`env(..., true)`) | Gates default disarmed pending a measured corpus | Phase 29 D-03 (`config/rams_tier1.php:115-133`) | D-03 applies this to all five |
| Gate checks only policy-derived data | Gate also checks engineer-typed data, and mirrors are added so it can see it | Plan 27-06 / 27-07 | Findings 2 and 3 are the same work for Phase 30 |
| Fixed 11-hazard baseline | Phase 26 tiered `include_when` library | Phase 26 | GATE-01/04 depend on this (ROADMAP "Depends on: Phase 26") |

**Not yet true (do not assume):** there is no warn channel, no `session('warning')` branch on the review screen, and `review()` does not run the gates. All three are stated in UI-SPEC and independently confirmed here.

## Project Constraints (from CLAUDE.md)

| Directive | Effect on this phase |
|---|---|
| "Keep the migration diff minimal — edit in place rather than refactoring" | Supports Finding 8's extend-don't-extract recommendation; rules out a five-class gate extraction |
| Data integrity / read-only posture on the replica | Not applicable — Phase 30 writes only `generated_data` on paths that already write it |
| AI usage: never for inventing scope | Rules out AI inference for GATE-14 (Finding 7) |
| `php` is not on the Bash tool's PATH; `php … \| tail` exits 0 while running nothing | **Every test claim in this document was read from source, not executed.** The planner must require tests be run from PowerShell/Herd, and must not accept a Bash-run green suite as evidence |

## Runtime State Inventory

Phase 30 is not a rename/refactor/migration phase — it adds gates and two mirrors. Inventory completed anyway because a new persisted `generated_data` key is introduced:

| Category | Items found | Action required |
|---|---|---|
| Stored data | `generated_data['compliance_warnings']` — **new key**, written on every `upgrade()` run, overwritten wholesale per UI-SPEC | None. No migration; recomputed every run (Finding 9) |
| Live service config | None — no external service carries any Phase 30 string | None. Verified: no queue/webhook/cron references Phase 30 identifiers |
| OS-registered state | None | None |
| Secrets/env vars | Three **new** env vars: `RAMS_STRUCTURAL_GATES`, `RAMS_MISSING_RISK_REF_GATE`, `RAMS_HOT_WORKS_GATE`. All default `false` in code, so an absent `.env` entry is the correct disarmed state | Add to `.env.example`; do **not** add to the live `.env` until each is deliberately flipped (D-03) |
| Build artifacts | None — PHP only, no compiled asset, no `npm` change | None. Laravel config cache: the live VPS runs `config:cache` (per project memory, MeetingStore entry — the same CWP-hosted pattern), so **flipping a flag requires `php artisan config:clear`/`config:cache`, not just an `.env` edit.** The planner must include this in the arming runbook |

## Environment Availability

| Dependency | Required by | Available | Version | Fallback |
|---|---|---|---|---|
| PHP 8.4 (Herd) | Running the test suite | ✓ (PowerShell only) | 8.4.25 per `29-MEASUREMENT.md` | — |
| `php` on the Bash tool PATH | Bash-run tests | ✗ | — | **Run tests from PowerShell.** A Bash `php … \| tail` exits 0 having run nothing |
| PHPUnit / `php artisan test` | All validation | ✓ | configured in `phpunit.xml` | — |
| SQLite in-memory | Feature tests | ✓ | `phpunit.xml` `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` | — |
| Live VPS (`stcav@rams.21stcav.com`, `stcav_rams`) | Corpus measurement (Finding 9) | Operator-only | — | Ship disarmed and defer arming — already the D-03 plan |
| New Composer/npm packages | — | n/a | — | None needed |

**Missing dependencies with no fallback:** none — nothing blocks execution.
**Missing dependencies with fallback:** `php` on the Bash PATH → use PowerShell.

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit via Laravel's `php artisan test` (`phpunit.xml` at repo root) |
| Config file | `phpunit.xml` — two suites (`tests/Unit`, `tests/Feature`); `<groups><exclude><group>snapshot</group>` (`:21-25`) |
| Quick run command | `php artisan test --filter=StructuralGate` (PowerShell, not Bash) |
| Full suite command | `php artisan test` (≈576s / 2,455+ tests per `29-MEASUREMENT.md`); snapshots separately via `vendor/bin/phpunit --group snapshot` |

### Phase Requirements → Test Map
| Req ID | Behavior | Test type | Automated command | File exists? |
|---|---|---|---|---|
| GATE-01 | Throws on a trigger phrase with no hazard row (canonical asbestos-orphan) | unit | `php artisan test --filter=StructuralGatesTest` | ❌ Wave 0 |
| GATE-01 | Throws when the hazard row exists but the client-responsibility entry does not (D-05 "either") | unit | `php artisan test --filter=StructuralGatesTest` | ❌ Wave 0 |
| GATE-01 | Sees a non-empty `client_responsibilities_expanded` on the Save-Review path (mirror proof) | feature | `php artisan test --filter=StructuralGatesDualPathTest` | ❌ Wave 0 |
| GATE-02 | Throws when an area name matches no phase title/step | unit | `php artisan test --filter=StructuralGatesTest` | ❌ Wave 0 |
| GATE-02 | **Passes vacuously** on zero areas (manual/form-only RAMS) | unit | `php artisan test --filter=StructuralGatesTest` | ❌ Wave 0 |
| GATE-02 | Sees a non-empty area list on all real entry points (mirror proof) | feature | `php artisan test --filter=StructuralGatesDualPathTest` | ❌ Wave 0 |
| GATE-04 | Throws when `post_l*post_s > pre_l*pre_s`; error names `RA##` by row index | unit | `php artisan test --filter=StructuralGatesTest` | ❌ Wave 0 |
| GATE-04 | **Warns, never throws**, on `post_severity < pre_severity` — using the committed Tilda hazard 0 (3×4 → 1×3) | unit | `php artisan test --filter=StructuralGatesTest` | ❌ Wave 0 |
| GATE-04 | Missing `pre_*` keys skip the row rather than tripping the `?? 1` default | unit | `php artisan test --filter=StructuralGatesTest` | ❌ Wave 0 |
| GATE-13 | Throws on assert-no-hot-works + unconditional permit requirement | unit | `php artisan test --filter=HotWorksGateTest` | ❌ Wave 0 |
| GATE-13 | Throws on assert-no-hot-works + solder/flux in COSHH | unit | `php artisan test --filter=HotWorksGateTest` | ❌ Wave 0 |
| GATE-13 | **Does NOT throw** when the only permit reference is `addPermitAndIsolation()`'s conditional `:939` line | unit | `php artisan test --filter=HotWorksGateTest` | ❌ Wave 0 |
| GATE-14 | Fires on the canonical Step-4 defect (cites RA11/12/13/21, omits RA01/RA02) | unit | `php artisan test --filter=MissingRiskRefGateTest` | ❌ Wave 0 |
| GATE-14 | Does **not** fire when the implied hazard is absent from the register | unit | `php artisan test --filter=MissingRiskRefGateTest` | ❌ Wave 0 |
| GATE-14 | Does not reuse `$keywordRiskMap` (source guard, `DisplayLiftPolicySourceGuardTest` precedent) | feature | `php artisan test --filter=MissingRiskRefGateSourceGuardTest` | ❌ Wave 0 |
| D-03 (all) | With all three flags false, `upgrade()` output is byte-identical to pre-Phase-30 | feature | `php artisan test --filter=StructuralGatesDisarmedTest` | ❌ Wave 0 |
| D-04 | Each flag is independent — disarming one leaves the others armed | unit | `php artisan test --filter=StructuralGatesTest` | ❌ Wave 0 |
| UI-SPEC | `compliance_warnings` overwritten wholesale (a stale entry clears) | unit | `php artisan test --filter=ComplianceWarningsChannelTest` | ❌ Wave 0 |
| UI-SPEC | Warnings panel renders nothing when the key is empty/absent | feature | `php artisan test --filter=ComplianceWarningsRenderTest` | ❌ Wave 0 |
| UI-SPEC | **No warning text in a regenerated PDF or DOCX** (assert, do not assume) | feature | `php artisan test --filter=ComplianceWarningsRenderTest` | ❌ Wave 0 |
| ROADMAP crit. 4 | A real regenerated project passes all five gates clean | feature/snapshot | `vendor/bin/phpunit --group snapshot` + full RAMS suite | ⚠️ partial — `tests/Fixtures/rams/tilda-21cq29531` exists; **no 21CQ30960 fixture exists** (grep: only string mentions in test docblocks) |

### Sampling Rate
- **Per task commit:** `php artisan test --filter=<the gate's own test class>` (PowerShell)
- **Per wave merge:** `php artisan test --filter=Rams` — the RAMS suite (349 tests / 1,604 assertions at Phase 29 closeout)
- **Phase gate:** full `php artisan test` green (expect the 1 known pre-existing `QueueRecoverCommandTest` failure, documented in `29-MEASUREMENT.md`) **plus** `vendor/bin/phpunit --group snapshot` green, before `/gsd:verify-work`

### Wave 0 Gaps
- [ ] `tests/Unit/Services/Rams/StructuralGatesTest.php` — GATE-01/02/04 (reflection pattern from `CdmEmergencyGateTest`)
- [ ] `tests/Unit/Services/Rams/HotWorksGateTest.php` — GATE-13 both halves + the `:939` non-firing regression
- [ ] `tests/Unit/Services/Rams/MissingRiskRefGateTest.php` — GATE-14
- [ ] `tests/Feature/Rams/StructuralGatesDualPathTest.php` — mirror reachability across the entry points (model: `CdmEmergencyDualPathGateTest`, `DisplayLiftSaveReviewGateTest`)
- [ ] `tests/Feature/Rams/StructuralGatesDisarmedTest.php` — byte-identical-when-false proof
- [ ] `tests/Feature/Rams/ComplianceWarningsChannelTest.php` + `ComplianceWarningsRenderTest.php` — warn channel + the must-not-leak assertion
- [ ] **A 21CQ30960 fixture** if ROADMAP criterion 4 is to be met automatically. None exists today. Either author one (`tests/Fixtures/rams/…`) or record criterion 4 as a human/UAT step against the live document — **the planner must choose explicitly.**
- [ ] Framework install: none required

## Security Domain

`security_enforcement` is not set in `.planning/config.json`; treating as enabled.

### Applicable ASVS Categories
| ASVS category | Applies | Standard control |
|---|---|---|
| V2 Authentication | no | Phase 30 adds no auth surface; the review screen is already behind existing auth |
| V3 Session management | no | UI-SPEC explicitly rejects a session-flash warn channel in favour of the data array |
| V4 Access control | no | No new route, no new controller action, no new policy |
| V5 Input validation | yes | GATE trigger vocabulary is config-authored, not user-authored. Warning `message`/`hazard` strings are rendered by Blade — **must use `{{ }}` escaping, never `{!! !!}`**. UI-SPEC's markup uses `{{ }}`; the executor must not "improve" it to raw output to render the `⚠` glyph (the glyph is a literal character, not an entity) |
| V6 Cryptography | no | None involved |
| V7 Error handling / logging | yes | Gate messages are shown to authenticated internal engineers and name document fields only — no stack traces, no DB errors. All four precedents follow this |

### Known Threat Patterns for Laravel + Blade
| Pattern | STRIDE | Standard mitigation |
|---|---|---|
| Stored XSS via a warning message echoed into the review screen | Tampering | Blade `{{ }}` auto-escaping; warning `message` strings are app-authored `sprintf` output, and the interpolated `hazard`/`area`/`step title` values come from user data — escaping is load-bearing |
| Internal review flags leaking into a client-facing safety document | Information disclosure | UI-SPEC's explicit boundary + a feature-test assertion that `compliance_warnings` never appears in a regenerated PDF/DOCX |
| Denial of service via a gate on a render path | DoS | All five gates are O(hazards × phases) over small in-memory arrays; no DB query, no AI call (Finding 6's no-DB invariant is also the mitigation here) |
| A disarmed gate mistaken for a passing gate | Repudiation | UI-SPEC's partially-armed copy rules: never claim "all checks passed", never enumerate gates that did not run |

## Assumptions Log

| # | Claim | Section | Risk if wrong |
|---|---|---|---|
| A1 | A deterministic implication map is the right GATE-14 inference mechanism | Finding 7 | If the corpus shows the map's false-positive rate is unacceptable, GATE-14 stays permanently disarmed — mitigated by its own kill switch (D-04) and by the warn-tier option |
| A2 | Mirroring areas under a gate-private key (not `$data['room_overviews']`) is safer | Finding 3 | If the planner prefers waking `ensurePerRoomBullets()`, that is a deliberate behaviour change needing its own snapshot review |
| A3 | No backfill migration is needed for any of the five gates | Finding 9 | If a gate turns out to police persisted content, arming would leave old rows failing — measurement (Finding 9) is the check that falsifies this before arming |
| A4 | The live VPS runs `config:cache`, so flag flips need a cache clear | Runtime State Inventory | If wrong, the extra `config:clear` is harmless; if right and omitted, a flag flip silently does nothing. Inferred from the project's CWP-hosted pattern, **not verified on this VPS** |
| A5 | GATE-14 may be better as warn-tier than error-tier | Finding 7 | Stated as an open decision for the planner, not a recommendation to implement |
| A6 | The 21CQ30960 defects (RA18 / §6.8 / COSHH; Step 4 omissions) are accurately described in CONTEXT.md | Findings 5, 7 | Neither the document nor a fixture of it exists in the repo; all reasoning traces the *class* of defect through app code, which reproduces it — but the specific RA numbers are unverified here |

## Open Questions (RESOLVED)

> Updated 2026-09-13, Plan 30-05. All four questions below were open at research time; each is
> now resolved by a decision and the plan that carries it. Retained in full (not deleted) so a
> future reader can see the reasoning, not just the outcome.

1. **Is GATE-14 an error or a warning? — RESOLVED: WARN, by Plan 30-08.**
   - Known: `associated_risks` is app-generated (`:991-1092`), so a violation is a defect in `$keywordRiskMap`, not in anything the engineer can edit.
   - Unclear: whether `REQUIREMENTS.md:75`'s "must cite" implies a blocking error.
   - **Resolution:** the user decided WARN tier, not error tier — a blocking error here would be unactionable from any review-screen field, since the engineer cannot edit `associated_risks` directly. Implemented in Plan 30-08 behind its own `RAMS_MISSING_RISK_REF_GATE` flag, using the existing `compliance_warnings` channel (Plan 30-01/30-04). `30-UI-SPEC.md` and `.planning/ROADMAP.md` (Phase 30 criterion 6) both corrected to match (Plan 30-05).

2. **How is ROADMAP criterion 4 (21CQ30960 clean) proven? — RESOLVED: two authored fixtures, by Plan 30-09.**
   - Known: no 21CQ30960 fixture exists in `tests/Fixtures/`; only docblock mentions.
   - Unclear: whether the real record is reachable from the dev environment (the Tilda fixture's `_notes` says the real Tilda record was *not*, and was hand-crafted instead).
   - **Resolution:** Plan 30-09 authors a defect-bearing 21CQ30960 fixture (reproducing the RA18/§6.8/COSHH contradiction and the Step 4 omission) and a clean counterpart, wires both into the snapshot test files, and proves criterion 4 automatically against the clean fixture rather than as a live-only UAT step. The human/UAT alternative this question raised was not taken.

3. **What are the five gates' actual false-positive rates on the live corpus? — RESOLVED procedurally: `30-MEASUREMENT.md`, by Plan 30-05 (this plan).**
   - Known: unmeasurable from this machine (no DB, no `php` on the Bash PATH).
   - **Resolution:** `30-MEASUREMENT.md` is the read-only, per-gate tinker-based measurement pass (following `29-MEASUREMENT.md`'s exact format) that produces these numbers, plus the arming runbook that sequences measurement → corpus regeneration → verification → flag flip. The pass itself has not been run yet (all five gates ship disarmed) — this resolves the *procedure*, not the numbers, which remain unknown until an operator runs it live.

4. **Does the Blade-side hot-works permit derivation (`pdf/rams.blade.php:407-409`) need closing? — RESOLVED: deliberately out of scope, carried forward.**
   - Known: a `'Hot Works Permit'` row can be derived in the Blade from a scope regex, invisible to `upgrade()`.
   - Unclear: whether that counts as "requiring a hot-works permit" for GATE-13's purposes.
   - **Resolution:** out of scope for Phase 30 — GATE-13 ships disarmed regardless, so this bypass cannot cause a live false-positive or false-negative yet. Recorded as a known limitation in `30-MEASUREMENT.md` (limitation (b)) and must be revisited before or during Phase 31's GATE-13 arming task, not before.

## Sources

### Primary (HIGH confidence — files read 2026-09-13)
- `app/Services/Rams/RamsComplianceUpgradeService.php` — `:19` (no-DB docblock), `:38-104` (dispatch), `:931-942` (permit rules), `:991-1092` (`crossReferenceMethodStatementRisks`), `:1000-1013` (RA index rule), `:1015-1027` (`$keywordRiskMap`), `:1100-1103` (`isAssociatedRisksLine`), `:1201-1218` (`enforceCdmGate`), `:1227-1242` (`enforceEmergencyGate`), `:1268-1295` (`enforceDisplayLiftGate` docblock + skip rule)
- `app/Services/RamsDataBuilderService.php` — `:60-113` (`assemble`), `:236-245` (`buildQuoteSummary` early return), `:260-275` (`room_summaries`), `:425-458` (hazard normalisation), `:500-505` (phase normalisation), `:524-531` (`client_responsibilities`, `rooms`), `:660-680` (`deriveClientResponsibilities`)
- `app/Services/RamsBuilderService.php` — `:155-200` (room_overviews handling), `:270-302` (mirrors + upgrade + defaults order), `:370-400` (`buildProjectContext` rooms), `:920-950` (runPipeline mirrors + upgrade)
- `app/Http/Controllers/RamsController.php` — `:495-502`, `:520-536` (`client_responsibilities_expanded`), `:584-596` (existing mirrors), `:603` (upgrade + catch), `:623` (`generated_data` persist), `:701`, `:857`
- `app/Services/Rams/HazardIncludeWhenResolver.php` — `:1-42` (docblock), `:48-131` (const maps), `:150-160` (`resolve`), `:166-204` (`evaluate`), `:205-234` (tier matching)
- `app/Services/Rams/ControlTextRuleViolations.php` — `:84-89` (`DETECTORS`), `:101-125` (negation/affirmative lists), `:134-165` (`detect`/`detectAll`)
- `app/Services/Rams/Tier1RamsDefaultsService.php` — `:70-84` (unconditional `coshh_baseline`)
- `app/Services/Rams/RamsDisplayPatchService.php` — `:390-405`
- `app/Console/Commands/RamsRefreshComplianceCommand.php` — `:170-200`
- `config/rams_tier1.php` — `:60-134` (three flag blocks + rationale comments)
- `resources/views/pdf/rams.blade.php` — `:290-301`, `:323-336`, `:407-409`, `:447`, `:1312-1369` (hazard table + RA label), `:1568-1600` (client responsibilities), `:1615-1640` (method of works)
- `tests/Unit/Services/Rams/CdmEmergencyGateTest.php` — `:1-70` (reflection + non-vacuity procedure)
- `tests/Feature/Rams/CdmEmergencyDualPathGateTest.php` — `:1-75` (dual-path/dormancy documentation)
- `tests/Feature/Rams/Snapshot/PdfSnapshotTest.php` — `:1-45` (golden-file mechanics)
- `tests/Fixtures/rams/tilda-21cq29531/record.json` — full parse: `generated_data` keys, hazard 0 scores, `quote` = `[]`
- `phpunit.xml`, `composer.json` scripts
- `.planning/REQUIREMENTS.md` `:55-80`, `:150-175`; `.planning/ROADMAP.md` §225-238
- `.planning/phases/30-structural-validation-gates/30-CONTEXT.md`, `30-UI-SPEC.md`
- `.planning/phases/29-cdm-duty-holder-emergency-arrangements/29-MEASUREMENT.md`
- `.planning/reference/21cav-rams-skill/PORTING-NOTES.md` §55-80; `SKILL.md` §40-52

### Secondary (MEDIUM)
- Project memory: "a content gate defaulting ON is a deploy-order trap" (Phase 28 retrospective) — corroborated by `config/rams_tier1.php:115-133`
- Project memory: CWP-hosted Laravel apps run `config:cache`, so `.env` greps lie — basis for A4

### Tertiary (LOW)
- None. No web search was performed; this phase has no external-library surface.

## Metadata

**Confidence breakdown:**
- Standard stack: **HIGH** — zero new dependencies; every component located and read
- Architecture / data reachability: **HIGH** — the three blocking gaps were traced with `grep` across `app/` and confirmed against the committed fixture
- GATE-14 inference recommendation: **MEDIUM** — the *problem* diagnosis is HIGH (traced through `:1015-1063`); the *solution* choice is design judgement
- Corpus behaviour: **UNKNOWN** — not measurable here; Finding 9 is the remedy
- Pitfalls: **HIGH** — each is grounded in a specific line or a committed fixture value

**Research date:** 2026-09-13
**Valid until:** 2026-10-13 (30 days — internal codebase, no external dependency drift; re-verify line numbers if `RamsComplianceUpgradeService.php` changes)
