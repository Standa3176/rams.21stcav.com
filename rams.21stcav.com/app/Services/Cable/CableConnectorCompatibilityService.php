<?php

namespace App\Services\Cable;

/**
 * Phase 22 — pure-function connector compatibility check.
 *
 * Drives:
 *   1. Client-side filter inside the picker modal (Plan 22-02) — destination-port
 *      options filter against the picked source port via the same config data
 *      injected via @js(config('cables.compatibility_aliases')).
 *   2. Server-side validation warning when CableScheduleController@update
 *      persists a connector pair (Plan 22-02).
 *   3. (Future) Phase 23 renderer signal-type colour coding (config/cables.php
 *      signal_type_colours map).
 *
 * The service is stateless and idempotent — drop it in a controller or queue
 * job and call check() per cable row. No DB access.
 *
 * Pitfall 4 (RESEARCH.md): empty / unknown connector_type is treated as
 * "assume compatible" — Tier 1.5 stencils (91 of 96 promoted from v1.3 catalog)
 * carry empty port metadata until Phase 24 curates them; that's missing data,
 * not a wrong-port error. The picker doesn't show a warning in this case; the
 * row may be flagged for Phase 24's curation queue by a future plan.
 *
 * Override semantics (CONTEXT D-LOCK override workflow): the picker modal
 * displays the `reason` string in the inline yellow warning banner when
 * compatible=false, and forces the engineer to type an override note before
 * Apply accepts. The note persists to cable_schedule_items.connector_override_note.
 *
 * @see config/cables.php
 * @see .planning/phases/22-cable-schedule-with-port-level-fks/22-CONTEXT.md
 */
class CableConnectorCompatibilityService
{
    /**
     * Check whether two connectors are compatible.
     *
     * Empty / whitespace-only inputs are treated as "unknown" → compatible
     * with an informational reason (Tier 1.5 tolerance per Pitfall 4).
     *
     * Callers must pass strings — coerce nullable DevicePort::$connector_type
     * via `(string) $port?->connector_type` before invoking. This keeps the
     * signature unambiguous and the empty-string semantic explicit.
     *
     * @param  string  $sourceConnector  connector_type of the source port (e.g. 'hdmi')
     * @param  string  $destConnector    connector_type of the dest port (e.g. 'rj45')
     * @return array{compatible: bool, reason: ?string}
     */
    public function check(string $sourceConnector, string $destConnector): array
    {
        $src = strtolower(trim($sourceConnector));
        $dst = strtolower(trim($destConnector));

        // ── Tier 1.5 tolerance (Pitfall 4) ─────────────────────────────────
        if ($src === '' || $dst === '') {
            return [
                'compatible' => true,
                'reason'     => 'connector type not catalogued — assume compatible',
            ];
        }

        // ── Exact match (the default DRAW-39 rule) ──────────────────────────
        if ($src === $dst) {
            return ['compatible' => true, 'reason' => null];
        }

        // ── Bidirectional allowlist (A4) ────────────────────────────────────
        $aliases = (array) config('cables.compatibility_aliases', []);
        foreach ($aliases as $alias) {
            $from = strtolower(trim((string) ($alias['from'] ?? '')));
            $to   = strtolower(trim((string) ($alias['to']   ?? '')));
            if (($from === $src && $to === $dst) || ($from === $dst && $to === $src)) {
                return [
                    'compatible' => true,
                    'reason'     => $alias['note'] ?? null,
                ];
            }
        }

        return [
            'compatible' => false,
            'reason'     => sprintf('Connector mismatch: %s → %s', $src, $dst),
        ];
    }
}
