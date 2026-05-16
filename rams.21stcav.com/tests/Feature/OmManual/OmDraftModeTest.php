<?php

namespace Tests\Feature\OmManual;

use App\Jobs\BuildOmManualJob;
use App\Models\OmManual;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\User;
use App\Services\OmManualValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Tests for draft-mode O&M generation (`?draft=1`).
 *
 * Background: production projects in `quote_imported` / `survey_pending`
 * status don't yet have a scheduled handover_date or attached drawings.
 * The strict Tier-1 NO-TBC validator rejected those manuals entirely,
 * blocking early-stage preview generation. Tilda 21CQ29531-05-OPS hit
 * this on 2026-05-16:
 *
 *   "O&M Manual cannot be generated — required fields missing:
 *    handover date; narrative for ROOM BOOKING PANELS;
 *    at least one drawing (Appendix A)"
 *
 * The narrative gate was fixed by `cbe6397` (F-OM-01). Draft mode
 * (this feature) covers the other two by seeding [TBC] placeholders
 * into the context BEFORE validation runs. Final-issue mode (no flag)
 * continues to enforce the strict policy.
 *
 * @see OmManualController::generateFromProject
 * @see OmManualGeneratorService::generateContent (draft seeding block)
 */
class OmDraftModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_query_param_persists_into_extracted_data(): void
    {
        Bus::fake([BuildOmManualJob::class]);

        $f = $this->seedReadyProject();

        $this->actingAs($f['user']);
        $response = $this->post(
            route('om-manuals.generate-from-project', $f['project']) . '?draft=1'
        );

        $response->assertRedirect();
        $manual = OmManual::latest('id')->first();
        $this->assertNotNull($manual, 'OmManual should be created');
        $this->assertTrue(
            (bool) ($manual->extracted_data['_draft_mode'] ?? false),
            '_draft_mode MUST be persisted in extracted_data so it survives Retry re-dispatches'
        );

        Bus::assertDispatched(BuildOmManualJob::class);
    }

    public function test_default_call_does_not_set_draft_mode(): void
    {
        Bus::fake([BuildOmManualJob::class]);

        $f = $this->seedReadyProject();

        $this->actingAs($f['user']);
        $this->post(route('om-manuals.generate-from-project', $f['project']));

        $manual = OmManual::latest('id')->first();
        $this->assertFalse(
            (bool) ($manual->extracted_data['_draft_mode'] ?? false),
            'Default generate path MUST keep _draft_mode false so the strict NO-TBC policy applies'
        );
    }

    public function test_validator_accepts_draft_seeded_placeholders(): void
    {
        // Lock the contract: a context with [TBC] placeholders for handover_date
        // and a placeholder-drawing entry MUST pass the validator. If a future
        // change to OmManualValidationService tightens the "blank" check (e.g.
        // rejects strings starting with [TBC]), draft mode breaks silently and
        // this test catches it at CI time.
        $validator = new OmManualValidationService();

        $context = [
            'project_name'  => 'Test Project',
            'client_name'   => 'Test Client',
            'site_address'  => '1 Test Way',
            'document_date' => '17 May 2026',
            'handover_date' => '[TBC] — handover date to be scheduled',
            'rooms'         => [[
                'name'      => 'Boardroom',
                'equipment' => [['name' => 'Display']],
                'narrative' => 'Sony 85" display + Yealink bar.',
            ]],
            'drawings'      => [[
                'name' => '[TBC] — engineering drawings to follow',
                'type' => 'placeholder',
            ]],
        ];

        // Should not throw.
        $validator->validateOmData($context);
        $this->assertTrue(true, 'validator accepts draft-seeded context without throwing');
    }

    public function test_validator_still_rejects_blank_handover_and_zero_drawings(): void
    {
        // Negative control: prove the strict rules are still enforced when
        // draft mode is NOT engaged. If this test ever starts passing without
        // a corresponding policy change, the validator has been over-relaxed.
        $validator = new OmManualValidationService();

        $context = [
            'project_name'  => 'Test Project',
            'client_name'   => 'Test Client',
            'site_address'  => '1 Test Way',
            'document_date' => '17 May 2026',
            'handover_date' => '',     // ← blank — strict mode rejects
            'rooms'         => [[
                'name'      => 'Boardroom',
                'equipment' => [['name' => 'Display']],
                'narrative' => 'Sony 85" display.',
            ]],
            'drawings'      => [],     // ← zero — strict mode rejects
        ];

        $this->expectException(\App\Exceptions\OmManualValidationException::class);
        $validator->validateOmData($context);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function seedReadyProject(): array
    {
        $user = User::factory()->create();

        // A "ready" project: no handover_date, no drawings — but the linked
        // package IS marked reviewed (so the controller's package-status
        // gate doesn't bounce the request before our draft-flag handling).
        $project = Project::create([
            'user_id'      => $user->id,
            'name'         => 'Draft-Mode Project',
            'client_name'  => 'Acme',
            'site_address' => '1 Acme Way',
            'status'       => Project::STATUS_QUOTE_IMPORTED,
        ]);

        ProjectPackage::create([
            'project_id'        => $project->id,
            'user_id'           => $user->id,
            'quote_filename'    => 'test.pdf',
            'quote_path'        => 'quote-imports/test.pdf',
            'extracted_data'    => [
                'rooms' => ['Boardroom'],
                'room_overviews' => [[
                    'room'             => 'Boardroom',
                    'overview'         => 'Sony 85" display + Yealink A30 bar.',
                    'works_summary'    => '',
                    'solution_type_id' => null,
                ]],
            ],
            'equipment_list'    => [],
            'cable_list'        => [],
            'works_description' => null,
            'revision'          => 1,
            'status'            => ProjectPackage::STATUS_REVIEWED,
        ]);

        return ['user' => $user, 'project' => $project];
    }
}
