<?php

namespace App\Services\Cable;

use App\Models\CableScheduleItem;
use App\Models\Device;
use Illuminate\Support\Collection;

/**
 * Phase 22 Plan 03 — pure deterministic backfill matcher.
 *
 * Given a CableScheduleItem and the project's devices (with their stencil +
 * ports pre-attached via setRelation('stencil', $stencil)), returns a per-row
 * decision: did the from_location text resolve to exactly ONE device with
 * exactly ONE plausible port? If so, the source_device_id + source_port_id
 * are set. Same logic for to_location → dest_*. Outcomes per-side are
 * independent for diagnostics — the resolver may report
 * source_device_id+source_port_id populated even when overall 'match' is
 * 'ambiguous' because the dest half failed. The COMMAND layer
 * (BackfillCablePortFksCommand) MUST NOT persist those partial values per
 * CONTEXT D-LOCK + DRAW-41 ("leaves nullable where ambiguous"). See
 * Plan 22-03 RED test 10 + the command's writes-only-on-matched branch.
 *
 * Pitfall 3 mitigation (RESEARCH.md §"Pitfall 3"): matching is STRICT against
 * normalised manufacturer + model + part_no — NOT a substring contains-match
 * on manufacturer alone. A project with 3 different Crestron devices does NOT
 * cross-match by manufacturer; each device's full identifier must appear in
 * the cable text.
 *
 * Pitfall 4 mitigation (RESEARCH.md §"Pitfall 4"): ports with empty
 * connector_type (Tier 1.5 stencils — 91 of 96 per Phase 21 Plan 02 SUMMARY)
 * are treated as "unknown" and do NOT count toward "exactly one matching
 * port". Tier 1.5 stencils fail to deterministic match; the backfill skips
 * them with a 'no-device-match' or 'ambiguous' outcome that surfaces in the
 * report. Phase 24 curation fills the gap.
 *
 * The service is PURE — no DB writes, no side effects, idempotent.
 *
 * @see app/Console/Commands/BackfillCablePortFksCommand.php (the consumer)
 * @see tests/Unit/Services/Cable/CablePortFkResolverServiceTest.php
 */
class CablePortFkResolverService
{
    /**
     * Cable-type → connector_type hint map. Cable text "HDMI" implies the
     * port's connector_type should be 'hdmi'. Empty / unknown cable_type
     * means "any port" (which only resolves when the device has exactly
     * one catalogued port with a non-empty connector_type).
     */
    private const CABLE_TYPE_TO_CONNECTOR = [
        'hdmi'    => 'hdmi',
        'cat6'    => 'rj45',
        'cat6a'   => 'rj45',
        'cat5e'   => 'rj45',
        'utp'     => 'rj45',
        'usb-c'   => 'usb-c',
        'usb'     => 'usb-c',
        'rs232'   => 'rs232',
        'rs-232'  => 'rs232',
        'xlr'     => 'xlr',
        '3.5mm'   => '3.5mm',
        'speakon' => 'speakon',
        'phx'     => 'phoenix',
        'phoenix' => 'phoenix',
        'dp'      => 'dp',
    ];

    /**
     * Resolve port FKs for a single cable schedule item.
     *
     * @param  CableScheduleItem $item            row to resolve
     * @param  iterable<Device>  $projectDevices  devices in the same project as the item's schedule.
     *                                            Each device must have its `stencil` relation pre-attached
     *                                            (with `ports` loaded) via setRelation() — the resolver
     *                                            performs no DB queries.
     * @return array{
     *     match: 'matched'|'ambiguous'|'no-device-match',
     *     source_device_id: ?int,
     *     source_port_id:   ?int,
     *     dest_device_id:   ?int,
     *     dest_port_id:     ?int,
     *     reason:           string,
     * }
     */
    public function resolve(CableScheduleItem $item, iterable $projectDevices): array
    {
        $devices = collect($projectDevices)->values();
        $connectorHint = $this->connectorHintForCableType($item->cable_type);

        $source = $this->resolveSide(
            text: (string) ($item->from_location ?? ''),
            connectorHint: $connectorHint,
            devices: $devices,
        );

        $dest = $this->resolveSide(
            text: (string) ($item->to_location ?? ''),
            connectorHint: $connectorHint,
            devices: $devices,
        );

        // Overall match aggregation:
        //   both matched              → 'matched'
        //   both no-device-match      → 'no-device-match'
        //   anything else (any side
        //     ambiguous, mixed)       → 'ambiguous'
        //
        // Partial diagnostics (e.g. source matched + dest ambiguous) are kept
        // in the return shape so the command can log them. The command's
        // `$apply && $tag === 'matched'` branch is the ONLY write path — per
        // CONTEXT D-LOCK + DRAW-41, ambiguous rows MUST leave all 4 FKs NULL.
        if ($source['outcome'] === 'matched' && $dest['outcome'] === 'matched') {
            $overall = 'matched';
            $reason  = 'both sides resolved deterministically';
        } elseif ($source['outcome'] === 'no-device-match' && $dest['outcome'] === 'no-device-match') {
            $overall = 'no-device-match';
            $reason  = 'neither side text resolved to a catalogued device';
        } else {
            $overall = 'ambiguous';
            $reason  = trim(
                ($source['reason'] !== null ? 'source: ' . $source['reason'] : '')
                . ($source['reason'] !== null && $dest['reason'] !== null ? '; ' : '')
                . ($dest['reason'] !== null ? 'dest: ' . $dest['reason'] : ''),
                '; '
            );
            if ($reason === '') {
                $reason = 'one side resolved, the other did not';
            }
        }

        return [
            'match'             => $overall,
            'source_device_id'  => $source['device_id'],
            'source_port_id'    => $source['port_id'],
            'dest_device_id'    => $dest['device_id'],
            'dest_port_id'      => $dest['port_id'],
            'reason'            => $reason,
        ];
    }

    /**
     * @return array{outcome: 'matched'|'ambiguous'|'no-device-match', device_id: ?int, port_id: ?int, reason: ?string}
     */
    private function resolveSide(string $text, string $connectorHint, Collection $devices): array
    {
        $normText = $this->normalise($text);
        if ($normText === '') {
            return [
                'outcome'   => 'no-device-match',
                'device_id' => null,
                'port_id'   => null,
                'reason'    => 'empty location text',
            ];
        }

        // STRICT match: device's normalised "manufacturer model", "manufacturer
        // part_no", bare model, or bare part_no must appear as a substring
        // within the normalised text. Pitfall 3: avoid a manufacturer-only
        // substring contains-match that lets "Crestron" match 3 different
        // Crestron devices on the same project.
        $matchingDevices = $devices->filter(function (Device $d) use ($normText) {
            $candidates = [
                $this->normalise(trim(($d->manufacturer ?? '') . ' ' . ($d->model ?? ''))),
                $this->normalise(trim(($d->manufacturer ?? '') . ' ' . ($d->part_no ?? ''))),
                $this->normalise((string) ($d->model ?? '')),
                $this->normalise((string) ($d->part_no ?? '')),
            ];
            foreach ($candidates as $candidate) {
                // Require candidate to be non-trivial (length >= 3) to avoid a
                // bare "x" model collision. Most real part_no / model values
                // exceed this threshold.
                if ($candidate !== '' && strlen($candidate) >= 3 && str_contains($normText, $candidate)) {
                    return true;
                }
            }
            return false;
        })->values();

        if ($matchingDevices->count() === 0) {
            return [
                'outcome'   => 'no-device-match',
                'device_id' => null,
                'port_id'   => null,
                'reason'    => "text '{$text}' did not match any project device",
            ];
        }
        if ($matchingDevices->count() > 1) {
            $ids = $matchingDevices->pluck('id')->implode(', ');
            return [
                'outcome'   => 'ambiguous',
                'device_id' => null,
                'port_id'   => null,
                'reason'    => "text '{$text}' matched multiple devices ({$ids})",
            ];
        }

        /** @var Device $device */
        $device  = $matchingDevices->first();
        $stencil = $device->stencil ?? null;
        $ports   = $stencil ? collect($stencil->ports ?? []) : collect();

        // Filter to ports matching the connector hint, if any. Pitfall 4: when
        // connector hint is empty, exclude empty-connector ports (unknown) so
        // Tier 1.5 stencils with empty port catalog still fail deterministic
        // match.
        $candidatePorts = $connectorHint === ''
            ? $ports->filter(fn ($p) => trim((string) ($p->connector_type ?? '')) !== '')
            : $ports->filter(fn ($p) => strtolower(trim((string) ($p->connector_type ?? ''))) === $connectorHint);

        if ($candidatePorts->isEmpty()) {
            return [
                'outcome'   => 'no-device-match',
                'device_id' => null,
                'port_id'   => null,
                'reason'    => $stencil
                    ? "device {$device->id} has no catalogued port matching connector '{$connectorHint}' (Tier 1.5 stencil may need Phase 24 curation)"
                    : "device {$device->id} has no stencil — needs Phase 24 curation",
            ];
        }
        if ($candidatePorts->count() > 1) {
            return [
                'outcome'   => 'ambiguous',
                'device_id' => null,
                'port_id'   => null,
                'reason'    => "device {$device->id} has " . $candidatePorts->count() . " ports matching connector '{$connectorHint}'",
            ];
        }

        return [
            'outcome'   => 'matched',
            'device_id' => $device->id,
            'port_id'   => $candidatePorts->first()->id,
            'reason'    => null,
        ];
    }

    private function normalise(string $value): string
    {
        return strtolower(trim((string) preg_replace('/\s+/', ' ', $value)));
    }

    private function connectorHintForCableType(?string $cableType): string
    {
        $key = strtolower(trim((string) $cableType));
        return self::CABLE_TYPE_TO_CONNECTOR[$key] ?? '';
    }
}
