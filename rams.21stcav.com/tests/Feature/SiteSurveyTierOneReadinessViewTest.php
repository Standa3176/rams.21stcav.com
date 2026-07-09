<?php

namespace Tests\Feature;

use App\Http\Controllers\PublicSurveyController;
use App\Models\SiteSurvey;
use App\Models\SiteSurveyPhoto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature-level view rendering tests for the Tier 1 readiness surface.
 *
 * Scope note: the prompt called out `resources/views/public-survey/show.blade.php`
 * and `resources/views/site-survey/show.blade.php`. Both files were edited.
 * The internal PM view (`site-surveys.show` route → SiteSurveyController::show
 * → site-survey.show blade) is exercised end-to-end here via the live route.
 * The public engineer view (public-survey.show) is no longer on the live
 * route table — `survey.show` resolves to SurveyController::show rendering the
 * Alpine wizard (`surveys/show.blade.php`). We still assert the public view's
 * markup by invoking PublicSurveyController::show() directly so the edit
 * delivered by this commit is exercised.
 */
class SiteSurveyTierOneReadinessViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Register a test-only route that invokes PublicSurveyController::show.
        // The live `survey.show` route resolves to SurveyController::show (the
        // Alpine wizard), so we need this to exercise the blade file we edited
        // in this commit with a real HTTP-request lifecycle (so $errors, view
        // composers, and the session are bound as Laravel expects).
        Route::middleware('web')
            ->get('/__test/public-survey/{token}', [PublicSurveyController::class, 'show'])
            ->name('test.public_survey.show');
    }

    /** @return array{user:User, survey:SiteSurvey, token:string, ready:\App\Models\SiteSurveyRoom, partial:\App\Models\SiteSurveyRoom} */
    private function makeSurveyWithMixedRooms(): array
    {
        $user  = User::factory()->create();
        $token = (string) Str::uuid();

        $survey = SiteSurvey::create([
            'user_id'      => $user->id,
            'project_name' => 'T1 UI Project',
            'client_name'  => 'Acme Ltd',
            'status'       => 'draft',
        ]);
        // Re-audit S-03 — access_token was dropped from $fillable, so
        // SiteSurvey::create() no longer accepts it. Set it via
        // forceFill() so the test can drive a known token through the
        // public URL. The boot::creating hook has already set a random
        // UUID; this overrides it.
        $survey->forceFill(['access_token' => $token])->save();

        // Fully-ready room.
        $ready = $survey->rooms()->create([
            'room_name'               => 'Ready Boardroom',
            'sort_order'              => 0,
            'room_width_m'            => 6,
            'room_depth_m'            => 4,
            'room_height_m'           => 3,
            'av_requirements'         => 'Samsung 75" display',
            'has_power'               => true,
            'power_outlet_count'      => 4,
            'has_network'             => true,
            'network_port_count'      => 2,
            'engineer_confirmed'      => true,
            'engineer_signature_name' => 'Jane Engineer',
        ]);
        SiteSurveyPhoto::create([
            'site_survey_room_id' => $ready->id,
            'filename'            => 'survey-photos/ready.jpg',
            'original_name'       => 'ready.jpg',
            'mime_type'           => 'image/jpeg',
            'sort_order'          => 0,
        ]);

        // Partial room: only AV scope filled → obvious misses.
        $partial = $survey->rooms()->create([
            'room_name'       => 'Partial Meeting Room',
            'sort_order'      => 1,
            'av_requirements' => 'Basic AV',
        ]);

        return compact('user', 'survey', 'token', 'ready', 'partial');
    }

    // ─── Public engineer view (invoked directly — route not bound to this controller) ──

    public function test_public_view_renders_tier1_summary_card(): void
    {
        ['token' => $token] = $this->makeSurveyWithMixedRooms();

        $response = $this->get('/__test/public-survey/' . $token);

        $response->assertOk();
        $response->assertSeeText('Tier 1 Readiness');
        $response->assertSeeText('rooms ready');
        $response->assertSeeText('1 / 2');
        $response->assertSeeText('missing item');
    }

    public function test_public_view_renders_ready_hint_for_ready_room(): void
    {
        ['token' => $token] = $this->makeSurveyWithMixedRooms();

        $response = $this->get('/__test/public-survey/' . $token);

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('tier-one-hint--ready', $html,
            'Expected the ready room to render the ready variant of the Tier-1 hint');
        $this->assertStringContainsString('Tier 1:', $html);
    }

    public function test_public_view_renders_percent_and_first_missing_items_for_partial_room(): void
    {
        ['token' => $token] = $this->makeSurveyWithMixedRooms();

        $response = $this->get('/__test/public-survey/' . $token);

        $response->assertOk();
        $html = $response->getContent();

        // Partial room is missing: dimensions, photos, engineer_sign_off.
        // First two humanised labels expected in the hint output.
        $this->assertStringContainsString('Room dimensions', $html);
        $this->assertStringContainsString('Room photo',      $html);
        $this->assertMatchesRegularExpression(
            '/<span class="tier-one-hint__pct">\d+%/', $html,
            'Partial room should expose a percent in the hint block',
        );
    }

    // ─── Internal PM view (exercised via live route) ─────────────────────────

    public function test_internal_view_shows_tier1_strip_with_stats(): void
    {
        ['user' => $user, 'survey' => $survey] = $this->makeSurveyWithMixedRooms();

        $response = $this->actingAs($user)->get(route('site-surveys.show', $survey));

        $response->assertOk();
        $response->assertSeeText('Tier 1 Readiness');
        $response->assertSeeText('Overall');
        $response->assertSeeText('Rooms Ready');
        $response->assertSeeText('Missing Items');
        $response->assertSeeText('1 / 2');
    }

    public function test_internal_view_shows_per_room_badges(): void
    {
        ['user' => $user, 'survey' => $survey] = $this->makeSurveyWithMixedRooms();

        $response = $this->actingAs($user)->get(route('site-surveys.show', $survey));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('tier-one-room-badge--ready',   $html);
        $this->assertStringContainsString('T1 · Ready',                   $html);
        $this->assertStringContainsString('tier-one-room-badge--partial', $html);
        $this->assertMatchesRegularExpression('/T1 · \d+% · \d+ missing/', $html);
    }
}
