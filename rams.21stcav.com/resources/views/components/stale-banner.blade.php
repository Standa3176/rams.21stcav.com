@props([
    'doc',                {{-- Any model that implements isStale() + staleSince(). --}}
    'label'    => 'document',   {{-- What to call it in the copy: "worksheet", "RAMS", "O&M manual". --}}
    'regenUrl' => null,   {{-- Regenerate action URL. When set, renders the ↻ Regenerate button. --}}
    'variant'  => 'admin',{{-- 'admin' | 'pill' — matches the worksheets/_stale-banner API. --}}
])

@php
    /*
     * Batch 11 UX-09 — shared stale-data banner.
     * Ports the worksheets/_stale-banner.blade.php pattern into a
     * generic Blade component so RAMS + O&M + worksheet all emit the
     * same amber banner when the source ProjectPackage has advanced
     * past the document's snapshot.
     *
     * Early-exit when the doc isn't stale so callers can drop the
     * component inline without an outer @if guard.
     */
    if (! method_exists($doc, 'isStale') || ! $doc->isStale()) {
        return;
    }

    $staleAgo = method_exists($doc, 'staleSince')
        ? $doc->staleSince()?->diffForHumans()
        : null;
@endphp

@if ($variant === 'pill')
    <span class="inline-block px-2 py-1 text-xs rounded"
          style="background: var(--warning-light); color:#92400E; border:1px solid color-mix(in oklab, var(--warning) 30%, transparent);"
          title="Project data updated {{ $staleAgo }} — regenerate to refresh">
        Stale
    </span>
@else
    <div class="mb-4" role="status"
         style="background: var(--warning-light); border:1px solid color-mix(in oklab, var(--warning) 30%, transparent); color:#92400E; border-radius: var(--radius-lg); padding:16px 18px; display:flex; align-items:flex-start; justify-content:space-between; gap:12px; flex-wrap:wrap;">
        <div style="min-width:0; flex:1;">
            <strong style="font-weight:600;">⚠ Project data was updated {{ $staleAgo }}, after this {{ $label }} was generated.</strong>
            <div style="font-size: var(--fs-small); margin-top:4px; color:#78350F;">
                The {{ $label }} may be out of date — regenerate to pick up the latest changes.
            </div>
        </div>
        @if ($regenUrl)
            <form method="POST"
                  action="{{ $regenUrl }}"
                  data-confirm="Regenerate this {{ $label }}? The current version will be replaced."
                  data-confirm-label="Regenerate"
                  style="display:inline; flex-shrink:0;">
                @csrf
                <button type="submit" class="btn btn-sm"
                        style="background:#B45309; border-color:#B45309; color:#fff;"
                        aria-label="Regenerate {{ $label }}">
                    ↻ Regenerate
                </button>
            </form>
        @endif
    </div>
@endif
