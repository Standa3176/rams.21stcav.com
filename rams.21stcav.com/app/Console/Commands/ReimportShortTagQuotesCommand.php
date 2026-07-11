<?php

namespace App\Console\Commands;

use App\Core\Modules\QuoteImport\QuoteImportService;
use App\Jobs\ReimportQuoteJob;
use App\Models\ProjectPackage;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-shot audit + re-extract for QuoteWerks packages that were imported
 * BEFORE 260604-p9u shipped the pdftotext -layout auto-switch for the
 * priced "ram" short-tag template variant.
 *
 * Symptoms of the pollution (see .planning/quick/260603-q7t and 260604-p9u):
 *   - client_name reads "H1 <company> H1E H4S <person> H4E"
 *   - project_name has trailing " H1E H4S ... H4E"
 *   - room names read "D1S <name> D1E"
 *   - line-items include stray "P4S £nnn P4E" price markers
 *   - postcode parsed as a SKU
 *
 * All of the above are literal parser markers that leaked because the
 * pre-260604 pipeline couldn't detect the short-tag variant and stripped
 * layout information smalot's PDF extractor had, breaking the translator.
 *
 * Both fixes are now on live; every NEW quote extraction produces clean
 * output. This command finds the projects imported before the fix and
 * re-extracts them so their extracted_data / client_name / project_name
 * inherit the clean parse.
 *
 * Safety:
 *   - Default is DRY-RUN (audit only). --commit flag actually dispatches.
 *   - Never touches Project.name / client_name directly — those are
 *     re-derived from extracted_data by ReimportQuoteJob after Claude
 *     re-parses the PDF.
 *   - Handles missing PDFs gracefully (skips with a warning).
 *   - Uses the original uploader as the User for reimportPending; falls
 *     back to any admin if the uploader is gone.
 *   - Groups by project — only the LATEST package per project is queued,
 *     even if multiple past revisions are all polluted.
 */
class ReimportShortTagQuotesCommand extends Command
{
    protected $signature = 'cables:reimport-shorttag-quotes
                            {--commit : Actually dispatch ReimportQuoteJob (default is dry-run audit only)}
                            {--project= : Limit to a single project ID}';

    protected $description = 'Audit + optionally re-extract QuoteWerks packages polluted by the pre-260604 short-tag parser bug';

    private const POLLUTION_MARKERS = ['H1E', 'H4S', 'H4E', 'D1S', 'D1E', 'P4S', 'P4E', 'P5S'];

    public function handle(QuoteImportService $service): int
    {
        $commit    = (bool) $this->option('commit');
        $projectId = $this->option('project');

        $this->info($commit ? '── COMMIT MODE — jobs will be dispatched ──' : '── DRY-RUN MODE (default) — no changes ──');
        $this->newLine();

        $dirty = $this->findPollutedPackages($projectId);

        if ($dirty->isEmpty()) {
            $this->info('No polluted packages found. Nothing to do.');
            return self::SUCCESS;
        }

        $this->line("Found {$dirty->count()} polluted package(s):");
        $this->newLine();

        $rows = $dirty->map(fn (ProjectPackage $pkg) => [
            'pkg_id'      => $pkg->id,
            'project_id'  => $pkg->project_id,
            'project'     => mb_substr((string) ($pkg->project?->name ?? '—'), 0, 40),
            'client'      => mb_substr((string) ($pkg->extracted_data['client_name'] ?? '—'), 0, 40),
            'rev'         => $pkg->revision,
            'status'      => $pkg->status,
            'markers'     => implode(', ', $this->markersFound($pkg)),
        ])->all();

        $this->table(
            ['Pkg', 'Proj', 'Project name', 'Client name (dirty)', 'Rev', 'Status', 'Markers'],
            $rows,
        );

        if (! $commit) {
            $this->newLine();
            $this->warn('DRY-RUN — no jobs dispatched.');
            $this->line('Re-run with --commit to dispatch ReimportQuoteJob for each project (uses latest package per project).');
            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('Dispatching re-extraction jobs...');

        $dispatched = 0;
        $skipped    = 0;

        // Group by project so we only queue the LATEST polluted package per project.
        $byProject = $dirty->groupBy('project_id');

        foreach ($byProject as $projectPackages) {
            $latest = $projectPackages->sortByDesc('revision')->first();

            $user = $latest->uploadedBy
                ?? User::where('is_admin', true)->first()
                ?? User::first();

            if (! $user) {
                $this->warn("  Package #{$latest->id}: no user available for reimportPending — skipped");
                $skipped++;
                continue;
            }

            try {
                $new = $service->reimportPending(user: $user, existing: $latest);
                ReimportQuoteJob::dispatch($new, $user, $latest, null);
                $this->line("  Package #{$latest->id} (project #{$latest->project_id}) → new rev {$new->revision} queued");
                $dispatched++;
            } catch (\Throwable $e) {
                $this->warn("  Package #{$latest->id}: {$e->getMessage()}");
                $skipped++;
            }
        }

        $this->newLine();
        $this->info("Done: {$dispatched} dispatched, {$skipped} skipped.");
        $this->line('Watch progress: sudo -u stcav tail -f storage/logs/laravel.log | grep -i ReimportQuoteJob');
        $this->line('After all jobs finish: delete + recreate any surveys/RAMS/O&Ms built from the dirty projects — they were snapshotted at extract time.');

        return self::SUCCESS;
    }

    /**
     * Find packages whose extracted_data JSON or parent project name/client
     * name contains any of the pollution markers.
     */
    private function findPollutedPackages(?string $projectId)
    {
        $q = ProjectPackage::query()
            ->with(['project', 'uploadedBy'])
            ->where(function ($outer) {
                foreach (self::POLLUTION_MARKERS as $m) {
                    // extracted_data JSON contains the raw marker
                    $outer->orWhereRaw("JSON_SEARCH(LOWER(extracted_data), 'one', ?, NULL, '$**') IS NOT NULL", [strtolower($m)]);
                }
            });

        if ($projectId) {
            $q->where('project_id', $projectId);
        }

        return $q->get()
            ->filter(fn (ProjectPackage $pkg) => !empty($this->markersFound($pkg)))
            ->values();
    }

    /**
     * Return the list of pollution markers present in this package's
     * extracted_data. Uses a real substring scan (JSON_SEARCH above only
     * catches value hits, not embedded ones inside longer strings), so we
     * re-verify here to eliminate false-positive containments.
     */
    private function markersFound(ProjectPackage $pkg): array
    {
        $blob = json_encode($pkg->extracted_data ?? [], JSON_UNESCAPED_UNICODE) ?: '';

        return array_values(array_filter(self::POLLUTION_MARKERS, function ($m) use ($blob) {
            // Word-boundary-ish: marker must appear surrounded by non-alnum
            // so "H1E" doesn't match inside "HD1EM". Case-insensitive.
            return (bool) preg_match('/(?<![A-Za-z0-9])' . preg_quote($m, '/') . '(?![A-Za-z0-9])/i', $blob);
        }));
    }
}
