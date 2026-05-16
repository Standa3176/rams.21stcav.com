<?php

namespace Tests\Feature\Worksheets;

use App\Models\Project;
use App\Models\User;
use App\Models\Worksheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression guard for F-WS-02 (audit 2026-05-17).
 *
 * Before this fix: resources/views/worksheets/show.blade.php did NOT
 * render works_summary_bullets or room_works_description for any room.
 * The DOCX renderer (app/Services/WorksheetDocxService.php:278-279) DID
 * render both — under an "ENGINEER WORK SUMMARY" heading, bullets-first
 * with a prose fallback to room_works_description.
 *
 * Net effect: a user viewing the worksheet on the web saw a markedly
 * less informative document than the DOCX they could download. The two
 * canonical channels diverged on the same generated_data payload.
 *
 * After fix: the show blade renders an "Engineer Work Summary" sub-
 * section between Equipment and Install Steps, mirroring the DOCX
 * ordering (DOCX does work_summary → install_steps too). Bullets
 * render as a <ul>; falls back to a <p> with room_works_description
 * when no bullets are populated. Whole block is skipped when neither
 * source carries data — matches DOCX behaviour at line 280-293.
 *
 * @see resources/views/worksheets/show.blade.php
 * @see app/Services/WorksheetDocxService.php (lines 274-294)
 * @see .planning/audits/worksheet-om-parity-audit-2026-05-17.md (F-WS-02)
 */
class WorksheetShowViewParityTest extends TestCase
{
    use RefreshDatabase;

    // ── Fixture helpers ──────────────────────────────────────────────────────

    private function makeWorksheet(array $rooms): Worksheet
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        return Worksheet::factory()->create([
            'user_id'        => $user->id,
            'project_id'     => $project->id,
            'status'         => Worksheet::STATUS_DRAFT,
            'generated_data' => ['rooms' => $rooms],
        ]);
    }

    private function showAs(Worksheet $worksheet): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($worksheet->user)
            ->get(route('worksheets.show', $worksheet));
    }

    // ── Tests ────────────────────────────────────────────────────────────────

    public function test_show_view_renders_works_summary_bullets_when_present(): void
    {
        // PRIMARY case — works_summary_bullets is a non-empty array of
        // install-action strings. Each bullet must appear in the rendered
        // HTML under an "Engineer Work Summary" heading, matching the
        // DOCX behaviour at WorksheetDocxService.php:280-286.
        $worksheet = $this->makeWorksheet([
            [
                'name'                  => 'Boardroom',
                'is_surveyed'           => true,
                'works_summary_bullets' => [
                    'Mount Sony 85-inch display centred at 1200mm AFFL',
                    'Install Yealink A30 bar beneath display',
                    'Wire HDMI 4K cable from table plate to display',
                ],
                'room_works_description' => '',
                'equipment'              => [],
                'install_steps'          => '',
            ],
        ]);

        $response = $this->showAs($worksheet);

        $response->assertOk();
        $response->assertSee('Engineer Work Summary', false);
        $response->assertSee('Mount Sony 85-inch display centred at 1200mm AFFL', false);
        $response->assertSee('Install Yealink A30 bar beneath display', false);
        $response->assertSee('Wire HDMI 4K cable from table plate to display', false);
    }

    public function test_show_view_falls_back_to_room_works_description_when_no_bullets(): void
    {
        // FALLBACK case — works_summary_bullets empty, but room_works_description
        // carries the prose paragraph (the path WorksheetGeneratorService takes
        // when no per-room install-action bullets are available, line 558-560).
        $worksheet = $this->makeWorksheet([
            [
                'name'                   => 'Reception',
                'is_surveyed'            => true,
                'works_summary_bullets'  => [],
                'room_works_description' => 'Install two Samsung QM55B displays driven from a single BrightSign player for lobby digital signage.',
                'equipment'              => [],
                'install_steps'          => '',
            ],
        ]);

        $response = $this->showAs($worksheet);

        $response->assertOk();
        $response->assertSee('Engineer Work Summary', false);
        $response->assertSee('Install two Samsung QM55B displays driven from a single BrightSign player', false);
    }

    public function test_show_view_prefers_bullets_over_description_when_both_present(): void
    {
        // Mirrors the DOCX preference chain at WorksheetDocxService.php:280-289
        // — bullets win, description does NOT also leak through. This matches
        // generator logic at line 556-560 that blanks room_works_description
        // when bullets are populated, but we defend the view against any
        // stale generated_data where both got persisted.
        $worksheet = $this->makeWorksheet([
            [
                'name'                   => 'Training Room',
                'is_surveyed'            => true,
                'works_summary_bullets'  => ['Bullet-form action item'],
                'room_works_description' => 'PROSE paragraph that should NOT appear when bullets exist.',
                'equipment'              => [],
                'install_steps'          => '',
            ],
        ]);

        $response = $this->showAs($worksheet);

        $response->assertOk();
        $response->assertSee('Bullet-form action item', false);
        $response->assertDontSee('PROSE paragraph that should NOT appear when bullets exist.', false);
    }

    public function test_show_view_skips_section_entirely_when_no_works_data(): void
    {
        // Negative case — neither source has data. Section heading must NOT
        // render, matching DOCX behaviour where buildRoom only renders the
        // "ENGINEER WORK SUMMARY" heading when at least one of the two
        // sources is non-empty.
        $worksheet = $this->makeWorksheet([
            [
                'name'                   => 'Empty Room',
                'is_surveyed'            => false,
                'works_summary_bullets'  => [],
                'room_works_description' => '',
                'equipment'              => [],
                'install_steps'          => '',
            ],
        ]);

        $response = $this->showAs($worksheet);

        $response->assertOk();
        $response->assertDontSee('Engineer Work Summary', false);
    }

    public function test_show_view_filters_whitespace_only_bullets(): void
    {
        // Defensive case — whitespace-only bullet strings (e.g. trailing
        // newlines that snuck through generator normalisation) must be
        // dropped rather than rendering as empty <li> rows. The blade
        // applies trim+filter on the bullet list before rendering.
        $worksheet = $this->makeWorksheet([
            [
                'name'                   => 'Huddle Pod',
                'is_surveyed'            => true,
                'works_summary_bullets'  => ['Real bullet', '', '   ', 'Another real bullet'],
                'room_works_description' => '',
                'equipment'              => [],
                'install_steps'          => '',
            ],
        ]);

        $response = $this->showAs($worksheet);

        $response->assertOk();
        $response->assertSee('Real bullet', false);
        $response->assertSee('Another real bullet', false);
        // The empty/whitespace entries must not produce empty <li> rows.
        $this->assertEquals(
            2,
            substr_count($response->getContent(), '<li style="margin-bottom:.25rem;">'),
            'F-WS-02: whitespace-only bullets were not filtered out of the rendered list.',
        );
    }
}
