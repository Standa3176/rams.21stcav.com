<?php

namespace App\View\Components;

use App\Models\LabourResource;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * Phase 44 Plan 03 Task 1 — PM-facing multi-select of active labour
 * resources (44-CONTEXT.md D-03, D-05).
 *
 * This component queries `LabourResource::active()` itself inside
 * `render()`. There is no prop path that accepts a pre-built resource
 * list, so a caller cannot smuggle a deactivated resource through by
 * forgetting to filter — the structural guarantee behind T-44-08.
 *
 * For the PM only (D-05) — do not wire this into any engineer-facing form.
 *
 * @see .planning/phases/44-labour-resources/44-CONTEXT.md (D-02, D-03, D-04, D-05)
 */
class LabourResourceSelect extends Component
{
    /**
     * @param  array<int, int>  $selected  Resource IDs to pre-select.
     * @param  string  $name  Base input name; rendered as "{$name}[]" on the <select multiple>.
     */
    public function __construct(
        public array $selected = [],
        public string $name = 'resource_ids',
    ) {
    }

    public function render(): View
    {
        return view('components.labour-resource-select', [
            'resources' => LabourResource::active()->orderBy('name')->get(),
        ]);
    }
}
