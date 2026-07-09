<?php

namespace App\Services;

use App\Models\OmManual;
use App\Models\RamsDocument;
use Illuminate\Support\Facades\Cache;

/**
 * Re-audit S-05 — cached workspace-wide running-jobs count.
 *
 * The admin nav's running-indicator chip previously ran two COUNT()s
 * inline inside layouts/navigation.blade.php on every admin request.
 * Cheap when the tables are healthy, but a wedged queue with many
 * rows stuck in STATUS_GENERATING amplifies DB load across every page
 * render (the count fires whether the chip renders or not).
 *
 * This service wraps the same query with a short cache window so the
 * chip's count stays fresh enough to be useful (15s) but the DB doesn't
 * see a fresh COUNT() per page render.
 *
 * Consumers:
 *   • resources/views/layouts/navigation.blade.php — top-nav chip
 *
 * The cache key is not user-scoped — the count is global workspace data
 * and every admin sees the same number, so all admins share one bucket.
 */
class WorkerStatusService
{
    /** Seconds to cache the count. Short enough to feel live, long
     *  enough to spare the DB across a burst of page renders. */
    private const CACHE_TTL_SECONDS = 15;

    private const CACHE_KEY = 'worker.running_counts.v1';

    /**
     * Return a breakdown of running-job counts across all AI-heavy models.
     *
     * @return array{rams:int, om:int, total:int}
     */
    public function runningCounts(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
            $rams = RamsDocument::query()
                ->whereIn('status', [
                    RamsDocument::STATUS_GENERATING,
                    RamsDocument::STATUS_APPROVED_FOR_GENERATION,
                ])
                ->count();

            $om = OmManual::query()
                ->where('status', OmManual::STATUS_GENERATING)
                ->count();

            return [
                'rams'  => $rams,
                'om'    => $om,
                'total' => $rams + $om,
            ];
        });
    }

    /**
     * Invalidate the cache — call from BuildRamsDocumentJob / BuildOmManualJob
     * hooks so a job start / finish transition doesn't wait 15s to reflect
     * on the nav. Optional: the cache is short-lived enough that most
     * transitions surface within a page render or two anyway.
     */
    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
