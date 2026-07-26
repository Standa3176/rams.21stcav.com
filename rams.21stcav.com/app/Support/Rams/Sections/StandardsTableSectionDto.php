<?php

namespace App\Support\Rams\Sections;

/**
 * Section 3b — Standards & Guidance table.
 *
 * Each row: [ 'ref' => 'BS 7671:...', 'title' => '...', 'applies_to' => '...' ]
 *
 * Populated by RamsDocumentComposer (Plan 02) from
 * config('rams_tier1.standards_references') / reviewed_data overrides.
 */
final readonly class StandardsTableSectionDto
{
    /**
     * @param  array<int, array{ref: string, title: string, applies_to: string}>  $rows
     */
    public function __construct(
        public array $rows = [],
    ) {}

    public static function fromArray(array $data): self
    {
        $rows = (array) ($data['rows'] ?? []);

        $normalised = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $normalised[] = [
                'ref'        => (string) ($row['ref']        ?? ''),
                'title'      => (string) ($row['title']      ?? ''),
                'applies_to' => (string) ($row['applies_to'] ?? ''),
            ];
        }

        return new self(rows: $normalised);
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }
}
