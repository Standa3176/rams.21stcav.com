<?php

namespace App\Support\Rams\Sections;

/**
 * Section 5 — Risk Assessment.
 *
 * matrix   — the 5×5 scoring grid (rows = severity, cols = likelihood)
 *            rendered as the legend at the top of the section.
 *            Structure: array<int, array<int, int>> — 1-indexed L×S → score.
 * hazards  — the hazard register rows.
 *            Each: [ 'ref' => 'RA01', 'hazard' => 'Working at Height',
 *                    'persons_at_risk' => ['21CAV Engineers', ...],
 *                    'initial_l' => 4, 'initial_s' => 4, 'initial_r' => 16,
 *                    'controls' => ['...', ...],
 *                    'residual_l' => 2, 'residual_s' => 3, 'residual_r' => 6 ]
 *
 * Populated by RamsDocumentComposer (Plan 02) from
 * reviewed hazards / config('rams_tier1.baseline_hazards').
 */
final readonly class RiskAssessmentSectionDto
{
    /**
     * @param  array<int, array<int, int>>       $matrix  5×5 pre-mitigation scoring grid.
     * @param  array<int, array<string, mixed>>  $hazards Ordered hazard rows (see class docblock for shape).
     */
    public function __construct(
        public array $matrix  = [],
        public array $hazards = [],
    ) {}

    public static function fromArray(array $data): self
    {
        // Normalise matrix — accept partial data and coerce every cell to int.
        $matrix = [];
        foreach ((array) ($data['matrix'] ?? []) as $rowKey => $row) {
            $matrix[(int) $rowKey] = array_map('intval', (array) $row);
        }

        $hazards = [];
        foreach ((array) ($data['hazards'] ?? []) as $h) {
            $h = (array) $h;
            $hazards[] = [
                'ref'             => (string) ($h['ref']    ?? ''),
                'hazard'          => (string) ($h['hazard'] ?? ''),
                'persons_at_risk' => array_values(array_map('strval', (array) ($h['persons_at_risk'] ?? []))),
                'initial_l'       => (int)    ($h['initial_l'] ?? 0),
                'initial_s'       => (int)    ($h['initial_s'] ?? 0),
                'initial_r'       => (int)    ($h['initial_r'] ?? 0),
                'controls'        => array_values(array_map('strval', (array) ($h['controls'] ?? []))),
                'residual_l'      => (int)    ($h['residual_l'] ?? 0),
                'residual_s'      => (int)    ($h['residual_s'] ?? 0),
                'residual_r'      => (int)    ($h['residual_r'] ?? 0),
            ];
        }

        return new self(matrix: $matrix, hazards: $hazards);
    }

    public function isEmpty(): bool
    {
        return $this->matrix === [] && $this->hazards === [];
    }
}
