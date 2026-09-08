<?php

namespace Tests\Feature\ProjectPackages;

use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression guard — the package review screen must render a hazard row
 * without a legacy `risk` key.
 *
 * `45f4260` (Phase 26-05) rewrote `RamsReviewDataService::normaliseHazards()`
 * to the numeric pre_/post_ score schema and **dropped the legacy
 * High/Medium/Low `risk` string from its output**. It still reads `risk` on
 * the way in, so nothing upstream broke — but
 * `resources/views/project-packages/review.blade.php` was never updated and
 * kept reading `$hazard['risk']` directly.
 *
 * Result: `Undefined array key "risk"` → HTTP 500 on EVERY package review
 * carrying at least one hazard. It sat latent from Phase 26 until a live
 * import surfaced it on 2026-09-08 (two 500s: "create RAMS for a newly loaded
 * project" and "edit project data" — the same screen, the same fault).
 *
 * `risk` was the ONLY key that commit removed (`activity_key`, `hazard` and
 * `control_measures` all survive), so this is the complete blast radius —
 * verified by grepping every non-backup blade for `['risk']`, which returns
 * this one call site.
 *
 * This test renders the real route through the real normaliser. It fails
 * against the pre-fix blade.
 */
class ReviewHazardRiskLabelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<int, array<string, mixed>>  $hazards
     * @return array{user: User, package: ProjectPackage}
     */
    private function makePackageWithHazards(array $hazards): array
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $package = ProjectPackage::create([
            'project_id'     => $project->id,
            'user_id'        => $user->id,
            'extracted_data' => [
                'equipment' => [[
                    'part_number' => 'A',
                    'name'        => 'Switch',
                    'category'    => 'hardware',
                    'area'        => 'Server Room',
                    'quantity'    => 1,
                ]],
                'hazards' => $hazards,
            ],
            'status'         => ProjectPackage::STATUS_EXTRACTED,
        ]);

        return ['user' => $user, 'package' => $package];
    }

    public function test_review_screen_renders_when_hazard_has_no_legacy_risk_key(): void
    {
        // Exactly what normaliseHazards() emits post-45f4260: numeric scores,
        // no `risk`. This is the shape that produced the live 500.
        $f = $this->makePackageWithHazards([[
            'activity_key'     => '',
            'hazard'           => 'Working at height',
            'pre_likelihood'   => 4,
            'pre_severity'     => 4,
            'control_measures' => ['Use a podium step'],
        ]]);

        $this->actingAs($f['user']);

        $this->get(route('project-packages.review.show', $f['package']))
            ->assertOk();
    }

    public function test_risk_label_is_derived_from_the_numeric_scores(): void
    {
        // 4 x 4 = 16 -> High, per riskLabelFromScore()'s thresholds
        // (<=3 Low, <=6 Medium, else High).
        $f = $this->makePackageWithHazards([[
            'activity_key'     => '',
            'hazard'           => 'Working at height',
            'pre_likelihood'   => 4,
            'pre_severity'     => 4,
            'control_measures' => ['Use a podium step'],
        ]]);

        $this->actingAs($f['user']);

        $html = $this->get(route('project-packages.review.show', $f['package']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            '<option value="High"',
            $html,
            'the High option should be present',
        );
        $this->assertMatchesRegularExpression(
            '/<option value="High"\s+selected/',
            $html,
            'a 4x4=16 hazard must preselect High, not fall back to a flat Medium — '
            . 'a bare `?? \'Medium\'` fix would pass the render test above but silently '
            . 'misrepresent the assessed risk to the engineer',
        );
    }

    public function test_low_band_is_derived_correctly(): void
    {
        // 1 x 2 = 2 -> Low
        $f = $this->makePackageWithHazards([[
            'activity_key'     => '',
            'hazard'           => 'Minor abrasion',
            'pre_likelihood'   => 1,
            'pre_severity'     => 2,
            'control_measures' => ['Gloves'],
        ]]);

        $this->actingAs($f['user']);

        $html = $this->get(route('project-packages.review.show', $f['package']))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/<option value="Low"\s+selected/', $html);
    }

    public function test_a_legacy_risk_string_is_still_honoured_when_present(): void
    {
        // Pre-Phase-26 payloads carry `risk` and no scores. normaliseHazards()
        // maps High -> 4x4, so the derived label round-trips to High either way;
        // this asserts the legacy path is not regressed.
        $f = $this->makePackageWithHazards([[
            'activity_key'     => '',
            'hazard'           => 'Legacy hazard',
            'risk'             => 'High',
            'control_measures' => ['Some control'],
        ]]);

        $this->actingAs($f['user']);

        $html = $this->get(route('project-packages.review.show', $f['package']))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/<option value="High"\s+selected/', $html);
    }
}
