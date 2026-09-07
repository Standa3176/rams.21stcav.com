<?php

namespace Tests\Feature\Rams;

use App\Models\RamsDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 28 follow-up — proves `2026_09_07_090000_backfill_residual_ffp2_control_lines`
 * clears the two residual FFP2 control strings that the first backfill's
 * library lookup could not resolve, and touches nothing else.
 *
 * The fixtures use the REAL legacy hazard names found in production
 * (2026-09-07 diagnosis) — "Dust from Drilling & Cutting" and "Working in
 * Ceiling Voids" — precisely because those names are absent from
 * `hazard_templates`. A fixture using a current library name would pass
 * without exercising the reason this migration exists.
 */
class BackfillResidualFfp2ControlLinesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_09_07_090000_backfill_residual_ffp2_control_lines.php';

    private function runMigration(): void
    {
        $migration = require base_path(self::MIGRATION);
        $migration->up();
    }

    private function makeDoc(array $hazards, string $column = 'generated_data'): RamsDocument
    {
        return RamsDocument::factory()->create([
            $column => ['hazards' => $hazards],
        ]);
    }

    public function test_replaces_both_residual_strings_under_unresolvable_legacy_hazard_names(): void
    {
        $doc = $this->makeDoc([
            [
                'hazard' => 'Dust from Drilling & Cutting',
                'controls' => ['FFP2 dust mask and safety glasses worn during all drilling and cutting'],
            ],
            [
                'hazard' => 'Working in Ceiling Voids',
                'controls' => ['Dust mask (FFP2) worn when accessing ceiling voids'],
            ],
        ]);

        $this->runMigration();

        $hazards = $doc->fresh()->generated_data['hazards'];

        $this->assertSame(
            'FFP3 dust mask and safety glasses worn during all drilling and cutting',
            $hazards[0]['controls'][0],
        );
        $this->assertSame(
            'Dust mask (FFP3) worn when accessing ceiling voids',
            $hazards[1]['controls'][0],
        );
    }

    public function test_is_idempotent(): void
    {
        $doc = $this->makeDoc([[
            'hazard' => 'Dust from Drilling & Cutting',
            'controls' => ['FFP2 dust mask and safety glasses worn during all drilling and cutting'],
        ]]);

        $this->runMigration();
        $afterFirst = $doc->fresh()->generated_data;

        $this->runMigration();
        $afterSecond = $doc->fresh()->generated_data;

        $this->assertSame($afterFirst, $afterSecond, 'second run must be a no-op');
    }

    public function test_leaves_non_matching_control_lines_untouched(): void
    {
        $engineerText = 'FFP2 masks are NOT sufficient here — see site-specific assessment';

        $doc = $this->makeDoc([[
            'hazard' => 'Dust from Drilling & Cutting',
            'controls' => [$engineerText, 'Unrelated control line'],
        ]]);

        $this->runMigration();

        $controls = $doc->fresh()->generated_data['hazards'][0]['controls'];

        $this->assertSame(
            $engineerText,
            $controls[0],
            'a control line that is not an exact match must be left alone — fail closed, never guess',
        );
        $this->assertSame('Unrelated control line', $controls[1]);
    }

    public function test_handles_the_control_measures_field_name_and_reviewed_data_column(): void
    {
        $doc = $this->makeDoc([[
            'hazard' => 'Working in Ceiling Voids',
            'control_measures' => ['Dust mask (FFP2) worn when accessing ceiling voids'],
        ]], 'reviewed_data');

        $this->runMigration();

        $this->assertSame(
            'Dust mask (FFP3) worn when accessing ceiling voids',
            $doc->fresh()->reviewed_data['hazards'][0]['control_measures'][0],
        );
    }

    public function test_document_with_no_hazards_is_not_corrupted(): void
    {
        $doc = RamsDocument::factory()->create(['generated_data' => ['hazards' => []]]);

        $this->runMigration();

        $this->assertSame(['hazards' => []], $doc->fresh()->generated_data);
    }
}
