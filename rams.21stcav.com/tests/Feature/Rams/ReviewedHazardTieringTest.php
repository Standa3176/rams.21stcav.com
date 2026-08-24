<?php

namespace Tests\Feature\Rams;

use App\Jobs\BuildRamsDocumentJob;
use App\Models\RamsDocument;
use App\Models\User;
use App\Services\RamsBuilderService;
use Database\Seeders\HazardTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 26 Plan 07 (HAZ-02 gap closure) — the coverage hole 26-VERIFICATION.md
 * identified: no test in the 2265-strong pre-Plan-07 suite generated through
 * RamsBuilderService::runFromReview() with a pre-populated reviewed hazard
 * list. Live evidence (21CQ30960 / RAMS 96) proved this path never reached
 * HazardIncludeWhenResolver — a genuinely fresh regeneration of an
 * already-reviewed project produced 11 old-vocabulary hazards with none of
 * the always/confirm-tier hazards present.
 *
 * This test regenerates through the SAME job (BuildRamsDocumentJob) the
 * "Generate RAMS" / regenerate button dispatches — real seeded DB, real
 * RiskTemplateResolverService, real HazardIncludeWhenResolver. AI is mocked
 * via Http::fake() (MethodStatementGeneratorService calls out to Claude) —
 * no live AI call, per plan constraint (no AI decides hazard inclusion,
 * CLAUDE.md line 12).
 */
class ReviewedHazardTieringTest extends TestCase
{
    use RefreshDatabase;

    /** Absolute paths of DOCX files written during the test run (cleaned up in tearDown). */
    private array $generatedFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HazardTemplateSeeder::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->generatedFiles as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function fakeClaudeResponse(): void
    {
        Http::fake(['*' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode(['phases' => [
                ['title' => 'Phase 1: Pre-works', 'steps' => ['Site induction', 'PPE check']],
            ]])]],
            'stop_reason' => 'end_turn',
        ], 200)]);
    }

    /**
     * The 5 reviewed hazards, in the old, pre-Phase-26 vocabulary matching
     * the live 21CQ30960 evidence shape. Explicit numeric scores + controls
     * on every row so the "reviewed values always win" contract is asserted
     * independent of the hazard library's fuzzy-match behaviour.
     */
    private function makeReviewedHazards(): array
    {
        return [
            [
                'hazard'           => 'Working at Height',
                'pre_likelihood'   => 3,
                'pre_severity'     => 4,
                'control_measures' => ['Use podium steps, maintain 3-point contact'],
                'post_likelihood'  => 2,
                'post_severity'    => 3,
            ],
            [
                'hazard'           => 'Manual Handling',
                'pre_likelihood'   => 3,
                'pre_severity'     => 3,
                'control_measures' => ['Team lift for items over 20 kg'],
                'post_likelihood'  => 1,
                'post_severity'    => 2,
            ],
            [
                'hazard'           => 'Electrical Hazards',
                'pre_likelihood'   => 3,
                'pre_severity'     => 4,
                'control_measures' => ['Isolate supply before connecting'],
                'post_likelihood'  => 1,
                'post_severity'    => 3,
            ],
            [
                'hazard'           => 'Slips, Trips & Falls (Same Level)',
                'pre_likelihood'   => 3,
                'pre_severity'     => 2,
                'control_measures' => ['Keep walkways clear of cable coils'],
                'post_likelihood'  => 1,
                'post_severity'    => 1,
            ],
            [
                'hazard'           => 'Noise and Vibration',
                'pre_likelihood'   => 2,
                'pre_severity'     => 2,
                'control_measures' => ['Hearing protection worn during drilling'],
                'post_likelihood'  => 1,
                'post_severity'    => 1,
            ],
        ];
    }

    private function makeReviewedData(string $scopeOfWorks, ?array $hazards = null): array
    {
        return [
            'project' => [
                'project_name' => 'Reviewed Hazard Tiering Test',
                'quote_ref'    => 'RHT-001',
                'client_name'  => 'Acme Ltd',
                'site_name'    => 'Acme HQ',
                'site_address' => '1 Test Street',
                'site_contact' => 'Jane Doe',
            ],
            'equipment'              => [],
            'activities'             => [],
            'hazards'                => $hazards ?? $this->makeReviewedHazards(),
            'ppe'                    => ['Safety Boots', 'Hi-Vis Vest'],
            'access'                 => [],
            'exclusions'             => [],
            'room_overviews'         => [],
            'method_statement_notes' => '',
            'scope_of_works'         => $scopeOfWorks,
            'works_overview'         => 'A two-sentence project overview with no drilling language.',
            'site_logistics'         => [],
        ];
    }

    private function makeRams(User $user, array $reviewedData): RamsDocument
    {
        return RamsDocument::create([
            'user_id'        => $user->id,
            'project_ref'    => 'RHT-001',
            'project_name'   => 'Reviewed Hazard Tiering Test',
            'client_name'    => 'Acme Ltd',
            'site_address'   => '1 Test Street',
            'status'         => RamsDocument::STATUS_COMPLETED,
            'ai_provider'    => 'claude',
            'ai_model'       => 'claude-sonnet',
            'filename'       => 'pending-reviewed-hazard-tiering.docx',
            'approved_at'    => now(),
            'form_data'      => ['source' => 'quote_upload'],
            'generated_data' => [],
            'reviewed_data'  => $reviewedData,
        ]);
    }

    /** Runs the SAME job the "Generate RAMS" / regenerate button dispatches. */
    private function regenerate(RamsDocument $rams): RamsDocument
    {
        $this->fakeClaudeResponse();
        (new BuildRamsDocumentJob($rams->id))->handle(app(RamsBuilderService::class));

        $fresh = $rams->fresh();

        $candidate = storage_path('app/rams/' . $fresh->filename);
        if (file_exists($candidate)) {
            $this->generatedFiles[] = $candidate;
        }

        return $fresh;
    }

    private function hazardNames(RamsDocument $rams): array
    {
        return array_map(
            static fn (array $row): string => (string) ($row['hazard'] ?? ''),
            (array) ($rams->generated_data['hazards'] ?? []),
        );
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    /**
     * Test 2: a reviewed RAMS with no drilling/fixing language regenerates to
     * 5 reviewed + 4 always-tier + 5 confirm-tier = 14 rows, zero duplicates,
     * reviewed values untouched, none of the three drilling-gated hazards.
     */
    public function test_reviewed_rams_regenerates_with_always_and_confirm_tier_hazards_merged_on_top(): void
    {
        $user = User::factory()->create();
        $rams = $this->makeRams($user, $this->makeReviewedData('Supply and install AV systems.'));

        $rebuilt = $this->regenerate($rams);

        $hazards = (array) ($rebuilt->generated_data['hazards'] ?? []);
        $names   = $this->hazardNames($rebuilt);

        $this->assertCount(14, $hazards, 'expected 5 reviewed + 4 always-tier + 5 confirm-tier = 14 rows');
        $this->assertSame(14, count(array_unique($names)), 'zero duplicate hazard names');

        // The 5 originally-reviewed rows survive, untouched.
        $reviewedByName = [];
        foreach ($hazards as $row) {
            $reviewedByName[$row['hazard']] = $row;
        }

        $this->assertSame(4, $reviewedByName['Working at Height']['post_severity'] ?? null, 'reviewed row untouched — pre-severity/controls check below');
        $this->assertSame(['Use podium steps, maintain 3-point contact'], $reviewedByName['Working at Height']['controls'] ?? null);
        $this->assertSame(3, $reviewedByName['Working at Height']['pre_likelihood'] ?? null);
        $this->assertSame(4, $reviewedByName['Working at Height']['pre_severity'] ?? null);

        $this->assertSame(['Team lift for items over 20 kg'], $reviewedByName['Manual Handling']['controls'] ?? null);
        $this->assertSame(['Isolate supply before connecting'], $reviewedByName['Electrical Hazards']['controls'] ?? null);
        $this->assertSame(['Keep walkways clear of cable coils'], $reviewedByName['Slips, Trips & Falls (Same Level)']['controls'] ?? null);
        $this->assertSame(['Hearing protection worn during drilling'], $reviewedByName['Noise and Vibration']['controls'] ?? null);

        // The 4 always-tier hazards.
        foreach (['Slips, trips and falls', 'Low voltage AV connections', 'Fire and evacuation', 'COSHH substances'] as $always) {
            $this->assertContains($always, $names, "always-tier hazard '{$always}' missing from merged register");
        }

        // The 5 confirm-tier hazards, each needs_confirmation = true.
        $confirmNames = ['Occupied premises', 'Asbestos-containing materials', 'Vehicle and plant movement', 'Lone and small-team working', 'Occupational road risk'];
        foreach ($confirmNames as $confirm) {
            $this->assertContains($confirm, $names, "confirm-tier hazard '{$confirm}' missing from merged register");
            $this->assertTrue(
                (bool) ($reviewedByName[$confirm]['needs_confirmation'] ?? false),
                "confirm-tier hazard '{$confirm}' must carry needs_confirmation = true",
            );
        }

        // Negative baseline (Test 6 restates this explicitly): no drilling
        // language anywhere in the scope narrative, so none of the three
        // drilling-gated tier-2 hazards should appear.
        foreach (['Dust from drilling and cutting', 'Fixings into walls, ceilings and pillars', 'Noise and vibration'] as $drillingGated) {
            $this->assertNotContains($drillingGated, $names, "drilling-gated hazard '{$drillingGated}' must NOT appear with no drilling language in scope");
        }
    }

    /**
     * Test 3: regenerating the SAME reviewed record twice produces an
     * identical hazard count and name set — the merge is idempotent because
     * reviewedToRisk() derives everything fresh from reviewed_data and never
     * writes tier-1/3 rows back into it.
     */
    public function test_regenerating_the_same_reviewed_rams_twice_does_not_duplicate_hazards(): void
    {
        $user = User::factory()->create();
        $rams = $this->makeRams($user, $this->makeReviewedData('Supply and install AV systems.'));

        $first  = $this->regenerate($rams);
        $firstNames = $this->hazardNames($first);

        $second = $this->regenerate($first);
        $secondNames = $this->hazardNames($second);

        $this->assertCount(14, $firstNames);
        $this->assertCount(14, $secondNames);

        $sortedFirst  = $firstNames;
        $sortedSecond = $secondNames;
        sort($sortedFirst);
        sort($sortedSecond);
        $this->assertSame($sortedFirst, $sortedSecond, 'regenerating twice must produce an identical hazard name set — no duplicate accumulation');
    }

    /**
     * Test 4: RAMS_HAZARD_LIBRARY_TIERING=false degrades runFromReview() to
     * reviewed-picks-only — zero tier-1/3 additions, the rollback path for
     * this generation entry point (26-VERIFICATION.md Outstanding item 4).
     */
    public function test_tiering_disabled_degrades_to_reviewed_picks_only(): void
    {
        config(['rams_tier1.hazard_tiering_enabled' => false]);

        $user = User::factory()->create();
        $rams = $this->makeRams($user, $this->makeReviewedData('Supply and install AV systems.'));

        $rebuilt = $this->regenerate($rams);
        $names   = $this->hazardNames($rebuilt);

        $this->assertCount(5, $names, 'flag off must return ONLY the 5 reviewed names — zero tier-1/3 auto-population');
        $this->assertEqualsCanonicalizing(
            ['Working at Height', 'Manual Handling', 'Electrical Hazards', 'Slips, Trips & Falls (Same Level)', 'Noise and Vibration'],
            $names,
        );
    }

    /**
     * Test 5 (Blocker 1 coverage, positive direction): reviewed scope text
     * containing drilling/fixing language (matched by
     * EquipmentClassifierService::MOUNT_KEYWORDS) yields all three
     * drilling-gated tier-2 hazards — proving the derived signal, not a
     * hardcoded false, now reaches HazardIncludeWhenResolver.
     *
     * Uses an EMPTY reviewed-hazards list (rather than the 5-row live-vocab
     * fixture Test 2 uses) so the assertion isolates the derived drilling
     * signal — the live-vocab fixture's "Noise and Vibration" row would
     * otherwise case-insensitively dedup against the library's own "Noise
     * and vibration" tier-2 hazard and mask the very signal under test.
     */
    public function test_drilling_language_in_scope_yields_the_three_drilling_gated_hazards(): void
    {
        $user = User::factory()->create();
        $rams = $this->makeRams($user, $this->makeReviewedData(
            'Drill fixing brackets to the wall and ceiling for the new display mounts.',
            [],
        ));

        $rebuilt = $this->regenerate($rams);
        $names   = $this->hazardNames($rebuilt);

        foreach (['Dust from drilling and cutting', 'Fixings into walls, ceilings and pillars', 'Noise and vibration'] as $drillingGated) {
            $this->assertContains($drillingGated, $names, "drilling-gated hazard '{$drillingGated}' missing despite drilling language in scope");
        }
    }

    /**
     * Test 6 (Blocker 1 coverage, negative direction): the same fixture as
     * Test 2 with no drilling/fixing language anywhere in the scope
     * narrative explicitly asserts none of the three drilling-gated hazards
     * appear — the fail-safe default.
     */
    public function test_no_drilling_language_yields_none_of_the_drilling_gated_hazards(): void
    {
        $user = User::factory()->create();
        $rams = $this->makeRams($user, $this->makeReviewedData('Supply and install AV systems.'));

        $rebuilt = $this->regenerate($rams);
        $names   = $this->hazardNames($rebuilt);

        foreach (['Dust from drilling and cutting', 'Fixings into walls, ceilings and pillars', 'Noise and vibration'] as $drillingGated) {
            $this->assertNotContains($drillingGated, $names, "drilling-gated hazard '{$drillingGated}' must NOT appear with no drilling language in scope — no keyword hit must never become a false positive");
        }
    }
}
