<?php

namespace App\Mail;

use App\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 20 Plan 01 — completion notification for a project's bound multi-page
 * PDF (DRAW-21).
 *
 * Mirrors DrawingReadyMail's shape but is PROJECT-LEVEL rather than
 * drawing-level — bound PDFs aggregate every drawing in the project, so the
 * subject line + attachment reference the project, not a single drawing.
 *
 * Subject: "[ref] Project drawings ready — {projectName}"
 *
 * BCC is applied at the call site (BuildBoundPdfJob) — same Approach B
 * pattern as DrawingReadyMail / RamsReadyMail.
 *
 * @see app/Jobs/BuildBoundPdfJob.php — dispatch + idempotency context
 */
class BoundPdfReadyMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Project $project,
        public readonly string  $boundPdfPath,
    ) {}

    public function envelope(): Envelope
    {
        $ref     = (string) ($this->project->ref ?? '');
        $bracket = $ref !== '' ? "[{$ref}] " : '';
        $name    = (string) ($this->project->name ?? '');

        return new Envelope(
            subject: "{$bracket}Project drawings ready — {$name}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.bound-pdf-ready');
    }

    public function attachments(): array
    {
        if (! is_file($this->boundPdfPath)) {
            return [];
        }

        return [
            Attachment::fromPath($this->boundPdfPath)
                ->as(basename($this->boundPdfPath))
                ->withMime('application/pdf'),
        ];
    }
}
