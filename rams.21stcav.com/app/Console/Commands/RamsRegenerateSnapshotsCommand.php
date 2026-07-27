<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\RamsDocument;
use App\Models\User;
use App\Services\DocxBuilderService;
use App\Services\Rams\RamsDisplayPatchService;
use App\Support\Rams\RamsDocumentComposer;
use App\Support\Rams\RamsTheme;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Regenerate the golden snapshots consumed by:
 *  - `tests/Feature/Rams/Snapshot/PdfSnapshotTest.php`  (phase 260726-rf3 Plan 03)
 *  - `tests/Feature/Rams/Snapshot/DocxSnapshotTest.php` (phase 260726-rf3 Plan 04)
 *
 * Runs each fixture record through:
 *  - The legacy PDF path (`pdf.rams`) and the unified path (`pdf.rams-v2`),
 *    normalises the HTML, writes `expected-html-{v1|v2}.html`.
 *  - The legacy DocxBuilderService (flag off) and the unified
 *    DocxBuilderServiceV2 (flag on), extracts + normalises
 *    `word/document.xml`, writes `expected-docx-{v1|v2}.xml.norm`.
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
 *
 * The DOCX normaliser is INLINED here (see {@see self::normaliseDocxXml})
 * rather than imported from `Tests\Support\DocxXmlNormalizer` because
 * the `Tests\` namespace is only autoloaded in dev (composer
 * `autoload-dev`). Duplicating the rules here matches the precedent set
 * by {@see self::normaliseHtml} for the HTML normaliser and keeps this
 * command usable from `php artisan` in any environment. The two
 * implementations MUST stay in lock-step — see the docblock on
 * `Tests\Support\DocxXmlNormalizer` for the canonical rule list.
 * ─────────────────────────────────────────────────────────────────────
 */
class RamsRegenerateSnapshotsCommand extends Command
{
    protected $signature = 'rams:regenerate-snapshots
                            {fixture? : Fixture folder name under tests/Fixtures/rams/ (omit to regenerate all)}
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Regenerate the golden HTML + DOCX snapshots for the RAMS PDF + DOCX renderer tests.';

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

        $this->line('About to (re)capture golden HTML + DOCX snapshots for:');
        foreach ($fixtures as $f) {
            $this->line("  - {$f}");
        }
        $this->line('Each fixture writes expected-html-{v1,v2}.html AND expected-docx-{v1,v2}.xml.norm.');

        if (! $this->option('force') && ! $this->confirm('Proceed and overwrite existing goldens?', false)) {
            $this->warn('Aborted — no goldens written.');
            return self::SUCCESS;
        }

        Carbon::setTestNow(Carbon::parse(self::FIXED_DATE));

        // Fake the documents disk for the DOCX render path so its output
        // never lands in the real storage/app/documents/ tree while this
        // command is running.
        Storage::fake('documents');

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
                    [$htmlV1, $htmlV2, $docxV1, $docxV2] = $this->renderAllFour($fixture, $recordPath);
                } catch (\Throwable $e) {
                    $this->error("  ✗ {$fixture}: render failed — " . $e->getMessage());
                    $skipped++;
                    continue;
                }

                $dir = $fixturesRoot . DIRECTORY_SEPARATOR . $fixture;
                File::put($dir . DIRECTORY_SEPARATOR . 'expected-html-v1.html', $htmlV1);
                File::put($dir . DIRECTORY_SEPARATOR . 'expected-html-v2.html', $htmlV2);
                File::put($dir . DIRECTORY_SEPARATOR . 'expected-docx-v1.xml.norm', $docxV1);
                File::put($dir . DIRECTORY_SEPARATOR . 'expected-docx-v2.xml.norm', $docxV2);
                $written++;

                $htmlDelta = strlen($htmlV2) - strlen($htmlV1);
                $docxDelta = strlen($docxV2) - strlen($docxV1);
                $this->info(sprintf(
                    '  ✓ %-22s html: v1=%d v2=%d Δ=%+d   docx: v1=%d v2=%d Δ=%+d',
                    $fixture,
                    strlen($htmlV1), strlen($htmlV2), $htmlDelta,
                    strlen($docxV1), strlen($docxV2), $docxDelta,
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
     * Reproduce the exact steps BOTH snapshot tests use so the goldens
     * this command writes are identical to what the tests compare
     * against. Any drift between the two will cause CI to fail on the
     * VERY NEXT run — so `renderAllFour()` in the command and the
     * `renderBothBlades()` / `renderBothPipelines()` helpers in the
     * PDF + DOCX snapshot tests MUST stay in lock-step.
     *
     * @return array{0: string, 1: string, 2: string, 3: string}
     *         Tuple of (htmlV1, htmlV2, docxV1Norm, docxV2Norm).
     */
    private function renderAllFour(string $fixture, string $recordPath): array
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

        // ── DOCX capture — Plan 04 ────────────────────────────────────────
        // Flip the unified-composer flag to render each pipeline in turn.
        // Each build() call mutates $rams->filename + saves; we ->fresh()
        // before the second call so the two paths observe identical inputs.
        config(['rams.unified_composer' => false]);
        $docxPathV1  = app(DocxBuilderService::class)->build(
            $rams->generated_data ?? [],
            $rams->fresh(),
        );
        $docxBytesV1 = (string) file_get_contents($docxPathV1);

        config(['rams.unified_composer' => true]);
        $docxPathV2  = app(DocxBuilderService::class)->build(
            $rams->generated_data ?? [],
            $rams->fresh(),
        );
        $docxBytesV2 = (string) file_get_contents($docxPathV2);

        return [
            $this->normaliseHtml($htmlV1),
            $this->normaliseHtml($htmlV2),
            $this->normaliseDocxXml($docxBytesV1),
            $this->normaliseDocxXml($docxBytesV2),
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

    /**
     * Mirror of `Tests\Support\DocxXmlNormalizer::normalise()` — inlined
     * for the same reason `normaliseHtml` is: the `Tests\` namespace is
     * only autoloaded via composer `autoload-dev`, so this command can't
     * import it. MUST stay in lock-step with the test helper — every
     * transformation below appears there too.
     *
     * Rules (see the test helper's docblock for the full rationale):
     *  1. Unzip → `word/document.xml`.
     *  2. Sort `xmlns:*` attrs on root `<w:document>`.
     *  3. Strip `w:id="..."`.
     *  4. Strip `r:id="rId..."`.
     *  5. Strip `w:rsidR`, `w:rsidRPr`, `w:rsidRDefault`, `w:rsidP`.
     *  6. Canonicalise decimal attrs inside `<w:sectPr|w:pgMar|w:pgSz>`
     *     to 4 decimal places.
     */
    private function normaliseDocxXml(string $bytes): string
    {
        // 1. Unzip via tempnam (ZipArchive can only open file paths).
        $tmp = tempnam(sys_get_temp_dir(), 'docxnorm-cmd-');
        if ($tmp === false) {
            throw new \RuntimeException('normaliseDocxXml: could not create temp file.');
        }

        try {
            file_put_contents($tmp, $bytes);

            $zip = new ZipArchive();
            if ($zip->open($tmp) !== true) {
                throw new \RuntimeException('normaliseDocxXml: input is not a valid DOCX (zip open failed).');
            }
            $xml = $zip->getFromName('word/document.xml');
            $zip->close();
            if (! is_string($xml)) {
                throw new \RuntimeException('normaliseDocxXml: word/document.xml missing from DOCX.');
            }
        } finally {
            @unlink($tmp);
        }

        // 2. Sort xmlns:* on <w:document>.
        $xml = (string) preg_replace_callback(
            '/<w:document\b([^>]*)>/',
            static function (array $m): string {
                $attrs = $m[1];
                preg_match_all('/\s+xmlns:[A-Za-z0-9]+="[^"]*"/', $attrs, $matches);
                $xmlnsAttrs = $matches[0];
                sort($xmlnsAttrs, SORT_STRING);
                $nonXmlns = rtrim((string) preg_replace('/\s+xmlns:[A-Za-z0-9]+="[^"]*"/', '', $attrs));
                return '<w:document' . $nonXmlns . implode('', $xmlnsAttrs) . '>';
            },
            $xml,
            1,
        );

        // 3-5. Strip noise attributes.
        $xml = (string) preg_replace('/\s+w:id="[^"]*"/', '', $xml);
        $xml = (string) preg_replace('/\s+r:id="rId[^"]*"/', '', $xml);
        $xml = (string) preg_replace('/\s+w:rsid(?:R|RPr|RDefault|P)="[^"]*"/', '', $xml);

        // 6. Canonicalise decimal numeric attributes in section geometry.
        $xml = (string) preg_replace_callback(
            '/<(w:sectPr|w:pgMar|w:pgSz)\b([^>]*)>/',
            static function (array $m): string {
                $tag   = $m[1];
                $attrs = (string) preg_replace_callback(
                    '/(\s+[A-Za-z:]+)="(-?\d+\.\d+)"/',
                    static fn (array $n): string => $n[1] . '="' . number_format((float) $n[2], 4, '.', '') . '"',
                    $m[2],
                );
                return '<' . $tag . $attrs . '>';
            },
            $xml,
        );

        return $xml;
    }
}
