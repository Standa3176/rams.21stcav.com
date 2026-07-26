<?php

namespace App\Support\Rams\SectionComposers;

use App\Models\RamsDocument;
use App\Support\Rams\Sections\EnvironmentalSectionDto;
use Illuminate\Contracts\Config\Repository;

/**
 * Composes the Environmental Management section from static tier-1
 * defaults + reviewed_data overrides.
 *
 * Baseline bullets (waste disposal / noise-dust-vibration) live inline
 * here as fallback because the renderers currently hard-code the same
 * paragraphs. Moving them to a config key is a Plan 3/4 follow-up —
 * this composer just makes the source unified.
 */
final class EnvironmentalComposer
{
    private const DEFAULT_WASTE_DISPOSAL = [
        'All installation waste (packaging, offcuts, redundant cabling) removed from site at end of works.',
        'Waste transferred under waste-carrier duty of care to a licensed transfer station.',
        'Materials segregated for recycling where practical — cardboard, metal, cable copper.',
        'No waste burnt on site under any circumstances.',
    ];

    private const DEFAULT_NOISE_DUST_VIBRATION = [
        'Noisy operations (drilling, cutting) scheduled outside client core operating hours where practical.',
        'Dust extraction attachments used on power tools whenever finished surfaces are affected.',
        'Damp cloths / drop sheets deployed to contain drilling debris on client carpet or upholstery.',
        'Vibration-emitting tools operated in short bursts to keep engineer exposure within HAV daily limits.',
    ];

    public function __construct(
        private readonly Repository $config,
    ) {}

    public function compose(RamsDocument $record): EnvironmentalSectionDto
    {
        $rd = $record->reviewed_data ?? [];

        $waste = (array) ($rd['environmental']['waste_disposal']
            ?? $this->config->get('rams_tier1.environmental.waste_disposal', self::DEFAULT_WASTE_DISPOSAL));

        $ndv = (array) ($rd['environmental']['noise_dust_vibration']
            ?? $this->config->get('rams_tier1.environmental.noise_dust_vibration', self::DEFAULT_NOISE_DUST_VIBRATION));

        return EnvironmentalSectionDto::fromArray([
            'waste_disposal'       => $waste,
            'noise_dust_vibration' => $ndv,
        ]);
    }
}
