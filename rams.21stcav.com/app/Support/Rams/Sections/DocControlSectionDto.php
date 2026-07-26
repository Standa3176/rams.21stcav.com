<?php

namespace App\Support\Rams\Sections;

/**
 * Section 1 — Document Control revision history.
 *
 * Each revision row: [ 'rev' => 'Rev 0.1', 'date' => 'dd/mm/yyyy',
 *                      'author' => 'Name', 'description' => '...',
 *                      'status' => 'DRAFT' ]
 *
 * Populated by RamsDocumentComposer (Plan 02) from
 * `$rams->revision_history` / reviewed_data; consumed by both renderers.
 */
final readonly class DocControlSectionDto
{
    /**
     * @param  array<int, array<string, string>>  $revisions  Ordered revision rows.
     */
    public function __construct(
        public array $revisions = [],
    ) {}

    /**
     * Tolerant builder — normalises each row to the expected five keys.
     */
    public static function fromArray(array $data): self
    {
        $rows = (array) ($data['revisions'] ?? []);

        $normalised = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $normalised[] = [
                'rev'         => (string) ($row['rev']         ?? ''),
                'date'        => (string) ($row['date']        ?? ''),
                'author'      => (string) ($row['author']      ?? ''),
                'description' => (string) ($row['description'] ?? ''),
                'status'      => (string) ($row['status']      ?? ''),
            ];
        }

        return new self(revisions: $normalised);
    }

    public function isEmpty(): bool
    {
        return $this->revisions === [];
    }
}
