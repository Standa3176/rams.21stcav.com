<?php

namespace App\Mail;

use App\Models\CableSchedule;
use App\Services\DocumentArtifactStorage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Completion notification for a generated Cable Schedule (NOTF-01a).
 *
 * Dispatched from BuildCableScheduleJob's success path by the trigger
 * wiring in plan 09-05. Queue-backed via ShouldQueue + SerializesModels
 * (D-11). BCC lives at the call site (Approach B).
 *
 * Asymmetry vs the other three document types (RESEARCH "CableSchedule
 * Asymmetry"):
 *   1. Cable Schedule model only has `source_filename` — there is no
 *      separate `filename` column. The generated artifact lives under its
 *      source-filename so the attachment lookup uses `source_filename`.
 *   2. The artifact may be an XLSX (when PhpSpreadsheet is installed) OR
 *      a CSV (fallback). MIME is selected by extension.
 */
class CableScheduleReadyMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly CableSchedule $schedule) {}

    public function envelope(): Envelope
    {
        $ref     = (string) ($this->schedule->project_ref ?? '');
        $bracket = $ref !== '' ? "[{$ref}] " : '';

        return new Envelope(
            subject: "{$bracket}Cable Schedule ready — {$this->schedule->project_name}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.cable-schedule-ready');
    }

    public function attachments(): array
    {
        $filename = (string) ($this->schedule->source_filename ?? '');
        if ($filename === '') {
            return [];
        }

        $path = app(DocumentArtifactStorage::class)
            ->readPath(DocumentArtifactStorage::TYPE_CABLE, basename($filename));

        if ($path === null) {
            return [];
        }

        $mime = str_ends_with(strtolower($filename), '.csv')
            ? 'text/csv'
            : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        return [
            Attachment::fromPath($path)
                ->as(basename($filename))
                ->withMime($mime),
        ];
    }
}
