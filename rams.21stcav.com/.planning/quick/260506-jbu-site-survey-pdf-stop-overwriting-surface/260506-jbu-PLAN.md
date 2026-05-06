---
phase: quick-260506-jbu
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Core/Modules/Survey/SurveyService.php
  - app/Services/SurveyPdfService.php
  - resources/views/pdf/site-survey/summary.blade.php
autonomous: false   # Task 3 is a human-verification checkpoint
requirements: [QUICK-260506-JBU]
tags: [survey, pdf, ai-questions, quote-text-fidelity]

must_haves:
  truths:
    - "Survey-PDF rooms with both quote-specific overview text AND a matched solution type render the room's specific text FIRST, with the static checklist appended as a clearly-labelled supplementary block."
    - "Survey-PDF rooms with AI-generated SiteSurveyRoomQuestion rows render a per-room 'Pre-install Checks' section listing every question (sorted by sort_order) with the engineer's answer or '—' when unanswered, plus other_text when present."
    - "Rooms with no questions show NO 'Pre-install Checks' heading (empty headings suppressed)."
    - "Rooms with no quote overview AND no checklist still produce a working survey row (av_requirements stays null — current behaviour preserved)."
    - "Rooms with NO solution_type_id (e.g. 'Install Room Booking Panel onto glass') still render their specific overview text — same as today, no regression."
  artifacts:
    - path: "app/Core/Modules/Survey/SurveyService.php"
      provides: "Fixed createFromProject() — overview is primary, checklist appended as supplement"
      contains: "Standard checks for this solution type"
    - path: "resources/views/pdf/site-survey/summary.blade.php"
      provides: "Per-room 'Pre-install Checks' section rendering SiteSurveyRoomQuestion rows"
      contains: "Pre-install Checks"
    - path: "app/Services/SurveyPdfService.php"
      provides: "Eager-loads rooms.questions on summary build"
      contains: "rooms.questions"
  key_links:
    - from: "SurveyService::createFromProject"
      to: "SiteSurveyRoom.av_requirements"
      via: "merged overview + checklist string"
      pattern: "Standard checks for this solution type"
    - from: "summary.blade.php"
      to: "SiteSurveyRoomQuestion records"
      via: "$room->questions Eloquent relation"
      pattern: "\\$room->questions"
    - from: "SurveyPdfService::buildSummary"
      to: "rooms.questions eager load"
      via: "loadMissing chain"
      pattern: "rooms\\.questions"
---

<objective>
Fix two related bugs in the populated site-survey PDF so it actually reflects what's being installed in each room instead of regurgitating a generic per-solution-type checklist.

**Bug 1 — Overwrite:** `SurveyService::createFromProject()` currently lets the SolutionType's `survey_checklist` blob clobber the room's specific quote text whenever a `solution_type_id` is matched. Result: every "Video Conferencing" room gets the same 18-line generic checklist, drowning out the actual scope.

**Bug 2 — Hidden questions:** `GenerateSurveyQuestionsJob` already produces rich per-room AI questions and persists them as `SiteSurveyRoomQuestion` rows, but the survey PDF (`summary.blade.php`) ignores them entirely. The intelligence already exists; it just needs a Blade hook.

Purpose: Make the populated survey PDF room-specific and surface the AI work that's already happening behind the scenes.

Output:
- Updated `SurveyService.php` with overview-primary / checklist-appended logic.
- Updated `summary.blade.php` rendering a "Pre-install Checks" section per room.
- Updated `SurveyPdfService.php` eager-loading `rooms.questions` so the Blade has the data.
- Regenerated PDF for the Tilda survey demonstrating the fix end-to-end.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@CLAUDE.md
@.planning/STATE.md
@app/Core/Modules/Survey/SurveyService.php
@app/Services/SurveyPdfService.php
@app/Models/SiteSurveyRoom.php
@app/Models/SiteSurveyRoomQuestion.php
@app/Jobs/GenerateSurveyQuestionsJob.php
@resources/views/pdf/site-survey/summary.blade.php
@resources/views/pdf/site-survey/_styles.blade.php
@database/seeders/SolutionTypeSeeder.php

<interfaces>
<!-- Key contracts the executor needs. Extracted from codebase. No exploration required. -->

From app/Models/SiteSurveyRoom.php (line 139-142):
```php
public function questions(): HasMany
{
    return $this->hasMany(SiteSurveyRoomQuestion::class, 'site_survey_room_id')
                ->orderBy('sort_order');
}
```
Already ordered by sort_order — Blade does NOT need to re-sort.

From app/Models/SiteSurveyRoomQuestion.php — fillable fields:
- `question` (string)        — the AI-generated text
- `answer` (string|null)     — enum: 'yes' | 'no' | 'other' | null (unanswered)
- `other_text` (string|null) — engineer's free-text when answer='other'
- `sort_order` (int)         — already used by the relation's orderBy

From app/Services/SurveyPdfService.php (line 35) — existing eager-load chain:
```php
$survey->loadMissing('rooms.photos');
```
This is the line to extend so questions are available without N+1.

From app/Core/Modules/Survey/SurveyService.php (lines 212-221) — the bug site:
```php
$avRequirements = trim((string) ($roomData['overview'] ?? $roomData['summary'] ?? ''));
$solutionTypeId = (int) ($roomData['solution_type_id'] ?? 0) ?: null;
if ($solutionTypeId) {
    $st = \App\Models\SolutionType::find($solutionTypeId);
    if ($st && $st->survey_checklist) {
        $avRequirements = $st->survey_checklist;   // ← THE OVERWRITE
    }
}
```

From summary.blade.php (lines 95-111) — existing AV Requirements block to render NEXT TO:
```blade
@if($avReq !== '' || $avEq !== '')
    <h3>AV Requirements</h3>
    <table>
        @if($avReq !== '')
            <tr>
                <td class="label">Planned AV Works</td>
                <td>{!! H::narrativeAsTickList($avReq) !!}</td>
            </tr>
        @endif
        ...
    </table>
@endif
```
Match this visual idiom (h3 + table + label/value rows + teal accents from `_styles.blade.php`).

From _styles.blade.php — already defined:
- `.label` (30%, bold, grey background) for left column
- `.tick-list` for bullet-style lists with tight line-height
- `h3` styling: 9pt teal eyebrow with 0.5pt teal underline
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: Fix the AV Requirements overwrite bug — overview primary, checklist appended</name>
  <files>app/Core/Modules/Survey/SurveyService.php</files>
  <action>
In `createFromProject()`, replace lines 212-221 (the `$avRequirements = ... if ($solutionTypeId) { ... }` block) with priority-reversed logic:

1. Compute `$overview = trim((string) ($roomData['overview'] ?? $roomData['summary'] ?? ''));`
2. Compute `$solutionTypeId = (int) ($roomData['solution_type_id'] ?? 0) ?: null;`
3. Compute `$checklist = '';` and if `$solutionTypeId`, `$checklist = trim((string) (\App\Models\SolutionType::find($solutionTypeId)?->survey_checklist ?? ''));`
4. Combine into `$avRequirements` per these three cases (preserve existing null-safety — line 231 still does `$avRequirements ?: null`):
   - **Both present:** `$avRequirements = $overview . "\n\nStandard checks for this solution type:\n" . $checklist;`
   - **Only overview:** `$avRequirements = $overview;`
   - **Only checklist:** `$avRequirements = $checklist;` (preserves current fallback for rooms with no quote text but matched solution type)
   - **Neither:** `$avRequirements = '';` (line 231 converts to null)
5. Keep the `(\App\Models\SolutionType::find($solutionTypeId)?->slug ?? 'general')` lookup at line 234 intact — it's separate from this fix and re-queries the same row (acceptable cost; refactoring out is not in scope).

Do NOT modify `SolutionTypeSeeder.php`. Do NOT modify `GenerateSurveyQuestionsJob.php`. Do NOT add any migrations.

The `narrativeAsTickList()` helper that the Blade already calls on `$avReq` happily renders multi-paragraph text — the new "Standard checks for this solution type:" sentinel survives the round-trip and reads naturally in the PDF.
  </action>
  <verify>
    <automated>php -l app/Core/Modules/Survey/SurveyService.php &amp;&amp; grep -n "Standard checks for this solution type" app/Core/Modules/Survey/SurveyService.php</automated>
  </verify>
  <done>
- `php -l` reports "No syntax errors detected".
- `grep` returns one match for the sentinel string in `SurveyService.php`.
- The line-219 unconditional overwrite (`$avRequirements = $st->survey_checklist;`) NO LONGER appears in the file (`grep -c "= \\\$st->survey_checklist"` returns 0).
- Atomic commit: `feat(survey-260506-jbu): preserve quote-specific AV requirements over solution-type checklist`
  </done>
</task>

<task type="auto">
  <name>Task 2: Render AI-generated pre-install questions in the survey PDF</name>
  <files>app/Services/SurveyPdfService.php, resources/views/pdf/site-survey/summary.blade.php</files>
  <action>
**Step A — Eager-load the questions relation in `SurveyPdfService.php`:**

At line 35, change:
```php
$survey->loadMissing('rooms.photos');
```
to:
```php
$survey->loadMissing(['rooms.photos', 'rooms.questions']);
```
This prevents N+1 inside the per-room loop in the Blade. Do NOT touch `buildBlank()` or `buildFieldFormContents()` — neither renders question rows.

**Step B — Add the "Pre-install Checks" section in `summary.blade.php`:**

Insert a new block AFTER the existing "AV Requirements" block (which ends at line 111 with `@endif`) and BEFORE the "Engineer Findings" block at line 121 (`@if($hasEF)`).

Pattern (match existing visual idiom — h3 + table + label/value rows, same teal chrome already styled in `_styles.blade.php`):

```blade
{{-- ─────────────────────────────────────────────────────────────────
     Group 2.5 — Pre-install Checks (AI-generated SiteSurveyRoomQuestion rows)
     Surfaces the questions GenerateSurveyQuestionsJob produces for rooms
     with a matched solution type. Sorted by sort_order via the model
     relation. Suppressed when the room has no questions (no empty heading).
     ───────────────────────────────────────────────────────────────── --}}
@if($room->questions->isNotEmpty())
    @php
        $answerLabels = ['yes' => 'Yes', 'no' => 'No', 'other' => 'Other'];
    @endphp
    <h3>Pre-install Checks</h3>
    <table>
        @foreach($room->questions as $q)
            @php
                $answerKey   = strtolower((string) ($q->answer ?? ''));
                $answerLabel = $answerLabels[$answerKey] ?? '—';
                $other       = trim((string) ($q->other_text ?? ''));
            @endphp
            <tr>
                <td class="label">{{ $q->question }}</td>
                <td>
                    {{ $answerLabel }}@if($answerKey === 'other' && $other !== '') — {{ $other }}@endif
                </td>
            </tr>
        @endforeach
    </table>
@endif
```

Implementation rules:
- Use `$room->questions->isNotEmpty()` — the relation returns an Eloquent Collection so `isEmpty()/isNotEmpty()` is the idiomatic guard (NOT `count() > 0`).
- Render in TWO COLUMNS to match the existing `<table>` idiom — question on the left (in `.label` chrome so it gets the bold + grey-background eyebrow style), answer on the right.
- Show `—` (em-dash) when `$q->answer` is null/empty (unanswered) — matches the existing dash convention used throughout the file (e.g. line 17, 18, 19).
- When `answer === 'other'` AND `other_text` is non-empty, append " — {other_text}" after the "Other" label so the engineer's explanation is visible.
- When `answer === 'other'` but `other_text` is empty, just show "Other" alone (don't append a trailing dash).
- Do NOT add a new page-break before the section — let the natural flow handle it (matches Engineer Findings precedent at line 121).
- Do NOT modify `_styles.blade.php` — `.label`, `table`, `h3`, `tr:nth-child(even) td` are all reused as-is.

Do NOT touch `field-form.blade.php` (the printable blank form — out of scope per `<constraints>`).
Do NOT touch `blank.blade.php` (no rooms / no questions — irrelevant).
Do NOT modify any controller, route, model, migration, or seeder.
  </action>
  <verify>
    <automated>php -l app/Services/SurveyPdfService.php &amp;&amp; php artisan view:clear &amp;&amp; grep -n "rooms.questions" app/Services/SurveyPdfService.php &amp;&amp; grep -n "Pre-install Checks" resources/views/pdf/site-survey/summary.blade.php &amp;&amp; grep -n "\$room->questions->isNotEmpty" resources/views/pdf/site-survey/summary.blade.php</automated>
  </verify>
  <done>
- `php -l` reports "No syntax errors detected" for `SurveyPdfService.php`.
- `php artisan view:clear` succeeds (proves the Blade compiles).
- `grep` finds `'rooms.questions'` in the loadMissing call in `SurveyPdfService.php`.
- `grep` finds `Pre-install Checks` heading in `summary.blade.php`.
- `grep` finds `$room->questions->isNotEmpty()` guard in `summary.blade.php`.
- The new block is positioned after `@endif` on/around line 111 and before `@if($hasEF)` on/around line 121.
- Atomic commit: `feat(survey-260506-jbu): render AI pre-install questions per room in survey PDF`
  </done>
</task>

<task type="checkpoint:human-verify" gate="blocking">
  <name>Task 3: Regenerate Tilda survey PDF and confirm both fixes end-to-end</name>
  <what-built>
- Task 1: Quote-specific room overview text now leads in `av_requirements`; the static SolutionType checklist appears as an appended "Standard checks for this solution type:" block when both exist.
- Task 2: The survey PDF now renders a "Pre-install Checks" section per room when `SiteSurveyRoomQuestion` rows exist for that room.
  </what-built>
  <how-to-verify>
**Step A — Find the Tilda survey ID** (the one that produced the PDF the user complained about):
```bash
php artisan tinker --execute="echo \App\Models\SiteSurvey::query()->whereHas('project', fn(\$q) => \$q->where('project_name', 'like', '%Tilda%'))->latest('id')->value('id');"
```
Note the survey ID printed.

**Step B — Regenerate the PDF and capture the path:**
```bash
php artisan tinker --execute="\$s = \App\Models\SiteSurvey::find({SURVEY_ID}); echo app(\App\Services\SurveyPdfService::class)->buildSummary(\$s);"
```
Replace `{SURVEY_ID}` with the value from Step A. Note the absolute path printed.

**Step C — Open the regenerated PDF and visually confirm ALL of:**
1. **Conferencing rooms (OREGANO / CINNAMON / SAFFRON or whichever rooms map to a SolutionType):** The "AV Requirements / Planned AV Works" block shows the room's SPECIFIC scope text FIRST (the original quote overview), followed by the line "Standard checks for this solution type:" and then the 18-line generic checklist below. The specific text MUST come first.
2. **A "Pre-install Checks" section** appears below "AV Requirements" on at least one room (any room that triggered `GenerateSurveyQuestionsJob` — i.e. has `solution_type_id`). Each row shows the question on the left and either "Yes" / "No" / "Other — {explanation}" / "—" on the right.
3. **Rooms with NO AI questions** (e.g. fully manual room with no solution-type match) DO NOT show an empty "Pre-install Checks" heading — the section is suppressed entirely.
4. **Room-booking-panel rooms (Nutmeg / Project Room / Cardamon — no solution_type_id):** Their specific quote overview text still renders correctly under "Planned AV Works" — no regression versus the previous PDF.
5. **No layout breakage:** Page breaks behave sanely; no overlapping text; teal accent + grey-label chrome match the rest of the document.

If ANY of points 1-4 fail, describe which room and what's wrong; otherwise approve.

**Note:** Task 3 is verification only. NO code changes. NO commit. Capture the PDF path so it can be referenced in `260506-jbu-SUMMARY.md` and so the user can compare against the original complaint screenshot.
  </how-to-verify>
  <resume-signal>Type "approved" with the regenerated PDF path, or describe specific rooms/sections that don't match the expected output.</resume-signal>
</task>

</tasks>

<verification>
**Lint / compile gates (run before commit on Tasks 1 and 2):**
```bash
php -l app/Core/Modules/Survey/SurveyService.php
php -l app/Services/SurveyPdfService.php
php artisan view:clear
```
All three must report success.

**Sentinel grep (proves the bug-fix landed at the source):**
```bash
grep -c "= \$st->survey_checklist" app/Core/Modules/Survey/SurveyService.php
# expected: 0 — the unconditional-overwrite line is gone

grep -c "Standard checks for this solution type" app/Core/Modules/Survey/SurveyService.php
# expected: 1 — appended-block sentinel is present

grep -c "Pre-install Checks" resources/views/pdf/site-survey/summary.blade.php
# expected: 1 — the new heading exists

grep -c "rooms.questions" app/Services/SurveyPdfService.php
# expected: 1 — eager load is in place
```

**Negative-regression check (proves we didn't break the field-form / blank-form templates):**
```bash
grep -c "Pre-install Checks" resources/views/pdf/site-survey/field-form.blade.php
grep -c "Pre-install Checks" resources/views/pdf/site-survey/blank.blade.php
# both expected: 0 — only summary.blade.php should render the new section
```

**End-to-end (Task 3):** Real PDF regenerated for the Tilda survey, all five visual checkpoints pass.
</verification>

<success_criteria>
- [ ] `app/Core/Modules/Survey/SurveyService.php` no longer overwrites `$avRequirements` with `$st->survey_checklist`. New behaviour: overview primary, checklist appended only when both present.
- [ ] `resources/views/pdf/site-survey/summary.blade.php` renders a "Pre-install Checks" section per room when `$room->questions` is non-empty. Suppressed otherwise.
- [ ] `app/Services/SurveyPdfService.php` eager-loads `rooms.questions` (no N+1 in the Blade loop).
- [ ] Two atomic commits land with `feat(survey-260506-jbu):` prefix (Tasks 1 and 2).
- [ ] Tilda survey PDF regenerated; the five visual checkpoints in Task 3 all pass.
- [ ] Zero changes to: `SolutionTypeSeeder.php`, `GenerateSurveyQuestionsJob.php`, `SiteSurveyRoomQuestion` schema, public engineer wizard, controllers, routes, migrations.
</success_criteria>

<output>
After completion, create `.planning/quick/260506-jbu-site-survey-pdf-stop-overwriting-surface/260506-jbu-SUMMARY.md` with:

1. **What changed** — the overwrite-fix and the new Pre-install Checks PDF section, line ranges referenced.
2. **Commits** — both `feat(survey-260506-jbu): ...` SHAs.
3. **Tilda PDF verification** — absolute path of the regenerated PDF + which checkpoints from Task 3 passed.
4. **Backward compatibility** — confirmation that legacy surveys with no questions render identically (Pre-install Checks section is suppressed) and rooms with no solution_type_id keep their specific overview text.
5. **Out of scope (deferred)** — explicit note that the L3 equipment-rule engine is a separate quick task; this fix is the L1+L2 layers only.
6. **Files to upload to live** — exact list of every modified file (mandatory per the local-edit-then-upload deployment workflow):
   - `app/Core/Modules/Survey/SurveyService.php`
   - `app/Services/SurveyPdfService.php`
   - `resources/views/pdf/site-survey/summary.blade.php`
</output>
</content>
</invoke>