<?php

namespace App\Mail;

use App\Models\ProjectDrawing;
use App\Services\DocumentArtifactStorage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Completion notification for a generated drawing — single mailable
 * branching on $drawing->kind for subject/body. Mirrors RamsReadyMail and
 * the v1.1 *ReadyMail pattern.
 *
 * Three subject branches:
 *   schematic  → "[ref] Schematic ready — {projectName}"
 *   rack       → "[ref] Rack elevation ready — {projectName}"
 *   floor_plan → "[ref] Floor plan ready — {projectName}"
 *
 * BCC is applied at the call site (BuildSchematicJob) — same Approach B
 * pattern as RamsReadyMail.
 */
class DrawingReadyMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly ProjectDrawing $drawing) {}

    public function envelope(): Envelope
    {
        $project = $this->drawing->project;
        $ref = (string) ($project->ref ?? '');
        $bracket = $ref !== '' ? "[{$ref}] " : '';
        $name = (string) ($project->name ?? '');

        $kindLabel = match ($this->drawing->kind) {
            ProjectDrawing::KIND_SCHEMATIC => 'Schematic ready',
            ProjectDrawing::KIND_RACK => 'Rack elevation ready',
            ProjectDrawing::KIND_FLOOR_PLAN => 'Floor plan ready',
            default => 'Drawing ready',
        };

        return new Envelope(
            subject: "{$bracket}{$kindLabel} — {$name}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.drawing-ready');
    }

    public function attachments(): array
    {
        $filename = (string) ($this->drawing->filename ?? '');
        if ($filename === '') {
            return [];
        }

        $path = app(DocumentArtifactStorage::class)
            ->readPath(DocumentArtifactStorage::TYPE_DRAWING, basename($filename));

        if ($path === null) {
            return [];
        }

        // Mime by extension — Plan 03 produces PDFs as the primary export,
        // but Plan 17-01's placeholder body writes SVG. Both are supported.
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'pdf' => 'application/pdf',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            default => 'application/octet-stream',
        };

        return [
            Attachment::fromPath($path)
                ->as(basename($filename))
                ->withMime($mime),
        ];
    }
}
