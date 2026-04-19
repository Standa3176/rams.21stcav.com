<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Project;
use App\Models\User;
use App\Services\NotificationRecipientResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for NotificationRecipientResolver.
 *
 * Locks the Phase 09 recipient-resolution contract against two latent bugs
 * already present in app/Core/Modules/Survey/SurveyService.php:406-407:
 *
 *   1. Admin lookup must use User->role = 'admin'  (NOT is_admin = true —
 *      that column does not exist on the users table — RESEARCH Pitfall 1).
 *   2. Project owner lookup must use Project->owner relation (NOT ->user —
 *      that relation does not exist on the Project model — RESEARCH Pitfall 2).
 *
 * Also covers the full five-branch fallback tree plus the admin-collection
 * accessor used by failure-alert mailables (NOTF-05a, NOTF-05b).
 *
 * Uses RefreshDatabase + SQLite in-memory so real Eloquent queries exercise
 * the schema and catch column-name drift instantly.
 *
 * @see NOTF-05a, NOTF-05b, NOTF-05g
 * @see app/Services/NotificationRecipientResolver.php
 */
class NotificationRecipientResolverTest extends TestCase
{
    use RefreshDatabase;

    private NotificationRecipientResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new NotificationRecipientResolver();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // resolveProjectRecipient — five-branch fallback tree
    // ─────────────────────────────────────────────────────────────────────────

    public function test_returns_owner_when_project_has_owner_with_email(): void
    {
        $owner   = User::factory()->create(['email' => 'owner@example.test']);
        $project = Project::factory()->create(['user_id' => $owner->id]);

        $result = $this->resolver->resolveProjectRecipient($project);

        $this->assertNotNull($result);
        $this->assertSame($owner->id, $result->id);
        $this->assertSame('owner@example.test', $result->email);
    }

    public function test_falls_back_to_first_admin_when_project_owner_is_null(): void
    {
        // Make sure no stray admin from prior tests — RefreshDatabase gives a
        // clean users table, but we create our two admins explicitly with
        // controllable ids.
        $firstAdmin  = User::factory()->create(['role' => 'admin', 'email' => 'first-admin@example.test']);
        $secondAdmin = User::factory()->create(['role' => 'admin', 'email' => 'second-admin@example.test']);

        // projects.user_id is NOT NULL with cascadeOnDelete FK, so we cannot
        // save a Project without an owner. Use an in-memory Project (no DB
        // row) with user_id cleared — loadMissing('owner') then resolves to
        // null because the FK points nowhere. This mirrors the production
        // "job-failed alert with no project context" path.
        $project          = Project::factory()->make();
        $project->user_id = null;

        $result = $this->resolver->resolveProjectRecipient($project);

        $this->assertNotNull($result);
        $this->assertSame($firstAdmin->id, $result->id, 'Expected first admin (lowest id)');
        $this->assertLessThan($secondAdmin->id, $firstAdmin->id);
    }

    public function test_returns_first_admin_when_project_argument_is_null(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'admin@example.test']);

        $result = $this->resolver->resolveProjectRecipient(null);

        $this->assertNotNull($result);
        $this->assertSame($admin->id, $result->id);
    }

    public function test_falls_back_to_admin_when_project_owner_has_no_email(): void
    {
        // Owner exists but has an empty email — treat owner as missing and
        // fall back to admin. The users.email column is declared NOT NULL
        // at the schema level (see 0001_01_01_000000_create_users_table.php),
        // so "no email" in practice means an empty string rather than null.
        // The resolver treats both null and '' as falsy and falls through to
        // the admin lookup.
        $owner = User::factory()->create([
            'email' => 'placeholder+tmp@example.test',
        ]);
        $owner->email = '';
        $owner->save();

        $admin = User::factory()->create(['role' => 'admin', 'email' => 'admin@example.test']);

        $project = Project::factory()->create(['user_id' => $owner->id]);

        $result = $this->resolver->resolveProjectRecipient($project);

        $this->assertNotNull($result);
        $this->assertSame($admin->id, $result->id, 'Expected admin fallback when owner has empty email');
    }

    public function test_returns_null_when_no_owner_and_no_admin(): void
    {
        // Delete any admins the environment might seed (defensive — Phase 08
        // tests sometimes seed an admin).
        User::where('role', 'admin')->delete();

        // In-memory Project with no owner — see note in the "owner null"
        // test. This is the only way to exercise the "no owner + no admin"
        // branch given the projects.user_id NOT NULL FK.
        $project          = Project::factory()->make();
        $project->user_id = null;

        $result = $this->resolver->resolveProjectRecipient($project);

        $this->assertNull($result);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Regression lock — the two latent bug patterns from SurveyService
    // ─────────────────────────────────────────────────────────────────────────

    public function test_admin_lookup_uses_role_column_not_is_admin(): void
    {
        // Create one regular user and one admin. If the resolver ever reverts
        // to `User::where('is_admin', true)` (bug pattern from SurveyService),
        // the query will fail with an SQL column-not-found error on SQLite —
        // or, worse on MySQL with strict mode off, silently return no rows.
        User::factory()->create(['role' => 'user',  'email' => 'not-admin@example.test']);
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'real-admin@example.test']);

        $result = $this->resolver->resolveProjectRecipient(null);

        $this->assertNotNull($result, 'Resolver should have found the admin via role column');
        $this->assertSame($admin->id, $result->id);
        $this->assertSame('admin', $result->role);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // resolveAdminRecipients — used by failure-alert mailables (NOTF-05b)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_resolve_admin_recipients_returns_only_admins_with_email(): void
    {
        // Clear any pre-seeded admins so the count is deterministic.
        User::where('role', 'admin')->delete();

        $adminWithEmail = User::factory()->create(['role' => 'admin', 'email' => 'alerts@example.test']);

        // Admin with empty email — users.email is NOT NULL at the schema
        // level, so "no email" = empty string in practice. The resolver must
        // filter these out (a mail-send to '' would hard-fail at runtime).
        $adminNoEmail = User::factory()->create(['role' => 'admin', 'email' => 'placeholder@example.test']);
        $adminNoEmail->email = '';
        $adminNoEmail->save();

        User::factory()->create(['role' => 'user', 'email' => 'regular@example.test']);

        $result = $this->resolver->resolveAdminRecipients();

        $this->assertCount(1, $result, 'Only the admin with a usable email should be returned');
        $this->assertSame($adminWithEmail->id, $result->first()->id);
    }
}
