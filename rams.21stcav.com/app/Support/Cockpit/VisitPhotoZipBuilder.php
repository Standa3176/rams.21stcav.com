<?php

namespace App\Support\Cockpit;

use App\Models\Project;
use App\Models\Visit;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * VisitPhotoZipBuilder — the Bitrix hand-off (Phase 46.1, Plan 46.1-02, Task 1).
 *
 * D-03: one archive per visit, photos only, grouped `{Room}/{before|after|label}/`.
 * The reason is SCC's own comment on the same control — *"so the admin doesn't
 * have to click each thumbnail one at a time"* — and the user's framing: *"they
 * would then download the info and add Bitrix"*. A folder structure survives
 * being dropped into Bitrix; a pile of thumbnails does not.
 *
 * ── THIS IS THE ONE PLACE IN THE PHASE WHERE A PATH MEETS THE DISK ──────
 *
 * `VisitEvidence` carries RELATIVE storage paths and deliberately contains no
 * `Storage::` call at all (T-46.1-05). Here, and in
 * `ProjectCockpitEvidenceController`, is where those strings become filesystem
 * paths — so both threat mitigations live at this call site rather than being
 * assumed of a neighbouring file.
 *
 * T-46.1-06 — PATH TRAVERSAL. The only source of a path is the `path` value on
 * an entry `VisitEvidence` produced from the database. Nothing from the request
 * is ever concatenated into one. Even so, every resolved absolute path is
 * `realpath()`-ed and asserted to sit INSIDE `realpath(Storage::disk('local')
 * ->path(''))` before it is read; an entry resolving outside the disk root is
 * SKIPPED and logged, NEVER read. The rows were written by an UNAUTHENTICATED
 * public token page, so `worksheet_photos.filename` and
 * `device_label_photos.photo_path` are untrusted data even though they are not
 * in this request. `CockpitEvidenceDownloadTest` proves it with a real row
 * whose filename is `../../../../.env` and asserts the `.env` bytes are absent
 * from the archive.
 *
 * T-46.1-07 — ZIP-SLIP. Entry names are built from a room name and an original
 * filename, BOTH engineer-entered free text on an unauthenticated token page,
 * and the archive then leaves this app to be unpacked by whatever the operator
 * uses. So: `basename()` FIRST — the house rule carried in
 * `ProjectDrawingController`'s own T-20-02 comment, which notes a hostile
 * `../../etc/passwd` would write its CONTENTS as `passwd` in the ZIP rather
 * than escape it — then a strict allow-list `preg_replace`. Folder segments get
 * a separator strip, and every `.`/`..`/empty result becomes `Unnamed`. The
 * test enumerates EVERY `getNameIndex($i)` of the produced archive, not the
 * first, because the one entry that escapes would be the one not checked.
 *
 * ── WRITES NOTHING ──────────────────────────────────────────────────────
 *
 * No database write, no `touch()`, and deliberately NO activity-log call — see
 * the ruling (T-46.1-09) recorded in `ProjectCockpitEvidenceController`'s
 * docblock.
 *
 * ── BUILT SYNCHRONOUSLY ─────────────────────────────────────────────────
 *
 * Temp file, then close, then return the path — never streamed while building.
 * `ProjectDrawingController::downloadBundle()` already carries the reason:
 * ZipArchive can produce EMPTY ENTRIES when a caller consumes the response in
 * chunks mid-build. T-46.1-11 accepts the synchronous cost; the only streaming
 * writer available (`maennchen/zipstream-php`) is a transitive dependency of
 * phpoffice/phpspreadsheet, not a direct require, and this phase has no mandate
 * to change the deploy's composer surface.
 *
 * @see \App\Support\Cockpit\VisitEvidence — the bucket mapping lives in ITS docblock
 * @see \App\Http\Controllers\ProjectDrawingController::downloadBundle() — the house pattern
 */
final class VisitPhotoZipBuilder
{
    /** The folder a photo whose room is blank or unresolvable lands in. */
    public const UNASSIGNED_ROOM = 'Unassigned room';

    /** What a folder segment sanitises down to when nothing usable survives. */
    public const UNNAMED = 'Unnamed';

    /**
     * Build the archive and return the ABSOLUTE path of the temp file.
     *
     * The caller streams it and deletes it after send. A visit with zero
     * photos still yields a VALID archive holding only `README.txt` — the
     * builder never returns a broken ZIP, and whether to offer the link at all
     * is the caller's decision.
     */
    public function build(Project $project, Visit $visit, VisitEvidence $evidence): string
    {
        $tmpZip = tempnam(sys_get_temp_dir(), 'visit-photos-').'.zip';

        $zip = new ZipArchive();

        if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($tmpZip);
            abort(500, 'Could not create ZIP archive.');
        }

        $included = 0;
        $skipped  = 0;

        /** @var array<string, int> $sequence per-folder counter, so two photos sharing a name cannot collide */
        $sequence = [];

        foreach ($evidence->photos() as $photo) {
            $absolute = $this->resolveWithinDisk((string) ($photo['path'] ?? ''), [
                'project_id' => $project->id,
                'visit_id'   => $visit->id,
                'kind'       => $photo['kind'] ?? null,
                'photo_id'   => $photo['id'] ?? null,
            ]);

            if ($absolute === null) {
                $skipped++;

                continue;
            }

            $folder = $this->folderSegment((string) ($photo['room'] ?? ''), self::UNASSIGNED_ROOM)
                .'/'
                .$this->bucketSegment((string) ($photo['bucket'] ?? ''));

            $sequence[$folder] = ($sequence[$folder] ?? 0) + 1;

            $entry = $folder
                .'/'
                .sprintf('%03d', $sequence[$folder])
                .'-'
                .$this->entryFilename($photo);

            // T-46.1-07: $entry is built ONLY from sanitised segments above.
            if (! $zip->addFile($absolute, $entry)) {
                $skipped++;
                $sequence[$folder]--;

                continue;
            }

            $included++;
        }

        $zip->addFromString('README.txt', $this->readme($project, $visit, $included, $skipped));

        $zip->close();

        return $tmpZip;
    }

    /**
     * The download filename: slugged, always, because the raw project name is
     * user data and this value lands in a `Content-Disposition` header.
     */
    public function filename(Project $project, Visit $visit): string
    {
        $label = trim((string) ($project->ref ?: $project->name));

        $date = $visit->scheduled_date
            ? $visit->scheduled_date->format('Y-m-d')
            : ($visit->created_at?->format('Y-m-d') ?? now()->format('Y-m-d'));

        $slug = Str::slug($label.'-'.$date.'-photos');

        if ($slug === '') {
            $slug = 'project-'.$project->id.'-visit-'.$visit->id.'-photos';
        }

        return $slug.'.zip';
    }

    /**
     * T-46.1-06 — turn a RELATIVE stored path into an absolute one, or null.
     *
     * Null means: empty, not a real file, or resolving OUTSIDE the local disk
     * root. Nothing is read before this returns. Shared with the controller's
     * inline photo route so there is exactly one containment rule in the phase.
     *
     * @param  array<string, mixed>  $logContext
     */
    public function resolveWithinDisk(string $relativePath, array $logContext = []): ?string
    {
        if (trim($relativePath) === '') {
            return null;
        }

        $root = realpath(Storage::disk('local')->path(''));

        if ($root === false) {
            return null;
        }

        $root = rtrim($root, DIRECTORY_SEPARATOR);

        $candidate = realpath(Storage::disk('local')->path($relativePath));

        if ($candidate === false || ! is_file($candidate)) {
            // A file simply missing on disk is not an attack; it is a short
            // ZIP, and the README says so rather than the download failing.
            return null;
        }

        if ($candidate !== $root && ! str_starts_with($candidate, $root.DIRECTORY_SEPARATOR)) {
            Log::warning('VisitPhotoZipBuilder: refused a path outside the local disk root (T-46.1-06)', $logContext + [
                'stored_path' => $relativePath,
            ]);

            return null;
        }

        return $candidate;
    }

    // ── Sanitisers (T-46.1-07) ───────────────────────────────────────────

    /**
     * A FOLDER segment. Separators and the Windows-reserved set are collapsed
     * to `-`, and so is EVERY run of two or more dots — `../../../etc` must not
     * survive as `..-..-..-etc`, which still carries `..` into the archive even
     * though it could no longer traverse. A `.`-only or empty result becomes
     * `Unnamed`, so no segment can ever be `.` or `..`.
     */
    private function folderSegment(string $raw, string $fallback): string
    {
        $raw = trim($raw);

        if ($raw === '') {
            return $fallback;
        }

        $clean = preg_replace('/[\/\\\\:*?"<>|]+/', '-', $raw) ?? '';
        $clean = preg_replace('/\.{2,}/', '-', $clean) ?? '';
        $clean = preg_replace('/-{2,}/', '-', $clean) ?? '';
        $clean = trim($clean);
        $clean = trim($clean, '-');
        $clean = ltrim($clean, '.');
        $clean = trim($clean, '-');
        $clean = trim($clean);

        if ($clean === '' || trim($clean, '.') === '') {
            return self::UNNAMED;
        }

        return $clean;
    }

    /**
     * The bucket folder. Resolved by MEMBERSHIP against `VisitEvidence::BUCKETS`
     * — the mapping is recorded at that definition site and is not re-derived
     * here, so the ZIP's folders and the Returned tab's headings cannot drift.
     */
    private function bucketSegment(string $bucket): string
    {
        return in_array($bucket, VisitEvidence::BUCKETS, true) ? $bucket : self::UNNAMED;
    }

    /**
     * An ENTRY filename. `basename()` FIRST — the house rule — then a strict
     * allow-list, then a leading `.` strip so no entry is a dotfile.
     *
     * @param  array<string, mixed>  $photo
     */
    private function entryFilename(array $photo): string
    {
        $raw = (string) ($photo['original_name'] ?? '');

        if (trim($raw) === '') {
            // No original name (device-label photos carry none): fall back to
            // the STORED path's basename, which is a generated uuid.
            $raw = basename(str_replace('\\', '/', (string) ($photo['path'] ?? '')));
        }

        // basename() first, on both separators — a Windows-style path must not
        // survive as a segment either.
        $name = basename(str_replace('\\', '/', $raw));

        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?? '';
        // A run of dots is collapsed too: `..name.jpg` could not traverse, but
        // it would still carry `..` into an archive another tool unpacks.
        $name = preg_replace('/\.{2,}/', '.', $name) ?? '';
        $name = trim($name, '-');
        $name = ltrim($name, '.');
        $name = trim($name, '-');

        if ($name === '') {
            $name = 'photo-'.(int) ($photo['id'] ?? 0);
        }

        return $name;
    }

    /**
     * Why a short archive is short. Included and skipped counts, the visit
     * reference and the generation time — and NOTHING from the three
     * capture-audit columns (`device_label_photos.captured_by`,
     * `worksheet_signoffs.ip_address`, `.user_agent`). See the RV-03 block in
     * `VisitEvidence`'s docblock; a manifest is exactly where such a value
     * would sneak back in.
     */
    private function readme(Project $project, Visit $visit, int $included, int $skipped): string
    {
        $lines = [
            'Visit photos',
            '',
            'Project:    '.($project->ref ?: $project->name),
            'Visit:      #'.$visit->id.' ('.$visit->type.')',
            'Generated:  '.now()->toDateTimeString(),
            '',
            'Photos included: '.$included,
            'Photos skipped (file missing on disk): '.$skipped,
            '',
            'Folders are {Room}/{before|after|label}:',
            '  before = site survey photos',
            '  after  = worksheet photos',
            '  label  = device label photos',
            '',
        ];

        return implode("\n", $lines);
    }
}
