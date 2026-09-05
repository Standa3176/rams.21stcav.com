<?php

namespace Tests\Feature\Rams;

use App\Services\RamsBuilderService;
use Database\Seeders\HazardTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Plan 27-08 Task 3 — proves reviewedToRisk()'s three-tier control precedence.
 *
 * WHY THIS EXISTS. 27-VERIFICATION.md, Blocker 1: a live regeneration of
 * 21CQ30960 (RAMS 100, 2026-08-26) rendered the Manual Handling hazard with
 * "items over 20 kg" and "screens and equipment over 40\" — minimum two
 * persons". Neither string was in the library — they were stale reviewed_data
 * passed straight through, because reviewedToRisk() only replaced controls on
 * a genuine rename and "Manual handling" already matched canonically. Every
 * house-rule correction made to the seeded library was therefore invisible on
 * the most common generation path, and would have been for Phases 28 and 31
 * too.
 *
 * The precedence now is:
 *   1. controls breach a known house rule -> library wins, reason recorded
 *   2. controls_reviewed !== true         -> library wins (no human intent)
 *   3. otherwise                          -> the engineer's text stands
 *
 * Real seeded DB, real HazardLibraryService. No AI — reviewedToRisk() is
 * deterministic (CLAUDE.md line 12).
 */
class ReviewedControlRefreshTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HazardTemplateSeeder::class);
    }

    /**
     * Invoke the private reviewedToRisk() with a single reviewed hazard row.
     *
     * @param  array<string, mixed>  $hazardRow
     * @return array<string, mixed>  the resolved hazard
     */
    private function resolveOne(array $hazardRow): array
    {
        $service = app(RamsBuilderService::class);

        $method = new \ReflectionMethod($service, 'reviewedToRisk');
        $method->setAccessible(true);

        $result = $method->invoke(
            $service,
            ['hazards' => [$hazardRow]],
            null,   // userId — global templates only
            [],     // activities
            '',     // scopeNarrative
            false,  // drillingRequired
        );

        // reviewedToRisk() returns ['hazards' => [...], 'ppe' => ..., 'access_equipment' => ...]
        $rows = array_values(array_filter(
            $result['hazards'] ?? [],
            fn ($r) => strcasecmp((string) ($r['hazard'] ?? ''), 'Manual handling') === 0,
        ));

        $this->assertNotEmpty($rows, 'Expected a resolved "Manual handling" row.');

        return $rows[0];
    }

    /** The exact two control lines captured from live RAMS 100. */
    private function liveViolatingControls(): array
    {
        return [
            'Use mechanical aids (sack trucks, lifting trolleys) for items over 20 kg.',
            'Team lift required for screens and equipment over 40" — minimum two persons.',
        ];
    }

    // ── Tier 2 — never edited, library wins ──────────────────────────────────

    public function test_controls_never_edited_are_replaced_by_the_library(): void
    {
        $row = $this->resolveOne([
            'hazard'           => 'Manual handling',
            'control_measures' => ['Some stale text nobody ever reviewed.'],
            // controls_reviewed deliberately absent — the legacy shape
        ]);

        $controls = implode(' ', $row['controls']);

        $this->assertStringNotContainsString('Some stale text', $controls);
        $this->assertStringContainsString('single-operative lift', $controls);
    }

    // ── Tier 3 — engineer edited and clean, their text stands ────────────────

    public function test_engineer_edited_clean_controls_survive(): void
    {
        $custom = ['Site-specific: use the goods lift in Block C only.'];

        $row = $this->resolveOne([
            'hazard'            => 'Manual handling',
            'control_measures'  => $custom,
            'controls_reviewed' => true,
        ]);

        $this->assertSame($custom, $row['controls']);
        $this->assertNull($row['controls_replaced_reason'] ?? null);
    }

    public function test_the_same_clean_controls_are_replaced_when_unmarked(): void
    {
        // The distinguishing case for tier 2: identical text, marker absent.
        $row = $this->resolveOne([
            'hazard'           => 'Manual handling',
            'control_measures' => ['Site-specific: use the goods lift in Block C only.'],
        ]);

        $this->assertNotSame(
            ['Site-specific: use the goods lift in Block C only.'],
            $row['controls'],
        );
        $this->assertStringContainsString('single-operative lift', implode(' ', $row['controls']));
    }

    // ── Tier 1 — violates a house rule, replaced regardless of the marker ────

    public function test_violating_controls_are_replaced_even_when_engineer_edited(): void
    {
        $row = $this->resolveOne([
            'hazard'            => 'Manual handling',
            'control_measures'  => $this->liveViolatingControls(),
            'controls_reviewed' => true,   // the engineer DID edit these
        ]);

        $controls = implode(' ', $row['controls']);

        $this->assertStringNotContainsString('over 20 kg', $controls);
        $this->assertStringNotContainsString('over 40', $controls);

        $reasons = $row['controls_replaced_reason'] ?? [];
        $this->assertContains('kg_threshold', $reasons);
        $this->assertContains('size_conditional_lift', $reasons);
    }

    /**
     * The live-evidence test. Mirrors ReviewedHazardTieringTest's
     * real-vocabulary test, which is what finally closed HAZ-02 after two
     * premature closures — a synthetic fixture would not have caught this.
     */
    public function test_real_rams_100_vocabulary_no_longer_reaches_output(): void
    {
        foreach ([true, false] as $reviewed) {
            $hazard = [
                'hazard'           => 'Manual handling',
                'control_measures' => $this->liveViolatingControls(),
            ];

            if ($reviewed) {
                $hazard['controls_reviewed'] = true;
            }

            $controls = implode(' ', $this->resolveOne($hazard)['controls']);

            $this->assertStringNotContainsString(
                'over 20 kg',
                $controls,
                'RULE-13 breach survived regeneration (controls_reviewed='
                . var_export($reviewed, true) . ')',
            );
            $this->assertStringNotContainsString(
                'over 40',
                $controls,
                'RULE-02 breach survived regeneration (controls_reviewed='
                . var_export($reviewed, true) . ')',
            );
        }
    }

    // ── Guards ───────────────────────────────────────────────────────────────

    public function test_unmatched_hazard_keeps_its_controls_and_does_not_throw(): void
    {
        $service = app(RamsBuilderService::class);
        $method  = new \ReflectionMethod($service, 'reviewedToRisk');
        $method->setAccessible(true);

        $custom = ['Wholly bespoke control for a hazard the library does not know.'];

        $result = $method->invoke($service, ['hazards' => [[
            'hazard'           => 'Zzz Nonexistent Bespoke Hazard',
            'control_measures' => $custom,
        ]]], null, [], '', false);

        $rows = array_values(array_filter(
            $result['hazards'] ?? [],
            fn ($r) => stripos((string) ($r['hazard'] ?? ''), 'Bespoke') !== false,
        ));

        $this->assertNotEmpty($rows, 'The unmatched hazard should still be emitted.');
        $this->assertSame($custom, $rows[0]['controls']);
    }

    public function test_score_reviewed_behaviour_is_unchanged(): void
    {
        // Plan 27-08 threads a parallel marker through the same interfaces;
        // this asserts it did not disturb HAZ-03's score precedence.
        $row = $this->resolveOne([
            'hazard'           => 'Manual handling',
            'control_measures' => ['Anything.'],
            'pre_likelihood'   => 5,
            'pre_severity'     => 5,
            'score_reviewed'   => true,
        ]);

        $this->assertSame(5, $row['pre_likelihood']);
        $this->assertSame(5, $row['pre_severity']);
        $this->assertTrue($row['score_reviewed']);
    }
}
