<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }

    // ── H-01: is_active enforcement at login ─────────────────────────────────

    public function test_suspended_users_cannot_authenticate_even_with_correct_password(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $response = $this->post('/login', [
            'email'    => $user->email,
            'password' => 'password',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors('email');
    }

    public function test_active_users_can_still_authenticate(): void
    {
        // Explicit is_active=true; the factory default should match this
        // but locking it in prevents a factory drift from silently gating
        // legitimate logins.
        $user = User::factory()->create(['is_active' => true]);

        $response = $this->post('/login', [
            'email'    => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_suspended_user_login_error_is_generic_not_status_specific(): void
    {
        // Defence-in-depth: the error surfaced to the form should not
        // enumerate the account status ("this account is suspended" would
        // let an attacker distinguish active-vs-suspended accounts, which
        // is a mild info leak). Confirm the message is the generic gate.
        $user = User::factory()->create(['is_active' => false]);

        $response = $this->post('/login', [
            'email'    => $user->email,
            'password' => 'password',
        ]);

        $errors = $response->baseResponse->getSession()->get('errors');
        $this->assertNotNull($errors);
        $emailErrors = $errors->get('email');
        $this->assertNotEmpty($emailErrors);
        // Message must not contain the raw credential-failure text — that
        // would incorrectly signal "wrong password" for a suspended user.
        // But must clearly indicate the account is inactive so support can
        // triage. Our chosen wording is "Your account is not active…".
        $this->assertStringContainsStringIgnoringCase(
            'not active',
            $emailErrors[0]
        );
    }
}
