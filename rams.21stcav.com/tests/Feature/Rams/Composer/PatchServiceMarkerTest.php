<?php

namespace Tests\Feature\Rams\Composer;

use App\Models\Project;
use App\Models\RamsDocument;
use App\Models\User;
use App\Services\Rams\RamsDisplayPatchService;
use App\Support\Rams\RamsDocumentComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Contract tests for the RamsDisplayPatchService `_display_patched_at`
 * marker + RamsDocumentComposer warning behaviour.
 *
 * Pins the order-of-operations invariant:
 *   - RamsDisplayPatchService::patch() writes `_display_patched_at`
 *     onto generated_data.
 *   - RamsDocumentComposer emits Log::warning() when it composes a
 *     record whose generated_data lacks that marker.
 *
 * Prevents a silent regression where a renderer refactor bypasses the
 * patch service and the composer produces incomplete DTOs (missing
 * personnel resolution, stale contact rows, etc.) without any signal.
 */
class PatchServiceMarkerTest extends TestCase
{
    use RefreshDatabase;

    private function makeRams(): RamsDocument
    {
        $owner = User::factory()->create();
        $project = Project::factory()->for($owner, 'owner')->create([
            'name'         => 'Marker Test Project',
            'client_name'  => 'Marker Client',
            'site_address' => '1 Marker Street',
            'ref'          => 'MARK-001',
        ]);

        return RamsDocument::create([
            'user_id'        => $owner->id,
            'project_id'     => $project->id,
            'project_ref'    => 'MARK-001',
            'project_name'   => 'Marker Test Project',
            'client_name'    => 'Marker Client',
            'site_address'   => '1 Marker Street',
            'ai_provider'    => 'claude',
            'ai_model'       => 'claude-sonnet-4-6',
            'filename'       => 'marker.docx',
            'status'         => RamsDocument::STATUS_AWAITING_REVIEW,
            'form_data'      => [],
            'reviewed_data'  => [],
            'generated_data' => ['project' => ['name' => 'Marker Test Project']],
        ]);
    }

    public function test_patch_service_writes_display_patched_at_marker(): void
    {
        $rams = $this->makeRams();
        $this->assertArrayNotHasKey('_display_patched_at', $rams->generated_data ?? [],
            'Fresh record must not have the marker before patch runs.');

        app(RamsDisplayPatchService::class)->patch($rams);

        $gd = $rams->generated_data ?? [];
        $this->assertArrayHasKey('_display_patched_at', $gd,
            'Patch service must set _display_patched_at on generated_data.');
        $this->assertNotEmpty($gd['_display_patched_at']);

        // Value shape — ISO 8601 timestamp.
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+\-]\d{2}:\d{2}$/',
            $gd['_display_patched_at']
        );
    }

    public function test_composer_emits_warning_when_marker_absent(): void
    {
        $rams = $this->makeRams();

        // Skip the patch service — marker will NOT be set.
        Log::shouldReceive('warning')
            ->once()
            ->with(
                \Mockery::pattern('/RamsDocumentComposer: composing without/'),
                \Mockery::type('array')
            );

        // Non-warning log calls the composer may pass through should be tolerated.
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();
        Log::shouldReceive('notice')->zeroOrMoreTimes();

        app(RamsDocumentComposer::class)->compose($rams);
    }

    public function test_composer_does_not_warn_when_marker_present(): void
    {
        $rams = $this->makeRams();

        // Patch first so the marker lands.
        app(RamsDisplayPatchService::class)->patch($rams);

        // No warning should fire — assert by asking Log to never receive one
        // that matches the composer's message.
        Log::shouldReceive('warning')
            ->never()
            ->with(
                \Mockery::pattern('/RamsDocumentComposer: composing without/'),
                \Mockery::any()
            );
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();
        Log::shouldReceive('notice')->zeroOrMoreTimes();

        app(RamsDocumentComposer::class)->compose($rams);
    }
}
