<?php

namespace Tests\Feature\Rams;

use Tests\TestCase;

/**
 * Phase 22.1 Plan 05 — DATA-01 invariant static guard.
 *
 * Mirrors the DeadPathRemovalGuardTest pattern: a no-runtime-deps source scan
 * that prevents the six surgical changes shipped in Plan 22.1-05 from being
 * silently reverted by a future commit (revert, bad merge, copy-paste).
 *
 * The six invariants (one per change):
 *   D-02: ExtractQuoteJob/ExtractRamsDraftJob no longer auto-write
 *         Project.works_description = $extracted['overview'] (works_description
 *         on ProjectPackage::update also dropped from the same auto-seed path).
 *   D-03 (controller): ProjectPackageReviewController save() AND approve() no
 *         longer copy method_statement_notes into Project.works_description.
 *   D-03 (service): MethodStatementService::buildScope() no longer appends the
 *         "PM INSTRUCTIONS" separator-suffix block.
 *   D-06: RamsComplianceUpgradeService::upgradeScopeOfWorks() has a
 *         read-through cache guard for persisted scope_of_works_bullets, and
 *         ::computeScopeOfWorksBulletsForApprove() exists as a public entry
 *         point for the approve-time persistence.
 *   D-06 (controller): ProjectPackageReviewController::approve() calls the
 *         new compute method.
 *   D-01 (read-site lock): ensurePerRoomBullets() no longer reads
 *         $room['description'] as a fallback (description is no longer a
 *         canonical room_overviews key after Plan 03).
 *   D-08: pdf/rams.blade.php per-room fallback chain is simplified to
 *         overview-only (drops description / scope keys).
 *   D-09: RamsReviewDataService::normaliseProject() no longer emits the
 *         project-level 'overview' key.
 *
 * No runtime dependencies — these are static source-content assertions that
 * fire everywhere CI runs (no Browsershot, no D2 binary, no DB).
 *
 * @see .planning/phases/22.1-rams-scope-room-data-consolidation/22.1-05-PLAN.md
 */
class ScopeConsolidationGuardTest extends TestCase
{
    // ══════════════════════════════════════════════════════════════════════════
    // D-02: Project.works_description auto-seed from $extracted['overview'] gone
    // ══════════════════════════════════════════════════════════════════════════

    public function test_extract_quote_job_does_not_auto_seed_works_description_from_overview(): void
    {
        $files = [
            base_path('app/Jobs/ExtractQuoteJob.php'),
            base_path('app/Jobs/ExtractRamsDraftJob.php'),
        ];

        foreach ($files as $path) {
            if (! is_file($path)) {
                continue; // file may not exist (Plan 22.1-04 deleted one variant)
            }
            $contents = file_get_contents($path);

            // The exact dead pattern: any line that maps 'works_description' to
            // $extracted['overview'] or $parsed['overview'].
            $this->assertDoesNotMatchRegularExpression(
                "/'works_description'\s*=>\s*\\\$(extracted|parsed)\[\s*'overview'\s*\]/",
                (string) $contents,
                "Phase 22.1 D-02 violated in {$path}: Project.works_description must not be auto-seeded from \$extracted['overview']."
            );
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // D-03 (controller): method_statement_notes ↛ Project.works_description
    // ══════════════════════════════════════════════════════════════════════════

    public function test_project_package_review_controller_does_not_mirror_method_statement_notes_to_works_description(): void
    {
        $path = base_path('app/Http/Controllers/ProjectPackageReviewController.php');
        $contents = (string) file_get_contents($path);

        // The dead pattern (appears in BOTH save() and approve() before Plan 05):
        //   'works_description' => $payload['method_statement_notes'] ?? ...
        $this->assertDoesNotMatchRegularExpression(
            "/'works_description'\s*=>\s*\\\$payload\[\s*'method_statement_notes'\s*\]/",
            $contents,
            'Phase 22.1 D-03 violated: ProjectPackageReviewController must not mirror method_statement_notes into Project.works_description.'
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // D-03 (service): MethodStatementService PM-INSTRUCTIONS suffix gone
    // ══════════════════════════════════════════════════════════════════════════

    public function test_method_statement_service_does_not_append_pm_instructions_block(): void
    {
        $path = base_path('app/Services/MethodStatementService.php');
        $contents = (string) file_get_contents($path);

        $this->assertStringNotContainsString(
            'PM INSTRUCTIONS',
            $contents,
            'Phase 22.1 D-03 violated: MethodStatementService::buildScope() must not append a "PM INSTRUCTIONS" separator-suffix block.'
        );
        $this->assertStringNotContainsString(
            'must be honoured',
            $contents,
            'Phase 22.1 D-03 violated: the "must be honoured" suffix marker must not appear in MethodStatementService.'
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // D-06: read-through cache + public compute entry point on the service
    // ══════════════════════════════════════════════════════════════════════════

    public function test_rams_compliance_upgrade_service_exposes_approve_time_compute_method(): void
    {
        $path = base_path('app/Services/Rams/RamsComplianceUpgradeService.php');
        $contents = (string) file_get_contents($path);

        $this->assertStringContainsString(
            'computeScopeOfWorksBulletsForApprove',
            $contents,
            'Phase 22.1 D-06 violated: RamsComplianceUpgradeService must expose computeScopeOfWorksBulletsForApprove() for approve-time persistence.'
        );

        // The read-through cache guard: when persisted bullets exist, the
        // heuristic short-circuits. Shape of the guard:
        //   $persisted = $data['scope_of_works_bullets'] ?? null;
        //   if (is_array($persisted) && ! empty($persisted)) { return $data; }
        $this->assertMatchesRegularExpression(
            "/is_array\(\s*\\\$persisted\s*\)\s*&&\s*!\s*empty\(\s*\\\$persisted\s*\)/",
            $contents,
            'Phase 22.1 D-06 violated: upgradeScopeOfWorks() must short-circuit when scope_of_works_bullets is persisted.'
        );
    }

    public function test_project_package_review_controller_calls_approve_time_compute(): void
    {
        $path = base_path('app/Http/Controllers/ProjectPackageReviewController.php');
        $contents = (string) file_get_contents($path);

        $this->assertStringContainsString(
            'computeScopeOfWorksBulletsForApprove',
            $contents,
            'Phase 22.1 D-06 violated: ProjectPackageReviewController::approve() must invoke RamsComplianceUpgradeService::computeScopeOfWorksBulletsForApprove() to persist the bullets.'
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // D-01 (read-site lock): ensurePerRoomBullets drops $room['description']
    // ══════════════════════════════════════════════════════════════════════════

    public function test_ensure_per_room_bullets_does_not_fall_back_to_description(): void
    {
        $path = base_path('app/Services/Rams/RamsComplianceUpgradeService.php');
        $contents = (string) file_get_contents($path);

        // Extract just the ensurePerRoomBullets() method body. The method ends
        // at the next `private static function` declaration or the SECTION
        // banner that opens the next public block.
        $startPattern = '/private static function ensurePerRoomBullets\b/';
        $this->assertMatchesRegularExpression(
            $startPattern,
            $contents,
            'ensurePerRoomBullets() method missing from RamsComplianceUpgradeService.',
        );
        $startOffset = preg_match($startPattern, $contents, $m, PREG_OFFSET_CAPTURE)
            ? $m[0][1]
            : 0;

        // Find the next top-level method (`private static function` or `public static function`)
        // AFTER the start. Cap to end-of-file if there is no next method.
        $remaining = substr($contents, $startOffset + 1);
        $endRelPattern = '/\n\s*(?:private|public|protected) static function\s/';
        $endOffset = preg_match($endRelPattern, $remaining, $m2, PREG_OFFSET_CAPTURE)
            ? $startOffset + 1 + $m2[0][1]
            : strlen($contents);

        $body = substr($contents, $startOffset, $endOffset - $startOffset);

        // The fallback chain at line ~71 reads `$room['description']`. After
        // D-01 the canonical schema has dropped description — this read must
        // be gone from the method body.
        $this->assertStringNotContainsString(
            "\$room['description']",
            $body,
            'Phase 22.1 D-01 violated: ensurePerRoomBullets() must not fall back to $room[\'description\'] — description is no longer a canonical room_overviews key.'
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // D-08: per-room blade fallback chain simplified to overview-only
    // ══════════════════════════════════════════════════════════════════════════

    public function test_pdf_rams_blade_per_room_fallback_drops_description_and_scope(): void
    {
        $path = base_path('resources/views/pdf/rams.blade.php');
        $contents = (string) file_get_contents($path);

        // The dead chain we are eliminating:
        //   $rvDesc = $roomOv['overview'] ?? ($roomOv['description'] ?? ($roomOv['scope'] ?? ''));
        // A simpler signal: after D-08, no `$roomOv['description']` or
        // `$roomOv['scope']` read survives in the blade.
        $this->assertStringNotContainsString(
            "\$roomOv['description']",
            $contents,
            'Phase 22.1 D-08 violated: pdf/rams.blade.php must not read $roomOv[\'description\'] (legacy fallback dropped).'
        );
        $this->assertStringNotContainsString(
            "\$roomOv['scope']",
            $contents,
            'Phase 22.1 D-08 violated: pdf/rams.blade.php must not read $roomOv[\'scope\'] (legacy fallback dropped).'
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // D-09: RamsReviewDataService::normaliseProject() drops project.overview
    // ══════════════════════════════════════════════════════════════════════════

    public function test_rams_review_data_service_normalise_project_drops_overview_key(): void
    {
        $path = base_path('app/Services/RamsReviewDataService.php');
        $contents = (string) file_get_contents($path);

        // Extract the normaliseProject() method body.
        $startOffset = strpos($contents, 'private function normaliseProject');
        $this->assertNotFalse(
            $startOffset,
            'normaliseProject() method missing from RamsReviewDataService.',
        );

        // Find the next method declaration after the start.
        $tail = substr($contents, $startOffset + 1);
        $endRelOffset = strpos($tail, "\n    private function ");
        $endOffset = $endRelOffset === false
            ? strlen($contents)
            : $startOffset + 1 + $endRelOffset;

        $body = substr($contents, $startOffset, $endOffset - $startOffset);

        // The dead emission line: `'overview'     => (string) ($p['overview']...
        // After D-09 normaliseProject() does not emit the project-level overview key.
        $this->assertDoesNotMatchRegularExpression(
            "/'overview'\s*=>\s*\(string\)\s*\(\\\$p\[\s*'overview'\s*\]/",
            $body,
            'Phase 22.1 D-09 violated: RamsReviewDataService::normaliseProject() must not emit the project-level `overview` key.'
        );
    }
}
