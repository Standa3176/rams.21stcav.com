{{--
    Sidebar navigation — tier-one v2 (2026-07-08, PLAN 260708-b7i).
    Rendered inside <aside class="app-sidebar"> by app.blade.php.
    Entire file is inside @auth — guests never see this partial.
--}}
@auth
<style>
    /* ── Sidebar nav — tier-one light chrome ─────────────────────────
       Was: dark teal background with white foreground + gold admin
       accent. Now: light sidebar with indigo active rail + muted body
       text + soft admin-section separator.
    */
    .sidebar-nav {
        display: flex;
        flex-direction: column;
        gap: 1px;
        padding: 12px 10px 4rem;
    }

    /* ⌘K search shortcut at the top of the sidebar. Non-functional
       affordance in v2 (real search wired later) — the visible chip is
       a tier-one cue that global search exists. */
    .snav-search {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 7px 10px;
        margin: 4px 6px 12px;
        background: #FFFFFF;
        border: 1px solid var(--border);
        border-radius: 6px;
        color: var(--text-muted);
        font-size: 12px;
        cursor: text;
        transition: border-color .12s, background .12s;
    }
    .snav-search:hover { border-color: var(--ink-300); background: var(--surface); }
    .snav-search svg { width: 13px; height: 13px; color: var(--text-faint); flex-shrink: 0; }
    .snav-search-input { flex: 1; color: var(--text-faint); }
    .snav-search-kbd {
        margin-left: auto;
        padding: 1px 6px; border-radius: 4px;
        background: var(--paper);
        border: 1px solid var(--border);
        color: var(--text-muted);
        font-family: var(--font-mono);
        font-size: 10px; font-weight: 500;
    }

    .snav-label {
        padding: 12px 12px 6px;
        font-size: 10px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .10em;
        color: var(--sidebar-fg-mute);
        white-space: nowrap;
        user-select: none;
    }

    .snav-link {
        position: relative;
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 7px 12px;
        border-radius: 6px;
        color: var(--sidebar-fg);
        font-size: 13px;
        font-weight: 500;
        text-decoration: none;
        transition: background .12s, color .12s;
        line-height: 1.35;
    }
    .snav-link:hover {
        background: color-mix(in oklab, var(--sidebar-active-bg) 50%, transparent);
        color: var(--ink-900);
        text-decoration: none;
    }
    .snav-link:hover .snav-icon { color: var(--teal-700); }

    .snav-link.active {
        background: var(--sidebar-active-bg);
        color: var(--sidebar-active-fg);
        font-weight: 600;
    }
    .snav-link.active .snav-icon { color: var(--teal-700); opacity: 1; }
    /* Left rail marker on active item — subtle 2px indigo accent. */
    .snav-link.active::before {
        content: "";
        position: absolute;
        left: -10px;
        top: 5px;
        bottom: 5px;
        width: 2px;
        background: var(--teal-700);
        border-radius: 0 2px 2px 0;
    }

    .snav-icon {
        width: 15px; height: 15px;
        flex-shrink: 0;
        color: var(--sidebar-fg-mute);
        opacity: 1;
        transition: color .12s;
    }

    .snav-sep {
        height: 1px;
        background: var(--border);
        margin: 10px 12px;
    }

    /* Admin dot — indigo (was gold) at 4px. Sits at the row end and
       marks admin-only items so a non-admin toggle later stays clear. */
    .snav-admin-dot {
        margin-left: auto;
        width: 4px; height: 4px;
        border-radius: 50%;
        background: var(--teal-500);
        flex-shrink: 0;
        opacity: .7;
    }

    /* Admin-only nav — same colour system as regular items, no more
       gold tint. Left-rail marker still fires on active. */
    .snav-label.admin-only { color: var(--sidebar-fg-mute); }
    .snav-link.admin-only { color: var(--sidebar-fg); }
    .snav-link.admin-only:hover {
        background: color-mix(in oklab, var(--sidebar-active-bg) 50%, transparent);
        color: var(--ink-900);
    }
</style>

<nav class="sidebar-nav" aria-label="Main navigation">
    @php $isAdmin = auth()->user()?->isAdmin(); @endphp

    {{-- ── Global search affordance (⌘K) ─────────────────────────── --}}
    <div class="snav-search" role="button" tabindex="0" aria-label="Global search (coming soon)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>
        </svg>
        <span class="snav-search-input">Search…</span>
        <span class="snav-search-kbd">⌘K</span>
    </div>

    {{-- ── MAIN ──────────────────────────────────────────────────── --}}
    <div class="snav-label">Main</div>

    @if ($isAdmin)
    <a href="{{ route('dashboard') }}"
       class="snav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}">
        <svg class="snav-icon" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
            <rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/>
        </svg>
        Dashboard
    </a>
    @endif

    <a href="{{ route('projects.index') }}"
       class="snav-link {{ request()->routeIs('projects.*') ? 'active' : '' }}">
        <svg class="snav-icon" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
        </svg>
        Projects
    </a>

    @if ($isAdmin)
    <div class="snav-sep"></div>

    {{-- ── DELIVERY TOOLS ────────────────────────────────────────── --}}
    <div class="snav-label admin-only">Delivery Tools</div>

    <a href="{{ route('rams.index') }}"
       class="snav-link admin-only {{ request()->routeIs('rams.*') && ! request()->routeIs('rams.upload*') && ! request()->routeIs('rams.settings*') ? 'active' : '' }}">
        <svg class="snav-icon" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
            <polyline points="14 2 14 8 20 8"/>
            <line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>
        </svg>
        RAMS
    </a>

    <a href="{{ route('site-surveys.index') }}"
       class="snav-link admin-only {{ request()->routeIs('site-surveys.*') ? 'active' : '' }}">
        <svg class="snav-icon" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
            <polyline points="9 22 9 12 15 12 15 22"/>
        </svg>
        Site Surveys
    </a>

    <a href="{{ route('cable-schedules.index') }}"
       class="snav-link admin-only {{ request()->routeIs('cable-schedules.*') ? 'active' : '' }}">
        <svg class="snav-icon" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <line x1="8" y1="6"  x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/>
            <line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6"  x2="3.01" y2="6"/>
            <line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>
        </svg>
        Cable Schedules
    </a>

    <a href="{{ route('om-manuals.index') }}"
       class="snav-link admin-only {{ request()->routeIs('om-manuals.*') ? 'active' : '' }}"
       title="Operations &amp; Maintenance Manuals">
        <svg class="snav-icon" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/>
            <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>
        </svg>
        O&amp;M Manuals
    </a>

    <div class="snav-sep"></div>

    {{-- ── IMPORT ─────────────────────────────────────────────────── --}}
    <div class="snav-label admin-only">Import</div>

    <a href="{{ route('quote-import.create') }}"
       class="snav-link admin-only {{ request()->routeIs('quote-import.*') ? 'active' : '' }}"
       title="Import a QuoteWerks PDF to generate a project package">
        <svg class="snav-icon" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <polyline points="16 16 12 12 8 16"/>
            <line x1="12" y1="12" x2="12" y2="21"/>
            <path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/>
        </svg>
        Quote Import
    </a>
    @endif

    {{-- ── ADMIN (admin only) ────────────────────────────────────── --}}
    @if ($isAdmin)
    <div class="snav-sep"></div>
    <div class="snav-label admin-only">Admin</div>

    <a href="{{ route('admin.users.index') }}"
       class="snav-link admin-only {{ request()->routeIs('admin.users.*') ? 'active' : '' }}">
        <svg class="snav-icon" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
            <circle cx="9" cy="7" r="4"/>
            <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
            <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
        </svg>
        Users
        <span class="snav-admin-dot" aria-hidden="true"></span>
    </a>

    <a href="{{ route('admin.solution-types.index') }}"
       class="snav-link admin-only {{ request()->routeIs('admin.solution-types.*') ? 'active' : '' }}">
        <svg class="snav-icon" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
            <rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>
        </svg>
        Solution Types
        <span class="snav-admin-dot" aria-hidden="true"></span>
    </a>

    <a href="{{ route('hazard-templates.index') }}"
       class="snav-link admin-only {{ request()->routeIs('hazard-templates.*') ? 'active' : '' }}">
        <svg class="snav-icon" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
            <line x1="12" y1="9" x2="12" y2="13"/>
            <line x1="12" y1="17" x2="12.01" y2="17"/>
        </svg>
        Hazard Templates
        <span class="snav-admin-dot" aria-hidden="true"></span>
    </a>

    <a href="{{ route('rams.settings') }}"
       class="snav-link admin-only {{ request()->routeIs('rams.settings*') ? 'active' : '' }}"
       title="AI Provider Settings">
        <svg class="snav-icon" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="12" cy="12" r="3"/>
            <path d="M19.07 4.93A10 10 0 1 0 4.93 19.07"/><path d="M19.07 4.93L12 12"/>
        </svg>
        AI Settings
        <span class="snav-admin-dot" aria-hidden="true"></span>
    </a>

    <a href="{{ route('admin.ai-usage.index') }}"
       class="snav-link admin-only {{ request()->routeIs('admin.ai-usage.*') ? 'active' : '' }}"
       title="AI Usage">
        <svg class="snav-icon" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <polyline points="3 6 9 12 13 8 21 16"/>
            <circle cx="9" cy="12" r="1"/><circle cx="13" cy="8" r="1"/><circle cx="21" cy="16" r="1"/>
        </svg>
        AI Usage
        <span class="snav-admin-dot" aria-hidden="true"></span>
    </a>

    <a href="{{ route('admin.worker.index') }}"
       class="snav-link admin-only {{ request()->routeIs('admin.worker*') ? 'active' : '' }}"
       title="Queue Worker Monitor">
        <svg class="snav-icon" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/>
            <line x1="12" y1="17" x2="12" y2="21"/>
        </svg>
        Worker
        <span class="snav-admin-dot" aria-hidden="true"></span>
    </a>
    @endif

</nav>
@endauth
