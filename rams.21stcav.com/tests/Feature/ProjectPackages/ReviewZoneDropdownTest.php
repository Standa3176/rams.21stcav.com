<?php

namespace Tests\Feature\ProjectPackages;

use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 23 Plan 06 — DRAW-46 D-03 zone-dropdown write-side test.
 *
 * Covers the equipment[N][zone] persistence path from the quote-review form
 * POST through ProjectPackageReviewController::update() into the package's
 * extracted_data JSON column. Validation rules enforce the Pitfall 8 XSS
 * regex (Unicode-letter friendly per checker warning #6).
 *
 * Route note: the project-packages.review.update route is POST (not PUT) —
 * see routes/web.php line ~165. Tests use $this->post().
 */
class ReviewZoneDropdownTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{user: User, package: ProjectPackage}
     */
    private function makeReviewableProject(): array
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $package = ProjectPackage::create([
            'project_id'     => $project->id,
            'user_id'        => $user->id,
            'extracted_data' => [
                'equipment' => [
                    [
                        'part_number' => 'A',
                        'name'        => 'Switch',
                        'category'    => 'hardware',
                        'area'        => 'Server Room',
                        'quantity'    => 1,
                    ],
                ],
            ],
            'status'         => ProjectPackage::STATUS_EXTRACTED,
        ]);

        return ['user' => $user, 'package' => $package];
    }

    /**
     * Minimal project payload — parseReviewPayload requires it to round-trip
     * without nuking the project section.
     *
     * @return array<string, string>
     */
    private function projectPayload(): array
    {
        return [
            'project_name' => 'P',
            'quote_ref'    => '',
            'client_name'  => '',
            'site_name'    => '',
            'site_address' => '',
            'prepared_by'  => '',
            'overview'     => '',
        ];
    }

    public function test_review_form_persists_zone_on_known_vocab_value(): void
    {
        $f = $this->makeReviewableProject();
        $this->actingAs($f['user']);

        $response = $this->post(route('project-packages.review.update', $f['package']), [
            'equipment' => [
                [
                    'part_number' => 'A',
                    'name'        => 'Switch',
                    'category'    => 'hardware',
                    'area'        => 'Server Room',
                    'quantity'    => 1,
                    'zone'        => 'RACK',
                ],
            ],
            'project' => $this->projectPayload(),
        ]);

        $response->assertRedirect();
        $f['package']->refresh();
        $this->assertSame('RACK', $f['package']->extracted_data['equipment'][0]['zone'] ?? null);
    }

    public function test_review_form_persists_free_text_zone_within_regex(): void
    {
        $f = $this->makeReviewableProject();
        $this->actingAs($f['user']);

        $this->post(route('project-packages.review.update', $f['package']), [
            'equipment' => [
                [
                    'part_number' => 'A',
                    'name'        => 'Switch',
                    'category'    => 'hardware',
                    'area'        => 'Server Room',
                    'quantity'    => 1,
                    'zone'        => 'Server Cabinet',
                ],
            ],
            'project' => $this->projectPayload(),
        ]);

        $f['package']->refresh();
        $this->assertSame('Server Cabinet', $f['package']->extracted_data['equipment'][0]['zone'] ?? null);
    }

    public function test_review_form_persists_unicode_zone_label(): void
    {
        // Regression for checker warning #6 — the regex must be Unicode-letter
        // friendly so engineers can label zones in non-ASCII scripts (e.g.
        // "Régie" for a French control room).
        $f = $this->makeReviewableProject();
        $this->actingAs($f['user']);

        $response = $this->post(route('project-packages.review.update', $f['package']), [
            'equipment' => [
                [
                    'part_number' => 'A',
                    'name'        => 'Switch',
                    'category'    => 'hardware',
                    'area'        => 'Server Room',
                    'quantity'    => 1,
                    'zone'        => 'Régie',
                ],
            ],
            'project' => $this->projectPayload(),
        ]);

        $response->assertRedirect();
        $f['package']->refresh();
        $this->assertSame('Régie', $f['package']->extracted_data['equipment'][0]['zone'] ?? null);
    }

    public function test_review_form_rejects_xss_payload_in_zone(): void
    {
        $f = $this->makeReviewableProject();
        $this->actingAs($f['user']);

        $response = $this->from(route('project-packages.review.show', $f['package']))->post(
            route('project-packages.review.update', $f['package']),
            [
                'equipment' => [
                    [
                        'part_number' => 'A',
                        'name'        => 'Switch',
                        'category'    => 'hardware',
                        'area'        => '',
                        'quantity'    => 1,
                        'zone'        => '<script>alert(1)</script>',
                    ],
                ],
                'project' => $this->projectPayload(),
            ]
        );

        $response->assertSessionHasErrors(['equipment.0.zone']);
        $f['package']->refresh();
        // Zone NOT persisted on validation failure.
        $this->assertArrayNotHasKey('zone', $f['package']->extracted_data['equipment'][0] ?? []);
    }

    public function test_review_form_rejects_zone_over_50_chars(): void
    {
        $f = $this->makeReviewableProject();
        $this->actingAs($f['user']);

        $longZone = str_repeat('A', 51);
        $response = $this->from(route('project-packages.review.show', $f['package']))->post(
            route('project-packages.review.update', $f['package']),
            [
                'equipment' => [
                    [
                        'part_number' => 'A',
                        'name'        => 'Switch',
                        'category'    => 'hardware',
                        'area'        => '',
                        'quantity'    => 1,
                        'zone'        => $longZone,
                    ],
                ],
                'project' => $this->projectPayload(),
            ]
        );

        $response->assertSessionHasErrors(['equipment.0.zone']);
    }

    public function test_empty_zone_is_omitted_not_persisted_as_empty_string(): void
    {
        $f = $this->makeReviewableProject();
        $this->actingAs($f['user']);

        $this->post(route('project-packages.review.update', $f['package']), [
            'equipment' => [
                [
                    'part_number' => 'A',
                    'name'        => 'Switch',
                    'category'    => 'hardware',
                    'area'        => '',
                    'quantity'    => 1,
                    'zone'        => '',
                ],
            ],
            'project' => $this->projectPayload(),
        ]);

        $f['package']->refresh();
        // Empty zone falls through to category default per D-01 — DO NOT persist as ''.
        $this->assertArrayNotHasKey('zone', $f['package']->extracted_data['equipment'][0] ?? []);
    }

    public function test_existing_equipment_fields_remain_unchanged_after_zone_addition(): void
    {
        $f = $this->makeReviewableProject();
        $this->actingAs($f['user']);

        $this->post(route('project-packages.review.update', $f['package']), [
            'equipment' => [
                [
                    'part_number' => 'A',
                    'name'        => 'Switch',
                    'category'    => 'hardware',
                    'area'        => 'Server Room',
                    'quantity'    => 3,
                    'zone'        => 'RACK',
                ],
            ],
            'project' => $this->projectPayload(),
        ]);

        $f['package']->refresh();
        $item = $f['package']->extracted_data['equipment'][0];
        $this->assertSame('A',           $item['part_number']);
        $this->assertSame('Switch',      $item['name']);
        $this->assertSame('hardware',    $item['category']);
        $this->assertSame('Server Room', $item['area']);
        $this->assertSame(3,             $item['quantity']);
        $this->assertSame('RACK',        $item['zone']);
    }
}
