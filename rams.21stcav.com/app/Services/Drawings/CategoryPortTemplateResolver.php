<?php

namespace App\Services\Drawings;

/**
 * Phase 24 Plan 01 Task 2 — deterministic device-type -> port-template
 * resolution (CONTEXT.md D-06/D-07).
 *
 * Mechanism mirrors EquipmentCategoryClassifier's priority-ordered decision
 * tree (app/Services/Imports/EquipmentCategoryClassifier.php) — but NOT its
 * vocabulary (that's a commercial axis, not a device type) and NOT its
 * unconditional `hardware` default. The "return null on no unambiguous
 * match" shape instead mirrors DrawingDataResolverService::inferRoleFromName()
 * (app/Services/Drawings/DrawingDataResolverService.php:437-469) — an
 * ambiguous or unrecognised device never gets a guessed template; it becomes
 * a zero-port stub flagged needs_review instead (D-07).
 *
 * Resolution order:
 *   1. `cable` beats everything (D-07 short-circuit) — permanent zero-port
 *      outcome, checked before anything else.
 *   2. `port_template_precedence` (D-07) — ordered list of explicit
 *      multi-keyword conflict rules (e.g. "Samsung 65in Display Bracket"
 *      matches both `display` and `bracket`; the precedence rule
 *      deterministically resolves it to `bracket`).
 *   3. Single-keyword lookup against `port_templates` top-level keys — exactly
 *      one match resolves; zero or multiple UNHANDLED matches return null
 *      (ambiguous — never guess).
 *
 * Determinism (RESEARCH.md Pitfall 3): `port_id` is derived from
 * `{connector_type}-{n}` (1-based counter scoped per connector_type within
 * the template) — never Str::uuid() or time()-derived. Plan 24-08's
 * `stencils:reapply-templates` dry-run diffing depends on byte-identical
 * output across repeated calls with the same input.
 *
 * `x_pct` / `y_pct` are always left null (D-01) — Phase 23's renderer
 * computes position when null; this resolver never invents a position.
 *
 * @see config/drawings.php `port_templates` / `port_template_precedence`
 * @see app/Models/DevicePort.php — exact fillable keys this resolver's
 *      output rows must use verbatim
 * @see .planning/phases/24-stencil-curation-ui-quote-import-auto-stub/24-CONTEXT.md (D-06, D-07)
 */
class CategoryPortTemplateResolver
{
    /**
     * Resolve a device-type port template from a name + part_number.
     *
     * Returns an array of port rows (possibly empty — a resolved-but-portless
     * device type such as `bracket`/`mount`/`cable`), or null when the
     * device-type is ambiguous or unrecognised.
     *
     * @return array<int, array{label:string, side:string, connector_type:string, signal_type:string, direction:string, sort_order:int, port_id:string, x_pct:?float, y_pct:?float}>|null
     */
    public function resolve(string $name, string $partNumber): ?array
    {
        $haystack = strtolower(trim($name.' '.$partNumber));

        // 1. 'cable' beats everything (D-07) — permanent zero-port outcome.
        if (str_contains($haystack, 'cable')) {
            return [];
        }

        // 2. Explicit multi-keyword precedence rules, evaluated in order.
        foreach (config('drawings.port_template_precedence', []) as $entry) {
            $keywords = $entry['keywords'] ?? [];
            if ($keywords === []) {
                continue;
            }

            $allPresent = true;
            foreach ($keywords as $keyword) {
                if (! str_contains($haystack, (string) $keyword)) {
                    $allPresent = false;

                    break;
                }
            }

            if ($allPresent) {
                $winner = $entry['winner'] ?? null;
                $template = $winner !== null ? config("drawings.port_templates.{$winner}") : null;

                if ($template !== null) {
                    return $this->assignPortIds($template);
                }
            }
        }

        // 3. Single-keyword lookup — exactly one match resolves; zero or
        //    multiple UNHANDLED matches are ambiguous, never guessed.
        $templates = config('drawings.port_templates', []);
        $matchedKeys = [];
        foreach (array_keys($templates) as $deviceType) {
            if (str_contains($haystack, (string) $deviceType)) {
                $matchedKeys[] = $deviceType;
            }
        }

        if (count($matchedKeys) === 1) {
            return $this->assignPortIds($templates[$matchedKeys[0]]);
        }

        return null;
    }

    /**
     * Populate deterministic port_id + null positional hints on a resolved
     * template's rows, without mutating the source config array.
     *
     * @param  array<int, array<string, mixed>>  $template
     * @return array<int, array<string, mixed>>
     */
    private function assignPortIds(array $template): array
    {
        $countPerConnector = [];
        $rows = [];

        foreach ($template as $row) {
            $connectorType = (string) ($row['connector_type'] ?? '');
            $countPerConnector[$connectorType] = ($countPerConnector[$connectorType] ?? 0) + 1;

            $rows[] = array_merge($row, [
                'port_id' => sprintf('%s-%d', $connectorType, $countPerConnector[$connectorType]),
                'x_pct'   => null,
                'y_pct'   => null,
            ]);
        }

        return $rows;
    }
}
