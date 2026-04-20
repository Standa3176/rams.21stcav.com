{{--
    Custom pagination view — overrides Laravel's default Tailwind partial.

    The authenticated layout uses hand-rolled design-token CSS (see
    layouts/app.blade.php `.pagination-wrap` rules), not Tailwind. The stock
    Tailwind pagination view emits SVG chevrons sized via `w-5 h-5` classes
    that don't exist here, so the icons render at intrinsic size and fill the
    page. It also wraps the "Showing X to Y of Z" numbers in <span> elements,
    which inherit the global `.pagination-wrap span` button treatment and look
    like form inputs.

    This replacement outputs strict `nav > (a|span)` markup — text chevrons
    (‹ / ›), numeric page links, and a plain-<strong> meta line that sits
    outside the nav so it isn't caught by the button rule.
--}}
@if ($paginator->hasPages())
    <div class="pag-block">
        <p class="pag-meta">
            Showing <strong>{{ $paginator->firstItem() ?? 0 }}</strong>
            to <strong>{{ $paginator->lastItem() ?? 0 }}</strong>
            of <strong>{{ $paginator->total() }}</strong> results
        </p>

        <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}">
            {{-- Previous --}}
            @if ($paginator->onFirstPage())
                <span aria-disabled="true" class="is-disabled" aria-label="{{ __('pagination.previous') }}">&lsaquo;</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="{{ __('pagination.previous') }}">&lsaquo;</a>
            @endif

            {{-- Numbered page links --}}
            @foreach ($elements as $element)
                @if (is_string($element))
                    <span aria-disabled="true" class="is-separator">{{ $element }}</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span aria-current="page">{{ $page }}</span>
                        @else
                            <a href="{{ $url }}" aria-label="{{ __('Go to page :page', ['page' => $page]) }}">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            {{-- Next --}}
            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="{{ __('pagination.next') }}">&rsaquo;</a>
            @else
                <span aria-disabled="true" class="is-disabled" aria-label="{{ __('pagination.next') }}">&rsaquo;</span>
            @endif
        </nav>
    </div>
@endif
