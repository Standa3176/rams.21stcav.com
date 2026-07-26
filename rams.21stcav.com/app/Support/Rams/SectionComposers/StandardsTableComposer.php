<?php

namespace App\Support\Rams\SectionComposers;

use App\Models\RamsDocument;
use App\Support\Rams\Sections\StandardsTableSectionDto;
use Illuminate\Contracts\Config\Repository;

/**
 * Composes Section 3b (Standards & Guidance table) from
 * `config('rams_tier1.standards_references')`, with reviewed_data override
 * when the engineer has supplied their own list.
 *
 * Tolerant of both key shapes seen in the wild (260725-rd1 Task 2):
 *   {ref, title, applies_to}   ← config/rams_tier1.php canonical
 *   {reference, name, scope}   ← older reviewed_data shape
 */
final class StandardsTableComposer
{
    public function __construct(
        private readonly Repository $config,
    ) {}

    public function compose(RamsDocument $record): StandardsTableSectionDto
    {
        $rd  = $record->reviewed_data ?? [];
        $raw = (array) ($rd['standards_references']
            ?? $this->config->get('rams_tier1.standards_references', []));

        $rows = [];
        foreach ($raw as $r) {
            $r = (array) $r;
            $rows[] = [
                'ref'        => (string) ($r['ref']        ?? ($r['reference'] ?? '')),
                'title'      => (string) ($r['title']      ?? ($r['name']      ?? '')),
                'applies_to' => (string) ($r['applies_to'] ?? ($r['scope']     ?? '')),
            ];
        }

        return StandardsTableSectionDto::fromArray(['rows' => $rows]);
    }
}
