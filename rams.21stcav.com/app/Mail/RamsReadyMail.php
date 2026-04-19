<?php

namespace App\Mail;

use App\Models\RamsDocument;
use App\Services\DocumentArtifactStorage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Completion notification for a generated RAMS document (NOTF-01a).
 *
 * Dispatched from BuildRamsDocumentJob's success path by the trigger wiring
 * in plan 09-05. Implements ShouldQueue so Laravel serialises the model id
 * (per SerializesModels / D-11) and hands delivery off to the database
 * queue worker.
 *
 * BCC is applied at the call site (Approach B, RESEARCH "BCC Implementation
 * Pattern") — not inside this class — so recipient resolution stays
 * testable and the BCC toggle (RAMS_NOTIFICATION_BCC) lives alongside the
 * primary `to()`.
 */
class RamsReadyMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly RamsDocument $rams) {}

    public function envelope(): Envelope
    {
        $ref     = (string) ($this->rams->project_ref ?? '');
        $bracket = $ref !== '' ? "[{$ref}] " : '';

        return new Envelope(
            subject: "{$bracket}RAMS ready — {$this->rams->project_name}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.rams-ready');
    }

    public function attachments(): array
    {
        $filename = (string) ($this->rams->filename ?? '');
        if ($filename === '') {
            return [];
        }

        $path = app(DocumentArtifactStorage::class)
            ->readPath(DocumentArtifactStorage::TYPE_RAMS, basename($filename));

        if ($path === null) {
            return [];
        }

        return [
            Attachment::fromPath($path)
                ->as(basename($filename))
                ->withMime(
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                ),
        ];
    }
}
