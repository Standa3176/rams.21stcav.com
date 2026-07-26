<?php

namespace Tests\Feature\Rams\Snapshot;

use App\Models\Project;
use App\Models\RamsDocument;
use App\Models\User;
use App\Services\Rams\RamsDisplayPatchService;
use App\Support\Rams\RamsDocumentComposer;
use App\Support\Rams\RamsTheme;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * HTML-level snapshot test guarding the RAMS PDF blade against silent
 * drift between the legacy (`pdf.rams`) and unified (`pdf.rams-v2`)
 * render paths.
 *
 * Tagged with `@group snapshot` so the default `phpunit` run skips it
 * (phpunit.xml `<groups><exclude>snapshot`). Run explicitly with:
 *
 *     vendor/bin/phpunit --group snapshot
 *
 * Golden files live under tests/Fixtures/rams/{fixture}/expected-*.html:
 *  - expected-html-v1.html   → snapshot of pdf.rams (legacy)
 *  - expected-html-v2.html   → snapshot of pdf.rams-v2 (unified)
 *
 * First run: if the golden is missing, captures the current output as the
 * golden and the assertion is skipped for that fixture with a message.
 * Subsequent runs assert byte-equality against the normalised golden.
 *
 * Golden files can be regenerated deliberately (after a legitimate output
 * change) with:
 *
 *     php artisan rams:regenerate-snapshots [fixture?]
 *
 * NORMALISATION: The snapshot doesn't just diff raw HTML — it strips
 * every deterministic-but-render-dependent value that would otherwise
 * make the test flake:
 *  - Document date (`created_at` was pinned to a fixed Carbon timestamp
 *    when the RamsDocument was built above, but any downstream
 *    `now()->format(...)` inside the blade would still drift).
 *  - Windows CRLF vs Unix LF line endings.
 *  - Trailing whitespace per line.
 *
 * Plan 03 does NOT assert v1 == v2 (the composer still doesn't fully
 * cover the compliance-upgrade surface — see deferred-items.md). Plan 05
 * parity sweep tightens the composer + turns that comparison into a hard
 * assertion.
 */
#[Group('snapshot')]
class PdfSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE_DIR = __DIR__ . '/../../../Fixtures/rams';

    /**
     * Pinned test clock so the "Document date" in generated HTML doesn't
     * drift on every run and invalidate the snapshot.
     */
    private const FIXED_DATE = '2026-07-25 10:30:00';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::FIXED_DATE));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Load fixture, build RamsDocument, run patch service + composer,
     * and render BOTH blades. Returns the normalised HTML for each.
     *
     * @return array{v1: string, v2: string}
     */
    private function renderBothBlades(string $fixture): array
    {
        $fx    = $this->loadFixture($fixture);
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

        // Force created_at to the fixed clock so the blade's docDate is stable.
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
            'v1' => self::normaliseHtml($htmlV1),
            'v2' => self::normaliseHtml($htmlV2),
        ];
    }

    private function loadFixture(string $name): array
    {
        $path = self::FIXTURE_DIR . '/' . $name . '/record.json';
        $this->assertFileExists($path, "Fixture record.json missing for '{$name}'.");
        $data = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($data, "Fixture '{$name}' record.json is not valid JSON.");
        return $data;
    }

    /**
     * Strip every drift-prone value from the rendered HTML so byte-diff
     * against the golden file is deterministic.
     *
     * Rules:
     *  - CRLF → LF (Windows checkouts don't fight Unix ones).
     *  - Trailing whitespace on each line trimmed (Blade emits variable
     *    whitespace after `{{ }}` sites).
     *  - Trailing newline enforced (POSIX + IDE friendly).
     */
    public static function normaliseHtml(string $html): string
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
     * Load-or-capture a golden file. When missing, writes the current
     * output as the golden and marks the test skipped so first-run CI
     * doesn't hard-fail. Subsequent runs assert equality.
     */
    private function assertGolden(string $fixture, string $variant, string $actual): void
    {
        $goldenPath = self::FIXTURE_DIR . '/' . $fixture . '/expected-html-' . $variant . '.html';

        if (! is_file($goldenPath)) {
            file_put_contents($goldenPath, $actual);
            $this->markTestSkipped(
                "Captured NEW golden for '{$fixture}' variant='{$variant}' at {$goldenPath}. "
                . "Re-run this test to assert against it."
            );
        }

        $expected = self::normaliseHtml((string) file_get_contents($goldenPath));
        $this->assertSame(
            $expected,
            $actual,
            "HTML drift detected for '{$fixture}' variant='{$variant}'. "
            . "If the change is intentional, regenerate the golden with "
            . "`php artisan rams:regenerate-snapshots {$fixture}`."
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // Tilda — the canonical reference fixture
    // ══════════════════════════════════════════════════════════════════════

    public function test_legacy_pdf_rams_blade_matches_golden_for_tilda(): void
    {
        [
            'v1' => $htmlV1,
        ] = $this->renderBothBlades('tilda-21cq29531');
        $this->assertGolden('tilda-21cq29531', 'v1', $htmlV1);
    }

    public function test_unified_pdf_rams_v2_blade_matches_golden_for_tilda(): void
    {
        [
            'v2' => $htmlV2,
        ] = $this->renderBothBlades('tilda-21cq29531');
        $this->assertGolden('tilda-21cq29531', 'v2', $htmlV2);
    }

    /**
     * Belt-and-braces informational check: v1 and v2 should produce the
     * SAME rendered document (modulo the theme <style> block prefix that
     * v2 adds). This assertion is currently loose — it just guards
     * against a large-scale divergence.
     *
     * Plan 05 will tighten this to structural equivalence once the
     * composer covers the compliance-upgrade surface end-to-end. For now
     * we assert only:
     *   - the byte-delta stays in the +500 to +2000 byte range (the size
     *     of the CSS-variables prefix + a little slack for whitespace).
     */
    public function test_v1_and_v2_produce_comparable_output_for_tilda(): void
    {
        [
            'v1' => $htmlV1,
            'v2' => $htmlV2,
        ] = $this->renderBothBlades('tilda-21cq29531');

        $delta = strlen($htmlV2) - strlen($htmlV1);
        $this->assertGreaterThan(500, $delta,
            "v2 unexpectedly SMALLER than v1 (delta={$delta}). The theme's paletteCss <style> "
            . "block should add ~800 bytes at minimum. A negative delta means the v2 blade lost "
            . "content — Plan 03 kept every section, so this is a regression.");
        $this->assertLessThan(2000, $delta,
            "v2 unexpectedly larger than v1 (delta={$delta}). Plan 03 v2 blade should only "
            . "differ by the paletteCss <style> block (~800 bytes) + minor rewrites. A large "
            . "positive delta means new content leaked in — check the diff.");
    }
}
