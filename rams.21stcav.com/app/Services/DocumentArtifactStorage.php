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

    /**
     * Legacy absolute-path roots, relative to storage_path(). Used ONLY for
     * read-fallback so existing files remain accessible. New writes never go
     * here.
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

        $legacy = storage_path(self::LEGACY_ROOTS[$type] . '/' . $filename);
        if (is_file($legacy)) {
            return $legacy;
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

        $legacy = storage_path(self::LEGACY_ROOTS[$type] . '/' . $filename);
        if (is_file($legacy)) {
            @unlink($legacy);
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
        ];
    }

    private function assertType(string $type): void
    {
        if (! in_array($type, $this->types(), true)) {
            throw new InvalidArgumentException("Unknown document artifact type: {$type}");
        }
    }
}
