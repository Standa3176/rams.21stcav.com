<?php

namespace App\Mail;

use App\Models\RamsDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RamsDocumentMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly RamsDocument $rams,
        public readonly string       $recipientName,
        public readonly string       $senderNote = '',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'RAMS Document — ' . $this->rams->project_name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.rams-document',
        );
    }

    public function attachments(): array
    {
        $path = storage_path('app/rams/' . $this->rams->filename);

        if (! file_exists($path)) {
            return [];
        }

        return [
            Attachment::fromPath($path)
                ->as($this->rams->filename)
                ->withMime(
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                ),
        ];
    }
}
