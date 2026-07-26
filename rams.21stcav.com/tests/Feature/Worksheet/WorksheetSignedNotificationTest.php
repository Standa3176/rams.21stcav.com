<?php

namespace Tests\Feature\Worksheet;

use App\Mail\WorksheetSignedMail;
use App\Models\Project;
use App\Models\User;
use App\Models\Worksheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Quick task 260726-fx4 Task 3 — public worksheet sign-off must notify the
 * office (project owner via NotificationRecipientResolver) and stamp
 * signed_notification_sent_at only on successful mail send.
 *
 * Mirrors the SurveyService::submitPublic + WorksheetSignedMail pattern.
 */
class WorksheetSignedNotificationTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeWorksheet(?User $owner = null, ?Project $project = null): Worksheet
    {
        $owner   = $owner   ?? User::factory()->create(['email' => 'owner@example.test']);
        $project = $project ?? Project::factory()->create(['user_id' => $owner->id]);

        return Worksheet::create([
            'user_id'      => $owner->id,
            'project_id'   => $project->id,
            'project_name' => 'Acme Boardroom',
            'project_ref'  => 'Q-100001',
            'status'       => Worksheet::STATUS_DRAFT,
        ]);
    }

    private function pngBase64(): string
    {
        // 1x1 transparent PNG
        return 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
    }

    private function signViaController(Worksheet $w, string $b64): void
    {
        $this->post(route('public-worksheet.sign', ['token' => $w->access_token]), [
            'client_name'          => 'Charlie Client',
            'signature_image'      => 'data:image/png;base64,' . $b64,
            'happy_with_work'      => '1',
            'signed_with_comments' => '0',
            'comments'             => null,
        ]);
    }

    // ── Happy path: mail sent + timestamp stamped ────────────────────────────

    public function test_sign_dispatches_worksheet_signed_mail_and_stamps_timestamp(): void
    {
        Mail::fake();

        $owner = User::factory()->create(['email' => 'owner@example.test']);
        $w     = $this->makeWorksheet($owner);

        $this->signViaController($w, $this->pngBase64());

        Mail::assertSent(WorksheetSignedMail::class, function ($mail) use ($owner) {
            return $mail->hasTo($owner->email);
        });
        Mail::assertSent(WorksheetSignedMail::class, 1);

        $w->refresh();
        $this->assertNotNull($w->signed_notification_sent_at,
            'signed_notification_sent_at must be stamped after successful mail send');
    }

    // ── Mail failure path: no timestamp stamped ──────────────────────────────

    public function test_sign_leaves_timestamp_null_when_mail_send_fails(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.test']);
        $w     = $this->makeWorksheet($owner);

        // Force a mail failure by making the send() throw.
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP down'));

        $this->signViaController($w, $this->pngBase64());

        $w->refresh();
        $this->assertNull($w->signed_notification_sent_at,
            'timestamp must stay null so a retry can fire the mail next time');
        // But the signoff itself must have persisted — mail failure never
        // rolls back the client's acceptance.
        $this->assertSame(1, $w->signoffs()->count(),
            'sign-off is authoritative — mail failure does not undo it');
    }

    // ── Guard: signed_notification_sent_at is not $fillable ─────────────────

    public function test_signed_notification_sent_at_is_not_mass_assignable(): void
    {
        $w = $this->makeWorksheet();

        // Attempt to mass-assign the guarded field — must be silently dropped.
        $w->update(['signed_notification_sent_at' => now()]);

        $w->refresh();
        $this->assertNull($w->signed_notification_sent_at,
            'guarded flags must not be settable via $model->update()');
    }

    // ── Admin show view surfaces the "Office notified" pill ─────────────────

    public function test_show_view_renders_office_notified_pill_when_stamped(): void
    {
        Mail::fake();

        $owner = User::factory()->create(['email' => 'owner@example.test']);
        $w     = $this->makeWorksheet($owner);
        $this->signViaController($w, $this->pngBase64());

        $response = $this->actingAs($owner)
            ->get(route('worksheets.show', $w))
            ->assertOk();

        $response->assertSee('Office notified', false);
    }

    public function test_show_view_renders_office_not_notified_when_null(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.test']);
        $w     = $this->makeWorksheet($owner);

        // Sign directly (bypass controller so no mail loop fires) — timestamp stays null.
        $w->signoffs()->create([
            'client_name'          => 'C',
            'signature_png_base64' => 'abcd',
            'signed_with_comments' => false,
            'comments'             => null,
            'signed_at'            => now(),
        ]);

        $response = $this->actingAs($owner)
            ->get(route('worksheets.show', $w))
            ->assertOk();

        $response->assertSee('Office not notified', false);
    }
}
