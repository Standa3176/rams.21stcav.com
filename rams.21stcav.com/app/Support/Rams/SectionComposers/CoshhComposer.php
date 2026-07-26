<?php

namespace App\Support\Rams\SectionComposers;

use App\Models\RamsDocument;
use App\Support\Rams\Sections\CoshhSectionDto;
use Illuminate\Contracts\Config\Repository;

/**
 * Composes the COSHH inventory section.
 *
 * Reads config('rams_tier1.coshh_products') as the baseline (canonical
 * shape uses `typical_use`), merged with any per-project additions
 * stored on reviewed_data.coshh (or the legacy 'coshh_additions' key
 * used by earlier form iterations).
 *
 * When the tier1 kill-switch is off, no baseline is emitted — matches
 * the DocxBuilderService fallback behaviour (COSHH section becomes
 * empty and the renderer emits the legacy 4-item bullet list instead).
 */
final class CoshhComposer
{
    public function __construct(
        private readonly Repository $config,
    ) {}

    public function compose(RamsDocument $record): CoshhSectionDto
    {
        $rd = $record->reviewed_data ?? [];

        $inventory = [];

        // Baseline from tier1 config (kill-switch gated).
        if ((bool) $this->config->get('rams_tier1.enabled', true)) {
            foreach ((array) $this->config->get('rams_tier1.coshh_products', []) as $p) {
                $inventory[] = $this->normaliseRow((array) $p);
            }
        }

        // Per-project additions from the review form.
        foreach ((array) ($rd['coshh'] ?? ($rd['coshh_additions'] ?? [])) as $p) {
            if (! is_array($p)) {
                continue;
            }
            $inventory[] = $this->normaliseRow($p);
        }

        return CoshhSectionDto::fromArray(['inventory' => $inventory]);
    }

    private function normaliseRow(array $p): array
    {
        return [
            'product'   => (string) ($p['product'] ?? ''),
            'use'       => (string) ($p['use']     ?? ($p['typical_use'] ?? '')),
            'ghs_codes' => array_values(array_map('strval', (array) ($p['ghs_codes'] ?? []))),
            'controls'  => array_values(array_map('strval', (array) ($p['controls']  ?? []))),
        ];
    }
}
