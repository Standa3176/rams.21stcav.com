---
phase: 260503-ipc
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - resources/views/layouts/app.blade.php
  - resources/views/site-survey/edit.blade.php
  - resources/views/site-survey/_room-form.blade.php
  - resources/views/projects/edit.blade.php
  - resources/views/om-manual/edit.blade.php
  - resources/views/om-manual/edit-devices.blade.php
  - resources/views/rams/review.blade.php
  - resources/views/rams/create.blade.php
  - resources/views/worksheets/show.blade.php
autonomous: false
requirements:
  - QUICK-260503-ipc
must_haves:
  truths:
    - "Every editable form view shows logical input groups visually grouped inside a teal-headed section card (using existing SCC v2 .section-heading)."
    - "Required text/number/email/date inputs and required textareas that are EMPTY render with a soft red-tinted background and red-tinted border so engineers can see at a glance which fields are unfilled."
    - "Required <select> elements whose current selection is the empty placeholder option render with the same soft-red treatment."
    - "Inputs explicitly marked as optional (notes, comments, internal references etc.) via [data-optional] or .is-optional do NOT receive the red empty-state treatment."
    - "Site survey collapsible room cards continue to expand/collapse exactly as before — the JS toggle, Alpine bindings, and existing inline header background logic in _room-form.blade.php are NOT broken by the new wrapper styles."
    - "Zero PHP / controller / route / FormRequest / service / migration changes are introduced — git diff stat for app/, routes/, database/, config/ is clean."
  artifacts:
    - path: "resources/views/layouts/app.blade.php"
      provides: ".form-section card styles + .form-section__header cream tint bar + global :placeholder-shown empty-state rules + [data-optional]/.is-optional opt-out + select empty-option rule + textarea empty-state rule"
      contains: ".form-section"
    - path: "resources/views/site-survey/edit.blade.php"
      provides: "Logical blocks (Project Details, Site Contact, Project Manager, Areas/Rooms) wrapped in .form-section cards. Inputs lacking placeholders gain placeholder=' '. Optional fields (general_notes, site_address, visit_time) marked data-optional."
    - path: "resources/views/site-survey/_room-form.blade.php"
      provides: "Per-room sub-form input groups gain placeholder=' ' on text/number/textarea inputs that lack one. Pure-notes textareas (notes, access_notes, av_requirements) marked data-optional. Existing room-card collapse styling untouched."
    - path: "resources/views/projects/edit.blade.php"
      provides: "form-grid-2 wrapped in .form-section card with teal heading 'Project Details'. ref/notes/works_description marked data-optional."
    - path: "resources/views/om-manual/edit.blade.php"
      provides: "Equipment List JSON textarea wrapped in .form-section with teal section heading 'Equipment List' (replaces existing inline h2). Status summary card kept."
    - path: "resources/views/om-manual/edit-devices.blade.php"
      provides: "Per-room device tables already use .card; inputs gain placeholder=' ' so empty cells highlight red. All device fields are optional-by-design (asset capture only-when-known) so EVERY device input gets data-optional — empty-state treatment must NOT fire here per project domain rule (extracted O&M intentionally allows blanks)."
    - path: "resources/views/rams/review.blade.php"
      provides: "RAMS reviewed_data tab panels: each <h3 class='section-heading'> heading wrapped in a .form-section. NOTE: this file has its OWN local .section-heading override (font-size:1rem, no teal ::before — see lines 105-115). DO NOT touch the local override; the new .form-section wrapper provides the card chrome and the local heading style continues to render the title text. Optional textareas (notes, descriptions) marked data-optional."
    - path: "resources/views/rams/create.blade.php"
      provides: "Existing .section-block divs stay (already act as section cards). Add placeholder=' ' to inputs lacking one + data-optional to non-required inputs in optional sections (B Operations, C Document Control, H Engineering Team, I Emergency Contact, J Document Author)."
    - path: "resources/views/worksheets/show.blade.php"
      provides: "Status bar, Client Sign-Off Link card, and room accordion remain functional. Wrap the existing top-of-page status + sign-off-link blocks in .form-section grouping where it improves visual hierarchy. (No editable form fields exist on this view — empty-state CSS still safely no-ops because there are no placeholder-shown inputs in scope.)"
  key_links:
    - from: "resources/views/layouts/app.blade.php"
      to: ".form-control:placeholder-shown:not([data-optional]):not(.is-optional):required"
      via: "global CSS rule in <style> block"
      pattern: "placeholder-shown.*required"
    - from: "resources/views/layouts/app.blade.php"
      to: "select.form-control:has(option[value=''][selected]):required, select.form-control:required:invalid"
      via: "select empty-option rule (use :invalid which fires when required select has no value)"
      pattern: "select.*required.*invalid"
    - from: "all 8 form views"
      to: "every required <input>/<textarea> without an explicit placeholder"
      via: "placeholder=\" \" attribute (single-space — required for :placeholder-shown to evaluate empty correctly across browsers)"
      pattern: "placeholder=\" \""
    - from: "all 8 form views (optional fields)"
      to: "data-optional attribute on the input/textarea/select element"
      via: "explicit opt-out marker"
      pattern: "data-optional"
---

<objective>
UI polish across all 5 editable forms (8 view files) so engineers can scan a long form and instantly see which fields they have not yet filled.

Two visual changes, presentation-only:

1. **Section cards** — wrap each logical block in a `.form-section` card with a cream-tinted header bar that uses the existing SCC v2 teal `.section-heading` (already defined in `layouts/app.blade.php` lines 753-790: teal-700, uppercase, letter-spaced, `::before` accent strip). Adds visual hierarchy without inventing new colour tokens.

2. **Empty-field highlight** — required inputs whose value is empty get a soft red-tinted background + red-tinted border. Implemented as **pure CSS** using `:placeholder-shown` + `:required` + a `[data-optional]`/`.is-optional` opt-out, so:
   - Zero Alpine bindings
   - Zero JS event listeners
   - Zero controller / FormRequest / validation changes
   - Highlight clears the moment the user types
   - Optional fields (notes, comments, internal references) explicitly excluded

Purpose: reduce engineer "did I forget anything?" scanning time on long forms (site survey edit = 457 lines, RAMS review = 1041 lines, RAMS create = 858 lines).

Output: 9 files modified — 1 layout (CSS additions only) + 8 form views (markup wrapping + `placeholder=" "` attribute additions + `data-optional` markers).
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@CLAUDE.md
@.planning/STATE.md

# Layout — design tokens + existing .section-heading live here
@resources/views/layouts/app.blade.php

# Form views to edit
@resources/views/site-survey/edit.blade.php
@resources/views/site-survey/_room-form.blade.php
@resources/views/projects/edit.blade.php
@resources/views/om-manual/edit.blade.php
@resources/views/om-manual/edit-devices.blade.php
@resources/views/rams/review.blade.php
@resources/views/rams/create.blade.php
@resources/views/worksheets/show.blade.php

<interfaces>
<!-- Existing CSS classes to reuse (do NOT redefine; do NOT replace tokens) -->

From resources/views/layouts/app.blade.php (already defined):

```css
/* Lines 753-773 — base section heading (teal accent strip + uppercase tracked) */
.section-heading {
    font-size: .75rem; font-weight: 700; color: var(--teal-700);
    letter-spacing: .10em; text-transform: uppercase;
    padding-bottom: .55rem; margin-bottom: 1rem;
    border-bottom: 1px solid var(--ink-100);
    display: flex; align-items: center; gap: 8px;
}
.section-heading::before {
    content: ''; display: inline-block;
    width: 4px; height: 14px;
    background: var(--teal-700); border-radius: 2px;
}

/* Lines 791-798 — existing card-style block */
.section-block {
    background: var(--surface);
    border-radius: var(--radius);
    border: 1px solid var(--border);
    box-shadow: var(--shadow-xs);
    padding: 1.5rem;
    margin-bottom: 1.25rem;
}

/* Lines 704-723 — form control */
.form-control {
    width: 100%; padding: .5rem .75rem;
    border: 1px solid #D1D5DB;
    border-radius: var(--radius-sm);
    background: var(--surface);
    /* ... transitions, focus, is-invalid */
}
```

Design tokens already available (do NOT introduce new ones):
- `--paper`         #F7F6F2  (cream surface)
- `--paper-2`       #F2EEE6  (deeper cream)
- `--teal-700`      #0F5963
- `--teal-100`      #DAEAEA
- `--ink-900`       #0F1418
- `--ink-300`       #C5CCD3
- `--ink-200`       #DDE2E7  (= --border alias)
- `--ink-100`       #EDEFF2
- `--danger`        #B33A2C
- `--danger-light`  #F7E2DD
- `--surface`       #FFFFFF
- `--shadow-xs`     0 1px 2px rgba(15,23,42,.04)
- `--radius`        10px
- `--radius-sm`     6px

CRITICAL — local override in rams/review.blade.php (lines 105-115) redefines `.section-heading` to a non-uppercase 1rem heading. DO NOT touch this local override. Only add the .form-section wrapper around its existing headings. The local style continues to render headings inside RAMS review with its own look — that is intentional and out of scope.
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: Add .form-section CSS + global empty-field highlight rules to layouts/app.blade.php</name>
  <files>resources/views/layouts/app.blade.php</files>
  <action>
    Add a new CSS block to the existing `<style>...</style>` block in `resources/views/layouts/app.blade.php`. Insert it AFTER the existing `.section-block` definition (around line 798) and BEFORE the TABLES section (around line 800).

    Use ONLY existing design tokens — `--paper`, `--paper-2`, `--teal-700`, `--teal-100`, `--ink-100`, `--ink-200`, `--ink-300`, `--ink-900`, `--danger`, `--danger-light`, `--surface`, `--shadow-xs`, `--radius`, `--radius-sm`. Do NOT introduce new colour variables.

    Add the following CSS (verbatim — copy exactly):

    ```css
    /* ═══════════════════════════════════════════════════════════════
       FORM SECTIONS — card wrapper for logical blocks of inputs
       Pairs with the existing .section-heading (teal accent strip)
    ═══════════════════════════════════════════════════════════════ */
    .form-section {
        background: var(--surface);
        border: 1px solid var(--ink-200);
        border-radius: var(--radius);
        box-shadow: var(--shadow-xs);
        margin-bottom: 1.25rem;
        overflow: hidden; /* keeps the cream header tint inside the radius */
    }
    .form-section__header {
        background: linear-gradient(180deg, var(--paper) 0%, var(--paper-2) 100%);
        border-bottom: 1px solid var(--ink-100);
        padding: .85rem 1.25rem;
    }
    /* When a .section-heading sits inside .form-section__header, drop its
       trailing border + bottom margin (the header bar already provides them). */
    .form-section__header .section-heading {
        margin: 0;
        padding-bottom: 0;
        border-bottom: 0;
    }
    .form-section__body {
        padding: 1.25rem 1.5rem 1rem;
    }
    @media (max-width: 768px) {
        .form-section__header { padding: .7rem 1rem; }
        .form-section__body   { padding: 1rem 1rem .5rem; }
    }

    /* ═══════════════════════════════════════════════════════════════
       EMPTY-FIELD HIGHLIGHT — pure CSS, opt-out via [data-optional]
       or .is-optional. Fires on REQUIRED inputs/textareas/selects
       whose value is empty. Clears instantly the moment a user types.
    ═══════════════════════════════════════════════════════════════ */
    /* Text-style inputs and textareas — :placeholder-shown evaluates
       empty when the input has a placeholder attribute (single space ' '
       qualifies) and no user-typed value. */
    .form-control:placeholder-shown:required:not([data-optional]):not(.is-optional):not(:focus) {
        background-color: #FDF1EE; /* derived from --danger-light, slightly softer */
        border-color: #E8B7AE;
    }
    /* Selects — :required + :invalid fires when current option has no value
       (the placeholder option must have value="" — already the project's
       convention; see site-survey/edit.blade.php line 350). */
    select.form-control:required:invalid:not([data-optional]):not(.is-optional):not(:focus) {
        background-color: #FDF1EE;
        border-color: #E8B7AE;
    }
    /* Date / number / email inputs that don't visually support placeholders
       in all browsers: still rely on :required:invalid as a fallback. */
    input.form-control[type="date"]:required:invalid:not([data-optional]):not(.is-optional):not(:focus),
    input.form-control[type="number"]:required:invalid:not([data-optional]):not(.is-optional):not(:focus),
    input.form-control[type="email"]:required:invalid:not([data-optional]):not(.is-optional):not(:focus) {
        background-color: #FDF1EE;
        border-color: #E8B7AE;
    }
    /* Project rule: server-side .is-invalid (validation failures from
       Laravel) must always win — keep the existing red ring at full
       strength even on otherwise-empty inputs. */
    .form-control.is-invalid {
        background-color: #FDF1EE;
        border-color: var(--danger);
    }
    ```

    Two surgical reasons for these specific selectors:
    - `:placeholder-shown` requires the input to literally have a `placeholder` attribute. We solve this in Task 2 by adding `placeholder=" "` (single space) to required inputs that lack one — this satisfies the selector without showing user-visible placeholder text.
    - `:not(:focus)` prevents the red flash on the field the user is currently typing into (UX win — no nagging during input).
    - The colour `#FDF1EE` is derived from `--danger-light` (#F7E2DD) but lightened ~5% so the tint reads as "soft warning" not "hard error" — engineers should see it as gentle, not alarming. Border `#E8B7AE` is the same family. Both are derived locally from existing danger tokens (no new design-token additions).

    Do NOT change any other CSS in the file. Do NOT replace tokens. Do NOT touch the `.section-heading` definition. Do NOT modify the `.section-block` definition (it stays as a separate, slightly different style — used directly by `rams/create.blade.php`).
  </action>
  <verify>
    <automated>grep -c "form-section__header" "resources/views/layouts/app.blade.php" must return >= 2; grep -c "placeholder-shown:required:not" "resources/views/layouts/app.blade.php" must return >= 1; existing token grep "var(--teal-700)" count unchanged from baseline (no token deletions).</automated>
  </verify>
  <done>
    `.form-section`, `.form-section__header`, `.form-section__body`, and the four `:placeholder-shown` / `:invalid` empty-state rules exist in `layouts/app.blade.php`. No existing CSS rules were modified. No new colour CSS variables were added. Page still renders (open any existing page — sidebar / header / cards look identical).
  </done>
</task>

<task type="auto">
  <name>Task 2: Wrap form blocks in .form-section + add placeholder=" " + data-optional markers across all 8 form views</name>
  <files>resources/views/site-survey/edit.blade.php, resources/views/site-survey/_room-form.blade.php, resources/views/projects/edit.blade.php, resources/views/om-manual/edit.blade.php, resources/views/om-manual/edit-devices.blade.php, resources/views/rams/review.blade.php, resources/views/rams/create.blade.php, resources/views/worksheets/show.blade.php</files>
  <action>
    Apply the following minimal markup edits per file. **No PHP, no `@php` blocks, no controller calls, no validation changes, no Alpine additions.**

    Universal rules (apply across every file):
    - Required `<input type="text|email|number|date">` and required `<textarea>` that have NO `placeholder=` attribute → add `placeholder=" "` (literal single space). This makes `:placeholder-shown` evaluate correctly. Do NOT change inputs that already have a meaningful placeholder.
    - Inputs/textareas/selects that the user can legitimately leave blank get `data-optional` attribute. Do NOT add `data-optional` to anything that already has the `required` HTML attribute UNLESS the project clearly treats it as optional in practice (in this scope, only the asset-register device fields fall into that bucket — see file-by-file rules below).
    - Wrap each logical block in:
      ```blade
      <div class="form-section">
          <div class="form-section__header">
              <h2 class="section-heading">Section Title</h2>
          </div>
          <div class="form-section__body">
              {{-- existing fields --}}
          </div>
      </div>
      ```
      For files that already use `<div class="section-block"><h2 class="section-heading">…</h2>…</div>` (rams/create.blade.php), leave them as-is — they already act as section cards. Only add the `placeholder=" "` and `data-optional` markers there.

    ───────────────────────────────────────────────────────────────
    File 1: resources/views/site-survey/edit.blade.php
    ───────────────────────────────────────────────────────────────
    - Already uses `<div class="section-block"><h2 class="section-heading">…</h2>…</div>` for "Project Details" (line 30) and "Areas / Rooms" (line 137). Replace BOTH `<div class="section-block">…<h2 class="section-heading">X</h2>…</div>` wrappers with the new `.form-section`/`.form-section__header`/`.form-section__body` structure (move the `<h2>` into the header div, move everything else into the body div).
    - Inside Project Details, the existing inline `<fieldset>` for Project Manager (lines 105-124) — leave as-is; do NOT replace it with a nested `.form-section` (it is intentionally visually nested inside Project Details). It already has a `<legend>` styled in teal — that styling stays.
    - The Site Contact form-grid-2 (lines 91-102) — wrap it in a thin `<div class="form-section" style="margin-bottom:.75rem;"><div class="form-section__header"><h2 class="section-heading">Site Contact</h2></div><div class="form-section__body">…</div></div>` so it visually splits from the Project Manager fieldset above it. Or — simpler — leave the .form-grid-2 inline and just add a `<h3 class="section-heading" style="margin-top:1rem;">Site Contact</h3>` heading above it. Choose whichever produces tighter visual flow during execution.
    - `placeholder=" "` additions:
      - `project_name` (required) — has no placeholder → add `placeholder=" "`.
    - `data-optional` additions:
      - `general_notes` textarea (line 132)
      - `site_address` textarea (line 128)
      - `visit_time` (line 85 — already has its own placeholder so will not flag, but add data-optional defensively)
      - `project_ref`, `client_name`, `surveyor_name`, `survey_date`, `site_contact_name`, `site_contact_phone`, `pm_name`, `pm_phone`, `pm_email` — none of these are server-side required for the survey, so add `data-optional` to all of them. The required attribute is only on `project_name`.
    - DO NOT touch the `<script>` block (the `roomCardHtml()` template literal stays exactly as-is — Task 2 only edits server-rendered Blade markup, not the JS-built dynamic room template).

    ───────────────────────────────────────────────────────────────
    File 2: resources/views/site-survey/_room-form.blade.php
    ───────────────────────────────────────────────────────────────
    - This is a Blade partial included inside the Areas/Rooms section card from File 1. It already has its own `.room-card` chrome with collapsible header — DO NOT wrap the whole partial in a `.form-section` (would conflict with `.room-card` and break the toggle JS in `toggleAdminCard()`).
    - `placeholder=" "` additions:
      - `room_name` (required, line 198) — already has placeholder. Skip.
    - `data-optional` additions on every textarea + every non-required input:
      - `av_requirements`, `existing_cabling`, `cable_route_desc`, `cable_route_from`, `cable_route_to`, `notes`, `access_notes`, `existing_condition`, `items_to_remove`, `items_to_retain`, `room_ref`, `floor`, all `*_count` numbers, all `*_m` numbers, `bg_noise_db`, `display_size_in`, etc. → all data-optional.
    - DO NOT add `data-optional` to `room_name` — it is required.
    - DO NOT change any `style=` attributes, the collapse toggle, or the kit-list drawer logic.

    ───────────────────────────────────────────────────────────────
    File 3: resources/views/projects/edit.blade.php
    ───────────────────────────────────────────────────────────────
    - Currently the form sits inside a single `<div class="card">` (line 12). Replace that wrapper with `.form-section` chrome:
      ```blade
      <div class="form-section" style="max-width:720px;">
          <div class="form-section__header">
              <h2 class="section-heading">Project Details</h2>
          </div>
          <div class="form-section__body">
              <form …> … </form>
          </div>
      </div>
      ```
    - `placeholder=" "` additions: `name`, `client_name`, `site_address` (all required, none have placeholders). Add `placeholder=" "` to each.
    - `data-optional` additions: `ref`, `works_description`, `notes` (the three non-required fields).

    ───────────────────────────────────────────────────────────────
    File 4: resources/views/om-manual/edit.blade.php
    ───────────────────────────────────────────────────────────────
    - The "Equipment List (JSON)" card (line 76) — replace its `<div class="card" style="…">` wrapper with `.form-section`:
      ```blade
      <div class="form-section">
          <div class="form-section__header">
              <h2 class="section-heading">Equipment List (JSON)</h2>
          </div>
          <div class="form-section__body">
              <form …> … </form>
          </div>
      </div>
      ```
    - Remove the existing inline `<h2 style="font-size:1rem; font-weight:700; margin-bottom:1rem;">Equipment List (JSON)</h2>` (line 77) — the section heading is now in the header.
    - The `extracted_json` textarea uses class `form-input` (NOT `form-control`) so the empty-field rule will not fire on it (it is JSON content — not user-typed sentences — and should never be highlighted red). Add `data-optional` defensively.
    - The Generate O&M card (line 95) and the status summary card (line 64) — leave as plain `.card` blocks (no need to wrap; no inputs inside the latter, and the former is just a single button).

    ───────────────────────────────────────────────────────────────
    File 5: resources/views/om-manual/edit-devices.blade.php
    ───────────────────────────────────────────────────────────────
    - **Critical project rule:** asset register fields (serial, IP, VLAN, port, firmware, asset tag, MAC) are intentionally optional — engineers populate them when known. The project description (top of file, line 36-40) literally says "Fields can be left blank where unknown".
    - Therefore: add `data-optional` to EVERY `<input>` in the device table. Do NOT let the empty-field highlight fire on this page.
    - The per-room cards already use `<div class="card">` with their own room-name `<h2>`. Leave them as-is — no `.form-section` wrap needed; the empty-state opt-out is the meaningful change here.
    - These inputs use class `form-input` not `form-control`, so the rule will not fire anyway — but add `data-optional` for clarity + future-proofing in case the class is harmonised later.

    ───────────────────────────────────────────────────────────────
    File 6: resources/views/rams/review.blade.php
    ───────────────────────────────────────────────────────────────
    - **Local override warning:** lines 105-115 of this file redefine `.section-heading` locally (font-size:1rem, no teal `::before`). DO NOT touch that override. The new `.form-section__header` cream bar will visually frame whatever style of heading sits inside it.
    - Each `<h3 class="section-heading" style="margin-top:1rem;">…</h3>` (lines 404, 446, 478, 499, 528, 594, 638, 689, 750, 785, 865, 933, 969, 1003, 1024) and `<h2 class="section-heading">Project Details</h2>` (line 349) — wrap each heading PLUS the input block that follows it (until the next sibling section heading or end-of-card) in a `.form-section`.
    - This file uses tabbed `.rams-tab-panel` containers — wrap inside, not around the panels. Stay surgical: each section heading + its block becomes one `.form-section`.
    - `data-optional` additions: every textarea that captures notes/descriptions/methodology free-text. Specifically scan for any `<textarea name=…>` that isn't `required` or doesn't render a structured value (hazard step text, method statement step text are NOT optional — they're the document content). Apply data-optional to: any "notes" / "comments" / "additional info" / "internal reference" style fields.
    - This is a 1041-line file — work tab-by-tab, do NOT try to hold the whole file in memory at once. Use Edit tool with targeted multi-line blocks; do NOT Write the whole file.

    ───────────────────────────────────────────────────────────────
    File 7: resources/views/rams/create.blade.php
    ───────────────────────────────────────────────────────────────
    - Already uses `<div class="section-block"><h2 class="section-heading">A — Project Details</h2>…</div>` for sections A through J. The `.section-block` style (defined in app.blade.php line 791) already provides card chrome — do NOT replace these with `.form-section`. Leave the section structure as-is.
    - `placeholder=" "` additions: required inputs lacking placeholders. Specifically `project_name` (line 62), `client_name` (line 78), and any other input that has the `required` attribute and no `placeholder=` attribute.
    - `data-optional` additions: every input/select/textarea inside sections explicitly labelled `(optional)` in their heading — sections B, C, H, I, J. Sections D (Scope of Works), E (Hazards), F (PPE), G (Persons at Risk) are required content; do NOT add data-optional there.

    ───────────────────────────────────────────────────────────────
    File 8: resources/views/worksheets/show.blade.php
    ───────────────────────────────────────────────────────────────
    - This view has NO editable form inputs — only a read-only `<input type="text" :value="url" readonly>` (line 160) and the room accordion (read-only). The "edit/sign-off" actually lives in `worksheets/public-show.blade.php`, but per the constraint we only touch the 8 listed files.
    - Minimal change: wrap the Status bar (line 129) and the Client Sign-Off Link card (line 154-176) in a single `.form-section` titled "Sign-Off Status" so the new section-card pattern is visible and consistent on this page too. The room accordion below it stays as separate `.survey-room-card` instances (already correctly card-structured).
    - Mark the read-only URL input with `data-optional` so even if the empty-state CSS were ever extended to read-only inputs, this one would not flag.
    - Do NOT touch the room accordion JS / Alpine bindings.

    ───────────────────────────────────────────────────────────────
    Cross-cutting verification before committing each file:
    ───────────────────────────────────────────────────────────────
    - `git diff app/ routes/ database/ config/` MUST be empty after every save.
    - Every Blade file must still parse: run `php artisan view:clear` then load the page in browser (Task 3 covers this).
    - No `@php` blocks added, no new helpers, no Alpine x-data introduced.
  </action>
  <verify>
    <automated>php artisan view:clear &amp;&amp; grep -rE 'form-section__header|form-section__body' resources/views/site-survey/edit.blade.php resources/views/projects/edit.blade.php resources/views/om-manual/edit.blade.php | wc -l must be >= 6 (3 files * 2 markers); grep -rE 'data-optional' resources/views/site-survey/ resources/views/om-manual/edit-devices.blade.php | wc -l must be >= 15; git diff --stat app/ routes/ database/ config/ must produce ZERO lines.</automated>
  </verify>
  <done>
    All 8 form views modified. Section cards visible on every form. `data-optional` markers in place on intentionally-optional fields. `placeholder=" "` added to required inputs that lacked one. No PHP / route / migration / service / FormRequest changes. `php artisan view:clear` runs clean.
  </done>
</task>

<task type="checkpoint:human-verify" gate="blocking">
  <name>Task 3: Visual verification across all 5 forms</name>
  <what-built>
    Section card chrome (cream-tinted header bar with teal section heading) wraps logical input blocks across 8 form views. Required, empty inputs render with a soft red-tinted background + border. Optional fields (notes, comments, internal references, asset register fields) do NOT highlight red.
  </what-built>
  <how-to-verify>
    Boot the dev server (`php artisan serve` if not running, plus `npm run dev`). Visit each of the following URLs and confirm visually:

    1. **Project edit** — `/projects/{any}/edit`
       - Single `.form-section` card wrapping the form, cream header bar, teal "Project Details" heading.
       - `name`, `client_name`, `site_address` start empty after a fresh load → soft red tint visible.
       - Type one character into `name` → red tint clears immediately.
       - `ref`, `works_description`, `notes` stay neutral (white) when empty.

    2. **Site survey edit** — `/site-surveys/{any}/edit`
       - "Project Details" and "Areas / Rooms" each in their own `.form-section` card.
       - `project_name` flags red when empty; `general_notes` / `site_address` stay neutral.
       - Click "+ Add Room" → new room card appears with the existing inline JS-built chrome (collapse toggle still works). Confirm no console errors.
       - Open an existing room (click header) → required `room_name` input flags red if empty.

    3. **O&M edit** — `/om-manuals/{any}/edit`
       - Equipment List JSON wrapped in `.form-section`. JSON textarea NOT highlighted red (uses `form-input` class + has `data-optional`).
       - Status summary card and Generate card render unchanged.

    4. **O&M asset register** — `/om-manuals/{any}/edit-devices`
       - Per-room `.card` blocks render unchanged.
       - Empty serial / IP / VLAN inputs stay neutral white (data-optional applied; this is intentional per project rule "fields can be left blank where unknown").

    5. **RAMS create** — `/rams/create?project={any}`
       - Sections A-J each render in their existing `.section-block` cards.
       - `project_name`, `client_name` (Section A required) flag red when empty.
       - All inputs in optional sections (B, C, H, I, J) stay neutral when empty.

    6. **RAMS review** — `/rams/{any}/edit` (or wherever the reviewed_data editor lives — `/rams/{id}` may redirect)
       - Each section heading sits inside a `.form-section__header` cream bar.
       - The local `.section-heading` style (1rem, no teal `::before`) renders as before — DO NOT confuse "visually different from create.blade.php" with a bug.
       - Tab switching still works.

    7. **Worksheet show** — `/worksheets/{any}` (any worksheet in draft or final status)
       - Status bar + Client Sign-Off Link wrapped together in a `.form-section` titled "Sign-Off Status".
       - Room accordion below it expands/collapses normally.
       - The sign-off URL input is not flagged red (data-optional + readonly).

    8. **Console + git check**:
       - Open browser DevTools → Console tab on each page → ZERO new errors.
       - In repo root: `git status` → only the 9 listed files modified. `git diff --stat app/ routes/ database/ config/` → empty.
  </how-to-verify>
  <resume-signal>Type "approved" if all 8 visual checks pass, or describe specific issues per page (e.g. "Site survey room card collapse broken", "Required field on RAMS create not flagging").</resume-signal>
</task>

</tasks>

<verification>
- All 9 files modified, no others.
- `git diff --stat app/ routes/ database/ config/` = 0 lines.
- `php artisan view:clear` runs without errors.
- No console errors on any of the 5 form pages.
- Required empty inputs visibly red-tinted; optional empty inputs stay white.
- Section cards visible on every form with the existing teal `.section-heading`.
- Site-survey room-card collapse JS still works.
- RAMS review tab navigation still works.
</verification>

<success_criteria>
1. Engineer opens any of the 5 forms → can see at a glance which required fields are still empty (soft red tint).
2. Logical input blocks visually grouped in cream-headed section cards using existing SCC v2 teal `.section-heading`.
3. Zero backend / business-logic changes (controllers, services, routes, migrations, FormRequests, validation rules untouched).
4. Existing JS interactivity (room collapse, tab switching, kit drawer, Alpine bindings) survives unchanged.
5. Optional fields (notes, comments, asset register) stay neutral and never nag the user.
</success_criteria>

<output>
After completion, create `.planning/quick/260503-ipc-ui-polish-across-editable-forms-section-/260503-ipc-SUMMARY.md` capturing:
- What got the .form-section wrapper vs what stayed as plain .section-block / .card
- Final list of inputs marked data-optional per file (so future devs know the convention)
- Any visual quirks discovered on RAMS review (the file with the local .section-heading override)
- Confirmation `git diff app/ routes/ database/ config/` produced 0 changes
</output>
