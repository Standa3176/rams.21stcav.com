<?php

namespace Tests\Unit\Support;

use App\Support\SurveyPdfHelpers;
use Tests\TestCase;

/**
 * Pins the post-survey summary PDF behaviour around the
 * "Standard checks for this solution type:" boilerplate that
 * SurveyService::resolveAvRequirementsText() appends to every room's
 * av_requirements column. The summary PDF strips it (this test);
 * field-form.blade.php deliberately keeps it (covered elsewhere).
 *
 * Triggered by the Tilda 21CQ29531-05-OPS site survey PDF (Oregano /
 * Cinnamon / Saffron rooms): the unanswered checklist questions
 * rendered as bullets in "Planned AV Works" and read as a list of
 * un-investigated items on a completed survey.
 */
class SurveyPdfHelpersStripStandardChecksTest extends TestCase
{
    public function test_strips_standard_checks_block_and_everything_after_it(): void
    {
        $narrative = "Install new 75\" Sony Display.\n"
                   . "Integrate Jabra PanaCast 50 for conferencing.\n"
                   . "Provision full room control via Crestron touch panel.\n\n"
                   . "Standard checks for this solution type:\n"
                   . "Room dimensions (W × D × H) — determine display size and camera FOV\n"
                   . "Seating capacity and table configuration (boardroom, U-shape, theatre)\n"
                   . "UC platform in use — Microsoft Teams, Zoom, Cisco Webex, Google Meet";

        $stripped = SurveyPdfHelpers::stripStandardChecksTail($narrative);

        $this->assertStringContainsString('Install new 75" Sony Display.', $stripped);
        $this->assertStringContainsString('Crestron touch panel.', $stripped);
        $this->assertStringNotContainsString('Standard checks for this solution type', $stripped);
        $this->assertStringNotContainsString('Room dimensions', $stripped);
        $this->assertStringNotContainsString('UC platform in use', $stripped);
    }

    public function test_returns_narrative_unchanged_when_marker_absent(): void
    {
        $narrative = "Install new 75\" Sony Display.\nIntegrate Jabra PanaCast 50.";

        $this->assertSame(
            $narrative,
            SurveyPdfHelpers::stripStandardChecksTail($narrative)
        );
    }

    public function test_returns_empty_string_for_empty_input(): void
    {
        $this->assertSame('', SurveyPdfHelpers::stripStandardChecksTail(''));
    }

    public function test_handles_single_blank_line_separator(): void
    {
        // The SurveyService canonical concatenation uses "\n\n" but a
        // tolerant separator (\R+) keeps the strip robust against editors
        // that normalise newlines or operators who hand-paste a single
        // blank line.
        $narrative = "Install new display.\nStandard checks for this solution type:\nRoom dimensions";

        $stripped = SurveyPdfHelpers::stripStandardChecksTail($narrative);

        $this->assertSame('Install new display.', $stripped);
    }

    public function test_handles_crlf_line_endings(): void
    {
        $narrative = "Install new display.\r\n\r\nStandard checks for this solution type:\r\nRoom dimensions";

        $stripped = SurveyPdfHelpers::stripStandardChecksTail($narrative);

        $this->assertSame('Install new display.', $stripped);
    }

    public function test_marker_is_case_sensitive_to_avoid_false_strips(): void
    {
        // The SurveyService marker is system-emitted at known case, so a
        // case-insensitive strip would risk eating operator-typed sentences
        // like "standard checks for this room" mid-narrative. Keep strict.
        $narrative = "Install new display.\n\nstandard checks for this solution type:\nRoom dimensions";

        $stripped = SurveyPdfHelpers::stripStandardChecksTail($narrative);

        $this->assertSame($narrative, $stripped);
    }
}
