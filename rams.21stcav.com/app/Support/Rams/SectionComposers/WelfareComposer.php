<?php

namespace App\Support\Rams\SectionComposers;

use App\Models\RamsDocument;
use App\Support\Rams\Sections\WelfareSectionDto;
use Illuminate\Contracts\Config\Repository;

/**
 * Composes the Welfare Arrangements section from
 * reviewed_data.welfare with static defaults.
 */
final class WelfareComposer
{
    private const DEFAULT_TOILETS        = 'Client welfare facilities used on site with prior agreement, or nearest public / trade facilities noted at site induction.';
    private const DEFAULT_WASHING        = 'Hand-washing facilities in client welfare block, or engineer-issue hand-cleanser + wipes carried in vehicle.';
    private const DEFAULT_REST_AREA      = 'Break areas as agreed with site contact; vehicle used as fallback rest area.';
    private const DEFAULT_FIRST_AID      = 'First-aid kit carried in every 21CAV vehicle; qualified first-aider details captured in Section 7.';
    private const DEFAULT_DRINKING_WATER = 'Engineers supplied with sufficient drinking water for the working day; client kitchen used with permission.';

    public function __construct(
        private readonly Repository $config,
    ) {}

    public function compose(RamsDocument $record): WelfareSectionDto
    {
        $rd      = $record->reviewed_data ?? [];
        $welfare = (array) ($rd['welfare'] ?? []);

        return new WelfareSectionDto(
            toilets:       (string) ($welfare['toilets']        ?? $this->config->get('rams_tier1.welfare.toilets',        self::DEFAULT_TOILETS)),
            washing:       (string) ($welfare['washing']        ?? $this->config->get('rams_tier1.welfare.washing',        self::DEFAULT_WASHING)),
            restArea:      (string) ($welfare['rest_area']      ?? $this->config->get('rams_tier1.welfare.rest_area',      self::DEFAULT_REST_AREA)),
            firstAid:      (string) ($welfare['first_aid']      ?? $this->config->get('rams_tier1.welfare.first_aid',      self::DEFAULT_FIRST_AID)),
            drinkingWater: (string) ($welfare['drinking_water'] ?? $this->config->get('rams_tier1.welfare.drinking_water', self::DEFAULT_DRINKING_WATER)),
        );
    }
}
