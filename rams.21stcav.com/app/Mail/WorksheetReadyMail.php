<?php

namespace App\Mail;

use App\Models\Worksheet;
use App\Services\DocumentArtifactStorage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Completion notification for a generated engineering Worksheet (NOTF-01a).
 *
 * Dispatched from BuildWorksheetJob's success path by the trigger wiring
 * in plan 09-05. Queue-backed via ShouldQueue + SerializesModels (D-11).
 * BCC lives at the call site (Approach B).
 */
class WorksheetReadyMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Worksheet $worksheet) {}

    public function envelope(): Envelope
    {
        $ref     = (string) ($this->worksheet->project_ref ?? '');
        $bracket = $ref !== '' ? "[{$ref}] " : '';

        return new Envelope(
            subject: "{$bracket}Worksheet ready — {$this->worksheet->project_name}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.worksheet-ready');
    }

    public function attachments(): array
    {
        $filename = (string) ($this->worksheet->filename ?? '');
        if ($filename === '') {
            return [];
        }

        $path = app(DocumentArtifactStorage::class)
            ->readPath(DocumentArtifactStorage::TYPE_WORKSHEET, basename($filename));

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
