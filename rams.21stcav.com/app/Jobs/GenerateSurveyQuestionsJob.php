<?php

namespace App\Jobs;

use App\Core\AI\AIManager;
use App\Core\AI\Prompts\SurveyQuestionsPrompt;
use App\Models\SiteSurveyRoom;
use App\Models\SiteSurveyRoomQuestion;
use App\Models\SolutionType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * GenerateSurveyQuestionsJob — async job to generate AI pre-install check questions per room.
 *
 * Dispatched by SurveyService::createFromProject() for every room that has a
 * solution_type_id. The job loads the room's project context, calls AIManager
 * with SurveyQuestionsPrompt, and persists the returned questions as
 * SiteSurveyRoomQuestion records.
 *
 * Per D-11: failure is silent — the room simply has zero AI-generated questions.
 * The engineer can proceed with the survey unblocked.
 *
 * Queue: default
 * Retries: 2 (AI transient failures)
 * Timeout: 60s (question generation is lightweight)
 */
class GenerateSurveyQuestionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 60;

    public function __construct(
        public readonly int $roomId,
    ) {}

    // =========================================================================
    // HANDLE
    // =========================================================================

    /**
     * Execute the job.
     *
     * Failure is absorbed silently (per D-11): any \Throwable during handle()
     * is logged but not re-thrown. The room is left with zero questions so the
     * survey form remains fully usable.
     */
    public function handle(): void
    {
        try {
            // ── 1. Load room ───────────────────────────────────────────────────
            $room = SiteSurveyRoom::find($this->roomId);

            if (! $room) {
                // Room was deleted between dispatch and execution — discard silently.
                return;
            }

            // ── 2. Load project package ────────────────────────────────────────
            $package = $room->survey?->project?->latestPackage;

            if (! $package) {
                Log::warning('GenerateSurveyQuestionsJob: no project package found', [
                    'room_id' => $this->roomId,
                ]);
                return;
            }

            // ── 3. Resolve solution type from extracted_data room_overviews ────
            $extractedData  = $package->extracted_data ?? [];
            $roomOverviews  = $extractedData['room_overviews'] ?? [];
            $roomData       = collect($roomOverviews)->firstWhere('room', $room->room_name);
            $solutionTypeId = (int) ($roomData['solution_type_id'] ?? 0) ?: null;
            $solutionType   = $solutionTypeId ? SolutionType::find($solutionTypeId) : null;

            // Solution type is optional context. If absent, proceed with empty context so
            // the prompt can still generate generic pre-install checks for the room.
            if (! $solutionType) {
                Log::info('GenerateSurveyQuestionsJob: no solution type resolved, proceeding with generic context', [
                    'room_id'          => $this->roomId,
                    'room_name'        => $room->room_name,
                    'solution_type_id' => $solutionTypeId,
                ]);
            }

            // ── 4. Build equipment list for this room ──────────────────────────
            $equipment = collect($extractedData['equipment'] ?? [])
                ->filter(fn ($item) => isset($item['area']) && strcasecmp($item['area'], $room->room_name) === 0)
                ->values()
                ->toArray();

            // ── 5. Build context for prompt ────────────────────────────────────
            $context = [
                'solution_type_slug' => $solutionType?->slug ?? '',
                'checklist_lines'    => $solutionType?->checklistLines() ?? [],
                'equipment'          => $equipment,
                'works_overview'     => trim((string) ($extractedData['works_overview'] ?? '')),
                'room_description'   => trim((string) ($roomData['description'] ?? '')),
                'room_summary'       => trim((string) ($roomData['summary'] ?? '')),
            ];

            // ── 6. Call AI ─────────────────────────────────────────────────────
            // Resolve via container so tests can inject a mock (app()->bind(AIManager::class, ...)).
            // Calling ->run() as an instance method allows Mockery shouldReceive('run') to intercept.
            $result = app(AIManager::class)->run(
                new SurveyQuestionsPrompt(),
                $context,
                config('ai.default', 'claude'),
            );

            // ── 7. Validate shape ──────────────────────────────────────────────
            $questions = $result['questions'] ?? null;

            if (! is_array($questions)) {
                Log::warning('GenerateSurveyQuestionsJob: AI returned invalid shape', [
                    'room_id' => $this->roomId,
                    'result'  => $result,
                ]);
                return;
            }

            // ── 8. Persist questions ───────────────────────────────────────────
            foreach ($questions as $i => $questionText) {
                if (! is_string($questionText) || trim($questionText) === '') {
                    continue;
                }

                SiteSurveyRoomQuestion::create([
                    'site_survey_room_id' => $room->id,
                    'question'            => trim($questionText),
                    'sort_order'          => $i,
                    'answer'              => null,
                    'other_text'          => null,
                ]);
            }

            Log::info('GenerateSurveyQuestionsJob: questions generated', [
                'room_id' => $this->roomId,
                'count'   => count($questions),
            ]);
        } catch (\Throwable $e) {
            // Per D-11: failure is silent. Log the error but do not rethrow —
            // the room simply has zero AI-generated questions.
            Log::error('GenerateSurveyQuestionsJob: failed', [
                'room_id' => $this->roomId,
                'error'   => $e->getMessage(),
                'attempt' => $this->attempts(),
            ]);
        }
    }

    // =========================================================================
    // FAILURE HOOK — called by the queue after all retries are exhausted
    // =========================================================================

    /**
     * Handle a job failure after all retries are exhausted.
     *
     * Per D-11: silent failure — do not update room status, do not surface to engineer.
     */
    public function failed(\Throwable $e): void
    {
        Log::error('GenerateSurveyQuestionsJob: all retries exhausted', [
            'room_id' => $this->roomId,
            'error'   => $e->getMessage(),
        ]);
        // Per D-11: silent failure — do not update room status, do not surface to engineer
    }
}
