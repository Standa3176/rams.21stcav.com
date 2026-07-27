<?php

namespace Tests\Feature\Rams\Snapshot;

use App\Models\Project;
use App\Models\RamsDocument;
use App\Models\User;
use App\Services\DocxBuilderService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DocxXmlNormalizer;
use Tests\TestCase;

/**
 * DOCX-level snapshot test guarding the RAMS DOCX renderer against silent
 * drift between the legacy DocxBuilderService (`flag=false`) and the
 * unified DocxBuilderServiceV2 (`flag=true`) render paths — phase
 * 260726-rf3 Plan 04 Commit 3.
 *
 * Structural twin of PdfSnapshotTest but at the DOCX layer:
 *  - Same fixture (`tilda-21cq29531`).
 *  - Same pinned Carbon clock (see `FIXED_DATE`).
 *  - Same load-or-capture golden pattern.
 *
 * Tagged `snapshot` so the default `phpunit` run skips it (phpunit.xml
 * already excludes the group). Run explicitly with:
 *
 *     vendor/bin/phpunit --group snapshot
 *
 * Golden files live under tests/Fixtures/rams/{fixture}/expected-docx-*:
 *  - expected-docx-v1.xml.norm   → snapshot of DocxBuilderService (legacy)
 *  - expected-docx-v2.xml.norm   → snapshot of DocxBuilderServiceV2 (unified)
 *
 * NORMALISATION: the raw `word/document.xml` inside a PhpWord-emitted
 * DOCX contains three families of drift-prone attributes (`w:id`,
 * `r:id="rId..."`, `w:rsidR*`), plus xmlns declaration-order noise on
 * the root, plus float serialisation drift on section geometry. The
 * shared {@see \Tests\Support\DocxXmlNormalizer} strips or canonicalises
 * every one — see its class docblock for the exhaustive rule list.
 *
 * Golden files can be regenerated deliberately (after a legitimate
 * output change) with:
 *
 *     php artisan rams:regenerate-snapshots [fixture?]
 *
 * The Plan 04 Commit 2 delta between v1 and v2 is intentionally small:
 *  - The cover renders from `RamsDocumentDTO->cover` in v2 (identical
 *    text/style, except CLIENT CONTACT splits name + email onto two
 *    lines via `addTextBreak()` — legacy concatenates with `"\n"`).
 *  - Everything from doc control through Appendix A delegates to the
 *    legacy `buildRestOfDocument()` seam, so the "rest" is byte-identical.
 *
 * `test_v1_and_v2_produce_bounded_delta_for_tilda` asserts that delta
 * stays under 3000 bytes; if it explodes we've broken the "rest delegates
 * to legacy" invariant.
 */
#[Group('snapshot')]
class DocxSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE_DIR = __DIR__ . '/../../../Fixtures/rams';

    /**
     * MUST match {@see \Tests\Feature\Rams\Snapshot\PdfSnapshotTest::FIXED_DATE}
     * and RamsRegenerateSnapshotsCommand::FIXED_DATE — the same clock is
     * used across snapshot infrastructure so cross-format goldens stay
     * comparable.
     */
    private const FIXED_DATE = '2026-07-25 10:30:00';

    protected function setUp(): void
    {
        parent::setUp();

        // The DOCX writer uses `now()->format('Ymd_His_u')` for its filename
        // and `$record->created_at` for the cover date; both must be pinned
        // so successive runs produce identical output.
        Carbon::setTestNow(Carbon::parse(self::FIXED_DATE));

        // H-07 testing convention — keep generated DOCX bytes out of
        // storage/app/documents/rams between runs.
        Storage::fake('documents');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ══════════════════════════════════════════════════════════════════════
    // Tilda — the canonical reference fixture
    // ══════════════════════════════════════════════════════════════════════

    public function test_legacy_docx_renderer_matches_golden_for_tilda(): void
    {
        [
            'v1' => $xmlV1,
        ] = $this->renderBothPipelines('tilda-21cq29531');

        $this->assertGolden('tilda-21cq29531', 'v1', $xmlV1);
    }

    public function test_unified_docx_renderer_matches_golden_for_tilda(): void
    {
        [
            'v2' => $xmlV2,
        ] = $this->renderBothPipelines('tilda-21cq29531');

        $this->assertGolden('tilda-21cq29531', 'v2', $xmlV2);
    }

    /**
     * Belt-and-braces v1↔v2 delta guard.
     *
     * Plan 04 Commit 2's V2 renders the cover from the DTO (adding a
     * `<w:br/>` + a second `<w:r>` block for the CLIENT CONTACT email
     * split) and delegates everything else to the legacy seam. That's a
     * small delta — a few dozen to a few hundred bytes of extra XML. A
     * delta over 3000 bytes means either:
     *
     *  1. The "rest delegates to legacy" invariant broke (V2 accidentally
     *     re-implemented sections that should have been delegated).
     *  2. A big content regression leaked in (a whole section vanished
     *     or was duplicated).
     *
     * Plan 05 will tighten this to structural equivalence once the
     * remaining sections are ported. For now the loose upper bound is
     * enough to catch large-scale drift.
     */
    public function test_v1_and_v2_produce_bounded_delta_for_tilda(): void
    {
        [
            'v1' => $xmlV1,
            'v2' => $xmlV2,
        ] = $this->renderBothPipelines('tilda-21cq29531');

        $delta = strlen($xmlV2) - strlen($xmlV1);

        $this->assertLessThan(
            3000,
            abs($delta),
            "DOCX v2 diverged from v1 by |delta|={$delta} bytes (limit 3000). "
                . 'V2 should only add the CLIENT CONTACT line-break element on the cover; '
                . 'the "rest delegates to legacy" invariant appears to be broken — '
                . 'inspect DocxBuilderServiceV2::build() for accidental re-implementation.',
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // Helpers
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Build the fixture RAMS record, render the DOCX TWICE (flag off, then
     * flag on), and return the normalised `word/document.xml` for each.
     *
     * We deliberately re-render the second pipeline against a fresh copy
     * of the record so the two paths observe identical inputs (the first
     * build mutates `$record->filename` and — via `patchService->patch()`
     * — the generated_data blob, but `->fresh()` reloads from the DB and
     * `patchService` is idempotent).
     *
     * @return array{v1: string, v2: string}
     */
    private function renderBothPipelines(string $fixture): array
    {
        $rams = $this->buildRamsFromFixture($fixture);

        // ── Legacy pipeline (flag off) ────────────────────────────────────
        config(['rams.unified_composer' => false]);
        $pathV1  = app(DocxBuilderService::class)->build(
            $rams->generated_data ?? [],
            $rams->fresh(),
        );
        $bytesV1 = (string) file_get_contents($pathV1);
        $this->assertNotSame('', $bytesV1, 'Legacy DOCX render produced empty bytes.');

        // ── Unified pipeline (flag on) ────────────────────────────────────
        config(['rams.unified_composer' => true]);
        $pathV2  = app(DocxBuilderService::class)->build(
            $rams->generated_data ?? [],
            $rams->fresh(),
        );
        $bytesV2 = (string) file_get_contents($pathV2);
        $this->assertNotSame('', $bytesV2, 'Unified DOCX render produced empty bytes.');

        return [
            'v1' => DocxXmlNormalizer::normalise($bytesV1),
            'v2' => DocxXmlNormalizer::normalise($bytesV2),
        ];
    }

    /**
     * Hydrate the fixture into a real RamsDocument (+ owning Project +
     * owner User) — mirror the setup PdfSnapshotTest uses so the two
     * snapshots always describe the same underlying record.
     */
    private function buildRamsFromFixture(string $fixture): RamsDocument
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

        // Pin created_at to the fixed clock so the cover DATE row is stable.
        $rams->created_at = Carbon::parse(self::FIXED_DATE);
        $rams->save();
        $rams->refresh();

        return $rams;
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
     * Load-or-capture golden pattern (mirror of PdfSnapshotTest). If the
     * golden is missing, capture the current output as the new golden and
     * mark the test skipped so first-run CI doesn't hard-fail. Subsequent
     * runs assert byte-equality.
     */
    private function assertGolden(string $fixture, string $variant, string $actual): void
    {
        $goldenPath = self::FIXTURE_DIR . '/' . $fixture . '/expected-docx-' . $variant . '.xml.norm';

        if (! is_file($goldenPath)) {
            file_put_contents($goldenPath, $actual);
            $this->markTestSkipped(
                "Captured NEW DOCX golden for '{$fixture}' variant='{$variant}' at {$goldenPath}. "
                    . 'Re-run this test to assert against it.',
            );
        }

        $expected = (string) file_get_contents($goldenPath);
        $this->assertSame(
            $expected,
            $actual,
            "DOCX drift detected for '{$fixture}' variant='{$variant}'. "
                . 'If the change is intentional, regenerate the golden with '
                . "`php artisan rams:regenerate-snapshots {$fixture}`.",
        );
    }
}
