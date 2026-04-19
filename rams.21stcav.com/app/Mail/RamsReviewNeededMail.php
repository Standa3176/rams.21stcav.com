<?php

namespace App\Mail;

use App\Models\RamsDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * RamsReviewNeededMail — sent once per ExtractRamsDraftJob success when a RAMS
 * document hits STATUS_AWAITING_REVIEW (NOTF-03 / D-04). Dispatched in plan 09-05.
 *
 * ShouldQueue: background-dispatched. Recipient resolution happens at the trigger
 * site via NotificationRecipientResolver::resolveProjectRecipient($project).
 *
 * No attachments — the artifact has not been generated yet at this stage of the
 * pipeline (awaiting_review is pre-generation). Body contains a link to
 * route('rams.review', $rams) so the engineer can open the review screen (NOTF-03b).
 */
class RamsReviewNeededMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly RamsDocument $rams) {}

    public function envelope(): Envelope
    {
        $ref     = $this->rams->project_ref ?: '';
        $bracket = $ref !== '' ? "[{$ref}] " : '';

        return new Envelope(
            subject: "{$bracket}RAMS ready for review — {$this->rams->project_name}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.rams-review-needed');
    }
}
