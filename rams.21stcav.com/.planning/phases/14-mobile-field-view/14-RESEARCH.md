# Phase 14: Mobile Field View - Research

**Researched:** 2026-04-19
**Domain:** Mobile-first Laravel Blade/Alpine field app, iOS HEIC photo ingestion, per-task AJAX state saves
**Confidence:** HIGH (stack & patterns verified against codebase); MEDIUM-LOW on HEIC reliability (see Pitfall 1)

## Summary

Phase 14 is a brown-field UI phase on top of the install-task data layer delivered in Phase 12 + 13. Every pattern it needs — UUID photo uploads on `Storage::disk('local')`, fetch+CSRF AJAX, ownership guards, thin-controller-to-service flow, status enums as PHP constants — already exists in the codebase. The correct approach is to **copy and adapt**, not invent.

The only genuinely new technology is **`intervention/image` v3.11.7 with the Imagick driver** for HEIC→JPEG conversion. v3 has a clean `ImageManager::imagick()` static factory and does **not** require the `intervention/image-laravel` integration package (facade/service provider), so we can wrap it in a plain `HeicImageConverter` service. PHP 8.2 is compatible (v3 requires `^8.1`; v4 was released 2026-03-28 requiring PHP 8.3 — we stay on v3).

The single biggest risk is **runtime HEIC reliability**: ImageMagick must be compiled with the `libheif` delegate, AND the `imagick` PHP extension must be loaded, AND the Imagick build must be linked against the same ImageMagick that has the delegate. This is an ops-layer concern that the code cannot fix. CONTEXT.md D-11 locks the behaviour to "fail loudly" — this is the correct choice given PROJECT.md's data-integrity constraint.

Runtime state to watch: on the current **Windows dev box**, `php -m | grep -i imagick` is the mandatory pre-flight check (I could not run it from bash since PHP CLI is not on the shell PATH in this environment — surface this to the user in Wave 0).

**Primary recommendation:** Build a 3-wave plan. Wave 1 = migrations (`install_task_photos` + `time_entries`) + `InstallTaskPhoto` + `TimeEntry` models + `HeicImageConverter` service with failing health check. Wave 2 = controller(s) + AJAX endpoints + `TaskPhotoService` + `TimeEntryService` + routes. Wave 3 = Blade + Alpine + Tailwind mobile UI. All waves carry their own tests; no Dusk needed — use heuristic grep/response assertions for responsiveness.

## User Constraints (from CONTEXT.md)

### Locked Decisions

**Layout & Navigation**
- **D-01** Room-sectioned scrollable list: each room is a collapsible section containing its tasks, room-level `N of M complete` counter on the section header. All rooms expanded by default; engineer can collapse any room they're not in.
- **D-02** Default task scope for engineers = `assigned_to = auth()->id()`. Admins (`User::isAdmin()`) and project owners (`project.user_id === auth()->id()`) see all programme tasks by default. Engineers get a toggle to "Show all" for situational awareness.
- **D-03** Sticky top bar above the task list shows project name + current clock-in status + clock in/out button. Persistent across scroll.
- **D-04** Programme-wide progress shown as a linear progress bar + `X of Y tasks complete` text near the top of the page (under the sticky bar).

**Task Status Interaction**
- **D-05** Primary interaction = tap-to-advance the task row through `pending → in_progress → complete`. Tapping a completed task does nothing on the main path.
- **D-06** `blocked` and `skipped` statuses live behind an overflow (`⋮`) icon per task row. Setting either opens a bottom-sheet and **requires a reason note** before saving.
- **D-07** Regression from `complete → in_progress` is allowed via the overflow menu only. Every status change is persisted with an audit-log row (`status_changed_at` + `status_changed_by` minimum).
- **D-08** Visual confirmation on save = inline row state change (colour + icon) + brief checkmark pulse over the row. **No toast.** All saves are AJAX, no page reload (INST-03c).

**Photo Capture Flow**
- **D-09** Multiple photos per task via a new `install_task_photos` table modelled **exactly** on `site_survey_photos`. UI shows thumbnails as a horizontal scrolling strip below the task row.
- **D-10** Photos are **optional** — UI encourages capture but does not block `complete`.
- **D-11** HEIC → JPEG conversion via `intervention/image` with the **Imagick** driver, **synchronous** inside the upload request. Fails loudly if the PHP Imagick extension is missing (HTTP 500 with a clear log message — **never silent fallback**).
- **D-12** Photos support optional captions. Caption input is inline under each thumbnail and saves on blur via AJAX.

### Claude's Discretion
- Clock in/out backend wiring — minimal `time_entries` table (`id`, `project_id`, `user_id`, `clocked_in_at`, `clocked_out_at` nullable, `last_heartbeat_at` nullable, timestamps). **No `category` column yet** — Phase 15 adds it. One open entry per user per project → 422 on duplicate start.
- Notes input pattern — inline auto-expanding textarea per task, saved on blur via AJAX.
- Empty state — "No tasks assigned to you yet." with a "Show all programme tasks" link.
- Photo thumbnail layout — horizontal scrolling strip, 80×80 thumbnails below task row. Tap opens a lightbox.
- Max photo size — 20 MB per upload.
- Storage path — `storage/app/private/task-photos/{project_id}/{task_id}/{uuid}.jpg` on the default `local` disk.

### Deferred Ideas (OUT OF SCOPE)
- Offline / service worker / localStorage queue (INST-03h — online only).
- Time-tracking categories, heartbeat loop, `programme:close-stale-sessions` command → Phase 15.
- Budget vs actual hours → Phase 15+ (INST-04i).
- Commissioning evidence photos, client signature, snagging PDF → Phase 16 (INST-05).
- Per-task-type `requires_photo` flag → Phase 16.
- Task-status audit-trail UI (saves the audit, doesn't render it).
- Push notifications on task reassignment.
- Backfill HEIC conversion for existing `site_survey_photos` — noted as a separate follow-up.

## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| INST-03 | Responsive mobile field page | D-01..D-08; existing survey UX pattern; Phase 13 `schedule.blade.php` Alpine+Tailwind baseline |
| INST-03a | `/projects/{project}/programme` route — mobile-responsive Blade + Tailwind | New GET route on `InstallProgrammeController::field()` (recommended) or new `FieldController`. Project bind + existing ownership guard pattern |
| INST-03b | Grouped by room, filtered to engineer by default, PM sees all | D-02. Server-side filter mirrors Phase 13 `schedule()` INST-02g pattern — `$tasks = $isOwnerOrAdmin ? $programme->tasks : $programme->tasks->where('assigned_to', auth()->id())` |
| INST-03c | Task status AJAX toggle with no reload | D-05, D-07, D-08. `PATCH /install-tasks/{task}/status` returns JSON. fetch+CSRF pattern from `resources/views/public-survey/show.blade.php:1801–1970` |
| INST-03d | Photo capture per task with `capture="environment"` | D-09. Copy shape of `resources/views/components/survey/photo-upload.blade.php`. New `install_task_photos` table modelled on `site_survey_photos` migration |
| INST-03e | iOS HEIC server-side conversion to JPEG before storage | D-11. **REQUIREMENTS.md says "using GD"** — but CONTEXT.md D-11 overrides with **Imagick**. See Pitfall 1 for why. `intervention/image:^3` + `ext-imagick` check |
| INST-03f | Per-task notes, AJAX save | Inline auto-expanding textarea, save on blur — `PATCH /install-tasks/{task}/notes` |
| INST-03g | Room `N of M` counter + programme-wide progress | D-01, D-04. Counts computed server-side, patched client-side after each status save — status endpoint returns the new counters |
| INST-03h | Online only — no service worker, no offline caching | No `manifest.json`, no SW registration. Deferred scope — do not plan for |

## Project Constraints (from CLAUDE.md)

**Critical directives planner MUST honor:**

- **AI usage** — AI is ONLY allowed for formatting / method-statement structuring. **No AI in this phase.** Photo captioning, status transitions, notes = user-entered only.
- **Data integrity** — "processing failures MUST surface loudly." This is the non-negotiable rule behind D-11 (fail loudly when `imagick` missing). Do NOT add a silent fallback to GD. Do NOT catch and suppress conversion exceptions.
- **Architecture** — Laravel service-based, thin controllers. Controllers validate + authorise + delegate. Business logic goes in `app/Services/` (flat namespace) — new services this phase: `HeicImageConverter`, `TaskPhotoService`, `TimeEntryService`.
- **SQL security** — N/A this phase (no QuoteWerks touch).
- **Output formats** — N/A this phase (no document generation).
- **Queue-compatible** — Photo conversion happens synchronously in the request (D-11: sync in upload request). Confirmed compatible with CLAUDE.md because no queue job is introduced.
- **Naming** — Services `PascalCase`Service (`HeicImageConverter` acceptable — not every infrastructure service ends in `Service` per `DocumentArtifactStorage` precedent). Models `PascalCase` singular (`InstallTaskPhoto`, `TimeEntry`). Controllers `PascalCaseController`. Blade views `kebab-case`.
- **Methods** — camelCase; boolean prefixed `is`/`has`; constants `STATUS_UPPERCASE`.
- **Log prefix** — `'ClassName: message'` — e.g. `Log::info('TaskPhotoService: photo uploaded', [...])`.
- **Pint PSR-12** — 4-space indent, opening brace on same line for classes/methods.
- **GSD workflow** — Every task goes through a GSD plan; no direct edits outside a plan.
- **Fetch CSRF** — Use native `fetch` + `X-CSRF-TOKEN` meta header for form saves. Axios is bootstrapped but not used for form/save traffic (precedent: `resources/views/public-survey/show.blade.php`). [VERIFIED: `resources/js/bootstrap.js`, `resources/views/layouts/app.blade.php:6`]

## Standard Stack

### Core

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `intervention/image` | `^3.11.7` | HEIC→JPEG conversion with Imagick driver | Only mainstream PHP image library with a clean driver abstraction, v3 still maintained, v4 requires PHP 8.3 (we're on 8.2). [VERIFIED: Packagist `intervention/image` JSON + source at github.com/Intervention/image/blob/3.11.7] |
| `ext-imagick` (PHP ext) | system-installed | HEIC delegate access | Required by v3 Imagick driver. `Imagick::Driver::checkHealth()` throws `DriverException` if absent. [VERIFIED: source at `Intervention\Image\Drivers\Imagick\Driver::checkHealth()`] |
| `ImageMagick` w/ `libheif` delegate | system-installed | Actually decode HEIC bytes | Without the `libheif` delegate, `Imagick` itself loads but cannot read HEIC. Surfaces at conversion time, not boot time. [CITED: https://genijaho.medium.com/how-to-add-support-for-heic-images-with-imagemagick-in-php-ffa212f41bf3] |

### Supporting (already in project)

| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| `alpinejs` | `^3` (bundled) | Row-level reactivity + AJAX state | Every task row's status state + photo strip + notes textarea |
| `axios` | `^1.11.0` | Installed but **not used** for form saves per project convention | Skip — use native `fetch` |
| `tailwindcss` + `@tailwindcss/forms` | `^3.1` / `^0.5.2` | Mobile-first utility CSS | Whole field view |
| `laravel/framework` | `^12.0` | HTTP, routing, validation, queue, FS | — |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| `intervention/image` v3 + Imagick | GD (REQUIREMENTS.md wording) | **GD has no HEIC support.** This is the main reason CONTEXT.md D-11 explicitly overrides the REQUIREMENTS.md wording from "using GD" to "using Imagick". Flag in plan: REQUIREMENTS.md text is outdated relative to CONTEXT.md D-11. [VERIFIED: PHP GD docs — image-create-from-* has no `heic` variant] |
| `intervention/image` v3 + Imagick | `MaestroError/php-heic-to-jpg` | Go-based binary shelled out from PHP. Works, but adds a second runtime, a second source of "it failed silently", and breaks on Windows/dev. Rejected. [CITED: GitHub search result] |
| `intervention/image` v3 + Imagick | Shell out to `magick` binary (geni jaho approach) | One blogger reports Intervention+Imagick fails for HEIC; recommends shell-out. Only relevant as a **fallback diagnosis path** if the standard approach fails on the real production server. Not the primary recommendation. [CITED: https://genijaho.medium.com/how-to-add-support-for-heic-images-with-imagemagick-in-php-ffa212f41bf3] |
| `intervention/image-laravel` (integration pkg) | Facade + config file | Not needed — we're instantiating `ImageManager::imagick()` directly inside a service. No facade in app. Keeps composer.json small. [VERIFIED: v3 ImageManager source — `::imagick()` static factory is core, not in integration pkg] |

**Installation:**

```bash
composer require intervention/image:^3
```

**Version verification** (required before planning Wave 1):

- Package version: `intervention/image 3.11.7` — published 2024-02-21 [VERIFIED: packagist.org/packages/intervention/image]
- PHP constraint: `^8.1` — satisfied by project's `php ^8.2` [VERIFIED: https://raw.githubusercontent.com/Intervention/image/3.11.7/composer.json]
- Only other `require`: `intervention/gif ^4.2`, `ext-mbstring`. No other composer deps. [VERIFIED: same source]
- v4.0.0 (2026-03-28) requires PHP 8.3 — do NOT adopt v4. Locking to `^3` via composer constraint is important.

**Deployment note** (planner to add to README / deployment doc):

```
# Linux (Ubuntu 22.04+):
sudo apt install php8.2-imagick libheif-dev
# Verify after install:
php -r "\$i = new Imagick(); print_r(\$i->queryFormats('HEI*'));"
# Must return ['HEIC', 'HEIF'] (or similar). If empty → ImageMagick lacks libheif delegate.

# Windows dev box (this project):
# php.ini needs: extension=imagick
# The Windows Imagick binary must include the HEIC delegate — verify with:
php -r "echo extension_loaded('imagick') ? 'ok' : 'MISSING';"
```

## Architecture Patterns

### Recommended Project Structure

```
app/
├── Http/Controllers/
│   ├── InstallProgrammeController.php   # EXTEND — add field() action (see "field controller: extend vs new" below)
│   ├── TaskStatusController.php          # NEW — PATCH /install-tasks/{task}/status + /notes
│   ├── TaskPhotoController.php           # NEW — POST/PATCH/DELETE photos
│   └── TimeEntryController.php           # NEW — start/stop clock
├── Services/                              # flat namespace per CLAUDE.md
│   ├── HeicImageConverter.php            # NEW — wraps intervention/image + Imagick
│   ├── TaskPhotoService.php              # NEW — UUID storage, DB row, delete
│   └── TimeEntryService.php              # NEW — start/stop with open-entry guard
├── Models/
│   ├── InstallTask.php                   # EXTEND — add photos() hasMany
│   ├── InstallTaskPhoto.php              # NEW — mirror SiteSurveyPhoto
│   └── TimeEntry.php                     # NEW — minimal schema
database/migrations/
│   ├── 2026_04_19_000001_create_install_task_photos_table.php   # NEW — mirrors site_survey_photos
│   ├── 2026_04_19_000002_add_status_audit_to_install_tasks_table.php   # NEW — status_changed_at, status_changed_by
│   └── 2026_04_19_000003_create_time_entries_table.php         # NEW — minimal schema
resources/views/
│   ├── install-programmes/
│   │   └── field.blade.php                                      # NEW — the mobile UI
│   └── components/install-task/
│       └── photo-upload.blade.php                               # NEW — fork of survey/photo-upload.blade.php
routes/web.php                             # EXTEND — 7 new routes in auth group
```

### Pattern 1: Mobile-first Blade + Alpine composition

**What:** Single Blade view, one root Alpine `x-data` object holding `rooms[]`, per-row local state, sticky-bar clock state. Nested `x-for` over rooms → tasks. Each task-row is a small Alpine component that owns its own fetch calls and UI state (status, notes dirty flag, photo strip).

**When to use:** Whole-page mobile reactivity where every task is independently editable. Matches the Phase 13 `schedule.blade.php` Alpine slide-over + the public-survey per-question fetch.

**Example (status toggle in a task row):**

```blade
{{-- Source: adapted from resources/views/public-survey/show.blade.php:1801–1830 --}}
<div
  x-data="{
    status: '{{ $task->status }}',
    savedPulse: false,
    advance() {
      const next = this.nextStatus();
      if (!next) return;             // D-05: tap on complete is a no-op
      this.patch(next);
    },
    nextStatus() {
      return { pending: 'in_progress', in_progress: 'complete' }[this.status] || null;
    },
    patch(newStatus) {
      fetch('/install-tasks/{{ $task->id }}/status', {
        method: 'PATCH',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
          'Accept': 'application/json',
        },
        body: JSON.stringify({ status: newStatus }),
      })
      .then(r => r.ok ? r.json() : Promise.reject(r))
      .then(d => {
        this.status = d.status;
        this.savedPulse = true;
        setTimeout(() => this.savedPulse = false, 800);
        // D-08 visual confirmation is inline — no toast.
        // Bubble new counters up to the room/programme header:
        window.dispatchEvent(new CustomEvent('task-saved', { detail: d }));
      })
      .catch(() => { /* D-11 spirit: no silent success, surface visually */
        this.savedPulse = 'error';
      });
    },
  }"
  @click="advance()"
  :class="{
    'bg-amber-50': status === 'in_progress',
    'bg-green-50': status === 'complete',
    'ring-2 ring-green-400 transition': savedPulse === true,
    'ring-2 ring-red-400': savedPulse === 'error',
  }"
  class="flex items-start gap-3 p-3 rounded-xl active:bg-gray-100"
>
  {{-- ... icon + title + meta + photo strip + notes ... --}}
</div>
```

### Pattern 2: Photo upload — fork, don't parameterise

**What:** New `resources/views/components/install-task/photo-upload.blade.php`. Copy the survey component and rename the callbacks — do **not** try to make one component serve both survey and install. The caption-on-blur behaviour (D-12) is new and belongs here.

**Why:** The existing survey component targets `currentRoom.photos` in its parent Alpine scope. Retrofitting it to also target `task.photos` would couple unrelated pages. Forking is 40 lines of Blade.

**Example (photo-upload component):**

```blade
{{-- resources/views/components/install-task/photo-upload.blade.php --}}
@props(['task'])

<div x-data="{
    photos: @js($task->photos->map(fn($p) => [
      'id' => $p->id, 'url' => route('install-task-photos.show', $p),
      'caption' => $p->caption,
    ])),
    captionDirty: {},
    upload(input) {
      const fd = new FormData();
      fd.append('photo', input.files[0]);
      fetch('/install-tasks/{{ $task->id }}/photos', {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
        body: fd,
      })
      .then(r => r.ok ? r.json() : r.json().then(e => Promise.reject(e)))
      .then(d => this.photos.push(d))
      .catch(err => alert(err?.message ?? 'Upload failed — try again.'));
      input.value = '';  // allow re-selecting same file
    },
    saveCaption(photoId, value) {
      fetch('/install-task-photos/' + photoId, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json',
                   'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
        body: JSON.stringify({ caption: value }),
      });
    },
}">
  <div class="flex gap-2 overflow-x-auto py-2">
    <template x-for="photo in photos" :key="photo.id">
      <div class="flex-shrink-0 w-20">
        <img :src="photo.url" class="w-20 h-20 object-cover rounded-lg"
             @click="$dispatch('lightbox-open', { url: photo.url })">
        <input type="text" x-model="photo.caption"
               @blur="saveCaption(photo.id, photo.caption)"
               class="text-xs w-full mt-1" placeholder="Caption">
      </div>
    </template>

    <label class="flex-shrink-0 w-20 h-20 border-2 border-dashed border-gray-300
                  rounded-lg flex items-center justify-center bg-gray-50">
      📷
      <input type="file" accept="image/*" capture="environment" class="sr-only"
             @change="upload($event.target)">
    </label>
  </div>
</div>
```

### Pattern 3: Service-wraps-Imagick + constructor health check

**What:** `HeicImageConverter` is a plain PHP service injected by the container. Constructor throws `RuntimeException` if `imagick` extension missing. Per-request use is a single method `convertToJpeg(UploadedFile $file, string $destinationAbsPath): void`. Decides internally whether to convert (HEIC) or pass-through (JPEG/PNG via `copy()`).

**When to use:** Every task-photo upload.

**Example (full service):**

```php
<?php
// app/Services/HeicImageConverter.php
namespace App\Services;

use Illuminate\Http\UploadedFile;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageManagerInterface;
use RuntimeException;

/**
 * Converts HEIC / HEIF uploads to JPEG using Imagick.
 *
 * Design intent (PROJECT.md data-integrity rule + CONTEXT.md D-11):
 *   - No silent fallback. If php-imagick is missing, the upload fails loudly.
 *   - JPEG / PNG pass through untouched (copy), not re-encoded, to avoid
 *     quality loss and EXIF stripping for photos that don't need conversion.
 */
class HeicImageConverter
{
    private const HEIC_MIMES = ['image/heic', 'image/heif',
                                'image/heic-sequence', 'image/heif-sequence'];
    private const JPEG_QUALITY = 85;

    private ImageManagerInterface $manager;

    public function __construct()
    {
        if (! extension_loaded('imagick') || ! class_exists(\Imagick::class)) {
            throw new RuntimeException(
                'HeicImageConverter: the php-imagick PHP extension is required '
                . 'for HEIC conversion. Install it with: sudo apt install php8.2-imagick '
                . '(Linux) or enable extension=imagick in php.ini (Windows).'
            );
        }
        // Intervention's Imagick driver also calls checkHealth() in its ctor.
        $this->manager = ImageManager::imagick();
    }

    /**
     * Convert or copy the uploaded file to $destinationAbsPath as JPEG/passthrough.
     *
     * @throws RuntimeException if HEIC decode fails (ImageMagick lacks libheif delegate)
     */
    public function writeAsJpeg(UploadedFile $file, string $destinationAbsPath): void
    {
        $mime = $this->detectMime($file);

        if ($this->isHeic($mime)) {
            try {
                $this->manager
                    ->read($file->getRealPath())
                    ->toJpeg(quality: self::JPEG_QUALITY)
                    ->save($destinationAbsPath);
            } catch (\Throwable $e) {
                throw new RuntimeException(
                    'HeicImageConverter: HEIC conversion failed. This usually means '
                    . 'ImageMagick was not compiled with the libheif delegate. '
                    . 'Verify with: php -r "print_r((new Imagick())->queryFormats(\'HEI*\'));". '
                    . 'Original error: ' . $e->getMessage(),
                    previous: $e,
                );
            }
            return;
        }

        // JPEG / PNG / WebP passthrough — just move.
        $file->move(dirname($destinationAbsPath), basename($destinationAbsPath));
    }

    private function isHeic(string $mime): bool
    {
        return in_array(strtolower($mime), self::HEIC_MIMES, true);
    }

    /**
     * iOS Safari sometimes sends 'application/octet-stream' for HEIC.
     * Check three sources and trust the most specific one.
     */
    private function detectMime(UploadedFile $file): string
    {
        // 1. finfo (content-based, most reliable)
        $finfoMime = @mime_content_type($file->getRealPath());
        if ($finfoMime && $finfoMime !== 'application/octet-stream') {
            return $finfoMime;
        }
        // 2. file extension (fallback when iOS sends octet-stream)
        $ext = strtolower($file->getClientOriginalExtension());
        if (in_array($ext, ['heic', 'heif'], true)) {
            return 'image/heic';
        }
        // 3. client-provided MIME (least trustworthy)
        return $file->getMimeType() ?? 'application/octet-stream';
    }
}
```

### Pattern 4: Ownership guard + route model binding

**What:** Every endpoint in this phase loads `task.programme.project` and runs the canonical guard. For `time_entries` routes the guard runs on the `$project` directly.

**Example (verbatim from codebase):**

```php
// Source: app/Http/Controllers/InstallProgrammeController.php:55–58
abort_if(
    $project->user_id !== auth()->id() && ! auth()->user()->isAdmin(),
    403
);

// Source: app/Http/Controllers/TaskAssignmentController.php:368–371
$task->load('programme.project');
abort_if(
    $task->programme->project->user_id !== auth()->id() && ! auth()->user()->isAdmin(),
    403
);
```

### Anti-Patterns to Avoid

- **Silent GD fallback for HEIC.** Violates CLAUDE.md "data integrity" and CONTEXT.md D-11. GD cannot decode HEIC. A "best effort fallback" that silently saves a corrupted/partial JPEG is exactly the failure mode the rule exists to prevent.
- **Using Axios for the status/notes/photo endpoints.** Codebase convention is `fetch` + manual CSRF meta. Axios is imported by `bootstrap.js` but only for interceptor-style usage. Mixing clients on the same page is a smell.
- **Toast notifications on task save.** D-08 says no. Use inline row recolouring + pulse only.
- **Re-using the survey photo-upload component as-is.** Its Alpine bindings target a `currentRoom.photos` global, not a `$task.photos` scoped array. Fork it.
- **Putting HEIC conversion behind a queue job.** D-11 locks it as synchronous. The engineer must see the thumbnail appear in the same interaction. Also keeps error reporting linear.
- **A "category" column on `time_entries` now.** Phase 15 owns that (INST-04a). Adding it now puts Phase 15 in a messy "rename or extend?" decision.
- **Hand-building an upload progress bar.** Native `<input type="file">` + simple "Uploading..." text is enough; full progress requires XHR (not fetch). Keep scope tight.
- **Optimistic local updates with rollback on failure.** Tempting, but means writing the rollback code. Simpler: disable the button, wait for server, apply state.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| HEIC decoding | Custom HEIC parser or shell to `magick` | `intervention/image` v3 + Imagick | HEIC/HEIF is a patented, Apple-flavoured container. Rolling your own = guaranteed bug farm. Intervention is the industry-standard wrapper. |
| UUID filenames | `uniqid()` + microtime | `Str::uuid()` | Already used in `SurveyService::addPhoto()`. Collision-proof and sortable. |
| Photo thumbnail generation | `imagecopyresampled` in a helper | — (don't generate thumbnails) | CONTEXT.md didn't ask for resized thumbnails. Serve the full JPEG with a CSS-constrained `<img>`. Add thumbnails only if performance complaints surface. |
| CSRF token refresh on long sessions | `setInterval(refresh)` | Laravel's default 120-minute session lifetime | Session lifetime = 120 min (see `config/session.php`). If a session expires mid-save, the 419 response bubbles; user re-auths. Don't pre-empt. |
| File-type sniffing | Regex on bytes / magic numbers | `mime_content_type()` + `$file->getClientOriginalExtension()` fallback | PHP's finfo covers HEIC; extension fallback covers iOS's occasional octet-stream. |
| Private-photo serving | Public symlink | Authenticated controller action (`response()->file()`) | Precedent: `SiteSurveyController::servePhoto()`. Keeps ownership guard in effect. |
| Image lightbox | Alpine modal | Pure CSS / `<dialog>` element | Or skip lightbox entirely — CONTEXT.md "Tap opens a lightbox" can be honoured with a `<dialog>` + native zoom. Small code, no library. |
| Status enum PHP code | Re-declare constants | `InstallTask::STATUS_PENDING` (already exists) | Existing model already has all 5 values including BLOCKED and SKIPPED. Reuse. |
| Room progress counter | Client-side aggregation | Server-returned counters in the status-save response | Avoids drift between Alpine local state and DB. Server is truth. |

**Key insight:** Almost nothing is new. The only genuinely new primitive is HEIC→JPEG; everything else (photo upload, UUID storage, ownership guard, status pipeline, fetch+CSRF) already lives in the codebase 30 meters from where Phase 14 will build.

## Runtime State Inventory

> Phase 14 is a feature-add phase (new tables, new routes, new views). It is NOT a rename / refactor / migration. This section is included anyway to flag the one real runtime-state concern.

| Category | Items Found | Action Required |
|----------|-------------|------------------|
| Stored data | None — phase creates new tables (`install_task_photos`, `time_entries`) with fresh rows. No backfill. | None. |
| Live service config | None — no Datadog tags, no n8n workflows, no Tailscale ACLs touched. | None. |
| OS-registered state | None this phase. Phase 15 will add `programme:close-stale-sessions` as a scheduled task — defer. | None this phase. |
| Secrets / env vars | None new. No API keys for this phase (no AI, no external service). | None. |
| Build artifacts / installed packages | **composer.json will be modified** (`intervention/image` added). `composer.lock` + `vendor/` regenerate. On the Windows dev box, the `imagick` PHP extension must be enabled in `php.ini` — this is a **manual developer action**, not a code change, and must be called out in the plan's Wave 1 summary. | Add a "developer setup" checklist in the phase plan (verify `php -m \| grep -i imagick` on every dev machine). |

**Nothing found in the other categories** — verified by:
- No cron/scheduler entries in `app/Console/Kernel.php` reference install/task/photo.
- No env vars in `.env.example` for imagick/image conversion.
- No pm2 / Docker tags referencing renamed entities (nothing is renamed).

## Common Pitfalls

### Pitfall 1: ImageMagick without libheif delegate — "it works on my machine" trap

**What goes wrong:** `php -m` shows `imagick`. `intervention/image` loads. `ImageManager::imagick()` constructor passes. The upload request hits the converter. Imagick::readImageBlob() silently produces a blank/unreadable JPEG, OR throws `ImagickException: no decode delegate for this image format 'HEIC'`.

**Why it happens:** `ext-imagick` binds to the system-installed ImageMagick. If that ImageMagick was NOT compiled with `--with-heif`, HEIC support is absent even though the PHP extension loads fine. Two independent moving parts — the extension works, the library doesn't have the format.

**How to avoid:**

1. **Health check at boot** (warning only, app still starts):
   ```php
   // In AppServiceProvider::boot()
   if (extension_loaded('imagick')) {
       $formats = (new \Imagick())->queryFormats('HEI*');
       if (empty($formats)) {
           \Log::warning('AppServiceProvider: imagick loaded but HEIC delegate missing. '
               . 'HEIC uploads will fail. Install libheif-dev and recompile ImageMagick.');
       }
   } else {
       \Log::warning('AppServiceProvider: imagick extension not loaded. '
           . 'HEIC uploads will 500.');
   }
   ```
   Logging a warning at boot is better than throwing — keeps unrelated endpoints working.

2. **Throw in service constructor** when the extension is missing (fails loudly per D-11).

3. **Catch `Throwable` around `read()+toJpeg()`** — rethrow as `RuntimeException` with a message that names the `libheif` delegate explicitly. This is the planner's bright beacon when it hits a production issue.

**Warning signs:**
- Local Windows dev works (Imagick Windows builds often include libheif), production Linux doesn't.
- `Imagick::queryFormats("HEI*")` returns `[]` on the server.
- File saved is 0 bytes or has a `.jpg` extension but is actually a blank image.

**Confidence:** MEDIUM — the Geni Jaho blog post explicitly reports `intervention/image` HEIC support is unreliable in their experience and advocates shelling to `magick`. This is not confirmation that Intervention+Imagick **cannot** work, just that it requires the underlying ImageMagick to be correctly compiled. Recommend: plan a small "smoke test" task in Wave 3 that uploads a committed HEIC fixture on a real CI/prod environment before declaring phase complete.

### Pitfall 2: Laravel's `image` validation rule rejects HEIC by default

**What goes wrong:** `$request->validate(['photo' => 'required|image|max:20480'])` rejects a HEIC upload with "The photo must be a valid image." The `image` rule expands to `jpg, jpeg, png, bmp, gif, webp` — HEIC/HEIF not included.

**Why it happens:** Laravel's `image` rule is conservative. HEIC was intentionally excluded for historical compatibility reasons.

**How to avoid:** Use `mimetypes` explicitly:

```php
$request->validate([
    'photo' => [
        'required',
        'file',
        'mimetypes:image/jpeg,image/png,image/webp,image/heic,image/heif,image/heic-sequence,image/heif-sequence',
        'max:20480',  // 20 MB in KB
    ],
    'caption' => ['nullable', 'string', 'max:200'],
]);
```

**Warning signs:** 422 in network tab with Laravel's default "must be a valid image" message when an iPhone uploads.

**Confidence:** HIGH — verified against Laravel 12 validation docs.

### Pitfall 3: iOS sends `application/octet-stream` for HEIC under some Safari versions

**What goes wrong:** Even `mimetypes:image/heic` rejects the upload because iOS Safari sometimes reports the client MIME as `application/octet-stream` (especially when the user pasted from a photo roll via share sheet, vs. using the camera button).

**Why it happens:** iOS Safari's `FormData` boundary reporting is inconsistent across iOS versions.

**How to avoid:**

- **Don't trust client MIME.** Use `mime_content_type()` on the server (content-sniffing) as the primary source. Fall back to file extension. Fall back to client MIME. See `HeicImageConverter::detectMime()` example above.
- In the validation rule, additionally accept `application/octet-stream` **only if** the extension is `heic`/`heif`. Easier: skip that and instead run a custom closure validator that calls `detectMime()`.

**Warning signs:** Upload fails validation only on some iPhones, intermittently. The user reports "sometimes it works."

**Confidence:** MEDIUM — documented by multiple iOS upload blogs; not reproducible without a real iOS device.

### Pitfall 4: `post_max_size` / `upload_max_filesize` silently cap uploads below Laravel's `max:20480`

**What goes wrong:** Laravel validation says 20 MB is fine; PHP returns a 413 or an empty `$_FILES` before the rule even runs, because `upload_max_filesize=2M` (PHP default).

**Why it happens:** PHP defaults are 2 MB / 8 MB respectively. Laravel validates whatever makes it past PHP. If PHP rejected, Laravel never sees the file.

**How to avoid:**

1. Document required `php.ini` values in the README/deployment doc:
   ```
   upload_max_filesize = 25M
   post_max_size       = 32M
   ```
2. At runtime, add a Blade helper that reads `ini_get('upload_max_filesize')` and warns if < 20M — surface in field view as a small admin note.
3. Handle `ValidationException` cleanly when it does trigger so the user sees "File too large (max 20 MB)" not a generic error.

**Warning signs:** Works for small test photos, fails for real-world 8–15 MB iPhone photos.

**Confidence:** HIGH — fundamental PHP behaviour.

### Pitfall 5: Private-disk URL broken because Laravel's `Storage::disk('local')->url()` returns a symlinked public URL

**What goes wrong:** `$photo->url()` calls `Storage::disk('local')->url(...)`. For the `local` disk this returns something like `/storage/task-photos/...` which requires a `public/storage` symlink to that directory — which we deliberately don't have for private files.

**Why it happens:** The `local` disk root is `storage/app/`; its `url()` is intended for files under `storage/app/public` via the symlink.

**How to avoid:** Do NOT call `$photo->url()`. Serve through a controller action:

```php
// Route:
Route::get('install-task-photos/{photo}',
    [TaskPhotoController::class, 'show'])
    ->name('install-task-photos.show');

// Controller:
public function show(InstallTaskPhoto $photo): \Symfony\Component\HttpFoundation\Response
{
    $photo->load('task.programme.project');
    abort_if(
        $photo->task->programme->project->user_id !== auth()->id()
            && ! auth()->user()->isAdmin(),
        403
    );

    $abs = Storage::disk('local')->path($photo->relative_path);
    abort_unless(file_exists($abs), 404);

    return response()->file($abs, [
        'Content-Type' => $photo->mime_type ?? 'image/jpeg',
        'Content-Disposition' => 'inline; filename="' . $photo->original_name . '"',
    ]);
}
```

**Warning signs:** `<img src>` returns a 404 or "Forbidden". The symlinked path points to nothing.

**Confidence:** HIGH — precedent is `SiteSurveyController::servePhoto()` which does exactly this.

### Pitfall 6: Two simultaneous "clock in" requests from a laggy network create two open entries

**What goes wrong:** Engineer taps "Clock In", network hangs. They tap again 4 seconds later. Two entries are created both with `clocked_out_at = null`.

**Why it happens:** Classic double-submit. The route has no server-side guard. CONTEXT.md calls this out (INST-04g) but the guard still needs to be implemented.

**How to avoid:** In `TimeEntryService::start()`, wrap in a DB transaction with a `SELECT ... FOR UPDATE`:

```php
public function start(Project $project, User $user): TimeEntry
{
    return DB::transaction(function () use ($project, $user) {
        $existing = TimeEntry::where('project_id', $project->id)
            ->where('user_id', $user->id)
            ->whereNull('clocked_out_at')
            ->lockForUpdate()      // <— the critical bit on MySQL
            ->first();

        if ($existing) {
            throw new \App\Exceptions\ClockInBlockedException(
                'You already have an open clock-in for this project. '
                . 'Clock out first.'
            );
        }

        return TimeEntry::create([
            'project_id'        => $project->id,
            'user_id'           => $user->id,
            'clocked_in_at'     => now(),
            'last_heartbeat_at' => now(),
        ]);
    });
}
```

And on the controller catch this and return 422. SQLite (used in test env) supports `SELECT ... FOR UPDATE` as a no-op — behaviour still correct because the transaction is atomic.

**Warning signs:** A project has multiple open `time_entries` for the same user. Phase 15's `programme:close-stale-sessions` will clean up but that's not the fix — server-side prevention is.

**Confidence:** HIGH — standard Laravel + MySQL pattern.

### Pitfall 7: REQUIREMENTS.md INST-03e says "using GD" but CONTEXT.md D-11 says Imagick

**What goes wrong:** A planner reads REQUIREMENTS.md first, plans for GD, then realises HEIC can't be decoded. Wasted planning cycle; or worse, ships a silent-fallback implementation.

**Why it happens:** REQUIREMENTS.md predates the HEIC realisation. CONTEXT.md D-11 overrides. The planner must honour CONTEXT.md (locked decisions) over REQUIREMENTS.md's historical text.

**How to avoid:** Plan uses Imagick explicitly. Leave a comment in the migration or service: "GD cannot decode HEIC; CONTEXT.md D-11 selected Imagick."

**Warning signs:** Composer requires no image library. A GD-based converter service appears. Tests pass with JPEG fixtures only.

**Confidence:** HIGH — direct textual conflict between two canonical docs, with CONTEXT.md winning per GSD doctrine.

## Code Examples

All examples drawn from existing codebase patterns.

### Example 1: Migration for `install_task_photos` (mirror of `site_survey_photos`)

```php
// Source pattern: database/migrations/2026_03_14_000031_create_site_survey_photos_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-task photo attachments for install tasks.
 * Photos are stored on the local disk under storage/app/task-photos/{project_id}/{task_id}/{uuid}.jpg
 * and served through TaskPhotoController::show() so they stay private.
 * HEIC uploads are converted to JPEG at upload time by HeicImageConverter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('install_task_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('install_task_id')
                  ->constrained()
                  ->cascadeOnDelete();
            $table->string('filename', 255);           // relative path, e.g. task-photos/42/77/{uuid}.jpg
            $table->string('original_name', 255);
            $table->string('mime_type', 50)->default('image/jpeg');
            $table->string('caption', 200)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('install_task_photos');
    }
};
```

### Example 2: Migration for `time_entries` (minimal schema — Phase 15 will extend)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Minimal time_entries schema for Phase 14 clock in/out.
 * Phase 15 (INST-04) extends with: category, notes, heartbeat scheduled-job.
 * last_heartbeat_at is included from day one per REQUIREMENTS.md Technical Constraints.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('clocked_in_at');
            $table->timestamp('clocked_out_at')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();  // Phase 15 INST-04d wires it up
            $table->timestamps();

            $table->index(['project_id', 'user_id']);
            // Partial unique index on open entries would be ideal but SQLite/MySQL
            // differ — enforce via service-layer lockForUpdate instead.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entries');
    }
};
```

### Example 3: Migration — add status audit columns to install_tasks (D-07)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('install_tasks', function (Blueprint $table) {
            $table->timestamp('status_changed_at')->nullable()->after('blocked_reason');
            $table->foreignId('status_changed_by')->nullable()->after('status_changed_at')
                  ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('install_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('status_changed_by');
            $table->dropColumn('status_changed_at');
        });
    }
};
```

### Example 4: Route registration (routes/web.php, inside auth middleware group)

```php
// Append after existing install-programmes.schedule route (line ~290)
Route::get('projects/{project}/programme',
    [\App\Http\Controllers\InstallProgrammeController::class, 'field'])
    ->name('install-programmes.field');

Route::patch('install-tasks/{task}/status',
    [\App\Http\Controllers\TaskStatusController::class, 'update'])
    ->name('install-tasks.status');

Route::patch('install-tasks/{task}/notes',
    [\App\Http\Controllers\TaskStatusController::class, 'updateNotes'])
    ->name('install-tasks.notes');

Route::post('install-tasks/{task}/photos',
    [\App\Http\Controllers\TaskPhotoController::class, 'store'])
    ->name('install-task-photos.store')
    ->middleware('throttle:60,1');

Route::patch('install-task-photos/{photo}',
    [\App\Http\Controllers\TaskPhotoController::class, 'update'])
    ->name('install-task-photos.update');

Route::delete('install-task-photos/{photo}',
    [\App\Http\Controllers\TaskPhotoController::class, 'destroy'])
    ->name('install-task-photos.destroy');

Route::get('install-task-photos/{photo}',
    [\App\Http\Controllers\TaskPhotoController::class, 'show'])
    ->name('install-task-photos.show');

Route::post('projects/{project}/time-entries/start',
    [\App\Http\Controllers\TimeEntryController::class, 'start'])
    ->name('time-entries.start')
    ->middleware('throttle:30,1');

Route::post('projects/{project}/time-entries/stop',
    [\App\Http\Controllers\TimeEntryController::class, 'stop'])
    ->name('time-entries.stop')
    ->middleware('throttle:30,1');
```

**Note on field controller: extend vs new.** Looking at `InstallProgrammeController.php` at 262 lines, adding a `field()` action is comfortably within a single-file-responsibility budget — the controller is already the canonical install-programme HTTP surface. Recommend: **add `field()` to `InstallProgrammeController`** alongside the existing `schedule()` method. The status/notes/photo/time endpoints get their own controllers because they have different responsibilities (state mutation, file upload, time tracking) and would bloat the existing controller.

### Example 5: Status endpoint controller action

```php
// app/Http/Controllers/TaskStatusController.php
public function update(Request $request, InstallTask $task): JsonResponse
{
    $task->load('programme.project');
    abort_if(
        $task->programme->project->user_id !== auth()->id()
            && ! auth()->user()->isAdmin(),
        403
    );

    $validated = $request->validate([
        'status'        => ['required', Rule::in([
            InstallTask::STATUS_PENDING, InstallTask::STATUS_IN_PROGRESS,
            InstallTask::STATUS_COMPLETE, InstallTask::STATUS_BLOCKED,
            InstallTask::STATUS_SKIPPED,
        ])],
        'blocked_reason' => ['nullable', 'string', 'max:500',
                             'required_if:status,' . InstallTask::STATUS_BLOCKED,
                             'required_if:status,' . InstallTask::STATUS_SKIPPED],
    ]);

    $task->update([
        'status'            => $validated['status'],
        'blocked_reason'    => $validated['blocked_reason'] ?? null,
        'status_changed_at' => now(),
        'status_changed_by' => auth()->id(),
        'started_at'        => $task->started_at
                                ?? ($validated['status'] === InstallTask::STATUS_IN_PROGRESS ? now() : null),
        'completed_at'      => $validated['status'] === InstallTask::STATUS_COMPLETE ? now() : null,
    ]);

    // Recompute room + programme counters so the client can update without a second request.
    $programme = $task->programme;
    $roomTotal = $programme->tasks()->where('room_name', $task->room_name)->count();
    $roomDone  = $programme->tasks()
        ->where('room_name', $task->room_name)
        ->where('status', InstallTask::STATUS_COMPLETE)
        ->count();
    $progTotal = $programme->tasks()->count();
    $progDone  = $programme->tasks()->where('status', InstallTask::STATUS_COMPLETE)->count();

    Log::info('TaskStatusController: status updated', [
        'task_id'    => $task->id,
        'status'     => $validated['status'],
        'user_id'    => auth()->id(),
    ]);

    return response()->json([
        'id'             => $task->id,
        'status'         => $task->status,
        'blocked_reason' => $task->blocked_reason,
        'counters'       => [
            'room'      => ['complete' => $roomDone, 'total' => $roomTotal],
            'programme' => ['complete' => $progDone, 'total' => $progTotal],
        ],
    ]);
}
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| REQUIREMENTS.md INST-03e "using GD" | CONTEXT.md D-11 — Intervention Image + Imagick | 2026-04-19 (CONTEXT gathering) | Planner MUST use Imagick. GD cannot decode HEIC. |
| Client-side HEIC conversion via library (heic2any) | Server-side conversion only | Mandated by REQUIREMENTS.md Technical Constraints | iOS silently uploads succeeds but downstream pipelines break later — document as a non-negotiable. |
| `image/jpeg` as default accepted upload | `image/heic`, `image/heif`, `image/heic-sequence`, `image/heif-sequence` + JPEG/PNG via `mimetypes` rule | Phase 14 requirement | Validation rule uses `mimetypes`, not `mimes` or `image`. |
| `intervention/image` v2 (`$img->save()`) | v3 `ImageManager::imagick()->read()->toJpeg()->save()` | v3 released 2024; v4 2026-03-28 (PHP 8.3 only) | Stay on v3 (our PHP is 8.2). |

**Deprecated/outdated:**
- Intervention Image v2 (2.x) — still usable but not installed; would require `Image::make()` facade. Don't install.
- `intervention/image-laravel` service provider — not required for v3; we instantiate directly. Don't install.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | Production server has `php-imagick` with `libheif` delegate when Phase 14 ships | Standard Stack, Pitfall 1 | HIGH — every iPhone HEIC upload 500s. Mitigation: boot-time health check logs warning; per-upload throws clear error. Ops can diagnose with one-liner in Pitfall 1. |
| A2 | `intervention/image` v3.11.7 + Imagick driver can actually decode HEIC when libheif is present | Standard Stack | MEDIUM — one blog (Geni Jaho) reports it fails; other sources say it works. Mitigation: include a committed HEIC fixture in Wave 3 tests so CI proves the stack end-to-end. If it genuinely fails, the fallback is `shell_exec('magick input.heic output.jpg')` wrapped in the same `HeicImageConverter` interface — same public API, swapped implementation. Not blocking the plan. |
| A3 | iOS Safari `capture="environment"` opens the rear camera reliably | Architecture Patterns | LOW — standard iOS behaviour since iOS 14. Precedent: existing survey photo component uses same attribute in production. |
| A4 | No existing data in `install_tasks` has `status_changed_at` / `status_changed_by` set (since columns don't exist yet) | Example 3 migration | LOW — new columns, nullable, default null. Safe. |
| A5 | `Storage::disk('local')->path()` resolves to an absolute path on Windows in the same way as Linux | HeicImageConverter example | LOW — Laravel abstracts this; precedent in `SiteSurveyController::servePhoto()` works on both. |
| A6 | 20 MB max photo size is a reasonable iPhone boundary | CONTEXT.md Claude's discretion | LOW — iPhone photos typically 3–15 MB; 20 MB headroom. |
| A7 | Phase 15 will add `category` column via a non-destructive migration, not rename `time_entries` | Discretion — time_entries minimal schema | MEDIUM — if Phase 15 instead renames columns, Wave 1 data has to migrate. Mitigation: CONTEXT.md explicitly says "Phase 15 adds it via a non-destructive migration". Planner records this as a Phase 15 dependency contract. |
| A8 | REQUIREMENTS.md Technical Constraints row about `last_heartbeat_at` does not force wiring up the heartbeat loop in Phase 14 | Phase Requirements | LOW — constraint is about schema ("required from day one — not retrofittable"), not behaviour. Column added now; JS heartbeat + scheduled command = Phase 15 (INST-04d, INST-04e). |

**Confirm with user before execution:** A1, A2, A7. Others are low-risk defaults.

## Open Questions

1. **Does the production server (as of phase execution) have `ext-imagick` AND the libheif delegate?**
   - What we know: REQUIREMENTS.md mandates HEIC handling; CONTEXT.md D-11 mandates Imagick.
   - What's unclear: Current state of the production server is not inspected in this research.
   - Recommendation: Wave 0 task — run `php -r "echo extension_loaded('imagick') ? 'ok' : 'MISSING'; print_r((new Imagick())->queryFormats('HEI*'));"` on the dev box and every target environment **before** Wave 1 lands. Add the one-liner to a new `php artisan env:check-imagick` command if we want it repeatable. (This is a small, safe artisan command — belongs in Wave 1.)

2. **Should we commit a real HEIC test fixture (~200 KB) to the repo?**
   - What we know: Tests need to assert HEIC→JPEG end-to-end.
   - What's unclear: Repo policy on binary test fixtures. `tests/` has no existing HEIC.
   - Recommendation: Yes — commit `tests/fixtures/sample.heic` (a ~100 KB real HEIC photo). Binary in git is fine at this size. Alternative: generate one via `magick -size 100x100 xc:red sample.heic` at test-bootstrap time — dependency on `magick` binary in CI, messier.

3. **Field controller: extend `InstallProgrammeController` or create `FieldController`?**
   - What we know: CONTEXT.md leaves this to the planner. Existing controller is 262 lines.
   - Recommendation: Extend `InstallProgrammeController` with `field()`. The status / photo / time endpoints get their own dedicated controllers (see "Route registration" above) because they have different responsibilities. This keeps `InstallProgrammeController` as the install-programme-shaped HTTP surface and isolates the mobile-specific action patterns.

4. **Should room-progress counters be computed on every status save, or on view load only?**
   - What we know: D-01 requires room `N of M` counter. D-04 requires programme-wide progress bar.
   - What's unclear: Whether counter drift matters enough to justify a per-save recompute.
   - Recommendation: Per-save recompute (already shown in Example 5). Cost = 2 COUNT queries per status change = negligible. Benefit: UI is always correct without double-requests.

5. **Does `mimes:heic,heif` work at all, or do we definitely need `mimetypes`?**
   - What we know: Laravel 12 docs show `mimes` reads file contents. HEIC is supported in `mimes` starting Laravel 9 per some community sources.
   - What's unclear: Whether all Laravel 12 environments reliably detect HEIC via `mimes`.
   - Recommendation: Use `mimetypes:image/jpeg,image/png,image/webp,image/heic,image/heif` for certainty. Slightly more verbose but removes ambiguity. (Multiple sources recommend this approach.)

6. **Lightbox: native `<dialog>` vs Alpine modal?**
   - What we know: CONTEXT.md says "Tap opens a lightbox".
   - Recommendation: Native `<dialog>` with `showModal()` — zero dependencies, iOS-native, auto-dismissable. If browser support becomes an issue, fall back to a small Alpine modal (8 lines).

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP | — | ✓ (implicit) | ^8.2 per `composer.json` | — |
| Composer | Installing intervention/image | ✓ (assumed; used throughout project) | — | — |
| MySQL | New migrations | ✓ (implicit) | — | SQLite for tests |
| `ext-imagick` (PHP) | `HeicImageConverter` | **UNKNOWN** on this Windows box | — | **Fail loudly per D-11.** No code fallback. |
| ImageMagick + libheif delegate | HEIC decoding inside Imagick | **UNKNOWN** | — | **Fail loudly per D-11.** Surface via health check + per-upload error. |
| Node.js / npm | Existing `npm run build` for Alpine/Tailwind | ✓ (bundle exists at `public/build`) | — | — |
| iPhone / iOS device | Manual smoke test of field view + HEIC upload | — (test device dependent) | — | Commit HEIC fixture for automated test; device testing is final-verification only. |

**Missing dependencies with no fallback:**
- `ext-imagick` → per CONTEXT.md D-11, this is **intentionally** no-fallback. Plan must call out ops dependency explicitly.
- ImageMagick + `libheif` → same. Surfaces only at upload time if missing.

**Missing dependencies with fallback:**
- None — every other dependency is already in the project or in Laravel 12 baseline.

**Audit note:** PHP CLI is NOT on the bash shell PATH in the current research environment (`php: command not found`). The planner's Wave 0 task should validate `ext-imagick` availability from a working PHP environment (e.g., inside `php artisan tinker` or via a Windows PowerShell + PHP.exe check) before Wave 1 code lands.

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | PHPUnit `^11.5.3` via `phpunit.xml` |
| Config file | `phpunit.xml` at repo root |
| Test DB | SQLite `:memory:` via `RefreshDatabase` trait (see `tests/Unit/SurveyServiceTest.php`) |
| Quick run command | `php artisan test --filter=FieldView` (scoped to a test class by convention) |
| Full suite command | `php artisan test` |
| Fake disk helper | `Storage::fake('local')` — precedent `DocumentArtifactStorageTest` |
| Fake mail | `Mail::fake()` — precedent Phase 09 notification tests |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| INST-03a | `GET /projects/{project}/programme` 200 for owner/admin, 403 otherwise | Feature | `php artisan test --filter=FieldViewAccessTest` | ❌ Wave 0 |
| INST-03a | View contains no horizontal-scroll-inducing markup (heuristic) | Feature | `php artisan test --filter=FieldViewResponsivenessTest` | ❌ Wave 0 |
| INST-03b | Engineer sees only own assigned tasks; admin/owner sees all | Feature | `php artisan test --filter=FieldViewTaskScopeTest` | ❌ Wave 0 |
| INST-03c | `PATCH /install-tasks/{task}/status` transitions pending → in_progress → complete; blocked/skipped require reason; regression allowed | Feature | `php artisan test --filter=TaskStatusControllerTest` | ❌ Wave 0 |
| INST-03c | Status AJAX returns updated counters | Feature | part of `TaskStatusControllerTest` | ❌ Wave 0 |
| INST-03d | `POST /install-tasks/{task}/photos` with JPEG fixture succeeds | Feature | `php artisan test --filter=TaskPhotoControllerTest::test_jpeg_upload` | ❌ Wave 0 |
| INST-03d | `POST /install-tasks/{task}/photos` with HEIC fixture produces a JPEG on disk | Feature | `php artisan test --filter=TaskPhotoControllerTest::test_heic_converts_to_jpeg` | ❌ Wave 0 — requires HEIC fixture |
| INST-03e | `HeicImageConverter` with HEIC bytes writes JPEG | Unit | `php artisan test --filter=HeicImageConverterTest::test_converts_heic_to_jpeg` | ❌ Wave 0 — skip via `@requires extension imagick` when missing; add explicit error-path test |
| INST-03e | `HeicImageConverter` constructor throws when imagick missing | Unit | `php artisan test --filter=HeicImageConverterTest::test_throws_when_imagick_missing` | ❌ Wave 0 — mock via `runkit7` alternative: split the extension check into a testable service |
| INST-03f | `PATCH /install-tasks/{task}/notes` persists text | Feature | `php artisan test --filter=TaskStatusControllerTest::test_notes_save` | ❌ Wave 0 |
| INST-03g | Room + programme counters update after complete | Feature | part of `TaskStatusControllerTest` | ❌ Wave 0 |
| INST-03h | No service worker registered, no `manifest.json` / `sw.js` references | Feature | heuristic grep test in `FieldViewResponsivenessTest` | ❌ Wave 0 |
| CONTEXT D-09 | `install_task_photos` migration creates expected columns & types | Unit | `php artisan test --filter=InstallTaskPhotoMigrationTest` | ❌ Wave 0 |
| CONTEXT D-11 | `HeicImageConverter` happy-path and extension-missing error-path | Unit | above | ❌ Wave 0 |
| CONTEXT discretion | One open `time_entries` per project per user; second start → 422 | Feature | `php artisan test --filter=TimeEntryControllerTest::test_double_clock_in_rejected` | ❌ Wave 0 |
| CONTEXT discretion | `time_entries` migration includes `last_heartbeat_at` nullable | Unit | `php artisan test --filter=TimeEntryMigrationTest` | ❌ Wave 0 |
| CONTEXT discretion | Clock in → stop → closed entry with `clocked_out_at` set; duration computable | Feature | `php artisan test --filter=TimeEntryControllerTest::test_start_then_stop` | ❌ Wave 0 |
| CONTEXT D-12 | `PATCH /install-task-photos/{photo}` updates caption, 403 for non-owner | Feature | `php artisan test --filter=TaskPhotoControllerTest::test_caption_update` | ❌ Wave 0 |

### Sampling Rate

- **Per task commit:** `php artisan test --filter={TestClassName}` — scoped to the class changed (< 5s)
- **Per wave merge:** `php artisan test --testsuite=Feature && php artisan test --testsuite=Unit` (< 60s for current suite size)
- **Phase gate:** Full `php artisan test` + manual smoke test on iOS Safari (real device, real HEIC)

### Responsiveness Testing (Pragmatic Approach, no Dusk)

Instead of full browser automation, apply a heuristic feature-test:

```php
// tests/Feature/FieldViewResponsivenessTest.php
public function test_field_view_uses_only_mobile_first_tailwind_classes(): void
{
    $response = $this->actingAs($user)->get("/projects/{$project->id}/programme");
    $html = $response->getContent();

    // Assert no absolute pixel widths over 375px
    $this->assertDoesNotMatchRegularExpression('/\bw-\[(?:[4-9]\d{2}|\d{4,})px\]/', $html);

    // Assert no service-worker registration script (INST-03h)
    $this->assertStringNotContainsString('serviceWorker', $html);
    $this->assertStringNotContainsString('navigator.serviceWorker', $html);

    // Assert no fixed, un-collapsing layouts
    $this->assertStringNotContainsString('min-w-[1024', $html);
}
```

Real-device testing happens during the final phase verify step, using the developer's iPhone — document this clearly in Wave 3 success criteria.

### Wave 0 Gaps

- [ ] `tests/fixtures/sample.heic` — committed real HEIC photo (~100 KB) for Feature tests
- [ ] `tests/fixtures/sample.jpg` — committed JPEG for passthrough test
- [ ] `tests/Unit/Services/HeicImageConverterTest.php` — HeicImageConverter unit tests (covers INST-03e, D-11)
- [ ] `tests/Feature/FieldView/FieldViewAccessTest.php` — route + ownership guard
- [ ] `tests/Feature/FieldView/FieldViewTaskScopeTest.php` — INST-03b engineer vs admin filter
- [ ] `tests/Feature/FieldView/FieldViewResponsivenessTest.php` — heuristic responsiveness + no-SW check
- [ ] `tests/Feature/InstallTasks/TaskStatusControllerTest.php` — status + notes AJAX endpoints
- [ ] `tests/Feature/InstallTasks/TaskPhotoControllerTest.php` — upload + caption + delete + serve
- [ ] `tests/Feature/TimeEntries/TimeEntryControllerTest.php` — clock in/out + double-start guard
- [ ] `tests/Unit/Migrations/InstallTaskPhotoMigrationTest.php` — schema assertions
- [ ] `tests/Unit/Migrations/TimeEntryMigrationTest.php` — schema assertions (incl. last_heartbeat_at present)
- [ ] Artisan command `env:check-imagick` OR AppServiceProvider boot-time health warning (pitfall 1) — ops visibility

**Framework install:** None needed. PHPUnit 11 already present.

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V1 Architecture | yes | All routes in `auth` middleware group; policies via abort_if; ownership guard transitively through task → programme → project |
| V2 Authentication | yes (reuse) | Laravel Breeze auth (existing). No new auth paths. |
| V3 Session Management | yes (reuse) | Laravel session driver = database (120-min lifetime). No new session logic. |
| V4 Access Control | yes | `abort_if($project->user_id !== auth()->id() && ! auth()->user()->isAdmin(), 403)` on every endpoint. Engineer scope-filter for task lists (INST-03b / D-02). |
| V5 Input Validation | yes | All endpoints run `$request->validate()` with specific `mimetypes`, `max`, `Rule::in` for status. `status_changed_by` set from `auth()->id()`, never from request input. |
| V6 Cryptography | no | No secrets handled; no client-side encryption; HTTPS at edge assumed. |
| V7 Error Handling & Logging | yes | All services use `Log::info/warning/error` prefixed with class name. Photo upload failure returns structured JSON, never leaks stack trace to client. |
| V8 Data Protection | yes | Photos private (non-public disk). Served through ownership-guarded controller, not symlink. |
| V10 Malicious Code | yes | No user-supplied PHP, no dynamic `include`. Photos go to filesystem; no DB-stored binary. |
| V12 Files & Resources | **critical** | Uploaded files: `mimetypes` validated, `max:20480`, UUID filenames (no user-controlled path), stored outside webroot, served via ownership-guarded action. |
| V13 API | yes | REST-style JSON endpoints. CSRF enforced via `web` middleware (not `api`). Throttle on upload + time routes. |

### Known Threat Patterns for this stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Path traversal via uploaded filename | Tampering | `Str::uuid() . '.jpg'` — never use `$file->getClientOriginalName()` for filesystem path |
| Content-type spoofing (upload claims JPEG, is actually a script) | Tampering | Server-side MIME sniff via `mime_content_type()`, not client `$file->getMimeType()`. Validator uses `mimetypes`, not `mimes`, for strictness. |
| ImageMagick delegate RCE (historical ImageTragick) | Elevation of Privilege | Current ImageMagick (>= 7.x) disables unsafe delegates by default. Ops concern: deploy recent ImageMagick. Intervention Image uses safe read modes. |
| SQL injection via route params | Tampering | Route-model binding uses `{task}` / `{photo}` / `{project}` → Eloquent find → no user SQL. |
| XSS in notes/caption field | Tampering | Blade `{{ }}` auto-escapes. Alpine `x-text` auto-escapes. Do NOT use `{!! !!}` or `x-html` for user content. |
| DOS via massive HEIC upload | DoS | `max:20480` validation + `upload_max_filesize=25M` in php.ini. `throttle:60,1` on upload route. |
| IDOR — user reads another project's photos | Elevation of Privilege / Info Disclosure | `TaskPhotoController::show()` ownership guard through `photo → task → programme → project → user_id` chain. |
| Concurrent double clock-in | Tampering | `DB::transaction` + `lockForUpdate` on open-entry query in `TimeEntryService::start()`. |
| CSRF on AJAX endpoints | Spoofing | `X-CSRF-TOKEN` from `meta` tag, enforced by `VerifyCsrfToken` middleware in `web` group. |
| Unauthenticated access via known route | Spoofing | All routes inside `auth` middleware group — unauthenticated → 302 to login. |

## Sources

### Primary (HIGH confidence)
- `CLAUDE.md` — project constraints, conventions
- `.planning/REQUIREMENTS.md` (§INST-03, §INST-04, §INST-05d, Technical Constraints table)
- `.planning/ROADMAP.md` (Phase 14 / 15 / 16 sections)
- `.planning/phases/14-mobile-field-view/14-CONTEXT.md` (D-01..D-12, discretion items, canonical refs)
- `.planning/phases/12-install-task-generation/12-01-PLAN.md` + `12-01-SUMMARY.md` (install_tasks schema shipped)
- `.planning/phases/13-task-assignment-scheduling/13-01-PLAN.md` + `13-02-PLAN.md` (controller + Alpine patterns)
- `app/Http/Controllers/InstallProgrammeController.php` (ownership guard pattern, schedule() reference)
- `app/Http/Controllers/TaskAssignmentController.php` (JsonResponse + validation pattern)
- `app/Http/Controllers/SiteSurveyController.php` (servePhoto + uploadPhoto pattern)
- `app/Http/Controllers/PublicSurveyController.php` (uploadPhoto + servePhoto + throttle pattern)
- `app/Core/Modules/Survey/SurveyService.php:437–489` (UUID photo storage)
- `app/Models/InstallTask.php` (status enum, existing fillable, casts)
- `app/Models/SiteSurveyPhoto.php` (storagePath + absolutePath pattern)
- `app/Models/User.php:45–47` (`isAdmin()`)
- `database/migrations/2026_03_14_000031_create_site_survey_photos_table.php` (migration template)
- `resources/views/components/survey/photo-upload.blade.php` (Alpine camera-input component)
- `resources/views/public-survey/show.blade.php:1801–1970` (fetch+CSRF AJAX pattern)
- `resources/js/app.js` + `resources/js/bootstrap.js` (Alpine + Axios setup)
- `resources/views/layouts/app.blade.php:6` (csrf-token meta tag)
- `composer.json` (current deps: no image library)
- `phpunit.xml` (SQLite in-memory, PHPUnit 11)
- `tests/Unit/SurveyServiceTest.php` (RefreshDatabase + service pattern)
- Intervention Image v3.11.7 source: `https://raw.githubusercontent.com/Intervention/image/3.11.7/src/ImageManager.php` (verified `::imagick()` factory + `read()` signature)
- Intervention Image v3.11.7 composer.json (verified PHP `^8.1`)
- Packagist `intervention/image` JSON (verified version 3.11.7 is latest v3)

### Secondary (MEDIUM confidence, cross-verified)
- [Laravel 12 Validation docs](https://laravel.com/docs/12.x/validation) — image rule does NOT include HEIC; must use `mimetypes`
- [Laravel Daily: Validate Max File Size](https://laraveldaily.com/post/validate-max-file-size-in-laravel-php-and-web-server) — PHP `upload_max_filesize` default 2M
- [Honeybadger: Using Intervention Image in Laravel](https://www.honeybadger.io/blog/using-intervention-image-in-laravel/) — facade pattern reference (not used here)
- [Intervention/image-laravel GitHub](https://github.com/Intervention/image-laravel) — confirms optional integration package

### Tertiary (LOW confidence, flagged for validation)
- [Geni Jaho: HEIC + ImageMagick in PHP](https://genijaho.medium.com/how-to-add-support-for-heic-images-with-imagemagick-in-php-ffa212f41bf3) — claims Intervention+Imagick unreliable for HEIC; recommends `shell_exec('magick ...')` fallback. **FLAG:** One blogger's experience; not reproducible here. Record as assumption A2 and as Pitfall 1 fallback strategy.
- [Mastering Laravel: HEIC tip](https://masteringlaravel.io/daily/2023-10-27-how-to-get-rid-of-heic-files-in-your-app) — Safari auto-converts HEIC when `accept` attribute lists standard MIMEs; we don't rely on this since server conversion is mandatory.
- [Flux Plugins: Installing Imagick + libheif on Ubuntu](https://fluxplugins.com/installing-imagick-for-php-8-3-on-ubuntu-24-optimized-avif-webp/) — Ubuntu install pattern for php-imagick with AVIF/HEIC.

## Metadata

**Confidence breakdown:**
- Standard stack (`intervention/image` v3.11.7 + Imagick): HIGH — verified at source level, version pinned, PHP compat confirmed
- Architecture patterns (Blade+Alpine+fetch+CSRF, service pattern): HIGH — every pattern has a live precedent in the codebase
- REQUIREMENTS.md vs CONTEXT.md conflict resolution (GD → Imagick): HIGH — explicit CONTEXT.md decision wins
- Pitfalls (HEIC delegate, Laravel image rule, CSRF, IDOR): HIGH — documented and verified
- HEIC reliability via Intervention+Imagick in production: MEDIUM — one contrary blogger; mitigation plan is documented fallback
- Environment availability (imagick extension): LOW — could not verify from this shell, must be Wave 0 task
- Test architecture: HIGH — matches existing phpunit.xml + RefreshDatabase precedent; Wave 0 gaps clearly enumerated

**Research date:** 2026-04-19
**Valid until:** 2026-05-19 (30 days — stable ecosystem, only risk is server-side ImageMagick patch level)

---

*Phase: 14-mobile-field-view*
*Research completed: 2026-04-19*
