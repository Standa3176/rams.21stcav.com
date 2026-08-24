<?php

namespace Tests\Feature\Rams;

use App\Models\RamsDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 26 Plan 05 (HAZ-04) — quote-review.blade.php hazard row editable
 * numeric scores + needs-confirmation badge.
 *
 * HAZ-04 is absolute: "Do not let the app apply the typical scores
 * silently." This test locks the review screen's markup contract: 4
 * editable numeric L×S inputs per hazard row (no Low/Medium/High select),
 * and a visible badge that distinguishes a resolver-flagged
 * needs_confirmation row from a confidently-matched one.
 *
 * @see resources/views/rams/quote-review.blade.php
 * @see app/Services/RamsReviewDataService.php::normaliseHazards()
 */
class HazardScoreEditableDefaultTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        return User::factory()->create();
    }

    private function makeRecordWithHazards(User $user, array $hazards): RamsDocument
    {
        return RamsDocument::create([
            'user_id'        => $user->id,
            'project_ref'    => 'TEST-HAZ04',
            'project_name'   => 'Test Project',
            'client_name'    => 'Acme Ltd',
            'site_address'   => '123 Test Street',
            'ai_provider'    => 'claude',
            'ai_model'       => 'claude-sonnet-4-6',
            'form_data'      => ['stored_pdf_path' => '/tmp/test.pdf', 'source' => 'quote_upload'],
            'status'         => RamsDocument::STATUS_AWAITING_REVIEW,
            'extracted_data' => [
                'project'    => ['project_name' => 'Test Project'],
                'equipment'  => [['quantity' => 1, 'name' => 'Display']],
                'activities' => [['key' => 'display_installation', 'label' => 'Display Installation']],
                'hazards'    => $hazards,
                'ppe'        => ['Safety Boots (steel toe cap)'],
                'access'     => [],
                'meta'       => ['parser_confidence' => 0.9, 'source' => 'extracted'],
            ],
        ]);
    }

    public function test_review_screen_shows_numeric_score_inputs_not_a_select(): void
    {
        $user = $this->makeUser();

        $record = $this->makeRecordWithHazards($user, [
            [
                'activity_key'       => 'display_installation',
                'hazard'             => 'Slips, trips and falls',
                'pre_likelihood'     => 2,
                'pre_severity'       => 2,
                'post_likelihood'    => 1,
                'post_severity'      => 1,
                'score_reviewed'     => false,
                'needs_confirmation' => false,
                'control_measures'   => ['Keep walkways clear'],
            ],
        ]);

        $response = $this->actingAs($user)->get(route('rams.quote-review.show', $record));

        $response->assertStatus(200);
        $html = $response->getContent();

        $this->assertStringContainsString('name="hazards[0][pre_likelihood]"', $html);
        $this->assertStringContainsString('name="hazards[0][pre_severity]"', $html);
        $this->assertStringContainsString('name="hazards[0][post_likelihood]"', $html);
        $this->assertStringContainsString('name="hazards[0][post_severity]"', $html);
        $this->assertStringContainsString('name="hazards[0][score_reviewed]"', $html);
        $this->assertStringNotContainsString('<select name="hazards', $html);
    }

    public function test_review_screen_shows_confirmation_badge_only_for_flagged_rows(): void
    {
        $user = $this->makeUser();

        $record = $this->makeRecordWithHazards($user, [
            [
                'activity_key'       => 'display_installation',
                'hazard'             => 'Occupied premises',
                'pre_likelihood'     => 3,
                'pre_severity'       => 3,
                'post_likelihood'    => 2,
                'post_severity'      => 2,
                'score_reviewed'     => false,
                'needs_confirmation' => true,
                'control_measures'   => ['Coordinate with site staff'],
            ],
            [
                'activity_key'       => 'display_installation',
                'hazard'             => 'Slips, trips and falls',
                'pre_likelihood'     => 2,
                'pre_severity'       => 2,
                'post_likelihood'    => 1,
                'post_severity'      => 1,
                'score_reviewed'     => false,
                'needs_confirmation' => false,
                'control_measures'   => ['Keep walkways clear'],
            ],
        ]);

        $response = $this->actingAs($user)->get(route('rams.quote-review.show', $record));

        $response->assertStatus(200);
        $html = $response->getContent();

        $this->assertStringContainsString('Needs confirmation', $html);
        $this->assertSame(
            1,
            substr_count($html, '<span class="badge badge-warning badge-needs-confirmation">'),
            'only the flagged row (index 0) renders the confirmation badge span',
        );
        $this->assertSame(
            1,
            substr_count($html, 'class="hazard-needs-confirmation"'),
            'only the flagged row (index 0) carries the row-highlight class',
        );
    }
}
