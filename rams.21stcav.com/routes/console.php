<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use App\Models\Project;
use App\Models\RamsDocument;
use App\Services\AICacheService;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('rams:backfill-project-ids {--commit : Persist changes (default is dry-run)}', function () {
    $commit = (bool) $this->option('commit');

    $this->info($commit ? 'Backfilling RAMS project_id (COMMIT mode).' : 'Dry-run: no changes will be written.');

    $updated = 0;
    $skipped = 0;

    RamsDocument::query()
        ->whereNull('project_id')
        ->orderBy('id')
        ->chunkById(200, function ($ramsDocs) use (&$updated, &$skipped, $commit) {
            foreach ($ramsDocs as $rams) {
                $match = null;

                $ref = trim((string) $rams->project_ref);
                if ($ref !== '') {
                    $match = Project::query()->where('ref', $ref)->first();
                }

                if (! $match) {
                    $name = trim((string) $rams->project_name);
                    if ($name !== '') {
                        $match = Project::query()
                            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                            ->first();
                    }
                }

                if (! $match) {
                    $skipped++;
                    $this->line("SKIP #{$rams->id} — no unique project match.");
                    continue;
                }

                $updated++;
                $this->line("MATCH #{$rams->id} → project #{$match->id} ({$match->name})");

                if ($commit) {
                    DB::table('rams_documents')
                        ->where('id', $rams->id)
                        ->update(['project_id' => $match->id]);
                }
            }
        });

    $this->info("Done. Matches: {$updated}. Skipped: {$skipped}.");
    if (! $commit) {
        $this->info('Run again with --commit to persist changes.');
    }
})->purpose('Backfill rams_documents.project_id from project_ref or project_name');

Artisan::command('ai:cache-prune', function () {
    $deleted = app(AICacheService::class)->pruneExpired();
    $this->info("AI cache pruned: {$deleted} expired entries removed.");
})->purpose('Remove expired AI cache entries from the database');

// Schedule the prune to run nightly.
// ->dailyAt() on Artisan::command() only names the command; Schedule::command() is what
// actually registers it with the task scheduler (php artisan schedule:run).
Schedule::command('ai:cache-prune')->dailyAt('03:00');

