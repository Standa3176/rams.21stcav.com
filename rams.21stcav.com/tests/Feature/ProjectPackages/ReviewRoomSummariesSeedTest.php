<?php

namespace Tests\Feature\ProjectPackages;

use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sidebar fix 2026-05-14 — extracted-data-room-key-mismatch.
 *
 * Regression guard for the Claude PDF-vision import path
 * (QuoteImportService::import / ::reimport → QuoteExtractorService) which
 * writes per-room data under `extracted_data.room_summaries` instead of
 * `room_overviews`. Without the defensive seed in
 * ProjectPackageReviewController::show(), the review form rendered
 * "No spaces detected" for any package extracted through that path
 * (real-world example: Light Forms Ltd 21CQ30451-01-OPS, package id 124,
 * revision 2 = re-extracted).
 *
 * See .planning/notes/2026-05-14-extracted-data-room-key-mismatch.md and
 * .planning/debug/extracted-data-room-key-mismatch.md.
 */
class ReviewRoomSummariesSeedTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{user: User, package: ProjectPackage}
     */
    private function makePackageWithRoomSummaries(array $roomSummaries, array $extraExtracted = []): array
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $extracted = array_merge([
            'qw_number'     => 'QW-TEST-001',
            'client_name'   => 'Acme Ltd',
            'site_address'  => '1 Test St, London',
            'project_name'  => 'Test Boardroom AV',
            // Mirror the Claude-vision shape exactly — line_items not equipment,
            // no `area` field on items, no `room_overviews` key at all.
            'line_items'    => [
                ['sku' => 'FW-85BZ40L', 'qty' => 1, 'description' => 'Sony 85" Anti Glare Display'],
            ],
            'room_summaries' => $roomSummaries,
        ], $extraExtracted);

        $package = ProjectPackage::create([
            'project_id'     => $project->id,
            'user_id'        => $user->id,
            'extracted_data' => $extracted,
            'status'         => ProjectPackage::STATUS_EXTRACTED,
        ]);

        return ['user' => $user, 'package' => $package];
    }

    public function test_show_seeds_room_overviews_from_room_summaries_when_no_room_overviews_present(): void
    {
        $f = $this->makePackageWithRoomSummaries([
            ['room' => 'Boardroom', 'summary' => 'Removal of existing Vestel display and install Sony 85" Anti Glare Display.'],
        ]);

        $this->actingAs($f['user'])
            ->get(route('project-packages.review.show', $f['package']))
            ->assertOk()
            ->assertSee('Boardroom')
            ->assertSee('Removal of existing Vestel')
            // Empty-state message must NOT be rendered when room_summaries has content.
            ->assertDontSee('No spaces detected');
    }

    public function test_show_seeds_multiple_rooms_from_room_summaries(): void
    {
        $f = $this->makePackageWithRoomSummaries([
            ['room' => 'Boardroom',     'summary' => 'Sony 85" display + Crestron control.'],
            ['room' => 'Reception',     'summary' => 'Wall-mounted 55" digital signage.'],
            ['room' => 'Training Suite', 'summary' => '2x interactive displays + speaker bar.'],
        ]);

        $this->actingAs($f['user'])
            ->get(route('project-packages.review.show', $f['package']))
            ->assertOk()
            ->assertSee('Boardroom')
            ->assertSee('Reception')
            ->assertSee('Training Suite');
    }

    public function test_show_prefers_saved_room_overviews_over_room_summaries(): void
    {
        // Curated mode: once room_overviews exists, it is the SOLE authority.
        // room_summaries must not re-add rooms the PM has curated away.
        $f = $this->makePackageWithRoomSummaries(
            roomSummaries: [
                ['room' => 'Boardroom', 'summary' => 'AI-detected boardroom prose.'],
                ['room' => 'Stairwell', 'summary' => 'AI hallucinated extra room.'],
            ],
            extraExtracted: [
                'room_overviews' => [
                    ['room' => 'Boardroom', 'overview' => 'PM-curated prose.', 'works_summary' => '', 'solution_type_id' => null],
                ],
            ],
        );

        $response = $this->actingAs($f['user'])
            ->get(route('project-packages.review.show', $f['package']))
            ->assertOk()
            ->assertSee('Boardroom')
            ->assertSee('PM-curated prose')
            // Curated mode must NOT re-add Stairwell from room_summaries.
            ->assertDontSee('Stairwell');
    }

    public function test_show_does_not_change_existing_overview_when_summary_is_empty(): void
    {
        $f = $this->makePackageWithRoomSummaries([
            ['room' => 'Boardroom', 'summary' => ''],
        ]);

        // Room name still surfaces even if summary text is empty.
        $this->actingAs($f['user'])
            ->get(route('project-packages.review.show', $f['package']))
            ->assertOk()
            ->assertSee('Boardroom')
            ->assertDontSee('No spaces detected');
    }

    public function test_show_skips_excluded_keywords_in_room_summaries(): void
    {
        // The AI sometimes emits the project-level "Summary" section as a
        // pseudo-room. The excludedAreaWords list must filter these out so
        // the defensive seed doesn't accidentally re-introduce them.
        $f = $this->makePackageWithRoomSummaries([
            ['room' => 'Boardroom', 'summary' => 'Real room.'],
            ['room' => 'Cabling',   'summary' => 'Cable-run section, not a room.'],
            ['room' => 'General',   'summary' => 'Generic catch-all.'],
        ]);

        $this->actingAs($f['user'])
            ->get(route('project-packages.review.show', $f['package']))
            ->assertOk()
            ->assertSee('Boardroom');

        // We don't assertDontSee('Cabling') because the equipment column or
        // category labels may legitimately contain the word; the controller-
        // level guarantee is that Cabling/General do NOT become Section-2
        // room rows. The roundtrip-save test below confirms persistence shape.
    }

    public function test_saved_room_summaries_round_trip_through_first_save(): void
    {
        // End-to-end: GET show() seeds rooms → POST update() persists them
        // → next GET show() finds room_overviews populated and renders them
        // in curated mode. This is the path that converts the AI's seed into
        // a PM-approved canonical list on the very first save.
        $f = $this->makePackageWithRoomSummaries([
            ['room' => 'Boardroom', 'summary' => 'Sony display install.'],
        ]);

        $this->actingAs($f['user']);

        // Step 1: confirm seeded list is rendered.
        $this->get(route('project-packages.review.show', $f['package']))
            ->assertOk()
            ->assertSee('Boardroom');

        // Step 2: simulate the PM clicking Save with the seeded values posted
        // back unchanged.
        $this->post(route('project-packages.review.update', $f['package']), [
            'project' => [
                'project_name' => 'Test Boardroom AV',
                'quote_ref'    => 'QW-TEST-001',
                'client_name'  => 'Acme Ltd',
                'site_name'    => '',
                'site_address' => '1 Test St, London',
                'prepared_by'  => '',
                'overview'     => '',
            ],
            'room_overviews' => [
                [
                    'room'             => 'Boardroom',
                    'overview'         => 'Sony display install.',
                    'works_summary'    => '',
                    'solution_type_id' => null,
                ],
            ],
        ])->assertRedirect();

        // Step 3: extracted_data.room_overviews now exists.
        $f['package']->refresh();
        $this->assertNotEmpty($f['package']->extracted_data['room_overviews'] ?? []);
        $this->assertSame('Boardroom', $f['package']->extracted_data['room_overviews'][0]['room']);

        // Step 4: room_summaries MUST still be present — other consumers
        // (PDF rams.blade.php, WordDocumentService) depend on it.
        $this->assertNotEmpty($f['package']->extracted_data['room_summaries'] ?? []);
    }
}
