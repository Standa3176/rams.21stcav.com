<?php

namespace Tests\Feature\Rams;

use App\Models\HazardTemplate;
use App\Models\RamsDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 28 Plan 07 Task 2 — proves the backfill migration's four surfaces
 * (PPE fold, hazard-controls FFP2 replace, hazard-NAME replace, exclusions
 * bullet append) fire correctly on BOTH `reviewed_data` and `generated_data`,
 * never touch a document whose `exclusions` key is unset, never drop an
 * engineer-authored exclusion, and are idempotent — a second run is a
 * byte-identical no-op.
 *
 * The migration file is included directly (`require`, not `require_once`)
 * and its `up()` invoked against fixture rows inserted AFTER
 * `RefreshDatabase` has already run every migration once (on an empty
 * table, so that initial run is a no-op) — mirroring how a real second
 * `php artisan migrate` invocation would behave against already-migrated
 * production data.
 *
 * @see database/migrations/2026_09_05_180000_backfill_ppe_ffp2_and_electrical_exclusion_bullet.php
 * @see .planning/phases/28-ppe-ceiling-electrical-boundary-house-rules/28-07-MEASUREMENT.md
 */
class BackfillPpeFfp2AndElectricalExclusionBulletMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_09_05_180000_backfill_ppe_ffp2_and_electrical_exclusion_bullet.php';

    private const RULE_09_BULLET = 'Electrical scope terminates at the existing socket outlet or client data outlet — no alteration to the fixed electrical installation and no live working under any circumstances.';

    private function runMigration(): void
    {
        $migration = require base_path(self::MIGRATION_PATH);
        $migration->up();
    }

    private function seedDustHazardLibraryEntry(): void
    {
        HazardTemplate::create([
            'user_id'         => null,
            'name'            => 'Dust from drilling and cutting',
            'description'     => 'Dust from drilling and cutting into walls, ceilings or floors.',
            'pre_likelihood'  => 3,
            'pre_severity'    => 2,
            'post_likelihood' => 1,
            'post_severity'   => 2,
            'controls'        => [
                'FFP3 dust mask and safety glasses worn during all drilling and cutting. All operatives face-fit tested.',
                'Dust extraction / vacuum attachment used on all drilling operations.',
            ],
            'is_global'       => true,
        ]);
    }

    /** A single fixture document carrying all four defects, in both columns. */
    private function fixtureDocument(): RamsDocument
    {
        $this->seedDustHazardLibraryEntry();

        $hazardsBlock = fn (string $controlsKey) => [
            [
                'hazard'    => 'Confined Spaces',
                $controlsKey => ['Confirm ventilation before entering ceiling void.'],
            ],
            [
                'hazard'    => 'Dust from drilling and cutting',
                $controlsKey => ['Use FFP2 dust mask when drilling.'],
            ],
        ];

        return RamsDocument::factory()->create([
            'reviewed_data' => [
                'ppe'        => ['Dust Mask (FFP2)', 'Safety glasses'],
                'exclusions' => ['No structural works'],
                'hazards'    => $hazardsBlock('control_measures'),
            ],
            'generated_data' => [
                'ppe'        => ['Dust Mask (FFP2)'],
                'ppe_matrix' => [
                    ['task' => 'Drilling / cutting / fixing', 'ppe' => ['Dust mask (FFP2)']],
                ],
                'exclusions' => ['No structural works'],
                'hazards'    => $hazardsBlock('controls'),
            ],
        ]);
    }

    public function test_backfill_fixes_all_four_surfaces_in_both_columns(): void
    {
        $doc = $this->fixtureDocument();

        $this->runMigration();

        $doc->refresh();

        foreach (['reviewed_data', 'generated_data'] as $column) {
            $data = $doc->$column;

            // (a) ppe fold.
            $this->assertContains('Dust Mask (FFP3)', $data['ppe'], "{$column}.ppe must contain the folded FFP3 string");
            foreach ($data['ppe'] as $item) {
                $this->assertStringNotContainsStringIgnoringCase('FFP2', $item, "{$column}.ppe must never carry FFP2");
            }

            // (c) hazard NAME fix — deterministic single-string replace.
            $names = array_column($data['hazards'], 'hazard');
            $this->assertContains('Restricted access and ceiling void working', $names, "{$column} hazard name must fold to the canonical string");
            $this->assertNotContains('Confined Spaces', $names, "{$column} must never carry the legacy hazard name");

            // (b) hazard controls FFP2 fix — replaced with current library text.
            $dustHazard = collect($data['hazards'])->first(fn ($h) => $h['hazard'] === 'Dust from drilling and cutting');
            $this->assertNotNull($dustHazard, "{$column} must still carry the Dust from drilling and cutting hazard");

            $controlsField = array_key_exists('controls', $dustHazard) ? 'controls' : 'control_measures';
            foreach ($dustHazard[$controlsField] as $control) {
                $this->assertStringNotContainsStringIgnoringCase('FFP2', $control, "{$column} hazard controls must never carry FFP2 after backfill");
            }
            $this->assertContains(
                'FFP3 dust mask and safety glasses worn during all drilling and cutting. All operatives face-fit tested.',
                $dustHazard[$controlsField],
                "{$column} hazard controls must be replaced with the current library text",
            );

            // (d) exclusions bullet appended, existing bullet preserved.
            $this->assertContains(self::RULE_09_BULLET, $data['exclusions'], "{$column}.exclusions must carry the RULE-09 bullet");
            $this->assertContains('No structural works', $data['exclusions'], "{$column}.exclusions must preserve the pre-existing bullet");
        }

        // ppe_matrix — generated_data only, defence-in-depth surface.
        $matrix = $doc->generated_data['ppe_matrix'];
        $this->assertContains('Dust Mask (FFP3)', $matrix[0]['ppe']);
        foreach ($matrix[0]['ppe'] as $item) {
            $this->assertStringNotContainsStringIgnoringCase('FFP2', $item);
        }
    }

    public function test_backfill_is_idempotent(): void
    {
        $doc = $this->fixtureDocument();

        $this->runMigration();
        $afterFirstRun = DB::table('rams_documents')->where('id', $doc->id)->first();

        // A second run must be a byte-identical no-op — re-including the
        // migration file mirrors a real second `php artisan migrate` call.
        $this->runMigration();
        $afterSecondRun = DB::table('rams_documents')->where('id', $doc->id)->first();

        $this->assertSame(
            $afterFirstRun->reviewed_data,
            $afterSecondRun->reviewed_data,
            'reviewed_data must be byte-identical after a second migration run',
        );
        $this->assertSame(
            $afterFirstRun->generated_data,
            $afterSecondRun->generated_data,
            'generated_data must be byte-identical after a second migration run',
        );
    }

    public function test_backfill_never_touches_a_document_with_no_exclusions_key(): void
    {
        $doc = RamsDocument::factory()->create([
            'reviewed_data' => [
                'ppe' => ['Safety glasses'],
                // exclusions deliberately absent — RamsDisplayPatchService's
                // !isset seed already covers this document for free.
            ],
            'generated_data' => [
                'ppe' => ['Safety glasses'],
            ],
        ]);

        $this->runMigration();

        $doc->refresh();

        $this->assertArrayNotHasKey('exclusions', $doc->reviewed_data);
        $this->assertArrayNotHasKey('exclusions', $doc->generated_data);
    }

    public function test_backfill_never_removes_an_engineer_authored_exclusion(): void
    {
        $doc = RamsDocument::factory()->create([
            'reviewed_data' => [
                'exclusions' => ['No structural works', 'A bespoke engineer-authored exclusion'],
            ],
            'generated_data' => [
                'exclusions' => ['No structural works', 'A bespoke engineer-authored exclusion'],
            ],
        ]);

        $this->runMigration();

        $doc->refresh();

        foreach (['reviewed_data', 'generated_data'] as $column) {
            $this->assertContains('A bespoke engineer-authored exclusion', $doc->$column['exclusions']);
            $this->assertContains('No structural works', $doc->$column['exclusions']);
            $this->assertContains(self::RULE_09_BULLET, $doc->$column['exclusions']);
        }
    }

    public function test_backfill_never_renames_a_hazard_with_no_library_match(): void
    {
        // A hazard name this migration must NOT touch — neither the
        // deterministic "Confined Spaces" replace nor the fold+exact
        // library lookup applies to it, so it must be left byte-identical
        // (fail-closed, per the migration's "DELIBERATE NARROWING" docblock).
        $doc = RamsDocument::factory()->create([
            'reviewed_data' => [
                'hazards' => [
                    [
                        'hazard'           => 'A totally unrecognised hazard name',
                        'control_measures' => ['Use FFP2 dust mask when doing the unrecognised activity.'],
                    ],
                ],
            ],
            'generated_data' => [],
        ]);

        $this->runMigration();

        $doc->refresh();

        $this->assertSame('A totally unrecognised hazard name', $doc->reviewed_data['hazards'][0]['hazard']);
        $this->assertSame(
            ['Use FFP2 dust mask when doing the unrecognised activity.'],
            $doc->reviewed_data['hazards'][0]['control_measures'],
        );
    }
}
