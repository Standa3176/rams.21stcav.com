{{-- Phase 17 Plan 03 — reusable status pill for ProjectDrawing rows.
     Reads $drawing->statusLabel() + $drawing->statusBadgeClass() (defined on
     the model). Tailwind classes mirror the badge style used in
     resources/views/site-survey/show.blade.php for visual consistency. --}}
<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $drawing->statusBadgeClass() }}">
    {{ $drawing->statusLabel() }}
</span>
