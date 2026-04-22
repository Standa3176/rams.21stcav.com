<?php

namespace App\Console\Commands;

use App\Models\RamsDocument;
use App\Services\Rams\RamsComplianceUpgradeService;
use App\Services\RamsDocumentRendererService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * rams:refresh-compliance
 *
 * One-shot migration that back-fills previously-generated RAMS documents after
 * the scope-gating + dedup fix to RamsComplianceUpgradeService.
 *
 * Why this exists: before the fix, addProjectSpecificRisks() unconditionally
 * appended 7 AV-generic hazards (Rack, Ceiling voids, Existing services, etc.)
 * regardless of project scope, and its dedup guard had a strlen > 4 gate that
 * let "Rack", "Dust", "Low" pass through twice on regenerate — producing the
 * RA08/RA15, RA11/RA16, RA13/RA17 duplicate pairs.
 *
 * What this command does per-RAMS:
 *   1. Strips the 7 AV-generic hazard names from generated_data.hazards
 *   2. De-duplicates remaining hazards by exact case-folded hazard name
 *   3. Re-runs RamsComplianceUpgradeService::upgrade() — whose new applies()
 *      closures re-add only the hazards that match the project scope
 *   4. Saves generated_data back to the DB
 *   5. Re-renders the DOCX artifact via RamsDocumentRendererService
 *
 * Usage:
 *   php artisan rams:refresh-compliance              # all RAMS with generated_data
 *   php artisan rams:refresh-compliance --id=5       # single RAMS by id
 *   php artisan rams:refresh-compliance --dry-run    # report only, no writes
 */
class RamsRefreshComplianceCommand extends Command
{
    protected $signature = 'rams:refresh-compliance
                            {--id= : Refresh a single RamsDocument by id (default: all with generated_data)}
                            {--dry-run : Report what would change without writing or re-rendering}';

    protected $description = 'Back-fill previously-generated RAMS docs to strip scope-irrelevant hazards + duplicates and re-render.';

    /**
     * Canonical list of hazard names that the upgrade service auto-injects.
     * Keyed by lowercased exact match. Kept in sync with addProjectSpecificRisks().
     */
    private const AUTO_INJECTED_HAZARDS = [
        'rack installation — heavy equipment handling and securing',
        'cable pulling and termination — strain injury and eye hazard',
        'working in ceiling voids — falling debris and dust inhalation',
        'low voltage av connections — electric shock and equipment damage',
        'fixings into walls and ceilings — structural damage and falling objects',
        'dust generation from drilling and cutting — respiratory and eye hazard',
        'working near existing services — damage to fire, hvac, or electrical systems',
    ];

    public function __construct(
        private readonly RamsDocumentRendererService $renderer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $id     = $this->option('id');
        $dryRun = (bool) $this->option('dry-run');

        $query = RamsDocument::query()->whereNotNull('generated_data');
        if ($id !== null) {
            $query->where('id', (int) $id);
        }

        $docs = $query->get();
        if ($docs->isEmpty()) {
            $this->info('No RAMS documents with generated_data found.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s %d RAMS document(s)%s',
            $dryRun ? 'Would refresh' : 'Refreshing',
            $docs->count(),
            $dryRun ? ' [DRY RUN]' : '',
        ));

        $summary = ['processed' => 0, 'stripped' => 0, 'deduped' => 0, 'rendered' => 0, 'failed' => 0];

        foreach ($docs as $rams) {
            try {
                $result = $this->refreshOne($rams, $dryRun);
                $summary['processed']++;
                $summary['stripped'] += $result['stripped'];
                $summary['deduped']  += $result['deduped'];
                $summary['rendered'] += $result['rendered'] ? 1 : 0;

                $this->line(sprintf(
                    '  #%d %s — stripped %d, deduped %d, after %d hazards%s',
                    $rams->id,
                    $rams->project_name ?: $rams->project_ref ?: '(no name)',
                    $result['stripped'],
                    $result['deduped'],
                    $result['after_count'],
                    $result['rendered'] ? ', re-rendered' : '',
                ));
            } catch (Throwable $e) {
                $summary['failed']++;
                $this->error(sprintf('  #%d FAILED: %s', $rams->id, $e->getMessage()));
                Log::error('RamsRefreshComplianceCommand: refresh failed', [
                    'rams_id'   => $rams->id,
                    'exception' => $e->getMessage(),
                    'trace'     => $e->getTraceAsString(),
                ]);
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'Done — processed=%d, stripped=%d, deduped=%d, rendered=%d, failed=%d%s',
            $summary['processed'],
            $summary['stripped'],
            $summary['deduped'],
            $summary['rendered'],
            $summary['failed'],
            $dryRun ? ' [DRY RUN — nothing written]' : '',
        ));

        return $summary['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{stripped:int,deduped:int,rendered:bool,after_count:int}
     */
    private function refreshOne(RamsDocument $rams, bool $dryRun): array
    {
        $data = (array) ($rams->generated_data ?? []);
        $hazardsBefore = (array) ($data['hazards'] ?? []);

        // Carry PM guidance over from reviewed_data so upgrade()'s scope gates see it.
        // Historical bug: method_statement_notes was stored on reviewed_data but never copied to
        // generated_data, so the keyword gates had nothing to match against.
        $reviewed = (array) ($rams->reviewed_data ?? []);
        $pmNotes  = trim((string) ($reviewed['method_statement_notes'] ?? ''));
        if ($pmNotes !== '' && empty($data['method_statement_notes'])) {
            $data['method_statement_notes'] = $pmNotes;
        }
        if (empty($data['scope_of_works']) && ! empty($reviewed['scope_of_works'])) {
            $data['scope_of_works'] = (string) $reviewed['scope_of_works'];
        }

        // 1. Strip the 7 auto-injected hazards — upgrade() will re-add only those that apply.
        $kept = [];
        $stripped = 0;
        foreach ($hazardsBefore as $h) {
            $name = strtolower(trim((string) ($h['hazard'] ?? '')));
            if (in_array($name, self::AUTO_INJECTED_HAZARDS, true)) {
                $stripped++;
                continue;
            }
            $kept[] = $h;
        }

        // 2. De-duplicate surviving hazards by exact case-folded name (keep first occurrence).
        $seen = [];
        $deduped = [];
        $dupCount = 0;
        foreach ($kept as $h) {
            $name = strtolower(trim((string) ($h['hazard'] ?? '')));
            if ($name === '') {
                $deduped[] = $h;
                continue;
            }
            if (isset($seen[$name])) {
                $dupCount++;
                continue;
            }
            $seen[$name] = true;
            $deduped[]   = $h;
        }

        $data['hazards'] = array_values($deduped);

        // 3. Re-run upgrade — scope-gated applies() closures determine what comes back in.
        $upgraded = RamsComplianceUpgradeService::upgrade($data);
        $afterCount = count((array) ($upgraded['hazards'] ?? []));

        if ($dryRun) {
            return [
                'stripped'    => $stripped,
                'deduped'     => $dupCount,
                'rendered'    => false,
                'after_count' => $afterCount,
            ];
        }

        // 4. Persist.
        $rams->update(['generated_data' => $upgraded]);

        // 5. Re-render DOCX — skip if the RAMS is in a non-renderable state or has no reviewed_data.
        //    Rendering failure must not roll back the data update; data takes precedence because
        //    the next regenerate will re-render anyway.
        $rendered = false;
        try {
            $this->renderer->render($upgraded, $rams);
            $rendered = true;
        } catch (Throwable $e) {
            Log::warning('RamsRefreshComplianceCommand: re-render failed, data persisted anyway', [
                'rams_id'   => $rams->id,
                'exception' => $e->getMessage(),
            ]);
        }

        return [
            'stripped'    => $stripped,
            'deduped'     => $dupCount,
            'rendered'    => $rendered,
            'after_count' => $afterCount,
        ];
    }
}
