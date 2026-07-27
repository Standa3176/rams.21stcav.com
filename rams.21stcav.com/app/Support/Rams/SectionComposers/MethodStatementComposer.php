<?php

namespace App\Support\Rams\SectionComposers;

use App\Models\RamsDocument;
use App\Support\Rams\Sections\MethodStatementSectionDto;

/**
 * Composes Section 6 (Method Statement + subsections 6.1-6.12) from
 * post-patch RamsDocument.
 *
 * Reads:
 *   - reviewed_data.method_statement.phases[]  (or generated_data.method_statement.phases[])
 *   - reviewed_data.method_statement_team[]
 *   - reviewed_data.method_statement_tools[]
 *   - reviewed_data.method_statement_ppe (task-keyed map)
 *   - reviewed_data.method_statement_access_equipment[]
 *   - reviewed_data.method_statement_access_requirements[]
 *   - reviewed_data.client_responsibilities_expanded[]
 *   - reviewed_data.material_handling — dual-shape:
 *       LEGACY bullet-list: [ 'two-person lift for displays', ... ]
 *       PROD object       : { large_items[]:{item,weight_kg,handling_method},
 *                             handling_notes: string }
 *   - reviewed_data.permits_required[]
 *   - reviewed_data.method_statement_fixings[]
 *   - reviewed_data.method_statement_supervision[]
 *   - reviewed_data.method_statement_coordination[]
 *   - reviewed_data.method_statement_it_safety[]
 *
 * Every subsection is optional — an empty MethodStatementSectionDto is a
 * valid result. Renderers use MethodStatementSectionDto::isEmpty() to
 * decide whether to render the section header.
 */
final class MethodStatementComposer
{
    public function compose(RamsDocument $record): MethodStatementSectionDto
    {
        $rd = $record->reviewed_data  ?? [];
        $gd = $record->generated_data ?? [];

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

        // ── Team members ──────────────────────────────────────────────────
        $team = [];
        foreach ((array) ($rd['method_statement_team'] ?? []) as $m) {
            $m = (array) $m;
            $role = trim((string) ($m['role'] ?? ''));
            if ($role === '') {
                continue;
            }
            $team[] = [
                'role'         => $role,
                'qty'          => (int)    ($m['qty']          ?? ($m['quantity'] ?? 1)),
                'requirements' => (string) ($m['requirements'] ?? ''),
            ];
        }

        // ── PPE — task-keyed map ──────────────────────────────────────────
        $ppe = [];
        foreach ((array) ($rd['method_statement_ppe'] ?? []) as $task => $items) {
            $items = $stringList($items);
            if ($items !== []) {
                $ppe[(string) $task] = $items;
            }
        }

        // ── Method steps — canonical source: method_statement.phases[] ────
        // Each raw phase has {title, steps[], associated_risks_label?, associated_risks[]}
        // The DTO's "step" == raw phase (a labelled group of bullets).
        $phasesRaw = (array) ($rd['method_statement']['phases']
            ?? ($gd['method_statement']['phases'] ?? []));

        $steps = [];
        foreach ($phasesRaw as $phase) {
            $phase = (array) $phase;
            $title = trim((string) ($phase['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            // Strip any AI-added "Step N — " / "Phase N — " / "N. " prefix
            // so the renderer can prepend its own ordinal without
            // "Step 1 — Step 1 — Title" duplication (matches DocxBuilder D8).
            $cleanTitle = (string) preg_replace(
                '/^\s*(step\s+\d+[\.\-–—\s]*|phase\s+\d+[\.\-–—\s]*|\d+[\.\-–—\s]+)/i',
                '',
                $title,
            );

            $steps[] = [
                'title'            => $cleanTitle !== '' ? $cleanTitle : $title,
                'bullets'          => $stringList($phase['steps'] ?? ($phase['bullets'] ?? [])),
                'associated_risks' => $stringList($phase['associated_risks'] ?? []),
            ];
        }

        // ── §6.8 Permits — flatten permits_required into a bullet list ────
        $permits = [];
        foreach ((array) ($rd['permits_required'] ?? []) as $p) {
            if (! is_array($p)) {
                continue;
            }
            $required = $p['required'] ?? true;
            if (! $required) {
                continue;
            }
            $type  = trim((string) ($p['type']  ?? ''));
            $notes = trim((string) ($p['notes'] ?? ''));
            if ($type === '' && $notes === '') {
                continue;
            }
            $permits[] = $type !== '' && $notes !== '' ? "{$type} — {$notes}" : ($type ?: $notes);
        }

        // ── §6.7 Material handling — dual-shape support (Plan 05a) ────────
        // Prod records store `material_handling` as an OBJECT:
        //   { large_items: [{item, weight_kg, handling_method}, ...],
        //     handling_notes: "..." }
        // Legacy fixtures store it as a bullet-list array of strings.
        //
        // Detection: an OBJECT shape has an associative `large_items` or
        // `handling_notes` key at the top level. Anything else (numerically
        // indexed array, string, empty) is the legacy bullet list.
        $rawMh = $rd['material_handling'] ?? [];
        $isMhObjectShape = is_array($rawMh)
            && (
                array_key_exists('large_items',     $rawMh)
                || array_key_exists('handling_notes', $rawMh)
            );

        if ($isMhObjectShape) {
            $mhBullets = $stringList($rawMh['handling_notes'] ?? '');
            $mhItems   = [];
            foreach ((array) ($rawMh['large_items'] ?? []) as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $item = trim((string) ($row['item'] ?? ''));
                if ($item === '') {
                    continue;
                }
                $mhItems[] = [
                    'item'            => $item,
                    'weight_kg'       => $row['weight_kg']       ?? null,
                    'handling_method' => $row['handling_method'] ?? null,
                ];
            }
        } else {
            $mhBullets = $stringList($rawMh);
            $mhItems   = [];
        }

        return MethodStatementSectionDto::fromArray([
            'team'                    => $team,
            'tools'                   => $stringList($rd['method_statement_tools']              ?? []),
            'ppe'                     => $ppe,
            'access_equipment'        => $stringList($rd['method_statement_access_equipment']   ?? []),
            'access_requirements'     => $stringList($rd['method_statement_access_requirements'] ?? []),
            'client_responsibilities' => $stringList($rd['client_responsibilities_expanded']    ?? []),
            'steps'                   => $steps,
            'material_handling'       => $mhBullets,
            'material_handling_items' => $mhItems,
            'permits'                 => $permits,
            'fixings_controls'        => $stringList($rd['method_statement_fixings']             ?? []),
            'supervision'             => $stringList($rd['method_statement_supervision']         ?? []),
            'coordination'            => $stringList($rd['method_statement_coordination']        ?? []),
            'it_safety'               => $stringList($rd['method_statement_it_safety']           ?? []),
        ]);
    }
}
