<?php

namespace App\Support\Rams\Sections;

/**
 * Scope Exclusions — items explicitly out of scope for this works package.
 *
 * Plain strings, one per bullet. Populated by RamsDocumentComposer
 * (Plan 02) from reviewed exclusion notes.
 */
final readonly class ExclusionsSectionDto
{
    /**
     * @param  array<int, string>  $items
     */
    public function __construct(
        public array $items = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            items: array_values(array_map('strval', (array) ($data['items'] ?? []))),
        );
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }
}
