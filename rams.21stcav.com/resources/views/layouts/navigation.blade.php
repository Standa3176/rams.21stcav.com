{{--
    Top navigation bar — Jetbuilt-style horizontal shell (2026-07-08).
    Rendered inside <nav class="app-topnav"> by app.blade.php. Replaces
    the previous vertical sidebar. Entire file is inside @auth so guests
    never see it.
--}}
@auth
<style>
    /* ── Top-nav row — Jetbuilt horizontal shell ──────────────────── */
    .tnav-brand {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 0 20px 0 24px;
        height: 100%;
        color: var(--ink-900);
        text-decoration: none;
        border-right: 1px solid var(--ink-100);
        flex-shrink: 0;
    }
    .tnav-brand:hover { text-decoration: none; color: var(--ink-900); }
    .tnav-brand-mark {
        width: 30px; height: 30px;
        background: var(--nav-800);
        color: #fff;
        border-radius: var(--radius-sm);
        display: grid;
        place-items: center;
        flex-shrink: 0;
        font-weight: 700;
        font-size: 13px;
        letter-spacing: -0.03em;
    }
    .tnav-brand-mark svg { color: #fff; }
    .tnav-brand-name {
        font-size: 15px;
        font-weight: 600;
        color: var(--ink-900);
        letter-spacing: -.015em;
    }

    /* ── Primary nav links — horizontal row ─────────────────────────
       Fills the space between brand and right-hand controls. Overflows
       to a hamburger below 900px (see @media at the bottom). */
    .tnav-primary {
        display: flex;
        align-items: stretch;
        height: 100%;
        gap: 2px;
        padding: 0 16px;
        flex: 1;
        min-width: 0;
        overflow-x: auto;
        scrollbar-width: none;
    }
    .tnav-primary::-webkit-scrollbar { display: none; }

    .tnav-link {
        position: relative;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 0 14px;
        height: 100%;
        color: var(--ink-500);
        font-size: 13px;
        font-weight: 500;
        letter-spacing: -0.005em;
        text-decoration: none;
        white-space: nowrap;
        transition: color var(--transition);
    }
    .tnav-link:hover {
        color: var(--ink-900);
        text-decoration: none;
    }
    .tnav-link.active {
        color: var(--ink-900);
        font-weight: 600;
    }
    /* Active-state bottom rail — Jetbuilt uses a 2px accent underline */
    .tnav-link.active::after {
        content: "";
        position: absolute;
        left: 12px; right: 12px; bottom: -1px;
        height: 2px;
        background: var(--accent-600);
        border-radius: 2px 2px 0 0;
    }
    .tnav-icon {
        width: 14px; height: 14px;
        color: currentColor;
        flex-shrink: 0;
        opacity: .9;
    }

    /* ── Admin overflow menu — dropdown pinned right ──────────────── */
    .tnav-admin {
        position: relative;
        display: inline-flex;
        align-items: center;
        height: 100%;
        margin-left: 4px;
    }
    .tnav-admin-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 0 12px;
        height: 100%;
        background: transparent;
        border: none;
        color: var(--ink-500);
        font-size: 13px;
        font-weight: 500;
        cursor: pointer;
        transition: color var(--transition);
    }
    .tnav-admin-btn:hover { color: var(--ink-900); }
    .tnav-admin-btn.active { color: var(--ink-900); font-weight: 600; }
    .tnav-admin-menu {
        position: absolute;
        top: calc(100% - 1px);
        right: 0;
        min-width: 220px;
        background: var(--surface);
        border: 1px solid var(--ink-200);
        border-radius: var(--radius-lg);
        box-shadow: var(--shadow-pop);
        z-index: 300;
        padding: 6px;
        display: none;
    }
    .tnav-admin[data-open="1"] .tnav-admin-menu { display: block; }
    .tnav-admin-item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 8px 10px;
        border-radius: var(--radius-sm);
        color: var(--ink-700);
        font-size: 13px;
        font-weight: 500;
        text-decoration: none;
        transition: background var(--transition);
    }
    .tnav-admin-item:hover {
        background: var(--surface-soft);
        color: var(--ink-900);
        text-decoration: none;
    }
    .tnav-admin-item.active {
        background: var(--accent-50);
        color: var(--accent-700);
    }
    .tnav-admin-item svg { width: 14px; height: 14px; color: currentColor; flex-shrink: 0; opacity: .9; }
    .tnav-admin-sep { height: 1px; background: var(--ink-100); margin: 6px -6px; }
    .tnav-admin-label {
        padding: 8px 10px 4px;
        font-size: 10px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .10em;
        color: var(--ink-400);
    }

    /* ── Search chip — Jetbuilt uses a persistent ⌘K trigger ─────── */
    .tnav-search {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        margin: 0 10px 0 auto;
        padding: 6px 10px;
        background: var(--surface-soft);
        border: 1px solid var(--ink-200);
        border-radius: var(--radius-sm);
        color: var(--ink-500);
        font-size: 12px;
        cursor: text;
        min-width: 180px;
        transition: border-color var(--transition), background var(--transition);
        flex-shrink: 0;
    }
    .tnav-search:hover { border-color: var(--ink-300); background: var(--surface); }
    .tnav-search svg { width: 12px; height: 12px; color: var(--ink-400); flex-shrink: 0; }
    .tnav-search-label { color: var(--ink-500); flex: 1; }
    .tnav-search-kbd {
        padding: 1px 6px;
        border-radius: 4px;
        background: var(--surface);
        border: 1px solid var(--ink-200);
        color: var(--ink-500);
        font-family: var(--font-mono);
        font-size: 10px;
        font-weight: 500;
    }

    @media (max-width: 1100px) {
        .tnav-search { min-width: 40px; padding: 6px 8px; }
        .tnav-search-label, .tnav-search-kbd { display: none; }
    }

    /* Running-jobs chip — Jetbuilt-clean amber pill with a pulsing dot.
       Sits between the admin dropdown and the search chip; only rendered
       for admins when count > 0. */
    .tnav-running {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 5px 10px;
        margin-right: 6px;
        background: var(--warning-light);
        color: #92400E;
        border: 1px solid color-mix(in oklab, var(--warning) 30%, transparent);
        border-radius: 999px;
        font-size: 12px;
        font-weight: 500;
        text-decoration: none;
        transition: background var(--transition), border-color var(--transition);
        flex-shrink: 0;
    }
    .tnav-running:hover {
        background: color-mix(in oklab, var(--warning) 20%, var(--surface));
        border-color: color-mix(in oklab, var(--warning) 45%, transparent);
        color: #78350F;
        text-decoration: none;
    }
    .tnav-running__pulse {
        width: 7px; height: 7px;
        border-radius: 50%;
        background: var(--warning);
        box-shadow: 0 0 0 3px color-mix(in oklab, var(--warning) 22%, transparent);
        animation: tnavPulse 1.6s ease-in-out infinite;
    }
    .tnav-running__count { font-weight: 600; font-variant-numeric: tabular-nums; }
    .tnav-running__label { color: currentColor; opacity: .82; }

    @media (max-width: 1100px) {
        .tnav-running__label { display: none; }
    }

    @keyframes tnavPulse {
        0%, 100% { opacity: 1;   transform: scale(1); }
        50%      { opacity: .55; transform: scale(.78); }
    }

    @media (max-width: 768px) {
        .tnav-brand { padding: 0 12px 0 16px; }
        .tnav-brand-name { display: none; }
        .tnav-link { padding: 0 10px; font-size: 12px; }
        .tnav-link .tnav-icon { display: none; }
    }
</style>

@php
    $isAdmin = auth()->user()?->isAdmin();
    /*
     * Route-active detection — the sidebar used a per-item routeIs() call
     * which stays correct here but the admin-dropdown needs to know if
     * ANY admin route is active so the "Admin ▾" button can highlight.
     */
    $adminActive = request()->routeIs('admin.*')
        || request()->routeIs('hazard-templates.*')
        || request()->routeIs('rams.settings*')
        || request()->routeIs('design.gallery');
@endphp

{{-- Brand — lockup lives on the far left. --}}
<a href="{{ route('dashboard') }}" class="tnav-brand" aria-label="RAMS home">
    <div class="tnav-brand-mark" aria-hidden="true">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 2 L22 7 L22 17 L12 22 L2 17 L2 7 Z"/>
        </svg>
    </div>
    <span class="tnav-brand-name">RAMS</span>
</a>

{{-- Primary horizontal nav — main workspace sections. --}}
<div class="tnav-primary" aria-label="Main navigation">
    @if ($isAdmin)
    <a href="{{ route('dashboard') }}"
       class="tnav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}">
        <svg class="tnav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
            <rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/>
        </svg>
        Dashboard
    </a>
    @endif

    <a href="{{ route('projects.index') }}"
       class="tnav-link {{ request()->routeIs('projects.*') ? 'active' : '' }}">
        <svg class="tnav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
        </svg>
        Projects
    </a>

    @if ($isAdmin)
        <a href="{{ route('rams.index') }}"
           class="tnav-link {{ request()->routeIs('rams.*') && ! request()->routeIs('rams.upload*') && ! request()->routeIs('rams.settings*') ? 'active' : '' }}">
            <svg class="tnav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                <polyline points="14 2 14 8 20 8"/>
                <line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>
            </svg>
            RAMS
        </a>

        <a href="{{ route('site-surveys.index') }}"
           class="tnav-link {{ request()->routeIs('site-surveys.*') ? 'active' : '' }}">
            <svg class="tnav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                <polyline points="9 22 9 12 15 12 15 22"/>
            </svg>
            Surveys
        </a>

        <a href="{{ route('om-manuals.index') }}"
           class="tnav-link {{ request()->routeIs('om-manuals.*') ? 'active' : '' }}"
           title="Operations &amp; Maintenance Manuals">
            <svg class="tnav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/>
                <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>
            </svg>
            O&amp;M
        </a>

        <a href="{{ route('cable-schedules.index') }}"
           class="tnav-link {{ request()->routeIs('cable-schedules.*') ? 'active' : '' }}">
            <svg class="tnav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <line x1="8" y1="6"  x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/>
                <line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6"  x2="3.01" y2="6"/>
                <line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>
            </svg>
            Cables
        </a>

        <a href="{{ route('quote-import.create') }}"
           class="tnav-link {{ request()->routeIs('quote-import.*') ? 'active' : '' }}"
           title="Import a QuoteWerks PDF to generate a project package">
            <svg class="tnav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <polyline points="16 16 12 12 8 16"/>
                <line x1="12" y1="12" x2="12" y2="21"/>
                <path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/>
            </svg>
            Import
        </a>
    @endif

    {{-- Admin dropdown — collapses configuration + tooling into a
         single overflow menu so the main row stays scan-tight. --}}
    @if ($isAdmin)
    <div class="tnav-admin"
         x-data="{ open: false }"
         :data-open="open ? '1' : '0'"
         x-on:click.outside="open = false">
        <button type="button"
                class="tnav-admin-btn {{ $adminActive ? 'active' : '' }}"
                x-on:click="open = !open"
                :aria-expanded="open">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="12" r="3"/>
                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h.01a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v.01a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
            </svg>
            Admin
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <polyline points="6 9 12 15 18 9"/>
            </svg>
        </button>
        <div class="tnav-admin-menu" role="menu">
            <div class="tnav-admin-label">Library</div>
            <a href="{{ route('hazard-templates.index') }}"
               class="tnav-admin-item {{ request()->routeIs('hazard-templates.*') ? 'active' : '' }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                    <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                </svg>
                Hazard Templates
            </a>
            <a href="{{ route('admin.solution-types.index') }}"
               class="tnav-admin-item {{ request()->routeIs('admin.solution-types.*') ? 'active' : '' }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
                    <rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>
                </svg>
                Solution Types
            </a>

            <div class="tnav-admin-sep"></div>

            <div class="tnav-admin-label">Users &amp; System</div>
            <a href="{{ route('admin.users.index') }}"
               class="tnav-admin-item {{ request()->routeIs('admin.users.*') ? 'active' : '' }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                    <circle cx="9" cy="7" r="4"/>
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                </svg>
                Users
            </a>
            <a href="{{ route('admin.worker.index') }}"
               class="tnav-admin-item {{ request()->routeIs('admin.worker*') ? 'active' : '' }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/>
                    <line x1="12" y1="17" x2="12" y2="21"/>
                </svg>
                Worker Monitor
            </a>

            <div class="tnav-admin-sep"></div>

            <div class="tnav-admin-label">AI</div>
            <a href="{{ route('rams.settings') }}"
               class="tnav-admin-item {{ request()->routeIs('rams.settings*') ? 'active' : '' }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="3"/>
                    <path d="M19.07 4.93A10 10 0 1 0 4.93 19.07"/><path d="M19.07 4.93L12 12"/>
                </svg>
                AI Settings
            </a>
            <a href="{{ route('admin.ai-usage.index') }}"
               class="tnav-admin-item {{ request()->routeIs('admin.ai-usage.*') ? 'active' : '' }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <polyline points="3 6 9 12 13 8 21 16"/>
                    <circle cx="9" cy="12" r="1"/><circle cx="13" cy="8" r="1"/><circle cx="21" cy="16" r="1"/>
                </svg>
                AI Usage
            </a>

            <div class="tnav-admin-sep"></div>

            <a href="{{ route('design.gallery') }}"
               class="tnav-admin-item {{ request()->routeIs('design.gallery') ? 'active' : '' }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="13.5" cy="6.5" r="0.5"/><circle cx="17.5" cy="10.5" r="0.5"/>
                    <circle cx="8.5" cy="7.5" r="0.5"/><circle cx="6.5" cy="12.5" r="0.5"/>
                    <path d="M12 2a10 10 0 1 0 10 10c0-5-3-6-5-6h-3a4 4 0 0 1 0-8h.5"/>
                </svg>
                Design System
            </a>
        </div>
    </div>
    @endif
</div>

{{-- Batch 11 UX-06 — global running-indicator.
     Admin-only chip that shows how many RAMS / O&M / worksheet jobs are
     currently processing across the whole workspace. Clicking jumps to
     the Worker Monitor. Query is a small handful of indexed COUNT()s
     — cheap enough to run on every page render. When there's nothing
     running, the chip stays hidden so the nav row doesn't gain a
     permanent status pip. --}}
@if ($isAdmin)
    @php
        // Re-audit S-05 — was two inline COUNT() queries on every admin
        // page render. WorkerStatusService caches for 15s so a burst of
        // page navigations shares one query and a wedged queue can't
        // amplify DB load across the nav.
        $running = app(\App\Services\WorkerStatusService::class)->runningCounts();
        $ramsRunning = $running['rams'];
        $omRunning   = $running['om'];
        $runningJobs = $running['total'];
    @endphp
    @if ($runningJobs > 0)
        <a href="{{ route('admin.worker.index') }}"
           class="tnav-running"
           aria-label="{{ $runningJobs }} job{{ $runningJobs === 1 ? '' : 's' }} currently running — view Worker Monitor"
           title="{{ $ramsRunning }} RAMS + {{ $omRunning }} O&amp;M generating">
            <span class="tnav-running__pulse" aria-hidden="true"></span>
            <span class="tnav-running__count">{{ $runningJobs }}</span>
            <span class="tnav-running__label">running</span>
        </a>
    @endif
@endif

{{-- Search chip — Jetbuilt keeps ⌘K permanent in the top bar. --}}
<div class="tnav-search"
     role="button" tabindex="0"
     aria-label="Global search (Ctrl+K)"
     x-data
     x-on:click="$dispatch('open-global-search')"
     x-on:keydown.enter.prevent="$dispatch('open-global-search')"
     x-on:keydown.space.prevent="$dispatch('open-global-search')">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>
    </svg>
    <span class="tnav-search-label">Search…</span>
    <span class="tnav-search-kbd">⌘K</span>
</div>
@endauth
