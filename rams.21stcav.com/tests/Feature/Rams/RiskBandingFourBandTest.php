<?php

namespace Tests\Feature\Rams;

use App\Models\Project;
use App\Models\RamsDocument;
use App\Models\User;
use App\Services\DocxBuilderService;
use App\Support\Rams\RamsRiskBand;
use App\Support\Rams\RamsDocumentComposer;
use App\Support\Rams\RamsTheme;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use ReflectionClass;
use Tests\TestCase;

/**
 * quick-260927-rb4 — four-band risk banding across EVERY renderer.
 *
 * THE DEFECT
 * ----------
 * The banding topped out at `>= 10 => HIGH` in four independent copies
 * (pdf/rams.blade.php, pdf/rams-v2.blade.php, DocxBuilderService::riskColour
 * + ::riskBadge, and a fifth differently-banded copy in RiskMatrixService).
 * A 5×5 matrix runs to 25, so the very worst score on the grid — an almost
 * certain fatality — printed in the same pink as a 10, carried the same word
 * "HIGH", and the legend told the reader the same thing. No band said the
 * activity must not proceed and must be redesigned.
 *
 * WHAT THIS TEST LOCKS
 * --------------------
 *  - 25 resolves to the Very High fill, the "VERY HIGH" code and the
 *    redesign wording;
 *  - 16 and 17 fall on OPPOSITE sides of the High / Very High boundary;
 *  - the legend has FOUR rows, in ascending order, in every renderer;
 *  - the legend wording is SCC's reviewed safety copy verbatim
 *    (resources/rams-skill/scripts/build_rams.js:268-272 of the sibling
 *    service-contractor-creator repository — read-only, never written to);
 *  - BOTH PDF blades and the DOCX builder derive from the one helper, so a
 *    cell colour can never disagree with the label beside it or the legend
 *    below it.
 *
 * A test that only checked one Blade would prove nothing about the other —
 * `RAMS_UNIFIED_COMPOSER` chooses between them at render time, so both are
 * exercised here.
 */
class RiskBandingFourBandTest extends TestCase
{
    use RefreshDatabase;

    /** SCC build_rams.js:271 — HIGH, verbatim. */
    private const SCC_HIGH_ACTION =
        'Work must not proceed until the listed controls are implemented and verified by the Lead Engineer.';

    /** SCC build_rams.js:272 — VERY HIGH, verbatim. */
    private const SCC_VHIGH_ACTION =
        'Unacceptable. Work must not proceed. The activity must be redesigned or further controls '
        .'introduced to bring the risk down before any work begins.';

    /** SCC build_rams.js:270 — MEDIUM, verbatim. */
    private const SCC_MED_ACTION =
        'Further reduction required where reasonably practicable. Work may proceed once the listed '
        .'controls are implemented and the residual risk is accepted by the Lead Engineer.';

    /** SCC build_rams.js:269 — LOW, verbatim. */
    private const SCC_LOW_ACTION = 'Acceptable. Monitor and maintain controls.';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        Carbon::setTestNow(Carbon::parse('2026-09-27 09:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── The single source of truth ───────────────────────────────────────────

    public function test_worst_possible_score_is_very_high_not_high(): void
    {
        $band = RamsRiskBand::for(25);

        $this->assertSame('VERY HIGH', $band->code,
            'A 5×5 = 25 is the worst score on the matrix and must not share the word "HIGH" with a 10.');
        $this->assertSame('Very High', $band->name);
        $this->assertSame('17 – 25', $band->range);
        $this->assertSame(RamsRiskBand::FILL_VHIGH, $band->fill);
        $this->assertSame('#'.RamsRiskBand::FILL_VHIGH, $band->cssFill());
        $this->assertSame(self::SCC_VHIGH_ACTION, $band->action);
    }

    public function test_sixteen_and_seventeen_fall_on_opposite_sides(): void
    {
        $high  = RamsRiskBand::for(16);
        $vhigh = RamsRiskBand::for(17);

        $this->assertSame('HIGH', $high->code, '16 is the TOP of High, not Very High.');
        $this->assertSame('VERY HIGH', $vhigh->code, '17 is the BOTTOM of Very High, not High.');
        $this->assertNotSame($high->fill, $vhigh->fill,
            'High and Very High must not share a fill — that is the original defect.');
        $this->assertNotSame($high->action, $vhigh->action);
        $this->assertSame(self::SCC_HIGH_ACTION, $high->action);
    }

    public function test_all_four_band_boundaries(): void
    {
        $expected = [
            1  => 'LOW',  4  => 'LOW',
            5  => 'MED',  9  => 'MED',
            10 => 'HIGH', 16 => 'HIGH',
            17 => 'VERY HIGH', 20 => 'VERY HIGH', 25 => 'VERY HIGH',
        ];

        foreach ($expected as $score => $code) {
            $this->assertSame($code, RamsRiskBand::for($score)->code,
                "Score {$score} must band as {$code}.");
        }
    }

    public function test_legend_has_four_rows_ascending_with_scc_wording(): void
    {
        $legend = RamsRiskBand::legend();

        $this->assertCount(4, $legend, 'The legend must list FOUR bands — a 5×5 matrix reaches 25.');
        $this->assertSame(
            ['LOW', 'MEDIUM', 'HIGH', 'VERY HIGH'],
            array_map(fn (RamsRiskBand $b) => $b->legendCode, $legend),
        );
        $this->assertSame(
            ['1 – 4', '5 – 9', '10 – 16', '17 – 25'],
            array_map(fn (RamsRiskBand $b) => $b->range, $legend),
            'Ranges must be closed — "10+" is what hid the missing fourth band.',
        );
        $this->assertSame(
            [self::SCC_LOW_ACTION, self::SCC_MED_ACTION, self::SCC_HIGH_ACTION, self::SCC_VHIGH_ACTION],
            array_map(fn (RamsRiskBand $b) => $b->action, $legend),
            'Legend wording is SCC reviewed safety copy and must be verbatim.',
        );
        $this->assertCount(4, array_unique(array_map(fn (RamsRiskBand $b) => $b->fill, $legend)),
            'Four bands need four distinct fills.',
        );
    }

    // ── Renderer 1 — pdf/rams.blade.php (live when RAMS_UNIFIED_COMPOSER is unset/false) ──

    public function test_legacy_pdf_blade_renders_very_high_and_a_four_row_legend(): void
    {
        $this->assertBladeBanding('pdf.rams');
    }

    // ── Renderer 2 — pdf/rams-v2.blade.php (live when RAMS_UNIFIED_COMPOSER=true) ──

    public function test_unified_pdf_blade_renders_very_high_and_a_four_row_legend(): void
    {
        $this->assertBladeBanding('pdf.rams-v2');
    }

    // ── Renderer 3 — DocxBuilderService ─────────────────────────────────────

    public function test_docx_risk_colour_and_badge_have_four_bands(): void
    {
        $builder = app(DocxBuilderService::class);
        $ref     = new ReflectionClass($builder);

        $colour = $ref->getMethod('riskColour');
        $colour->setAccessible(true);
        $badge = $ref->getMethod('riskBadge');
        $badge->setAccessible(true);

        $this->assertSame(RamsRiskBand::FILL_LOW,   $colour->invoke($builder, 4));
        $this->assertSame(RamsRiskBand::FILL_MED,   $colour->invoke($builder, 5));
        $this->assertSame(RamsRiskBand::FILL_HIGH,  $colour->invoke($builder, 16));
        $this->assertSame(RamsRiskBand::FILL_VHIGH, $colour->invoke($builder, 17),
            '17 must take the Very High fill in the DOCX too.');
        $this->assertSame(RamsRiskBand::FILL_VHIGH, $colour->invoke($builder, 25));
        $this->assertNotSame($colour->invoke($builder, 16), $colour->invoke($builder, 17));

        $this->assertSame('HIGH',      $badge->invoke($builder, 16));
        $this->assertSame('VERY HIGH', $badge->invoke($builder, 17));
        $this->assertSame('VERY HIGH', $badge->invoke($builder, 25),
            'The DOCX badge called 25 "HIGH" — the same word as a 10.');
    }

    public function test_docx_legend_lists_four_bands_with_scc_wording(): void
    {
        $xml = $this->renderDocumentXml($this->makeRams());

        foreach (['LOW', 'MEDIUM', 'HIGH', 'VERY HIGH'] as $code) {
            $this->assertStringContainsString($code, $xml, "DOCX legend missing the {$code} band.");
        }
        foreach (['1 – 4', '5 – 9', '10 – 16', '17 – 25'] as $range) {
            $this->assertStringContainsString($range, $xml, "DOCX legend missing the {$range} range.");
        }
        $this->assertStringContainsString(self::SCC_HIGH_ACTION, $xml);
        $this->assertStringContainsString(self::SCC_VHIGH_ACTION, $xml);
        $this->assertStringNotContainsString('10+', $xml,
            'The open-ended "10+" band must be gone from the DOCX legend.');
        $this->assertStringNotContainsString('Stop work. Implement immediate controls.', $xml,
            'The three-band HIGH wording must be replaced by SCC reviewed copy.');
    }

    public function test_docx_matrix_grid_paints_the_top_right_cell_very_high(): void
    {
        $xml = $this->renderDocumentXml($this->makeRams());

        $this->assertStringContainsString(RamsRiskBand::FILL_VHIGH, $xml,
            'No cell in the DOCX 5×5 grid carries the Very High fill — scores 17-25 exist in it.');
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Render a RAMS PDF blade with a 5×5=25 initial score and a 4×4=16
     * residual score, then assert the cell, the inline label and the legend
     * all agree about the four bands.
     */
    private function assertBladeBanding(string $view): void
    {
        $rams = $this->makeRams();
        $html = $this->renderBlade($view, $rams);

        // The 25 cell — Very High fill AND the Very High word.
        $this->assertStringContainsString('#'.RamsRiskBand::FILL_VHIGH, $html,
            "{$view}: a score of 25 did not receive the Very High fill.");
        $this->assertStringContainsString('VERY HIGH', $html,
            "{$view}: a score of 25 must not print as merely \"HIGH\".");
        $this->assertStringContainsString('5&times;5=25', $html,
            "{$view}: fixture hazard with L5×S5 did not render.");

        // 16 stays HIGH and keeps the HIGH fill — the boundary holds both ways.
        $this->assertStringContainsString('4&times;4=16', $html,
            "{$view}: fixture residual score 16 did not render.");
        $this->assertStringContainsString('#'.RamsRiskBand::FILL_HIGH, $html,
            "{$view}: a score of 16 must keep the High fill, not the Very High one.");

        // The legend — four rows, closed ranges, SCC wording, no "10+".
        $this->assertSame(4, substr_count($html, 'class="rk-band"'),
            "{$view}: the risk-key legend must have FOUR band rows, one per band.");
        foreach (['LOW', 'MEDIUM', 'HIGH', 'VERY HIGH'] as $code) {
            $this->assertStringContainsString('<strong>'.$code.'</strong>', $html,
                "{$view}: legend missing the {$code} heading.");
        }
        foreach (['1 – 4', '5 – 9', '10 – 16', '17 – 25'] as $range) {
            $this->assertStringContainsString($range, $html,
                "{$view}: legend missing the {$range} range.");
        }
        $this->assertStringContainsString(self::SCC_HIGH_ACTION, $html,
            "{$view}: HIGH legend wording is not the SCC reviewed copy.");
        $this->assertStringContainsString(self::SCC_VHIGH_ACTION, $html,
            "{$view}: VERY HIGH legend wording is not the SCC reviewed copy.");
        $this->assertStringNotContainsString('10+', $html,
            "{$view}: the open-ended \"10+\" band must be gone.");
        $this->assertStringNotContainsString('Stop work. Implement immediate controls.', $html,
            "{$view}: the three-band HIGH wording must be replaced.");
    }

    private function renderBlade(string $view, RamsDocument $rams): string
    {
        if ($view === 'pdf.rams') {
            return view('pdf.rams', [
                'rams' => $rams,
                'data' => $rams->generated_data ?? [],
            ])->render();
        }

        return view('pdf.rams-v2', [
            'rams'  => $rams,
            'data'  => $rams->generated_data ?? [],
            'dto'   => app(RamsDocumentComposer::class)->compose($rams),
            'theme' => app(RamsTheme::class),
        ])->render();
    }

    /**
     * Deterministic fixture: one hazard scored 5×5=25 before controls and
     * 4×4=16 after, so a single render exercises both sides of the 16/17
     * boundary.
     */
    private function makeRams(): RamsDocument
    {
        $user    = User::factory()->create(['name' => 'Sonny Tanda']);
        $project = Project::factory()->create([
            'user_id' => $user->id,
            'name'    => 'Risk Banding Fixture',
        ]);

        return RamsDocument::factory()->create([
            'user_id'        => $user->id,
            'project_id'     => $project->id,
            'project_name'   => 'Risk Banding Fixture',
            'project_ref'    => '21CQ00000-01-OPS',
            'client_name'    => 'Banding Ltd',
            'site_address'   => '1 Test Street, London',
            'form_data'      => [],
            'status'         => RamsDocument::STATUS_COMPLETED,
            'generated_data' => [
                'project' => [
                    'name'         => 'Risk Banding Fixture',
                    'ref'          => '21CQ00000-01-OPS',
                    'client'       => 'Banding Ltd',
                    'site_address' => '1 Test Street, London',
                    'doc_author'   => 'Sonny',
                ],
                'team'    => [['role' => 'Project Manager', 'name' => 'Sonny']],
                'hazards' => [[
                    'hazard'          => 'Work at height above a live auditorium floor',
                    'persons_at_risk' => ['Engineers'],
                    'pre_likelihood'  => 5,
                    'pre_severity'    => 5,
                    'post_likelihood' => 4,
                    'post_severity'   => 4,
                    'controls'        => ['Scaffold tower, trained operatives, exclusion zone'],
                ]],
                'method_statement' => ['phases' => []],
            ],
        ]);
    }

    /** Render the DOCX and return its word/document.xml contents. */
    private function renderDocumentXml(RamsDocument $record): string
    {
        $path = app(DocxBuilderService::class)->build($record->generated_data ?? [], $record->fresh());
        $this->assertFileExists($path);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true, 'Failed to open generated DOCX as zip.');
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        $this->assertIsString($xml);

        return $xml;
    }
}
