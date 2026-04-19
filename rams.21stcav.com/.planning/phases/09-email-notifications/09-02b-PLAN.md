---
phase: 09-email-notifications
plan: 02b
type: execute
wave: 2
depends_on: ["09-01"]
files_modified:
  - database/factories/RamsDocumentFactory.php
  - database/factories/OmManualFactory.php
  - database/factories/WorksheetFactory.php
  - database/factories/CableScheduleFactory.php
autonomous: true
requirements: [NOTF-01a, NOTF-01b, NOTF-02a, NOTF-04a]

must_haves:
  truths:
    - "`database/factories/RamsDocumentFactory.php` exists and produces a valid `RamsDocument` model via `RamsDocument::factory()->make()` (status defaults to STATUS_AWAITING_REVIEW so completion-flip tests can transition it). HasFactory trait was added to RamsDocument by plan 09-01 Task 3."
    - "`database/factories/OmManualFactory.php` exists and produces a valid `OmManual` model via `OmManual::factory()->make()` (status defaults to STATUS_GENERATING so completion-flip tests can transition it to STATUS_DRAFT)"
    - "`database/factories/WorksheetFactory.php` exists and produces a valid `Worksheet` model via `Worksheet::factory()->make()` (status defaults to STATUS_GENERATING)"
    - "`database/factories/CableScheduleFactory.php` exists and produces a valid `CableSchedule` model via `CableSchedule::factory()->make()` (status defaults to STATUS_GENERATING; uses `source_filename` not `filename`). HasFactory trait was added to CableSchedule by plan 09-01 Task 3."
    - "Each factory references the existing `User::factory()` and `Project::factory()` for `user_id` / `project_id` foreign keys, so feature tests can compose owner-relationship chains without manual seeding"
  artifacts:
    - path: "database/factories/RamsDocumentFactory.php"
      provides: "RamsDocument factory for plan 09-05 feature tests"
      contains: "class RamsDocumentFactory"
    - path: "database/factories/OmManualFactory.php"
      provides: "OmManual factory for plan 09-05 feature tests"
      contains: "class OmManualFactory"
    - path: "database/factories/WorksheetFactory.php"
      provides: "Worksheet factory for plan 09-05 feature tests"
      contains: "class WorksheetFactory"
    - path: "database/factories/CableScheduleFactory.php"
      provides: "CableSchedule factory for plan 09-05 feature tests (source_filename, not filename)"
      contains: "source_filename"
  key_links:
    - from: "plan 09-05 Task 2 (notification feature tests)"
      to: "the 4 new factories"
      via: "`RamsDocument::factory()->create([...])` and equivalent for the other 3 models"
      pattern: "::factory\\(\\)"
    - from: "plan 09-01 Task 3 (HasFactory trait + model fillable wiring — completed in Wave 1)"
      to: "RamsDocumentFactory + CableScheduleFactory"
      via: "HasFactory trait on the model classes makes `Model::factory()` static call resolve to these factory files via Laravel's `Factory::resolveFactoryName()` convention"
      pattern: "use HasFactory"
---

<objective>
Create the four model factories that plan 09-05 Task 2 (notification feature tests)
depend on. The codebase currently has only `ProjectFactory` and `UserFactory`. Without
these four new factories, plan 09-05's executor would either invent factory shapes
mid-task (error-prone) or copy the factory creation into the feature test setup
(duplicated across 4+ test files).

**Wave & dependency change (per checker B-01):** This plan is now Wave 2 with
`depends_on: ["09-01"]` because 09-01 Task 3 owns the model file edits — including the
HasFactory trait additions on RamsDocument and CableSchedule. Running 09-02b in Wave 1
would collide with 09-01 on `app/Models/RamsDocument.php` and `app/Models/CableSchedule.php`.
Now this plan only writes to `database/factories/` (zero overlap with anything else in
Wave 2 — 09-03 mailables and 09-04 mailables touch `app/Mail/` and `resources/views/emails/`,
disjoint from `database/factories/`).

Purpose: Address checker issue I-02 (factories required for plan 09-05 feature tests).
Factory wiring is small enough to ship in one short, focused task.

Output: Four factory files under `database/factories/`. Each instantiates cleanly
via `Model::factory()->make()` (does not need DB) and `Model::factory()->create()`
(needs DB; relies on User + Project factories for FK resolution).
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/ROADMAP.md
@.planning/STATE.md
@.planning/phases/09-email-notifications/09-CONTEXT.md
@.planning/phases/09-email-notifications/09-RESEARCH.md
@.planning/phases/09-email-notifications/09-VALIDATION.md
@.planning/phases/09-email-notifications/09-01-SUMMARY.md

@app/Models/RamsDocument.php
@app/Models/OmManual.php
@app/Models/Worksheet.php
@app/Models/CableSchedule.php
@database/factories/ProjectFactory.php
@database/factories/UserFactory.php

<interfaces>
<!-- Existing factory pattern to mirror -->
From database/factories/ProjectFactory.php / UserFactory.php:
- Namespace: `Database\Factories`
- Extends `Illuminate\Database\Eloquent\Factories\Factory`
- Property: `protected $model = ModelClass::class;`
- Method: `public function definition(): array { return [ ... ]; }`

<!-- Required model traits — 09-01 Task 3 (Wave 1) ALREADY added HasFactory where needed -->
- OmManual: `use HasFactory, SoftDeletes;` ✓ (pre-existing)
- Worksheet: `use HasFactory, SoftDeletes;` ✓ (pre-existing)
- RamsDocument: `use HasFactory, SoftDeletes;` ✓ (added by 09-01 Task 3)
- CableSchedule: `use HasFactory, SoftDeletes;` ✓ (added by 09-01 Task 3)

If 09-01 Task 3 has NOT yet shipped when this plan starts (e.g., wave-execution
ordering broken), the executor MUST stop and resolve the dependency — DO NOT add the
HasFactory trait here as an emergency workaround; that re-introduces the file-collision
that B-01 was raised to prevent. The acceptance criteria explicitly check the trait
exists on RamsDocument and CableSchedule before any factory work proceeds.

<!-- Field shapes (from $fillable inspection AFTER 09-01 Task 3 wiring) -->
RamsDocument $fillable: user_id, project_id, project_ref, project_name, client_name, site_address, ai_provider, ai_model, form_data, extracted_data, reviewed_data, generated_data, filename, status, error_message, email_sent_at, approved_at, approved_by, superseded_by_id, completion_email_sent_at, failed_email_sent_at, review_needed_email_sent_at
OmManual $fillable: user_id, project_id, rams_document_id, project_name, project_ref, client_name, site_address, source_filename, source_path, status, error_message, extracted_data, generated_data, filename, completion_email_sent_at, failed_email_sent_at
Worksheet $fillable: user_id, project_id, project_name, project_ref, client_name, site_address, status, error_message, generated_data, filename, completion_email_sent_at, failed_email_sent_at
CableSchedule $fillable: user_id, project_id, project_name, project_ref, client_name, source_filename, status, completion_email_sent_at, failed_email_sent_at, error_message

<!-- Status defaults — pick the pre-completion state so feature tests can transition forward -->
- RamsDocument: STATUS_AWAITING_REVIEW (jobs flip to STATUS_COMPLETED) — chosen because plan 09-05 review-needed test needs this exact starting status
- OmManual: STATUS_GENERATING (jobs flip to STATUS_DRAFT)
- Worksheet: STATUS_GENERATING (jobs flip to STATUS_DRAFT)
- CableSchedule: STATUS_GENERATING (jobs flip to STATUS_DRAFT)
</interfaces>
</context>

<tasks>

<task type="auto" tdd="false">
  <name>Task 1: Create the 4 model factories (HasFactory trait already present courtesy of 09-01 Task 3)</name>
  <files>database/factories/RamsDocumentFactory.php, database/factories/OmManualFactory.php, database/factories/WorksheetFactory.php, database/factories/CableScheduleFactory.php</files>
  <read_first>
    - database/factories/ProjectFactory.php (canonical factory shape — namespace, model property, definition() method, faker usage)
    - database/factories/UserFactory.php (second example — see how relationships are NOT pre-resolved; tests pass FK explicitly)
    - app/Models/RamsDocument.php — confirm `use HasFactory, SoftDeletes;` is present (added by 09-01 Task 3 in Wave 1). If absent, STOP and verify 09-01 actually shipped before continuing.
    - app/Models/CableSchedule.php — same trait check
    - app/Models/OmManual.php / Worksheet.php — confirm they have HasFactory (pre-existing — was always present)
    - CHECKER ISSUE B-01 in revision context — explains why this plan no longer touches model files (file-collision avoidance)
  </read_first>
  <action>
**Pre-flight check (B-01 enforcement):** Before creating any factory file, run:
```
grep -q "use HasFactory" app/Models/RamsDocument.php || (echo "BLOCKED: 09-01 Task 3 has not added HasFactory to RamsDocument — cannot proceed without re-introducing B-01 file collision" && exit 1)
grep -q "use HasFactory" app/Models/CableSchedule.php || (echo "BLOCKED: 09-01 Task 3 has not added HasFactory to CableSchedule — cannot proceed without re-introducing B-01 file collision" && exit 1)
```
If either check fails, the executor must surface this as a wave-ordering bug and let the orchestrator re-sequence. Do NOT add the trait here as a workaround — that recreates B-01.

**Step 1 — Create `database/factories/RamsDocumentFactory.php`:**

```php
<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\RamsDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class RamsDocumentFactory extends Factory
{
    protected $model = RamsDocument::class;

    public function definition(): array
    {
        return [
            'user_id'       => User::factory(),
            'project_id'    => Project::factory(),
            'project_ref'   => fake()->bothify('##C?#####'),                  // e.g., 21CQ30017-style
            'project_name'  => fake()->company() . ' AV Refresh',
            'client_name'   => fake()->company(),
            'site_address'  => fake()->address(),
            'filename'      => 'rams-' . fake()->uuid() . '.docx',
            'status'        => RamsDocument::STATUS_AWAITING_REVIEW,
        ];
    }
}
```

**Step 2 — Create `database/factories/OmManualFactory.php`:**

```php
<?php

namespace Database\Factories;

use App\Models\OmManual;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class OmManualFactory extends Factory
{
    protected $model = OmManual::class;

    public function definition(): array
    {
        return [
            'user_id'       => User::factory(),
            'project_id'    => Project::factory(),
            'project_ref'   => fake()->bothify('##C?#####'),
            'project_name'  => fake()->company() . ' AV Refresh',
            'client_name'   => fake()->company(),
            'site_address'  => fake()->address(),
            'filename'      => 'om-manual-' . fake()->uuid() . '.docx',
            'status'        => OmManual::STATUS_GENERATING,
        ];
    }
}
```

**Step 3 — Create `database/factories/WorksheetFactory.php`:**

```php
<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\User;
use App\Models\Worksheet;
use Illuminate\Database\Eloquent\Factories\Factory;

class WorksheetFactory extends Factory
{
    protected $model = Worksheet::class;

    public function definition(): array
    {
        return [
            'user_id'       => User::factory(),
            'project_id'    => Project::factory(),
            'project_ref'   => fake()->bothify('##C?#####'),
            'project_name'  => fake()->company() . ' AV Refresh',
            'client_name'   => fake()->company(),
            'site_address'  => fake()->address(),
            'filename'      => 'worksheet-' . fake()->uuid() . '.docx',
            'status'        => Worksheet::STATUS_GENERATING,
        ];
    }
}
```

**Step 4 — Create `database/factories/CableScheduleFactory.php`:**

Cable uses `source_filename` (RESEARCH "CableSchedule Asymmetry") and has NO `site_address` in $fillable. Status default = STATUS_GENERATING.

```php
<?php

namespace Database\Factories;

use App\Models\CableSchedule;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CableScheduleFactory extends Factory
{
    protected $model = CableSchedule::class;

    public function definition(): array
    {
        return [
            'user_id'         => User::factory(),
            'project_id'      => Project::factory(),
            'project_ref'     => fake()->bothify('##C?#####'),
            'project_name'    => fake()->company() . ' AV Refresh',
            'client_name'     => fake()->company(),
            'source_filename' => 'cable-schedule-' . fake()->uuid() . '.xlsx',
            'status'          => CableSchedule::STATUS_GENERATING,
        ];
    }
}
```

**General constraints:**
- Do NOT seed `completion_email_sent_at`, `failed_email_sent_at`, or `review_needed_email_sent_at` in any factory — they MUST default to null so feature tests can assert "not yet sent" → "sent" transitions
- Do NOT seed `error_message` in any factory — also defaults to null
- Do NOT include `email_sent_at` (RamsDocument legacy manual-send column) — owned by RamsController@email, irrelevant to Phase 09 triggers
- Use `User::factory()` and `Project::factory()` (callables) so Eloquent resolves them lazily during `create()` — tests can override by passing `['user_id' => $owner->id]`
- Use the global `fake()` helper (Laravel 12 standard) not `$this->faker`
- **DO NOT touch `app/Models/RamsDocument.php` or `app/Models/CableSchedule.php`** — those edits live in 09-01 Task 3 (B-01 fix). If you find yourself needing to edit a model file here, the wave ordering broke and you should escalate, not patch.
  </action>
  <verify>
    <automated>php artisan tinker --execute="echo App\\Models\\RamsDocument::factory()->make()->project_ref ? 'A' : ''; echo App\\Models\\OmManual::factory()->make()->project_ref ? 'B' : ''; echo App\\Models\\Worksheet::factory()->make()->project_ref ? 'C' : ''; echo App\\Models\\CableSchedule::factory()->make()->source_filename ? 'D' : '';" | grep -q ABCD</automated>
  </verify>
  <acceptance_criteria>
    - **Pre-flight (B-01 enforcement):**
      - `grep -q "use HasFactory" app/Models/RamsDocument.php` exits 0 (proves 09-01 ran before this plan — Wave 2 ordering correct)
      - `grep -q "use HasFactory" app/Models/CableSchedule.php` exits 0 (same)
    - **Factory file existence:**
      - File exists: `test -f database/factories/RamsDocumentFactory.php`
      - File exists: `test -f database/factories/OmManualFactory.php`
      - File exists: `test -f database/factories/WorksheetFactory.php`
      - File exists: `test -f database/factories/CableScheduleFactory.php`
    - **Factory class structure:**
      - `grep -q "class RamsDocumentFactory" database/factories/RamsDocumentFactory.php` exits 0
      - `grep -q "class OmManualFactory" database/factories/OmManualFactory.php` exits 0
      - `grep -q "class WorksheetFactory" database/factories/WorksheetFactory.php` exits 0
      - `grep -q "class CableScheduleFactory" database/factories/CableScheduleFactory.php` exits 0
      - `grep -q "source_filename" database/factories/CableScheduleFactory.php` exits 0   (cable uses source_filename, not filename)
      - `! grep -q "'filename'" database/factories/CableScheduleFactory.php` exits 0   (negative — cable does NOT have filename)
    - **Factory smoke (no DB needed for `->make()`):**
      - `php artisan tinker --execute="echo App\\Models\\RamsDocument::factory()->make()->project_ref;"` produces a non-empty string
      - `php artisan tinker --execute="echo App\\Models\\OmManual::factory()->make()->project_ref;"` produces a non-empty string
      - `php artisan tinker --execute="echo App\\Models\\Worksheet::factory()->make()->project_ref;"` produces a non-empty string
      - `php artisan tinker --execute="echo App\\Models\\CableSchedule::factory()->make()->source_filename;"` produces a non-empty string
    - **Status defaults:**
      - `php artisan tinker --execute="echo App\\Models\\RamsDocument::factory()->make()->status;"` prints `awaiting_review`
      - `php artisan tinker --execute="echo App\\Models\\OmManual::factory()->make()->status;"` prints `generating`
      - `php artisan tinker --execute="echo App\\Models\\Worksheet::factory()->make()->status;"` prints `generating`
      - `php artisan tinker --execute="echo App\\Models\\CableSchedule::factory()->make()->status;"` prints `generating`
    - **Email-timestamp columns NOT pre-seeded (idempotency invariant):**
      - `php artisan tinker --execute="echo App\\Models\\RamsDocument::factory()->make()->completion_email_sent_at === null ? 'NULL_OK' : 'BAD';"` prints `NULL_OK`
    - **Regression:**
      - `vendor/bin/phpunit --testsuite=Unit` exits 0 (no autoloader collision)
  </acceptance_criteria>
  <done>4 factory files exist and resolve via `Model::factory()`. No model files were touched (B-01 collision avoided). Downstream plan 09-05 Task 2 can rely on the factories.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| factories → test runtime | Factories produce model rows used only in tests; no production exposure |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| Test-data leakage | I (Information disclosure) | factory definitions | accept | All factories use `fake()` helper (Faker library) which generates synthetic data; no real customer data hard-coded. Project::factory() and User::factory() chains compose lazily so tests pass real FK values without seeding production-shaped rows. |
| Factory drift | T (Tampering) | factory `status` defaults | mitigate | Defaults are pinned to constants (`STATUS_AWAITING_REVIEW`, `STATUS_GENERATING`) — if the constants change, the factory still resolves correctly because it imports the class. Tests that depend on specific statuses override per-test. |
</threat_model>

<verification>
- All 4 factory files exist and instantiate via `Model::factory()->make()` (no DB needed for `->make()`).
- Pre-flight check confirms HasFactory trait is present on RamsDocument and CableSchedule (added by 09-01 Task 3 in Wave 1) — proves wave ordering is correct.
- Status defaults align with the pre-completion state each feature test will transition forward.
- Cable factory uses `source_filename` not `filename`.
- New email-timestamp columns default to null (no pre-seeding).
- No model files touched — eliminates Wave 1 file collision (B-01 fix).
- Existing unit suite green (no regression).
</verification>

<success_criteria>
- Plan 09-05 Task 2 feature tests can compose:
  ```php
  $owner = User::factory()->create();
  $project = Project::factory()->create(['user_id' => $owner->id]);
  $rams = RamsDocument::factory()->create(['project_id' => $project->id, 'user_id' => $owner->id]);
  ```
  ...without needing to invent factory shapes mid-test.
- No regression to the existing test suite.
- Wave ordering preserved: 09-01 (Wave 1) ships HasFactory + columns; this plan (Wave 2) ships factories. Zero file overlap.
</success_criteria>

<output>
After completion, create `.planning/phases/09-email-notifications/09-02b-SUMMARY.md` with:
- Paths + line counts of the 4 new factory files
- Confirmation that the pre-flight HasFactory check passed (proves 09-01 ran first)
- Smoke test output proving each factory resolves
- Confirmation that NO model files were modified by this plan (B-01 enforcement)
- Any gotcha (e.g., Faker locale issues, FK resolution edge cases)
</output>
</content>
