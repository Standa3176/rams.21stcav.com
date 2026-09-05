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
                'score_reviewed'   => true,
            ],
            [
                'hazard'           => 'Manual Handling',
                'pre_likelihood'   => 3,
                'pre_severity'     => 3,
                'control_measures' => ['Team lift for items over 20 kg'],
                'post_likelihood'  => 1,
                'post_severity'    => 2,
                'score_reviewed'   => true,
            ],
            [
                'hazard'           => 'Electrical Hazards',
                'pre_likelihood'   => 3,
                'pre_severity'     => 4,
                'control_measures' => ['Isolate supply before connecting'],
                'post_likelihood'  => 1,
                'post_severity'    => 3,
                'score_reviewed'   => true,
            ],
            [
                'hazard'           => 'Slips, Trips & Falls (Same Level)',
                'pre_likelihood'   => 3,
                'pre_severity'     => 2,
                'control_measures' => ['Keep walkways clear of cable coils'],
                'post_likelihood'  => 1,
                'post_severity'    => 1,
                'score_reviewed'   => true,
            ],
            [
                'hazard'           => 'Noise and Vibration',
                'pre_likelihood'   => 2,
                'pre_severity'     => 2,
                'control_measures' => ['Hearing protection worn during drilling'],
                'post_likelihood'  => 1,
                'post_severity'    => 1,
                'score_reviewed'   => true,
            ],
        ];
    }

    /**
     * Plan 26-08 (HAZ-02/HAZ-03 gap closure, round 2) — the real 7-name
     * legacy vocabulary observed on live (21CQ30960 / RAMS 97), the exact
     * fixture gap that let round 1 AND round 2 through: uniform 3x3->2x2
     * scores (the GATE-05 signature), a single generic control string, and
     * NO score_reviewed key present at all — mirrors real pre-26-05 data.
     */
    private function makeUnreviewedLegacyHazards(): array
    {
        $names = [
            'Working at Height',
            'Manual Handling',
            'Electrical Hazards',
            'Slips, Trips & Falls (Same Level)',
            'Noise and Vibration',
            'Working in Occupied Premises',
            'Confined Spaces',
        ];

        return array_map(static fn (string $name): array => [
            'hazard'           => $name,
            'pre_likelihood'   => 3,
            'pre_severity'     => 3,
            'control_measures' => ['Generic control noted during review.'],
            'post_likelihood'  => 2,
            'post_severity'    => 2,
            // score_reviewed deliberately absent — mirrors real pre-26-05 data.
        ], $names);
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
     * Test 2: a reviewed RAMS with no drilling/fixing language regenerates.
     * Plan 26-08: with score_reviewed=true on all 5 reviewed rows, each
     * folds onto its canonical library name (Electrical Hazards ->
     * Electrical, Slips, Trips & Falls (Same Level) -> Slips, trips and
     * falls, and the 3 case-only matches display under the library's exact
     * casing) — reviewed VALUES win (score_reviewed=true), so scores are
     * unchanged, but controls on a genuine rename are replaced with the
     * library's own text. "Slips, trips and falls" is ALSO one of the 4
     * always-tier hazards, so the reviewed row and the always-tier
     * candidate collapse into ONE row: 5 reviewed (distinct after fold) + 3
     * new always-tier (excluding the one collision) + 5 confirm-tier = 13
     * rows, zero duplicates, none of the three drilling-gated hazards.
     */
    public function test_reviewed_rams_regenerates_with_always_and_confirm_tier_hazards_merged_on_top(): void
    {
        $user = User::factory()->create();
        $rams = $this->makeRams($user, $this->makeReviewedData('Supply and install AV systems.'));

        $rebuilt = $this->regenerate($rams);

        $hazards = (array) ($rebuilt->generated_data['hazards'] ?? []);
        $names   = $this->hazardNames($rebuilt);

        $this->assertCount(13, $hazards, 'expected 5 folded reviewed + 3 new always-tier + 5 confirm-tier = 13 rows (Slips/trips/falls collapses with its always-tier counterpart)');
        $this->assertSame(13, count(array_unique($names)), 'zero duplicate hazard names');

        // The 5 originally-reviewed rows survive under their FOLDED canonical
        // names (score_reviewed=true means their own numeric scores win).
        $reviewedByName = [];
        foreach ($hazards as $row) {
            $reviewedByName[$row['hazard']] = $row;
        }

        $this->assertSame(3, $reviewedByName['Working at height']['post_severity'] ?? null, 'reviewed row scores untouched — post-severity check');
        // SUPERSEDED BY PLAN 27-08. This previously asserted that a case-only
        // name match keeps the row's own controls, because Phase 26 decided
        // control replacement by whether a genuine RENAME had occurred. That
        // proxy is gone: Plan 27-08 replaced it with an explicit
        // `controls_reviewed` marker, and this fixture row carries no marker,
        // so the library's current text now wins (tier 2). That is the whole
        // point of 27-08 — stale reviewed controls were surviving every
        // regeneration and defeating every house-rule correction
        // (27-VERIFICATION.md, Blocker 1).
        //
        // The protection that actually matters is asserted below and in
        // ReviewedControlRefreshTest: text an engineer really edited survives.
        $this->assertNotSame(
            ['Use podium steps, maintain 3-point contact'],
            $reviewedByName['Working at height']['controls'] ?? null,
            'unmarked reviewed controls must now be refreshed from the library (Plan 27-08 tier 2)',
        );
        $this->assertStringContainsString(
            'podium steps or mobile access tower',
            implode(' ', (array) ($reviewedByName['Working at height']['controls'] ?? [])),
            'the refreshed controls should be the library Working at height text',
        );
        $this->assertSame(3, $reviewedByName['Working at height']['pre_likelihood'] ?? null);
        $this->assertSame(4, $reviewedByName['Working at height']['pre_severity'] ?? null);

        // SUPERSEDED BY PLAN 27-08, twice over. This fixture text is BOTH
        // unmarked (tier 2 refreshes it from the library) and itself a RULE-13
        // breach — "over 20 kg" is exactly the banned kg threshold, so tier 1
        // would replace it even if an engineer had authored it. The assertion
        // that it survives regeneration is the behaviour 27-08 exists to end.
        $manualControls = implode(' ', (array) ($reviewedByName['Manual handling']['controls'] ?? []));

        $this->assertStringNotContainsString(
            'over 20 kg',
            $manualControls,
            'a RULE-13 kg threshold must never survive regeneration (Plan 27-08 tier 1)',
        );
        $this->assertStringContainsString(
            'not a kg threshold',
            $manualControls,
            'the refreshed controls should be the current library Manual handling text',
        );
        $this->assertSame(
            'All electrical work to be carried out by competent, authorised persons only. No live working under any circumstances.',
            $reviewedByName['Electrical']['controls'][0] ?? null,
            // Same outcome as before Plan 27-08, different reason: this used to
            // hold because a genuine rename (Electrical Hazards -> Electrical)
            // forced replacement. That rule is gone; it now holds because the
            // row carries no controls_reviewed marker, so tier 2 applies.
            'unmarked reviewed controls are replaced with the library text',
        );

        // SUPERSEDED BY PLAN 27-08 — same tier-2 supersession as the Working at
        // height and Manual handling rows above. This fixture row is unmarked,
        // so the library text now wins instead of the stale one-line reviewed
        // control surviving every regeneration.
        $noiseControls = implode(' ', (array) ($reviewedByName['Noise and vibration']['controls'] ?? []));

        $this->assertNotSame(
            ['Hearing protection worn during drilling'],
            $reviewedByName['Noise and vibration']['controls'] ?? null,
            'unmarked reviewed controls must now be refreshed from the library (Plan 27-08 tier 2)',
        );
        $this->assertStringContainsString(
            'hearing protection',
            strtolower($noiseControls),
            'the refreshed controls should be the current library Noise and vibration text',
        );

        // "Slips, trips and falls" is both the folded reviewed row AND an
        // always-tier hazard — it collapses to one row, and since
        // score_reviewed=true the reviewed row's own scores survive.
        $this->assertSame(1, $reviewedByName['Slips, trips and falls']['post_likelihood'] ?? null, 'the collapsed row keeps the REVIEWED row values, not the always-tier defaults');

        // The remaining 3 always-tier hazards (Slips/trips/falls already
        // asserted above via the collapsed reviewed row).
        foreach (['Low voltage AV connections', 'Fire and evacuation', 'COSHH substances'] as $always) {
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
        foreach (['Dust from drilling and cutting', 'Fixings into walls, ceilings and pillars'] as $drillingGated) {
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

        $this->assertCount(13, $firstNames);
        $this->assertCount(13, $secondNames);

        $sortedFirst  = $firstNames;
        $sortedSecond = $secondNames;
        sort($sortedFirst);
        sort($sortedSecond);
        $this->assertSame($sortedFirst, $sortedSecond, 'regenerating twice must produce an identical hazard name set — no duplicate accumulation');
    }

    /**
     * Test 4: RAMS_HAZARD_LIBRARY_TIERING=false degrades reviewedToRisk()'s
     * own merge point to reviewed-picks-only — zero tier-1/3 library
     * additions, the rollback path for this generation entry point
     * (26-VERIFICATION.md Outstanding item 4).
     *
     * RamsComplianceUpgradeService::addProjectSpecificRisks() is a SEPARATE
     * injection path gated behind the SAME flag (Task 3); when the flag is
     * off it preserves pre-Phase-26 legacy behaviour byte-identically — its
     * unconditional legacy candidates are therefore EXPECTED here too, not
     * a bug. Success criterion: "legacy behaviour preserved byte-identical,
     * not degraded to a blank hole" on BOTH gated paths simultaneously.
     *
     * Plan 26-08: the fold/rename applies REGARDLESS of
     * rams_tier1.hazard_tiering_enabled (only the TIERED MERGE is gated by
     * that flag) — so the 5 reviewed picks survive under their FOLDED
     * canonical names, not the raw legacy names.
     */
    public function test_tiering_disabled_degrades_to_reviewed_picks_only(): void
    {
        config(['rams_tier1.hazard_tiering_enabled' => false]);

        $user = User::factory()->create();
        $rams = $this->makeRams($user, $this->makeReviewedData('Supply and install AV systems.'));

        $rebuilt = $this->regenerate($rams);
        $names   = $this->hazardNames($rebuilt);

        // The 5 reviewed picks always survive — under their folded names.
        foreach (['Working at height', 'Manual handling', 'Electrical', 'Slips, trips and falls', 'Noise and vibration'] as $reviewed) {
            $this->assertContains($reviewed, $names, "folded reviewed hazard '{$reviewed}' must survive with tiering disabled");
        }

        // reviewedToRisk()'s tier-1/3 merge point contributes ZERO rows —
        // none of the 18-hazard library's always/confirm-tier names appear.
        // "Slips, trips and falls" is deliberately EXCLUDED from this
        // negative list: it is one of the 5 folded reviewed picks above
        // (Slips, Trips & Falls (Same Level) -> Slips, trips and falls),
        // present via the fold — not via the (disabled) tier merge.
        $tieredLibraryNames = [
            'Low voltage AV connections', 'Fire and evacuation', 'COSHH substances',
            'Occupied premises', 'Asbestos-containing materials', 'Vehicle and plant movement',
            'Lone and small-team working', 'Occupational road risk',
        ];
        foreach ($tieredLibraryNames as $tieredName) {
            $this->assertNotContains($tieredName, $names, "tiered library hazard '{$tieredName}' must not appear via reviewedToRisk() when tiering is disabled");
        }

        // addProjectSpecificRisks()'s own byte-identical legacy rollback —
        // its 3 unconditional candidates fire exactly as they did pre-Phase-26.
        foreach (['Cable Pulling & Termination', 'Low Voltage AV Connections', 'Fixings into Walls & Ceilings'] as $legacy) {
            $this->assertContains($legacy, $names, "legacy candidate '{$legacy}' expected — addProjectSpecificRisks() rollback must be byte-identical to pre-Phase-26 behaviour");
        }
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
     * appear via a signal match — the fail-safe default.
     *
     * "Noise and vibration" is deliberately EXCLUDED from this negative
     * list (Plan 26-08): the fixture's reviewed "Noise and Vibration" row
     * now folds onto the library's canonical "Noise and vibration" name, so
     * it IS present in the output — via the fold, not via a drilling-signal
     * false positive. The remaining 2 checks still fully prove the
     * fail-safe default (neither is a reviewed pick name in this fixture).
     */
    public function test_no_drilling_language_yields_none_of_the_drilling_gated_hazards(): void
    {
        $user = User::factory()->create();
        $rams = $this->makeRams($user, $this->makeReviewedData('Supply and install AV systems.'));

        $rebuilt = $this->regenerate($rams);
        $names   = $this->hazardNames($rebuilt);

        foreach (['Dust from drilling and cutting', 'Fixings into walls, ceilings and pillars'] as $drillingGated) {
            $this->assertNotContains($drillingGated, $names, "drilling-gated hazard '{$drillingGated}' must NOT appear with no drilling language in scope — no keyword hit must never become a false positive");
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // Plan 26-08 (HAZ-02/HAZ-03 gap closure, round 2) — the REAL 7-name
    // legacy vocabulary observed on live (21CQ30960 / RAMS 97), the exact
    // fixture gap that let round 1 AND round 2 through.
    // ══════════════════════════════════════════════════════════════════════

    /**
     * The full 7-name live-evidence legacy vocabulary, with uniform
     * 3x3->2x2 scores and NO score_reviewed key (mirrors real pre-26-05
     * data), regenerated with a neutral scope narrative:
     *   - 7 reviewed rows fold onto 7 DISTINCT canonical names, zero
     *     duplicates among themselves.
     *   - No row's hazard case-insensitively equals "Confined Spaces".
     *   - "Restricted access and ceiling voids" carries the library's
     *     residual score and its exact "not classified as confined spaces"
     *     control text.
     *   - "Working at height" carries the library's residual 1x4 (the named
     *     HAZ-03 proof) — restored from the fixture's stale 3x3->2x2 input,
     *     because score_reviewed is absent.
     *   - "Occupied premises" (folded from "Working in Occupied Premises")
     *     carries needs_confirmation=true.
     *   - "Electrical" (folded from "Electrical Hazards") carries the
     *     library's own control text.
     *   - Total: 7 folded-distinct reviewed names + 3 new always-tier
     *     (excluding "Slips, trips and falls", which collapses with its
     *     reviewed counterpart) + 4 new confirm-tier (excluding "Occupied
     *     premises", which likewise collapses) = 14 rows.
     */
    public function test_real_legacy_vocabulary_folds_dedupes_and_restores_library_scores(): void
    {
        $user = User::factory()->create();
        $rams = $this->makeRams($user, $this->makeReviewedData(
            'Supply and install AV systems.',
            $this->makeUnreviewedLegacyHazards(),
        ));

        $rebuilt = $this->regenerate($rams);

        $hazards = (array) ($rebuilt->generated_data['hazards'] ?? []);
        $names   = $this->hazardNames($rebuilt);

        $this->assertCount(14, $hazards, 'expected 7 folded-distinct reviewed + 3 new always-tier + 4 new confirm-tier = 14 rows');
        $this->assertSame(14, count(array_unique($names)), 'zero duplicate hazard names');

        foreach ($names as $name) {
            $this->assertNotSame('confined spaces', strtolower(trim($name)), "no row's hazard may case-insensitively equal 'Confined Spaces' — the client-facing mislabel this plan closes");
        }

        $byName = [];
        foreach ($hazards as $row) {
            $byName[$row['hazard']] = $row;
        }

        $this->assertArrayHasKey('Restricted access and ceiling voids', $byName);
        $this->assertSame(1, $byName['Restricted access and ceiling voids']['post_likelihood'] ?? null);
        $this->assertSame(2, $byName['Restricted access and ceiling voids']['post_severity'] ?? null);
        $this->assertContains(
            'Confirm ventilation and safe access before entering ceiling voids, comms rooms or enclosures. These are not classified as confined spaces, but access is restricted and is treated as a controlled activity.',
            $byName['Restricted access and ceiling voids']['controls'] ?? [],
        );

        $this->assertArrayHasKey('Working at height', $byName);
        $this->assertSame(3, $byName['Working at height']['pre_likelihood'] ?? null);
        $this->assertSame(4, $byName['Working at height']['pre_severity'] ?? null);
        $this->assertSame(1, $byName['Working at height']['post_likelihood'] ?? null, 'HAZ-03: residual score restored from stale reviewed_data when score_reviewed is absent');
        $this->assertSame(4, $byName['Working at height']['post_severity'] ?? null);

        $this->assertArrayHasKey('Occupied premises', $byName);
        $this->assertTrue((bool) ($byName['Occupied premises']['needs_confirmation'] ?? false));

        $this->assertArrayHasKey('Electrical', $byName);
        $this->assertContains(
            'All electrical work to be carried out by competent, authorised persons only. No live working under any circumstances.',
            $byName['Electrical']['controls'] ?? [],
        );
    }

    /**
     * Regenerating the same real-legacy-vocabulary fixture twice produces
     * an identical hazard name set — idempotency holds for the full 7-name
     * live vocabulary too, not just the clean 5-name fixture.
     */
    public function test_real_legacy_vocabulary_regenerates_idempotently(): void
    {
        $user = User::factory()->create();
        $rams = $this->makeRams($user, $this->makeReviewedData(
            'Supply and install AV systems.',
            $this->makeUnreviewedLegacyHazards(),
        ));

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
}
