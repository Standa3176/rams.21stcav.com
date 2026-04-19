<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * DocumentGenerationFailedMail — polymorphic failure alert dispatched from the
 * `failed()` hook of each Build*Job across the four document pipelines.
 *
 * Uses PRIMITIVE constructor args (string/nullable-string) rather than an
 * Eloquent model — this keeps one mailable class polymorphic across all four
 * document types and avoids SerializesModels quirks that arise when the same
 * mailable must serialize different model classes across invocations
 * (see 09-RESEARCH.md "Recommended Class Structure" final bullet).
 *
 * Subject prefix `[FAILED]` makes admin inboxes easy to filter or route.
 * The caller (plan 09-05 trigger site) is responsible for truncating
 * errorMessage to ≤500 chars per NOTF-04c before instantiating this mailable.
 *
 * @see NOTF-04, NOTF-04a, NOTF-04c
 */
class DocumentGenerationFailedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string  $documentType,    // human-readable doc-type label shown in subject + body
        public readonly ?string $projectRef,
        public readonly string  $projectName,
        public readonly ?string $errorMessage,    // already truncated by caller
        public readonly string  $detailUrl,
    ) {}

    public function envelope(): Envelope
    {
        $ref     = $this->projectRef ?: '';
        $bracket = $ref !== '' ? "[{$ref}] " : '';

        return new Envelope(
            subject: "[FAILED] {$bracket}{$this->documentType} generation failed — {$this->projectName}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.document-generation-failed');
    }
}
