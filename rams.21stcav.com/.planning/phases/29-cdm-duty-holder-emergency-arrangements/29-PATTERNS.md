# Phase 29: CDM Duty-Holder & Emergency Arrangements - Pattern Map

**Mapped:** 2026-09-11
**Files analyzed:** 13 (new/modified)
**Analogs found:** 13 / 13

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|---|---|---|---|---|
| `RamsComplianceUpgradeService::addCdmDutyHolders()` (modify) | service (default-filler) | transform | same method, existing body | exact (self-modify) |
| `RamsComplianceUpgradeService::enforceCdmGate()` (new, GATE-11) | service (throwing gate) | transform / validation | `enforceFfp2AndConfinedSpaceGate()` (`:1298-1378`) | exact |
| `RamsComplianceUpgradeService::enforceEmergencyGate()` (new, GATE-12) | service (throwing gate) | transform / validation | `enforceFfp2AndConfinedSpaceGate()` (`:1298-1378`) | exact |
| `App\Services\Rams\SiteEmergencyResolver` (new class) | service (pure resolver) | transform | `ControlTextRuleViolations` (static classifier shape) + `DisplayLiftPolicy` (pure decision class consumed by both a gate and a render path) | role-match |
| `config/rams_tier1.php` (`cdm_ae_gate_enabled` block, new) | config | request-response (read at runtime) | `display_lift_gate_enabled` (`:74`) / `ffp2_confined_space_gate_enabled` (`:98`) | exact |
| `database/migrations/2026_09_1X_backfill_cdm_duty_holder_placeholder.php` (new) | migration | batch / idempotent transform | `2026_09_05_180000_backfill_ppe_ffp2_and_electrical_exclusion_bullet.php` | exact |
| `resources/views/pdf/rams.blade.php` (Welfare bullet `:1960`, `'TBC'` fallback `:1983`, CDM merge `:1822-1826`) | component (blade) | request-response (render) | itself / `rams-v2.blade.php` twin | exact (paired site) |
| `resources/views/pdf/rams-v2.blade.php` (Welfare bullet `:2021`, `'TBC'` fallback `:2066`, CDM merge `:1883-1887`) | component (blade) | request-response (render) | `rams.blade.php` twin | exact |
| `app/Services/DocxBuilderService.php::buildWelfareArrangements()` (`:2149`) | service (DOCX render) | request-response (render) | `buildCdmSection()` (`:1680-1712`, same file, already reads `cdm_duty_holders`) | exact |
| `app/Support/Rams/SectionComposers/EmergencyComposer.php` | service (composer) | transform | itself — extend to call `SiteEmergencyResolver` | exact (self-modify) |
| `app/Support/Rams/Sections/EmergencySectionDto.php` | model (DTO) | transform | itself — add a resolved/verified field | exact (self-modify) |
| `RamsDisplayPatchService::patch()` carry-forward block (`:417-430`) | service (transform, non-persisted) | transform | same block, existing body | exact (self-modify) |
| `tests/Unit/Services/Rams/CdmEmergencyGateTest.php` (new) | test | request-response (unit) | `tests/Unit/Services/Rams/Ffp2ConfinedSpaceGateTest.php` | exact |
| `tests/Feature/Rams/BackfillCdmDutyHolderMigrationTest.php` (new) | test | batch | `BackfillPpeFfp2AndElectricalExclusionBulletMigrationTest.php` (sibling to the migration above) | exact |

## Pattern Assignments

### `RamsComplianceUpgradeService::enforceCdmGate()` / `enforceEmergencyGate()` (GATE-11/GATE-12)

**Analog:** `app/Services/Rams/RamsComplianceUpgradeService.php::enforceFfp2AndConfinedSpaceGate()` (`:1298-1378`), wired into `upgrade()` at `:62-79`.

**Pipeline wiring pattern** (`upgrade()`, `:36-77` — GATE-11/12 slot in directly after `addCdmDutyHolders()` at `:70`, per RESEARCH.md Finding 1):
```php
// Source: app/Services/Rams/RamsComplianceUpgradeService.php:62-79 (existing gates)
if (config('rams_tier1.display_lift_gate_enabled', true)) {
    $ramsData = self::enforceDisplayLiftGate($ramsData);
}
if (config('rams_tier1.ffp2_confined_space_gate_enabled', true)) {
    $ramsData = self::enforceFfp2AndConfinedSpaceGate($ramsData);
}
// GATE-11/GATE-12 insertion point — immediately after addCdmDutyHolders(),
// which is the last-but-one step in upgrade() at :70 today:
$ramsData = self::addCdmDutyHolders($ramsData);
if (config('rams_tier1.cdm_ae_gate_enabled', false)) {   // NOTE default false, not true — D-03
    $ramsData = self::enforceCdmGate($ramsData);
    $ramsData = self::enforceEmergencyGate($ramsData);
}
$ramsData = self::cleanTextArtifacts($ramsData);
```

**Core throwing-gate shape to copy verbatim** (`:1298-1378`):
```php
private static function enforceFfp2AndConfinedSpaceGate(array $data): array
{
    $hazards = (array) ($data['hazards'] ?? []);

    foreach ($hazards as $hazard) {
        $hazard = (array) $hazard;
        $name = (string) ($hazard['hazard'] ?? '');
        // Unconditional per hazard — NOT nested inside any
        // template-resolution branch.
        $nameViolation = ControlTextRuleViolations::detect($name);

        if ($nameViolation === 'confined_space') {
            throw new RamsGenerationException(sprintf(
                'Hazard name "%s" is classified as a confined-space house-rule violation (GATE-07/RULE-06). '
                . 'Rename this hazard before regenerating, or set '
                . 'RAMS_PPE_CEILING_ELECTRICAL_GATE=false to disable this check.',
                $name,
            ));
        }
        // ... (control-line loop, PPE array loop — same "throw on first
        // violation found" shape, message names offending value + rule ID
        // + disabling env var) ...
    }

    return $data;
}
```
For GATE-11, loop `$data['cdm_duty_holders']` and throw when any of `principal_designer`/`principal_contractor`/`project_manager`/`site_supervisor` (or whichever keys the restated RULE-07 defines) is still literally `'[To be confirmed]'` after `addCdmDutyHolders()` ran (i.e. the gate is really "did the default-filler leave the forbidden placeholder in the FINAL payload" — mirrors GATE-06's raw-substring PPE check at `:1353-1362` more than the hazard-loop). For GATE-12, loop over the resolved A&E value (from `SiteEmergencyResolver`, see below) and apply the three D-08 checks (banned string / urgent-care keyword / named-with-no-address-or-postcode) — the D-05 hold-point line must pass clean, exactly like `ControlTextRuleViolations`' conservative-by-construction "unclassifiable is CLEAN" rule.

**Message format to copy exactly** — every throw names (a) the offending value, (b) the rule ID (`GATE-11/RULE-07`, `GATE-12/RULE-08`), (c) the disabling env var (`RAMS_CDM_AE_GATE=false`). Example template:
```php
throw new RamsGenerationException(sprintf(
    '%s "%s" is classified as a %s (GATE-1X/RULE-0Y). Correct the value before regenerating, or set '
    . 'RAMS_CDM_AE_GATE=false to disable this check.',
    $fieldLabel, $value, $reason,
));
```

---

### `config/rams_tier1.php` — new `cdm_ae_gate_enabled` block

**Analog:** `:74` (`display_lift_gate_enabled`) and `:98` (`ffp2_confined_space_gate_enabled`).

```php
// Source: config/rams_tier1.php:74 / :98 (shape to copy, comment-block style)
'display_lift_gate_enabled' => env('RAMS_DISPLAY_LIFT_GATE', true),
'ffp2_confined_space_gate_enabled' => env('RAMS_PPE_CEILING_ELECTRICAL_GATE', true),
```

New block (append after `:98`'s block):
```php
/*
|--------------------------------------------------------------------------
| CDM duty-holder / A&E gate kill-switch (Phase 29, GATE-11/GATE-12)
|--------------------------------------------------------------------------
|
| Gates ONLY RamsComplianceUpgradeService::enforceCdmGate() and
| enforceEmergencyGate() — the independent re-check of the CDM
| duty-holder table (GATE-11/RULE-07) and the resolved nearest-A&E value
| (GATE-12/RULE-08) after upgrade() has run its default-fillers. When
| false, neither method is called — upgrade() proceeds byte-identical to
| pre-GATE-11/12 behaviour, no redeploy required.
|
| A NEW, INDEPENDENT flag per D-03 — deliberately never reuses
| RAMS_DISPLAY_LIFT_GATE or RAMS_PPE_CEILING_ELECTRICAL_GATE, so this
| gate's rollback can never accidentally disarm another's, or vice versa.
|
| DELIBERATE DIVERGENCE from the two precedents above: defaults FALSE,
| not true. GATE-09/GATE-06/07 defaulted true because their corpora were
| measured clean BEFORE shipping; GATE-11/12 have not yet been measured
| (D-02's backfill runs after this code deploys) — arming a content gate
| before its backfill/measurement is the exact "deploy-order trap" Phase
| 28's retrospective (28-CONTEXT.md) warned against. Flip to true in
| .env as a separate one-line change only after the backfill has run and
| a live regeneration verifies clean (D-03).
|
*/
'cdm_ae_gate_enabled' => env('RAMS_CDM_AE_GATE', false),
```

---

### `database/migrations/2026_09_1X_backfill_cdm_duty_holder_placeholder.php`

**Analog:** `database/migrations/2026_09_05_180000_backfill_ppe_ffp2_and_electrical_exclusion_bullet.php` (full file read).

**Idempotency technique to copy verbatim** — value-equality guard per surface, chunked, dual-column, auditable count output:
```php
// Source: 2026_09_05_180000_backfill_ppe_ffp2_and_electrical_exclusion_bullet.php (shape)
public function up(): void
{
    $documentsTouched = 0;
    // ... one counter per independently-tracked surface ...

    DB::table('rams_documents')
        ->select('id', 'reviewed_data', 'generated_data')
        ->orderBy('id')
        ->chunkById(100, function ($rows) use (&$documentsTouched /* ... */) {
            foreach ($rows as $row) {
                $update = [];
                foreach (['reviewed_data', 'generated_data'] as $column) {
                    if (empty($row->$column)) { continue; }
                    $data = is_array($row->$column) ? $row->$column : json_decode((string) $row->$column, true);
                    if (! is_array($data)) { continue; }

                    $columnChanged = false;
                    [$data, $changed] = self::fixCdmPlaceholder($data);   // your new method
                    $columnChanged = $columnChanged || $changed;

                    if ($columnChanged) {
                        $update[$column] = json_encode($data);
                    }
                }
                if ($update === []) { continue; }
                DB::table('rams_documents')->where('id', $row->id)->update($update);
                $documentsTouched++;
            }
        });

    echo sprintf("backfill_cdm_duty_holder_placeholder: %d document(s) touched\n", $documentsTouched);
}

public function down(): void
{
    // Deliberate no-op — see class docblock "REVERSIBILITY".
    // Cannot distinguish a migration-touched row from one an engineer
    // independently typed a real name into by hand afterward.
}
```

**Per-surface fix method shape to copy** (`fixExclusions()` at the bottom of the analog is the closest single-field-append example; `fixHazards()` shows the "only overwrite when currently the placeholder" guard pattern needed for CDM's two shapes):
```php
// reviewed_data['cdm'] is a LIST of {role, name} rows (RamsController.php:504-509);
// generated_data['cdm_duty_holders'] is a KEYED array (client/principal_designer/...).
// Check both shapes independently — Finding 5's measurement query already
// distinguishes them; reuse that exact logic as the "is this still a
// placeholder" test:
private static function fixCdmPlaceholder(array $data): array
{
    $changed = false;
    // generated_data['cdm_duty_holders'] shape — only overwrite a key whose
    // current value is literally '[To be confirmed]'.
    if (isset($data['cdm_duty_holders']) && is_array($data['cdm_duty_holders'])) {
        foreach (['principal_designer', 'principal_contractor', 'project_manager', 'site_supervisor'] as $key) {
            if (($data['cdm_duty_holders'][$key] ?? null) === '[To be confirmed]') {
                // apply the restated RULE-07 default for $key
                $changed = true;
            }
        }
    }
    // reviewed_data['cdm'] shape — a list of {role, name}; patch any row
    // whose name still contains 'To be confirmed'.
    if (isset($data['cdm']) && is_array($data['cdm'])) {
        foreach ($data['cdm'] as $i => $row) {
            if (str_contains((string) ($row['name'] ?? ''), 'To be confirmed')) {
                // apply the restated default
                $changed = true;
            }
        }
    }
    return [$data, $changed];
}
```

**Measurement query to run first (D-02 checkpoint)** — from RESEARCH.md Finding 5, copy verbatim into a `php artisan tinker` checkpoint task before writing the migration body (counts placeholder rows across both `reviewed_data['cdm']` list-shape and `generated_data['cdm_duty_holders']` keyed-shape).

---

### `RamsComplianceUpgradeService::addCdmDutyHolders()` (modify, RULE-07 wording)

**Existing body to modify** (`:1093-1111`):
```php
private static function addCdmDutyHolders(array $data): array
{
    $project = (array) ($data['project'] ?? []);

    $data['cdm_duty_holders'] = [
        'client'               => trim((string) ($project['client'] ?? '')) ?: '[Client Name]',
        'principal_designer'   => '[To be confirmed]',
        'principal_contractor' => '[To be confirmed]',
        'contractor'           => '21st Century AV Ltd',
        'subcontractor'        => '21st Century AV Ltd',
        'project_manager'      => trim((string) ($project['project_manager'] ?? '')) ?: '[To be confirmed]',
        'site_supervisor'      => trim((string) ($project['lead_engineer'] ?? '')) ?: '[To be confirmed]',
        'cdm_regulation'       => 'Construction (Design and Management) Regulations 2015',
        'notification'         => 'F10 notification submitted by Principal Contractor where applicable',
    ];

    return $data;
}
```
Do **not** simply string-replace `'[To be confirmed]'` — RULE-07's restated wording (CONTEXT.md Claude's Discretion) needs a conditional sole-vs-multiple-contractor sentence assembled here, likely a new key (e.g. `contractor_note`) or a restructured `contractor`/`principal_contractor` value carrying the anticipated-sole-contractor sentence verbatim from `standards-and-legislation.md:23-34`.

---

### `App\Services\Rams\SiteEmergencyResolver` (new class, RULE-08 branch logic)

**Analog for "pure static resolver both a gate and multiple render sites call"**: `App\Services\Rams\DisplayLiftPolicy` (consumed by both `deriveMaterialHandling()` and `enforceDisplayLiftGate()`) and `ControlTextRuleViolations::detect()` (static classifier, no state, called from both the gate and the backfill migration).

**Shape to follow** — a single static method resolving `site_emergency` into exactly two branches (verified named+address, or the D-05 hold-point string), called from:
1. `RamsComplianceUpgradeService::upgrade()` to populate a new resolved key on `$data` (e.g. `$data['site_emergency_resolved']`) before `enforceEmergencyGate()` inspects it, and
2. `EmergencyComposer` (composer path) so `EmergencySectionDto` carries the same resolved value.

```php
// Skeleton — no existing file to copy verbatim (RESEARCH.md: "genuinely new
// architecture"); follow ControlTextRuleViolations' static-utility-class
// shape (no constructor, all methods `public static function`):
final class SiteEmergencyResolver
{
    // D-05 hold-point line — verbatim from house-rules.md, never paraphrased.
    private const HOLD_POINT = 'Nearest A&E — to be confirmed at induction (must be a 24/7 Emergency Department)';

    public static function resolve(array $siteEmergency): array
    {
        $name = trim((string) ($siteEmergency['nearest_hospital'] ?? ''));
        $address = trim((string) ($siteEmergency['hospital_address'] ?? ''));

        if ($name === '' || $address === '') {
            return ['verified' => false, 'text' => self::HOLD_POINT];
        }

        return [
            'verified' => true,
            'text' => sprintf('%s, %s. Route and travel time confirmed at induction.', $name, $address),
        ];
    }
}
```
GATE-12's plausibility keywords (D-08: urgent-care / minor-injury / walk-in / UTC) should live as a static const array on this resolver or on `ControlTextRuleViolations`-style detector, mirroring `ControlTextRuleViolations::CONFINED_SPACE_AFFIRMATIVE`'s exact shape (static const array, checked with `stripos`) per RESEARCH.md's Don't-Hand-Roll table.

---

### Render sites — Welfare bullet + `'TBC'` fallback (5 sites, D-01/D-07)

**Analog:** the two blades mirror each other exactly; DOCX mirrors both blades' prose.

**Welfare bullet — current banned text (identical in both blades)**:
```blade
{{-- Source: resources/views/pdf/rams.blade.php:1960 (and rams-v2.blade.php:2021, identical) --}}
<li><strong>First Aid:</strong> At least one engineer on site will hold a current First Aid at Work or Emergency First Aid at Work certificate. First aid kit carried at all times. Nearest hospital A&amp;E to be identified at site induction.</li>
```
Per D-07, replace the last sentence with a pointer: `Nearest A&E — see Section 7.0.` — keep the first-aid certificate/kit sentence unchanged.

**DOCX equivalent** (`app/Services/DocxBuilderService.php::buildWelfareArrangements()`, item array around `:2149`):
```php
// Source: app/Services/DocxBuilderService.php (buildWelfareArrangements(), items array)
['First Aid:', 'At least one engineer on site will hold a current First Aid at Work or Emergency First Aid at Work certificate. First aid kit carried at all times. Nearest hospital A&E to be identified at site induction.'],
```
Same fix — drop the A&E sentence, add the Section 7.0 pointer.

**`'TBC'` fallback in §7.0 table — legacy blade** (`rams.blade.php:1983`):
```blade
{{ ($siteEmerg['nearest_hospital'] ?? '') ?: 'TBC' }}
```
**v2 blade's own independent fallback** (`rams-v2.blade.php:2066` — NOT named in CONTEXT.md's D-01 table, confirmed live by RESEARCH.md Pitfall 3, must also be fixed):
```blade
{{ $emergencyDto->nearestHospital ?: 'TBC' }}
```
Both replaced with a call to the resolved value from `SiteEmergencyResolver::resolve()` (legacy blade reads `$data['site_emergency_resolved']['text']`; v2 blade reads a new `$emergencyDto` field — see `EmergencySectionDto` below), never the literal `'TBC'` string per D-07.

**`buildCdmSection()` — pattern to mirror for reading a pre-resolved key** (`app/Services/DocxBuilderService.php:1680-1712`, already correctly reads `$data['cdm_duty_holders']` with per-key `?? '[To be confirmed]'` fallbacks — this is the shape any new "read the resolved emergency value" DOCX code should copy, i.e. read one already-resolved key, no branch logic in the render method itself):
```php
private function buildCdmSection(PhpWord $phpWord, array $data): void
{
    $cdm = $data['cdm_duty_holders'] ?? [];
    if (empty($cdm)) {
        return; // No CDM data — skip section entirely (backwards compatible)
    }
    // ... table rows read $cdm['client'] ?? '[Client Name]' etc., no computation here ...
}
```

**CDM merge pattern (both blades, identical) — where the D-02 backfill's real target lives**:
```blade
{{-- Source: rams.blade.php:1822-1826 / rams-v2.blade.php:1883-1887 (identical) --}}
// Merge cdm_duty_holders from compliance upgrade when cdmRows is empty
$cdmMerged = $cdmRows;
if (empty($cdmMerged) && ! empty($data['cdm_duty_holders'])) {
    $cdmDh = $data['cdm_duty_holders'];
    ...
}
```
`$cdmRows` comes from `$rams->reviewed_data['cdm'] ?? []` at `rams.blade.php:439` / `rams-v2.blade.php:478`. Per RESEARCH.md Finding 6, DOCX (`buildCdmSection()`) never reads `cdmRows` at all — the backfill's `generated_data['cdm_duty_holders']` fix is what actually reaches the live DOCX path; `reviewed_data['cdm']` only matters for the two PDF blades.

---

### `RamsDisplayPatchService::patch()` carry-forward guard (D-04)

**Existing block to modify** (`:407-430`):
```php
// Source: app/Services/Rams/RamsDisplayPatchService.php:407-430
if ($rams->project_id && (empty($rd['site_emergency']) || empty($rd['cdm']))) {
    $prior = RamsDocument::query()
        ->where('project_id', $rams->project_id)
        ->where('id', '!=', $rams->id)
        ->where('status', RamsDocument::STATUS_COMPLETED)
        ->orderByDesc('updated_at')
        ->first();
    if ($prior) {
        $priorRd = $prior->reviewed_data ?? [];
        if (empty($rd['site_emergency']) && ! empty($priorRd['site_emergency'])) {
            $rd['site_emergency'] = $priorRd['site_emergency'];
        }
        if (empty($rd['cdm']) && ! empty($priorRd['cdm'])) {
            $rd['cdm'] = $priorRd['cdm'];
        }
    }
}
```
D-04 requires each of the two `! empty($priorRd[...])` guards to ALSO check the prior value is not still the placeholder before copying — e.g. for `cdm`, skip any row whose `name` contains `'To be confirmed'`; for `site_emergency`, skip when `nearest_hospital` is blank or matches the hold-point text. Reuse the same placeholder-detection logic as the backfill migration's `fixCdmPlaceholder()` (single choke point — do not write a third independent placeholder check).

---

### Tests — GATE-11/GATE-12 throw/no-throw boundary

**Analog:** `tests/Unit/Services/Rams/Ffp2ConfinedSpaceGateTest.php` (full reflection-based private-method-invocation pattern, `:1-90` read).

**Reflection helper to copy verbatim**:
```php
// Source: tests/Unit/Services/Rams/Ffp2ConfinedSpaceGateTest.php:56-62
private function invokePrivateStatic(string $method, array $args = []): mixed
{
    $m = new \ReflectionMethod(RamsComplianceUpgradeService::class, $method);
    $m->setAccessible(true);

    return $m->invoke(null, ...$args);
}
```

**Test-per-violation shape**:
```php
public function test_hazard_control_ffp2_throws(): void
{
    $this->expectException(RamsGenerationException::class);

    $this->invokePrivateStatic('enforceFfp2AndConfinedSpaceGate', [
        $this->dataWithHazard('X', ['Dust mask (FFP2) worn.']),
    ]);
}
```
For `CdmEmergencyGateTest`, build `dataWithCdm(array $cdmDutyHolders)` / `dataWithSiteEmergency(array $resolved)` fixture helpers and one test method per throw condition (GATE-11: each placeholder field; GATE-12: banned string / urgent-care keyword / named-with-no-address / D-05 hold-point passes clean / verified name+address passes clean).

**Non-vacuity discipline to repeat** — the analog's class docblock (`:22-51`) documents a "break the fix, watch the test fail, restore, watch it pass" procedure performed during development, not as a committed assertion. RESEARCH.md's Wave 0 gap list explicitly asks for this discipline to be repeated for the new gate.

**Dual-path test analog:** `Ffp2ConfinedSpaceDualPathGateTest.php` (referenced but not read this session — locate under `tests/Feature/Rams/` and mirror its shape for `CdmEmergencyDualPathGateTest.php`, proving both `RamsController::updateAndDownload()` (Save Review) and the regeneration path independently hit the gate).

**Backfill migration test analog:** `tests/Feature/Rams/BackfillPpeFfp2AndElectricalExclusionBulletMigrationTest.php` (sibling to the migration file above — not read this session; mirror its shape for `BackfillCdmDutyHolderMigrationTest.php`, asserting idempotency on second run and that a real engineer-typed name is never overwritten).

## Shared Patterns

### Gate = auto-correct + independent throwing re-check
**Source:** `app/Services/Rams/RamsComplianceUpgradeService.php:62-79` (wiring), `:1171-1240` (GATE-09), `:1298-1378` (GATE-06/07)
**Apply to:** `enforceCdmGate()`, `enforceEmergencyGate()` — same call-immediately-after-the-producing-step placement, same unconditional-loop-no-template-branching shape, same three-part throw message (offending value + rule ID + disabling env var).

### Per-gate config kill-switch, own flag, default divergence documented
**Source:** `config/rams_tier1.php:60-98`
**Apply to:** the new `cdm_ae_gate_enabled` block — same doc-comment structure (what it gates / when false / why an independent flag), but explicitly calls out the `false` default as a deliberate divergence from the two `true`-default precedents (D-03/Pitfall 5).

### Throw + catch + friendly redirect (no new surfacing code needed)
**Source:** `app/Http/Controllers/RamsController.php:597-604` (Save Review), `:857-861` (PDF render), `:697-705` (DOCX rebuild, looser `catch (\Throwable $e)`)
**Apply to:** GATE-11/GATE-12 — `RamsGenerationException` thrown inside `upgrade()` is already caught at all three call sites; verify (per RESEARCH.md Assumption A3) the third site's `\Throwable` catch surfaces the message as cleanly as the other two before assuming zero controller changes needed.

### Idempotent backfill migration (measure-first, value-equality guard, documented no-op `down()`)
**Source:** `database/migrations/2026_09_05_180000_backfill_ppe_ffp2_and_electrical_exclusion_bullet.php` (full file)
**Apply to:** the new CDM backfill migration — chunked `DB::table('rams_documents')->chunkById(100, ...)`, per-column (`reviewed_data`/`generated_data`) independent processing, per-surface changed-counters, auditable `echo` summary, `down()` documented as a deliberate no-op with the same "cannot distinguish migration-touched from hand-corrected" justification.

## No Analog Found

| File | Role | Data Flow | Reason |
|---|---|---|---|
| `App\Services\Rams\SiteEmergencyResolver` | service (pure resolver) | transform | Genuinely new architecture per RESEARCH.md — no existing class resolves a two-branch verified/hold-point value shared between a gate and multiple render sites. Closest structural analogs (`DisplayLiftPolicy`, `ControlTextRuleViolations`) are cited above for shape (static utility class, no state) but neither does branch resolution of this kind. |

## Metadata

**Analog search scope:** `app/Services/Rams/`, `app/Http/Controllers/RamsController.php`, `app/Support/Rams/`, `config/rams_tier1.php`, `resources/views/pdf/`, `database/migrations/`, `tests/Unit/Services/Rams/`, `tests/Feature/Rams/`
**Files scanned:** 13 source files read in full or targeted-range; RESEARCH.md's own file:line map (already file-verified) used to target every Read/Grep precisely, avoiding re-reads
**Pattern extraction date:** 2026-09-11
