<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Single source of truth for "where generated documents live on disk".
 *
 * Before H-07, four pipelines (RAMS / O&M / Worksheet / Cable) each spelled
 * out their own output directory via a mix of storage_path() and
 * Storage::disk('local')->path(), landing in three different roots:
 *
 *   storage/app/rams/
 *   storage/app/om-manuals/
 *   storage/app/private/worksheets/
 *   storage/app/private/cable-schedules/
 *
 * Anyone updating one convention could not see the others — a classic
 * maintenance trap. This service unifies all writes under the `documents`
 * disk (storage/app/documents/{type}/), and readers fall back to the legacy
 * locations so documents generated before the migration remain downloadable.
 *
 * Callers never construct paths by hand — always ask this service via
 * writePath() / readPath() / exists() / delete().
 */
class DocumentArtifactStorage
{
    /** Filesystems.php disk name. */
    public const DISK = 'documents';

    public const TYPE_RAMS      = 'rams';
    public const TYPE_OM        = 'om-manuals';
    public const TYPE_WORKSHEET = 'worksheets';
    public const TYPE_CABLE     = 'cable-schedules';
    // TYPE_SNAGGING (Phase 16) has NO pre-H-07 legacy history — only current
    // documents disk lookup. Deliberately absent from LEGACY_ROOTS so a
    // missing snagging PDF returns null instead of silently resolving to a
    // fake legacy path that never had data in it (B-02 guard).
    public const TYPE_SNAGGING  = 'snagging';

    /**
     * Legacy absolute-path roots, relative to storage_path(). Used ONLY for
     * read-fallback so existing files remain accessible. New writes never go
     * here.
     *
     * NB: TYPE_SNAGGING is intentionally NOT listed — see constant docblock.
     */
    private const LEGACY_ROOTS = [
        self::TYPE_RAMS      => 'app/rams',
        self::TYPE_OM        => 'app/om-manuals',
        self::TYPE_WORKSHEET => 'app/private/worksheets',
        self::TYPE_CABLE     => 'app/private/cable-schedules',
    ];

    /**
     * Absolute filesystem path for writing a new artifact. The parent
     * subdirectory is created on demand so callers don't need to mkdir.
     *
     * @throws InvalidArgumentException when $type is not one of the TYPE_*
     *                                   constants.
     */
    public function writePath(string $type, string $filename): string
    {
        $this->assertType($type);
        $disk = Storage::disk(self::DISK);
        if (! $disk->exists($type)) {
            $disk->makeDirectory($type);
        }
        return $disk->path("{$type}/{$filename}");
    }

    /**
     * Absolute filesystem path for reading an artifact. Prefers the new
     * unified `documents` disk, falling back to the legacy location if the
     * file is still there. Returns null if the file cannot be found in
     * either place.
     */
    public function readPath(string $type, string $filename): ?string
    {
        $this->assertType($type);

        $new = Storage::disk(self::DISK)->path("{$type}/{$filename}");
        if (is_file($new)) {
            return $new;
        }

        // Guard: TYPE_SNAGGING (and any future add-on types) are NOT in
        // LEGACY_ROOTS. Skip the legacy-directory branch when no mapping
        // exists — return null instead of probing a directory that was
        // never a real pre-H-07 location (B-02).
        $legacyRel = self::LEGACY_ROOTS[$type] ?? null;
        if ($legacyRel !== null) {
            $legacy = storage_path($legacyRel . '/' . $filename);
            if (is_file($legacy)) {
                return $legacy;
            }
        }

        return null;
    }

    /** Does the artifact exist in either the new or the legacy location? */
    public function exists(string $type, string $filename): bool
    {
        return $this->readPath($type, $filename) !== null;
    }

    /**
     * Remove the artifact from both possible locations (idempotent). Useful
     * when a soft-deleted record is purged: callers no longer need to know
     * which path the old file landed in.
     */
    public function delete(string $type, string $filename): void
    {
        $this->assertType($type);

        $new = Storage::disk(self::DISK)->path("{$type}/{$filename}");
        if (is_file($new)) {
            @unlink($new);
        }

        // Guard: same rationale as readPath() — types without a LEGACY_ROOTS
        // entry (e.g. TYPE_SNAGGING) skip the legacy branch entirely.
        $legacyRel = self::LEGACY_ROOTS[$type] ?? null;
        if ($legacyRel !== null) {
            $legacy = storage_path($legacyRel . '/' . $filename);
            if (is_file($legacy)) {
                @unlink($legacy);
            }
        }
    }

    /** All configured artifact types. Useful for sanity/self-tests. */
    public function types(): array
    {
        return [
            self::TYPE_RAMS,
            self::TYPE_OM,
            self::TYPE_WORKSHEET,
            self::TYPE_CABLE,
            self::TYPE_SNAGGING,
        ];
    }

    private function assertType(string $type): void
    {
        if (! in_array($type, $this->types(), true)) {
            throw new InvalidArgumentException("Unknown document artifact type: {$type}");
        }
    }
}
