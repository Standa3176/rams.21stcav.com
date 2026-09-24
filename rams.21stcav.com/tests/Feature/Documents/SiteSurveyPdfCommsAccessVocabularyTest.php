<?php

namespace Tests\Feature\Documents;

use App\Models\SiteSurvey;
use Tests\TestCase;

/**
 * D-46.2-04-02 — THE COMMS-ROOM ACCESS TICK BOXES, AND WHY THEY ARE 46.2-06's.
 *
 * `resources/views/pdf/site-survey/_header-meta.blade.php` compared
 * `comms_room_access_status` against `permission|outsourced|free`. The STORED
 * vocabulary is `yes,no,outsourced,unknown` — enforced in three places
 * (`PublicSurveyController:759`, `SiteSurveyController:681`,
 * `SurveyController:1096`) and mapped correctly by every other renderer
 * (`WorksheetDocxService:156`, `DocxBuilderService:711`, both using
 * yes => 'Permission required', no => 'Free access'). So two of the three boxes
 * on the survey PDF could NEVER tick, whatever the surveyor answered.
 *
 * The stored value was right. Only this Blade was wrong. It was logged by 46.2-04
 * and left logged because nothing surfaced the field — and then Plan 46.2-05 put
 * `comms_room_access_status` on the cockpit's own document form, which made a
 * silent render defect reachable by an ordinary PM in one click. That is what
 * moved it in scope: a defect nobody could trigger became a defect a user can.
 *
 * ── THE SECOND DEFECT IN THE SAME THREE LINES ─────────────────────────────
 *
 * The glyphs were `{{ $status === '…' ? '&#9745;' : '&#9744;' }}`. Blade's `{{ }}`
 * runs `e()` with `$doubleEncode = true`, so `&#9744;` left the renderer as
 * `&amp;#9744;` and the PDF printed the LITERAL TEXT `&#9744;` where a ballot box
 * belonged. Measured, not assumed — see the test below. Fixing only the
 * comparison would therefore have produced a "tick" that no reader could see, so
 * the two are one fix: the characters are now the ballot characters themselves,
 * which need no entity and survive `e()` untouched. NO raw `{!! !!}` was
 * introduced — that would have added an unescaped echo to a PDF Blade, which is
 * the exact thing T-46.2-16 exists to keep out.
 *
 * The rest of the file's static `&#9744;` glyphs sit OUTSIDE `{{ }}` and always
 * rendered correctly; they are untouched.
 */
class SiteSurveyPdfCommsAccessVocabularyTest extends TestCase
{
    /** The ballot characters, named once. U+2611 BALLOT BOX WITH CHECK, U+2610 BALLOT BOX. */
    private const TICKED = '☑';

    private const EMPTY_BOX = '☐';

    private function render(?string $status): string
    {
        $survey = new SiteSurvey(['comms_room_access_status' => $status]);

        return view('pdf.site-survey._header-meta', ['survey' => $survey])->render();
    }

    /** The single character inside the checkbox span that precedes a given label. */
    private function boxFor(string $html, string $label): string
    {
        $pattern = '/<span class="checkbox">(.*?)<\/span>\s*'.preg_quote($label, '/').'/u';

        $this->assertMatchesRegularExpression(
            $pattern,
            $html,
            "No checkbox renders before `{$label}` — the row's shape changed and this test is measuring nothing."
        );

        preg_match($pattern, $html, $m);

        return trim($m[1]);
    }

    /** @return array<string, string> label => glyph */
    private function boxes(?string $status): array
    {
        $html = $this->render($status);

        return [
            'Permission required' => $this->boxFor($html, 'Permission required'),
            'Outsourced'          => $this->boxFor($html, 'Outsourced'),
            'Free'                => $this->boxFor($html, 'Free'),
        ];
    }

    private function assertOnlyTicked(?string $status, ?string $expectedLabel): void
    {
        foreach ($this->boxes($status) as $label => $glyph) {
            $this->assertSame(
                $label === $expectedLabel ? self::TICKED : self::EMPTY_BOX,
                $glyph,
                "`{$status}` rendered `{$glyph}` against `{$label}`."
            );
        }
    }

    // ── The stored vocabulary, all four values ──────────────────────────────

    public function test_yes_ticks_permission_required(): void
    {
        // `yes` is the stored value for "permission required" everywhere else in
        // the app. Before this fix it ticked NOTHING.
        $this->assertOnlyTicked('yes', 'Permission required');
    }

    public function test_no_ticks_free(): void
    {
        // `no` means no permission needed — "Free access" in the canonical label
        // map. Before this fix it ticked NOTHING.
        $this->assertOnlyTicked('no', 'Free');
    }

    public function test_outsourced_ticks_outsourced(): void
    {
        // The one value that always worked, asserted so the fix cannot break it.
        $this->assertOnlyTicked('outsourced', 'Outsourced');
    }

    public function test_unknown_and_null_tick_nothing(): void
    {
        // Correct, not a gap: the row offers three boxes and `unknown` is not one
        // of them. A fourth box is a content decision, not a render fix.
        $this->assertOnlyTicked('unknown', null);
        $this->assertOnlyTicked(null, null);
    }

    // ── The glyph itself ────────────────────────────────────────────────────

    /**
     * The defect that made the first fix invisible. `{{ }}` double-encodes, so a
     * numeric entity inside it reaches the page as `&amp;#9744;` and PRINTS as
     * `&#9744;`. Asserted directly, because "the box is ticked" and "the reader
     * can see it is ticked" are two different claims.
     */
    public function test_the_checkbox_is_a_ballot_character_and_never_a_literal_entity(): void
    {
        $html = $this->render('yes');

        $ticked = $this->boxFor($html, 'Permission required');

        $this->assertSame(self::TICKED, $ticked);
        $this->assertStringNotContainsString('&amp;#9745;', $html, 'A double-encoded entity prints as text in the PDF.');
        $this->assertStringNotContainsString('&amp;#9744;', $html, 'A double-encoded entity prints as text in the PDF.');
    }

    /**
     * NO RAW ECHO WAS ADDED. T-46.2-16's concern is user text reaching Browsershot
     * unescaped; this fix must not become the first place it can.
     */
    public function test_the_fix_introduced_no_unescaped_output(): void
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
