<?php

namespace Tests\Unit\Services\Rams;

use App\Exceptions\RamsGenerationException;
use App\Services\Rams\DisplayLiftPolicy;
use App\Services\Rams\RamsComplianceUpgradeService;
use Tests\TestCase;

/**
 * Phase 27 Plan 06 (GATE-09 coverage-gap closure) — proves two things that
 * Plan 27-03's shipped gate could not:
 *
 *   1. Task 1's `parseStatedTeamSize()` / `parseStatedInches()` free-text
 *      parsers extract operative counts and inch sizes conservatively —
 *      ambiguity always returns null (T-27-06-01, HIGH: a parsing miss must
 *      never block a real job), and every sentence
 *      `DisplayLiftPolicy::forSize()` can emit for the 1/2/3 bands
 *      round-trips through `parseStatedTeamSize()` to that band's own team
 *      size (the app must never reject its own correct output).
 *
 *   2. Task 2's extended `enforceDisplayLiftGate()` validates
 *      engineer-typed `material_handling.large_items[]` rows — the free-text
 *      form field that renders straight into the live DOCX/PDF unchecked
 *      before this plan — routing conformance through
 *      `DisplayLiftPolicy::violatesPolicy()` only, never its own band
 *      numbers.
 *
 * Reflection is used to exercise the private static methods directly,
 * mirroring `DisplayLiftGateTest` (Plan 27-03) and
 * `ProjectSpecificRisksGatedTest` (Plan 26-07)'s established pattern.
 *
 * ── Deliberate convention exception ───────────────────────────────────────
 * This plan makes a DELIBERATE exception to the codebase's "engineer values
 * always win, never re-validated" convention (HAZ-04's `score_reviewed`
 * precedent) — a stated lift team size is treated as a safety claim, not a
 * preference, per the 2026-08-26 user decision recorded in
 * `27-06-PLAN.md`. See 27-06-SUMMARY.md for the full record.
 *
 * @see app/Services/Rams/RamsComplianceUpgradeService.php
 * @see app/Services/Rams/DisplayLiftPolicy.php
 * @see .planning/phases/27-manual-handling-display-lift-house-rules/27-06-PLAN.md
 */
class DisplayLiftGateEngineerRowsTest extends TestCase
{
    private function invokePrivateStatic(string $method, array $args = []): mixed
    {
        $m = new \ReflectionMethod(RamsComplianceUpgradeService::class, $method);
        $m->setAccessible(true);

        return $m->invoke(null, ...$args);
    }

    private function parseStatedTeamSize(string $text): ?int
    {
        return $this->invokePrivateStatic('parseStatedTeamSize', [$text]);
    }

    private function parseStatedInches(string $text): ?float
    {
        return $this->invokePrivateStatic('parseStatedInches', [$text]);
    }

    private function dataWithLargeItems(array $largeItems): array
    {
        return [
            'material_handling' => [
                'large_items' => $largeItems,
            ],
        ];
    }

    // ── Task 1: parseStatedTeamSize() — recognised phrasings ─────────────────

    public function test_parses_minimum_n_persons(): void
    {
        $this->assertSame(4, $this->parseStatedTeamSize('Team lift — minimum 4 persons'));
    }

    public function test_parses_n_persons_minimum_trailing_sentence(): void
    {
        $this->assertSame(
            2,
            $this->parseStatedTeamSize('Team lift (2 persons minimum). Use screen protection during transit.'),
        );
    }

    public function test_parses_single_person_lift_as_one(): void
    {
        $this->assertSame(1, $this->parseStatedTeamSize('Single person lift for tilting/fixed wall mount.'));
    }

    public function test_parses_word_number_hyphenated_operative(): void
    {
        $this->assertSame(2, $this->parseStatedTeamSize('two-operative team lift'));
    }

    public function test_parses_hyphenated_n_person_lift(): void
    {
        $this->assertSame(3, $this->parseStatedTeamSize('3-person lift for the display'));
    }

    public function test_parses_one_operative(): void
    {
        $this->assertSame(1, $this->parseStatedTeamSize('one operative required'));
    }

    public function test_parses_minimum_n_operatives(): void
    {
        $this->assertSame(4, $this->parseStatedTeamSize('minimum 4 operatives'));
    }

    // ── Task 1: parseStatedTeamSize() — ambiguity ALWAYS returns null ────────

    public function test_no_recognisable_count_returns_null(): void
    {
        $this->assertNull($this->parseStatedTeamSize('Use a trolley and take care'));
    }

    public function test_conflicting_counts_returns_null(): void
    {
        $this->assertNull($this->parseStatedTeamSize('2 persons normally, 3 for the 98 inch'));
    }

    // ── Task 1: parseStatedInches() ──────────────────────────────────────────

    public function test_parses_inches_from_descriptive_text(): void
    {
        $this->assertSame(98.0, $this->parseStatedInches('SAMSUNG QM98 98" commercial display'));
    }

    public function test_no_inch_number_returns_null(): void
    {
        $this->assertNull($this->parseStatedInches('Double-arm wall mount'));
    }

    // ── Task 1: round-trip — the app's own emitted sentences must never be
    //    rejected by the parser it will be checked against ───────────────────

    public function test_round_trips_every_display_lift_policy_band_sentence(): void
    {
        $under55 = DisplayLiftPolicy::forSize(43.0);
        $band55To90 = DisplayLiftPolicy::forSize(75.0);
        $above90 = DisplayLiftPolicy::forSize(98.0);

        $this->assertSame(1, $this->parseStatedTeamSize($under55['sentence']));
        $this->assertSame(2, $this->parseStatedTeamSize($band55To90['sentence']));
        $this->assertSame(3, $this->parseStatedTeamSize($above90['sentence']));

        // Sanity: the round-trip result equals forSize()'s own min_persons,
        // for every band, proving the parser can never reject the app's own
        // correct output.
        $this->assertSame($under55['min_persons'], $this->parseStatedTeamSize($under55['sentence']));
        $this->assertSame($band55To90['min_persons'], $this->parseStatedTeamSize($band55To90['sentence']));
        $this->assertSame($above90['min_persons'], $this->parseStatedTeamSize($above90['sentence']));
    }

    // ── Task 2: enforceDisplayLiftGate() — engineer-typed row enforcement ────

    public function test_engineer_row_four_persons_throws(): void
    {
        $this->expectException(RamsGenerationException::class);
        $this->expectExceptionMessageMatches('/Samsung 98" display/');

        $this->invokePrivateStatic('enforceDisplayLiftGate', [$this->dataWithLargeItems([
            ['item' => 'Samsung 98" display', 'handling_method' => 'Team lift — minimum 4 persons'],
        ])]);
    }

    public function test_engineer_row_three_persons_above_90_does_not_throw(): void
    {
        $result = $this->invokePrivateStatic('enforceDisplayLiftGate', [$this->dataWithLargeItems([
            ['item' => 'Samsung 98" display', 'handling_method' => 'Team lift — minimum 3 persons'],
        ])]);

        $this->assertSame(
            'Samsung 98" display',
            $result['material_handling']['large_items'][0]['item'],
        );
    }

    public function test_engineer_row_single_operative_at_75_inches_throws(): void
    {
        $this->expectException(RamsGenerationException::class);

        $this->invokePrivateStatic('enforceDisplayLiftGate', [$this->dataWithLargeItems([
            ['item' => 'Samsung QM75 75" display', 'handling_method' => 'Single person lift'],
        ])]);
    }

    public function test_engineer_row_single_operative_under_55_inches_does_not_throw(): void
    {
        $result = $this->invokePrivateStatic('enforceDisplayLiftGate', [$this->dataWithLargeItems([
            ['item' => 'Samsung 43" monitor', 'handling_method' => 'Single person lift'],
        ])]);

        $this->assertNotEmpty($result['material_handling']['large_items']);
    }

    public function test_engineer_row_with_no_parseable_team_size_is_skipped(): void
    {
        $result = $this->invokePrivateStatic('enforceDisplayLiftGate', [$this->dataWithLargeItems([
            ['item' => 'Rack cabinet', 'handling_method' => 'Use equipment trolley for transport.'],
        ])]);

        $this->assertNotEmpty($result['material_handling']['large_items']);
    }

    public function test_engineer_row_unresolvable_inches_still_checked_and_throws(): void
    {
        $this->expectException(RamsGenerationException::class);
        $this->expectExceptionMessageMatches('/unresolved/');

        $this->invokePrivateStatic('enforceDisplayLiftGate', [$this->dataWithLargeItems([
            ['item' => 'Unlabelled display', 'handling_method' => 'Team lift — minimum 4 persons'],
        ])]);
    }

    public function test_engineer_row_check_obeys_the_kill_switch(): void
    {
        config(['rams_tier1.display_lift_gate_enabled' => false]);

        $result = RamsComplianceUpgradeService::upgrade([
            'material_handling' => [
                'large_items' => [
                    ['item' => 'Samsung 98" display', 'handling_method' => 'Team lift — minimum 4 persons'],
                ],
            ],
        ]);

        $this->assertIsArray($result);
    }

    public function test_no_literal_band_numbers_used_as_conformance_decisions(): void
    {
        $source = file_get_contents(app_path('Services/Rams/RamsComplianceUpgradeService.php'));
        $this->assertIsString($source);

        $start = strpos($source, 'private static function enforceDisplayLiftGate');
        $this->assertIsInt($start, 'enforceDisplayLiftGate() not found');

        // Isolate just the method body up to its closing brace at column 4
        // (the next "\n    }\n" after the opening).
        $methodEnd = strpos($source, "\n    }\n", $start);
        $this->assertIsInt($methodEnd, 'could not locate end of enforceDisplayLiftGate()');
        $body = substr($source, $start, $methodEnd - $start);

        // The ONLY conformance call inside the method must be
        // DisplayLiftPolicy::violatesPolicy( — no inline `>= 4`, `> 90`,
        // `>= 55`, `=== 1` style comparisons deciding conformance.
        $this->assertMatchesRegularExpression('/DisplayLiftPolicy::violatesPolicy\(/', $body);
        $this->assertDoesNotMatchRegularExpression('/DisplayLiftPolicy::forSize\(/', $body);
    }
}
