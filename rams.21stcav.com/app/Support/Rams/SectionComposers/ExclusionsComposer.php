<?php

namespace App\Support\Rams\SectionComposers;

use App\Models\RamsDocument;
use App\Support\Rams\Sections\ExclusionsSectionDto;

/**
 * Composes the Scope Exclusions section.
 *
 * Reads reviewed_data.exclusions[] first; falls back to
 * generated_data.exclusions[] for fixture convenience per 260725-rd1
 * Task 2 (DocxBuilderService lines 766-780).
 *
 * RamsDisplayPatchService seeds a 5-item default list when neither
 * source is populated, so the composer typically sees at least those.
 *
 * Empty strings are filtered so a stray "" entry doesn't render a
 * blank bullet.
 */
final class ExclusionsComposer
{
    public function compose(RamsDocument $record): ExclusionsSectionDto
    {
        $rd = $record->reviewed_data  ?? [];
        $gd = $record->generated_data ?? [];

        $items = [];

        foreach ((array) ($rd['exclusions'] ?? []) as $x) {
            $s = is_string($x) ? trim($x) : '';
            if ($s !== '') {
                $items[] = $s;
            }
        }

        if ($items === []) {
            foreach ((array) ($gd['exclusions'] ?? []) as $x) {
                $s = is_string($x) ? trim($x) : '';
                if ($s !== '') {
                    $items[] = $s;
                }
            }
        }

        return ExclusionsSectionDto::fromArray(['items' => $items]);
    }
}
