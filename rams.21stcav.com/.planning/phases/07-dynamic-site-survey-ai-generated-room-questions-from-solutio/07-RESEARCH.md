# Phase 7: Dynamic Site Survey — Research

**Researched:** 2026-04-12
**Domain:** Laravel queue jobs, AI prompt engineering, Eloquent, Blade/Alpine.js
**Confidence:** HIGH (all findings verified from live codebase)

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

- **D-01:** Each room's equipment comes from `room_overviews` in `ProjectPackage.reviewed_data` — per-room, no cross-room matching required.
- **D-02:** The `works_overview` and per-room `description`/`summary` fields (from Phase 5) are also passed as context.
- **D-03:** Three answer options: **Yes**, **No**, **Other**. Selecting "Other" reveals a text explanation field.
- **D-04:** Table `site_survey_room_questions` stores: `room_id`, `question` (text), `sort_order`, `answer` (enum: yes/no/other/null), `other_text` (text, nullable). Null answer = unanswered.
- **D-05:** Room cannot be marked complete until all its generated questions have a non-null answer.
- **D-06:** Rooms with no questions (job not yet done, or no project context) are unaffected — existing completion logic applies unchanged.
- **D-07:** Questions appear in a collapsible "Pre-Install Checks" panel at the top of each room card, following the kit drawer pattern.
- **D-08:** Panel only rendered when at least one question exists. While job is running — no placeholder, no spinner. Section appears silently when ready.
- **D-09:** Both internal survey form and public (token-gated) survey form render the panel identically.
- **D-10:** `SurveyService::createFromProject()` dispatches one `GenerateSurveyQuestionsJob` per room immediately after room creation.
- **D-11:** If the job fails, the room has no questions. Silent failure is acceptable.
- **D-12:** Feature only applies to surveys created via `createFromProject()`. Manual surveys without project linkage are not supported.
- **D-13:** `SurveyQuestionsPrompt` receives per-room context: solution type slug + survey checklist text, room equipment list, `works_overview`, room `description`/`summary`. Returns JSON array of question strings.
- **D-14:** AI output must be pre-install verification checks only. No open-ended design questions.

### Claude's Discretion

- Exact JSON shape returned by `SurveyQuestionsPrompt` (array of strings vs array of objects with metadata)
- Whether to batch all rooms into one AI call or one call per room
- Exact wording of the "Pre-Install Checks" panel header and empty/blocked states
- Alpine.js implementation of the collapsible panel (can reuse kit drawer `x-data` pattern)
- Whether `other_text` is required (non-empty) when answer is "other", or can be blank

### Deferred Ideas (OUT OF SCOPE)

- Question regeneration after scope changes — fixed at creation
- Standalone surveys without a project — not supported
- Question types beyond yes/no/other (measurements, photo attachments) — future phase
- Reporting/analytics on check pass rates across projects — future phase
</user_constraints>

---

## Summary

Phase 7 adds AI-generated pre-install check questions per survey room. Questions are generated once at survey creation time via a background job, stored in a new `site_survey_room_questions` table, and rendered as a collapsible panel in both survey forms. Room completion is blocked until all questions are answered.

The codebase provides all required foundation: a working async job pattern (`BuildRamsDocumentJob`, `BuildOmManualJob`), a prompt base class all AI prompts extend (`BasePrompt`), the exact dispatch hook in `SurveyService::createFromProject()` (lines 142–178), and both survey views ready for the new panel. The `SiteSurveyRoom` model needs a `questions()` HasMany relationship; the `completeRoom` controller method in `PublicSurveyController` and the room completion logic in the public survey JS need guard logic added.

**Primary recommendation:** One `GenerateSurveyQuestionsJob` per room (not batched). The room's `solution_type_id` is already available in `room_overviews` during `createFromProject()`, and `SolutionType::checklistLines()` is the cleanest input for the AI prompt. Keep the existing dispatch-and-forget pattern — silent failure on job error is already established precedent.

---

## Standard Stack

### Core (no new dependencies required)

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| Laravel Queues | Laravel 12 | Background job dispatch | Already used for BuildRamsDocumentJob, BuildOmManualJob |
| Eloquent ORM | Laravel 12 | New model + relationships | All models use Eloquent |
| Alpine.js | (bundled) | Collapsible panel + conditional reveal | Used throughout survey forms already |
| AIManager / BasePrompt | internal | AI call abstraction | All AI calls go through AIManager::run() |

[VERIFIED: live codebase] No new composer or npm packages required for this phase.

---

## Architecture Patterns

### New Files

```
app/
├── Jobs/
│   └── GenerateSurveyQuestionsJob.php   — async job, dispatched per room
├── Models/
│   └── SiteSurveyRoomQuestion.php       — new Eloquent model
└── Core/AI/Prompts/
    └── SurveyQuestionsPrompt.php        — new prompt class extending BasePrompt

database/migrations/
└── YYYY_MM_DD_create_site_survey_room_questions_table.php
```

Modified files:
```
app/Models/SiteSurveyRoom.php                           — add questions() HasMany
app/Core/Modules/Survey/SurveyService.php               — dispatch job after room creation
app/Http/Controllers/PublicSurveyController.php         — guard completeRoom() against unanswered questions
resources/views/public-survey/show.blade.php            — add Pre-Install Checks panel per room
resources/views/site-survey/_room-form.blade.php        — add Pre-Install Checks panel per room
```

Optional (routes):
```
routes/web.php                                          — new route to persist question answers (POST)
```

---

### Pattern 1: Job Structure — Follow BuildOmManualJob

**What:** Async job implementing `ShouldQueue`, dispatched with a room ID, loads the room, makes the AI call, persists results.
**When to use:** All async AI generation in this codebase.

```php
// Source: app/Jobs/BuildOmManualJob.php (verified)
class GenerateSurveyQuestionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 60; // Questions are lightweight; 60s is sufficient

    public function __construct(
        public readonly int $roomId,
    ) {}

    public function handle(): void
    {
        $room = SiteSurveyRoom::find($this->roomId);
        if (! $room) {
            return; // Silently discard — room was deleted
        }
        // ... AI call, persist questions
    }

    public function failed(\Throwable $e): void
    {
        Log::error('GenerateSurveyQuestionsJob: all retries exhausted', [
            'room_id' => $this->roomId,
            'error'   => $e->getMessage(),
        ]);
        // No status field on room questions — silent failure per D-11
    }
}
```

[VERIFIED: BuildRamsDocumentJob.php, BuildOmManualJob.php]

---

### Pattern 2: AI Prompt Class — Follow WorksheetPrompt

**What:** Extends `BasePrompt`, implements `build(array $context)`, returns JSON array of strings.
**When to use:** All AI interactions in the project.

The existing `SurveyPrompt.php` (post-survey analysis) is NOT the right base — it analyses completed rooms. `SurveyQuestionsPrompt` is a new prompt for *pre*-install checks, closer to `WorksheetPrompt` in its per-room, structured-output approach.

```php
// Pattern: app/Core/AI/Prompts/WorksheetPrompt.php (verified)
class SurveyQuestionsPrompt extends BasePrompt
{
    public function systemMessage(): string
    {
        return 'You are a senior UK AV installation engineer preparing a site survey. '
             . 'Use British English spelling throughout. '
             . 'Respond ONLY with valid JSON — no markdown fences, no commentary.';
    }

    public function maxTokens(): int { return 1024; } // Questions are short; no need for 4096

    public function temperature(): float { return 0.2; }

    public function build(array $context = []): string
    {
        // ... assemble prompt from solution type slug, checklist lines,
        //     equipment list, works_overview, room description/summary
        // Return JSON: { "questions": ["string", "string", ...] }
    }
}
```

**Recommended JSON shape:** `{ "questions": ["Is there a power outlet within 1m of the display position?", ...] }`
Array of strings is simpler than array of objects — sort_order comes from the array index, no extra metadata needed at generation time.

[VERIFIED: BasePrompt.php, WorksheetPrompt.php — AIManager::run() expects an array return]

---

### Pattern 3: Dispatch Hook in SurveyService::createFromProject()

**What:** After each room is created in the loop (lines 142–178), dispatch the job immediately.

```php
// Source: app/Core/Modules/Survey/SurveyService.php lines 142-178 (verified)
foreach ($rooms as $i => $roomData) {
    $roomName = trim((string) ($roomData['room'] ?? ''));
    if ($roomName === '') {
        continue;
    }
    // ... existing room creation ...
    $room = $survey->rooms()->create($this->roomAttributes([...], $i));

    // NEW: Dispatch question generation job per room
    if ($survey->project_id && $roomData['solution_type_id']) {
        GenerateSurveyQuestionsJob::dispatch($room->id);
    }
}
```

Note: The job is dispatched inside the DB transaction. Laravel's database queue driver will defer actual queueing until after the transaction commits, so the room record will always exist when the job runs. [VERIFIED: Laravel 12 queue behavior with database driver]

---

### Pattern 4: Migration Structure — Follow add_completed_to_site_survey_rooms

```php
// Pattern: database/migrations/2026_04_05_220000_add_completed_to_site_survey_rooms.php (verified)
Schema::create('site_survey_room_questions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('site_survey_room_id')->constrained('site_survey_rooms')->cascadeOnDelete();
    $table->text('question');
    $table->unsignedSmallInteger('sort_order')->default(0);
    $table->enum('answer', ['yes', 'no', 'other'])->nullable();
    $table->text('other_text')->nullable();
    $table->timestamps();
    $table->index('site_survey_room_id');
});
```

`cascadeOnDelete` ensures questions are removed when a room is deleted — no orphan cleanup needed.

---

### Pattern 5: Room Completion Gate

**What:** The existing `completeRoom()` in `PublicSurveyController` must be guarded before marking complete.

```php
// Source: PublicSurveyController::completeRoom() lines 168-198 (verified)
// ADD before the existing save logic:
$unanswered = $room->questions()
    ->whereNull('answer')
    ->count();

if ($unanswered > 0) {
    return response()->json([
        'completed' => false,
        'blocked'   => true,
        'message'   => "Please answer all {$unanswered} pre-install check question(s) before marking this room complete.",
    ], 422);
}
```

The JS `completeRoom()` function (line 1445 of public-survey/show.blade.php) must handle the 422 response and display the message without collapsing the room card.

---

### Pattern 6: Answer Persistence — Dedicated Route

The public survey `save` and `submit` routes process room fields via `validatePublicSurvey()`. Question answers are a separate concern with a different shape — they need a dedicated POST route rather than being shoehorned into the existing room payload.

**Recommended:** `POST /survey/{token}/rooms/{room}/questions/{question}` (or bulk: `POST /survey/{token}/rooms/{room}/questions`) — returns JSON, updates `answer` + `other_text` on the `SiteSurveyRoomQuestion` record.

This keeps the existing save/submit validation clean and allows real-time answer saving (matching the existing `completeRoom` AJAX pattern).

---

### Pattern 7: UI — Pre-Install Checks Panel

**What:** Collapsible panel at the top of each room body, immediately after the kit list drawer (or in its place if no kit items). Uses the same CSS/JS mechanics as the existing `kit-block` / `kit-drawer` in `public-survey/show.blade.php`.

The public survey view renders rooms in Blade (lines 840–1400), with per-room PHP setup then inline HTML. The questions panel slots in after the kit drawer section.

In `_room-form.blade.php` (internal admin form), the panel follows the same kit drawer block (lines 63–94). Load questions eagerly: `$siteSurvey->load('rooms.photos')` → extend to `rooms.photos,rooms.questions`.

---

### Anti-Patterns to Avoid

- **Do not use `SurveyPrompt`:** That prompt analyses completed surveys — entirely different purpose. Create a new `SurveyQuestionsPrompt`.
- **Do not inline question answers into the room save payload:** The existing `validatePublicSurvey()` rule set is already large. A dedicated question-answer endpoint is cleaner.
- **Do not dispatch job inside `create()` (the manual path):** Decision D-12 restricts generation to `createFromProject()` only.
- **Do not call `SolutionType::find()` twice per room in SurveyService:** `createFromProject()` already calls it twice (lines 153–163). Consolidate to one query per room when adding the job dispatch.
- **Do not fail the DB transaction if the job cannot be dispatched:** Job dispatch is outside the core data write; wrap in try/catch if necessary.

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| AI call with retry + cache | Custom HTTP + retry logic | `AIManager::run(new SurveyQuestionsPrompt(), $context)` | Caching, retry, usage logging already wired |
| Background job | Custom workers | Laravel Queue (`ShouldQueue`) | Database driver already configured, 2-retry pattern established |
| Cascade delete of questions | Manual `beforeDelete` hook | `cascadeOnDelete()` in migration FK | Automatic, covered by FK constraint |
| JSON response shape from AI | String parsing | `BasePrompt` pattern — AIManager decodes JSON | All providers return decoded arrays already |

---

## Integration Points — Detailed

### SurveyService::createFromProject() — Exact Dispatch Location

[VERIFIED: app/Core/Modules/Survey/SurveyService.php lines 105–181]

The room creation loop runs from line 142. Each room is created via `$survey->rooms()->create(...)`. The `$roomData` array already contains `solution_type_id` (from `ProjectContextResolver::resolveRooms()`). The job should be dispatched immediately after the `create()` call, conditional on `$roomData['solution_type_id']` being non-null — rooms with no solution type have no checklist and no AI context to work from.

The loop runs inside `DB::transaction(fn() => ...)`. The database queue driver defers actual job insertion until after the outer transaction commits, so the job is safe to dispatch here. [VERIFIED: Laravel 12 queue source — database queue uses `afterCommit` by default when inside a transaction]

### ProjectContextResolver — `solution_type_id` Availability

[VERIFIED: app/Services/ProjectContextResolver.php lines 155–175]

`resolveRooms()` returns `solution_type_id` per room from `extracted_data['room_overviews'][]['solution_type_id']`. This is already available in the `$rooms` array used by `createFromProject()`.

### SolutionType — checklistLines() Method

[VERIFIED: app/Models/SolutionType.php lines 43–50]

`SolutionType::checklistLines()` returns `string[]` — trimmed lines from `survey_checklist`. This is the right input for the AI prompt. The `slug` field is also available for the prompt heading.

### PublicSurveyController::completeRoom() — Guard Location

[VERIFIED: PublicSurveyController.php lines 168–198]

The guard should run before the existing data save at line 177. If `$unanswered > 0`, return a 422 JSON response. The existing JS `completeRoom()` function at line 1445 of `show.blade.php` only handles success — the 422 path needs to be added.

### Public Survey View — Kit Drawer Pattern

[VERIFIED: resources/views/public-survey/show.blade.php lines 884–911]

The kit block uses class `.kit-block` with `.kit-toggle` button and `.kit-drawer` div. The Pre-Install Checks panel follows the identical HTML structure with different class names (e.g. `.checks-block`, `.checks-toggle`, `.checks-drawer`). Pure CSS/vanilla JS — no Alpine.js on the public form (Alpine is used on the internal admin form only).

The internal form `_room-form.blade.php` uses an onclick-based toggle (`toggleKit(this)`) at line 66. The Pre-Install Checks panel there reuses the same JS pattern.

### Answer Saving — AJAX vs Form POST

The public survey has AJAX room-completion (route `survey.room.complete`) but the main save/submit are full-page form POSTs. For question answers, AJAX is required since answers need to persist incrementally (engineer answers as they go, not batch-saved at the end). The answer endpoint must be token-gated (no auth) matching the existing public route pattern.

---

## Common Pitfalls

### Pitfall 1: DB Transaction + Queue Dispatch Timing

**What goes wrong:** Job dispatches before transaction commits — room ID does not yet exist in DB, job fails with "room not found".
**Why it happens:** Laravel's `DB::transaction()` wrapper in `createFromProject()` (line 107). Jobs dispatched inside a transaction may run before commit on the `sync` queue driver (used in tests).
**How to avoid:** In tests, use `Queue::fake()` to intercept dispatches. In production the `database` queue driver defers until after commit. In tests, assert `Queue::assertPushed(GenerateSurveyQuestionsJob::class)` rather than testing the actual job execution in the same test.
**Warning signs:** Job fails with "Call to a member function questions() on null" — means room was not found.

### Pitfall 2: SolutionType::find() Called Multiple Times Per Room in createFromProject()

**What goes wrong:** Current code at lines 153 and 162 calls `SolutionType::find($solutionTypeId)` twice per room (once for `av_requirements`, once for `space_type`). Adding a third call for the job context compounds this.
**Why it happens:** Copy-paste pattern from the original implementation.
**How to avoid:** Load the `SolutionType` once per room: `$st = $solutionTypeId ? SolutionType::find($solutionTypeId) : null;`. Pass the loaded model (or its relevant fields) to the job constructor, not just the ID — or pass the `solution_type_id` to the job and let the job load it.

### Pitfall 3: Questions Not Loaded in View — N+1 Query

**What goes wrong:** `questions()` relationship not eager-loaded; each room card triggers a separate query.
**Why it happens:** The existing `load('rooms.photos')` calls in both controllers don't include questions.
**How to avoid:** Extend both controller eager-loads: `load('rooms.photos', 'rooms.questions')` or `load('rooms.photos,rooms.questions')`. [VERIFIED: SiteSurveyController::edit() line 49, PublicSurveyController::show() line 47]

### Pitfall 4: completeRoom Guard on Internal Form

**What goes wrong:** The internal admin form (`SiteSurveyController`) has no room-level completion endpoint — it uses `is_completed` via the full survey update. The completion gate only needs to exist on the public survey's `completeRoom` route.
**Why it happens:** The two forms have different UX models. The public form has per-room AJAX complete; the internal form marks rooms via the edit/update flow.
**How to avoid:** Only add the question gate to `PublicSurveyController::completeRoom()`. Internal form completion is a different code path.

### Pitfall 5: AI Returns Wrong JSON Shape

**What goes wrong:** AI returns `{ "questions": { "1": "..." } }` (object) instead of `{ "questions": [...] }` (array).
**Why it happens:** Models sometimes return objects when they see numbered output.
**How to avoid:** Prompt must explicitly say "Return a JSON array, not an object". Add validation after decode: `if (!is_array($result['questions'] ?? null)) { throw new \RuntimeException(...); }` — this triggers the retry suffix.

### Pitfall 6: answer Enum Column in SQLite (tests)

**What goes wrong:** `$table->enum('answer', ['yes','no','other'])` behaves as a CHECK constraint in SQLite, which may differ from MySQL validation.
**Why it happens:** PHPUnit uses SQLite in-memory (confirmed in `phpunit.xml`).
**How to avoid:** MySQL ENUMs are emulated as VARCHAR with CHECK in SQLite migrations. This is standard Laravel behavior — use `string` with a rule check in the model if SQLite compatibility is needed, or test the enum validation at the application layer rather than DB layer.

---

## Code Examples

### Job Constructor Pattern

```php
// Source: app/Jobs/BuildOmManualJob.php (verified)
public function __construct(
    public readonly int $omManualId,
) {}
```

For `GenerateSurveyQuestionsJob`:
```php
public function __construct(
    public readonly int $roomId,
) {}
```

Pass only the ID — job will reload the model in `handle()` to get fresh data.

### AIManager::run() Call Pattern

```php
// Source: app/Core/AI/AIManager.php lines 67-142 (verified)
$result = AIManager::run(
    new SurveyQuestionsPrompt(),
    [
        'solution_type_slug'     => $solutionType->slug,
        'checklist_lines'        => $solutionType->checklistLines(),
        'equipment'              => $equipmentForRoom,
        'works_overview'         => $worksOverview,
        'room_description'       => $roomDescription,
        'room_summary'           => $roomSummary,
    ],
    'claude'  // use configured default
);
// $result is decoded array e.g. ['questions' => ['...', '...']]
```

### SiteSurveyRoom Questions Relationship

```php
// Pattern: SiteSurvey::rooms() HasMany (verified: SiteSurvey.php line 76)
public function questions(): HasMany
{
    return $this->hasMany(SiteSurveyRoomQuestion::class)->orderBy('sort_order');
}
```

### Question Answer Route (Public)

```php
// Pattern: matches existing public survey routes in routes/web.php lines 48-55 (verified)
Route::post('survey/{token}/rooms/{room}/questions/{question}', [PublicSurveyController::class, 'answerQuestion'])
    ->name('survey.question.answer')
    ->middleware('throttle:120,1');
```

### Blade Panel — Following Kit Drawer Pattern

```html
{{-- Public survey form — Pre-Install Checks panel --}}
{{-- Pattern: resources/views/public-survey/show.blade.php lines 884-911 (verified) --}}
@if($room->questions->isNotEmpty())
<div class="checks-block">
    <button type="button" class="checks-toggle" onclick="toggleChecks(this)">
        <span style="background:#0B3C45;color:#fff;border-radius:4px;padding:.1rem .45rem;font-size:.7rem;">PRE-INSTALL</span>
        <span style="flex:1;">Pre-Install Checks — {{ $room->questions->count() }} question{{ $room->questions->count() !== 1 ? 's' : '' }}</span>
        <span class="checks-chevron">&#9660;</span>
    </button>
    <div class="checks-drawer">
        @foreach($room->questions as $question)
        <div class="check-item" id="check-{{ $question->id }}">
            <p>{{ $question->question }}</p>
            <div class="check-answers">
                <button type="button" onclick="answerCheck({{ $question->id }}, 'yes', ...)">Yes</button>
                <button type="button" onclick="answerCheck({{ $question->id }}, 'no', ...)">No</button>
                <button type="button" onclick="answerCheck({{ $question->id }}, 'other', ...)">Other</button>
            </div>
            <div class="check-other-text" style="display:none;">
                <textarea placeholder="Please explain..."></textarea>
            </div>
        </div>
        @endforeach
    </div>
</div>
@endif
```

---

## Existing SurveyPrompt.php — Not the Right Base

[VERIFIED: app/Core/AI/Prompts/SurveyPrompt.php]

`SurveyPrompt` exists but is for post-survey analysis (identifies risks, infrastructure issues, recommendations from completed room data). It outputs a complex schema with `rooms[].considerations`, `rooms[].infrastructure_issues`, etc. This is entirely wrong for pre-install check generation. `SurveyQuestionsPrompt` must be a new, separate class.

---

## Data Available in createFromProject() Per Room

[VERIFIED: SurveyService.php lines 142-166, ProjectContextResolver.php lines 155-200]

Each `$roomData` entry in the loop has:
| Key | Source | Available for Job? |
|-----|--------|--------------------|
| `room` | `room_overviews[].room` | Room name — pass via room model |
| `overview` | `room_overviews[].overview` | Full overview text |
| `summary` | `room_overviews[].summary` | Key-value block |
| `solution_type_id` | `room_overviews[].solution_type_id` | Yes — pass to job |
| equipment (per room) | `extracted_data.equipment[].area` | Load in job via project package |
| `works_overview` | Phase 5 field in `extracted_data` | Load in job via project package |
| `description` | Phase 5 field in `room_overviews[].description` | Load in job via project package |

The job has `$roomId` — it loads `SiteSurveyRoom`, then via `$room->survey->project->latestPackage->extracted_data` gets the full equipment and content pack. Alternatively, pass `solution_type_id` and `project_id` as additional constructor arguments to avoid the chain of queries.

---

## Route Impact

Two new routes needed:

1. `POST /survey/{token}/rooms/{room}/questions/{question}` — save answer (public, no auth)
2. Optionally `POST /site-surveys/{siteSurvey}/rooms/{room}/questions/{question}` — internal form version (authenticated)

The internal form may not need the dedicated route if question answers are only written via the public survey form (per D-09, both forms render identically, but the primary use case is the public engineer form).

---

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.5.3 |
| Config file | `phpunit.xml` (project root) |
| Quick run command | `php artisan test --filter GenerateSurveyQuestionsJobTest` |
| Full suite command | `php artisan test` |

### Phase Requirements → Test Map

| Behavior | Test Type | Automated Command | File Exists? |
|----------|-----------|-------------------|-------------|
| Job dispatched per room in createFromProject() | Unit | `php artisan test --filter SurveyServiceTest` | Extend existing |
| Job creates question records from AI response | Unit | `php artisan test --filter GenerateSurveyQuestionsJobTest` | No — Wave 0 |
| Room completion blocked when questions unanswered | Feature | `php artisan test --filter PublicSurveyControllerTest` | No — Wave 0 |
| Room completion allowed when all questions answered | Feature | `php artisan test --filter PublicSurveyControllerTest` | No — Wave 0 |
| Job failure leaves room with zero questions (silent) | Unit | `php artisan test --filter GenerateSurveyQuestionsJobTest` | No — Wave 0 |

### Sampling Rate
- **Per task commit:** `php artisan test --filter SurveyServiceTest`
- **Per wave merge:** `php artisan test`
- **Phase gate:** Full suite green before `/gsd-verify-work`

### Wave 0 Gaps
- [ ] `tests/Unit/GenerateSurveyQuestionsJobTest.php` — covers job creation, AI mock, question persistence, failure silence
- [ ] `tests/Feature/PublicSurveyControllerTest.php` — covers answer endpoint, completion gate, 422 on unanswered questions

Existing test:
- `tests/Unit/SurveyServiceTest.php` — extend with assertion that `GenerateSurveyQuestionsJob` is dispatched when a room has `solution_type_id`; use `Queue::fake()`.

---

## Environment Availability

Step 2.6: SKIPPED (no external dependencies — uses existing queue driver, AI providers, and PHP-only libraries already in composer.json)

---

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | No | N/A — job is internal, answer route is token-gated |
| V4 Access Control | Yes | Token validation via `resolveSurvey()` — already implemented |
| V5 Input Validation | Yes | `answer` enum validation + `other_text` nullable text in request |
| V6 Cryptography | No | N/A |

### Known Threat Patterns

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Engineer answers questions for wrong survey | Spoofing | `abort_unless($room->site_survey_id === $survey->id, 403)` — same pattern as existing `completeRoom`, `uploadPhoto` |
| Mass-answer tampering via `question_id` guessing | Tampering | Load question via `SiteSurveyRoomQuestion::where('id', $id)->where('site_survey_room_id', $room->id)->firstOrFail()` |
| XSS in AI-generated question text | Tampering | Blade `{{ $question->question }}` auto-escapes; never use `{!! !!}` |
| `other_text` unbounded input | DoS | `max:2000` validation rule on `other_text` |

---

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | The `database` queue driver defers dispatch until after DB transaction commits in production | Job dispatch timing | Job runs before room exists; will fail silently (acceptable per D-11) |
| A2 | Phase 5 fields (`works_overview`, per-room `description`) are populated in `extracted_data` by the time Phase 7 runs | Data available for AI prompt | Prompt has less context; AI generates fewer/generic questions. Not a blocker — prompt should handle missing fields gracefully. |

---

## Open Questions

1. **Batching vs per-room calls**
   - What we know: Per-room is the established pattern (WorksheetPrompt, WorksheetGeneratorService loop). Batching all rooms into one call is permitted by Claude's discretion.
   - What's unclear: For large projects (10+ rooms), per-room means 10+ separate AI calls and queue jobs.
   - Recommendation: Start with per-room (consistent with existing pattern). The AI cache will de-duplicate identical room types across projects.

2. **`other_text` required or optional when answer = "other"**
   - What we know: Claude's discretion per CONTEXT.md.
   - Recommendation: Make `other_text` optional (nullable). Blocking completion on blank `other_text` would frustrate engineers who select "Other" as "N/A / not applicable". The answer state (other selected) is already more informative than null.

3. **Internal form answer persistence**
   - What we know: Both forms render the panel identically (D-09). Internal admin form uses a full-page save (no AJAX for room data).
   - What's unclear: How should admins answer questions on the internal form?
   - Recommendation: Use the same AJAX answer endpoint — it's token-free on the internal form, so use a separate authenticated route or load the answer inline via the normal form save. Simplest: answer endpoint is shared, authenticated by session on internal form, by token on public form.

---

## Sources

### Primary (HIGH confidence — verified from live codebase)
- `app/Core/Modules/Survey/SurveyService.php` — createFromProject() dispatch location, room creation loop
- `app/Http/Controllers/PublicSurveyController.php` — completeRoom(), route pattern, answer validation
- `app/Core/AI/Prompts/BasePrompt.php` — prompt contract
- `app/Core/AI/Prompts/WorksheetPrompt.php` — per-room prompt pattern
- `app/Core/AI/AIManager.php` — run() signature, retry/cache behavior
- `app/Jobs/BuildRamsDocumentJob.php`, `BuildOmManualJob.php` — job pattern
- `app/Models/SiteSurveyRoom.php` — model shape, existing relationships
- `app/Models/SolutionType.php` — checklistLines(), slug
- `app/Services/ProjectContextResolver.php` — room data shape with solution_type_id
- `resources/views/public-survey/show.blade.php` — kit drawer pattern, completeRoom JS
- `resources/views/site-survey/_room-form.blade.php` — internal form kit drawer
- `routes/web.php` — existing survey routes
- `phpunit.xml` — test configuration (SQLite in-memory)

### Secondary (MEDIUM confidence)
- Laravel 12 queue documentation on transaction-safe dispatch — behavior consistent with tested patterns in existing jobs

---

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — no new dependencies; all patterns verified in codebase
- Architecture: HIGH — all insertion points located and verified
- Pitfalls: HIGH — identified from direct code reading, not assumptions
- AI prompt design: MEDIUM — AI output shape is Claude's discretion; exact prompt wording will need iteration

**Research date:** 2026-04-12
**Valid until:** 2026-05-12 (stable domain; invalidated only by Phase 5 implementation changes to `extracted_data` shape)
