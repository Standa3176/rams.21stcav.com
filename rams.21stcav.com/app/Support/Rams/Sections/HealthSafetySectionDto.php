<?php

namespace App\Support\Rams\Sections;

/**
 * Section 3a — Health & Safety Policy statement boilerplate.
 *
 * `policy_text` is the long H&S policy paragraph.
 * `standards_intro_text` is the short lead-in above the standards table
 * (Section 3b — StandardsTableSectionDto).
 *
 * Populated by RamsDocumentComposer (Plan 02) from static
 * config('rams_tier1.*') defaults / reviewed_data overrides.
 */
final readonly class HealthSafetySectionDto
{
    public function __construct(
        public string $policyText         = '',
        public string $standardsIntroText = '',
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            policyText:         (string) ($data['policy_text']          ?? ''),
            standardsIntroText: (string) ($data['standards_intro_text'] ?? ''),
        );
    }

    public function isEmpty(): bool
    {
        return $this->policyText === '' && $this->standardsIntroText === '';
    }
}
