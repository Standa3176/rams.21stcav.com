<?php

namespace App\Services;

use App\Exceptions\OmManualValidationException;

/**
 * Validates the canonical generator-context array that drives O&M Manual
 * rendering. Phase 1 + 6 of the Tier 1 upgrade — NO TBC POLICY.
 *
 * Required fields enforced:
 *   - project_name
 *   - client_name
 *   - site_address
 *   - document_date
 *   - handover_date
 *   - rooms  (≥ 1 entry)
 *   - per room: name, equipment (≥ 1 item), narrative
 *   - drawings (≥ 1 entry)             — Phase 6: at least one drawing required
 *   - user_guides                       — Phase 6: optional, no count rule
 *
 * On failure, throws OmManualValidationException with the full list of
 * missing fields so the caller can surface a clear actionable error.
 */
class OmManualValidationService
{
    /**
     * @param array $data Generator-context shape (see buildContextFromProjectData).
     *
     * @throws OmManualValidationException When any required field is blank or absent.
     */
    public function validateOmData(array $data): void
    {
        $missing = [];

        if ($this->isBlank($data['project_name'] ?? null)) {
            $missing[] = 'project name';
        }
        if ($this->isBlank($data['client_name'] ?? null)) {
            $missing[] = 'client';
        }
        if ($this->isBlank($data['site_address'] ?? null)) {
            $missing[] = 'site';
        }
        if ($this->isBlank($data['document_date'] ?? null)) {
            $missing[] = 'document date';
        }
        if ($this->isBlank($data['handover_date'] ?? null)) {
            $missing[] = 'handover date';
        }

        $rooms = is_array($data['rooms'] ?? null) ? $data['rooms'] : [];
        if (empty($rooms)) {
            $missing[] = 'at least one room';
        } else {
            foreach ($rooms as $i => $room) {
                $position = $i + 1;
                $isArray  = is_array($room);
                $name     = $isArray ? trim((string) ($room['name'] ?? '')) : '';
                $label    = $name !== '' ? $name : "room #{$position}";

                if (! $isArray || $this->isBlank($room['name'] ?? null)) {
                    $missing[] = "room name (entry #{$position})";
                }

                $equipment = $isArray && is_array($room['equipment'] ?? null)
                    ? $room['equipment']
                    : [];
                if (empty($equipment)) {
                    $missing[] = "equipment for {$label}";
                }

                if (! $isArray || $this->isBlank($room['narrative'] ?? null)) {
                    $missing[] = "narrative for {$label}";
                }
            }
        }

        // Phase 6 — Appendix A drawings register.
        // At least one drawing is required; user guides are optional and
        // intentionally NOT validated (no count rule).
        $drawings = is_array($data['drawings'] ?? null) ? $data['drawings'] : [];
        if (empty($drawings)) {
            $missing[] = 'at least one drawing (Appendix A)';
        }

        if (! empty($missing)) {
            throw new OmManualValidationException(
                'O&M Manual cannot be generated — required fields missing: '
                . implode('; ', $missing),
                $missing,
            );
        }
    }

    private function isBlank(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_array($value)) {
            return empty($value);
        }
        return trim((string) $value) === '';
    }
}
