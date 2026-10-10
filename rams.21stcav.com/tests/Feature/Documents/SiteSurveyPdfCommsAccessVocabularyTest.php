<?php

namespace Tests\Feature\Documents;

use App\Models\SiteSurvey;
use Tests\TestCase;

/**
 * D-46.2-04-02 — THE COMMS-ROOM ACCESS TICK BOXES, AND WHY THEY WERE 46.2-06's.
 *
 * `resources/views/pdf/site-survey/_header-meta.blade.php` used to compare
 * `comms_room_access_status` against `permission|outsourced|free`. The STORED
 * vocabulary is `yes,no,outsourced,unknown`, so two of the three boxes on the
 * survey PDF could never tick. That defect is now moot: 46.8 D-02 removed the
 * comms-room row from this Blade entirely (it overrides 46.5 D-03's
 * "keep comms room everywhere" ruling, specifically for this field). The
 * `comms_room_access_status` COLUMN is untouched on `site_surveys` — D-03
 * forbids any migration or write path — this file only proves the RENDER is
 * gone, for every stored status, for any value that column can hold.
 *
 * See `.planning/phases/46.8-survey-capture-refinements/46.8-CONTEXT.md`
 * D-02/D-03.
 */
class SiteSurveyPdfCommsAccessVocabularyTest extends TestCase
{
    private function render(?string $status): string
    {
        $survey = new SiteSurvey(['comms_room_access_status' => $status]);

        return view('pdf.site-survey._header-meta', ['survey' => $survey])->render();
    }

    /**
     * All four stored statuses, plus null, render no comms-room row at all —
     * no label, no checkbox, no ticked/unticked glyph for it.
     */
    public function test_comms_room_access_never_renders_for_any_stored_status(): void
    {
        foreach (['yes', 'no', 'outsourced', 'unknown', null] as $status) {
            $html = $this->render($status);

            $this->assertStringNotContainsString(
                'Comms room access',
                $html,
                "Status '{$status}' must not render a Comms room access row (46.8 D-02)."
            );
            $this->assertStringNotContainsString('Permission required', $html);
            $this->assertStringNotContainsString('Outsourced', $html);
        }
    }

    /**
     * The "Distance from base" row, immediately below comms room in the
     * markup, is NOT in 46.8-02's scope and must still render exactly as
     * before — a sibling row disappearing alongside comms room would be this
     * plan accidentally widening its own diff.
     */
    public function test_distance_from_base_row_is_unaffected(): void
    {
        $survey = new SiteSurvey([
            'comms_room_access_status' => 'yes',
            'distance_from_base_miles' => '42',
            'distance_from_base_notes' => 'M4 J11 then ten minutes',
        ]);

        $html = view('pdf.site-survey._header-meta', ['survey' => $survey])->render();

        $this->assertStringContainsString('Distance from base', $html);
        $this->assertStringContainsString('42', $html);
        $this->assertStringContainsString('M4 J11 then ten minutes', $html);
    }

    /**
     * NO RAW ECHO WAS ADDED BY THIS REMOVAL. T-46.2-16's concern is user text
     * reaching Browsershot unescaped; deleting markup must not become the
     * first place a new raw echo sneaks in.
     */
    public function test_the_removal_introduced_no_unescaped_output(): void
    {
        $blade = file_get_contents(
            resource_path('views/pdf/site-survey/_header-meta.blade.php')
        );

        $this->assertNotFalse($blade);

        // Every raw echo in this file is `H::` helper output, which escapes its
        // own user text (`H::blank()` returns `e($value)`). Not one of them is a
        // bare model attribute.
        preg_match_all('/\{!!(.*?)!!\}/s', (string) $blade, $matches);

        foreach ($matches[1] as $expression) {
            $this->assertMatchesRegularExpression(
                '/^\s*H::/',
                $expression,
                "A raw echo in _header-meta.blade.php is not an escaping helper: `{$expression}`"
            );
        }
    }
}
