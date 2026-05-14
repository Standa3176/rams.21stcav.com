<?php

namespace App\Services\Drawings;

use App\Models\Project;
use App\Models\ProjectDrawing;
use Illuminate\Support\Facades\Auth;

/**
 * Phase 23 — Title block renderer (DRAW-48).
 *
 * Emits 8 mxCell text descriptors per sheet at the bottom of the page,
 * with field values resolved per CONTEXT D-08:
 *
 *   project     → Project.name
 *   client      → Project.client_name
 *   designed-by → Auth::user()->name (else '—')
 *   drawn-by    → same as designed-by (D-08 default)
 *   checked-by  → Project.metadata['drawing_checked_by'] (else '—')
 *   sheet       → $sheet['sheet_number'] (from SheetPaginator)
 *   date        → now()->format('Y-m-d') — Carbon::setTestNow honoured for determinism
 *   revision    → ProjectDrawing.version cast to string (else 'R0')
 *
 * Threat mitigations:
 *   T-23-04-A1 — every interpolated user string passes through xml()
 *     (htmlspecialchars ENT_XML1 | ENT_QUOTES) before becoming an mxCell
 *     value attribute. Tested for project name, client name, checked-by,
 *     and Auth-user-name (designed-by/drawn-by).
 *   T-23-04-A3 — Auth user's `name` field is read, NOT `email`. Tests confirm.
 *
 * Pure read-only — NO Eloquent writes, NO AI calls, deterministic.
 *
 * @see .planning/phases/23-xten-av-style-renderer/23-CONTEXT.md D-08
 * @see .planning/phases/23-xten-av-style-renderer/23-RESEARCH.md Example 6
 */
class TitleBlockRenderer
{
    /**
     * Text-only mxCell style — transparent fill, no border, left-aligned
     * 10pt text. Matches RESEARCH.md Example 6 contract.
     */
    private const FIELD_STYLE = 'text;html=1;align=left;verticalAlign=middle;strokeColor=none;fillColor=none;fontSize=10;fontColor=#333333;';

    private const FIELD_WIDTH = 200;

    private const FIELD_HEIGHT = 20;

    private const FIELD_START_X = 60;

    private const FIELD_GAP = 30;

    /**
     * Render the 8-field title block for one sheet.
     *
     * @param  array{key: string, sheet_number: string, title: string, signal_filter: ?string}  $sheet
     * @return array<int, array<string, mixed>>
     */
    public function render(array $sheet, Project $project, ?ProjectDrawing $drawing = null): array
    {
        $y = (int) (config('drawings.page_dimensions.title_block_y') ?? 940);

        // D-08 source resolution — keep raw values here; xml() escapes at emit time.
        $userName = Auth::user()?->name ?? '—';
        if ($userName === '') {
            $userName = '—';
        }

        $metadata = is_array($project->metadata) ? $project->metadata : [];
        $checkedBy = (string) ($metadata['drawing_checked_by'] ?? '—');
        if ($checkedBy === '') {
            $checkedBy = '—';
        }

        $revision = $drawing?->version !== null ? (string) $drawing->version : 'R0';

        // Field ordering is the contract — Plan 23-05's orchestrator + the
        // visual contract reference image expect this left-to-right layout.
        $fields = [
            ['label' => 'Project',     'value' => (string) $project->name],
            ['label' => 'Client',      'value' => (string) $project->client_name],
            ['label' => 'Designed by', 'value' => $userName],
            ['label' => 'Drawn by',    'value' => $userName],
            ['label' => 'Checked by',  'value' => $checkedBy],
            ['label' => 'Sheet',       'value' => (string) $sheet['sheet_number']],
            ['label' => 'Date',        'value' => now()->format('Y-m-d')],
            ['label' => 'Rev',         'value' => $revision],
        ];

        $cells = [];
        foreach ($fields as $i => $field) {
            // Build the displayed value, THEN escape — so the label colon prefix
            // is preserved verbatim and only user-controlled content is escaped.
            $cells[] = [
                'kind'   => 'title-block-field',
                'id'     => 'tb-'.$sheet['key'].'-'.$i,
                'value'  => $this->xml($field['label'].': '.$field['value']),
                'style'  => self::FIELD_STYLE,
                'parent' => '1',
                'x'      => self::FIELD_START_X + $i * (self::FIELD_WIDTH + self::FIELD_GAP),
                'y'      => $y,
                'w'      => self::FIELD_WIDTH,
                'h'      => self::FIELD_HEIGHT,
            ];
        }

        return $cells;
    }

    /**
     * Mitigates T-23-04-A1 — escape XML special chars in user-supplied
     * strings before interpolation into mxCell value attribute.
     */
    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
