<?php

namespace Tests\Feature\Rams;

use App\Models\Project;
use App\Models\RamsDocument;
use App\Models\User;
use App\Services\DocxBuilderService;
use App\Services\DocxBuilderServiceV2;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 30 Plan 04 — proves the two Phase 30 GATE-04/GATE-14 warning render
 * surfaces (30-UI-SPEC.md Surface 1 panel + Surface 2 hazard-row marker) on
 * `resources/views/rams/review.blade.php`, and — the security-critical part
 * — that `generated_data['compliance_warnings']` can NEVER reach a
 * client-facing generated artefact (both PDF blades, both DOCX builders).
 *
 * Setup for the HTTP review-route assertions is modelled on
 * SharedWorkspaceAccessTest.php:121 (the only other test that GETs
 * `rams.review` and inspects the response), which shows a minimal
 * `generated_data` array is sufficient for the page to render 200 because
 * every local the blade derives from it falls back with `??`.
 *
 * The PDF-blade and DOCX-builder assertions are modelled on
 * CdmContractorNoteRenderRegressionTest (direct blade render, real
 * generated_data fixture) and DocxBrandColourRegressionTest (build() +
 * ZipArchive extraction of word/document.xml), rather than going through
 * HTTP download routes.
 */
class ComplianceWarningsRenderTest extends TestCase
{
    use RefreshDatabase;

    /** Distinctive sentinel proving compliance_warnings never leaks into a client-facing artefact. */
    private const LEAK_SENTINEL = 'ZZ-GATE-LEAK-SENTINEL-30-04-ZZ';

    private function makeProjectAndOwner(): array
    {
        $owner = User::factory()->create();
        $project = Project::factory()->for($owner, 'owner')->create();

        return [$owner, $project];
    }

    private function baseGeneratedData(array $overrides = []): array
    {
        return array_replace([
            'project' => [
                'name'            => 'Compliance Warnings Render Test',
                'ref'             => '21CQ00000-30-OPS',
                'client'          => 'Regression Test Ltd',
                'site_address'    => '1 Regression Street, London',
                'doc_author'      => 'Sonny',
                'revision'        => 'Rev 1.0',
                'document_status' => 'For Issue',
            ],
            'team' => [
                ['role' => 'Project Manager', 'name' => 'Sonny'],
            ],
            'hazards' => [
                ['hazard' => 'Working at Height', 'pre_likelihood' => 3, 'pre_severity' => 4, 'post_likelihood' => 1, 'post_severity' => 2],
                ['hazard' => 'Manual Handling', 'pre_likelihood' => 2, 'pre_severity' => 2, 'post_likelihood' => 1, 'post_severity' => 1],
            ],
            'method_statement' => ['phases' => []],
            'site_emergency'   => [],
        ], $overrides);
    }

    // ── Surface 1 + 2: empty state ───────────────────────────────────────────

    public function test_review_page_renders_no_panel_when_compliance_warnings_absent(): void
    {
        [$owner, $project] = $this->makeProjectAndOwner();

        $rams = RamsDocument::factory()->create([
            'user_id'        => $owner->id,
            'project_id'     => $project->id,
            'status'         => RamsDocument::STATUS_COMPLETED,
            'generated_data' => $this->baseGeneratedData(),
        ]);

        $response = $this->actingAs($owner)->get(route('rams.review', $rams));

        $response->assertOk();
        $response->assertDontSee('flagged for review');
        $response->assertDontSee('These do not block the document.');
        // The `.gate-flagged` CSS rule itself is always present (it mirrors
        // .diff-modified/.diff-added, which are always-present style
        // definitions) — what must be absent is the class actually applied
        // to a row.
        $response->assertDontSee('class="gate-flagged"', false);
        $response->assertDontSee('All checks passed');
    }

    public function test_review_page_renders_no_panel_when_compliance_warnings_empty_array(): void
    {
        [$owner, $project] = $this->makeProjectAndOwner();

        $rams = RamsDocument::factory()->create([
            'user_id'        => $owner->id,
            'project_id'     => $project->id,
            'status'         => RamsDocument::STATUS_COMPLETED,
            'generated_data' => $this->baseGeneratedData(['compliance_warnings' => []]),
        ]);

        $response = $this->actingAs($owner)->get(route('rams.review', $rams));

        $response->assertOk();
        $response->assertDontSee('flagged for review');
    }

    // ── Surface 1 + 2: populated state ───────────────────────────────────────

    public function test_review_page_renders_singular_panel_and_row_marker_for_one_warning(): void
    {
        [$owner, $project] = $this->makeProjectAndOwner();

        $rams = RamsDocument::factory()->create([
            'user_id'        => $owner->id,
            'project_id'     => $project->id,
            'status'         => RamsDocument::STATUS_COMPLETED,
            'generated_data' => $this->baseGeneratedData([
                'compliance_warnings' => [
                    [
                        'gate'         => 'GATE-04',
                        'hazard_index' => 0,
                        'hazard'       => 'Working at Height',
                        'message'      => 'residual severity is lower than initial severity.',
                    ],
                ],
            ]),
        ]);

        $response = $this->actingAs($owner)->get(route('rams.review', $rams));

        $response->assertOk();
        $response->assertSee('⚠ 1 item flagged for review', false);
        $response->assertSee('These do not block the document. Check each one before issuing.');
        $response->assertSee('GATE-04', false);
        $response->assertSee('class="gate-flagged"', false);
    }

    public function test_review_page_pluralises_heading_for_three_warnings(): void
    {
        [$owner, $project] = $this->makeProjectAndOwner();

        $rams = RamsDocument::factory()->create([
            'user_id'        => $owner->id,
            'project_id'     => $project->id,
            'status'         => RamsDocument::STATUS_COMPLETED,
            'generated_data' => $this->baseGeneratedData([
                'compliance_warnings' => [
                    ['gate' => 'GATE-04', 'hazard_index' => 0, 'hazard' => 'Working at Height', 'message' => 'msg one'],
                    ['gate' => 'GATE-04', 'hazard_index' => 1, 'hazard' => 'Manual Handling', 'message' => 'msg two'],
                    ['gate' => 'GATE-14', 'hazard_index' => null, 'step_title' => 'Setup', 'hazard' => 'Electrical', 'message' => 'msg three'],
                ],
            ]),
        ]);

        $response = $this->actingAs($owner)->get(route('rams.review', $rams));

        $response->assertOk();
        $response->assertSee('⚠ 3 items flagged for review', false);
    }

    public function test_only_hazard_row_matching_hazard_index_carries_gate_flagged_class(): void
    {
        [$owner, $project] = $this->makeProjectAndOwner();

        $rams = RamsDocument::factory()->create([
            'user_id'        => $owner->id,
            'project_id'     => $project->id,
            'status'         => RamsDocument::STATUS_COMPLETED,
            'generated_data' => $this->baseGeneratedData([
                'compliance_warnings' => [
                    ['gate' => 'GATE-04', 'hazard_index' => 1, 'hazard' => 'Manual Handling', 'message' => 'flagged row message'],
                ],
            ]),
        ]);

        $html = $this->actingAs($owner)->get(route('rams.review', $rams))->getContent();

        // Exactly one hazard row is flagged (the always-present CSS rule
        // selector is excluded by matching the applied class attribute).
        $this->assertSame(1, substr_count($html, 'class="gate-flagged"'));

        // The panel entry appears regardless of row position.
        $this->assertStringContainsString('Manual Handling', $html);
    }

    public function test_warning_with_null_hazard_index_renders_panel_entry_and_no_row_marker(): void
    {
        [$owner, $project] = $this->makeProjectAndOwner();

        $rams = RamsDocument::factory()->create([
            'user_id'        => $owner->id,
            'project_id'     => $project->id,
            'status'         => RamsDocument::STATUS_COMPLETED,
            'generated_data' => $this->baseGeneratedData([
                'compliance_warnings' => [
                    [
                        'gate'         => 'GATE-14',
                        'hazard_index' => null,
                        'step_title'   => 'Setup',
                        'hazard'       => 'Electrical Isolation',
                        'message'      => 'method step does not cite the hazard it implies.',
                    ],
                ],
            ]),
        ]);

        $html = $this->actingAs($owner)->get(route('rams.review', $rams))->getContent();

        $this->assertStringContainsString('⚠ 1 item flagged for review', $html);
        $this->assertStringContainsString('GATE-14', $html);
        // No row can carry the class since hazard_index is null — never guess a row.
        $this->assertStringNotContainsString('class="gate-flagged"', $html);
    }

    public function test_panel_never_claims_all_checks_passed_or_names_a_non_firing_gate(): void
    {
        [$owner, $project] = $this->makeProjectAndOwner();

        $rams = RamsDocument::factory()->create([
            'user_id'        => $owner->id,
            'project_id'     => $project->id,
            'status'         => RamsDocument::STATUS_COMPLETED,
            'generated_data' => $this->baseGeneratedData([
                'compliance_warnings' => [
                    ['gate' => 'GATE-04', 'hazard_index' => 0, 'hazard' => 'Working at Height', 'message' => 'msg'],
                ],
            ]),
        ]);

        $html = $this->actingAs($owner)->get(route('rams.review', $rams))->getContent();

        $this->assertStringNotContainsString('All checks passed', $html);
        $this->assertStringNotContainsString('0 issues found', $html);
        // GATE-13 and GATE-14 did not fire in this fixture — the panel must not name them.
        $this->assertStringNotContainsString('GATE-13', $html);
        $this->assertStringNotContainsString('GATE-14', $html);
    }

    // ── XSS escaping ──────────────────────────────────────────────────────────

    public function test_warning_message_with_html_metacharacters_renders_escaped_not_as_markup(): void
    {
        [$owner, $project] = $this->makeProjectAndOwner();

        $maliciousHazard = '<script>alert(1)</script>';

        $rams = RamsDocument::factory()->create([
            'user_id'        => $owner->id,
            'project_id'     => $project->id,
            'status'         => RamsDocument::STATUS_COMPLETED,
            'generated_data' => $this->baseGeneratedData([
                'compliance_warnings' => [
                    [
                        'gate'         => 'GATE-04',
                        'hazard_index' => 0,
                        'hazard'       => $maliciousHazard,
                        'message'      => 'residual < initial & "quoted"',
                    ],
                ],
            ]),
        ]);

        $html = $this->actingAs($owner)->get(route('rams.review', $rams))->getContent();

        $this->assertStringNotContainsString($maliciousHazard, $html, 'Raw <script> tag must never appear unescaped.');
        $this->assertStringContainsString(e($maliciousHazard), $html, 'Hazard name must appear HTML-escaped.');
        $this->assertStringContainsString(e('residual < initial & "quoted"'), $html, 'Message must appear HTML-escaped.');
    }

    // ── Must-not-leak boundary: PDF blades + DOCX builders ───────────────────

    private function generatedDataWithSentinel(): array
    {
        return $this->baseGeneratedData([
            'compliance_warnings' => [
                [
                    'gate'         => 'GATE-04',
                    'hazard_index' => 0,
                    'hazard'       => 'Working at Height',
                    'message'      => self::LEAK_SENTINEL,
                ],
            ],
        ]);
    }

    public function test_pdf_rams_blade_never_renders_compliance_warnings(): void
    {
        [$owner, $project] = $this->makeProjectAndOwner();

        $rams = RamsDocument::factory()->create([
            'user_id'        => $owner->id,
            'project_id'     => $project->id,
            'status'         => RamsDocument::STATUS_COMPLETED,
            'generated_data' => $this->generatedDataWithSentinel(),
        ]);

        $html = view('pdf.rams', [
            'rams' => $rams,
            'data' => $rams->generated_data ?? [],
        ])->render();

        $this->assertStringNotContainsString(self::LEAK_SENTINEL, $html);
        $this->assertStringNotContainsString('compliance_warnings', $html);
    }

    public function test_pdf_rams_v2_blade_never_renders_compliance_warnings(): void
    {
        [$owner, $project] = $this->makeProjectAndOwner();

        $rams = RamsDocument::factory()->create([
            'user_id'        => $owner->id,
            'project_id'     => $project->id,
            'status'         => RamsDocument::STATUS_COMPLETED,
            'generated_data' => $this->generatedDataWithSentinel(),
        ]);

        $dto   = app(\App\Support\Rams\RamsDocumentComposer::class)->compose($rams);
        $theme = app(\App\Support\Rams\RamsTheme::class);

        $html = view('pdf.rams-v2', [
            'rams'  => $rams,
            'data'  => $rams->generated_data ?? [],
            'dto'   => $dto,
            'theme' => $theme,
        ])->render();

        $this->assertStringNotContainsString(self::LEAK_SENTINEL, $html);
        $this->assertStringNotContainsString('compliance_warnings', $html);
    }

    public function test_docx_builder_v1_never_emits_compliance_warnings(): void
    {
        Storage::fake('documents');

        [$owner, $project] = $this->makeProjectAndOwner();

        $rams = RamsDocument::factory()->create([
            'user_id'        => $owner->id,
            'project_id'     => $project->id,
            'status'         => RamsDocument::STATUS_COMPLETED,
            'generated_data' => $this->generatedDataWithSentinel(),
        ]);

        $path = app(DocxBuilderService::class)->build($rams->generated_data ?? [], $rams->fresh());
        $this->assertFileExists($path);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true, 'Failed to open generated DOCX as zip.');
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        $this->assertIsString($xml);

        $this->assertStringNotContainsString(self::LEAK_SENTINEL, $xml);
        $this->assertStringNotContainsString('compliance_warnings', $xml);
    }

    public function test_docx_builder_v2_never_emits_compliance_warnings(): void
    {
        Storage::fake('documents');

        [$owner, $project] = $this->makeProjectAndOwner();

        $rams = RamsDocument::factory()->create([
            'user_id'        => $owner->id,
            'project_id'     => $project->id,
            'status'         => RamsDocument::STATUS_COMPLETED,
            'generated_data' => $this->generatedDataWithSentinel(),
        ]);

        $path = app(DocxBuilderServiceV2::class)->build($rams->generated_data ?? [], $rams->fresh());
        $this->assertFileExists($path);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true, 'Failed to open generated DOCX (v2) as zip.');
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        $this->assertIsString($xml);

        $this->assertStringNotContainsString(self::LEAK_SENTINEL, $xml);
        $this->assertStringNotContainsString('compliance_warnings', $xml);
    }
}
