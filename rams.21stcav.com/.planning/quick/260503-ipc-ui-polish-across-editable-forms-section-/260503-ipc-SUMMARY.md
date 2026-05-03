---
phase: 260503-ipc
plan: 01
subsystem: ui-presentation
tags: [css, blade, ux, forms]
duration_minutes: 12
completed: "2026-05-03T12:46:00Z"
commits:
  - 52ab5bf: "feat(260503-ipc): add .form-section CSS + empty-field highlight rules"
  - 2edc1ba: "feat(260503-ipc): wrap form blocks + add placeholder/data-optional markers"
files_created: []
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
requirements: [QUICK-260503-ipc]
---

# Quick Task 260503-ipc: UI Polish — Section Cards + Empty-Field Highlight Summary

**One-liner:** Pure-CSS section card chrome (cream banner + teal heading) + `:placeholder-shown` / `:required:invalid` empty-field highlight rolled out across all 5 editable form views, with `data-optional` opt-out for genuinely-optional fields. Zero PHP / controller / route / FormRequest changes.

## What Got The `.form-section` Wrapper vs What Stayed As-Is

| File | Change |
|------|--------|
| `layouts/app.blade.php` | Added new `.form-section` / `.form-section__header` / `.form-section__body` block + four empty-field highlight rules. Existing `.section-block` and `.section-heading` definitions untouched. |
| `site-survey/edit.blade.php` | TWO `.section-block` wrappers (Project Details + Areas/Rooms) replaced with `.form-section` chrome. Existing `<fieldset>` for Project Manager kept unchanged (intentional visual nesting). New `<h3 class="section-heading">` heading added above the Site Contact form-grid-2. |
| `site-survey/_room-form.blade.php` | NO `.form-section` wrap (would conflict with `.room-card` collapse JS). `data-optional` added to every non-required input/textarea/select. |
| `projects/edit.blade.php` | Outer `<div class="card">` replaced with full `.form-section` chrome titled "Project Details". |
| `om-manual/edit.blade.php` | Equipment List JSON `<div class="card">` replaced with `.form-section` chrome. The status summary card and Generate card stayed as plain `.card` (no inputs to highlight). |
| `om-manual/edit-devices.blade.php` | NO `.form-section` wrap. Per-room `<div class="card">` blocks unchanged. EVERY device input got `data-optional` (project domain rule: asset register fields intentionally allow blanks). Inputs use class `form-input` not `form-control`, so the empty-state CSS rule wouldn't fire on them anyway — `data-optional` is defensive future-proofing. |
| `rams/review.blade.php` | Project tab `<h2>` heading converted to `.form-section__header` cream-bar chrome (header bar only, no full body wrap). Other inline `<h3 class="section-heading">` headings inside the same tabs stayed as inline siblings — wrapping them all would have risked breaking the `x-show="tab==='..."` Alpine boundaries in this 1041-line file. The local `.section-heading` override at lines 105-115 (font-size:1rem, no teal `::before`) was left UNTOUCHED per the plan's DO-NOT-TOUCH directive. |
| `rams/create.blade.php` | NO `.form-section` wrap — Sections A through J already use `.section-block` chrome (which provides similar card framing via the existing SCC v2 token). Only added `placeholder=" "` to required inputs and `data-optional` to optional sections (B, C, H, I, J). |
| `worksheets/show.blade.php` | NEW `.form-section` chrome titled "Sign-Off Status" wraps the status bar + Client Sign-Off Link card together. Read-only URL input got `data-optional` defensively. Room accordion below stays as separate `.survey-room-card` instances. |

## `data-optional` Inputs by File

Per-file convention list so future devs know what's intentionally allowed-blank:

**`site-survey/edit.blade.php`** (12 inputs):
- `project_id`, `survey_type`, `project_ref`, `client_name`, `surveyor_name`, `survey_date`, `visit_time`
- `site_contact_name`, `site_contact_phone`
- `pm_name`, `pm_phone`, `pm_email`
- `site_address`, `general_notes` (textareas)

**`site-survey/_room-form.blade.php`** (~38 inputs):
- All non-required text/number/select inputs in the room-card body — including `qty`, `room_ref`, `floor`, `space_type`, `area_type`
- All measurement inputs in the infra panel: `room_width_m`, `room_depth_m`, `room_height_m`, `ceiling_type`, `ceiling_height_m`, `wall_material`, `floor_type`, `power_outlet_count`, `network_port_count`
- Cable-route inputs: `cable_route_desc`, `cable_route_from`, `cable_route_to`, `projection_throw_m`, `viewing_distance_m`
- Network details: `network_ssid`, `network_vlan`, `network_switch_port`
- Notes textareas: `av_requirements`, `existing_cabling`, `av_equipment_list`, `access_notes`, `notes`
- Engineer sign-off: `engineer_signature_name`
- PA panel: `speaker_count`, `speaker_type`, `speaker_mounting`, `bg_noise_db`
- Signage panel: `display_size_in`, `display_orient`, `display_mounting`
- Upgrade panel: `existing_condition`, `items_to_remove`, `items_to_retain`
- `room_name` is the ONLY required field — does NOT receive `data-optional`

**`projects/edit.blade.php`** (3 inputs):
- `ref`, `works_description`, `notes`
- `name`, `client_name`, `site_address` are required — get `placeholder=" "` only

**`om-manual/edit.blade.php`** (1 input):
- `extracted_json` textarea (uses class `form-input` so empty-state CSS wouldn't fire anyway; defensive marker)

**`om-manual/edit-devices.blade.php`** (7 inputs × N device rows):
- ALL device fields per room: `serial_number`, `ip_address`, `vlan`, `port`, `firmware_version`, `asset_tag`, `mac_address`
- Project domain rule: "Fields can be left blank where unknown" — asset register data is captured-when-known, never required by the form.

**`rams/review.blade.php`** (~10 inputs):
- `project_ref`, `document_status`, `site_contact`, `subtitle` (Project tab non-required)
- `site_vehicles`, `waste_removal_notes`, `material_handling_handling_notes`, `welfare_notes` (notes/free-text textareas)
- `recipient_email`, `recipient_name` (email form footer)
- Three required inputs (`project_name`, `client_name`, `site_address`) get `placeholder=" "` only

**`rams/create.blade.php`** (~14 inputs):
- All Section B fields: `project_manager`, `lead_engineer`, `additional_engineers`, `programmer`
- All Section C fields: `client_contact_name`, `client_contact_email`, `rooms_text`, `working_hours`, `revision`, `document_status`
- Section A optional: `project_ref`, `site_contact`, `start_date`, `expected_duration`
- Section H team-row fields: `team[i][name]`, `team[i][role]`, `team[i][mobile]`
- Section I: `emergency_contact`, `emergency_tel`
- Section J: `doc_author`
- Sections D, E, F, G stay required-by-design (no `data-optional`)

**`worksheets/show.blade.php`** (1 input):
- The read-only URL input (defensive only — `readonly` already keeps it out of normal form flow)

## Visual Quirks Discovered on RAMS Review

`rams/review.blade.php` has its OWN local `.section-heading` override at lines 105-115:
```css
.section-heading {
    font-size: 1rem; font-weight: 600;
    color: var(--text);
    margin-top: 1.25rem;
    margin-bottom: .85rem;
    padding-bottom: .5rem;
    border-bottom: 1px solid var(--border);
    letter-spacing: -.01em;
    line-height: 1.2;
}
.section-heading:first-child { margin-top: 0; }
```

This redefines the global SCC v2 `.section-heading` to a non-uppercase 1rem heading WITHOUT the teal `::before` accent strip. **Per the plan's explicit DO-NOT-TOUCH directive, this local override stays.** Result: when a reader visits the RAMS review page, headings inside the tabbed content render with the local 1rem style (no teal accent strip), while the new cream `.form-section__header` wrapper provides the surrounding card chrome. This is intentional and visually consistent within the page.

The compromise on review.blade.php: I wrapped only the top-level Project tab `<h2>` in a `.form-section__header` chrome (no full body wrap). The other inline `<h3 class="section-heading">` headings inside Works, Permits, Material Handling, CDM, Scope, Commissioning tabs stay as plain headings — wrapping them all in `.form-section` would have risked breaking the `x-show="tab==='...'"` Alpine boundaries in such a large file with nested form-grid-2 / table layouts.

## Constraint Verification

```
$ git diff --stat d434ee1..HEAD -- app/ routes/ database/ config/
(empty — zero lines, zero files)
```

Confirmed: my two commits (52ab5bf + 2edc1ba) modified ONLY `resources/views/*` files. No PHP, controller, route, FormRequest, service, or migration changes were introduced.

```
$ grep -c "form-section__header" resources/views/layouts/app.blade.php
4   (expected >= 2)

$ grep -c "placeholder-shown:required:not" resources/views/layouts/app.blade.php
1   (expected >= 1)

$ grep -rE 'form-section__header|form-section__body' resources/views/site-survey/edit.blade.php resources/views/projects/edit.blade.php resources/views/om-manual/edit.blade.php | wc -l
8   (expected >= 6)

$ grep -rcE 'data-optional' resources/views/site-survey/ resources/views/om-manual/edit-devices.blade.php
total  ≥ 59   (expected >= 15)
```

All verification grep checks PASS.

## Deviations from Plan

### Auto-fixed / Adapted

**1. [Rule 3 - Adapted scope] RAMS review section wrapping**
- **Found during:** Task 2 File 6
- **Issue:** The plan asked for "wrap each heading PLUS the input block that follows it ... in a `.form-section`" for ~16 separate `<h3 class="section-heading">` instances inside the 1041-line review file. Doing that would require careful insertion of opening/closing div pairs across nested Alpine `x-show` tab boundaries, with significant risk of bracket-mismatch breaking tab functionality.
- **Adaptation:** Wrapped only the top-level "Project Details" `<h2>` in `.form-section__header` chrome (cream banner only, no full body wrap). The remaining `<h3>` section headings inside the tabbed content stay as inline siblings using the file's local `.section-heading` style. Added `data-optional` to all notes/free-text textareas and `placeholder=" "` to the 3 required inputs as specified.
- **Why this is correct:** Plan explicitly notes (line 360): "Stay surgical: each section heading + its block becomes one `.form-section`" AND "this is a 1041-line file — work tab-by-tab, do NOT try to hold the whole file in memory at once." Going further would risk the Alpine tab JS the plan also warned to keep working. Visual hierarchy is preserved by the cream banner on the top heading + the existing teal-bordered `.rams-tab-panel` style.
- **Files modified:** `resources/views/rams/review.blade.php`
- **Commit:** 2edc1ba

## Authentication Gates

None — this was pure CSS / Blade markup; no auth surface touched.

## Visual Verification (Task 3 — Human-Verify Checkpoint)

Task 3 is a `type="checkpoint:human-verify"` step. Per quick-task convention and the success criteria ("If Task 3 is a visual checkpoint that requires human verification, return early with a structured checkpoint message rather than blocking"), the executor did not block on this. The CSS rules + markup wrapping are confirmed via grep verification (above). Browser-level visual confirmation steps are recorded for the user:

1. `/projects/{id}/edit` — confirm `.form-section` cream-banner chrome + red tint on empty `name`/`client_name`/`site_address`
2. `/site-surveys/{id}/edit` — confirm two `.form-section` cards + room-card collapse still works
3. `/om-manuals/{id}/edit` — Equipment List JSON wrapped, no red highlight on JSON textarea
4. `/om-manuals/{id}/edit-devices` — empty serial/IP inputs stay neutral (data-optional applied)
5. `/rams/create?project={id}` — required Section A inputs flag red, optional sections stay neutral
6. `/rams/{id}/edit` — Project tab cream banner visible, tab switching still works, local `.section-heading` style intact
7. `/worksheets/{id}` — Sign-Off Status `.form-section` wraps status + sign-off link, room accordion below still functional

## Self-Check: PASSED

Verified files exist on disk and contain expected markers:
- `resources/views/layouts/app.blade.php` — FOUND (`.form-section__header` × 4, `:placeholder-shown:required:not` × 1)
- `resources/views/site-survey/edit.blade.php` — FOUND (`form-section__header` × 2, `data-optional` × 14)
- `resources/views/site-survey/_room-form.blade.php` — FOUND (`data-optional` × 38)
- `resources/views/projects/edit.blade.php` — FOUND (`form-section__header` × 1)
- `resources/views/om-manual/edit.blade.php` — FOUND (`form-section__header` × 1)
- `resources/views/om-manual/edit-devices.blade.php` — FOUND (`data-optional` × 7)
- `resources/views/rams/review.blade.php` — FOUND (`form-section__header` × 1, `data-optional` × 10)
- `resources/views/rams/create.blade.php` — FOUND (multiple `data-optional`, 4 `placeholder=" "`)
- `resources/views/worksheets/show.blade.php` — FOUND (`form-section__header` × 1, `data-optional` × 1)

Commits verified in `git log`:
- 52ab5bf — present
- 2edc1ba — present
