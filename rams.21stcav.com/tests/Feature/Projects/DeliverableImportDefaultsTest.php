<?php

namespace Tests\Feature\Projects;

use App\Models\Project;
use App\Models\ProjectDeliverable;
use App\Models\ProjectPackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 260822-08 (D-15): proves QuoteImportController::deliverablesStep()'s
 * server-side default computation in isolation — without exercising the
 * confirm() write path (that end-to-end proof lives in
 * QuoteImportDeliverablesStepTest).
 *
 * D-15's signal (EquipmentCategoryClassifier's single collapsed `services`
 * category, confirmed live at app/Services/Imports/EquipmentCategoryClassifier.php)
 * supports exactly one distinction: "no services line" vs "has a services
 * line". It does NOT support a positive "definitely required" default for
 * anything — so the with-services case defaults to Not yet decided, never
 * Required.
 */
class DeliverableImportDefaultsTest extends TestCase
{
    use RefreshDatabase;

    private function makePackage(User $user, array $equipmentList): ProjectPackage
    {
        $project = Project::create([
            'user_id'      => $user->id,
            'name'         => 'Defaults Test Project',
            'client_name'  => 'Client Defaults',
            'site_address' => '1 Defaults Street',
            'status'       => Project::STATUS_QUOTE_IMPORTED,
        ]);

        return ProjectPackage::create([
            'project_id'     => $project->id,
            'user_id'        => $user->id,
            'quote_filename' => 'defaults-test.pdf',
            'quote_path'     => 'quote-imports/defaults-test.pdf',
            'extracted_data' => [],
            'equipment_list' => $equipmentList,
            'cable_list'     => [],
            'revision'       => 1,
            'status'         => ProjectPackage::STATUS_EXTRACTED,
        ]);
    }

    private function postDeliverablesStep(User $user, ProjectPackage $package)
    {
        return $this->actingAs($user)->post(route('quote-import.deliverables-step', $package), [
            'name'         => 'Defaults Test Project',
            'client_name'  => 'Client Defaults',
            'site_address' => '1 Defaults Street',
            'project_id'   => $package->project_id,
        ]);
    }

    // ── No services line: RAMS/Worksheet/Survey default to Not required ────

    public function test_no_services_line_defaults_survey_rams_worksheet_to_not_required(): void
    {
        $user = User::factory()->create();

        // Pure hardware — no install/labour/commissioning/RAMS/survey SKU token
        // anywhere, so EquipmentCategoryClassifier never buckets a row as 'services'.
        $package = $this->makePackage($user, [
            ['name' => 'Samsung 55" QM55C Display', 'part_number' => 'LH55QMCEBGCXXU', 'category' => 'hardware'],
            ['name' => 'HDMI Cable 5m', 'part_number' => 'CBL-HDMI-5M', 'category' => 'cables'],
        ]);

        $response = $this->postDeliverablesStep($user, $package);

        $response->assertOk();
        $response->assertViewHas('defaults', function (array $defaults): bool {
            return $defaults[ProjectDeliverable::KEY_SITE_SURVEY] === ProjectDeliverable::STATE_NOT_REQUIRED
                && $defaults[ProjectDeliverable::KEY_RAMS] === ProjectDeliverable::STATE_NOT_REQUIRED
                && $defaults[ProjectDeliverable::KEY_WORKSHEET] === ProjectDeliverable::STATE_NOT_REQUIRED
                // Every other deliverable always defaults to Not yet decided —
                // D-15 only names these 3 keys.
                && $defaults[ProjectDeliverable::KEY_OM] === ProjectDeliverable::STATE_NOT_YET_DECIDED
                && $defaults[ProjectDeliverable::KEY_CABLE_SCHEDULE] === ProjectDeliverable::STATE_NOT_YET_DECIDED
                && $defaults[ProjectDeliverable::KEY_INSTALL_PROGRAMME] === ProjectDeliverable::STATE_NOT_YET_DECIDED
                && $defaults[ProjectDeliverable::KEY_DRAWINGS] === ProjectDeliverable::STATE_NOT_YET_DECIDED
                && $defaults[ProjectDeliverable::KEY_SNAGGING] === ProjectDeliverable::STATE_NOT_YET_DECIDED
                && $defaults[ProjectDeliverable::KEY_PROGRAMMING] === ProjectDeliverable::STATE_NOT_YET_DECIDED;
        });
    }

    // ── Has a services line: Survey/RAMS/Worksheet default to Not yet decided,
    //    NEVER Required — the classifier signal does not support a positive
    //    "definitely required" default. ───────────────────────────────────

    public function test_services_line_present_defaults_survey_rams_worksheet_to_not_yet_decided(): void
    {
        $user = User::factory()->create();

        $package = $this->makePackage($user, [
            ['name' => 'Samsung 55" QM55C Display', 'part_number' => 'LH55QMCEBGCXXU', 'category' => 'hardware'],
            // INSTALL2 is a canonical 21CAV services SKU token — buckets as 'services'.
            ['name' => 'Installation Labour', 'part_number' => 'INSTALL2', 'category' => 'services'],
        ]);

        $response = $this->postDeliverablesStep($user, $package);

        $response->assertOk();
        $response->assertViewHas('defaults', function (array $defaults): bool {
            return $defaults[ProjectDeliverable::KEY_SITE_SURVEY] === ProjectDeliverable::STATE_NOT_YET_DECIDED
                && $defaults[ProjectDeliverable::KEY_RAMS] === ProjectDeliverable::STATE_NOT_YET_DECIDED
                && $defaults[ProjectDeliverable::KEY_WORKSHEET] === ProjectDeliverable::STATE_NOT_YET_DECIDED;
        });
    }

    // ── Rendered checklist: 9 radio groups, one per ALL_KEYS ────────────────

    public function test_deliverables_view_renders_all_nine_radio_groups(): void
    {
        $user    = User::factory()->create();
        $package = $this->makePackage($user, []);

        $response = $this->postDeliverablesStep($user, $package);

        $response->assertOk();
        foreach (ProjectDeliverable::ALL_KEYS as $key) {
            $response->assertSee('deliverables['.$key.']', false);
        }
    }
}
