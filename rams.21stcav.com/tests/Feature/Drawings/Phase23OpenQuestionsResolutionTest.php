<?php

namespace Tests\Feature\Drawings;

use Tests\TestCase;

/**
 * Phase 23 Plan 01 Task 1 — enforces the two BLOCKING Wave 0 dispositions.
 *
 * If these files are missing or the disposition section is empty, Plan 02+03
 * are running against unverified assumptions per 23-RESEARCH.md Open Questions
 * 1 + 4. Failing this test red-blocks the entire Phase 23.
 */
class Phase23OpenQuestionsResolutionTest extends TestCase
{
    public function test_oq1_disposition_file_exists_with_selected_path(): void
    {
        $path = base_path('.planning/phases/23-xten-av-style-renderer/23-DISCOVERY-OQ-1-CATEGORIES.md');
        $this->assertFileExists($path);
        $contents = file_get_contents($path);
        $this->assertStringContainsString('## Disposition', $contents);
        $this->assertMatchesRegularExpression('/\*\*Path\s+[ABC]\s+selected/m', $contents);
        $this->assertStringContainsString('## Plan 02 carry-forward instruction', $contents);
    }

    public function test_oq4_disposition_file_exists_with_selected_path(): void
    {
        $path = base_path('.planning/phases/23-xten-av-style-renderer/23-DISCOVERY-OQ-4-TIER15-PORTS.md');
        $this->assertFileExists($path);
        $contents = file_get_contents($path);
        $this->assertStringContainsString('## Disposition', $contents);
        $this->assertMatchesRegularExpression('/\*\*Path\s+[AB]\s+selected/m', $contents);
    }
}
