{{--
    <x-mobile-tab-bar />
    Rendered automatically by app.blade.php inside @auth block.
    Shows bottom navigation on mobile (≤768px). Hidden on desktop via CSS.
--}}

<nav class="mobile-tab-bar" aria-label="Mobile navigation">
    <div class="mobile-tab-bar__inner">

        @php $current = request()->route()?->getName() ?? ''; @endphp

        {{-- Projects --}}
        @if (\Illuminate\Support\Facades\Route::has('projects.index'))
        <a href="{{ route('projects.index') }}"
           class="mobile-tab-bar__item {{ str_starts_with($current, 'projects') ? 'active' : '' }}"
           aria-label="Projects">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="1.75" stroke-linecap="round"
                 stroke-linejoin="round" aria-hidden="true">
                <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
            </svg>
            Projects
        </a>
        @endif

        {{-- RAMS --}}
        @if (\Illuminate\Support\Facades\Route::has('rams.index'))
        <a href="{{ route('rams.index') }}"
           class="mobile-tab-bar__item {{ str_starts_with($current, 'rams') ? 'active' : '' }}"
           aria-label="RAMS">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="1.75" stroke-linecap="round"
                 stroke-linejoin="round" aria-hidden="true">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                <polyline points="14 2 14 8 20 8"/>
                <line x1="16" y1="13" x2="8" y2="13"/>
                <line x1="16" y1="17" x2="8" y2="17"/>
                <polyline points="10 9 9 9 8 9"/>
            </svg>
            RAMS
        </a>
        @endif

        {{-- Site Surveys --}}
        @if (\Illuminate\Support\Facades\Route::has('site-surveys.index'))
        <a href="{{ route('site-surveys.index') }}"
           class="mobile-tab-bar__item {{ str_starts_with($current, 'site-surveys') ? 'active' : '' }}"
           aria-label="Surveys">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="1.75" stroke-linecap="round"
                 stroke-linejoin="round" aria-hidden="true">
                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                <circle cx="12" cy="10" r="3"/>
            </svg>
            Surveys
        </a>
        @endif

        {{-- Quote Import --}}
        @if (\Illuminate\Support\Facades\Route::has('quote-import.create'))
        <a href="{{ route('quote-import.create') }}"
           class="mobile-tab-bar__item {{ str_starts_with($current, 'quote-import') ? 'active' : '' }}"
           aria-label="Import">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="1.75" stroke-linecap="round"
                 stroke-linejoin="round" aria-hidden="true">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                <polyline points="17 8 12 3 7 8"/>
                <line x1="12" y1="3" x2="12" y2="15"/>
            </svg>
            Import
        </a>
        @endif

    </div>
</nav>
