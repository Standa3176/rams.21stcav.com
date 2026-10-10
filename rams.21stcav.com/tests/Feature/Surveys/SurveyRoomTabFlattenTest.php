<?php

namespace Tests\Feature\Surveys;

use App\Models\Project;
use App\Models\SiteSurvey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * D-06 (46.6-03): each room's former 8-step sequential Alpine wizard
 * (`currentStep`, `nextStep()`/`prevStep()`, `<x-survey.step-container>`)
 * is FLATTENED into one scrolling tab panel per room — the eight former
 * steps become always-visible, labelled `<section>` blocks, with no
 * step-gating markup, no "Step N of 8" progress marker, and no Next/Back
 * control left reachable. This is the larger diff the user chose (SCC's
 * actual shape) over the smaller, safer alternative of nesting the wizard
 * inside the tab.
 *
 * WHICH VIEW THIS IS — same two-file trap `SurveyLinkBrandingTest` and
 * `SurveyLinkTabbedLayoutTest` guard against: `GET survey/{token}`
 * (routes/web.php) resolves to `SurveyController@show`, rendering
 * `resources/views/surveys/show.blade.php`. The dead 127KB
 * `resources/views/public-survey/show.blade.php` has no GET route and is
 * never touched here.
 *
 * FIELD INVENTORY — the non-vacuity proof that nothing captured today is
 * lost. This is the literal, de-duplicated output of running, against the
 * file BEFORE Task 2 touched a single line:
 *
 *   awk 'NR==708,NR==1852' resources/views/surveys/show.blade.php \
 *     | grep -oE 'x-model(\.number)?="[^"]+"' | sort -u
 *
 * Two entries used a Blade-templated `{{ $key }}`/`{{ $field }}` suffix
 * (mounting heights, constraints) — those are expanded below into their
 * concrete, realized variants (the actual literal keys the `@foreach`
 * iterates), because a Blade placeholder never appears literally in
 * rendered HTML. Every other entry is copied verbatim, including the
 * Alpine `x-for` loop-variable paths (`row.*`), which DO appear literally
 * in compiled output because they sit inside a `<template x-for>` tag that
 * Blade passes through untouched.
 */
class SurveyRoomTabFlattenTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 35 distinct field paths: 29 raw grep lines, −2 templated entries,
     * +4 realized mounting-height keys, +4 realized constraint keys.
     *
     * @var array<int, string>
     */
    private const FIELD_INVENTORY = [
        // Step 1 — Room Context
        'x-model="rooms[currentRoomIdx].name"',

        // Step 2 — Quick Capture
        // voice_note's x-model entry was REMOVED from this inventory by
        // 46.8 Plan 05 (D-04) — the field itself was deleted from the room
        // page (UI-only, never read downstream), not lost in a flatten.
        'x-model="rooms[currentRoomIdx]._ui.quick_notes"',

        // Step 4 — Infrastructure
        'x-model="rooms[currentRoomIdx].infrastructure.power.socket_locations"',
        'x-model.number="rooms[currentRoomIdx].infrastructure.power.distance_to_screen"',
        'x-model.number="rooms[currentRoomIdx].infrastructure.network.ports_available"',
        'x-model="rooms[currentRoomIdx].infrastructure.network.switch_location"',
        'x-model.number="rooms[currentRoomIdx].infrastructure.cable_routes.estimated_distance"',
        // Step 4 — Infrastructure: engineer_feedback (quick task 260503-u2x)
        'x-model.number="rooms[currentRoomIdx].engineer_feedback.mounting_heights.screen_h_m"',
        'x-model.number="rooms[currentRoomIdx].engineer_feedback.mounting_heights.camera_h_m"',
        'x-model.number="rooms[currentRoomIdx].engineer_feedback.mounting_heights.booking_panel_h_m"',
        'x-model.number="rooms[currentRoomIdx].engineer_feedback.mounting_heights.speaker_h_m"',
        'x-model.number="row.height_m"',          // mounting_heights.other repeater
        'x-model="row.label"',                    // mounting_heights.other repeater
        'x-model="row.category"',                 // cable_routes repeater
        'x-model="row.from"',                      // cable_routes repeater
        'x-model="row.to"',                        // cable_routes repeater
        'x-model.number="row.length_m"',          // cable_routes repeater
        'x-model="row.notes"',                     // cable_routes / brackets repeater
        'x-model="row.equipment"',                 // brackets_required repeater
        'x-model="row.model"',                     // brackets_required repeater
        'x-model="rooms[currentRoomIdx].engineer_feedback.table_info.grommet_size"',
        'x-model.number="rooms[currentRoomIdx].engineer_feedback.table_info.grommet_count"',
        'x-model="rooms[currentRoomIdx].engineer_feedback.table_info.notes"',
        'x-model="rooms[currentRoomIdx].engineer_feedback.floor_box_info.cable_space"',
        'x-model.number="rooms[currentRoomIdx].engineer_feedback.floor_box_info.power_outlets"',
        'x-model.number="rooms[currentRoomIdx].engineer_feedback.floor_box_info.data_outlets"',
        'x-model="rooms[currentRoomIdx].engineer_feedback.floor_box_info.notes"',

        // Step 5 — Equipment (additional items repeater)
        'x-model="currentRoom.additional_items[ii].qty"',
        'x-model="currentRoom.additional_items[ii].description"',
        'x-model="currentRoom.additional_items[ii].note"',

        // Step 7 — Constraints (realized from the {{ $field }} placeholder)
        'x-model="rooms[currentRoomIdx]._ui.constraints.obstructions"',
        'x-model="rooms[currentRoomIdx]._ui.constraints.noise_restrictions"',
        'x-model="rooms[currentRoomIdx]._ui.constraints.client_constraints"',
        'x-model="rooms[currentRoomIdx]._ui.constraints.programme_constraints"',

        // Step 8 — Sign-off
        'x-model="rooms[currentRoomIdx]._ui.signoff.engineer_name"',
        'x-model="rooms[currentRoomIdx]._ui.signoff.client_signature"',
    ];

    // ── Fixtures (same shape as SurveyLinkTabbedLayoutTest / SurveyLinkBrandingTest) ──

    /**
     * @param  array<int, string>  $rooms
     */
    private function survey(array $rooms = ['Board Room', 'Huddle 1'], ?callable $tweak = null): string
    {
        $user    = User::factory()->create();
        $token   = (string) Str::uuid();
        $project = Project::factory()->create([
            'name'         => 'Flatten Test Job',
            'ref'          => 'Q-4703',
            'site_address' => '14 Wharf Road, Leeds',
        ]);

        $survey = SiteSurvey::create([
            'user_id'      => $user->id,
            'project_id'   => $project->id,
            'project_name' => 'Flatten Test Job',
            'site_address' => '14 Wharf Road, Leeds',
            'status'       => 'draft',
        ]);
        $survey->forceFill(['access_token' => $token])->save();

        foreach (array_values($rooms) as $i => $name) {
            $survey->rooms()->create([
                'room_name'  => $name,
                'space_type' => 'general',
                'sort_order' => $i,
            ]);
        }

        if ($tweak !== null) {
            $tweak($survey);
        }

        return $token;
    }

    private function render(string $token): string
    {
        return $this->get(route('survey.show', ['token' => $token]))
            ->assertOk()
            ->getContent();
    }

    // ── 1. FIELD INVENTORY — NOTHING CAPTURED TODAY IS LOST ─────────────────

    public function test_every_pre_flatten_field_path_still_appears_after_the_flatten(): void
    {
        $html = $this->render($this->survey());

        $this->assertNotEmpty(self::FIELD_INVENTORY);

        foreach (self::FIELD_INVENTORY as $path) {
            $this->assertStringContainsString(
                $path,
                $html,
                "Field path lost in the flatten: {$path}"
            );
        }
    }

    // ── 2. ALL EIGHT FORMER STEPS RENDER TOGETHER, NO STEP-GATING REMAINS ───

    /**
     * This is the direct proof the flatten actually happened: all 8
     * labelled sections present SIMULTANEOUSLY in one GET response (no
     * client-side step advance required to see them), and the step-wizard's
     * own navigation vocabulary — "Step N of 8", "Next →", "← Back", "Back
     * to site tab" — is gone.
     */
    public function test_room_tab_shows_all_eight_sections_with_no_step_gating(): void
    {
        $html = $this->render($this->survey());

        foreach ([1, 2, 3, 4, 5, 6, 7, 8] as $n) {
            $this->assertStringContainsString(
                "id=\"sv-step-{$n}\"",
                $html,
                "Section {$n} is missing — not all 8 former steps render together."
            );
        }

        $this->assertStringContainsString('Room Context', $html);
        $this->assertStringContainsString('Quick Capture', $html);
        $this->assertStringContainsString('Photos', $html);
        $this->assertStringContainsString('Infrastructure', $html);
        $this->assertStringContainsString('Equipment', $html);
        $this->assertStringContainsString('Access &amp; H&amp;S', $html);
        $this->assertStringContainsString('Constraints', $html);
        $this->assertStringContainsString('Sign-off', $html);

        $this->assertStringNotContainsString('Step 1 of 8', $html, 'A "Step N of 8" progress marker survived the flatten.');
        $this->assertStringNotContainsString('of 8 —', $html, 'The step-progress header strip survived the flatten.');
        $this->assertStringNotContainsString('Next →', $html, 'A Next button survived the flatten.');
        $this->assertStringNotContainsString('← Back', $html, 'A Back button survived the flatten.');
        $this->assertStringNotContainsString('Back to site tab', $html, 'The read-only step-nav "Back to site tab" button survived the flatten.');

        // The flattened section wrapper itself, not the old gated one.
        $this->assertStringNotContainsString('x-survey.step-container', $html);
    }

    /**
     * The sv-tab-bar scaffold (46.6-01) must still work — the flatten only
     * touches what is INSIDE a room tab, not the tab mechanics around it.
     */
    public function test_the_tab_bar_and_site_tab_are_unaffected_by_the_flatten(): void
    {
        $html = $this->render($this->survey());

        $this->assertStringContainsString('data-sv-tab-bar', $html);
        $this->assertStringContainsString('data-tab="site"', $html);
        $this->assertStringContainsString('data-tab="room-0"', $html);
        $this->assertStringContainsString('data-tab="room-1"', $html);
    }

    // ── 3. WORK_TYPE UNSET STILL RENDERS SENSIBLY, NO 500 ───────────────────

    /**
     * A freshly-created room has `_ui.work_type === ''` (SurveyController's
     * emptyCanonicalRoom() default). The work_type-conditional
     * `x-show="rooms[currentRoomIdx]?._ui?.work_type === 'new_install'"`
     * block in the Infrastructure section is content-relevance gating
     * (D-06 keeps it exactly as-is) — it must still render without a 500
     * when work_type is unset.
     */
    public function test_a_room_with_work_type_unset_renders_without_a_500(): void
    {
        $html = $this->render($this->survey());

        $this->assertStringContainsString('id="sv-step-4"', $html);
        $this->assertStringContainsString(
            "x-show=\"rooms[currentRoomIdx]?._ui?.work_type === 'new_install'\"",
            $html,
            'The work_type content-relevance gate must survive the flatten unchanged.'
        );
    }

    // ── 4. THE ROOMLESS AND LOCKED STATES STILL RENDER WITHOUT A 500 ────────

    public function test_roomless_state_still_renders_without_a_500(): void
    {
        $html = $this->render($this->survey([]));

        $this->assertStringContainsString('data-tab="site"', $html);
        $this->assertStringNotContainsString('data-tab="room-0"', $html);
    }

    public function test_locked_state_still_renders_its_eight_sections_read_only(): void
    {
        $token = $this->survey(['Board Room'], function (SiteSurvey $s): void {
            $s->rooms()->update(['is_completed' => true, 'completed_at' => now()]);
            $s->forceFill(['submitted_at' => now(), 'status' => 'submitted'])->save();
        });

        $html = $this->render($token);

        foreach ([1, 2, 3, 4, 5, 6, 7, 8] as $n) {
            $this->assertStringContainsString("id=\"sv-step-{$n}\"", $html);
        }
        $this->assertStringNotContainsString('Next →', $html);
        $this->assertStringNotContainsString('← Back', $html);
    }
}
