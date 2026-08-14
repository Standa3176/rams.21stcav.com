<?php

namespace App\Services\Drawings;

use App\Models\DeviceStencil;

/**
 * Phase 24 Plan 07 (DRAW-53) — D-04's two-tier "Promote to Engineer-Curated"
 * gate. No analog anywhere in the codebase (RESEARCH.md "No Analog Found"):
 * a plain, dependency-free service class rather than a FormRequest, because
 * it must be callable from BOTH an HTTP controller action
 * (DeviceStencilController::promote()) and any future console context
 * (mirroring how CategoryPortTemplateResolver/AutoGenericStencilGenerator
 * are already dependency-free and console-callable).
 *
 * HARD BLOCK (never promotable while true):
 *   - zero ports
 *   - any port missing label / connector_type / signal_type / direction
 *   - duplicate port_id within the stencil (the device_ports_stencil_port_
 *     unique compound index would otherwise throw a 500 on the NEXT save —
 *     this catches it in validation instead)
 *
 * SOFT WARN (promotable, surfaced for awareness only):
 *   - no manufacturer logo (neither logo_svg nor logo_path)
 *   - signal_type left 'unclassified'
 *   - missing positional hints (x_pct AND y_pct both null)
 *
 * Every returned string is the EXACT UI-SPEC Copywriting Contract copy
 * (24-UI-SPEC.md), including the "Blocked: " prefix baked into each hard-
 * block reason — the edit screen renders these lines directly above the
 * disabled Promote button with no further prefixing (UI-SPEC Component
 * Inventory point 6 / Copywriting Contract table).
 *
 * ⚠ T-24-17 (promote-bypass): DeviceStencilController::promote() re-runs
 * this evaluate() call SERVER-SIDE on every request, unconditionally — a
 * disabled client-side Promote button is UX only, never the enforcement
 * boundary.
 *
 * @see app/Http/Controllers/Admin/DeviceStencilController.php ::promote()
 * @see .planning/phases/24-stencil-curation-ui-quote-import-auto-stub/24-CONTEXT.md (D-04)
 * @see .planning/phases/24-stencil-curation-ui-quote-import-auto-stub/24-UI-SPEC.md (Copywriting Contract)
 */
class StencilPromotionValidator
{
    /** Hard-block field-presence checks, in evaluation order. Column key => human label. */
    private const REQUIRED_FIELD_LABELS = [
        'label'          => 'label',
        'connector_type' => 'connector type',
        'signal_type'    => 'signal type',
        'direction'      => 'direction',
    ];

    /**
     * @return array{blocking: string[], warnings: string[]}
     */
    public function evaluate(DeviceStencil $stencil): array
    {
        $ports = $stencil->ports;

        $blocking = [];
        $warnings = [];

        if ($ports->isEmpty()) {
            $blocking[] = 'Blocked: this stencil has zero ports.';
        }

        foreach (self::REQUIRED_FIELD_LABELS as $column => $humanLabel) {
            $missingCount = $ports->filter(static fn ($port) => blank($port->{$column}))->count();

            if ($missingCount === 0) {
                continue;
            }

            $blocking[] = $missingCount === 1
                ? "Blocked: 1 port is missing a {$humanLabel}."
                : "Blocked: {$missingCount} ports are missing a {$humanLabel}.";
        }

        // Laravel Collection::duplicates() returns every occurrence AFTER the
        // first for a repeated value — dedupe via unique() so a port_id
        // repeated 3x produces ONE line, not two.
        $duplicatePortIds = $ports->pluck('port_id')->duplicates()->unique()->values();
        foreach ($duplicatePortIds as $duplicatePortId) {
            $blocking[] = "Blocked: duplicate port ID \"{$duplicatePortId}\".";
        }

        // Soft-warn checks are only meaningful once the hard structural gate
        // has something to warn ABOUT — an already-blocked zero-port stencil
        // does not also need a "no logo" warning line.
        if ($ports->isNotEmpty()) {
            if ($stencil->logo_svg === null && $stencil->logo_path === null) {
                $warnings[] = 'This stencil has no manufacturer logo — promotion will proceed without one.';
            }

            $unclassifiedCount = $ports->filter(static fn ($port) => $port->signal_type === 'unclassified')->count();
            if ($unclassifiedCount > 0) {
                $warnings[] = $unclassifiedCount === 1
                    ? '1 port has an unclassified signal type.'
                    : "{$unclassifiedCount} ports have an unclassified signal type.";
            }

            $missingPositionCount = $ports->filter(
                static fn ($port) => $port->x_pct === null && $port->y_pct === null
            )->count();
            if ($missingPositionCount > 0) {
                $warnings[] = $missingPositionCount === 1
                    ? '1 port is missing position hints — it will render at an automatic default position.'
                    : "{$missingPositionCount} ports are missing position hints — they will render at automatic default positions.";
            }
        }

        return ['blocking' => $blocking, 'warnings' => $warnings];
    }
}
