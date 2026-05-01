<?php

namespace App\Services\DocumentEdits\Adapters;

use App\Models\ProjectDrawing;
use App\Services\DocumentEdits\DocumentEditAdapterInterface;
use Illuminate\Support\Facades\Log;

/**
 * Phase 17 SCAFFOLDING ONLY — DRAW-30 (DocumentEdits chat for drawings).
 *
 * Functional schematic chat ships in Phase 19 alongside the Konva editor
 * (per CONTEXT.md GAP-4 deferral). Phase 17 lands the adapter shape so:
 *   - The DocumentEditAdapterRegistry knows about 'drawing'.
 *   - DocumentEditParsingPromptFactory has a snapshot arm to prevent leak
 *     of equipment lists / cross-project ids.
 *   - Phase 18 (rack edits) and Phase 19 (floor plan edits + functional
 *     schematic chat) plug in by enriching this adapter — they don't add
 *     a new one.
 *
 * Operations are LAYOUT-ONLY: AI MAY NEVER add equipment, cables, rooms,
 * or any canonical data (REQUIREMENTS.md DRAW-30 + CLAUDE.md AI usage
 * constraint). Concretely the allow-list is fixed at:
 *   - set_status        (workflow flip — draft / for_review / approved only)
 *   - set_revision_note (audit-only string on the drawing)
 *   - add_layout_hint   (recorded but not yet acted on — Phase 19 wires it)
 */
class DrawingEditAdapter implements DocumentEditAdapterInterface
{
    public function documentType(): string
    {
        return 'drawing';
    }

    public function loadPayload(int $documentId): ?array
    {
        $drawing = ProjectDrawing::query()->find($documentId);
        if ($drawing === null) {
            return null;
        }

        return [
            'kind' => (string) $drawing->kind,
            'status' => (string) $drawing->status,
            'version' => (int) $drawing->version,
            'has_canvas_state' => ! empty($drawing->canvas_state),
            'project_ref' => (string) ($drawing->project->ref ?? ''),
        ];
    }

    public function allowedOperations(): array
    {
        return [
            'set_status',
            'set_revision_note',
            'add_layout_hint',
        ];
    }

    /**
     * Per-op argument schemas. Surfaced by the parser prompt factory so the
     * AI knows what `args` shapes are accepted for each op — prevents the
     * AI from inventing fields like 'equipment' or 'cable_id' that would
     * otherwise be silently rejected.
     *
     * @return array<string, array{args:array<string,string>, notes?:string}>
     */
    public function operationSchemas(): array
    {
        return [
            'set_status' => [
                'args' => [
                    'value' => 'One of: draft | for_review | approved',
                ],
                'notes' => 'Cannot set superseded — only the regenerate flow does that. '
                    .'Cannot set generating/ready/failed — those are job-controlled.',
            ],
            'set_revision_note' => [
                'args' => [
                    'text' => 'string — short revision note (≤200 chars)',
                ],
                'notes' => 'Stored on the drawing for audit (DRAW-24). Does not modify '
                    .'the SVG or canvas_state.',
            ],
            'add_layout_hint' => [
                'args' => [
                    'hint' => 'string — short layout suggestion (e.g. "group audio chain top, video bottom")',
                ],
                'notes' => 'Phase 17 SCAFFOLDING ONLY — actual schematic editor lands in '
                    .'Phase 19. The hint is recorded but does not yet alter generation. '
                    .'AI may NEVER add equipment or cables; layout-only.',
            ],
        ];
    }

    /**
     * Allowed status flips via chat (subset of ProjectDrawing::STATUS_*).
     * Generating / ready / failed are JOB-controlled; superseded is regen-controlled.
     */
    private const ALLOWED_STATUS_VALUES = [
        ProjectDrawing::STATUS_DRAFT,
        ProjectDrawing::STATUS_FOR_REVIEW,
        ProjectDrawing::STATUS_APPROVED,
    ];

    public function applyOperation(array $payload, array $op): array
    {
        return match (strtolower(trim((string) ($op['op'] ?? '')))) {
            'set_status' => $this->applySetStatus($payload, $op),
            'set_revision_note' => $this->applySetRevisionNote($payload, $op),
            'add_layout_hint' => $this->applyAddLayoutHint($payload, $op),
            default => [
                'ok' => false,
                'code' => 'unknown_operation',
                'error' => "Unknown drawing op '{$op['op']}'",
            ],
        };
    }

    private function applySetStatus(array $payload, array $op): array
    {
        $value = strtolower(trim((string) ($op['value'] ?? '')));
        if ($value === '') {
            return [
                'ok' => false,
                'code' => 'invalid_op',
                'error' => 'set_status requires a `value`',
            ];
        }
        if (! in_array($value, self::ALLOWED_STATUS_VALUES, true)) {
            return [
                'ok' => false,
                'code' => 'invalid_op',
                'error' => 'set_status only accepts: '.implode(', ', self::ALLOWED_STATUS_VALUES),
            ];
        }
        $payload['status'] = $value;

        return ['ok' => true, 'payload' => $payload];
    }

    private function applySetRevisionNote(array $payload, array $op): array
    {
        if (! array_key_exists('text', $op) || ! is_string($op['text'])) {
            return [
                'ok' => false,
                'code' => 'invalid_op',
                'error' => 'set_revision_note requires a string `text`',
            ];
        }
        $payload['revision_note'] = trim($op['text']);

        return ['ok' => true, 'payload' => $payload];
    }

    private function applyAddLayoutHint(array $payload, array $op): array
    {
        $hint = trim((string) ($op['hint'] ?? ''));
        if ($hint === '') {
            return [
                'ok' => false,
                'code' => 'invalid_op',
                'error' => 'add_layout_hint requires a non-empty `hint`',
            ];
        }
        $payload['layout_hints'] = (array) ($payload['layout_hints'] ?? []);
        $payload['layout_hints'][] = $hint;

        return ['ok' => true, 'payload' => $payload];
    }

    public function summariseDiff(array $before, array $after): array
    {
        return [
            'status_changed' => ($before['status'] ?? null) !== ($after['status'] ?? null),
        ];
    }

    /**
     * Phase 17 scaffolding: persist status flips only. Functional regen is
     * deferred — Phase 19 wires this up alongside the Konva editor and the
     * full schematic chat behaviour. Returns null (no artifact regen here).
     */
    public function commitChanges(int $documentId, array $payload): ?string
    {
        $drawing = ProjectDrawing::query()->find($documentId);
        if ($drawing === null) {
            Log::warning('DrawingEditAdapter::commitChanges drawing not found', [
                'id' => $documentId,
            ]);

            return null;
        }

        if (array_key_exists('status', $payload)
            && in_array($payload['status'], self::ALLOWED_STATUS_VALUES, true)) {
            $drawing->update(['status' => $payload['status']]);
        }

        // revision_note + layout_hints are recorded in the change-set audit
        // log by the upstream DocumentRevisionService; no schema-level home
        // for them on the project_drawings table at Phase 17.

        return null;
    }
}
