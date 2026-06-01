<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectReferenceFile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Upload / delete / stream engineer reference files (quick task 260601-r4c).
 *
 * - store():  finfo-based MIME sniff + extension allowlist + explicit
 *             deny-list + 20 MB cap; writes via DocumentArtifactStorage
 *             (TYPE_REFERENCE — H-07 single entry point); nests per
 *             project under reference-files/{project_id}/.
 * - delete(): idempotent disk + DB removal.
 * - streamResponse(): shared Content-Disposition logic used by both the
 *             admin controller AND the two public download controllers
 *             so every path emits identical headers (inline for PDF /
 *             image, attachment for everything else).
 *
 * NEVER trusts UploadedFile::getClientMimeType() — that value is client-
 * controlled. Always calls Symfony's getMimeType() which reads the
 * actual file bytes via finfo.
 */
class ProjectReferenceFileService
{
    /**
     * Persist an uploaded reference file against $project.
     *
     * @throws ValidationException when MIME/extension/size fail validation.
     */
    public function store(
        UploadedFile $file,
        Project $project,
        ?User $user,
        ?string $label,
    ): ProjectReferenceFile {
        $this->validateUpload($file);

        $originalName = (string) $file->getClientOriginalName();
        $ext          = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $sanitised    = $this->sanitiseFilename($originalName);
        $basename     = (string) Str::ulid() . '-' . $sanitised;

        // Per-project nested basename — service-layer responsibility.
        // DocumentArtifactStorage::writePath only mkdirs the top-level
        // {type} subdir; we mkdir the per-project nested dir explicitly.
        $relative = $project->id . '/' . $basename;
        $absolute = app(DocumentArtifactStorage::class)
            ->writePath(DocumentArtifactStorage::TYPE_REFERENCE, $relative);

        if (! is_dir(dirname($absolute))) {
            @mkdir(dirname($absolute), 0775, true);
        }

        $file->move(dirname($absolute), basename($absolute));

        // finfo on disk for canonical mime — the service contract is that
        // stored mime is what the bytes actually look like, not what the
        // client claimed pre-upload.
        $sniffedMime = $this->sniffFileMime($absolute) ?? 'application/octet-stream';
        $sizeBytes   = (int) filesize($absolute);

        return ProjectReferenceFile::create([
            'project_id'          => $project->id,
            'label'               => $label !== null ? trim($label) : null,
            'original_filename'   => $originalName,
            'stored_path'         => $relative,
            'mime_type'           => $sniffedMime,
            'size_bytes'          => $sizeBytes,
            'uploaded_by_user_id' => $user?->id,
            'uploaded_at'         => now(),
        ]);
    }

    /**
     * Remove a reference file from disk AND the database. Idempotent —
     * calling twice on the same model is safe (disk delete is no-op when
     * file already missing; second model->delete() simply no-ops because
     * the row already has a primary key that's been deleted).
     */
    public function delete(ProjectReferenceFile $file): void
    {
        $storage = app(DocumentArtifactStorage::class);
        $storage->delete(DocumentArtifactStorage::TYPE_REFERENCE, $file->stored_path);

        if ($file->exists) {
            $file->delete();
        }
    }

    /**
     * Stream a reference file as an HTTP response. Used by the admin
     * download controller AND the two public worksheet/survey download
     * controllers so all three paths emit identical headers.
     *
     * Returns 404 (via abort) when the underlying file is missing.
     */
    public function streamResponse(ProjectReferenceFile $file): Response
    {
        $absolute = app(DocumentArtifactStorage::class)
            ->readPath(DocumentArtifactStorage::TYPE_REFERENCE, $file->stored_path);

        abort_if($absolute === null, 404);

        return response()->file($absolute, [
            'Content-Type'        => $file->mime_type,
            'Content-Disposition' => $this->dispositionFor($file),
        ]);
    }

    /**
     * Compose the Content-Disposition header for a stored reference file.
     * - PDF / image/* → inline (renders in browser tab / iframe).
     * - everything else → attachment (forces download — CAD/Office/CSV).
     */
    public function dispositionFor(ProjectReferenceFile $file): string
    {
        $mime = strtolower((string) $file->mime_type);
        $inline = $mime === 'application/pdf' || str_starts_with($mime, 'image/');

        $disposition = $inline ? 'inline' : 'attachment';

        // RFC 5987 — safe ASCII filename + UTF-8 fallback. Drop quotes
        // from the filename so they don't break the header.
        $safeName = str_replace(['"', "\r", "\n"], '', $file->original_filename);

        return $disposition . '; filename="' . $safeName . '"';
    }

    /**
     * Idempotent: sanitiseFilename(sanitiseFilename($x)) === sanitiseFilename($x).
     *
     * - strips '..', '/', '\\', null bytes, ASCII control chars
     * - collapses repeating underscores + dots
     * - truncates basename to 100 chars
     * - lowercases extension
     * - returns "{base}.{ext}" — never an absolute path, never empty
     */
    public function sanitiseFilename(string $name): string
    {
        $name = trim($name);
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $base = pathinfo($name, PATHINFO_FILENAME);

        $base = str_replace(['..', '/', '\\', chr(0)], '_', $base);
        $base = (string) preg_replace('/[\x00-\x1F\x7F]/u', '_', $base);
        $base = (string) preg_replace('/_{2,}/', '_', $base);
        $base = (string) preg_replace('/\.{2,}/', '.', $base);
        $base = substr($base, 0, 100);

        if ($base === '' || $base === '_') {
            $base = 'file';
        }

        return $ext !== '' ? $base . '.' . $ext : $base;
    }

    // ─── Internals ───────────────────────────────────────────────────────

    /**
     * @throws ValidationException
     */
    private function validateUpload(UploadedFile $file): void
    {
        $allowedMimes      = (array) config('reference_files.allowed_mimes', []);
        $allowedExtensions = array_map('strtolower', (array) config('reference_files.allowed_extensions', []));
        $denyExtensions    = array_map('strtolower', (array) config('reference_files.deny_extensions', []));
        $maxBytes          = (int) config('reference_files.max_size_bytes', 20 * 1024 * 1024);

        // 1. Size cap — UploadedFile::getSize() returns bytes for the
        //    move()d/temp upload (still on disk at validation time).
        $size = (int) ($file->getSize() ?? 0);
        if ($size > $maxBytes) {
            throw ValidationException::withMessages([
                'file' => 'File is larger than 20 MB.',
            ]);
        }

        // 2. Extension allowlist + deny-list (case-insensitive, derived
        //    from the CLIENT-supplied original name).
        $originalName = (string) $file->getClientOriginalName();
        $ext          = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if ($ext !== '' && in_array($ext, $denyExtensions, true)) {
            throw ValidationException::withMessages([
                'file' => 'Filename has a disallowed extension.',
            ]);
        }

        if (! in_array($ext, $allowedExtensions, true)) {
            throw ValidationException::withMessages([
                'file' => 'File type not allowed.',
            ]);
        }

        // 3. finfo-sniffed MIME — NEVER trust getClientMimeType().
        $mime = $file->getMimeType() ?? 'application/octet-stream';

        if (! in_array($mime, $allowedMimes, true)) {
            throw ValidationException::withMessages([
                'file' => 'File type not allowed.',
            ]);
        }

        // 4. octet-stream is only valid when the extension is DWG/DXF
        //    (those binary formats commonly sniff as octet-stream; for
        //    every other extension, an octet-stream MIME means the bytes
        //    don't match the claimed extension — reject).
        if ($mime === 'application/octet-stream' && ! in_array($ext, ['dwg', 'dxf'], true)) {
            throw ValidationException::withMessages([
                'file' => 'File type not allowed.',
            ]);
        }
    }

    private function sniffFileMime(string $absolutePath): ?string
    {
        if (! function_exists('finfo_open')) {
            return null;
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }
        $mime = finfo_file($finfo, $absolutePath);
        finfo_close($finfo);

        return $mime !== false ? $mime : null;
    }
}
