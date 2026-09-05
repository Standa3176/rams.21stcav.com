<?php

namespace Tests\Unit\Services\Rams;

use App\Services\Rams\ControlTextRuleViolations;
use App\Services\Rams\DisplayLiftPolicy;
use Database\Seeders\HazardTemplateSeeder;
use Tests\TestCase;

/**
 * Plan 27-08 Task 1 — the detector registry that lets reviewedToRisk() replace
 * reviewed control text which breaches a settled house rule.
 *
 * The two self-check tests at the bottom are load-bearing, not decoration. A
 * false positive silently overwrites an engineer's deliberate wording on a live
 * safety document (T-27-08-01, HIGH). If the app can flag its own library or
 * policy output, it will flag correct engineer text too.
 */
class ControlTextRuleViolationsTest extends TestCase
{
    // ── kg_threshold (RULE-13) ───────────────────────────────────────────────

    public function test_detects_the_live_kg_threshold_from_rams_100(): void
    {
        $this->assertSame(
            'kg_threshold',
            ControlTextRuleViolations::detect(
                'Use mechanical aids (sack trucks, lifting trolleys) for items over 20 kg.',
            ),
        );
    }

    public function test_detects_kg_threshold_variants(): void
    {
        foreach ([
            'Team lift for items over 20 kg; mechanical aids used where available',
            'Single person lift acceptable if under 20 kg. Check weight before lifting.',
            'Two persons required above 25kg.',
        ] as $control) {
            $this->assertSame(
                'kg_threshold',
                ControlTextRuleViolations::detect($control),
                "Expected a kg_threshold violation in: {$control}",
            );
        }
    }

    public function test_indicative_weight_is_permitted_not_a_violation(): void
    {
        // house-rules.md explicitly WANTS weights given as indicative,
        // confirm-at-survey. Flagging these would be the false positive that
        // T-27-08-01 warns about.
        foreach ([
            'Panel weighs approximately 32 kg — confirm at survey.',
            'Rack weighs circa 60 kg; weight to be confirmed at survey.',
        ] as $control) {
            $this->assertNull(
                ControlTextRuleViolations::detect($control),
                "Indicative weight must not be flagged: {$control}",
            );
        }
    }

    // ── size_conditional_lift (RULE-02) ──────────────────────────────────────

    public function test_detects_the_live_size_conditional_lift_from_rams_100(): void
    {
        $this->assertSame(
            'size_conditional_lift',
            ControlTextRuleViolations::detect(
                'Team lift required for screens and equipment over 40" — minimum two persons.',
            ),
        );
    }

    // ── clean lines ──────────────────────────────────────────────────────────

    public function test_ordinary_control_text_is_clean(): void
    {
        foreach ([
            'Wear appropriate gloves and safety footwear at all times.',
            'Pre-plan the route and clear all access paths before moving equipment.',
            'Conduct a task-specific manual handling assessment prior to every lift.',
            'Site-specific: use the goods lift in Block C only.',
        ] as $control) {
            $this->assertNull(
                ControlTextRuleViolations::detect($control),
                "Clean control text must not be flagged: {$control}",
            );
        }
    }

    public function test_detect_all_maps_offending_indexes_only(): void
    {
        $violations = ControlTextRuleViolations::detectAll([
            'Wear appropriate gloves and safety footwear at all times.',
            'Use mechanical aids (sack trucks, lifting trolleys) for items over 20 kg.',
            'Pre-plan the route and clear all access paths before moving equipment.',
            'Team lift required for screens and equipment over 40" — minimum two persons.',
        ]);

        $this->assertSame([1, 3], array_keys($violations));
        $this->assertSame('kg_threshold', $violations[1]);
        $this->assertSame('size_conditional_lift', $violations[3]);
    }

    public function test_detect_all_returns_empty_for_a_clean_list(): void
    {
        $this->assertSame([], ControlTextRuleViolations::detectAll([
            'Wear appropriate gloves and safety footwear at all times.',
            'Take regular breaks to avoid fatigue during prolonged lifting tasks.',
        ]));
    }

    // ── SELF-CHECKS — the app must never reject its own output ───────────────

    public function test_no_seeded_library_control_is_ever_flagged(): void
    {
        $seeder = new HazardTemplateSeeder();
        $method = new \ReflectionMethod($seeder, 'standardHazards');
        $method->setAccessible(true);

        $flagged = [];
        $checked = 0;

        foreach ($method->invoke($seeder) as $hazard) {
            foreach ((array) ($hazard['controls'] ?? []) as $control) {
                $checked++;
                $violation = ControlTextRuleViolations::detect((string) $control);

                if ($violation !== null) {
                    $flagged[] = "[{$violation}] {$hazard['name']}: {$control}";
                }
            }
        }

        $this->assertGreaterThan(50, $checked, 'Expected to scan the full 18-hazard library.');
        $this->assertSame(
            [],
            $flagged,
            "The detectors flagged the app's own corrected library text. Either the library "
            . "has regressed or a detector is too aggressive — do not silence this by "
            . "narrowing the test.\n" . implode("\n", $flagged),
        );
    }

    public function test_no_display_lift_policy_sentence_is_ever_flagged(): void
    {
        foreach ([10.1, 14, 32, 43, 54, 55, 65, 75, 90, 91, 98, 110] as $inches) {
            $resolved = DisplayLiftPolicy::forSize((float) $inches);
            $sentence = (string) ($resolved['sentence'] ?? '');

            if ($sentence === '') {
                continue; // the no-row exclusion
            }

            $this->assertNull(
                ControlTextRuleViolations::detect($sentence),
                "DisplayLiftPolicy's own {$inches}in sentence was flagged: {$sentence}",
            );
        }

        $this->assertNull(ControlTextRuleViolations::detect(DisplayLiftPolicy::genericBandSummary()));
        $this->assertNull(ControlTextRuleViolations::detect(DisplayLiftPolicy::compactBandSummary()));
        $this->assertNull(ControlTextRuleViolations::detect(DisplayLiftPolicy::wallMountRemovalStatement()));
    }
}
