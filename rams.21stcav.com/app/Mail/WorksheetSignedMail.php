<?php

namespace App\Mail;

use App\Models\Worksheet;
use App\Models\WorksheetSignoff;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * WorksheetSignedMail — sent to the project owner (or fallback admin) when
 * a client signs off a worksheet via the public link.
 *
 * Sent inline (not queued) so a queue worker is not required. Failure is
 * caught in PublicWorksheetController::sign() and logged as a warning — the
 * sign-off is never rolled back due to a mail failure, and
 * signed_notification_sent_at stays null so the show-view falls back to
 * "Office not notified" (a future retry can then re-trigger).
 *
 * Quick task 260726-fx4 Task 3.
 */
class WorksheetSignedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Worksheet        $worksheet,
        public readonly WorksheetSignoff $signoff,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Worksheet Signed — {$this->worksheet->project_name}"
                . ($this->worksheet->project_ref ? " ({$this->worksheet->project_ref})" : ''),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.worksheet-signed',
        );
    }
}
