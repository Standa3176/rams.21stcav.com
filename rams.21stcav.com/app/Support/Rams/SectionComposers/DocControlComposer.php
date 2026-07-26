<?php

namespace App\Support\Rams\SectionComposers;

use App\Models\RamsDocument;
use App\Support\Rams\Sections\DocControlSectionDto;

/**
 * Composes Section 1 (Document Control) revision history from a post-patch
 * RamsDocument.
 *
 * Reads $rams->generated_data.document_control.revisions[] when present.
 * Always seeds a leading "current record" row from project revision +
 * document_status + created_at + doc_author so a freshly-issued RAMS with
 * no prior revisions still shows the first-issue row (matches the DOCX +
 * PDF behaviour of always rendering at least one row).
 *
 * Never mutates $record; never calls save().
 */
final class DocControlComposer
{
    public function compose(RamsDocument $record): DocControlSectionDto
    {
        $gd      = $record->generated_data ?? [];
        $project = (array) ($gd['project'] ?? []);
        $control = (array) ($gd['document_control'] ?? []);

        $docDate    = $record->created_at?->format('d/m/Y') ?: now()->format('d/m/Y');
        $revision   = (string) ($project['revision']        ?? 'Rev 1.0');
        $author     = (string) ($project['doc_author']      ?? '');
        $status     = (string) ($project['document_status'] ?? 'For Issue');

        $rows = [];

        // Leading "current" row — mirrors the pre-filled first row that both
        // renderers emit for the RAMS being issued right now.
        $rows[] = [
            'rev'         => $revision,
            'date'        => $docDate,
            'author'      => $author,
            'description' => 'Initial Issue',
            'status'      => $status,
        ];

        // Any prior revisions stored on generated_data.document_control.
        foreach ((array) ($control['revisions'] ?? []) as $r) {
            $r = (array) $r;
            $rev = (string) ($r['rev'] ?? '');
            // Skip if this row duplicates the leading current-record row we
            // just seeded (some legacy payloads store the current revision
            // twice — once implicitly, once explicitly).
            if ($rev !== '' && $rev === $revision) {
                continue;
            }
            $rows[] = [
                'rev'         => $rev,
                'date'        => (string) ($r['date']        ?? ''),
                'author'      => (string) ($r['author']      ?? ''),
                'description' => (string) ($r['description'] ?? ''),
                'status'      => (string) ($r['status']      ?? ''),
            ];
        }

        return DocControlSectionDto::fromArray(['revisions' => $rows]);
    }
}
