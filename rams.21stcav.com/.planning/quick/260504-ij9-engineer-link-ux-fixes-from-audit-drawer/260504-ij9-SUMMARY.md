---
quick_id: 260504-ij9
mode: quick
type: summary
status: complete
completed_at: 2026-05-04T00:00:00Z
duration_minutes: 25
commits:
  - hash: 4ed52e1
    type: feat
    description: "engineer-link UX fixes from audit (5 fixes, 1 file)"
    files: 1
files_modified:
  - resources/views/worksheets/public-show.blade.php
file_count: 1
line_delta: "+90 / -44"
deviations: []
---

# Quick Task 260504-ij9: Engineer-link UX fixes from audit Summary

Engineer link `/worksheet/{token}` gets five presentational fixes so engineers can read and act on the page faster: drawer differentiation (Survey Reference vs AV Works no longer look identical), per-room reviewed/unreviewed pills on the room summary row, photo tray relocated to the top of each room body, a top-of-page sign-off banner mirror with jump-to-first-unreviewed anchor, and a one-tap "I have reviewed this room" button (checkbox dropped). Pure additive Blade — no controller, route, schema, model, or JS changes.

## What changed

Single file: `resources/views/worksheets/public-show.blade.php`. Five coordinated edits, one atomic commit.

### Fix B1 — Differentiate Survey Reference from AV Works drawers

Both drawers previously rendered identically (`class="room-drawer teal"`, 📋 emoji). Engineers had to read the heading text to tell them apart.

- **Survey Reference drawer** — emoji changed from 📋 to 🔍, drawer class changed from `room-drawer teal` to `room-drawer teal teal--accent`. New CSS rule `.room-drawer.teal.teal--accent { border-left-width: 4px; border-left-color: #178A95; }` adds a 4px deeper-teal left edge for at-a-glance distinction.
- **AV Works drawer** — emoji changed from 📋 to 🛠, drawer class changed from `room-drawer teal` to `room-drawer grey`. New CSS rule set added alongside the existing teal/gold/amber rules:
  ```css
  .room-drawer.grey  { border-color: rgba(107,114,128,.35); }
  .room-drawer.grey  summary { background: rgba(107,114,128,.06); color: #374151; }
  .room-drawer.grey  summary .chev { color: #6B7280; }
  ```

### Fix B2 — Per-room status pills on `<summary>` row

Inside the per-room @php block, the existing `$hasEF` flag (which already accounts for both engineer-feedback data and survey photos as of 260504-hqe) drives a new `$gateApplies` boolean. `$isReviewed` reads from `pre_install_confirmations[$roomName]`. `$isUnreviewedWithGate = $gateApplies && ! $isReviewed`.

In the room `<summary>` row, before the existing `.photo-count-pill`, the template now renders:

- `⚠ Survey not reviewed` (amber `#FEF3C7` / `#92400E`) when `$isUnreviewedWithGate`
- `✓ Reviewed` (green `#DCFCE7` / `#166534`) when `$isReviewed`
- nothing when the room has no survey (gate doesn't apply)

Pills use inline styles with the same pattern as the rest of the page (no new CSS classes added — keeps the file footprint to one block of cosmetic CSS for fix B1).

### Fix B3 — Photo tray moved to TOP of room body

The "📷 Photos of completed work" tray (~30 lines, including thumbnails / Add-photo button / no-photos warning) previously rendered AFTER all four drawers (Survey Reference / AV Works / Kit / Steps), at the bottom of each room. Engineers had to scroll past everything to find the action item.

The block has been cut from its old position and pasted directly under the room `<summary>` close, before the Survey Reference drawer's `@if($hasEF)` block. Inline style adjustment: removed the original `margin-top + padding-top + border-top` (top divider), added `margin-bottom + padding-bottom + border-bottom` (bottom divider) so the dashed separator sits below the tray instead of above it. Functionally identical content; no JS changes needed since the upload/delete handlers reach the tray by `data-photo-tray` attribute.

New visual order inside each open room:

1. 📷 Photos of completed work (was bottom, now top)
2. 🔍 Survey Reference (teal accent)
3. 🛠 AV Works (grey)
4. 📦 Kit List (gold)
5. ✅ Install Steps (amber)

### Fix H2 — Top sign-off banner + jump-to anchor

The amber "review the survey reference for these rooms before signing off" banner previously only rendered inside the Sign-Off card at the bottom of the page. A second banner now renders at the top, immediately before the rooms loop, with `id="signoff-block-top"`. It includes a "Jump to first unreviewed room →" anchor link that scrolls to `#room-{slug}` of the first unreviewed room.

To make the anchor target work, each room `<details class="card">` now carries `id="room-{slug}"` where slug = `\Illuminate\Support\Str::slug($room['name'])`. Used the fully-qualified namespace to avoid adding a `use` statement to the Blade file.

The bottom banner inside the Sign-Off card remains unchanged. Two banners is intentional — engineers see the warning whether they're at the top or bottom of the page.

### Fix H5 — One-tap review button

The Mark Reviewed form previously had BOTH a `<input type="checkbox" required>` and a `<button type="submit">Mark Reviewed</button>`. Two taps to do one thing.

The checkbox is gone. The button is now the single action and reads:

```html
<button type="submit" class="btn-teal" style="font-size:.85rem;padding:.55rem 1rem;min-height:42px;">
    ✓ I have reviewed this room — mark reviewed
</button>
```

The form still POSTs to the same `route('public-worksheet.survey-reviewed', ['token' => $token, 'roomName' => $room['name']])` — backend untouched. Visual change only.

## File footprint audit

```
$ git diff --stat HEAD~1 HEAD
 .../views/worksheets/public-show.blade.php         | 134 ++++++++++++++-------
 1 file changed, 90 insertions(+), 44 deletions(-)
```

Exactly one file. Net +46 lines (the photo-tray cut/paste accounts for ~30 of the deletions; the rest are CSS additions, pill markup, top banner, and the one-tap button replacement).

## Render smoke tests

Performed in tinker against the local `laravel_rams` database (worksheet #3, project #3, survey #2, 5 rooms).

Two scenarios tested by temporarily seeding then restoring DB state:

| # | Scenario | Result |
|---|----------|--------|
| 1 | Worksheet rendered as-is (no EF / no survey photos / no confirmations) | View renders cleanly at 104 KB. No Survey Reference drawer (correctly gated by `$hasEF`). No top banner (correctly — `$signOffBlocked === false`). No pills (correctly — gate doesn't apply). 5 `id="room-{slug}"` attributes present on room `<details>` elements. Old checkbox label string absent from rendered HTML. **PASS** |
| 2 | Seeded `mounting_heights` + `cable_routes` on first survey room → drawer opens; no confirmation written | View renders at 110 KB. Survey Reference drawer renders with `<details class="room-drawer teal teal--accent">` and 🔍 emoji. CSS rules `.room-drawer.grey` and `.room-drawer.teal.teal--accent` both present in `<style>`. Amber `⚠ Survey not reviewed` pill renders on first room's `<summary>`. Photo tray (`data-photo-tray`) renders BEFORE Survey Reference drawer in the source order (pos 18630 < 20089). Top banner `id="signoff-block-top"` present. "Jump to first unreviewed room" anchor link present. Bottom banner still present. New one-tap button text "I have reviewed this room — mark reviewed" present. Old `<input type="checkbox" required>` tag absent. **PASS** |
| 3 | After seeded confirmation written for the first room | Pill flips from amber to green `✓ Reviewed`. `$signOffBlocked` flips to false → top banner gone. **PASS** |
| 4 | Seeded `works_summary_bullets` on first room | AV Works drawer renders as `<details class="room-drawer grey">` with 🛠 emoji. Photo tray remains BEFORE AV Works drawer in source order. **PASS** |

Blade compiles clean: `php artisan view:clear && php artisan view:cache` exits 0.

## Files to upload to live

```
resources/views/worksheets/public-show.blade.php
```

## Commands to run on live

```bash
# Pick up the view changes (no schema or route changes).
php artisan view:clear
```

No queue restart needed. No migration. No npm rebuild (Blade-only; new CSS rules live inside the `<style>` block in the same file).

## Deviations from plan

None. All five fixes shipped exactly as written, in one atomic commit.

## Self-Check: PASSED

```
$ ls .planning/quick/260504-ij9-engineer-link-ux-fixes-from-audit-drawer/260504-ij9-SUMMARY.md
FOUND
$ git log --oneline HEAD~1..HEAD
4ed52e1 feat(quick-260504-ij9): engineer-link UX fixes from audit
$ git diff --stat HEAD~1 HEAD
 1 file changed, 90 insertions(+), 44 deletions(-)
```

Single commit present. File footprint = 1 file. Forbidden-paths diff empty (no controller, no route, no schema, no model, no JS touched). View cache compiles; 4 smoke scenarios pass.
