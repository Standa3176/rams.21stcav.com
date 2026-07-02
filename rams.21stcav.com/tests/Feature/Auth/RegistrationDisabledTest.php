<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Security lockdown: the public registration flow was removed 2026-07-02
 * per audit finding C-03. This test suite locks that decision — if a
 * future commit accidentally reinstates the routes/controller/view, these
 * assertions fail at CI time before the change reaches production.
 *
 * If registration IS intentionally re-enabled in the future, delete this
 * test file AND update routes/auth.php to include the throttle, email
 * allow-list, admin-approval, and captcha guards documented in the C-03
 * remediation guidance.
 *
 * @see .planning/audits/security-audit-2026-05-17.md — finding C-03
 */
class RegistrationDisabledTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_register_returns_404(): void
    {
        $response = $this->get('/register');

        $response->assertNotFound();
    }

    public function test_post_register_returns_405_or_404(): void
    {
        // Without a matching route, Laravel returns 404 (not the 405 you
        // would see if only the verb was wrong on a still-registered
        // resource). Accept either — the point is the endpoint must not
        // create a user.
        $response = $this->post('/register', [
            'name'                  => 'Malicious User',
            'email'                 => 'attacker@example.com',
            'password'              => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertContains(
            $response->status(),
            [404, 405],
            'POST /register must not process — status was ' . $response->status()
        );
        $this->assertGuest();
    }

    public function test_register_route_is_not_registered_in_the_router(): void
    {
        // Route::has() is the same check the welcome blade uses to gate
        // the public "Register" link (welcome.blade.php:41). If this
        // assertion ever flips, the link auto-reappears on the landing
        // page — that IS the visible signal, but locking here catches it
        // in CI first.
        $this->assertFalse(
            \Illuminate\Support\Facades\Route::has('register'),
            'The `register` route name MUST NOT be registered — see audit C-03'
        );
    }

    public function test_registered_user_controller_file_does_not_exist(): void
    {
        // Guard against a partial revert that leaves the controller behind
        // even if the routes are dropped. The controller file being present
        // is a live-ammo hazard even without a route mapping (someone could
        // wire it up later or the composer optimize step could cache a
        // reference to it).
        //
        // Check the FILE path directly — class_exists() would consult the
        // composer autoload map which can be stale between rebuilds.
        $this->assertFileDoesNotExist(
            base_path('app/Http/Controllers/Auth/RegisteredUserController.php'),
            'RegisteredUserController.php must remain deleted — see audit C-03'
        );
    }
}
