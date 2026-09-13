# Phase 30: Structural Validation Gates — Pattern Map

**Mapped:** 2026-09-13
**Files analysed:** 17 surfaces (9 production, 8 test/fixture)
**Analogs found:** 15 / 17 exact-or-role match · 2 with **no precedent**

> Every line number below was read on 2026-09-13 in
> `C:\Users\sonny.tanda\Documents\1 - Claude Projects\Rams2\rams.21stcav.com`.
> Where research claimed something I could not confirm, the row says so.
>
> Two decisions taken since `30-RESEARCH.md` are reflected throughout:
> **(a) GATE-14 is WARN tier** — it writes `compliance_warnings`, it does not throw;
> **(b) ROADMAP criterion 4 is proven by an authored `21cq30960` fixture**, not a live UAT step.

---

## File Classification

### Production code

| New / modified surface | Role | Data flow | Closest analog | Match |
|---|---|---|---|---|
| `RamsComplianceUpgradeService::enforceOrphanControlGate()` (GATE-01) | service / private static gate | transform + throw | `enforceFfp2AndConfinedSpaceGate()` `:1446` | **exact** |
| `…::enforceAreaCoverageGate()` (GATE-02) | service / private static gate | transform + throw | `enforceDisplayLiftGate()` `:1320` | **exact** |
| `…::enforceResidualScoreGate()` (GATE-04) | service / private static gate | transform + throw **+ warn-write** | `enforceCdmGate()` `:1202` (throw half only) | **partial** — warn half has no analog |
| `…::enforceHotWorksGate()` (GATE-13) | service / private static gate | transform + throw | `enforceFfp2AndConfinedSpaceGate()` `:1446` | **exact** |
| `…::enforceMissingRiskRefGate()` (GATE-14) | service / private static gate | transform + **warn-write only** | `enforceCdmGate()` `:1202` for shape; **no analog for the non-throwing return** | **partial** |
| Three flag-gated dispatch blocks in `upgrade()` | service dispatch | branch | `upgrade()` `:75-79` and `:97-101` | **exact** |
| Three config flag blocks | config | static data | `config/rams_tier1.php:101-134` (`cdm_ae_gate_enabled`) | **exact** |
| GATE-01 trigger vocabulary + GATE-14 implication map (D-06) | config | static data | `config/rams_tier1.php` `coshh_products` `:145+` / `standards_references` `:250-303` | **role-match** |
| `generated_data['compliance_warnings']` write channel | service → persisted array | non-throwing advisory | **none — genuinely new** | ❌ |
| `client_responsibilities_expanded` + area-list mirrors | controller + service pre-`upgrade()` mirror | data plumbing | `RamsController.php:588-594` (`material_handling`, Plan 27-07) + `RamsBuilderService.php:285-294` / `:932-939` | **exact** |
| Warnings summary panel in `review.blade.php` | blade view | render array | `components/stale-banner.blade.php` (early-exit amber advisory) + `review.blade.php:393-398` (flash block) | **exact (two-source)** |
| `⚠` glyph + `.gate-flagged` left rail on hazard rows | blade view + inline CSS | render array | `.diff-modified` at `review.blade.php:421-425`, applied at `:1202` | **exact** |

### Tests and fixtures

| New file | Role | Closest analog | Match |
|---|---|---|---|
| `tests/Unit/Services/Rams/StructuralGatesTest.php` | unit, reflection into private statics | `tests/Unit/Services/Rams/CdmEmergencyGateTest.php` | **exact** |
| `tests/Unit/Services/Rams/HotWorksGateTest.php` | unit | same | **exact** |
| `tests/Unit/Services/Rams/MissingRiskRefGateTest.php` | unit (asserts a returned array, not a throw) | same, but **assertion style has no analog** | **partial** |
| `tests/Feature/Rams/StructuralGatesDualPathTest.php` | feature, entry-point reachability | `tests/Feature/Rams/CdmEmergencyDualPathGateTest.php` | **exact** |
| `tests/Feature/Rams/StructuralGatesDisarmedTest.php` | feature, flag-false inertness | `CdmEmergencyDualPathGateTest::test_gates_stay_silent_when_disarmed()` | **exact** |
| `tests/Feature/Rams/…SaveReviewGateTest`-style HTTP proof | feature, real route | `tests/Feature/Rams/DisplayLiftSaveReviewGateTest.php` | **exact** |
| `tests/Feature/Rams/MissingRiskRefGateSourceGuardTest.php` | feature, static source scan | `tests/Feature/Rams/DisplayLiftPolicySourceGuardTest.php` | **exact** |
| `tests/Feature/Rams/ComplianceWarningsRenderTest.php` | feature, blade render assertions | `CdmContractorNoteRenderRegressionTest.php` (PDF blades) + `SharedWorkspaceAccessTest.php:121` (the **only** test that GETs `rams.review`) | **partial** |
| `tests/Fixtures/rams/21cq30960/record.json` | golden fixture | `tests/Fixtures/rams/tilda-21cq29531/` | **exact** |

---

## Pattern Assignments

### 1. The five gate methods — `app/Services/Rams/RamsComplianceUpgradeService.php`

**Analog: `enforceCdmGate()` `:1202-1219` (shortest complete example).**

```php
    /**
     * GATE-11 (RULE-07) — independent re-check that the bare
     * `'[To be confirmed]'` placeholder never survives
     * {@see self::addCdmDutyHolders()}. ...
     */
    private static function enforceCdmGate(array $data): array
    {
        $cdm = (array) ($data['cdm_duty_holders'] ?? []);

        foreach (['principal_designer', 'principal_contractor'] as $field) {
            if (($cdm[$field] ?? null) === '[To be confirmed]') {
                throw new RamsGenerationException(sprintf(
                    'CDM duty-holder field "%s" is still the raw "[To be confirmed]" placeholder '
                    . '(GATE-11/RULE-07). ... or set RAMS_CDM_AE_GATE=false to disable this '
                    . 'check.',
                    $field,
                ));
            }
        }

        return $data;
    }
```

Shape to copy, verified across all four shipped gates:

| Element | Rule | Evidence |
|---|---|---|
| Signature | `private static function enforceXGate(array $data): array` | `:1202`, `:1228`, `:1320`, `:1446` |
| Input read | `(array) ($data['key'] ?? [])` — never assume presence | `:1204`, `:1240`, `:1322`, `:1448` |
| Return | `return $data;` unchanged on the clean path | `:1218`, `:1242` |
| Throw | `RamsGenerationException` via `sprintf`, on the **first** violation | `:1207`, `:1236` |
| Message tail | `'… or set RAMS_<FLAG>=false to disable this check.'` — all four carry it | `:1211`, `:1238`, `:1468` |
| Docblock | states the gate ID + RULE ID, what it re-checks, and **why it is independent** | `:1192-1200`, `:1264-1295` |
| Section banner | `// ===== N. TITLE` before a group of methods | `:1260-1262` |

**Independence docblock to copy verbatim in spirit — `:1268-1276`:**

```php
     * This method NEVER re-derives a team size and NEVER calls
     * {@see DisplayLiftPolicy::forSize()} — it only re-checks the numbers
     * `deriveMaterialHandling()` already stored, via the independent
     * {@see DisplayLiftPolicy::violatesPolicy()} re-check. This is the "gate
     * never trusts the same call path that produced the text" anti-pattern
     * guard from 27-RESEARCH.md: a violation check that merely re-derived
     * `forSize()`'s own output and compared it would not be a true
     * independent check.
```

This is the exact paragraph GATE-14 must adapt: it must not reuse
`crossReferenceMethodStatementRisks()`'s `$keywordRiskMap` (`:1015-1027`).

**Conservative-skip precedent (GATE-02 name matching, GATE-13 assertion detection) — `:1322-1329`:**

```php
        foreach ($items as $item) {
            $minPersons = $item['min_persons'] ?? null;
            if ($minPersons === null) {
                // Non-display item (mount/bracket/projector/rack/amp/speaker/
                // catch-all) — DisplayLiftPolicy's bands do not govern these,
                // per deriveMaterialHandling()'s own null convention.
                continue;
            }
```

**GATE-13's "no hot works" assertion detector** — delegate to the detector registry,
`app/Services/Rams/ControlTextRuleViolations.php:84-89`:

```php
    private const DETECTORS = [
        'kg_threshold'          => 'detectKgThreshold',
        'size_conditional_lift' => 'detectSizeConditionalLift',
        'ffp2'                  => 'detectFfp2',
        'confined_space'        => 'detectConfinedSpace',
    ];
```

with the two-list negation-first pair at `:101-109` (`CONFINED_SPACE_NEGATIONS`) and
`:118-125` (`CONFINED_SPACE_AFFIRMATIVE`). Copy that pair shape for a
`hot_works_assertion` key — negations checked first, short-circuit to clean.

**GATE-01 / GATE-14 hazard-side vocabulary** — reuse
`app/Services/Rams/HazardIncludeWhenResolver.php` const maps, confirmed present:
`TIER2_ACTIVITY_SIGNALS` `:47-52`, `TIER2_KEYWORD_SIGNALS` `:58+`, and the
`'asbestos' => ['asbestos','pre-2000','pre 2000','age unknown','built before 2000']`
entry at `:115-121` — GATE-01's canonical trigger is already in the vocabulary.
Do **not** call `resolve()` (it queries `HazardTemplate` models and would break the
class's "No AI. No database." docblock at `:19`).

---

### 2. Flag-gated dispatch — `upgrade()` `:36-104`

**Analog (read in full at `:97-101`):**

```php
        if (config('rams_tier1.cdm_ae_gate_enabled', false)) {
            $ramsData = self::enforceCdmGate($ramsData);
            $ramsData = self::enforceEmergencyGate($ramsData);
        }
```

Preceded by the 15-line comment block at `:82-96` that states: which methods are gated,
which env var flips it, that it is a **new independent** flag, that it ships **disarmed**,
and that `false` means `upgrade()` is byte-identical.

Two verified details the planner must not lose:
- The default is repeated at the call site: `config('…', false)` — `:97`. GATE-06/07/09 use
  `true` (`:62`, `:75`); all three Phase 30 blocks use `false` (D-03).
- Placement matters. `crossReferenceMethodStatementRisks()` runs at `:80` and
  `addPermitAndIsolation()` at `:53`. GATE-14 reads `associated_risks` and GATE-13 reads
  `permit_and_isolation`, so **all three new blocks belong after `:80`** — i.e. adjacent to
  the existing `:97` block — and before `cleanTextArtifacts()` at `:102`.

---

### 3. Config flag blocks — `config/rams_tier1.php`

**Analog: `:101-134`, the `cdm_ae_gate_enabled` block** — the only one of the three whose
default is `false`, and the only one carrying the armed-vs-disarmed rationale. Read in full;
its four load-bearing paragraphs are:

1. *Gates ONLY …* — names the exact methods (`:105-112`).
2. *When false, neither method is called — `upgrade()` proceeds byte-identical … no redeploy required* (`:110-112`).
3. *A NEW, INDEPENDENT flag per D-03 — deliberately never reuses `RAMS_DISPLAY_LIFT_GATE` (GATE-09) or `RAMS_PPE_CEILING_ELECTRICAL_GATE` (GATE-06/07)* (`:114-117`).
4. *UNLIKE the two precedents above, this flag's default is FALSE …* with the measured-corpus reasoning (`:119-132`), ending in the explicit deploy order.

Terminal line shape: `'cdm_ae_gate_enabled' => env('RAMS_CDM_AE_GATE', false),` (`:134`).

Phase 30 writes three such blocks: `structural_gates_enabled` / `RAMS_STRUCTURAL_GATES`,
`missing_risk_ref_gate_enabled` / `RAMS_MISSING_RISK_REF_GATE`,
`hot_works_gate_enabled` / `RAMS_HOT_WORKS_GATE`.

**`.env.example` — no precedent.** Verified: `grep -n "RAMS_" .env.example` returns exactly
one line, `:62 RAMS_NOTIFICATION_BCC=`. **None of the three shipped gate flags is in
`.env.example`.** So `30-RESEARCH.md`'s "Add to `.env.example`" instruction has no analog to
copy — it is a new convention. Either follow the existing (absent) convention, or introduce
the entries deliberately with a comment; do not present it as "matching the existing pattern".

---

### 4. Config data structures — trigger vocabulary (D-06) and GATE-14 implication map

**Analog: `config/rams_tier1.php` `standards_references` (read `:250-303`).** Shape is a
flat `list<array<string,string>>` of uniform-key rows with a comment banner above it:

```php
        [
            'ref'        => 'HSG 47',
            'title'      => 'Avoiding danger from underground services',
            'applies_to' => 'Any external drilling, floor-box installation or below-ground penetration on site.',
        ],
```

`coshh_products` (`:145+`) is the same idiom with a nested `list<string>` under `controls`.
`av_prompt_bullets` (`:317-324`) is the flat `list<string>` variant.

So the established config vocabulary idiom is: **banner comment → uniform-key rows → nested
string lists**, and it supports exactly the shape GATE-01 needs:

```php
'structural_gate_triggers' => [
    ['phrase' => 'asbestos register', 'signal' => 'asbestos', 'label' => 'asbestos survey/register'],
    ...
],
```

No analog exists for a *phrase → signal* map specifically; the row-of-uniform-keys shape is
the closest, and it is a clean fit. The `signal` values must be keys that already exist in
`HazardIncludeWhenResolver`'s maps (D-07).

---

### 5. `generated_data['compliance_warnings']` — **no precedent, genuinely new**

I searched for a non-throwing advisory surface and found none, confirming both
`30-RESEARCH.md` and `30-UI-SPEC.md`. Specifically:
- All four gates return `array` and communicate failure only by `throw` (`:1207`, `:1236`, `:1468`).
- `review.blade.php:393-398` renders `session('success')` and `session('error')` only — there
  is no `session('warning')` branch.
- The only `generated_data` write on the engineer path is `RamsController.php:625-631`'s
  `$rams->update([... 'generated_data' => $generatedData, ...])` inside a `DB::transaction`.

**Nearest structural cousins** (copy the *shape*, not the semantics):
- `RamsComplianceUpgradeService` already writes derived keys onto `$data` and returns them —
  e.g. `resolveSiteEmergency()` writing `site_emergency_resolved` (`upgrade()` `:83-86`,
  method docblock `:1246-1258`). That is the precedent for "a gate-adjacent method enriches
  the array and everything downstream reads one key".
- `site_emergency_resolved` is also the precedent for **unconditional** computation sitting
  next to a flag-gated throw — relevant because `compliance_warnings` must be overwritten
  wholesale on **every** run (UI-SPEC), including when it is empty.

**Planner note:** if `compliance_warnings` is only written inside the flag-gated blocks, a
flag flip from true→false leaves a stale persisted array on every document. The
`resolveSiteEmergency()` precedent (write unconditionally, gate only the throw) is the shape
that avoids this. Decide explicitly.

---

### 6. The data-reachability mirrors

**Analog A — controller, `app/Http/Controllers/RamsController.php:586-594`** (Plan 27-07):

```php
        // Also mirror material_handling into generated_data (Plan 27-07,
        // GATE-09 coverage-gap closure) so enforceDisplayLiftGate()'s
        // engineer-row loop can see the team size the engineer just typed
        // on THIS save request. Without this mirror, the gate's engineer-row
        // check always sees an empty array on the Save Review path — the
        // exact path an engineer uses ...
        $generatedData['material_handling'] = $reviewedData['material_handling'] ?? [];
```

immediately followed by the `upgrade()` call and its catch (`:598-606`):

```php
        try {
            $generatedData = \App\Services\Rams\RamsComplianceUpgradeService::upgrade($generatedData);
        } catch (\App\Exceptions\RamsGenerationException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
```

The `site_emergency` mirror three lines above (`:578-584`) is the *older* precedent and
carries the "so the PDF template's primary lookup finds the values" rationale.

**Analog B — builder, `app/Services/RamsBuilderService.php:285-294`** (`runFromReview`):

```php
        // Plan 27-06 (GATE-09 coverage-gap closure) — mirror reviewed_data
        // ['material_handling'] onto the pipeline array. ... Mirrored here,
        // immediately before upgrade(), exactly like scope_items above.
        $data['material_handling'] = (array) ($reviewedData['material_handling'] ?? $data['material_handling'] ?? []);

        // ── Tier 1 compliance upgrade (PPE matrix, CDM, risk colour key, etc.) ─
        $data = RamsComplianceUpgradeService::upgrade($data);
```

and the symmetric one in `runPipeline()` at `:932-939`, which mirrors from `$formData`
instead and says so explicitly ("matching `runFromReview()`'s equivalent mirror above …
keeps both real generation entry points symmetric").

**Source shape for the GATE-01 mirror — `RamsController.php:520-532`** (read in full):
`client_responsibilities_expanded` is a dict of four fixed buckets each `['required'=>bool,
'notes'=>string]` plus `'additional' => list<['item'=>..., 'notes'=>...]>`. Confirms
`30-RESEARCH.md` Finding 2 exactly: the four fixed buckets carry **no free text** beyond
`notes`, so only `notes` + `additional[*].item|notes` are matchable.

**Three catch sites confirmed** (research said `:603/:701/:857`; actual lines after reading):
`RamsController.php:602` (Save Review, `back()->withInput()->with('error', …)`),
`:701` (DOCX rebuild — note this one catches `\Throwable`, logs, and sets `$rebuildError`,
it does **not** redirect), `:856-861` (`downloadPdf`, `back()->with('error', …)`).
The DOCX-rebuild site behaves differently from the other two — the planner should not assume
all three surface a gate message the same way.

---

### 7. Blade — warnings summary panel

**Analog A — insertion point and markup idiom, `resources/views/rams/review.blade.php:393-398`:**

```blade
    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-error">{{ session('error') }}</div>
    @endif
```

The hidden regen form follows at `:400-403`. UI-SPEC places the panel between these two.

**Analog B — the advisory-banner idiom, `resources/views/components/stale-banner.blade.php`
(read whole file):** amber `role="status"`, `⚠` glyph inside a `<strong style="font-weight:600;">`,
`var(--warning-light)` background, `#92400E` text, `#78350F` sub-copy, and — the key pattern —
the **early exit** in the `@php` block so callers need no outer `@if`:

```php
    if (! method_exists($doc, 'isStale') || ! $doc->isStale()) {
        return;
    }
```

That is the exact mechanism for UI-SPEC's "when `compliance_warnings` is empty or absent,
the panel renders NOTHING".

**Note a real divergence:** `stale-banner` uses `var(--warning-light)` inline styles, while
UI-SPEC mandates the `.alert .alert-warning` class. Both exist; UI-SPEC's choice is the
class. Copy the *early-exit structure* and the *glyph/copy tone* from `stale-banner`, and the
*class* from the `.alert-error` line at `:396`.

---

### 8. Blade — inline hazard-row marker

**Analog — `resources/views/rams/review.blade.php:421-425`** (inside the page's own
`@push`-adjacent `<style>` block, opened at `:420`):

```css
        .diff-modified {
            border-left: 3px solid var(--warning) !important;
            background: color-mix(in oklab, var(--warning) 6%, var(--surface)) !important;
        }
        .diff-added {
            border-left: 3px solid var(--success) !important;
            ...
        }
```

**Application site — `:1196-1204`** (read in full; confirms UI-SPEC's `$hIdx` matching key):

```blade
                    @foreach ($hazards as $hIdx => $h)
                        @php
                            $pre  = (int)($h['pre_likelihood']  ?? 1) * (int)($h['pre_severity']  ?? 1);
                            $post = (int)($h['post_likelihood'] ?? 1) * (int)($h['post_severity'] ?? 1);
                            $hazardRowChanged = !empty(RamsDiffService::fieldChangesUnder($diff, "hazards.{$hIdx}"));
                        @endphp
                        <tr class="{{ $hazardRowChanged ? 'diff-modified' : '' }}">
                            <td>{{ $h['hazard'] ?? '—' }}</td>
```

`.gate-flagged` is a sibling rule in the same `<style>` block; the `⚠` goes immediately
before `{{ $h['hazard'] ?? '—' }}` in the `<td>` at `:1203`. Note the `@php` block at
`:1197-1201` **already computes `$pre`/`$post` with the same `?? 1` clamp GATE-04 uses** —
do not add a second computation here (UI-SPEC forbids it); read the warning array instead.

---

### 9. Unit gate tests

**Analog: `tests/Unit/Services/Rams/CdmEmergencyGateTest.php` (read `:1-95`).**

```php
    private function invokePrivateStatic(string $method, array $args = []): mixed
    {
        $m = new \ReflectionMethod(RamsComplianceUpgradeService::class, $method);
        $m->setAccessible(true);

        return $m->invoke(null, ...$args);
    }

    public function test_enforceCdmGate_throws_on_placeholder_principal_designer(): void
    {
        $this->expectException(RamsGenerationException::class);
        $this->expectExceptionMessageMatches('/principal_designer.*GATE-11\/RULE-07/s');

        $this->invokePrivateStatic('enforceCdmGate', [[ /* fixture array */ ]]);
    }
```

Three further conventions worth copying, all present in that file:
- Namespace `Tests\Unit\Services\Rams`, extends `Tests\TestCase`, **no `RefreshDatabase`**.
- The clean-path test builds its input by invoking the *producing* method first, then the
  gate, and asserts identity: `$this->assertSame($data, $result);` (`:86-92`). That is the
  right shape for "GATE-13 does NOT throw on `addPermitAndIsolation()`'s own `:939` line" —
  build `$data` via `invokePrivateStatic('addPermitAndIsolation', [[]])`, then run the gate.
- **The non-vacuity proof docblock** (`:18-45`) — a numbered 5-step "break the fix, watch the
  test fail, restore, confirm `git diff` empty" procedure, named per test. This is a house
  convention for gate tests and the planner should require it for each new gate test file.

**GATE-14's assertion style has no analog.** Every existing gate test asserts a throw or
identity. A warn-tier gate needs `assertSame(['GATE-14', ...], $result['compliance_warnings'][0])`
— new territory. The nearest shape in the repo is
`RamsComplianceUpgradeServiceDisplayLiftTest` / `MethodStatementAssociatedRisksTest`
(assert on the returned array), which the planner should read before writing it.

---

### 10. Feature dual-path / entry-point tests

**Analog A — `tests/Feature/Rams/CdmEmergencyDualPathGateTest.php` (read `:1-110`).** Its
value is almost entirely the docblock: it documents, per gate, *which of the six `upgrade()`
call sites the gate is live on and which it is dormant on*, and it records a **correction**
made at phase closeout when an earlier draft got that wrong (`:63-79`). Phase 30 has the same
asymmetry (research Finding 1: `coshh_baseline` absent on sites 1-2, present on 3-6), so this
docblock discipline is mandatory, not decorative.

Its three test methods are the template:
`test_gate_throws_via_run_pipeline()`, `test_gate_throws_via_run_from_review()`,
`test_gates_stay_silent_when_disarmed()` — the third is the direct analog for
`StructuralGatesDisarmedTest`.

Uses `RefreshDatabase` + `Http::fake()` (the AI call in the pipeline).

**Analog B — `tests/Feature/Rams/DisplayLiftSaveReviewGateTest.php` (read `:1-80`).** The
HTTP-level proof: drives `POST /rams/{rams}/update-and-download` with a realistic payload
"so these tests fail if routing, middleware, or validation regress the fix" (`:23-25`).
Carries two reusable helpers, `makeRams(User $user)` (`:36-58`) and
`payloadWithHandling()` (`:61-76`). This is the analog for proving the **mirrors** work — the
gate must see `client_responsibilities_expanded` / the area list posted on *this* request.

**Fake-AI shape** for any test that drives `buildFromReview()`:
`tests/Feature/Rams/PpeFfp2RenderRegressionTest.php:34-41`.

---

### 11. Static source-guard test (GATE-14 must not reuse `$keywordRiskMap`)

**Analog: `tests/Feature/Rams/DisplayLiftPolicySourceGuardTest.php` (read `:1-80`).**

Structure to copy exactly:
- Docblock states the guard's purpose, names the **grep the allow-list was derived from**
  (`grep -rl "DisplayLiftPolicy::" app --include=*.php`), and says the list was *re-derived
  live at execution time, not hand-copied* (`:16-19`).
- `private const ALLOWED_FILES = [...]` with a per-entry comment explaining why each file is
  legitimately allowed (`:47-58`).
- `private const MARKER = 'DisplayLiftPolicy::';` (`:60`).
- Scans `base_path('app')` only; `tests/` excluded with a stated reason; the class's own
  definition file excluded explicitly "for clarity, not because it would otherwise trip"
  (`:62-78`).

For GATE-14 the marker is `keywordRiskMap` (a `$`-variable local to
`crossReferenceMethodStatementRisks()` at `:1015-1027`), so the guard is narrower: assert the
symbol appears in exactly one file and that the GATE-14 method does not reference it. The
planner should pick the marker deliberately — a local variable is a weaker grep target than a
`Class::` marker, and that difference is not covered by the analog.

---

### 12. The 21CQ30960 golden fixture — **decision: author it**

**Analog: `tests/Fixtures/rams/tilda-21cq29531/` — directory listing verified:**

```
record.json                 16,204 bytes   ← the only hand-authored file
expected-html-v1.html       67,300         ← generated golden
expected-html-v2.html       67,963         ← generated golden
expected-docx-v1.xml.norm  177,444         ← generated golden
expected-docx-v2.xml.norm  177,544         ← generated golden
```

**`record.json` top-level shape (parsed, verified):**

```json
{
  "_notes": "…",
  "project": { "name": …, "client_name": …, "site_address": …, "ref": … },
  "rams":    { "project_ref": …, "project_name": …, "client_name": …, "site_address": …,
               "status": …, "generated_data": {…}, "reviewed_data": {…}, "form_data": {…} }
}
```

**`_notes` is load-bearing and must be written for the new fixture.** Tilda's reads:

> "Fixture 6 of phase 260726-rf3 — Tilda 21CQ29531 canonical reference document. Hand-crafted
> by Plan 03 executor 2026-07-26 because the real record (project 88, RAMS id 92) was not
> accessible from the dev environment (empty local DB). Modelled on the composite of: the four
> Plan 02 composer fixtures … If a real Tilda RAMS record becomes available later, this fixture
> should be regenerated from it and the snapshot golden files re-captured via
> `php artisan rams:regenerate-snapshots tilda-21cq29531`."

The 21CQ30960 `_notes` must state the same three things: why it is hand-crafted, what it was
modelled on, and the regeneration command.

**How the fixture is consumed — `tests/Feature/Rams/Snapshot/PdfSnapshotTest.php` (read `:1-120`):**

- `#[Group('snapshot')]` on the class (`:54`) — excluded from the default run by
  `phpunit.xml`; run via `vendor/bin/phpunit --group snapshot`.
- `private const FIXTURE_DIR = __DIR__ . '/../../../Fixtures/rams';` (`:58`).
- `private const FIXED_DATE = '2026-07-25 10:30:00';` pinned via `Carbon::setTestNow()` in
  `setUp()`/`tearDown()` (`:64-77`).
- `renderBothBlades(string $fixture)` (`:85-120`): loads the fixture, creates `User` +
  `Project` + `RamsDocument` from `$fx['rams'][...]`, force-sets `created_at` to the pinned
  clock, runs `RamsDisplayPatchService::patch()`, then `RamsDocumentComposer::compose()`.
- **First run captures the golden and skips the assertion**; later runs assert byte-equality
  (`:31-35`).
- `DocxSnapshotTest.php` consumes the same fixture (`:102`, `:104`, `:111`, `:113`, `:139`).

**Critical, verified: there is no data provider.** The fixture name is a **hardcoded string
literal** in both snapshot test files (`PdfSnapshotTest.php:206/207/214/215/235`,
`DocxSnapshotTest.php:102/104/111/113/139`). Adding `21cq30960` is therefore **not** a
drop-in-a-folder operation — the planner must add explicit test methods (or refactor to a
provider) in both files. The research and VALIDATION docs do not mention this.

**Golden capture — `app/Console/Commands/RamsRegenerateSnapshotsCommand.php` (read `:56-100`):**

```
php artisan rams:regenerate-snapshots 21cq30960 --force
```

It scans `base_path('tests/Fixtures/rams')` for directories, so a new folder is auto-discovered
by the *command* (unlike the tests). It writes all four goldens per fixture (`:95`), runs in an
always-rolled-back nested transaction, and prompts unless `--force`.

**Caution for a gate-defect fixture:** ROADMAP criterion 4 wants 21CQ30960 to regenerate
**clean**. But `30-CONTEXT.md` "Specifics" describes it as the document *containing* the
GATE-13 and GATE-14 defects. Those are two different fixtures, or one fixture used two ways.
The planner must resolve this explicitly — the Tilda precedent gives no guidance because
Tilda is a clean reference document, not a defect reproduction.

---

## Shared Patterns

### S1 — Fail-closed, flag-gated, byte-identical-when-false
**Source:** `RamsComplianceUpgradeService::upgrade()` `:62`, `:75`, `:97` + the config
rationale at `config/rams_tier1.php:110-112`.
**Apply to:** all five gates, all three dispatch blocks, all three config blocks.
The invariant to preserve verbatim: *"When the flag is false, X is never called — `upgrade()`
proceeds byte-identical to pre-gate behaviour, no redeploy required."*

### S2 — Throw on the first violation, naming the item and the kill switch
**Source:** `:1207-1213`, `:1236-1240`, `:1466-1472`.
**Apply to:** GATE-01, GATE-02, GATE-04-error, GATE-13. **Not** GATE-14 (warn tier).

### S3 — Conservative by construction: prefer a miss to a false positive
**Source:** `ControlTextRuleViolations` negations-first (`:90-109`) and `detect()`'s
"returns null … including *cannot confidently classify*" contract (`:127-134`);
`enforceDisplayLiftGate()`'s `continue` on a null (`:1323-1329`).
**Apply to:** GATE-02 name matching, GATE-13 assertion detection, GATE-14 implication map,
GATE-04's missing-`pre_*` skip.

### S4 — Mirror the data immediately before `upgrade()`, at every entry point, with a comment naming the coverage gap it closes
**Source:** `RamsController.php:586-594`, `RamsBuilderService.php:285-294` and `:932-939`.
**Apply to:** `client_responsibilities_expanded` and the area list.

### S5 — Gate delegates its *judgement* to a separate, directly-unit-testable class
**Source:** every shipped gate does this — `DisplayLiftPolicy::violatesPolicy()`,
`ControlTextRuleViolations::detect()`, `SiteEmergencyResolver::classify()` (`:1230-1233`).
The gate method itself owns only the loop and the `throw`.
**Apply to:** the shared trigger-flattening and implication-matching helpers
(research Finding 8's "split at the helper level, not the gate level").

### S6 — Blade escapes everything with `{{ }}`
**Source:** `review.blade.php:394`, `:397`, `:1203`. No `{!! !!}` anywhere in the flash or
hazard-table region. The `⚠` is a literal character, not an entity — no raw output needed.

### S7 — Non-vacuity proof recorded in the test docblock
**Source:** `CdmEmergencyGateTest.php:18-45`.
**Apply to:** every new gate test file.

---

## No Analog Found

| Surface | Role | Why there is no precedent |
|---|---|---|
| `generated_data['compliance_warnings']` write channel | non-throwing advisory | Verified: all four gates communicate only by `throw`; `review.blade.php:393-398` has no `session('warning')` branch; nothing in `app/Services/Rams/` returns advisories. `resolveSiteEmergency()`'s "unconditional enrichment + gated throw" (`upgrade():83-86`) is the closest *structural* cousin, not a semantic one. |
| A warn-tier gate test asserting on a returned array | unit test | Every existing gate test asserts `expectException` or `assertSame($data, $result)`. GATE-14's and GATE-04-warn's assertions are new shapes. |
| `.env.example` entries for gate flags | config | Verified absent: `.env.example` contains one `RAMS_` line (`:62`, `RAMS_NOTIFICATION_BCC`). No shipped gate flag is listed there. Adding them is a new convention, not a copied one. |
| A feature test that GETs `rams.review` and asserts rendered HTML | feature test | Only one test hits that route at all — `tests/Feature/Authorization/SharedWorkspaceAccessTest.php:121` — and it asserts authorization, not content. `ComplianceWarningsRenderTest` has no content-assertion precedent on this screen; the nearest is `CdmContractorNoteRenderRegressionTest`, which renders the **PDF** blades directly rather than going through the HTTP review route. |

---

## Corrections to Upstream Documents

| Claim | Source | Verified reality |
|---|---|---|
| Gate methods at `:1201` / `:1227` / `:1319` / `:1446` | `30-CONTEXT.md` canonical refs | Declarations are at `:1202`, `:1228`, `:1320`, `:1446`. Docblocks start ~10-38 lines earlier. Off-by-one on three of four; treat all line refs as ±2. |
| `upgrade()` dispatch at `:62-97` | `30-CONTEXT.md`, `30-RESEARCH.md` | Method opens `:36`; the three flag blocks are at `:62-64`, `:75-77`, `:97-101`; body ends `:104`. |
| Catch sites at `:597`/`:852` (CONTEXT) and `:603`/`:701`/`:857` (RESEARCH) | both | Actual: `:598-603` (Save Review, redirects), `:701` (DOCX rebuild — catches `\Throwable`, **logs and continues**, does not redirect), `:856-861` (`downloadPdf`, redirects). The three sites do **not** behave identically. |
| Snapshot fixtures are enumerated generically | implied by VALIDATION "author one under `tests/Fixtures/rams/`" | Fixture names are hardcoded string literals in `PdfSnapshotTest.php` and `DocxSnapshotTest.php`. A new fixture folder is picked up by `rams:regenerate-snapshots` but **not** by the tests without new test methods. |
| `.env.example` should gain the three flags "as established" | `30-RESEARCH.md` Runtime State Inventory | No gate flag has ever been added to `.env.example`. |

---

## Metadata

**Analog search scope:** `app/Services/Rams/`, `app/Services/`, `app/Http/Controllers/`,
`app/Console/Commands/`, `config/`, `resources/views/rams/`, `resources/views/components/`,
`tests/Unit/Services/Rams/`, `tests/Feature/Rams/`, `tests/Feature/Rams/Snapshot/`,
`tests/Fixtures/rams/`, `routes/web.php`, `.env.example`

**Files read in full or in targeted ranges (2026-09-13):**
`RamsComplianceUpgradeService.php` (`:30-110`, `:1190-1250`, `:1260-1330`, `:1430-1475`) ·
`RamsController.php` (`:515-540`, `:575-630`, `:690-712`, `:845-865`) ·
`RamsBuilderService.php` (`:275-305`, `:930-950`) ·
`ControlTextRuleViolations.php` (`:75-135`) ·
`HazardIncludeWhenResolver.php` (`:44-70`, `:108-132`) ·
`config/rams_tier1.php` (`:60-200`, `:250-330`) ·
`review.blade.php` (`:386-430`, `:1184-1220`) ·
`components/stale-banner.blade.php` (whole) ·
`CdmEmergencyGateTest.php` (`:1-95`) · `CdmEmergencyDualPathGateTest.php` (`:1-110`) ·
`DisplayLiftSaveReviewGateTest.php` (`:1-80`) · `DisplayLiftPolicySourceGuardTest.php` (`:1-80`) ·
`PpeFfp2RenderRegressionTest.php` (`:1-60`) · `CdmContractorNoteRenderRegressionTest.php` (`:1-45`) ·
`PdfSnapshotTest.php` (`:1-120`, plus fixture-name grep) ·
`RamsRegenerateSnapshotsCommand.php` (`:40-100`) ·
`tests/Fixtures/rams/tilda-21cq29531/record.json` (structure + `_notes`, parsed)

**Not executed:** no PHP ran. `php` is not on this shell's PATH and `php … | tail` exits 0
having run nothing. Every claim above is read from source.

**Pattern extraction date:** 2026-09-13
