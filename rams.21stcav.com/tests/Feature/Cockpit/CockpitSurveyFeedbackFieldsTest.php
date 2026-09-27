<?php

namespace Tests\Feature\Cockpit;

use App\Core\Modules\Survey\SurveyService;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\SiteSurvey;
use App\Models\User;
use App\Support\Cockpit\CockpitDocumentFormPresenter;
use App\Support\Visits\SurveyCarryForward;
use App\Support\Cockpit\CockpitWizardPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * THE COMMS-ROOM RULING, APPLIED A SECOND TIME — AND PROVEN ON BOTH SIDES.
 *
 * On 2026-09-27 the user, using the live wizard, asked for `Delivery routes` and
 * `Distance from base` (plus its travel notes) to go, saying: "Remove Delivery
 * routes as this is a survey not install with kit delievery".
 *
 * THAT REASON IS EXACTLY WHY THE CAPTURE STAYS. Delivery routes and distance
 * from base are what the SURVEYOR finds out on site so the INSTALLING engineer
 * has them later. `SurveyCarryForward` is the mechanism that hands them over,
 * and the survey PDF, the Word document and the engineer link all render them.
 * An office PM at creation time does not know any of it.
 *
 * So the change is ONE INTEGER PER GROUP on the map — `step => null`, the
 * established mechanism, the same one Comms room uses — and NOT a deletion. The
 * five files that read these columns are untouched by design:
 *
 *   app/Services/SiteSurveyDocxService.php
 *   resources/views/pdf/site-survey/*
 *   app/Http/Controllers/PublicSurveyController.php
 *   app/Http/Controllers/SurveyController.php
 *   app/Support/Visits/SurveyCarryForward.php
 *
 * ── WHY THIS FILE EXISTS RATHER THAN A LINE IN AN EXISTING TEST ─────────────
 *
 * Because a removal is only safe if BOTH halves are asserted TOGETHER. A test
 * that only proves the office form stopped asking would pass just as happily
 * after someone "finished the job" by deleting the columns — which is the
 * safety regression. Every test below therefore pairs an absence with a
 * presence, and each presence is RENDERED or RESOLVED, never inferred from a
 * constant alone.
 *
 * NEVER run `artisan migrate:fresh --env=testing` (see gate-46.ps1).
 */
class CockpitSurveyFeedbackFieldsTest extends TestCase
{
    use RefreshDatabase;

    /** The three the office form stopped asking, and the group they moved to. */
    private const MOVED_KEYS = [
        'delivery_routes',
        'distance_from_base_miles',
        'distance_from_base_notes',
    ];

    private const MOVED_LEGEND = 'For the install that follows';

    private const SITE_SURVEY = 'site_survey';

    private function project(): Project
    {
        $user    = User::factory()->create();
        $project = Project::create([
            'user_id'      => $user->id,
            'name'         => 'Feedback Fields Fixture',
            'client_name'  => 'Fixture Client',
            'site_address' => '1 Example Way, London',
        ]);

        ProjectPackage::create([
            'user_id'        => $user->id,
            'project_id'     => $project->id,
            'status'         => ProjectPackage::STATUS_REVIEWED,
            'extracted_data' => [
                'room_overviews' => [
                    ['room' => 'Board Room', 'overview' => 'VC + display', 'summary' => ''],
                ],
                'equipment' => [],
            ],
            'equipment_list' => [],
            'revision'       => 1,
        ]);

        return $project->fresh();
    }

    // ── 1. THE MAP: on no step, with the reason beside it in code ───────────

    /**
     * `step => null` IS THE MECHANISM, and the group is still on the map with
     * all three fields. Both halves in one assertion set: off the wizard, on
     * the map.
     */
    public function test_the_three_moved_fields_are_on_no_step_but_still_on_the_map(): void
    {
        $groups = CockpitDocumentFormPresenter::documentFieldMap()[self::SITE_SURVEY]['groups'];

        $found = null;

        foreach ($groups as $group) {
            if ($group['legend'] === self::MOVED_LEGEND) {
                $found = $group;
            }
        }

        $this->assertNotNull(
            $found,
            'The `'.self::MOVED_LEGEND.'` group left the MAP. The office form stops ASKING; the map keeps '
            .'the rows, because the columns are still read by five files.',
        );

        $this->assertNull(
            $found['step'],
            'The group must be `step => null` — that is the established mechanism (the comms-room ruling), '
            .'not a deletion.',
        );

        $this->assertSame(
            self::MOVED_KEYS,
            array_column($found['fields'], 'key'),
            'The group must hold exactly the three fields the user asked to remove from the office form.',
        );

        // THE REASON IS WRITTEN BESIDE THE `null` IN CODE, as the comms-room
        // group already does. A `null` with no reason is the thing a later
        // author "tidies up".
        $source = (string) file_get_contents(
            base_path('app/Support/Cockpit/CockpitDocumentFormPresenter.php'),
        );

        foreach (['SurveyCarryForward', 'SAFETY REGRESSION', 'INSTALLING'] as $needle) {
            $this->assertStringContainsString(
                $needle,
                $source,
                "The map does not explain, beside the `step => null`, why the capture stays. Missing: {$needle}",
            );
        }
    }

    /** No step number — real or hand-typed — is a way back to the group. */
    public function test_no_step_number_returns_the_moved_group(): void
    {
        $presenter = new CockpitWizardPresenter();
        $probed    = 0;

        foreach (range(0, 9) as $step) {
            foreach ($presenter->groupsForStep(self::SITE_SURVEY, $step) as $group) {
                $this->assertNotSame(
                    self::MOVED_LEGEND,
                    $group['legend'],
                    "The moved group was returned by step {$step}.",
                );

                foreach ($group['fields'] as $field) {
                    $this->assertNotContains(
                        $field['key'],
                        self::MOVED_KEYS,
                        "[{$field['key']}] is still asked by step {$step} of the office form.",
                    );
                }
            }

            $probed++;
        }

        $this->assertSame(10, $probed, 'This proof probed '.$probed.' step numbers, 0 through 9.');
    }

    // ── 2. THE SURVEYOR IS STILL ASKED — RENDERED, NOT INFERRED ────────────

    /**
     * THE HALF THAT MAKES THE REMOVAL SAFE. The live surveyor page is FETCHED
     * and its markup is searched for all three controls. If a later change
     * "finishes the job" by taking them out of the field capture, this is red.
     */
    public function test_the_surveyor_is_still_asked_for_all_three_on_the_public_survey_page(): void
    {
        $project = $this->project();
        $user    = User::find($project->user_id);
        $survey  = app(SurveyService::class)->createFromProject($project, $user);

        $html = $this->get(route('survey.show', ['token' => $survey->access_token]))
            ->assertOk()
            ->getContent();

        foreach (self::MOVED_KEYS as $key) {
            $this->assertStringContainsString(
                'engineerFeedbackSite.'.$key,
                $html,
                "The SURVEYOR is no longer asked for [{$key}] on /survey/{token}. The office form stopped "
                .'asking; the field capture must NOT have. This is the safety regression the `step => null` '
                .'ruling exists to avoid.',
            );
        }

        // NON-VACUITY: the same page must NOT be the office form. If this
        // assertion ever fails, the fetch above found the wrong page and the
        // three assertions above proved nothing.
        $this->assertStringNotContainsString('cav-qa__form', $html, 'That is the cockpit form, not the surveyor page.');
    }

    /** The surveyor's POST still accepts all three, so the page is not decorative. */
    public function test_the_public_save_still_accepts_all_three_from_the_surveyor(): void
    {
        $project = $this->project();
        $user    = User::find($project->user_id);
        $survey  = app(SurveyService::class)->createFromProject($project, $user);

        $this->post(route('survey.save', ['token' => $survey->access_token]), [
            'surveyor_name'            => 'Dev Chandra',
            'delivery_routes'          => 'SENTINEL-ROUTES-FROM-SITE',
            'distance_from_base_miles' => 42,
            'distance_from_base_notes' => 'SENTINEL-TRAVEL-FROM-SITE',
            'rooms'                    => [],
        ])->assertSessionHasNoErrors();

        $fresh = $survey->fresh();

        $this->assertSame('SENTINEL-ROUTES-FROM-SITE', $fresh->delivery_routes);
        $this->assertSame('42', (string) $fresh->distance_from_base_miles);
        $this->assertSame('SENTINEL-TRAVEL-FROM-SITE', $fresh->distance_from_base_notes);
    }

    // ── 3. THE CARRY-FORWARD STILL CARRIES ─────────────────────────────────

    /**
     * THE OTHER HALF: what an INSTALLING engineer reads. Resolved through
     * `SurveyCarryForward::forProject()` — the real call the engineer link
     * makes — not read off the const.
     */
    public function test_the_carry_forward_still_carries_delivery_routes_and_distance_from_base(): void
    {
        $project = $this->project();

        SiteSurvey::create([
            'user_id'                  => $project->user_id,
            'project_id'               => $project->id,
            'project_name'             => 'Feedback Fields Fixture',
            'client_name'              => 'Fixture Client',
            'status'                   => 'completed',
            'delivery_routes'          => 'SENTINEL-ROUTES-TO-INSTALL',
            'distance_from_base_miles' => '42',
            'distance_from_base_notes' => 'SENTINEL-TRAVEL-TO-INSTALL',
        ]);

        $rows   = SurveyCarryForward::forProject($project->fresh());
        $values = array_column($rows, 'value', 'key');

        $this->assertArrayHasKey(
            'delivery_routes',
            $values,
            'The carry-forward dropped `delivery_routes`. The installing engineer reads it — see the map.',
        );
        $this->assertSame('SENTINEL-ROUTES-TO-INSTALL', $values['delivery_routes']);

        $this->assertArrayHasKey(
            'distance_from_base_miles',
            $values,
            'The carry-forward dropped `distance_from_base_miles`.',
        );

        // The distance row folds the notes in — both halves reach the engineer.
        $this->assertStringContainsString('42 miles', $values['distance_from_base_miles']);
        $this->assertStringContainsString('SENTINEL-TRAVEL-TO-INSTALL', $values['distance_from_base_miles']);
    }

    // ── 4. THE FIVE FILES NAMED AS UNTOUCHED STILL READ THE COLUMNS ────────

    /**
     * ASSERTED AS SOURCE, because "untouched" is a claim about a diff and a diff
     * is not something a later reader can see. Each file must still contain a
     * read of the columns; deleting the read is the regression.
     */
    public function test_the_five_downstream_readers_still_read_these_columns(): void
    {
        $expectations = [
            'app/Services/SiteSurveyDocxService.php'                => ['delivery_routes'],
            'resources/views/pdf/site-survey/_header-meta.blade.php' => [
                '$survey?->delivery_routes',
                '$survey?->distance_from_base_miles',
                '$survey?->distance_from_base_notes',
            ],
            'app/Http/Controllers/PublicSurveyController.php'       => ['delivery_routes', 'distance_from_base_miles'],
            'app/Http/Controllers/SurveyController.php'             => ['delivery_routes', 'distance_from_base_miles'],
            'app/Support/Visits/SurveyCarryForward.php'             => ['delivery_routes', 'distance_from_base_miles'],
        ];

        $checked = 0;

        foreach ($expectations as $path => $needles) {
            $this->assertFileExists(base_path($path));

            $source = (string) file_get_contents(base_path($path));

            foreach ($needles as $needle) {
                $this->assertStringContainsString(
                    $needle,
                    $source,
                    "{$path} no longer reads `{$needle}`. The office form stopped ASKING; nothing downstream "
                    .'was allowed to stop READING.',
                );

                $checked++;
            }
        }

        $this->assertSame(10, $checked, 'This proof checked '.$checked.' reads across 5 files.');
    }
}
