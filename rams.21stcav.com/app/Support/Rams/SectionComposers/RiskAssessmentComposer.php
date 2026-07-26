<?php

namespace App\Support\Rams\SectionComposers;

use App\Models\RamsDocument;
use App\Support\Rams\Sections\RiskAssessmentSectionDto;
use Illuminate\Contracts\Config\Repository;

/**
 * Composes Section 5 (Risk Assessment) from post-patch RamsDocument.
 *
 * Reads $rams->reviewed_data['hazards'][] first; falls back to
 * generated_data['hazards'][]; last-resort falls back to
 * config('rams_tier1.baseline_hazards') per the 260712-twi kill-switch.
 *
 * Assigns stable RA{NN} refs — the same 1-indexed scheme that
 * DocxBuilderService::buildRiskAssessment() computes inline (line 1119)
 * so the ref labels don't shift when this composer takes over rendering.
 *
 * Computes initial_r = initial_l × initial_s and residual_r = residual_l
 * × residual_s so downstream renderers get pre-computed scores.
 */
final class RiskAssessmentComposer
{
    public function __construct(
        private readonly Repository $config,
    ) {}

    public function compose(RamsDocument $record): RiskAssessmentSectionDto
    {
        $rd = $record->reviewed_data  ?? [];
        $gd = $record->generated_data ?? [];

        $raw = (array) ($rd['hazards']
            ?? ($gd['hazards']
                ?? ($this->config->get('rams_tier1.enabled', true)
                    ? $this->config->get('rams_tier1.baseline_hazards', [])
                    : [])));

        $hazards = [];
        foreach (array_values($raw) as $idx => $h) {
            $h = (array) $h;
            $iL = (int) ($h['initial_l'] ?? ($h['pre_likelihood']  ?? 0));
            $iS = (int) ($h['initial_s'] ?? ($h['pre_severity']    ?? 0));
            $rL = (int) ($h['residual_l'] ?? ($h['post_likelihood'] ?? 0));
            $rS = (int) ($h['residual_s'] ?? ($h['post_severity']   ?? 0));

            $hazards[] = [
                'ref'             => (string) ($h['ref'] ?? ('RA' . str_pad((string) ($idx + 1), 2, '0', STR_PAD_LEFT))),
                'hazard'          => (string) ($h['hazard'] ?? ''),
                'persons_at_risk' => array_values(array_map('strval', (array) ($h['persons_at_risk'] ?? []))),
                'initial_l'       => $iL,
                'initial_s'       => $iS,
                'initial_r'       => $iL * $iS,
                'controls'        => array_values(array_map('strval', (array) ($h['controls'] ?? []))),
                'residual_l'      => $rL,
                'residual_s'      => $rS,
                'residual_r'      => $rL * $rS,
            ];
        }

        // 5x5 pre-mitigation matrix — L × S = R, rows keyed by severity 1..5,
        // cols keyed by likelihood 1..5. Mirrors the legend that both
        // renderers build inline.
        $matrix = [];
        for ($s = 1; $s <= 5; $s++) {
            $row = [];
            for ($l = 1; $l <= 5; $l++) {
                $row[$l] = $l * $s;
            }
            $matrix[$s] = $row;
        }

        return RiskAssessmentSectionDto::fromArray([
            'matrix'  => $matrix,
            'hazards' => $hazards,
        ]);
    }
}
