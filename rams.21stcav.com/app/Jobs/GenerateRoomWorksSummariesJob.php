<?php

namespace App\Jobs;

use App\Models\ProjectPackage;
use App\Services\RoomOverviewSummaryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * GenerateRoomWorksSummariesJob — Phase 46.9.
 *
 * Turns the imported QuoteWerks room scope (sales prose) into engineer-facing
 * install-action bullets, per room, in the background at import time, so the
 * bullets already exist before anyone creates a site survey or a worksheet.
 *
 * Modelled line-for-line in shape on GenerateSurveyQuestionsJob. It reuses the
 * EXISTING RoomOverviewSummaryService::summarize() and the EXISTING
 * RoomOverviewSummaryPrompt — no new prompt, no new service, no migration
 * (works_summary is a key inside the existing project_packages.extracted_data
 * JSON column), no Blade edit.
 *
 * ── Two named deviations from the GenerateSurveyQuestionsJob precedent ──────
 *
 * (a) D-06(a): the constructor takes `int $packageId`, NOT a room id, because
 *     rooms are JSON array elements inside extracted_data['room_overviews']
 *     and have no primary key. There is no room id to pass. This is therefore
 *     ONE job per package that loops, not twelve jobs — which also matches the
 *     service, whose AI call is a single batch call for every room
 *     (RoomOverviewSummaryService:59, outside any loop).
 *
 * (b) D-06(b): $timeout = 240, not the precedent's 60. A 429 is retried blind
 *     with no backoff and no Retry-After — ClaudeProvider:181 interpolates the
 *     HTTP status into a RuntimeException message string, so a rate limit is
 *     not even distinguishable from a hard error — and AIManager retries twice.
 *     The worst case is 2 x 180s = 360s. The 240 is justified by that
 *     arithmetic alone and is a NEW value in this codebase: no job in app/Jobs/
 *     uses 240 (the existing set is 60, 120, 120, 180, 180, 300 x 4, 600).
 *     The nearest real precedent for a generous AI timeout is
 *     BuildWorksheetJob:36 at 300 ("AI calls per room can be slow for large
 *     projects"); 240 was taken deliberately as the figure the arithmetic
 *     supports rather than rounding up to an existing constant.
 *
 * ⚠ $tries IS DECORATIVE HERE. The whole-body \Throwable catch in handle()
 * means the queue always sees success and never retries. That is the correct,
 * safer posture for a best-effort cosmetic enrichment: an import or a document
 * creation must never fail because of this feature (D-04). DO NOT let a
 * reviewer "fix" it by rethrowing.
 *
 * Queue: default
 * Retries: 2 (decorative — see above)
 * Timeout: 240s
 */
final class GenerateRoomWorksSummariesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 240;

    public function __construct(
        public readonly int $packageId,
    ) {}

    // =========================================================================
    // HANDLE
    // =========================================================================

    /**
     * Execute the job.
     *
     * Failure is absorbed: any \Throwable is logged but never re-thrown, so the
     * rooms simply keep whatever works_summary they already had and the
     * engineer link degrades to derived bullets via SurveyPdfHelpers::textToBullets().
     */
    public function handle(): void
    {
        try {
            // ── 1. Load package ────────────────────────────────────────────────
            $package = ProjectPackage::find($this->packageId);

            if (! $package) {
                // Package was deleted between dispatch and execution — discard silently.
                return;
            }

            // ── 2. Rooms, or nothing ───────────────────────────────────────────
            // This early return is LOAD-BEARING, not defensive padding. The two
            // importFromData() call sites in tests/Feature/ProjectAutoAdvanceTest.php:56,96
            // pass no extracted_data at all, and phpunit.xml:40 sets
            // QUEUE_CONNECTION=sync — so a dispatched job runs INLINE in the
            // test suite. Returning here before any AI call is what keeps the
            // suite off the network.
            $rooms = (array) ($package->extracted_data['room_overviews'] ?? []);

            if (empty($rooms)) {
                return;
            }

            // ── 3. The row filter — this is D-03 and the crux of the phase ─────
            //
            // Select only rows that have nothing a human wrote AND something to
            // summarise, keeping each row's ORIGINAL integer index.
            //
            // WHY the filter lives here and not in the service: handing
            // RoomOverviewSummaryService an unfiltered list WIPES an
            // office-authored summary, through two distinct paths.
            //
            //   Wipe 1 — RoomOverviewSummaryService:72-84. The final array_map
            //   iterates the UNFILTERED input, not the filtered $roomsForAi.
            //   Any row without a matching non-empty AI summary falls into the
            //   else at :78-82, which assigns fallbackSummary() — and that
            //   returns "" UNCONDITIONALLY at :117-121. So an office-authored
            //   row is wiped EVEN WHEN THE AI CALL SUCCEEDS, simply because the
            //   AI was never asked about that room.
            //
            //   Wipe 2 — RoomOverviewSummaryService:47-55. When NO row has an
            //   overview, the method short-circuits and sets works_summary = ""
            //   on every row it was handed.
            //
            // Both are prevented by this one filter. The service must NEVER be
            // changed to take the filter on (D-07: no service edit) — it is a
            // shared service with a live caller behind the sparkle-Generate
            // button and a second caller in WorksheetGeneratorService.
            $selected = [];

            foreach ($rooms as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }

                $existing = trim((string) ($row['works_summary'] ?? ''));
                $overview = trim((string) ($row['overview'] ?? ''));
                $name     = trim((string) ($row['room'] ?? ''));

                if ($name === '' || $existing !== '' || $overview === '') {
                    continue;
                }

                $selected[$index] = [
                    'room'     => $name,
                    'overview' => (string) ($row['overview'] ?? ''),
                ];
            }

            if (empty($selected)) {
                // Nothing to generate — do not call the service, and above all
                // do not ask the AI about a room it has nothing to say about
                // (D-11).
                return;
            }

            // ── 4. One batch AI call for every selected room ───────────────────
            // RoomOverviewSummaryService:59 makes a SINGLE AIManager::run()
            // call with every room in the payload. A 12-room quote is 1 AI
            // call, not 12. This is deliberately NOT a loop of calls.
            $results = app(RoomOverviewSummaryService::class)->summarize(array_values($selected));

            // Index the results by exact room name — the service matches rows
            // to AI output by EXACT 'room' string, so we do the same.
            $byRoom = [];

            foreach ((array) $results as $result) {
                if (! is_array($result)) {
                    continue;
                }

                $name = trim((string) ($result['room'] ?? ''));

                if ($name !== '') {
                    $byRoom[$name] = $result;
                }
            }

            // ── 5. Merge back into a FRESH read of extracted_data ──────────────
            // Mirrors ExtractQuoteJob:254-264 — re-read the column so a
            // concurrent office save is not clobbered by this job's stale copy.
            $fresh     = $package->fresh()->extracted_data ?? [];
            $freshRooms = (array) ($fresh['room_overviews'] ?? []);
            $updated    = 0;

            foreach ($selected as $index => $payload) {
                // Only ever write to an index that was in the selection, and
                // only when that index still exists. NEVER add a row:
                // ProjectPackageReviewController:231-239 freezes the room list
                // in curated mode, so an invented row desyncs the office view
                // from the engineer link (D-09).
                if (! array_key_exists($index, $freshRooms) || ! is_array($freshRooms[$index])) {
                    continue;
                }

                // Defend against the row having been edited between the read
                // and now: if a human wrote a summary in the meantime, theirs
                // wins (D-03).
                if (trim((string) ($freshRooms[$index]['works_summary'] ?? '')) !== '') {
                    continue;
                }

                $result = $byRoom[$payload['room']] ?? null;

                if ($result === null) {
                    continue;
                }

                // On _summary_fallback, persist the MARKER only, never the
                // empty summary — WorksheetGeneratorService:160-176 is the
                // precedent for that refusal, and its reason is that an empty
                // string overwrites prior valid bullets on the next regen.
                if ((bool) ($result['_summary_fallback'] ?? false)) {
                    $freshRooms[$index]['_summary_fallback'] = true;
                    continue;
                }

                $summary = $this->dashPrefix(trim((string) ($result['works_summary'] ?? '')));

                // Never persist an empty works_summary.
                if ($summary === '') {
                    continue;
                }

                // The imported original is kept byte-identical (D-05) — the
                // 'overview' key is never touched here.
                $freshRooms[$index]['works_summary']     = $summary;
                $freshRooms[$index]['_summary_fallback'] = false;
                $updated++;
            }

            $fresh['room_overviews'] = $freshRooms;
            $package->update(['extracted_data' => $fresh]);

            Log::info('GenerateRoomWorksSummariesJob: summaries generated', [
                'package_id'    => $this->packageId,
                'rooms_updated' => $updated,
                'rooms_offered' => count($selected),
            ]);
        } catch (\Throwable $e) {
            // D-04: a document creation or an import must never fail because of
            // this feature. Log and swallow — the rooms keep whatever they had.
            Log::error('GenerateRoomWorksSummariesJob: failed', [
                'package_id' => $this->packageId,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    // =========================================================================
    // DASH-PREFIX INVARIANT (AIB-05 / C-04)
    // =========================================================================

    /**
     * Normalise every non-empty line to the "- " bullet shape.
     *
     * WHY normalise rather than discard: WorksheetGeneratorService:111,174 gate
     * the works_summary BLOCK — a block with no dashed line anywhere is
     * discarded wholesale — while the survey (SurveyController:485-496) applies
     * no such rule and simply splits on newlines. A non-dashed summary would
     * therefore render on the survey and silently vanish from the worksheet,
     * which is exactly the "built, working, unpopulated and silent about it"
     * failure this project has already shipped.
     *
     * Discarding would also throw away usable AI content and leave the room
     * with nothing. Normalising is visually free: WorksheetGeneratorService:578
     * strips the leading dash again when it builds works_summary_bullets.
     *
     * An existing "- " or bullet-character prefix is left exactly as it is, so
     * a correctly-shaped AI response is persisted verbatim.
     */
    private function dashPrefix(string $summary): string
    {
        if ($summary === '') {
            return '';
        }

        $lines = [];

        foreach (preg_split('/\r?\n/', $summary) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (! preg_match('/^[-\x{2022}]\s/u', $line)) {
                $line = '- ' . $line;
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    // =========================================================================
    // FAILURE HOOK — called by the queue after all retries are exhausted
    // =========================================================================

    /**
     * Handle a job failure after all retries are exhausted.
     *
     * In practice unreachable, because handle() swallows every \Throwable — see
     * the $tries note in the class docblock. Kept for parity with
     * GenerateSurveyQuestionsJob:167-174 and because a timeout kill does not
     * pass through handle()'s catch.
     */
    public function failed(\Throwable $e): void
    {
        Log::error('GenerateRoomWorksSummariesJob: all retries exhausted', [
            'package_id' => $this->packageId,
            'error'      => $e->getMessage(),
        ]);
    }
}
