<?php

namespace App\Support\Rams\Sections;

/**
 * Welfare Arrangements — provisions for engineers on site.
 *
 * Each field is a plain-text descriptor (e.g. 'On-site — ground floor'
 * / 'Client staff kitchen'). Populated by RamsDocumentComposer (Plan 02)
 * from reviewed_data.welfare.
 */
final readonly class WelfareSectionDto
{
    public function __construct(
        public string $toilets        = '',
        public string $washing        = '',
        public string $restArea       = '',
        public string $firstAid       = '',
        public string $drinkingWater  = '',
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            toilets:       (string) ($data['toilets']         ?? ''),
            washing:       (string) ($data['washing']         ?? ''),
            restArea:      (string) ($data['rest_area']       ?? ''),
            firstAid:      (string) ($data['first_aid']       ?? ''),
            drinkingWater: (string) ($data['drinking_water']  ?? ''),
        );
    }

    public function isEmpty(): bool
    {
        return $this->toilets === ''
            && $this->washing === ''
            && $this->restArea === ''
            && $this->firstAid === ''
            && $this->drinkingWater === '';
    }
}
