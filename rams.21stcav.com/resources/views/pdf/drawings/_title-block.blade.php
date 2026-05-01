{{--
    Standard title block partial — DRAW-22.

    Reused by Phases 17 (schematics), 18 (rack elevations), 19 (floor plans),
    and 20 (drawing export). Pass the ProjectDrawing instance as $drawing.

    Field set is governed by config('drawings.title_block_fields'):
        project_ref, client, drawn_by, date, revision, status

    Phase 20 may extend with "Checked by" / "Approved by" once the status
    workflow matures (CONTEXT.md "Claude's Discretion").

    Browsershot-friendly:
      - Pure HTML table (no flex/grid quirks)
      - Inline <style> kept tiny so the partial composes inside other
        Blade views (schematic.blade.php, rack.blade.php, floor-plan.blade.php)
      - No SVG foreign-object containers, no JS

--}}
<style>
    .title-block {
        border-collapse: collapse;
        font-family: 'Figtree', sans-serif;
        font-size: 10pt;
        min-width: 220px;
        margin: 0;
    }
    .title-block td {
        border: 1px solid #444;
        padding: 3px 8px;
        vertical-align: top;
    }
    .title-block td:first-child {
        font-weight: 600;
        background: #f3f4f6;
        white-space: nowrap;
    }
    .title-block__title {
        background: #1f2937 !important;
        color: #fff !important;
        font-weight: 700;
        text-align: center;
        font-size: 11pt;
        letter-spacing: 0.5px;
        padding: 4px 8px;
    }
</style>
@php
    $project   = $drawing->project;
    $drawnBy   = optional($drawing->generatedBy)->name ?? '—';
    $today     = $drawing->updated_at?->format('Y-m-d') ?? now()->format('Y-m-d');
    $rev       = $drawing->revisionLabel();
    $statusLbl = $drawing->statusLabel();
@endphp
<table class="title-block">
    <tr>
        <td colspan="2" class="title-block__title">{{ $drawing->kindLabel() }}</td>
    </tr>
    <tr>
        <td>Project Ref</td>
        <td>{{ $project->ref ?? $project->quote_reference ?? '—' }}</td>
    </tr>
    <tr>
        <td>Client</td>
        <td>{{ $project->client_name ?? $project->client ?? '—' }}</td>
    </tr>
    <tr>
        <td>Drawn by</td>
        <td>{{ $drawnBy }}</td>
    </tr>
    <tr>
        <td>Date</td>
        <td>{{ $today }}</td>
    </tr>
    <tr>
        <td>Revision</td>
        <td>{{ $rev }}</td>
    </tr>
    <tr>
        <td>Status</td>
        <td>{{ $statusLbl }}</td>
    </tr>
</table>
