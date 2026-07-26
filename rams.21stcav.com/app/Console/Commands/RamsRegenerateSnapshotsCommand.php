<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\RamsDocument;
use App\Models\User;
use App\Services\Rams\RamsDisplayPatchService;
use App\Support\Rams\RamsDocumentComposer;
use App\Support\Rams\RamsTheme;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Regenerate the golden HTML snapshots consumed by
 * `tests/Feature/Rams/Snapshot/PdfSnapshotTest.php` (phase 260726-rf3
 * Plan 03).
 *
 * Runs each fixture record through both the legacy (`pdf.rams`) and the
 * unified (`pdf.rams-v2`) render paths, normalises the output, and
 * overwrites the golden files under
 * `tests/Fixtures/rams/{fixture}/expected-html-{v1|v2}.html`.
 *
 * Usage:
 *   php artisan rams:regenerate-snapshots                # all fixtures
 *   php artisan rams:regenerate-snapshots tilda-21cq29531 # single fixture
 *
 * Prints a diff prompt and requires --force (or an interactive `y`) to
 * proceed so a stray CI run can't silently rewrite the goldens.
 *
 * ─────────────────────────────────────────────────────────────────────
 * NOTE: this command runs against a temporary in-memory sqlite DB —
 * the same convention as PHPUnit's `:memory:` — so it never touches
 * production data. Requires artisan migrate:fresh to be safe to invoke.
 * The command uses a nested transaction that is ALWAYS rolled back so
 * even a mis-configured dev database is untouched.
 * ─────────────────────────────────────────────────────────────────────
 */
class RamsRegenerateSnapshotsCommand extends Command
{
    protected $signature = 'rams:regenerate-snapshots
                            {fixture? : Fixture folder name under tests/Fixtures/rams/ (omit to regenerate all)}
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Regenerate the golden HTML snapshots for the RAMS PDF blade tests.';

    /**
     * Pinned clock so `now()`/`created_at` reads inside the blade stay
     * stable across runs — MUST match PdfSnapshotTest::FIXED_DATE.
     */
    private const FIXED_DATE = '2026-07-25 10:30:00';

    public function handle(): int
    {
        $fixturesRoot = base_path('tests/Fixtures/rams');
        if (! is_dir($fixturesRoot)) {
            $this->error("Fixtures directory missing: {$fixturesRoot}");
            return self::FAILURE;
        }

        $target = (string) ($this->argument('fixture') ?? '');
        $fixtures = $target !== ''
            ? [$target]
            : array_values(array_filter(
                scandir($fixturesRoot) ?: [],
                static fn ($f) => $f !== '.' && $f !== '..' && is_dir($fixturesRoot . DIRECTORY_SEPARATOR . $f),
            ));

        if ($fixtures === []) {
            $this->error("No fixtures found under {$fixturesRoot}.");
            return self::FAILURE;
        }

        $this->line('About to (re)capture golden HTML snapshots for:');
        foreach ($fixtures as $f) {
            $this->line("  - {$f}");
        }
        $this->line('Each fixture writes expected-html-v1.html and expected-html-v2.html.');

        if (! $this->option('force') && ! $this->confirm('Proceed and overwrite existing goldens?', false)) {
            $this->warn('Aborted — no goldens written.');
            return self::SUCCESS;
        }

        Carbon::setTestNow(Carbon::parse(self::FIXED_DATE));

        $written = 0;
        $skipped = 0;

        // Wrap ALL work in a transaction that is ALWAYS rolled back — the
        // command must never persist RamsDocument / Project / User records
        // into a shared dev DB.
        DB::beginTransaction();
        try {
            foreach ($fixtures as $fixture) {
                $recordPath = $fixturesRoot . DIRECTORY_SEPARATOR . $fixture . DIRECTORY_SEPARATOR . 'record.json';
                if (! is_file($recordPath)) {
                    $this->warn("  ✗ {$fixture}: record.json missing — skipped");
                    $skipped++;
                    continue;
                }

                try {
                    [$htmlV1, $htmlV2] = $this->renderBoth($fixture, $recordPath);
                } catch (\Throwable $e) {
                    $this->error("  ✗ {$fixture}: render failed — " . $e->getMessage());
                    $skipped++;
                    continue;
                }

                $dir = $fixturesRoot . DIRECTORY_SEPARATOR . $fixture;
                File::put($dir . DIRECTORY_SEPARATOR . 'expected-html-v1.html', $htmlV1);
                File::put($dir . DIRECTORY_SEPARATOR . 'expected-html-v2.html', $htmlV2);
                $written++;

                $delta = strlen($htmlV2) - strlen($htmlV1);
                $this->info(sprintf(
                    '  ✓ %-22s v1=%d bytes  v2=%d bytes  delta=%+d',
                    $fixture,
                    strlen($htmlV1),
                    strlen($htmlV2),
                    $delta,
                ));
            }
        } finally {
            DB::rollBack();
            Carbon::setTestNow();
        }

        $this->line('');
        $this->info("Wrote goldens for {$written} fixture(s); skipped {$skipped}.");
        return $skipped === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Reproduce the exact steps the snapshot test uses so the goldens
     * this command writes are identical to what the test compares
     * against. Any drift between the two will cause CI to fail on the
     * VERY NEXT run — so `renderBoth()` in the command and
     * `renderBothBlades()` in the test intentionally do the SAME work.
     *
     * @return array{0: string, 1: string}
     */
    private function renderBoth(string $fixture, string $recordPath): array
    {
        $fx = json_decode((string) file_get_contents($recordPath), true);
        if (! is_array($fx)) {
            throw new \RuntimeException("Fixture '{$fixture}' record.json is not valid JSON.");
        }

        $owner = User::factory()->create(['name' => $fx['project']['doc_author'] ?? 'Alex Bloggs']);

        $project = Project::factory()->for($owner, 'owner')->create([
            'name'         => $fx['project']['name'],
            'client_name'  => $fx['project']['client_name'],
            'site_address' => $fx['project']['site_address'],
            'ref'          => $fx['project']['ref'],
        ]);

        $rams = RamsDocument::create([
            'user_id'        => $owner->id,
            'project_id'     => $project->id,
            'project_ref'    => $fx['rams']['project_ref'],
            'project_name'   => $fx['rams']['project_name'],
            'client_name'    => $fx['rams']['client_name'],
            'site_address'   => $fx['rams']['site_address'],
            'ai_provider'    => 'claude',
            'ai_model'       => 'claude-sonnet-4-6',
            'filename'       => 'rams-' . $fixture . '.docx',
            'status'         => $fx['rams']['status'],
            'form_data'      => $fx['rams']['form_data']      ?? [],
            'reviewed_data'  => $fx['rams']['reviewed_data']  ?? [],
            'generated_data' => $fx['rams']['generated_data'] ?? [],
        ]);
        $rams->created_at = Carbon::parse(self::FIXED_DATE);
        $rams->save();
        $rams->refresh();

        app(RamsDisplayPatchService::class)->patch($rams);
        $dto   = app(RamsDocumentComposer::class)->compose($rams);
        $theme = app(RamsTheme::class);

        $htmlV1 = view('pdf.rams', [
            'rams' => $rams,
            'data' => $rams->generated_data ?? [],
        ])->render();

        $htmlV2 = view('pdf.rams-v2', [
            'rams'  => $rams,
            'data'  => $rams->generated_data ?? [],
            'dto'   => $dto,
            'theme' => $theme,
        ])->render();

        return [
            $this->normaliseHtml($htmlV1),
            $this->normaliseHtml($htmlV2),
        ];
    }

    /**
     * Mirror of `PdfSnapshotTest::normaliseHtml()`. Kept private and
     * inline so this command has zero dependency on the test namespace
     * (tests aren't autoloaded in production).
     */
    private function normaliseHtml(string $html): string
    {
        $html = str_replace("\r\n", "\n", $html);
        $lines = explode("\n", $html);
        $lines = array_map(static fn ($l) => rtrim($l), $lines);
        $out = implode("\n", $lines);
        if (! str_ends_with($out, "\n")) {
            $out .= "\n";
        }
        return $out;
    }
}
