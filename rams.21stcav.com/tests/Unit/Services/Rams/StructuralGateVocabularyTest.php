<?php

namespace Tests\Unit\Services\Rams;

use App\Services\Rams\HazardIncludeWhenResolver;
use App\Services\Rams\StructuralGateVocabulary;
use Tests\TestCase;

/**
 * Phase 30 Plan 01 (D-06/D-07/D-08) — proves StructuralGateVocabulary is the
 * ONE shared signal-matching helper the five Phase 30 gates will use: it
 * reuses HazardIncludeWhenResolver's TIER2/TIER3 const maps (never
 * ::resolve(), which queries the DB), is conservative-by-construction
 * (unknown signal / absent input -> no match, never a throw), and
 * implements D-08's client-responsibility union.
 *
 * ── Non-vacuity proof (development-time only, not a committed assertion) ──
 *
 *   1. Before app/Services/Rams/StructuralGateVocabulary.php existed, every
 *      test in this file failed with a "class not found" error.
 *   2. After the class was written, all tests in this file passed.
 *
 * @see app/Services/Rams/StructuralGateVocabulary.php
 * @see app/Services/Rams/HazardIncludeWhenResolver.php
 * @see .planning/phases/30-structural-validation-gates/30-01-PLAN.md
 */
class StructuralGateVocabularyTest extends TestCase
{
    public function test_phrases_for_signal_reuses_hazard_include_when_resolver_asbestos_entry(): void
    {
        $ref = new \ReflectionClass(HazardIncludeWhenResolver::class);
        $tier3 = $ref->getReflectionConstant('TIER3_KEYWORD_PRECHECK')->getValue();

        $this->assertSame($tier3['asbestos'], StructuralGateVocabulary::phrasesForSignal('asbestos'));
    }

    public function test_phrases_for_signal_unknown_signal_returns_empty_array_never_throws(): void
    {
        $this->assertSame([], StructuralGateVocabulary::phrasesForSignal('a-signal-that-does-not-exist'));
    }

    public function test_supported_signals_matches_hazard_include_when_resolver_map_keys(): void
    {
        // Source-of-truth guard (house convention, 30-PATTERNS.md #11):
        // SUPPORTED_SIGNALS must never drift from the resolver's own maps.
        $ref = new \ReflectionClass(HazardIncludeWhenResolver::class);

        $expected = array_unique(array_merge(
            array_keys($ref->getReflectionConstant('TIER2_ACTIVITY_SIGNALS')->getValue()),
            array_keys($ref->getReflectionConstant('TIER2_KEYWORD_SIGNALS')->getValue()),
            array_keys($ref->getReflectionConstant('TIER3_KEYWORD_PRECHECK')->getValue()),
        ));

        sort($expected);
        $actual = StructuralGateVocabulary::SUPPORTED_SIGNALS;
        sort($actual);

        $this->assertSame($expected, $actual);
    }

    public function test_signal_present_in_text_matches_case_insensitively(): void
    {
        $this->assertTrue(StructuralGateVocabulary::signalPresentInText('asbestos', 'Confirm ASBESTOS register on file.'));
        $this->assertFalse(StructuralGateVocabulary::signalPresentInText('asbestos', 'Nothing relevant here.'));
    }

    public function test_signal_matches_hazards_true_when_hazard_row_present(): void
    {
        $hazards = [
            ['hazard' => 'Asbestos-containing materials', 'pre_likelihood' => 2, 'pre_severity' => 4],
            ['hazard' => 'Manual Handling'],
        ];

        $this->assertTrue(StructuralGateVocabulary::signalMatchesHazards('asbestos', $hazards));
    }

    public function test_signal_matches_hazards_false_when_no_matching_hazard_row(): void
    {
        $hazards = [
            ['hazard' => 'Manual Handling'],
            ['hazard' => 'Working at Height'],
        ];

        $this->assertFalse(StructuralGateVocabulary::signalMatchesHazards('asbestos', $hazards));
    }

    public function test_signal_matches_hazards_false_on_empty_register(): void
    {
        $this->assertFalse(StructuralGateVocabulary::signalMatchesHazards('asbestos', []));
    }

    public function test_signal_matches_hazards_unknown_signal_never_matches(): void
    {
        $hazards = [['hazard' => 'Asbestos-containing materials']];

        $this->assertFalse(StructuralGateVocabulary::signalMatchesHazards('not-a-real-signal', $hazards));
    }

    public function test_signal_matches_client_reqs_case_insensitive_flat_string_list(): void
    {
        $reqs = ['Confirm ASBESTOS Register is available on site before works commence.'];

        $this->assertTrue(StructuralGateVocabulary::signalMatchesClientReqs('asbestos', $reqs));
        $this->assertFalse(StructuralGateVocabulary::signalMatchesClientReqs('asbestos', ['Mains power at each location.']));
    }

    public function test_flatten_client_responsibilities_unions_flat_list_and_expanded_buckets(): void
    {
        $data = [
            'client_responsibilities' => ['Mains power outlets must be live.'],
            'client_responsibilities_expanded' => [
                'network_readiness' => ['required' => true, 'notes' => 'Cat6 drop live at rack location.'],
                'licences' => ['required' => false, 'notes' => ''],
                'access' => ['required' => false, 'notes' => 'Site access booked 08:00-17:00.'],
                'power_validation' => ['required' => false, 'notes' => ''],
                'additional' => [
                    ['item' => 'Client IT to confirm VLAN', 'notes' => 'Before install day.'],
                ],
            ],
        ];

        $flat = StructuralGateVocabulary::flattenClientResponsibilities($data);

        $this->assertContains('Mains power outlets must be live.', $flat);
        $this->assertContains('Cat6 drop live at rack location.', $flat);
        $this->assertContains('Site access booked 08:00-17:00.', $flat);
        $this->assertContains('Client IT to confirm VLAN', $flat);
        $this->assertContains('Before install day.', $flat);

        // D-08: a fixed bucket with required=true but no notes text must
        // still surface via a synthetic label (never silently dropped).
        $hasNetworkReadinessLabel = false;
        foreach ($flat as $entry) {
            if (str_contains(mb_strtolower($entry), 'network')) {
                $hasNetworkReadinessLabel = true;
            }
        }
        $this->assertTrue($hasNetworkReadinessLabel);
    }

    public function test_flatten_client_responsibilities_missing_both_keys_returns_empty_array(): void
    {
        $this->assertSame([], StructuralGateVocabulary::flattenClientResponsibilities([]));
    }

    public function test_flatten_client_responsibilities_never_throws_on_malformed_expanded(): void
    {
        $result = StructuralGateVocabulary::flattenClientResponsibilities([
            'client_responsibilities_expanded' => 'not-an-array',
        ]);

        $this->assertSame([], $result);
    }

    public function test_flatten_areas_reads_gate_private_mirror_key_first(): void
    {
        $result = StructuralGateVocabulary::flattenAreas([
            'areas_for_gate' => ['Boardroom', 'Boardroom', ' Reception ', ''],
            'rooms' => ['Should Not Be Used'],
        ]);

        $this->assertSame(['Boardroom', 'Reception'], $result);
    }

    public function test_flatten_areas_falls_back_to_rooms_when_no_gate_mirror(): void
    {
        $result = StructuralGateVocabulary::flattenAreas([
            'rooms' => ['Studio 1', 'Studio 2'],
        ]);

        $this->assertSame(['Studio 1', 'Studio 2'], $result);
    }

    public function test_flatten_areas_falls_back_to_rooms_when_gate_mirror_is_present_but_empty(): void
    {
        // CR-01 regression (code review 2026-09-14). This fixture shape is the
        // one PRODUCTION actually sends: all three mirror sites
        // (RamsController.php:609, RamsBuilderService.php:307 and :972) always
        // SET areas_for_gate, to [] when room_overviews is empty. They never
        // omit the key, which is what
        // test_flatten_areas_falls_back_to_rooms_when_no_gate_mirror above
        // exercises.
        //
        // With a `??` chain this returned [] and GATE-02 passed vacuously
        // despite real rooms being present — the "gate reports clean because
        // it cannot see its data" failure the phase exists to prevent. If this
        // test ever goes red, GATE-02 has gone blind on live documents; fix
        // the source, do not relax the assertion.
        $result = StructuralGateVocabulary::flattenAreas([
            'areas_for_gate' => [],
            'rooms' => ['Studio 1', 'Studio 2'],
        ]);

        $this->assertSame(['Studio 1', 'Studio 2'], $result);
    }

    public function test_flatten_areas_returns_empty_when_both_mirror_and_rooms_are_empty(): void
    {
        // The genuinely vacuous case stays vacuous: a manual/form-only RAMS
        // with no rooms at all must still pass GATE-02 (ROADMAP criterion 2's
        // "passes vacuously on zero areas"). The CR-01 fix must not turn this
        // into a false positive.
        $this->assertSame([], StructuralGateVocabulary::flattenAreas([
            'areas_for_gate' => [],
            'rooms' => [],
        ]));
    }

    public function test_flatten_areas_ignores_room_overviews_key(): void
    {
        // RESEARCH Finding 3 / Assumption A2 — room_overviews is the trigger
        // for the dormant ensurePerRoomBullets() AI path; must never be read.
        $result = StructuralGateVocabulary::flattenAreas([
            'room_overviews' => ['Some Room'],
        ]);

        $this->assertSame([], $result);
    }

    public function test_flatten_areas_returns_empty_array_when_no_source_present(): void
    {
        $this->assertSame([], StructuralGateVocabulary::flattenAreas([]));
    }

    public function test_class_makes_no_database_query(): void
    {
        // The class must never CALL HazardIncludeWhenResolver::resolve()
        // (which queries HazardTemplate models) — mentioning the method
        // name in a docblock (explaining why it must not be called) is
        // fine; an actual call site or object instantiation is not.
        $source = file_get_contents(app_path('Services/Rams/StructuralGateVocabulary.php'));

        $this->assertStringNotContainsString('->resolve(', $source);
        $this->assertStringNotContainsString('new HazardIncludeWhenResolver', $source);
        $this->assertStringNotContainsString('HazardTemplate::', $source);
        $this->assertStringNotContainsString('DB::', $source);
    }
}
