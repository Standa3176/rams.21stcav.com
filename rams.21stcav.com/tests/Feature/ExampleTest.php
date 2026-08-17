<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Smoke test for the root route. This is an internal-only app with no
     * public marketing landing (see routes/web.php) — a guest hitting `/`
     * is redirected to the login screen rather than getting a 200. The
     * stock Laravel scaffold assertion (200) was never updated when that
     * redirect was added.
     */
    public function test_the_application_redirects_guests_to_login(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('login'));
    }
}
