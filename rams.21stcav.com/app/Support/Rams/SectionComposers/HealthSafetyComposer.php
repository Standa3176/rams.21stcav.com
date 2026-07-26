<?php

namespace App\Support\Rams\SectionComposers;

use App\Models\RamsDocument;
use App\Support\Rams\Sections\HealthSafetySectionDto;
use Illuminate\Contracts\Config\Repository;

/**
 * Composes Section 3a (H&S Policy statement) — boilerplate paragraphs
 * lifted verbatim from DocxBuilderService::buildHealthSafetyPolicy() so
 * both renderers can read the same source once Plans 3+4 land.
 *
 * The policy text is duplicated here as a static default so we can move
 * the renderers off inline hard-coded strings in one atomic PR (Plan 3/4)
 * without a config file change intervening. When reviewed_data provides
 * an override (rare — engineer-edited H&S wording) it wins.
 */
final class HealthSafetyComposer
{
    private const DEFAULT_POLICY_TEXT =
        "21st Century AV Ltd is committed to ensuring the health, safety and welfare of all its employees, "
        ."subcontractors, clients and members of the public who may be affected by our activities. We comply "
        ."fully with the Health and Safety at Work etc. Act 1974 and all relevant statutory provisions, "
        ."including the Management of Health and Safety at Work Regulations 1999, the Provision and Use of "
        ."Work Equipment Regulations 1998 (PUWER), the Manual Handling Operations Regulations 1992, and the "
        ."Electricity at Work Regulations 1989.\n\n"
        ."All engineers operating on behalf of 21st Century AV Ltd are briefed on site-specific risks prior to "
        ."commencement of works and are required to adhere to this Risk Assessment and Method Statement at all "
        ."times. Engineers will not commence work until they are satisfied that it is safe to do so. Any near "
        ."misses, accidents, or unsafe conditions must be reported to the site manager and to the 21st Century "
        ."AV operations team immediately. This document must be read, understood, and complied with by all "
        ."persons carrying out the works described herein. It should be retained on site for the duration of "
        ."the works.";

    private const DEFAULT_STANDARDS_INTRO =
        "The following standards, statutory regulations and industry guidance publications apply to the works "
        ."described in this document. This list is not exhaustive; engineers must apply any additional site- "
        ."or system-specific standards as they arise on site.";

    public function __construct(
        private readonly Repository $config,
    ) {}

    public function compose(RamsDocument $record): HealthSafetySectionDto
    {
        $rd = $record->reviewed_data ?? [];

        $policyText = (string) ($rd['health_safety_policy_text']
            ?? $this->config->get('rams_tier1.policy_text', self::DEFAULT_POLICY_TEXT));

        $standardsIntro = (string) ($rd['standards_intro_text']
            ?? $this->config->get('rams_tier1.standards_intro_text', self::DEFAULT_STANDARDS_INTRO));

        return new HealthSafetySectionDto(
            policyText:         $policyText,
            standardsIntroText: $standardsIntro,
        );
    }
}
