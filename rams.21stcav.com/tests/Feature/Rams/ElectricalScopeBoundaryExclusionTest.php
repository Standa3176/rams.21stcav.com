<?php

namespace Tests\Feature\Rams;

use App\Models\Project;
use App\Models\RamsDocument;
use App\Models\User;
use App\Services\DocxBuilderService;
use App\Services\Rams\RamsDisplayPatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 28 Plan 05 — RULE-09.
 *
 * Locks in that the electrical scope boundary sentence added to
 * RamsDisplayPatchService's unconditional default exclusions array
 * (app/Services/Rams/RamsDisplayPatchService.php:387-395) reaches:
 *
 *   1. reviewed_data['exclusions'] after patch() runs against a
 *      never-reviewed document (reviewed_data['exclusions'] entirely unset).
 *   2. The rendered DOCX exclusions block via DocxBuilderService::build(),
 *      which is the live render path (config('rams.unified_composer') is
 *      false in production, so build() delegates to buildLegacy()).
 *
 * Per 28-05-PLAN.md's D-07 deferral: this test proves ONLY the one settled
 * boundary sentence RULE-09 names. It does not (and should not) assert
 * anything about BS 7671/lock-off wording, a live-working PPE row ban, or
 * "first-fix AV signal/data/ELV cabling" terminology — those are explicitly
 * deferred, see 28-05-SUMMARY.md.
 */
class ElectricalScopeBoundaryExclusionTest extends TestCase
{
    use RefreshDatabase;

    private const BOUNDARY_SENTENCE = 'Electrical scope terminates at the existing socket outlet or client data outlet — no alteration to the fixed electrical installation and no live working under any circumstances.';

    /** Build a never-reviewed RamsDocument fixture (reviewed_data['exclusions'] unset). */
    private function makeRams(): RamsDocument
    {
        $user = User::factory()->create(['name' => 'Sonny Tanda']);
        $project = Project::factory()->create([
            'user_id' => $user->id,
            'name'    => 'Boundary Test AV Fit-Out',
        ]);

        return RamsDocument::factory()->create([
            'user_id'        => $user->id,
            'project_id'     => $project->id,
            'project_name'   => 'Boundary Test AV Fit-Out',
            'project_ref'    => '21CQ99999-01-OPS',
            'client_name'    => 'Boundary Test Ltd',
            'site_address'   => '1 Boundary Street, London',
            'form_data'      => [],
            'generated_data' => [
                'project' => [
                    'name'            => 'Boundary Test AV Fit-Out',
                    'ref'             => '21CQ99999-01-OPS',
                    'client'          => 'Boundary Test Ltd',
                    'site_address'    => '1 Boundary Street, London',
                    'doc_author'      => 'Sonny',
                    'revision'        => 'Rev 1.0',
                    'document_status' => 'For Issue',
                    'working_hours'   => 'Monday–Friday, 09:00–17:30',
                ],
                'team'             => [
                    ['role' => 'Project Manager', 'name' => 'Sonny'],
                ],
                'hazards'          => [],
                'method_statement' => ['phases' => []],
            ],
            // Deliberately omit the 'exclusions' key entirely so
            // RamsDisplayPatchService's !isset seed condition fires.
            'reviewed_data' => null,
            'status'        => RamsDocument::STATUS_COMPLETED,
        ]);
    }

    public function test_patch_seeds_the_electrical_boundary_bullet_for_a_never_reviewed_document(): void
    {
        $rams = $this->makeRams();

        $this->assertNull($rams->reviewed_data['exclusions'] ?? null,
            'Fixture must start with exclusions entirely unset for the !isset seed to be meaningful.');

        app(RamsDisplayPatchService::class)->patch($rams);

        $exclusions = $rams->reviewed_data['exclusions'] ?? [];

        $this->assertIsArray($exclusions);
        $this->assertContains(self::BOUNDARY_SENTENCE, $exclusions,
            'RULE-09 boundary sentence missing from RamsDisplayPatchService default exclusions seed.');
        // The 5 pre-existing defaults must still be present — this is an
        // addition, not a replacement.
        $this->assertContains('No structural works', $exclusions);
        $this->assertCount(6, $exclusions);
    }

    public function test_docx_render_path_includes_the_electrical_boundary_sentence_for_a_never_reviewed_document(): void
    {
        Storage::fake('documents');

        $record = $this->makeRams();

        $builder = app(DocxBuilderService::class);
        $path = $builder->build($record->generated_data ?? [], $record->fresh());

        $this->assertFileExists($path);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true, 'Failed to open generated DOCX as zip.');
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        $this->assertIsString($xml);

        $this->assertStringContainsString('terminates at the existing socket outlet', $xml,
            'RULE-09 boundary sentence did not reach the rendered DOCX exclusions block for a never-reviewed document.');
    }
}
