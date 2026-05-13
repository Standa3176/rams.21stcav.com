<?php

namespace Tests\Unit\Services\Rams;

use App\Services\Rams\RamsComplianceUpgradeService;
use Tests\TestCase;

/**
 * Phase 22.1 Plan 05 Task 3 — read-through cache and D-01 read-site lock.
 *
 * Locks three invariants on RamsComplianceUpgradeService:
 *
 *   D-06 cache hit: upgradeScopeOfWorks() short-circuits when
 *     reviewed_data.scope_of_works_bullets is already a non-empty array.
 *     This is the approve-time-persistence pattern — the bullets get
 *     computed once at approve and locked into reviewed_data so the
 *     render-time pipeline does NOT recompute them. Backward-compatible:
 *     pre-cleanup records (no persisted bullets) still run the heuristic
 *     at render time.
 *
 *   D-06 cache miss: when scope_of_works_bullets is absent or empty, the
 *     heuristic still produces bullets (covers pre-cleanup records).
 *
 *   D-01 read-site lock: ensurePerRoomBullets() must NOT fall back to
 *     $room['description'] when works_summary + overview are empty. After
 *     Plan 22.1-03 trimmed the room_overviews schema to 4 keys (room /
 *     overview / works_summary / solution_type_id), `description` is dead
 *     and reading it pollutes the heuristic with legacy AI prose.
 *
 * Reflection is used to exercise the private static methods directly —
 * mirrors the existing MethodStatementServiceScopeTest pattern.
 *
 * @see app/Services/Rams/RamsComplianceUpgradeService.php
 * @see .planning/phases/22.1-rams-scope-room-data-consolidation/22.1-05-PLAN.md
 */
class RamsComplianceUpgradeServiceCacheTest extends TestCase
{
    private function invokePrivateStatic(string $method, array $args = []): mixed
    {
        $m = new \ReflectionMethod(RamsComplianceUpgradeService::class, $method);
        $m->setAccessible(true);
        return $m->invoke(null, ...$args);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // D-06: cache hit — persisted bullets short-circuit the heuristic
    // ══════════════════════════════════════════════════════════════════════════

    public function test_persisted_bullets_short_circuit_heuristic(): void
    {
        $persisted = [
            'Custom locked bullet A — approved at PM review',
            'Custom locked bullet B — approved at PM review',
        ];

        $result = $this->invokePrivateStatic('upgradeScopeOfWorks', [[
            'scope_of_works_bullets' => $persisted,
            // Provide heuristic inputs that WOULD overwrite the persisted
            // bullets if the cache hit guard were missing — proves the guard.
            'rooms' => [['name' => 'Boardroom', 'activities' => [], 'equipment' => [['type' => 'display']]]],
            'cable_requirements' => [],
            'quote' => ['line_items' => [['description' => 'Sony 98" display', 'qty' => 1]]],
        ]]);

        $this->assertSame(
            $persisted,
            $result['scope_of_works_bullets'],
            'Phase 22.1 D-06: persisted scope_of_works_bullets must short-circuit the heuristic — bullets locked at approve-time must not be overwritten at render-time.',
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // D-06: cache miss — heuristic still runs for pre-cleanup records
    // ══════════════════════════════════════════════════════════════════════════

    public function test_empty_persisted_bullets_runs_heuristic(): void
    {
        $result = $this->invokePrivateStatic('upgradeScopeOfWorks', [[
            // No scope_of_works_bullets — heuristic should produce some.
            'rooms' => [['name' => 'Boardroom', 'activities' => [], 'equipment' => [['type' => 'display']]]],
            'cable_requirements' => [],
            'quote' => ['line_items' => [['description' => 'Sony 98" display', 'qty' => 1]]],
        ]]);

        $this->assertNotEmpty(
            $result['scope_of_works_bullets'] ?? [],
            'Phase 22.1 D-06 backward compat: pre-cleanup records (no persisted bullets) still get bullets via the heuristic at render time.',
        );
    }

    public function test_array_persisted_but_empty_runs_heuristic(): void
    {
        $result = $this->invokePrivateStatic('upgradeScopeOfWorks', [[
            'scope_of_works_bullets' => [],
            'rooms' => [['name' => 'Boardroom', 'activities' => [], 'equipment' => [['type' => 'display']]]],
            'cable_requirements' => [],
            'quote' => ['line_items' => [['description' => 'Sony 98" display', 'qty' => 1]]],
        ]]);

        // Empty array IS treated as "no persisted bullets" — heuristic runs.
        $this->assertNotEmpty(
            $result['scope_of_works_bullets'] ?? [],
            'D-06: an empty persisted bullets array must NOT short-circuit — only a non-empty persisted array is a cache hit.',
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // D-01: ensurePerRoomBullets drops the $room['description'] fallback
    // ══════════════════════════════════════════════════════════════════════════

    public function test_ensure_per_room_bullets_does_not_fall_back_to_description(): void
    {
        // Seed a room with ONLY the legacy `description` key populated.
        // After D-01: the canonical schema is overview / works_summary only.
        // ensurePerRoomBullets must NOT read $room['description'] as a fallback.
        //
        // The heuristic's prose-source check requires strlen($overview) >= 40,
        // so we put a long string in `description` to ensure it WOULD be read
        // if the fallback were still in place — and then assert that the room
        // produces NO bullet derived from that string.
        $legacyProse = 'LEGACY DESCRIPTION FIELD: this AI prose should NOT seed the per-room bullet heuristic because the description key is no longer canonical after Phase 22.1-03 trimmed the room_overviews schema to 4 keys.';

        $result = $this->invokePrivateStatic('ensurePerRoomBullets', [[
            'room_overviews' => [[
                'room'          => 'Boardroom',
                // overview + works_summary intentionally empty — only `description`
                // has content. Old fallback chain: works_summary OR overview OR description.
                // New chain (post-D-01): works_summary OR overview only.
                'overview'      => '',
                'works_summary' => '',
                'description'   => $legacyProse,
            ]],
        ]]);

        // Whatever shape ensurePerRoomBullets uses for room_overviews on
        // output, the works_summary for Boardroom must NOT contain the
        // legacy description text (or any derivative produced by the
        // summariser that ran with description as input).
        $rooms = $result['room_overviews'] ?? [];
        $boardroom = $rooms[0] ?? [];
        $summary   = (string) ($boardroom['works_summary'] ?? '');

        $this->assertSame(
            '',
            $summary,
            'Phase 22.1 D-01: ensurePerRoomBullets() must NOT fall back to $room[\'description\'] — Boardroom has empty overview + works_summary so the room must produce no bullets even though `description` is populated.',
        );
    }

    public function test_ensure_per_room_bullets_still_uses_overview_when_present(): void
    {
        // Sanity check the inverse: with `overview` populated the heuristic
        // path still operates normally (works_summary will be populated
        // by the summariser if it returns one — but the key invariant here
        // is that the function does not crash + returns the room key).
        $proseOverview = str_repeat('AV installation across the Boardroom. ', 5);

        $result = $this->invokePrivateStatic('ensurePerRoomBullets', [[
            'room_overviews' => [[
                'room'          => 'Boardroom',
                'overview'      => $proseOverview,
                'works_summary' => '',
            ]],
        ]]);

        $rooms     = $result['room_overviews'] ?? [];
        $boardroom = $rooms[0] ?? [];
        $this->assertSame('Boardroom', $boardroom['room'] ?? null,
            'Sanity: ensurePerRoomBullets() preserves the room key when overview is non-empty.');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // D-06: the public approve-time compute method exists and runs the heuristic
    // ══════════════════════════════════════════════════════════════════════════

    public function test_compute_scope_of_works_bullets_for_approve_returns_array(): void
    {
        $bullets = RamsComplianceUpgradeService::computeScopeOfWorksBulletsForApprove(
            reviewedData: [
                'equipment' => [
                    ['description' => 'Sony 98" display', 'qty' => 1],
                ],
                'cable_requirements' => [],
            ],
            projectContext: [
                'rooms' => [
                    ['room' => 'Boardroom', 'works_summary' => '- Install display'],
                ],
            ],
        );

        $this->assertIsArray(
            $bullets,
            'Phase 22.1 D-06: computeScopeOfWorksBulletsForApprove() must return an array of bullet strings.',
        );
    }
}
