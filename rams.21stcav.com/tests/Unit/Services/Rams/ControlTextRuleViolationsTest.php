<?php

namespace Tests\Unit\Services\Rams;

use App\Services\Rams\ControlTextRuleViolations;
use App\Services\Rams\DisplayLiftPolicy;
use App\Services\Rams\LegacyHazardNameFoldMap;
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

    // ── ffp2 (RULE-01) ────────────────────────────────────────────────────────

    public function test_detects_bare_ffp2_token(): void
    {
        $this->assertSame(
            'ffp2',
            ControlTextRuleViolations::detect(
                'Dust mask (FFP2) worn when accessing ceiling voids.',
            ),
        );
    }

    public function test_ffp2_or_ffp3_hedge_is_still_flagged(): void
    {
        // RiskMatrixService.php:133 — offering FFP2 as an acceptable
        // alternative is itself the defect, not a sentence to spare.
        $this->assertSame(
            'ffp2',
            ControlTextRuleViolations::detect(
                'Wear FFP2 or FFP3 dust masks during all drilling and cutting operations.',
            ),
        );
    }

    public function test_ffp3_alone_is_never_flagged(): void
    {
        // HazardTemplateSeeder.php:294 — the seeder's already-correct
        // hazard #11 sentence, verbatim.
        $this->assertNull(
            ControlTextRuleViolations::detect(
                'FFP3 dust mask and safety glasses worn during all drilling and cutting. '
                . 'All operatives face-fit tested.',
            ),
        );
    }

    // ── confined_space (GATE-07) ─────────────────────────────────────────────

    public function test_detects_confined_space_affirmative_constructions(): void
    {
        foreach ([
            'This is a confined space.',
            'Confined space entry procedures apply.',
            'A confined space permit is required before entry.',
            'Refer to ACOP L101 for confined space entry.',
            'Confined Space',
        ] as $control) {
            $this->assertSame(
                'confined_space',
                ControlTextRuleViolations::detect($control),
                "Expected a confined_space violation in: {$control}",
            );
        }
    }

    public function test_confined_space_negation_is_clean(): void
    {
        // HazardTemplateSeeder.php:220 — hazard #7's exact sentence,
        // copied verbatim, never paraphrased.
        $this->assertNull(
            ControlTextRuleViolations::detect(
                'Confirm ventilation and safe access before entering ceiling voids, comms rooms '
                . 'or enclosures. These are not classified as confined spaces, but access is '
                . 'restricted and is treated as a controlled activity.',
            ),
        );
    }

    public function test_fold_map_target_is_never_flagged_as_confined_space(): void
    {
        foreach (LegacyHazardNameFoldMap::all() as $canonicalName) {
            $this->assertNull(
                ControlTextRuleViolations::detect($canonicalName),
                "Fold-map canonical name was flagged as confined_space: {$canonicalName}",
            );
        }
    }

    public function test_ai_extraction_prompt_confined_spaces_example_is_flagged_when_affirmative(): void
    {
        // Modelled on PromptBuilderService::buildFromFiles()'s "confined
        // spaces" example category — proves the gate would catch what the
        // live AI-extraction path could plausibly produce, without editing
        // the prompt itself.
        $this->assertSame(
            'confined_space',
            ControlTextRuleViolations::detect(
                'Confined spaces present in ceiling voids and enclosures — confined space entry '
                . 'procedures required.',
            ),
        );
    }

    public function test_hyphenated_confined_space_is_flagged(): void
    {
        // Revision 1 checker finding: proves the hyphen/whitespace
        // normalisation step actually runs. This test FAILS against a
        // detector that omits it, so it is non-vacuous by construction.
        $this->assertSame(
            'confined_space',
            ControlTextRuleViolations::detect('Confined-space entry procedures apply.'),
        );
    }

    public function test_bare_hyphenated_name_with_no_other_keyword_is_flagged(): void
    {
        // Revision 2 checker finding: the ONE combination (hyphenated AND
        // carrying no affirmative keyword) that reaches the bare-substring
        // fallback — the only fixture proving the fallback itself reads the
        // normalised string rather than the merely-lowercased one. Without
        // this case an implementation can pass every other test and still
        // ship the gap.
        $this->assertSame(
            'confined_space',
            ControlTextRuleViolations::detect('Confined-Space'),
        );
    }

    public function test_bare_hazard_name_labels_are_flagged(): void
    {
        // Short LABELS, not prose — the literal strings reachable through
        // the free-text hazard-name inputs at
        // resources/views/project-packages/review.blade.php:1875 / :2484
        // and resources/views/rams/quote-review.blade.php:808. Plan 28-06
        // Task 1 feeds the name field to this detector; this test proves
        // the classifier copes with that input shape.
        foreach (['Confined Space', 'Confined Spaces', 'Confined Space Entry'] as $name) {
            $this->assertSame(
                'confined_space',
                ControlTextRuleViolations::detect($name),
                "Expected a confined_space violation for the bare name: {$name}",
            );
        }
    }

    public function test_coordination_boilerplate_permit_list_is_documented_as_out_of_scope(): void
    {
        // DocxBuilderService.php:1903 §6.11 — this sentence DOES classify as
        // confined_space, and that is intentional and harmless: it is
        // rendered as direct DOCX boilerplate and never enters
        // $data['hazards'], so Plan 28-06's gate never scans it. It names a
        // permit category the Principal Contractor may operate on their
        // site; it does not assert that a 21CAV-controlled space IS a
        // confined space. See this plan's <scope_decisions> block. This
        // test exists so that if this boilerplate is ever routed through
        // hazard data in future, the behaviour is already known rather than
        // surprising.
        $this->assertSame(
            'confined_space',
            ControlTextRuleViolations::detect(
                'Principal Contractor — obtain permits-to-work (ceiling access, hot works, roof '
                . 'access, confined-space entry) from the Principal Contractor before commencing '
                . 'the relevant activity.',
            ),
        );
    }

    // ── hot_works_assertion (GATE-13, Phase 30 Plan 07) ──────────────────────

    public function test_detects_a_narrative_no_hot_works_absence_assertion(): void
    {
        $this->assertSame(
            'hot_works_assertion',
            ControlTextRuleViolations::detect('No hot works will be undertaken on this site.'),
        );
    }

    public function test_detects_hot_works_absence_assertion_variants(): void
    {
        foreach ([
            'No hot works are to be carried out at any point during this project.',
            'Hot works will not be undertaken by any operative on this contract.',
            'Hot works are not required for this scope of works.',
            'No soldering will be undertaken on site.',
        ] as $control) {
            $this->assertSame(
                'hot_works_assertion',
                ControlTextRuleViolations::detect($control),
                "Expected a hot_works_assertion violation in: {$control}",
            );
        }
    }

    public function test_hot_works_permit_under_conditional_wording_is_not_flagged(): void
    {
        $this->assertNull(
            ControlTextRuleViolations::detect('Hot works will be carried out under permit.'),
        );
    }

    public function test_addPermitAndIsolations_own_conditional_line_is_never_flagged(): void
    {
        // T-30-16 — the app's OWN unconditional-sounding-but-actually-
        // conditional permit line from
        // RamsComplianceUpgradeService::addPermitAndIsolation() ships on
        // EVERY document. This is the load-bearing regression proof: if
        // this ever starts matching, GATE-13 (Plan 30-07) fires
        // corpus-wide the moment it is armed.
        $this->assertNull(
            ControlTextRuleViolations::detect(
                'Hot works permit required if soldering or heat-shrink operations are performed on site',
            ),
        );
    }

    public function test_ambiguous_hot_works_mention_is_not_flagged(): void
    {
        // Cannot confidently classify — T-27-08-01's conservative-by-
        // construction contract: a miss, never a guess.
        foreach ([
            'The team briefly discussed hot works scheduling for next month.',
            'Hot works were not scheduled for today.',
        ] as $control) {
            $this->assertNull(
                ControlTextRuleViolations::detect($control),
                "Ambiguous hot-works mention must not be flagged: {$control}",
            );
        }
    }

    public function test_seeded_fire_and_evacuation_no_hot_works_scope_line_is_never_flagged(): void
    {
        // database/seeders/HazardTemplateSeeder.php:370 — "No hot works of
        // any kind included in this scope." ships on EVERY generated RAMS
        // (the "Fire and evacuation" hazard is tier-1 "always"-included).
        // This sentence IS a genuine "no hot works" statement in plain
        // English, but this detector must NOT flag it: {@see
        // self::detect()} feeds RamsBuilderService::reviewedToRisk()'s
        // Tier-1 house-rule-violation replacement path, which FORCES a
        // matched hazard's reviewed controls back to the template text —
        // appropriate for an actual rule violation (kg_threshold, ffp2,
        // confined_space), never appropriate for a scope-exclusion
        // statement that is not itself a violation of anything. See
        // HOT_WORKS_ABSENCE_ASSERTIONS's docblock for the narrowness this
        // proves.
        $this->assertNull(
            ControlTextRuleViolations::detect('No hot works of any kind included in this scope.'),
        );
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
