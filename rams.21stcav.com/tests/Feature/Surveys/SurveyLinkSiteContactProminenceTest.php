<?php

namespace Tests\Feature\Surveys;

use App\Models\Project;
use App\Models\SiteSurvey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Quick task 261008-mb9 — the engineer link's site install address must be
 * genuinely prominent (never clipped by `truncate`), and the site contact's
 * name + phone must be surfaced on the SITE tab, with a visible placeholder
 * when either is blank — the common case on most existing surveys.
 *
 * Fixture helpers below are copied verbatim (not extended) from
 * SurveyLinkBrandingTest, whose `survey()`/`render()` are private to that
 * class, matching the convention SurveyLinkTabbedLayoutTest already set.
 */
class SurveyLinkSiteContactProminenceTest extends TestCase
{
    use RefreshDatabase;

    // ── Fixtures (copied verbatim from SurveyLinkBrandingTest) ─────────────

    /**
     * @param  array<int, string>  $rooms
     */
    private function survey(array $rooms = ['Board Room', 'Huddle 1'], ?callable $tweak = null): string
    {
        $user    = User::factory()->create();
        $token   = (string) Str::uuid();
        $project = Project::factory()->create([
            'name'         => 'Branding Job',
            'ref'          => 'Q-4701',
            'site_address' => '12 Wharf Road, Leeds',
        ]);

        $survey = SiteSurvey::create([
            'user_id'      => $user->id,
            'project_id'   => $project->id,
            'project_name' => 'Branding Job',
            'site_address' => '12 Wharf Road, Leeds',
            'status'       => 'draft',
        ]);
        $survey->forceFill(['access_token' => $token])->save();

        foreach (array_values($rooms) as $i => $name) {
            $survey->rooms()->create([
                'room_name'  => $name,
                'space_type' => 'general',
                'sort_order' => $i,
            ]);
        }

        if ($tweak !== null) {
            $tweak($survey);
        }

        return $token;
    }

    private function render(string $token): string
    {
        return $this->get(route('survey.show', ['token' => $token]))
            ->assertOk()
            ->getContent();
    }

    /**
     * Returns the opening `<p ...>` tag that most closely precedes (and
     * encloses) the given needle in the HTML — used to assert a specific
     * line lost `truncate` without depending on exact whitespace.
     */
    private function enclosingParagraphTag(string $html, string $needle): string
    {
        $needlePos = strpos($html, $needle);
        $this->assertNotFalse($needlePos, 'Needle not found in HTML at all: '.$needle);

        $pTagPos = strrpos(substr($html, 0, $needlePos), '<p ');
        $this->assertNotFalse($pTagPos, 'No enclosing <p ...> tag found before needle.');

        $tagEnd = strpos($html, '>', $pTagPos);

        return substr($html, $pTagPos, $tagEnd - $pTagPos + 1);
    }

    // ── Test A — long address, not clipped, appears in both locations ──────

    public function test_long_address_is_not_truncated_and_appears_in_masthead_and_site_tab(): void
    {
        $longAddress = '4th Floor, 65 Chandos Place, Covent Garden, London, WC2N 4HG, United Kingdom';

        $token = $this->survey(['Board Room'], function (SiteSurvey $s) use ($longAddress): void {
            $s->forceFill(['site_address' => $longAddress])->save();
        });

        $html = $this->render($token);

        $masthead = $this->enclosingParagraphTag($html, $longAddress);
        $this->assertStringNotContainsString(
            'truncate',
            $masthead,
            'Masthead address paragraph still carries truncate: '.$masthead
        );

        $this->assertGreaterThanOrEqual(
            2,
            substr_count($html, $longAddress),
            'Long address must appear at least twice — masthead and SITE tab card.'
        );
    }

    // ── Test B — contact name + UK-normalised tel: link on the SITE tab ────

    public function test_contact_name_and_uk_normalised_tel_link_render_on_site_tab(): void
    {
        $token = $this->survey(['Board Room'], function (SiteSurvey $s): void {
            $s->forceFill([
                'site_contact_name'  => 'Jane Doe',
                'site_contact_phone' => '07911 123456',
            ])->save();
        });

        $html = $this->render($token);

        $siteTabPos = strpos($html, 'data-tab="site"');
        $this->assertNotFalse($siteTabPos, 'SITE tab panel not found.');
        $siteTabHtml = substr($html, $siteTabPos);

        $this->assertStringContainsString('Jane Doe', $siteTabHtml);
        $this->assertStringContainsString('href="tel:+447911123456"', $siteTabHtml);
    }

    // ── Test C — the common case: all three fields blank ────────────────────

    public function test_blank_address_and_contact_render_clean_placeholders(): void
    {
        // The default survey() fixture seeds a non-blank site_address, so the
        // common case (all three fields blank) must be produced explicitly.
        $token = $this->survey(['Board Room'], function (SiteSurvey $s): void {
            $s->forceFill([
                'site_address'       => '',
                'site_contact_name'  => '',
                'site_contact_phone' => '',
            ])->save();
        });

        $html = $this->render($token);

        $siteTabPos = strpos($html, 'data-tab="site"');
        $this->assertNotFalse($siteTabPos, 'SITE tab panel not found.');
        $siteTabHtml = substr($html, $siteTabPos);

        $this->assertStringContainsString('Site address not provided', $siteTabHtml);
        $this->assertStringContainsString('Site contact not provided', $siteTabHtml);

        $this->assertStringNotContainsString('href="tel:"', $html);
    }

    // ── Test D — hostile contact name is escaped ─────────────────────────────

    public function test_hostile_contact_name_is_escaped(): void
    {
        $token = $this->survey(['Board Room'], function (SiteSurvey $s): void {
            $s->forceFill([
                'site_contact_name'  => '<script>alert(1)</script>',
                'site_contact_phone' => '07911 123456',
            ])->save();
        });

        $html = $this->render($token);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    // ── Test E — the masthead contact line also loses truncate (decision a) ─

    public function test_masthead_contact_line_is_not_truncated(): void
    {
        $longName  = 'Alexandra Montgomery-Fitzwilliam-Pemberton';
        $longPhone = '07911 123456';

        $token = $this->survey(['Board Room'], function (SiteSurvey $s) use ($longName, $longPhone): void {
            $s->forceFill([
                'site_contact_name'  => $longName,
                'site_contact_phone' => $longPhone,
            ])->save();
        });

        $html = $this->render($token);

        $contactLinePos = strpos($html, 'Site contact:');
        $this->assertNotFalse($contactLinePos, 'Masthead "Site contact:" line not found.');

        $pTagPos = strrpos(substr($html, 0, $contactLinePos), '<p ');
        $this->assertNotFalse($pTagPos, 'No enclosing <p ...> tag found before "Site contact:" line.');
        $tagEnd = strpos($html, '>', $pTagPos);
        $contactTag = substr($html, $pTagPos, $tagEnd - $pTagPos + 1);

        $this->assertStringNotContainsString(
            'truncate',
            $contactTag,
            'Masthead "Site contact:" paragraph still carries truncate: '.$contactTag
        );
    }
}
