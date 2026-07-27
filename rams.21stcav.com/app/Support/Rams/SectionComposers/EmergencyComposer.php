<?php

namespace App\Support\Rams\SectionComposers;

use App\Models\RamsDocument;
use App\Support\Rams\Sections\EmergencySectionDto;

/**
 * Composes Section 7 (Emergency Procedures) from
 * $rams->reviewed_data['site_emergency'] (populated in the review form,
 * auto-carried from a prior completed RAMS by RamsDisplayPatchService,
 * or blank if never captured).
 *
 * Every string field falls through to the empty string when the source
 * is missing — renderers substitute the "TBC AT SITE INDUCTION"
 * placeholder per 260726-rf2, matching the current DOCX + PDF behaviour.
 *
 * Reads from generated_data.site_emergency first as an override hatch
 * (matches rams.blade.php:1956), then reviewed_data.site_emergency, then
 * empty defaults.
 */
final class EmergencyComposer
{
    public function compose(RamsDocument $record): EmergencySectionDto
    {
        $rd = $record->reviewed_data  ?? [];
        $gd = $record->generated_data ?? [];

        $siteEmerg = (array) ($gd['site_emergency'] ?? ($rd['site_emergency'] ?? []));

        $stringList = static function (mixed $v): array {
            if ($v === null || $v === '') {
                return [];
            }
            if (is_string($v)) {
                $v = preg_split('/\r?\n/', $v) ?: [];
            }
            $out = [];
            foreach ((array) $v as $item) {
                $s = trim((string) $item);
                if ($s !== '') {
                    $out[] = $s;
                }
            }
            return array_values($out);
        };

        return EmergencySectionDto::fromArray([
            'nearest_hospital'            => (string) ($siteEmerg['nearest_hospital']    ?? ''),
            'fire_assembly_point'         => (string) ($siteEmerg['fire_assembly_point'] ?? ''),
            'fire_warden'                 => (string) ($siteEmerg['fire_warden_name']    ?? ($siteEmerg['fire_warden'] ?? '')),
            'fire_warden_contact'         => (string) ($siteEmerg['fire_warden_contact'] ?? ''),
            'first_aider'                 => (string) ($siteEmerg['first_aider_name']    ?? ($siteEmerg['first_aider'] ?? '')),
            'first_aider_contact'         => (string) ($siteEmerg['first_aider_contact'] ?? ''),
            'defibrillator'               => (string) ($siteEmerg['defibrillator_location'] ?? ($siteEmerg['defibrillator'] ?? '')),
            'electrical_isolation_switch' => (string) ($siteEmerg['electrical_isolation_switch'] ?? ($siteEmerg['isolation_switch'] ?? '')),
            'fire_extinguisher_class'     => (string) ($siteEmerg['fire_extinguisher_class'] ?? ''),
            'emergency_contacts'          => (array) ($rd['emergency_contacts']  ?? []),
            'accident_procedure'          => $stringList($rd['accident_procedure'] ?? []),
            'fire_procedure'              => $stringList($rd['fire_procedure']     ?? []),
            'riddor_matrix'               => (array) ($rd['riddor_matrix']         ?? []),
        ]);
    }
}
