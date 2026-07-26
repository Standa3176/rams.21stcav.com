<?php

namespace App\Support\Rams\Sections;

/**
 * Environmental Management — waste disposal + noise/dust/vibration bullets.
 *
 * Populated by RamsDocumentComposer (Plan 02) from
 * config('rams_tier1.*') defaults / reviewed environmental overrides.
 */
final readonly class EnvironmentalSectionDto
{
    /**
     * @param  array<int, string>  $wasteDisposal
     * @param  array<int, string>  $noiseDustVibration
     */
    public function __construct(
        public array $wasteDisposal      = [],
        public array $noiseDustVibration = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            wasteDisposal:      array_values(array_map('strval', (array) ($data['waste_disposal']       ?? []))),
            noiseDustVibration: array_values(array_map('strval', (array) ($data['noise_dust_vibration'] ?? []))),
        );
    }

    public function isEmpty(): bool
    {
        return $this->wasteDisposal === [] && $this->noiseDustVibration === [];
    }
}
