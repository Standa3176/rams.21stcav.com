<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /design — internal design system reference.
 *
 * Admin-only (protected by the `admin` middleware alias registered in
 * bootstrap/app.php). A regular user hitting the route should be 403'd
 * or redirected, never 200. Guests must land on /login. Admins get
 * a full 200 with the token catalogue.
 */
class DesignGalleryTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/design')->assertRedirect(route('login'));
    }

    public function test_non_admin_user_is_forbidden(): void
    {
        $regular = User::factory()->create(['role' => 'user']);
        $this->actingAs($regular)
            ->get('/design')
            ->assertForbidden();
    }

    public function test_admin_gets_200_with_gallery_content(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $res = $this->actingAs($admin)->get('/design')->assertOk();

        // A handful of section titles proves the view rendered end-to-end.
        $res->assertSeeText('Design System');
        $res->assertSeeText('Colour tokens');
        $res->assertSeeText('Inter Variable');
        $res->assertSeeText('Action variants');
        $res->assertSeeText('Doc-kind chips');
    }
}
