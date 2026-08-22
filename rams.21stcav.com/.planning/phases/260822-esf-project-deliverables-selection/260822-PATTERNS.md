# Phase 260822-esf: Project Deliverables Selection - Pattern Map

**Mapped:** 2026-08-22
**Files analyzed:** 17 (new + modified)
**Analogs found:** 17 / 17

⚠ **Standing-hazard check performed.** RESEARCH.md's file pointers were spot-checked
against the live code before this document was written:
- `DeviceStencilAudit` write sites: RESEARCH.md cites one (`DeviceStencilController.php:231-240`).
  There are actually **three** (`:231`, `:360`, `:439` — edit/promote/discard-regenerate). All
  three are captured below since D-03's planner needs every call-site shape, not just one.
- `Project::factory()` **exists** (`database/factories/ProjectFactory.php`) even though
  `.planning/codebase/TESTING.md` states "Only User has a factory" (stale — see
  `tests/Feature/Projects/ActualHoursWidgetTest.php:30` using it live). Use the factory,
  not `Project::create([...])`, for any new project-show feature test — shorter and it's
  what the most-recently-written analog test in this exact area already does.
- The "Project Data" tab (D-10's stated destination) is currently a **read-only** canonical-data
  viewer (`show.blade.php:1816-1888`) — it has no settings-edit form today. The closest *write*
  analog for "edit something about a project from its show page" is the existing
  `POST projects/{project}/transition` action route + `ProjectController::transition()`
  method (see Pattern 6 below), not anything already living inside the Project Data tabpanel.
  Flagging this because CONTEXT.md's phrasing ("the tab that already exists for project-level
  settings") could be misread as "there's already an edit form there" — there is not.

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|---|---|---|---|---|
| `database/migrations/..._create_project_deliverables_table.php` | migration | CRUD (schema) | `database/migrations/2026_08_13_140000_..._create_device_stencil_audits.php` | role-match (schema+backfill shape) |
| `database/migrations/..._create_project_deliverable_audits_table.php` | migration | CRUD (schema) | same migration, `device_stencil_audits` block (lines 48-60) | exact |
| `database/migrations/..._backfill_project_deliverables_for_existing_projects.php` (D-17) | migration | batch/transform | same migration's backfill block (lines 62-70) | exact (same PHP-loop-not-raw-SQL constraint) |
| `app/Models/ProjectDeliverable.php` | model | CRUD | `app/Models/DeviceStencil.php` (needs_review boolean flag) + `app/Models/Project.php` ($fillable/$casts shape) | role-match |
| `app/Models/ProjectDeliverableAudit.php` | model | event-driven (append-only) | `app/Models/DeviceStencilAudit.php` | exact |
| `app/Services/ProjectDeliverablesService.php` | service | CRUD + audit | `app/Core/Modules/Projects/ProjectService.php` | exact (same DB::transaction + log-on-write shape) |
| `app/Models/Project.php` (edit: `canTransitionTo()`, D-11) | model | request-response (pure logic) | itself — `canTransitionTo()` lines 435-454 | exact (editing in place) |
| `app/Services/ProjectHealthService.php` (edit: D-12 filter) | service | transform | itself — `assess()` lines 32-81 | exact (editing in place) |
| `app/Core/Modules/QuoteImport/QuoteImportService.php` (edit: Hook 1, D-11/D-15) | service | event-driven | itself — `confirm()` lines 397-449 | exact (editing in place) |
| `app/Core/Modules/Survey/SurveyService.php` (edit: Hook 2, D-11, 2 call sites) | service | event-driven | itself — `complete()` ~452, `submitPublic()` ~556 | exact (editing in place) |
| `app/Http/Controllers/QuoteImportController.php` (edit: `confirm()`, D-16) | controller | request-response | itself — `confirm()` lines 116-191 | exact (editing in place) |
| `resources/views/quote-import/review.blade.php` (edit: checklist step, D-16) | component (Blade) | request-response (form) | itself — `<form id="confirmForm">` block, lines 53-80+ | exact (editing in place) |
| `app/Http/Controllers/ProjectController.php` (edit: `show()` D-07, new `updateDeliverables()` D-10) | controller | CRUD | itself — `transition()` lines 294-320 for the new action; `show()` lines 115-261 for D-07 reconciliation | exact (editing in place for `show()`; `transition()` is the template for the new method) |
| `resources/views/projects/show.blade.php` (edit: tab strip D-08/09, stepper Pitfall 2, Next-Step chain Pitfall 3, Project Data tab D-10) | component (Blade) | request-response | itself — tab strip lines 764-793, stepper lines 702-742, Next-Step chain lines 410-483 | exact (editing in place) |
| `routes/web.php` (edit: new `projects/{project}/deliverables` route) | route | request-response | itself — `projects/{project}/transition` route, line 231 | exact |
| `app/Policies/ProjectDeliverablePolicy.php` (new, optional — or extend `ProjectPolicy`) | middleware/policy | request-response | `app/Policies/WorksheetPolicy.php` | exact |
| `tests/Unit/ProjectTransitionTest.php` (edit — D-11 conditional cases) | test | request-response (pure) | itself | exact (editing in place) |
| `tests/Unit/ProjectHealthServiceTest.php` (edit — D-12 cases) | test | transform | itself | exact (editing in place) |
| `tests/Feature/ProjectAutoAdvanceTest.php` (edit — not-required-Survey skip cases) | test | event-driven | itself | exact (editing in place) |
| `tests/Unit/Services/ProjectDeliverablesServiceTest.php` (new) | test | CRUD | `tests/Unit/ProjectHealthServiceTest.php` (Laravel-with-DB unit style) | role-match |
| `tests/Feature/Projects/DeliverablesTabStripTest.php` or similar (new, D-08/D-09) | test | request-response | `tests/Feature/Projects/ActualHoursWidgetTest.php` | exact |
| `tests/Feature/Projects/DeliverableImportDefaultsTest.php` (new, D-15) | test | event-driven | `tests/Feature/ProjectAutoAdvanceTest.php` | role-match |

## Pattern Assignments

### 1. Audit-trail table + model (D-03) — mirror `device_stencil_audits` / `DeviceStencilAudit`, ADD `reason`

**Analog:** `database/migrations/2026_08_13_140000_add_needs_review_and_logo_path_to_device_stencils_and_create_device_stencil_audits.php`
+ `app/Models/DeviceStencilAudit.php`
+ **all three** write sites in `app/Http/Controllers/Admin/DeviceStencilController.php` (`:231`, `:360`, `:439`)

**Migration — schema block** (`2026_08_13_140000_...php:48-60`):
```php
Schema::create('device_stencil_audits', function (Blueprint $table) {
    $table->id();
    $table->foreignId('device_stencil_id')
        ->constrained('device_stencils')
        ->cascadeOnDelete();
    $table->foreignId('user_id')->constrained('users');
    // action: promote / edit / discard-regenerate (see
    // DeviceStencilAudit::ACTION_* constants).
    $table->string('action', 30);
    $table->json('before_snapshot')->nullable();
    $table->json('after_snapshot')->nullable();
    $table->timestamps();
});
```
**GAP vs D-03 (confirmed, not just RESEARCH's claim):** no `reason` column. Add
`$table->text('reason')->nullable();` for `project_deliverable_audits` — D-03 requires
"who, when, and why" with why (reason) explicitly optional free text.

**Backfill portability comment to copy verbatim in spirit** (`2026_08_13_140000_...php:28-33`):
```php
/**
 * Backfill (Pitfall 1 — MUST be PHP, never raw SQL JSON functions): this
 * migration runs against MariaDB in production and SQLite :memory: under the
 * test suite (phpunit.xml). Raw `JSON_EXTRACT(...)` / `->>` syntax diverges
 * across those two engines — looping in PHP with json_decode() is portable
 * everywhere.
 */
```
**Backfill loop pattern** (`2026_08_13_140000_...php:62-70`):
```php
DB::table('device_stencils')->get()->each(function ($row) {
    $metadata = json_decode((string) $row->metadata, true) ?: [];
    if (($metadata['needs_phase_24_curation'] ?? false) === true) {
        DB::table('device_stencils')->where('id', $row->id)->update(['needs_review' => true]);
    }
});
```
Apply this exact shape for D-17's backfill migration (loop over `Project::query()->get()`,
infer per-deliverable state from `->siteSurveys`/`->ramsDocuments`/etc. `->count()`, write
`project_deliverables` rows in the PHP loop — no raw SQL, no `JSON_EXTRACT`).

**`down()` — index-then-column drop order** (`2026_08_13_140000_...php:73-87`):
```php
public function down(): void
{
    Schema::dropIfExists('device_stencil_audits');

    // Drop the index explicitly before dropping its column — SQLite's
    // table-rebuild-based ALTER TABLE otherwise leaves a dangling index
    // definition pointing at the just-dropped column.
    Schema::table('device_stencils', function (Blueprint $table) {
        $table->dropIndex(['needs_review']);
    });

    Schema::table('device_stencils', function (Blueprint $table) {
        $table->dropColumn(['needs_review', 'logo_path']);
    });
}
```
This SQLite-vs-MariaDB drop-order gotcha applies to any new indexed boolean column on
`projects` (if the schema-shape decision goes that way instead of a dedicated table) —
copy the "drop index, then drop column, in separate `Schema::table()` calls" idiom.

**Model — full shape to mirror** (`app/Models/DeviceStencilAudit.php`):
```php
class DeviceStencilAudit extends Model
{
    public const ACTION_PROMOTE = 'promote';
    public const ACTION_EDIT = 'edit';
    public const ACTION_DISCARD_REGENERATE = 'discard-regenerate';

    protected $fillable = [
        'device_stencil_id', 'user_id', 'action', 'before_snapshot', 'after_snapshot',
    ];

    protected $casts = [
        'before_snapshot' => 'array',
        'after_snapshot'  => 'array',
    ];

    public function stencil(): BelongsTo { return $this->belongsTo(DeviceStencil::class, 'device_stencil_id'); }
    public function user(): BelongsTo { return $this->belongsTo(\App\Models\User::class); }
}
```
For `ProjectDeliverableAudit`: `$fillable` becomes
`['project_deliverable_id', 'user_id', 'action', 'reason', 'before_snapshot', 'after_snapshot']`,
`ACTION_*` becomes something like `ACTION_MARK_REQUIRED` / `ACTION_MARK_NOT_REQUIRED` /
`ACTION_AUTO_FLIP` (D-02's soft-gate auto-flip is itself an audit-worthy action — do not
let it write silently).

**Write-site pattern — all THREE sites share this shape** (`DeviceStencilController.php:231-240`,
also `:360-368`, `:439-446`):
```php
DeviceStencilAudit::create([
    'device_stencil_id' => $deviceStencil->id,
    'user_id'           => auth()->id(),
    'action'            => DeviceStencilAudit::ACTION_EDIT,
    'before_snapshot'   => $beforeSnapshot,
    'after_snapshot'    => [
        'mxgraph_xml' => $payload['mxgraph_xml'],
        'ports'       => $validatedPorts,
    ],
]);
```
Every one of the three call sites captures a `$beforeSnapshot` **before** the mutating
`update()` call and an `after_snapshot` **after** it, inside the same DB transaction as the
model write. `ProjectDeliverablesService` must follow this exactly: snapshot before/after
JSON (e.g. `['state' => 'required']` → `['state' => 'not_required']`), write the model update
and the audit row in one `DB::transaction()`.

---

### 2. Per-project settings store — no direct precedent; two real options, pick dedicated table

**What exists today (read-only precedent, NOT a settings-CRUD analog):** `Project.metadata`
is a JSON column (`$casts['metadata'] => 'array'`, `app/Models/Project.php:143`) already used
for two unrelated ad-hoc project-scoped flags:
```php
// app/Services/Drawings/SheetPaginator.php:106
$raw = is_array($project->metadata) ? ($project->metadata['force_sheets'] ?? null) : null;
// app/Services/Drawings/TitleBlockRenderer.php:69
$checkedBy = (string) ($metadata['drawing_checked_by'] ?? '—');
```
This is a **read-only, unvalidated, no-audit** JSON bag — explicitly documented elsewhere in
this codebase (Phase 24 D-03 migration docblock, quoted in RESEARCH.md) as inadequate for
anything that needs history: *"metadata only ever holds the LAST edit, not full history."*
D-01/D-03 need three-state-per-deliverable data **with** an audit trail — `metadata` is
disqualified by the codebase's own prior decision, not just by researcher opinion.

**Recommendation (matches RESEARCH.md's Established Patterns note):** dedicated
`project_deliverables` table, one row per `(project_id, deliverable_key)`, mirroring the
`device_stencils` "flag lives on a real column, not a JSON blob" shape — see
`device_stencils.needs_review` in the same migration (Pattern 1), which is exactly this
precedent already applied once in this codebase (D-10 of Phase 24, cited in that migration's
own docblock at lines 12-16 for the reason a real column beat a metadata flag: MariaDB can't
index a JSON extract).

```php
Schema::create('project_deliverables', function (Blueprint $table) {
    $table->id();
    $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
    $table->string('deliverable_key', 30); // site_survey|rams|worksheet|om|cable_schedule|install_programme|drawings|snagging|programming
    $table->string('state', 20)->default('not_yet_decided')->index(); // required|not_required|not_yet_decided
    $table->timestamps();
    $table->unique(['project_id', 'deliverable_key']);
});
```
This unique constraint + indexed `state` column is what makes D-12's health filter and
D-13's amber-grace-period query performant without a JSON scan — same rationale the
`needs_review` migration docblock gives for its own indexed boolean.

---

### 3. Tab strip rendering (D-08/D-09) — `show.blade.php:762-793`

**Analog:** itself, in place. Full current 9-entry array + render loop:
```php
// show.blade.php:763-775
$tabs = [
    ['key' => 'surveys',    'label' => 'Surveys',           'count' => $countSurvey],
    ['key' => 'rams',       'label' => 'RAMS',              'count' => $countRams],
    ['key' => 'worksheets', 'label' => 'Worksheets',        'count' => $countWorksheet],
    ['key' => 'cable',      'label' => 'Cable Schedule',    'count' => $countCable],
    ['key' => 'om',         'label' => 'O&M',               'count' => $countOm],
    ['key' => 'install',    'label' => 'Install Programme', 'count' => $countInstall],
    ['key' => 'quotes',     'label' => 'Quotes',            'count' => $countQuotes],
    ['key' => 'assets',     'label' => 'Asset Register',    'count' => $countAssets],
    ['key' => 'data',       'label' => 'Project Data',      'count' => null],
];
```
```blade
{{-- show.blade.php:776-792 --}}
@foreach ($tabs as $t)
    <button type="button" role="tab" class="ws-tab"
            @click="setTab('{{ $t['key'] }}')"
            :class="activeTab==='{{ $t['key'] }}' ? 'is-active' : ''"
            :aria-selected="activeTab==='{{ $t['key'] }}'">
        <span class="ws-tab__label">{{ $t['label'] }}</span>
        @if ($t['count'] !== null)
            <span class="ws-tab__count {{ $t['count'] === 0 ? 'ws-tab__count--empty' : '' }}">{{ $t['count'] }}</span>
        @endif
    </button>
@endforeach
```
**The muted-count class to reuse for "Not required" styling:** `ws-tab__count--empty`
(already renders a visually de-emphasised "0" pill — the same visual language D-08 wants
for a not-required tab, just applied for a different reason).

**The comment you MUST read before touching this block** (`show.blade.php:776-782`):
```blade
{{-- Re-audit UX-05 — was `@if($count > 0)` gate, so on a
     fresh project 7/9 tabs rendered label-only and the
     user couldn't tell which held data. Now render the
     count pill unconditionally (muted "0" for empties);
     populated tabs still pop via the accent-50 fill
     when active. Project Data has no count → skip. --}}
```
This is the exact regression D-08/D-09 must not reproduce — **do not** wrap any tab in
`@if($count > 0)` or an equivalent "hide if not required AND empty" conditional. D-09's
"a deliverable holding data is never hidden" rule is directly enforceable by checking
`$t['count'] > 0` before applying the "Not required" muted grouping, never by omitting
the tab from the `$tabs` array.

**Reorder hazard — Alpine `setTab`/localStorage persistence** (`show.blade.php:754-757`):
```blade
<div x-data="{
        activeTab: (localStorage.getItem('psv-tab-{{ $project->id }}') || '{{ $defaultTab }}'),
        q: '',
        setTab(t) { this.activeTab = t; localStorage.setItem('psv-tab-{{ $project->id }}', t); }
     }" class="ws">
```
Persistence is keyed by `$t['key']` string (`'surveys'`, `'rams'`, etc.), **not** by array
index — reordering the `$tabs` array for D-08's grouping is safe as long as the `key`
strings are unchanged. Renaming or removing a key (e.g. if D-04's Drawings/Snagging/
Programming additions get different key names than their eventual tab implementations use)
will silently orphan a user's stored `localStorage` value, which just falls back to
`$defaultTab` — not a hard break, but confirm new keys match whatever key the new
Drawings/Snagging tabpanels use.

**Where `$countX` variables come from** (`show.blade.php:386-397`, all in-memory counts on
already-eager-loaded relations, no extra queries):
```php
$countRams       = $project->ramsDocuments->count();
$countWorksheet  = $project->worksheets->count();
$countSurvey     = $project->siteSurveys->count();
$countOm         = $project->omManuals->count();
$countCable      = $project->cableSchedules->count();
$countInstall    = $project->installProgrammes->count();
$countQuotes     = $project->projectQuotes->count();
$countDrawings   = $project->drawings()->whereNull('superseded_by_id')->count(); // NOT eager-loaded — live query
$countAssets     = \App\Models\Device::where('project_id', $project->id)->count(); // NOT eager-loaded — live query
```
Note `$countDrawings`/`$countAssets` already exist and are **not** eager-loaded (they run a
live query each). D-04 adding Drawings as a real tab means this count is already computed —
no new count logic needed there. Snagging and Programming have no equivalent count variable
today; Programming never will (D-05, no generator/model), Snagging needs
`$project->snagging()->count()` or equivalent once its relation exists.

---

### 4. Multi-step confirm flow (D-16) — quote-import wizard

**Analog:** `app/Http/Controllers/QuoteImportController.php` (4-step wizard) +
`resources/views/quote-import/review.blade.php` (Step 3 view, posts to Step 4).

**The 4 steps, controller methods and views** (`QuoteImportController.php`):
| Step | Method | View | Route name |
|---|---|---|---|
| 1. Upload form | `create()` (`:26-38`) | `quote-import.create` | `quote-import.create` |
| 2. Store + dispatch async extraction | `store()` (`:42-61`) | (redirect only) | `quote-import.store` |
| 3. Review extracted data | `review()` (`:100-112`) | `quote-import.review` | `quote-import.review` |
| 4. Confirm + link to project | `confirm()` (`:116-191`) | (redirect only) | `quote-import.confirm` |

**Step 3→4 is a single form, single POST — no separate "step" page exists structurally**
(`review.blade.php:53-80`):
```blade
<form method="POST" action="{{ route('quote-import.confirm', $package) }}" id="confirmForm">
    @csrf
    <div class="form-grid-2">
        <div class="form-group" style="grid-column:span 2;">
            <label class="form-label" for="name">Project Name <span class="req">*</span></label>
            <input id="name" name="name" type="text"
                   class="form-control @error('name') is-invalid @enderror"
                   value="{{ old('name', $data['project_name'] ?? $package->project?->name ?? '') }}"
                   required>
            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        {{-- ref / client_name / site_address / works_description follow the same pattern --}}
    </div>
</form>
```
**Implication for D-16:** "a step in the existing confirm flow" is most naturally a new
`<fieldset>` section appended inside this SAME `#confirmForm` (submitted together with the
existing fields, not a 5th page/route) — this is the lowest-diff way to satisfy "not a modal
layered on the review screen" while staying inside the existing wizard's actual structure
(one form per step, not one page per field-group). If the plan instead wants a genuinely
separate 5th route/page, that is a bigger structural change than CONTEXT.md's phrasing implies
and should be called out explicitly as a deviation.

**`confirm()` controller method — where checklist values would be validated/read**
(`QuoteImportController.php:116-129`):
```php
public function confirm(Request $request, ProjectPackage $package): RedirectResponse
{
    $this->authorizePackage($package);
    $validated = $request->validate([
        'name'              => ['required', 'string', 'max:200'],
        'ref'               => ['nullable', 'string', 'max:50'],
        'client_name'       => ['required', 'string', 'max:150'],
        'site_address'      => ['required', 'string', 'max:500'],
        'works_description' => ['nullable', 'string'],
        'project_id'        => ['nullable', 'integer', 'exists:projects,id'],
        'new_project'       => ['nullable', 'boolean'],
    ]);
    // ...
```
New deliverable-checklist fields (e.g. `deliverables[site_survey]`, `deliverables[rams]`, ...)
would be validated here alongside the existing fields, following the same `$request->validate()`
array shape — `['nullable', 'in:required,not_required,not_yet_decided']` per key.

**D-15 default-derivation signal — where to read it, verified live** (research-cited, confirmed
present at `app/Services/Imports/EquipmentCategoryClassifier.php:224-266`):
```php
$hasServiceLine = collect($package->extracted_data['equipment'] ?? $package->equipment_list ?? [])
    ->contains(fn ($row) => strtolower(trim((string) ($row['category'] ?? ''))) === 'services');
// $hasServiceLine === false → RAMS, Worksheet, Survey all default to Not required (D-15)
```
This should be computed server-side in `review()` (Step 3, `QuoteImportController.php:100-112`)
so the checklist step's initial/default field values are correct on first render — not
recomputed in `confirm()`.

**QuoteImportService::confirm() Hook 1 — where D-11's skip-survey-pending branch lands**
(`app/Core/Modules/QuoteImport/QuoteImportService.php:423-444`):
```php
$linkedProject = $confirmed->project;
if (
    $linkedProject?->status === Project::STATUS_QUOTE_IMPORTED &&
    $linkedProject->canTransitionTo(Project::STATUS_SURVEY_PENDING)
) {
    try {
        $this->projectService->transition($linkedProject, Project::STATUS_SURVEY_PENDING, $user);
    } catch (\InvalidArgumentException) {
        Log::warning('QuoteImportService: auto-advance to survey_pending skipped', [
            'project_id'  => $linkedProject->id,
            'from_status' => $linkedProject->status,
        ]);
    }
}
```
D-11 requires a parallel branch here: when Survey is `Not required`, transition straight to
`Project::STATUS_ENGINEERING` instead. Guard shape (try/catch swallow, `Log::warning` on
failure, never throw out of `confirm()`) must be preserved exactly — this is the same
defence-in-depth pattern used by both of `SurveyService`'s hooks (Pattern 5).

---

### 5. Status-machine skip logic (D-11) — `Project::canTransitionTo()` + the two auto-advance hooks

**Analog:** itself, in place — `app/Models/Project.php:435-454`:
```php
public function canTransitionTo(string $status): bool
{
    if ($status === self::STATUS_ARCHIVED) {
        return $this->status !== self::STATUS_ARCHIVED;
    }
    if ($this->status === self::STATUS_ARCHIVED) {
        return false;
    }
    if (in_array($status, self::TRANSITIONS[$this->status] ?? [])) {
        return true;
    }
    return in_array($status, self::TRANSITIONS_BACKWARD[$this->status] ?? []);
}
```
**Constants it reads** (`Project.php:49-57, 65-71`):
```php
const TRANSITIONS = [
    self::STATUS_QUOTE_IMPORTED => [self::STATUS_SURVEY_PENDING],
    self::STATUS_SURVEY_PENDING => [self::STATUS_ENGINEERING],
    self::STATUS_ENGINEERING    => [self::STATUS_INSTALLING],
    // ...
];
const TRANSITIONS_BACKWARD = [
    self::STATUS_SURVEY_PENDING => [self::STATUS_QUOTE_IMPORTED],
    self::STATUS_ENGINEERING    => [self::STATUS_SURVEY_PENDING],
    // ...
];
```
**Do not** add `self::STATUS_QUOTE_IMPORTED => [self::STATUS_SURVEY_PENDING, self::STATUS_ENGINEERING]`
unconditionally to `TRANSITIONS` — confirmed live in `tests/Unit/ProjectTransitionTest.php:91-97`,
a test explicitly pins the OPPOSITE behaviour today:
```php
public function test_cannot_transition_to_completely_skipped_state(): void
{
    // Cannot jump from quote_imported directly to engineering (skipping survey_pending)
    $project = new Project(['status' => Project::STATUS_QUOTE_IMPORTED]);
    $this->assertFalse($project->canTransitionTo(Project::STATUS_ENGINEERING));
}
```
This test must become conditional (only false when Survey IS required), not deleted —
per RESEARCH.md's Test Map. `canTransitionTo()` needs to become instance-aware (consult
`$this->deliverables` or take an explicit parameter), per RESEARCH's Don't-Hand-Roll section.

**The transition choke point everything must go through** (`app/Core/Modules/Projects/ProjectService.php:63-99`):
```php
public function transition(Project $project, string $toStatus, User $user, ?string $note = null): Project
{
    if (! $project->canTransitionTo($toStatus)) {
        throw new InvalidArgumentException(
            "Cannot transition project #{$project->id} from '{$project->status}' to '{$toStatus}'."
        );
    }
    return DB::transaction(function () use ($project, $toStatus, $user, $note) {
        $fromStatus = $project->status;
        $timestamp  = $this->milestoneColumn($toStatus);
        $updates = ['status' => $toStatus];
        if ($timestamp) { $updates[$timestamp] = now(); }
        $project->update($updates);
        $this->log(/* ... ACTION_STATUS_CHANGED ... */);
        return $project->fresh();
    });
}
```
`milestoneColumn()` (`ProjectService.php:257-269`) maps `STATUS_SURVEY_PENDING` →
`survey_started_at`. When D-11 skips straight to `STATUS_ENGINEERING`, this method naturally
sets `engineering_started_at` and skips `survey_started_at` — which is exactly correct per
D-11 ("the stage genuinely never happened"). No change needed to `transition()` itself, only
to `canTransitionTo()`'s guard.

**Hook 2 — SurveyService, TWO call sites, same shape, both need the D-11 change:**
```php
// app/Core/Modules/Survey/SurveyService.php — complete() ~line 452
if ($project->canTransitionTo(Project::STATUS_ENGINEERING)) {
    try {
        $this->projects->transition($project, Project::STATUS_ENGINEERING, $user);
    } catch (\InvalidArgumentException) { /* log + swallow */ }
}
// submitPublic() ~line 556 — identical shape, separate call site
```
Once Hook 1 sends a not-required-Survey project straight to `STATUS_ENGINEERING`, these
two Hook-2 call sites become no-ops for that project (already past `survey_pending`) —
confirm this with a test, don't assume it silently.

---

### 6. Health calculation filter (D-12) — `ProjectHealthService::assess()`

**Analog:** itself, in place — `app/Services/ProjectHealthService.php:32-81`. Full method
already read; RED-branch guards that need a `$this->isRequired($project, 'rams')` /
`isRequired($project, 'survey')` AND clause:
```php
// :50-53 — needs `&& $this->isRequired($project, 'rams')` guard
if ($project->status === Project::STATUS_ENGINEERING
    && $rams->whereIn('status', $this->approvedOrBeyond())->isEmpty()) {
    return new ProjectHealth('red', 'No approved RAMS in engineering', $overdue);
}
// :55-60 — needs `&& $this->isRequired($project, 'survey')` guard
if ($project->status === Project::STATUS_SURVEY_PENDING
    && $surveys->filter(fn (SiteSurvey $s) => $s->isSubmitted())->isEmpty()
    && $stageStart !== null
    && Carbon::now()->diffInDays($stageStart, false) < -14) {
    return new ProjectHealth('red', 'Survey overdue — no submission', true);
}
```
**Hard constraint stated in the class docblock** (`ProjectHealthService.php:12-14`):
> "MUST NOT call `$project->relation()->get()` or issue any additional DB queries — caller is
> responsible for eager-loading."

This means the new `deliverables` relation MUST be added to the sole caller's eager-load list
— `app/Http/Controllers/DashboardController.php:55-63` — alongside the existing
`ramsDocuments`/`siteSurveys`, and `isRequired()` must read `$project->deliverables` (already
loaded), never `$project->deliverables()->where(...)->first()`.

**Test analog for D-12 cases** — `tests/Unit/ProjectHealthServiceTest.php` uses
`setRelation()` to build fixtures without hitting the DB, e.g.:
```php
$project->setRelation('ramsDocuments', collect([$this->makeRams(RamsDocument::STATUS_FAILED)]));
```
New D-12 tests should do the same with `setRelation('deliverables', collect([...]))`.

---

### 7. New write endpoint (D-10 edit) — mirror `ProjectController::transition()`

**Analog:** `app/Http/Controllers/ProjectController.php:294-320` (`transition()`) is the
closest existing "single-purpose POST action off a project, validate + call service + flash +
redirect back to show" shape — closer than `update()` (which patches core project fields, not
a sub-resource) because `transition()` is itself a status-affecting, audit-logged side-effect
action, same as a deliverable-flag flip will be.
```php
public function transition(Request $request, Project $project): RedirectResponse
{
    $validated = $request->validate([
        'to_status' => ['required', 'string'],
        'note'      => ['nullable', 'string', 'max:500'],
    ]);
    try {
        $this->service->transition($project, $validated['to_status'], auth()->user(), $validated['note'] ?? null);
    } catch (\InvalidArgumentException $e) {
        return back()->with('error', $e->getMessage());
    }
    $label = Project::STATUS_LABELS[$validated['to_status']] ?? $validated['to_status'];
    return redirect()->route('projects.show', $project)->with('success', "Project advanced to: {$label}.");
}
```
**Matching route** (`routes/web.php:231`):
```php
Route::post('projects/{project}/transition', [ProjectController::class, 'transition'])->name('projects.transition');
```
A new `POST projects/{project}/deliverables` route + `ProjectController::updateDeliverables()`
(or a dedicated small controller if the diff grows large — planner's call) should follow this
exact shape: validate `deliverables[key] => state` (+ optional `reason`), call
`ProjectDeliverablesService::setState(...)` per changed key (D-02's soft-gate auto-flip and
D-03's audit both live in that service, not the controller), flash + redirect to
`projects.show`.

**Authorization pattern to reuse** (`ProjectController.php:410-413`):
```php
private function authorizeProject(Project $project): void
{
    abort_unless(auth()->check(), 403); // Shared workspace: any authenticated user has full access.
}
```
And the dedicated-policy alternative, if the plan prefers a Gate/Policy over an inline
`abort_unless` (RESEARCH.md's Security Domain section recommends this) — full analog,
`app/Policies/WorksheetPolicy.php`:
```php
class WorksheetPolicy
{
    public function view(User $user, Worksheet $worksheet): bool { return true; }
    public function update(User $user, Worksheet $worksheet): bool { return true; }
    public function delete(User $user, Worksheet $worksheet): bool { return true; }
}
```
Every method returns `true` (permissive shared-workspace by design) — this is the
established pattern for "the surface exists so per-user rules CAN land in one place later,"
not evidence that authorization is being skipped.

---

## Shared Patterns

### Audit-on-write (D-03)
**Source:** `app/Http/Controllers/Admin/DeviceStencilController.php:231-240` (×3 sites)
**Apply to:** `ProjectDeliverablesService` — every state-changing method (manual flip,
D-02 auto-flip-on-create, D-17 backfill) writes a `ProjectDeliverableAudit` row inside the
same `DB::transaction()` as the state change, capturing before/after snapshot + actor +
optional reason.

### Lifecycle logging choke point
**Source:** `app/Core/Modules/Projects/ProjectService.php:211-229` (`log()`)
**Apply to:** Any code that changes `Project::status` — always via
`ProjectService::transition()`, never `$project->update(['status' => ...])` directly. This
house rule is stated in the class docblock (`ProjectService.php:14-15`): *"All status
transitions MUST go through this service. Direct model updates that bypass this service
will not produce activity log entries."*

### Defensive auto-advance hooks (swallow, log, never throw)
**Source:** `QuoteImportService.php:432-443`, `SurveyService.php` ×2
**Apply to:** The new D-11 skip-to-engineering branch in Hook 1, and the now-no-op Hook 2
call sites — same `try { transition() } catch (\InvalidArgumentException) { Log::warning(...) }`
shape so a lifecycle side-effect failure never blocks the primary action (quote confirm /
survey submit).

### Eager-load discipline for pure services
**Source:** `ProjectHealthService.php:12-14` docblock + `DashboardController.php:55-63`
**Apply to:** `ProjectHealthService::assess()` (D-12) and any other pure function over
`Project` relations — add `deliverables` to the caller's eager-load list; never lazy-load
inside the service.

### Blade compile verification (project house rule, not just this phase)
**Source:** `.planning/codebase/TESTING.md` is silent on this; it's stated directly in
RESEARCH.md and CLAUDE.md-adjacent phase instructions: `blade.compiler->compileString()`
must be run against every touched Blade file, not just `php -l`. Precedent: a JS comment in
a shared component broke Blade compilation site-wide while `php -l` passed clean (260817-jsg).
Any edit to `show.blade.php` or `review.blade.php` in this phase must be verified this way.

## No Analog Found

None. Every file in RESEARCH.md's Recommended Project Structure has a same-repo precedent
either as an exact in-place edit (Project.php, ProjectHealthService.php, QuoteImportService.php,
SurveyService.php, show.blade.php, review.blade.php, QuoteImportController.php,
ProjectController.php) or a close cross-domain analog (DeviceStencilAudit for the new audit
table/model, ProjectService for the new deliverables service, ProjectController::transition()
for the new write endpoint, WorksheetPolicy for authorization, ActualHoursWidgetTest for the
Blade-render test shape).

## Metadata

**Analog search scope:** `app/Models/`, `app/Services/`, `app/Core/Modules/`,
`app/Http/Controllers/`, `app/Policies/`, `database/migrations/`, `resources/views/projects/`,
`resources/views/quote-import/`, `tests/Unit/`, `tests/Feature/`.
**Files scanned:** ~28 (direct reads) across the above directories, plus targeted greps for
`DeviceStencilAudit::create`, `canTransitionTo`, `metadata`, route definitions, and existing
test files using `assertSee`/factories.
**Pattern extraction date:** 2026-08-22
