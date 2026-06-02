{{--
  Worksheet stale-data banner — quick task 260602-o2a.

  Single-source-of-truth partial: re-used on admin show (variant=admin, full
  banner + Regenerate button), admin index + project show (variant=pill,
  compact amber pill), and the public engineer link (variant=public,
  informational banner only, NO button).

  Props:
    $worksheet  (App\Models\Worksheet, required) — the worksheet to check.
    $variant    (string, default 'admin')        — 'admin' | 'public' | 'pill'.

  Renders nothing when $worksheet->isStale() is false (early-exit), so callers
  can mount it unconditionally inside row loops without an outer @if guard.

  @see App\Models\Worksheet::isStale
  @see App\Models\Worksheet::staleSince
--}}
@php
    /** @var \App\Models\Worksheet $worksheet */
    /** @var string $variant */
    $variant  = $variant ?? 'admin';

    if (! $worksheet->isStale()) {
        return;
    }

    $staleAgo = $worksheet->staleSince()?->diffForHumans();
@endphp

@if ($variant === 'pill')
    <span class="inline-block px-2 py-1 text-xs rounded bg-amber-100 text-amber-800"
          title="Project data updated {{ $staleAgo }} — regenerate to refresh">
        Stale
    </span>
@elseif ($variant === 'public')
    {{-- Public engineer link: amber chrome via RGBA inline styles (public view
         does NOT load Tailwind — see public-show.blade.php raw <style> block).
         RGBA palette lifted from .room-drawer.amber (lines 315/337/345). --}}
    <div role="status"
         style="background:rgba(245,158,11,.08); border:1px solid rgba(245,158,11,.4); color:#92400E; border-radius:8px; padding:.9rem 1.1rem; margin-bottom:1rem; font-size:.9rem; line-height:1.5;">
        <strong>&#9888; Project data has been updated since this worksheet was generated.</strong>
        Ask the office to refresh it before signing off.
    </div>
@else
    {{-- Admin show: full Tailwind amber banner with Regenerate button.
         Re-uses the data-confirm modal pattern from 260504-m2k (see show.blade.php
         line 115) so no new JS is needed — the project-wide handler pops the
         confirmation modal on click. --}}
    <div class="bg-amber-50 border border-amber-300 text-amber-900 rounded-lg p-4 mb-4 flex items-start justify-between gap-3">
        <div>
            <strong>&#9888; Project data was updated {{ $staleAgo }}, after this worksheet was generated.</strong>
            The worksheet may be out of date — regenerate to pick up the latest changes.
        </div>
        <form method="POST"
              action="{{ route('worksheets.retry-generation', $worksheet) }}"
              data-confirm="Regenerate this worksheet? The current DOCX will be replaced."
              data-confirm-label="Regenerate"
              style="display:inline;">
            @csrf
            <button type="submit"
                    class="bg-amber-600 hover:bg-amber-700 text-white text-sm font-medium px-3 py-1.5 rounded-md whitespace-nowrap"
                    aria-label="Regenerate Worksheet DOCX">&#8635; Regenerate</button>
        </form>
    </div>
@endif
