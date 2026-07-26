<?php

namespace App\Support\Rams\Sections;

/**
 * COSHH — Control of Substances Hazardous to Health inventory.
 *
 * Each row: [ 'product' => 'IPA', 'use' => 'Screen cleaning',
 *             'ghs_codes' => ['H225', 'H319'], 'controls' => ['...', ...] ]
 *
 * Populated by RamsDocumentComposer (Plan 02) from
 * config('rams_tier1.coshh_products') / reviewed COSHH additions.
 */
final readonly class CoshhSectionDto
{
    /**
     * @param  array<int, array<string, mixed>>  $inventory
     */
    public function __construct(
        public array $inventory = [],
    ) {}

    public static function fromArray(array $data): self
    {
        $stringList = static fn (mixed $v): array => array_values(array_map('strval', (array) $v));

        $rows = [];
        foreach ((array) ($data['inventory'] ?? []) as $r) {
            $r = (array) $r;
            $rows[] = [
                'product'   => (string) ($r['product'] ?? ''),
                'use'       => (string) ($r['use']     ?? ''),
                'ghs_codes' => $stringList($r['ghs_codes'] ?? []),
                'controls'  => $stringList($r['controls']  ?? []),
            ];
        }

        return new self(inventory: $rows);
    }

    public function isEmpty(): bool
    {
        return $this->inventory === [];
    }
}
