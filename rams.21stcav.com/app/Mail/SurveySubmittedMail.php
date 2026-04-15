<?php

namespace App\Mail;

use App\Models\SiteSurvey;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * SurveySubmittedMail — sent to the project owner (or fallback admin) when
 * an engineer submits a site survey via the public link.
 *
 * Sent inline (not queued) so a queue worker is not required. Failure is caught
 * in SurveyService::submitPublic() and logged as a warning — the survey
 * submission is never rolled back due to a mail failure.
 */
class SurveySubmittedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly SiteSurvey $survey,
        public readonly int        $roomsCompleted,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Site Survey Submitted — {$this->survey->project_name} ({$this->survey->project_ref})",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.survey-submitted',
        );
    }
}
