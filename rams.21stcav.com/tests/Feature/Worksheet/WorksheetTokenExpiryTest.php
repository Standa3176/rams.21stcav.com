<?php

namespace Tests\Feature\Worksheet;

use App\Models\Project;
use App\Models\User;
use App\Models\Worksheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Audit M-05 (2026-05-17) — worksheet access-token expiry + revoke coverage.
 *
 * Contract:
 *  - Null `access_token_expires_at` = never expires (default; legacy rows).
 *  - Past `access_token_expires_at` = 410 Gone from every public route.
 *  - Future `access_token_expires_at` = still valid.
 *  - Admin `worksheets.revoke-token` action regenerates the UUID + clears
 *    `access_token_expires_at`. Any leaked copy of the old URL is inert
 *    immediately (its token no longer resolves to any row → 404).
 *  - Unauthenticated users cannot hit the revoke route.
 */
class WorksheetTokenExpiryTest extends TestCase
{
    use RefreshDatabase;

    private function makeWorksheet(array $overrides = []): Worksheet
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        return Worksheet::create(array_merge([
            'user_id'      => $user->id,
            'project_id'   => $project->id,
            'project_name' => 'Acme Boardroom AV Refresh',
            'project_ref'  => 'Q-100001',
            'client_name'  => 'Acme Co.',
            'site_address' => '1 Test Street, London',
            'status'       => Worksheet::STATUS_DRAFT,
            'generated_data' => [
                'project' => ['name' => 'Acme Boardroom AV Refresh'],
                'rooms'   => [['name' => 'Boardroom', 'is_surveyed' => true, 'equipment' => []]],
            ],
        ], $overrides));
    }

    // ─── Model helpers ──────────────────────────────────────────────────────

    public function test_default_worksheet_never_expires(): void
    {
        $w = $this->makeWorksheet();

        $this->assertNull($w->access_token_expires_at);
        $this->assertFalse($w->isTokenExpired());
    }

    public function test_worksheet_with_future_expiry_is_not_expired(): void
    {
        $w = $this->makeWorksheet([
            'access_token_expires_at' => now()->addDays(7),
        ]);

        $this->assertFalse($w->isTokenExpired());
    }

    public function test_worksheet_with_past_expiry_is_expired(): void
    {
        $w = $this->makeWorksheet([
            'access_token_expires_at' => now()->subMinute(),
        ]);

        $this->assertTrue($w->isTokenExpired());
    }

    public function test_regenerate_access_token_rotates_uuid_and_clears_expiry(): void
    {
        $w = $this->makeWorksheet([
            'access_token_expires_at' => now()->addDay(),
        ]);
        $oldToken = $w->access_token;

        $w->regenerateAccessToken();

        $this->assertNotSame($oldToken, $w->access_token);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $w->access_token,
        );
        $this->assertNull($w->access_token_expires_at);

        // Persisted, not just on the in-memory instance.
        $w->refresh();
        $this->assertNotSame($oldToken, $w->access_token);
        $this->assertNull($w->access_token_expires_at);
    }

    // ─── Public routes reject expired tokens ────────────────────────────────

    public function test_public_show_returns_410_when_token_is_expired(): void
    {
        $w = $this->makeWorksheet([
            'access_token_expires_at' => now()->subMinute(),
        ]);

        $response = $this->get(route('public-worksheet.show', ['token' => $w->access_token]));

        $response->assertStatus(410);
    }

    public function test_public_show_still_serves_when_token_expiry_is_in_the_future(): void
    {
        $w = $this->makeWorksheet([
            'access_token_expires_at' => now()->addDay(),
        ]);

        $response = $this->get(route('public-worksheet.show', ['token' => $w->access_token]));

        $response->assertOk();
    }

    public function test_public_sign_returns_410_when_token_is_expired(): void
    {
        $w = $this->makeWorksheet([
            'access_token_expires_at' => now()->subMinute(),
        ]);

        $response = $this->post(route('public-worksheet.sign', ['token' => $w->access_token]), [
            'client_name'          => 'Late Client',
            'signature_image'      => 'data:image/png;base64,AAAA',
            'happy_with_work'      => '1',
        ]);

        $response->assertStatus(410);
        $this->assertSame(0, $w->signoffs()->count());
    }

    public function test_after_revoke_the_old_token_returns_404_and_the_new_token_works(): void
    {
        $w = $this->makeWorksheet();
        $oldToken = $w->access_token;

        $w->regenerateAccessToken();
        $newToken = $w->access_token;

        // The old URL is now inert — the token no longer matches any row.
        $this->get(route('public-worksheet.show', ['token' => $oldToken]))
            ->assertNotFound();

        // The new URL serves normally.
        $this->get(route('public-worksheet.show', ['token' => $newToken]))
            ->assertOk();
    }

    // ─── Admin revoke route ────────────────────────────────────────────────

    public function test_admin_revoke_route_rotates_token_and_flashes_success(): void
    {
        $w = $this->makeWorksheet();
        $oldToken = $w->access_token;

        $this->actingAs(User::find($w->user_id));

        $response = $this->post(route('worksheets.revoke-token', $w));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $w->refresh();
        $this->assertNotSame($oldToken, $w->access_token);
        $this->assertNull($w->access_token_expires_at);
    }

    public function test_admin_revoke_route_rejects_unauthenticated_requests(): void
    {
        $w = $this->makeWorksheet();
        $oldToken = $w->access_token;

        $response = $this->post(route('worksheets.revoke-token', $w));

        // Unauthenticated → redirected to login (Laravel Breeze auth middleware).
        $response->assertRedirect(route('login'));

        // Token is unchanged.
        $w->refresh();
        $this->assertSame($oldToken, $w->access_token);
    }

    public function test_admin_show_page_exposes_revoke_button_form(): void
    {
        $w = $this->makeWorksheet();

        $this->actingAs(User::find($w->user_id));

        $response = $this->get(route('worksheets.show', $w));

        $response->assertOk();
        $response->assertSee(route('worksheets.revoke-token', $w), false);
        $response->assertSee('Revoke &amp; regenerate', false);
    }
}
