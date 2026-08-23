# Phase 26: Hazard Library Structural Inversion - Pattern Map

**Mapped:** 2026-08-23
**Files analyzed:** 13 (5 new, 8 modified) + 5 test files
**Analogs found:** 17 / 18

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|---|---|---|---|---|
| `database/migrations/2026_08_2X_XXXXXX_add_include_when_to_hazard_templates.php` | migration | CRUD (schema) | `database/migrations/2026_04_19_000001_add_email_sent_columns_for_phase_09.php` | role-match (add-column, `hasColumn`-guarded) |
| `database/seeders/HazardTemplateSeeder.php` | seeder | CRUD (upsert) | itself (current version, being rewritten) | exact — extend existing idempotent pattern verbatim |
| New: `HazardIncludeWhenResolver` (or equivalent, tier logic) | service | transform | `app/Services/RiskTemplateResolverService.php` (`ACCESS_EQUIPMENT_MAP`/`PPE_ACTIVITY_MAP`) + `app/Core/Modules/KnowledgeLibrary/HazardLibraryService::fuzzyMatch()` | role-match — fixed-vocabulary matching discipline |
| `app/Core/Modules/KnowledgeLibrary/HazardLibraryService.php` | service | transform | itself (existing file, dead-code removal + new resolution entry point) | exact |
| `app/Services/RiskTemplateResolverService.php` | service | transform | itself (existing file, `buildHazards()`/`resolveHazards()` edit) | exact |
| `app/Services/Rams/Tier1RamsDefaultsService.php` | service | transform | itself (existing file, remove hazards branch only) | exact |
| `app/Services/RamsBuilderService.php` | service | request-response (build pipeline) | itself (existing file, `reviewedToRisk()` / `runPipeline()` / `buildFromReview()`) | exact |
| `config/rams_tier1.php` | config | — | itself (existing file, remove `baseline_hazards` key) | exact |
| `app/Support/Rams/SectionComposers/RiskAssessmentComposer.php` | service (composer, not live) | transform | itself (existing file, remove fallback) | exact |
| `resources/views/pdf/rams.blade.php` | view (Blade, live PDF) | request-response (render) | itself (existing file, remove `:315-317` fallback) | exact |
| `resources/views/pdf/rams-v2.blade.php` | view (Blade, not live) | request-response (render) | itself (existing file, remove `:371-373` fallback, mirrors rams.blade.php) | exact |
| `resources/views/rams/quote-review.blade.php` | view (Blade, editable intake) | request-response (form) | itself (existing file, extend hazard-row table `:753-814` + JS template `:1008-1030`) | exact |
| `app/Services/DocxBuilderService.php` (`buildRiskAssessment()`) | service (renderer) | request-response (render) | itself (existing file, `:1113-1237`, no fallback present — verify none is added) | exact |
| `app/Services/RamsReviewDataService.php` (`normaliseHazards()`) | service | transform | itself (existing file, `:155-172`) — implied modification for HAZ-04 marker, not explicitly listed but required by RESEARCH Pattern 2 | exact |
| `tests/Feature/Rams/Tier1BaselineHazardsRenderTest.php` | test | — | itself (rewrite target) | exact |
| New: hazard include-when resolver test | test | — | `tests/Unit/Services/Rams/MethodStatementAssociatedRisksTest.php` (reflection-based unit test of a private/static service method) | role-match |
| New: RA-ref regression extension | test | — | `tests/Unit/Services/Rams/MethodStatementAssociatedRisksTest.php` | exact (extend directly) |
| New: dead-path structural guard | test | — | `tests/Feature/Rams/DeadPathRemovalGuardTest.php` (confirmed real path — see below) | exact |
| New: `HazardTemplateSeeder` idempotency test | test | — | `tests/Feature/Drawings/DeviceCatalogSeederTest.php` | role-match (seeder idempotency shape) |

## Pattern Assignments

### `database/migrations/2026_08_2X_XXXXXX_add_include_when_to_hazard_templates.php` (migration, schema)

**Analog:** `database/migrations/2026_04_19_000001_add_email_sent_columns_for_phase_09.php`

**Full pattern** (verbatim structure to copy):
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [Docblock: phase, requirement IDs, what's added, WHY it's nullable/guarded,
 *  reversibility note — this repo's convention is a substantial docblock on
 *  every migration, not just a one-liner.]
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hazard_templates', function (Blueprint $t) {
            if (! Schema::hasColumn('hazard_templates', 'include_when')) {
                $t->text('include_when')->nullable()->after('controls');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hazard_templates', function (Blueprint $t) {
            $t->dropColumn('include_when');
        });
    }
};
```

**Guard-with-`hasColumn` precedent** (lines 50-58 of the analog, for the exact idiom):
```php
Schema::table('cable_schedules', function (Blueprint $t) {
    // Add error_message column (missing — RESEARCH Pitfall 3) so NOTF-04c
    // failure emails can include the cause. Guarded so re-run is safe.
    if (! Schema::hasColumn('cable_schedules', 'error_message')) {
        $t->string('error_message', 1000)->nullable()->after('status');
    }
    ...
});
```

**Note:** the `hazard_templates` table's own creation migration
(`database/migrations/2026_03_09_000001_create_hazard_templates_table.php:11-29`)
wraps the whole `Schema::create` in a `try/catch (\Throwable $e)` to survive
duplicate-table errors in SQLite test runs — that pattern is for `create`,
not `add-column`; the `add_email_sent_columns_for_phase_09` migration is the
better analog for THIS migration's shape (adding nullable columns to an
existing table) and does not need the try/catch wrapper.

---

### `database/seeders/HazardTemplateSeeder.php` (seeder, rewrite)

**Analog:** itself — the file already implements exactly the D-03 upsert-by-name
pattern the phase must extend to 18 rows plus `include_when`. Do not redesign
the loop; extend the payload shape and replace `standardHazards()`'s return
value.

**Full existing loop to preserve verbatim** (`database/seeders/HazardTemplateSeeder.php:18-42`):
```php
public function run(): void
{
    $hazards = $this->standardHazards();

    foreach ($hazards as $hazard) {
        // Idempotent: update if a global template with this name already exists
        $existing = HazardTemplate::where('is_global', true)
            ->where('name', $hazard['name'])
            ->first();

        $payload = array_merge($hazard, [
            'user_id'   => null,   // null = global
            'is_global' => true,
        ]);

        if ($existing) {
            $existing->update($payload);
            continue;
        }

        HazardTemplate::create($payload);
    }

    $this->command->info('HazardTemplateSeeder: ' . count($hazards) . ' standard hazards seeded.');
}
```

**Per-hazard array shape to extend** (from `standardHazards()`, e.g. `:50-65`):
```php
[
    'name'            => 'Manual Handling',
    'description'     => 'Moving, lifting, and positioning AV equipment, screens, and racks.',
    'pre_likelihood'  => 3,
    'pre_severity'    => 3,
    'post_likelihood' => 2,
    'post_severity'   => 2,
    'controls'        => [ /* strings */ ],
    // NEW key this phase adds:
    'include_when'    => 'always', // or a tier-2 keyword string, or an AI-tier marker, or null
],
```

**Important:** current `standardHazards()` has 13 entries; the D-03 upsert
matches on `name`, and there is no `truncate`/`delete` step anywhere — do not
add one. Renamed/merged hazards (D-02 folding map) mean some of the 13
current names will simply stop being re-emitted by the new `standardHazards()`
return value; their rows stay in the DB as orphaned `is_global=true` rows with
stale content unless the seeder explicitly handles removal — flag this as an
open design question for the planner (RESEARCH does not resolve it; D-03 only
guards against *destroying user rows*, not against orphaned superseded
global rows).

---

### New: include-when tiered resolver

**Analogs:** `app/Services/RiskTemplateResolverService.php:29-58` (fixed-vocabulary
constant-map discipline) and `app/Core/Modules/KnowledgeLibrary/HazardLibraryService.php:285-318`
(`fuzzyMatch()`, the tier-3 AI-judgement plug-in point).

**Fixed-vocabulary map pattern to copy** (`RiskTemplateResolverService.php:29-58`):
```php
private const PPE_BASE = [
    'Safety Boots (steel toe cap)',
    'Hi-Visibility Vest',
    'Safety Glasses',
    'Latex / Nitrile Gloves',
];

/**
 * Activity keys that add extra PPE items.
 * Multi-activity intersections handled by looping all matching entries.
 */
private const PPE_ACTIVITY_MAP = [
    'ceiling_works'        => ['Hard Hat', 'Dust Mask (FFP2)'],
    'display_installation' => ['Hard Hat'],
    'audio_installation'   => ['Hearing Protection'],
];

private const ACCESS_EQUIPMENT_MAP = [
    'ceiling_works'        => ['Podium Steps', 'Access Tower (if above 3 m)'],
    'display_installation' => ['Podium Steps', 'Kick Stool'],
    'av_rack'              => ['Kick Stool'],
];
```
This is the same "reviewed, fixed keyword/tag vocabulary — not open-ended
regex against free text" discipline RESEARCH's Security Domain section
explicitly names as the pattern to follow for tier-2 deterministic matching.

**Consumption loop pattern** (`buildAccessEquipment()`, `RiskTemplateResolverService.php:108-123`):
```php
private function buildAccessEquipment(array $activities): array
{
    $equipment = [];

    foreach (self::ACCESS_EQUIPMENT_MAP as $activity => $items) {
        if (in_array($activity, $activities, true)) {
            $equipment = array_merge($equipment, $items);
        }
    }

    if (empty($equipment)) {
        return self::ACCESS_EQUIPMENT_DEFAULT;
    }

    return array_values(array_unique($equipment));
}
```

**Tier-3 AI-judgement plug-in point** (`HazardLibraryService::fuzzyMatch()`, `:285-318`):
```php
private function fuzzyMatch(string $seed, Collection $library): ?HazardTemplate
{
    $seedLower = Str::lower(trim($seed));

    // 1. Exact
    $exact = $library->first(fn($t) => Str::lower($t->name) === $seedLower);
    if ($exact !== null) return $exact;

    // 2. Substring
    $sub = $library->first(function ($t) use ($seedLower) {
        $name = Str::lower($t->name);
        return str_contains($name, $seedLower) || str_contains($seedLower, $name);
    });
    if ($sub !== null) return $sub;

    // 3. Shared significant words (ignore stop words)
    $stopWords  = ['and', 'or', 'of', 'the', 'a', 'an', 'in', 'at', 'to', 'for', 'from', 'by'];
    $seedWords  = array_diff(explode(' ', $seedLower), $stopWords);

    $best      = null;
    $bestScore = 1; // Require at least 2 shared words

    foreach ($library as $template) {
        $nameWords = array_diff(explode(' ', Str::lower($template->name)), $stopWords);
        $shared    = count(array_intersect($seedWords, $nameWords));

        if ($shared > $bestScore) {
            $bestScore = $shared;
            $best      = $template;
        }
    }

    return $best;
}
```
Tier 3 reuses this via `resolveFromSeeds()` (`:77-113`) — the AI extraction
already emits seed strings that get fuzzy-matched to library rows; the new
resolver's tier-3 branch should feed candidate seed phrases (asbestos,
lone-working, road-risk, vehicle/plant, occupied-premises trigger language)
into this exact mechanism rather than building a second matcher.

---

### `app/Core/Modules/KnowledgeLibrary/HazardLibraryService.php` (remove dead code)

**Exact removal targets** (verbatim, current file):
```php
// :36-44 — DELETE
private const MANDATORY_KEYWORDS = [
    'working at height',
    'manual handling',
    'electrical',
    'slips, trips and falls',
    'noise and vibration',
    'working in occupied premises',
    'confined spaces',
];

// :195-249 — DELETE mandatoryBaseline()
// :254-273 — DELETE mergeWithMandatory()
```

**Callers that reference the deleted methods and must be updated in the same
file** (`resolveByIds()` at `:54-65`, `resolveFromSeeds()` at `:77-113`) —
both currently branch on `$includeMandatory` and call `mergeWithMandatory()`/
`mandatoryBaseline()`. Once those methods are gone, `$includeMandatory` either
gets removed as a parameter or repurposed to gate the new tiered resolver —
planner's call, but note both call sites are inside this same file and both
must change together, not just the private helpers.

**`fuzzyMatch()` (`:285-318`) is KEPT** — it becomes the tier-3 plug-in point,
not deleted. Do not remove it while deleting the mandatory-baseline machinery
around it.

---

### `app/Services/RiskTemplateResolverService.php` (empty-names fallback fix)

**Exact removal/replacement target** (`:189-194`):
```php
private function resolveHazards(int $userId, array $names): Collection
{
    $includeMandatory = empty($names);

    return $this->hazardLibrary->resolveFromSeeds($userId, $names, $includeMandatory);
}
```
This is the SECOND, separate edit the CONTEXT.md correction calls out — not
the same edit as removing `HazardLibraryService::MANDATORY_KEYWORDS`. Once
`resolveFromSeeds()`'s `$includeMandatory` parameter is gone/repurposed
(previous section), this call site's `empty($names)` branching must be
replaced by a call into the new tiered resolver (which does not need an
"empty names" special case — it evaluates unconditionally against `$signals`,
producing the 4 Always hazards even when `$names` is empty).

**Caller context** (`buildHazards()`, `:140-179`) — this is where the tiered
resolver's output shape must match: `id` (1-indexed within this array,
NOT a template FK), `hazard`, `persons_at_risk`, `pre_likelihood`,
`pre_severity`, `controls`, `post_likelihood`, `post_severity`. Any new
resolver must emit rows in this exact shape (or the caller must adapt them)
since this is what feeds `RamsDataBuilderService::assemble()` downstream.

---

### `app/Services/Rams/Tier1RamsDefaultsService.php` (remove hazards branch only)

**Exact removal target** (`:69-71`, DO NOT touch the two blocks around it):
```php
// ── Baseline AV hazard register (Section 5 fallback) ─────────────
// Engineer values ALWAYS win: only inject when the hazards key is
// missing OR its value is an empty array. Any populated engineer
// hazard list is preserved verbatim.
if (empty($data['hazards']) || ! is_array($data['hazards'])) {
    $data['hazards'] = (array) config('rams_tier1.baseline_hazards', []);
}
```
**Leave untouched** (same file): the `standards_references` block (`:74-76`)
and the `coshh_baseline` block (`:78-82`) — both explicitly out of scope per
CONTEXT.md Deferred Ideas.

---

### `app/Services/RamsBuilderService.php` (two call sites, `reviewedToRisk()`)

**Call sites that invoke the tier1Defaults injector — leave the call itself,
since the service's hazards branch is being removed at its source, not here**
(`:273` inside `buildFromReview()`, `:752` inside `runPipeline()`):
```php
$data = $this->tier1Defaults->injectDefaultsIntoRamsData($data);
```

**The actual live hazard-resolution call site to change** (`reviewedToRisk()`,
`:397-444`) — this is where the new tiered resolver plugs in, reading
`$rd['hazards']` (today's Low/Medium/High rows from `quote-review.blade.php`)
and looking each name up via `resolveFromSeeds($userId ?? 0, [$name], false)`:
```php
private function reviewedToRisk(array $rd, ?int $userId = null): array
{
    $hazards = array_values(array_map(function (array $h, int $i) {
        $preL = null; $preS = null; $postL = null; $postS = null;
        $controls = (array) ($h['control_measures'] ?? []);
        $name = (string) ($h['hazard'] ?? '');

        // Prefer hazard library values when available
        if ($name !== '') {
            $resolved = $this->hazardLibrary->resolveFromSeeds($userId ?? 0, [$name], false);
            $tpl = $resolved->first();
            if ($tpl) {
                $preL = (int) ($tpl->pre_likelihood  ?? null);
                $preS = (int) ($tpl->pre_severity    ?? null);
                $postL = (int) ($tpl->post_likelihood ?? null);
                $postS = (int) ($tpl->post_severity   ?? null);
                if (empty($controls)) {
                    $controls = (array) ($tpl->controls ?? []);
                }
            }
        }

        if ($preL === null || $preS === null) {
            [$preL, $preS] = $this->riskLevelsFromString((string) ($h['risk'] ?? 'Medium'));
        }
        ...
```
This is the ONE place that already reads `reviewed_data['hazards']` and turns
it into scored rows — the natural home for HAZ-04's "carry the typical score
as an editable default + unreviewed marker" behaviour, and where D-06's
"include and flag" default is easiest to implement (already has `$tpl`
in scope when a library match is found).

**`riskLevelsFromString()` (`:655-662`) — the Low/Medium/High → L×S mapping
HAZ-04 must extend or bypass**:
```php
private function riskLevelsFromString(string $risk): array
{
    return match (strtolower(trim($risk))) {
        'high'   => [4, 4],
        'low'    => [2, 2],
        default  => [3, 3],
    };
}
```

---

### `config/rams_tier1.php` (`baseline_hazards` removal)

Remove the entire `'baseline_hazards' => [...]` array (`:52-232`, ~180 lines,
11 fixed hazards). Leave `'enabled'` (`:36`), `'coshh_products'` (`:246-325`),
`'standards_references'` (`:337-399`), and `'av_prompt_bullets'` (`:415-422`)
untouched — all four are out of scope. Per D-01, this key is the only one
that "drops out of the hazard business entirely."

---

### `app/Support/Rams/SectionComposers/RiskAssessmentComposer.php` (not live, fix anyway)

**Exact fallback to remove** (`:34-38`):
```php
$raw = (array) ($rd['hazards']
    ?? ($gd['hazards']
        ?? ($this->config->get('rams_tier1.enabled', true)
            ? $this->config->get('rams_tier1.baseline_hazards', [])
            : [])));
```
Replace with just `$rd['hazards'] ?? $gd['hazards'] ?? []` (or equivalent) —
no config-baseline fallback survives. Note this class already supports an
explicit stored `ref` override (`:49`, `$h['ref'] ?? computed`) — useful
precedent if the planner wants ref stability, but that's discretionary,
not required.

---

### `resources/views/pdf/rams.blade.php` (LIVE PDF — remove fallback)

**Exact removal target** (`:309-317`):
```blade
// 260712-twi Task 2 — defensive render-time fallback for tier-1 AV
// baseline hazards. Task 1 already folds baseline hazards into
// generated_data['hazards'] at build time; this belt-and-braces path
// catches any legacy generated_data records persisted BEFORE Task 1
// shipped. Engineer values already loaded above still win — this only
// fires when hazards is empty AND the kill-switch is on.
if (empty($hazards) && config('rams_tier1.enabled', true)) {
    $hazards = (array) config('rams_tier1.baseline_hazards', []);
}
```
This is the highest-priority removal (Pitfall 1 in RESEARCH) — it's the LIVE
template and independently re-injects the fixed 11 regardless of what the
generation-time services decide.

---

### `resources/views/pdf/rams-v2.blade.php` (not live — mirror the fix)

**Exact removal target** (`:365-373`, identical structure to `rams.blade.php`):
```blade
// 260712-twi Task 2 — defensive render-time fallback for tier-1 AV
// baseline hazards. ...
if (empty($hazards) && config('rams_tier1.enabled', true)) {
    $hazards = (array) config('rams_tier1.baseline_hazards', []);
}
```

---

### `resources/views/rams/quote-review.blade.php` (HAZ-04 editable intake — the real gap)

**Analog:** itself — the existing hazard-row table is the touch-point to
extend, not replace wholesale.

**Current row shape to extend** (`:775-813`):
```blade
<tbody id="hazards-tbody">
    @foreach ($reviewPayload['hazards'] as $i => $hazard)
        @php $c_haz = $fieldChanged("hazards.{$i}.hazard"); @endphp
        <tr class="{{ $diffClass($c_haz) }}">
            <td class="col-act">
                <input type="text"
                       name="hazards[{{ $i }}][activity_key]"
                       value="{{ old("hazards.{$i}.activity_key", $hazard['activity_key']) }}"
                       placeholder="optional" maxlength="100"
                       style="font-family:monospace;font-size:.78rem;">
            </td>
            <td>
                <input type="text"
                       name="hazards[{{ $i }}][hazard]"
                       value="{{ old("hazards.{$i}.hazard", $hazard['hazard']) }}"
                       placeholder="e.g. Working at Height" maxlength="500">
            </td>
            <td class="col-risk">
                <select name="hazards[{{ $i }}][risk]">
                    @foreach (['Low', 'Medium', 'High'] as $level)
                        <option value="{{ $level }}"
                                {{ old("hazards.{$i}.risk", $hazard['risk']) === $level ? 'selected' : '' }}>
                            {{ $level }}
                        </option>
                    @endforeach
                </select>
            </td>
            <td>
                <textarea name="hazards[{{ $i }}][control_measures]" rows="3"
                          placeholder="Enter each control measure on a new line…">{{ old("hazards.{$i}.control_measures", implode("\n", $hazard['control_measures'])) }}</textarea>
            </td>
            <td class="col-del">
                <button type="button" class="btn-remove" onclick="removeRow(this)" title="Remove">✕</button>
            </td>
        </tr>
    @endforeach
</tbody>
```
This is the ONLY score-editability touch-point (`select` mapped to `[preL,preS]`
via `riskLevelsFromString()`) — no numeric L×S input, no reviewed/unreviewed
marker anywhere. The `$diffClass($c_haz)` / `$fieldChanged(...)` pattern
already shown here is the existing "highlight this field if the AI-vs-reviewed
values differ" convention to reuse for a "not yet reviewed" visual marker.

**Matching JS row-template to keep in sync** (`:1008-1030`):
```js
function hazardRowTemplate(idx) {
    return `<tr>
        <td class="col-act">
            <input type="text" name="hazards[${idx}][activity_key]" placeholder="optional" maxlength="100" style="font-family:monospace;font-size:.78rem;">
        </td>
        <td>
            <input type="text" name="hazards[${idx}][hazard]" placeholder="e.g. Working at Height" maxlength="500">
        </td>
        <td class="col-risk">
            <select name="hazards[${idx}][risk]">
                <option value="Low">Low</option>
                <option value="Medium" selected>Medium</option>
                <option value="High">High</option>
            </select>
        </td>
        <td>
            <textarea name="hazards[${idx}][control_measures]" rows="3" placeholder="Enter each control measure on a new line…"></textarea>
        </td>
        <td class="col-del">
            <button type="button" class="btn-remove" onclick="removeRow(this)" title="Remove">✕</button>
        </td>
    </tr>`;
}
```
Any static-row markup change (numeric L×S inputs, unreviewed marker) must be
mirrored here for rows added dynamically via "+ Add Row".

**IMPORTANT — do NOT confuse with `resources/views/rams/review.blade.php`**,
which is a read-only post-generation diff view. Verified during this mapping
pass by grep: `review.blade.php` contains no `hazards[` form-field names — it
only displays already-generated data. The editable intake is exclusively
`quote-review.blade.php`.

---

### `app/Services/RamsReviewDataService.php` (`normaliseHazards()` — implied by HAZ-04)

**Current schema to extend** (`:155-172`):
```php
private function normaliseHazards(mixed $raw): array
{
    if (! is_array($raw)) {
        return [];
    }

    return array_values(array_map(
        fn ($h) => [
            'activity_key'     => (string) ($h['activity_key'] ?? ''),
            'hazard'           => (string) ($h['hazard']       ?? ''),
            'risk'             => in_array($h['risk'] ?? '', ['Low', 'Medium', 'High'])
                                    ? $h['risk']
                                    : 'Medium',
            'control_measures' => $this->normaliseStringArray($h['control_measures'] ?? []),
        ],
        $raw,
    ));
}
```
RESEARCH Pattern 2 flags this normaliser as the schema gate any new
`score_reviewed` / numeric-L×S keys must survive — it currently whitelists
exactly 4 keys and will silently drop anything not in this list. Not
explicitly named in CONTEXT.md's file list but required for HAZ-04 to work
end-to-end; the planner should treat this as an implied touch-point.

---

### `app/Services/DocxBuilderService.php::buildRiskAssessment()` (LIVE DOCX — no fallback to add)

**Confirmed: no baseline-injection fallback exists in this file** — verified
by direct read of `:1113-1237`. The hazard consumption loop reads
`$data['hazards'] ?? []` directly and computes RA refs by array position:
```php
// :1222-1230
foreach ($data['hazards'] ?? [] as $idx => $hazard) {
    $rowBg     = ($idx % 2 === 0) ? self::WHITE : self::ROW_ALT;
    $preL      = (int)($hazard['pre_likelihood']  ?? 1);
    $preS      = (int)($hazard['pre_severity']    ?? 1);
    $postL     = (int)($hazard['post_likelihood'] ?? 1);
    $postS     = (int)($hazard['post_severity']   ?? 1);
    $preScore  = $preL  * $preS;
    $postScore = $postL * $postS;
    $refLabel  = 'RA' . str_pad((string)($idx + 1), 2, '0', STR_PAD_LEFT);
    ...
```
**No code change is required in this file for HAZ-02** — it already renders
whatever `$data['hazards']` contains, and once the five upstream injection
paths stop padding that array with the fixed 11, this loop naturally renders
only the resolved set. It IS the file to verify against (RESEARCH Pitfall 4)
when confirming the fix actually reaches the live document, and it's where
`pre_likelihood`/`pre_severity`/`post_likelihood`/`post_severity` (not the
`initial_l`/`residual_l` vocabulary `RiskAssessmentComposer` uses) is the
key names that matter — confirms the "dual key vocabulary" RESEARCH note.

---

## Shared Patterns

### Idempotent global-row upsert-by-name (D-03)
**Source:** `database/seeders/HazardTemplateSeeder.php:22-39` (current file, own analog)
**Apply to:** the rewritten seeder — no new pattern needed, extend in place.
```php
$existing = HazardTemplate::where('is_global', true)
    ->where('name', $hazard['name'])
    ->first();

$payload = array_merge($hazard, ['user_id' => null, 'is_global' => true]);

if ($existing) {
    $existing->update($payload);
    continue;
}

HazardTemplate::create($payload);
```

### Env-gated rollback / kill-switch (existing `RAMS_TIER1_DEFAULTS` precedent)
**Source:** `config/rams_tier1.php:22-36`
**Apply to:** whatever new, narrower flag the planner introduces for the five
injection-path removals.
```php
/*
| Master kill-switch
| When false, ... returns $data unchanged ...
*/
'enabled' => env('RAMS_TIER1_DEFAULTS', true),
```
Checked live at every render / every service call — "no build-time constant,
no container binding to invalidate" (per RESEARCH's citation of
`PdfService.php:44-47`'s own docblock). A new flag should follow this same
`config()` + `env()` shape, not a package.

### Fixed-vocabulary keyword/tag matching (tier-2 deterministic, D-05)
**Source:** `app/Services/RiskTemplateResolverService.php:29-58` (`PPE_ACTIVITY_MAP`, `ACCESS_EQUIPMENT_MAP`)
**Apply to:** the new include-when resolver's tier-2 branch.
```php
private const ACCESS_EQUIPMENT_MAP = [
    'ceiling_works'        => ['Podium Steps', 'Access Tower (if above 3 m)'],
    'display_installation' => ['Podium Steps', 'Kick Stool'],
    'av_rack'              => ['Kick Stool'],
];
```
Same discipline the Security Domain section of RESEARCH calls out as the
required approach — reviewed, fixed vocabulary, not open-ended regex against
free-text scope narrative.

### Reflection-based unit testing of private/static service methods
**Source:** `tests/Unit/Services/Rams/MethodStatementAssociatedRisksTest.php:35-41`
**Apply to:** any new unit test targeting a private method on the new tiered
resolver (if it's implemented as a private/static method rather than a public
class API).
```php
private function crossReference(array $data): array
{
    $m = new \ReflectionMethod(RamsComplianceUpgradeService::class, 'crossReferenceMethodStatementRisks');
    $m->setAccessible(true);

    return $m->invoke(null, $data);
}
```

## No Analog Found

| File | Role | Data Flow | Reason |
|---|---|---|---|
| HAZ-04 exact UI mechanism (numeric L×S input vs Low/Medium/High + marker) | component (Blade fragment) | request-response | No existing L×S numeric-input pattern anywhere in `quote-review.blade.php` or any other RAMS view — genuinely new UI, left to Claude's Discretion per CONTEXT.md. The closest structural precedent is the existing `$diffClass()`/`$fieldChanged()` highlight convention (see quote-review.blade.php section above), which is a styling analog, not a form-control analog. |

## Test File Confirmations

**`DeadPathRemovalGuardTest` — CONFIRMED, exact path:**
`tests/Feature/Rams/DeadPathRemovalGuardTest.php` — exists, is the Phase 22.1
guard (not a Phase 26 file). Its pattern (static substring scan across
`app/` + `tests/*.php` for forbidden class basenames, plus a
`assertFileDoesNotExist()` filesystem check) is the exact shape RESEARCH's
Wave 0 gap #4 asks the planner to model a NEW guard test on — for
`config('rams_tier1.baseline_hazards')` string-references and
`MANDATORY_KEYWORDS` instead of class basenames. Full pattern:
```php
public function test_deleted_classes_have_zero_references_in_app_and_tests(): void
{
    $forbiddenClasses = [ /* ... */ ];
    $thisTestPath = realpath(__FILE__);
    $files = array_merge(
        $this->phpFilesUnder(base_path('app')),
        $this->phpFilesUnder(base_path('tests')),
    );
    $offenders = [];
    foreach ($files as $file) {
        $real = realpath($file);
        if ($real !== false && $real === $thisTestPath) continue;
        $contents = file_get_contents($file);
        foreach ($forbiddenClasses as $needle) {
            if (str_contains($contents, $needle)) {
                $offenders[] = "{$file} contains '{$needle}'";
            }
        }
    }
    $this->assertEmpty($offenders, /* ... */);
}
```
A new Phase 26 guard test should live alongside it at
`tests/Feature/Rams/` (e.g. `HazardInjectionPathsRemovedGuardTest.php`),
following this exact substring-scan + filesystem-check shape but targeting
strings `rams_tier1.baseline_hazards` and `MANDATORY_KEYWORDS` instead of
class basenames.

**`Tier1BaselineHazardsRenderTest` — full content read**, `tests/Feature/Rams/Tier1BaselineHazardsRenderTest.php`
(134 lines). Three tests:
1. `test_baseline_hazards_render_when_reviewed_data_hazards_is_empty` — asserts
   the OLD behaviour (baseline injects "Working at Height" / "Manual Handling
   of AV Equipment" / "Electrical Isolation"). **Must be rewritten to assert
   the OPPOSITE** per RESEARCH Pitfall 2 — empty/near-empty signals → only the
   4 Always hazards, old config-baseline titles absent.
2. `test_engineer_supplied_hazards_render_verbatim_and_baseline_is_not_injected`
   — likely reusable as-is (engineer values still win).
3. `test_disabled_flag_leaves_hazards_empty_no_baseline_injected` — already
   models the target "flag off → empty" end state; reusable with minimal
   changes as the new kill-switch's regression guard. Uses `renderWith()` /
   `ramsStub()` / `baseData()` helpers that are good scaffolding to keep.

**`MethodStatementAssociatedRisksTest` — full content read**,
`tests/Unit/Services/Rams/MethodStatementAssociatedRisksTest.php` (201 lines).
Tests `RamsComplianceUpgradeService::crossReferenceMethodStatementRisks`
(private static, invoked via Reflection) with a fixed 3-hazard fixture
(non-sequential `id` values 4/9/11, proving refs are position-derived not
id-derived). The RA-ref regression extension RESEARCH's Wave 0 gap #4 asks
for should extend `hazards()` (`:51-58`) to a variable-length (non-3, ideally
non-11) fixture and re-assert `test_emitted_ra_ids_all_exist_in_the_rendered_risk_register`
(`:136-176`) still holds — that test already computes valid refs generically
(`array_keys(array_values($hazards))`), so it should extend cleanly to any
register length without needing new assertion logic, only a bigger fixture.

**Seeder idempotency — no exact `HazardTemplateSeeder` test exists.**
Closest analog: `tests/Feature/Drawings/DeviceCatalogSeederTest.php` (full
content read, 140 lines) — `test_seeder_is_idempotent` (`:79-98`) is the
exact shape to copy:
```php
public function test_seeder_is_idempotent(): void
{
    // ... create a fixture row ...
    $this->seed(DeviceCatalogSeeder::class);
    $afterFirst = $device->fresh();

    $this->seed(DeviceCatalogSeeder::class);
    $afterSecond = $device->fresh();

    $this->assertSame((string) $afterFirst->u_height, (string) $afterSecond->u_height);
    $this->assertSame($afterFirst->is_rack_mounted, $afterSecond->is_rack_mounted);
    $this->assertSame(1, Device::where('part_no', 'AM-3200-GV')->count(),
        'idempotent: no duplicate rows after re-running seeder');
}
```
For `HazardTemplateSeeder`, the equivalent assertion is
`HazardTemplate::where('is_global', true)->where('name', $name)->count() === 1`
after two consecutive `$this->seed(HazardTemplateSeeder::class)` calls, plus
an assertion that `is_global=false` (user-created) rows are untouched in row
count and content (the D-03 guarantee this analog doesn't need to cover but
this phase does).

## Metadata

**Analog search scope:** `app/Services/`, `app/Core/Modules/KnowledgeLibrary/`,
`app/Support/Rams/`, `app/Models/`, `database/migrations/`, `database/seeders/`,
`resources/views/pdf/`, `resources/views/rams/`, `tests/Feature/Rams/`,
`tests/Unit/Services/Rams/`, `tests/Feature/Drawings/` (seeder-test precedent)
**Files scanned:** 19 read directly (full or targeted) + 2 grepped for
line-location confirmation
**Pattern extraction date:** 2026-08-23
