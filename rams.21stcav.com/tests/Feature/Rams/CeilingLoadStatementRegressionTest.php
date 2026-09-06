<?php

namespace Tests\Feature\Rams;

use App\Models\HazardTemplate;
use App\Services\EquipmentClassifierService;
use App\Services\Rams\HazardIncludeWhenResolver;
use Database\Seeders\HazardTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 28 Plan 05 — RULE-10.
 *
 * Locks in `28-RESEARCH.md` Q1's empirical finding as a regression test:
 * `signal:ceiling_void_access` already fires for a ceiling-MOUNTED job (not
 * necessarily ceiling-void entry) via the broad `ceiling_works` activity
 * keyword match in EquipmentClassifierService, and the matched hazard's
 * seeded controls already carry RULE-10's exact ceiling-load sentence.
 *
 * This test proves the chain end-to-end against the REAL seeded hazard
 * library (not a hand-built template Collection, which is already covered
 * by tests/Unit/Services/Rams/HazardIncludeWhenResolverTest.php) — closing
 * the Wave-0 gap research identified: the resolver-level unit test proves
 * the signal-matching RULE, but nothing previously proved the classifier
 * output and the real seeded content actually connect end-to-end.
 *
 * No new trigger or mechanism is added by this plan — see 28-05-PLAN.md's
 * objective. This is a lock-in, not new derivation logic.
 *
 * Per research Pitfall 2 (an intentional non-fix): the SAME matched hazard
 * also carries void-entry-specific controls (asbestos register check,
 * head-torch void inspection, tile removal) that are over-inclusive for a
 * pure ceiling-mount (no void entry) job. This is documented, pre-existing
 * behaviour this phase does not change — this test does not assert against
 * it.
 */
class CeilingLoadStatementRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_ceiling_mounted_equipment_produces_the_ceiling_load_control_line_end_to_end(): void
    {
        $this->seed(HazardTemplateSeeder::class);

        $classified = app(EquipmentClassifierService::class)->classify([
            ['qty' => 1, 'description' => 'Ceiling mounted projector'],
        ]);

        $this->assertContains('ceiling_works', $classified['activities'],
            'EquipmentClassifierService did not classify a ceiling-mounted item as ceiling_works — RULE-10\'s trigger chain starts here.');

        $signals = [
            'activities'        => $classified['activities'],
            'drilling_required' => $classified['drilling_required'],
            'scope_narrative'   => '',
        ];

        // The real seeded global hazard library — not a hand-built fixture.
        $library = HazardTemplate::where('is_global', true)->get();

        $matched = app(HazardIncludeWhenResolver::class)->resolve($library, $signals);

        $this->assertGreaterThan(0, $matched->count(),
            'No hazard matched the ceiling_works signal against the real seeded library.');

        $ceilingLoadSentenceFound = $matched->contains(function (HazardTemplate $hazard) {
            $controls = $hazard->controls ?? [];

            return collect($controls)->contains(
                fn ($control) => is_string($control) && str_contains($control, 'structural soffit'),
            );
        });

        $this->assertTrue($ceilingLoadSentenceFound,
            'RULE-10 ceiling-load control line ("structural soffit or a purpose-designed ceiling mount kit") not found in any matched hazard\'s controls for a ceiling-mounted (non-void-entry) job.');
    }
}
