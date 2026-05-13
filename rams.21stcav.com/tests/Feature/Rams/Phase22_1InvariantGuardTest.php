<?php

namespace Tests\Feature\Rams;

use App\Models\Project;
use App\Models\RamsDocument;
use App\Models\User;
use App\Services\RamsReviewDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 22.1 — phase-level invariant guard.
 *
 * Each test method maps to ONE numbered Success Criterion from ROADMAP.md
 * §"Phase 22.1: RAMS Scope/Room-Data Consolidation". Failing this guard
 * means a future phase has regressed a Phase 22.1 invariant.
 *
 * Complementary to DeadPathRemovalGuardTest (Plan 04 — file existence + class
 * substring grep) and ReviewedDataStructuralDiffTest (Plan 06 Task 1 — data
 * shape). This test ties the two to the ROADMAP success-criteria text.
 *
 *   SC #1 → DATA-01  : no method_statement_notes → Project.works_description mapping
 *   SC #2 → DATA-02  : per-room schema = exactly 4 canonical keys
 *   SC #3 → DATA-03  : 3 dead-path files deleted; works_bullets surface gone
 *   SC #4 → DATA-04  : artisan command dry-run-by-default + --apply persists
 *   SC #5 → DATA-05  : RamsRenderRegressionTest class + 3 fixture-flavour tests
 *   SC #6 → CLAUDE.md AI constraint  : no AI prompt invents scope/equipment/design
 *
 * @see .planning/ROADMAP.md §"Phase 22.1: RAMS Scope/Room-Data Consolidation"
 * @see .planning/REQUIREMENTS.md §"Phase 22.1 — RAMS Scope/Room-Data Consolidation" DATA-01..05
 */
class Phase22_1InvariantGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * SC #1 (DATA-01): A single project-wide scope edit propagates to ONE
     * canonical JSON location only.
     *
     * Verifies the method_statement_notes → Project.works_description mapping
     * has been removed from BOTH save() and approve() in
     * ProjectPackageReviewController. Any future revert that re-introduces
     * the line (or any close variant) fails this guard.
     */
    public function test_sc1_single_canonical_scope_location_no_method_statement_notes_mapping(): void
    {
        $controller = base_path('app/Http/Controllers/ProjectPackageReviewController.php');
        $this->assertFileExists($controller);
        $contents = file_get_contents($controller);

        // The mapping line (or any close variant) must not exist.
        $forbiddenPatterns = [
            "'works_description' => \$payload['method_statement_notes']",
            "'works_description'=>\$payload['method_statement_notes']",
            '"works_description" => $payload["method_statement_notes"]',
            '"works_description"=>$payload["method_statement_notes"]',
        ];
        foreach ($forbiddenPatterns as $pat) {
            $this->assertStringNotContainsString($pat, $contents,
                'SC #1 (DATA-01) violated: method_statement_notes → Project.works_description mapping re-introduced.');
        }
    }

    /**
     * SC #2 (DATA-02): Per-room narrative carries exactly TWO text fields
     * (overview + works_summary) plus room name + solution_type_id.
     *
     * Verifies RamsReviewDataService::normaliseRoomOverviews emits exactly 4
     * canonical keys.
     */
    public function test_sc2_per_room_narrative_exactly_two_text_fields(): void
    {
        $svc = app(RamsReviewDataService::class);
        $out = $svc->normalise([
            'room_overviews' => [[
                'room' => 'A', 'overview' => 'o', 'works_summary' => 'ws',
                'summary' => 'legacy', 'description' => 'legacy', 'scope' => 'legacy',
                'solution_type_id' => 1,
            ]],
        ])['room_overviews'][0];

        $this->assertSame(
            ['room', 'overview', 'works_summary', 'solution_type_id'],
            array_keys($out),
            'SC #2 (DATA-02) violated: per-room schema must be exactly 4 keys.'
        );
    }

    /**
     * SC #3 (DATA-03): Five dead-path files/paths removed.
     *
     * Verifies the deleted PHP files do not exist and the works_bullets
     * textarea surface is gone from the review blade.
     */
    public function test_sc3_dead_paths_removed(): void
    {
        $deletedFiles = [
            base_path('app/Services/RamsGeneratorService.php'),
            base_path('app/Core/AI/Prompts/RamsPrompt.php'),
            base_path('app/Core/AI/Prompts/WorksBulletsPrompt.php'),
            // Plan 22.1-04 Rule-3 deviation: a SECOND RamsGeneratorService
            // existed at Core/Modules/RAMS/ and was deleted in the same wave.
            base_path('app/Core/Modules/RAMS/RamsGeneratorService.php'),
        ];
        foreach ($deletedFiles as $path) {
            $this->assertFileDoesNotExist($path,
                'SC #3 (DATA-03) violated: deleted file re-created at ' . $path);
        }

        // Spot-check: works_bullets textarea is gone from the review blade.
        $reviewBlade = base_path('resources/views/project-packages/review.blade.php');
        if (is_file($reviewBlade)) {
            $blade = file_get_contents($reviewBlade);
            $this->assertStringNotContainsString('name="works_bullets"', $blade,
                'SC #3 (DATA-03) violated: works_bullets textarea re-introduced in review form.');
            $this->assertStringNotContainsString('id="works-bullets-field"', $blade,
                'SC #3 (DATA-03) violated: works-bullets-field DOM id re-introduced in review form.');
        }
    }

    /**
     * SC #4 (DATA-04): Backfill artisan command registered, dry-run by
     * default, --apply persists the backfill.
     *
     * Exercises the command end-to-end:
     *   1. Seed a RamsDocument with legacy summary + empty works_summary.
     *   2. Dry-run — assert [DRY RUN] header printed, exit 0, record UNCHANGED.
     *   3. --apply — assert exit 0, record's works_summary now holds the
     *      backfilled bullets.
     */
    public function test_sc4_backfill_command_registered_dry_run_default(): void
    {
        // Seed a RAMS document so the command has something to iterate.
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $record  = RamsDocument::factory()->create([
            'user_id'       => $user->id,
            'project_id'    => $project->id,
            'status'        => RamsDocument::STATUS_COMPLETED,
            'reviewed_data' => [
                'room_overviews' => [
                    ['room' => 'A', 'overview' => '', 'works_summary' => '',
                     'summary' => '- legacy bullets', 'solution_type_id' => null],
                ],
            ],
        ]);

        // Dry-run — must NOT mutate the record.
        $this->artisan('rams:backfill-room-overview-summary')
            ->expectsOutputToContain('[DRY RUN]')
            ->assertExitCode(0);

        $record->refresh();
        $this->assertSame('', $record->reviewed_data['room_overviews'][0]['works_summary'],
            'SC #4 (DATA-04) violated: dry-run wrote to the record.');

        // --apply must persist.
        $this->artisan('rams:backfill-room-overview-summary', ['--apply' => true])
            ->assertExitCode(0);

        $record->refresh();
        $this->assertSame('- legacy bullets', $record->reviewed_data['room_overviews'][0]['works_summary'],
            'SC #4 (DATA-04) violated: --apply did not persist the backfill.');
    }

    /**
     * SC #5 (DATA-05): Byte-equivalence regression test class exists with at
     * least 3 fixture-flavour test methods.
     */
    public function test_sc5_byte_equivalence_regression_test_class_exists(): void
    {
        $this->assertTrue(
            class_exists(\Tests\Feature\Rams\RamsRenderRegressionTest::class),
            'SC #5 (DATA-05) violated: RamsRenderRegressionTest class missing.'
        );

        $reflection = new \ReflectionClass(\Tests\Feature\Rams\RamsRenderRegressionTest::class);
        $testMethods = array_filter(
            $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
            fn ($m) => str_starts_with($m->getName(), 'test_pdf_byte_identical')
        );
        $this->assertGreaterThanOrEqual(3, count($testMethods),
            'SC #5 (DATA-05) violated: RamsRenderRegressionTest must have at least 3 test methods (one per fixture flavour).');
    }

    /**
     * SC #6 (CLAUDE.md AI constraint): No AI prompt invents scope, equipment,
     * or design content.
     *
     * Method-scoped check (tightened from a broad literal-substring scan):
     * forbidden literals (`"controls"`, `"regulations"`, `"hazards"`) are
     * disallowed ONLY when they appear inside the BODY of one of these methods
     * on a BasePrompt subclass:
     *   - build()
     *   - any method whose name contains "schema" (e.g. response_schema,
     *     responseSchema, jsonSchema)
     *   - any method whose name contains "response_format" or "responseFormat"
     *
     * Why: a legitimate future prompt may want to say in its `systemMessage()`
     * something like "do not invent control measures" — that's a constraint,
     * not an AI-output field. The previous broad literal-substring check would
     * have rejected that legitimate use. The method-scoped check only catches
     * the actual JSON-schema fields the AI is instructed to fill, which is
     * where the CLAUDE.md "AI must not invent scope/equipment/design"
     * constraint actually matters.
     *
     * Implementation: tokenise each .php file with PHP's token_get_all() to
     * locate the body of each target method, then string-search inside that
     * body only. This avoids regex fragility around heredocs / nowdocs.
     */
    public function test_sc6_no_ai_prompt_invents_scope_equipment_design(): void
    {
        $promptDir = base_path('app/Core/AI/Prompts');
        $this->assertDirectoryExists($promptDir);

        $files = glob($promptDir . '/*.php') ?: [];

        $forbiddenAiOutputFields = ['"controls"', '"regulations"', '"hazards"'];

        $targetMethodMatcher = static function (string $name): bool {
            $lower = strtolower($name);
            return $lower === 'build'
                || str_contains($lower, 'schema')
                || str_contains($lower, 'response_format')
                || str_contains($lower, 'responseformat');
        };

        foreach ($files as $file) {
            // Skip BasePrompt itself — it's the contract, not an implementation.
            if (basename($file) === 'BasePrompt.php') {
                continue;
            }

            $contents = file_get_contents($file) ?: '';
            $tokens = token_get_all($contents);

            // Walk the token stream, find target method bodies, scan their text.
            $i = 0;
            $count = count($tokens);
            while ($i < $count) {
                $tok = $tokens[$i];
                if (is_array($tok) && $tok[0] === T_FUNCTION) {
                    // Lookahead: skip whitespace until we find the method name.
                    $j = $i + 1;
                    while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                        $j++;
                    }
                    if ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                        $methodName = $tokens[$j][1];
                        if ($targetMethodMatcher($methodName)) {
                            // Find the opening `{` of the method body, then
                            // capture until matching `}`.
                            $depth    = 0;
                            $k        = $j;
                            $bodyText = '';
                            $inBody   = false;
                            while ($k < $count) {
                                $t     = $tokens[$k];
                                $piece = is_array($t) ? $t[1] : $t;
                                if ($piece === '{') {
                                    if (! $inBody) {
                                        $inBody = true;
                                        $depth  = 1;
                                        $k++;
                                        continue;
                                    }
                                    $depth++;
                                } elseif ($piece === '}') {
                                    $depth--;
                                    if ($inBody && $depth === 0) {
                                        break;
                                    }
                                }
                                if ($inBody) {
                                    $bodyText .= $piece;
                                }
                                $k++;
                            }

                            // Now check the captured body for forbidden literals.
                            foreach ($forbiddenAiOutputFields as $needle) {
                                $this->assertStringNotContainsString(
                                    $needle,
                                    $bodyText,
                                    "SC #6 (CLAUDE.md AI constraint) violated: {$file}::{$methodName}() "
                                    . "body references AI output field {$needle}. AI must not generate "
                                    . "scope/equipment/design content. If this literal is a legitimate "
                                    . "schema field for non-RAMS-compliance content, add the file to an "
                                    . "explicit allow-list (see test source for the pattern)."
                                );
                            }
                            $i = $k;
                            continue;
                        }
                    }
                }
                $i++;
            }
        }
    }
}
