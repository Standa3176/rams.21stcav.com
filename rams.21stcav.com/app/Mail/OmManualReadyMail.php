<?php

namespace App\Mail;

use App\Models\OmManual;
use App\Services\DocumentArtifactStorage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Completion notification for a generated O&M Manual (NOTF-01a).
 *
 * Dispatched from BuildOmManualJob's success path by the trigger wiring
 * in plan 09-05. Queue-backed via ShouldQueue + SerializesModels (D-11).
 * BCC lives at the call site (Approach B).
 */
class OmManualReadyMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly OmManual $manual) {}

    public function envelope(): Envelope
    {
        $ref     = (string) ($this->manual->project_ref ?? '');
        $bracket = $ref !== '' ? "[{$ref}] " : '';

        return new Envelope(
            subject: "{$bracket}O&M Manual ready — {$this->manual->project_name}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.om-manual-ready');
    }

    public function attachments(): array
    {
        $filename = (string) ($this->manual->filename ?? '');
        if ($filename === '') {
            return [];
        }

        $path = app(DocumentArtifactStorage::class)
            ->readPath(DocumentArtifactStorage::TYPE_OM, basename($filename));

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
