<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Operations Platform') — 21st Century AV</title>

    {{-- Modern dashboard typography: Inter (sans-only). --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">

    <style>
        /* ═══════════════════════════════════════════════════════════════
           DESIGN TOKENS — Modern dashboard (matches latest reference)
           Solid dark teal sidebar + cool white surfaces + green CTAs
        ═══════════════════════════════════════════════════════════════ */
        @import url('https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,700&family=Inter:wght@400;500;600;700&display=swap');
        :root {
            /* Ink scale — warmer (SCC v2 alignment) */
            --ink-900:        #0F1418;
            --ink-700:        #2A3138;
            --ink-500:        #6A7280;
            --ink-300:        #C5CCD3;
            --ink-200:        #DDE2E7;
            --ink-100:        #EDEFF2;

            /* Surfaces — cream paper (SCC v2) */
            --paper:          #F7F6F2;
            --paper-2:        #F2EEE6;

            /* Teal — deeper (SCC v2) */
            --teal-900:       #073A41;
            --teal-700:       #0F5963;
            --teal-500:       #1A7984;
            --teal-100:       #DAEAEA;

            /* Green (kept as success-side option for legacy uses) */
            --green-600:      #1F7A3D;
            --green-500:      #22C55E;
            --green-100:      #DCFCE7;

            /* Signal — slightly muted to fit warm palette */
            --signal-success: #1F7A3D;
            --signal-warning: #B5841A;
            --signal-danger:  #B33A2C;

            /* Gold — accent (SCC v2 brand mark) */
            --gold-500:       #C8A65A;
            --gold-light:     #ECDFB8;
            --gold-deep:      #9F7E3D;

            /* Sidebar — dark teal gradient (SCC v2) */
            --sidebar-bg:     #163C3D;
            --sidebar-bg-2:   #0E2C2D;
            --sidebar-fg:     #B8CCC9;
            --sidebar-fg-mute:#6B8584;
            --sidebar-active-bg: rgba(255,255,255,.08);
            --sidebar-active-fg: #FFFFFF;
            --sidebar-width:  220px;
            --header-height:  64px;

            /* Page width — fixed max for predictable layout */
            --page-max:       1440px;

            /* ── Aliases used by existing RAMS components ── */
            --teal:           var(--teal-700);
            --teal-hover:     #0A4951;
            --teal-active:    #073A41;
            --teal-light:     var(--teal-100);
            --teal-mid:       rgba(15,89,99,.30);
            --teal-deep:      var(--teal-900);
            --bg:             var(--paper);
            --bg-deep:        var(--paper-2);
            --surface:        #FFFFFF;
            --surface-soft:   #FAFAF7;
            --surface-deep:   var(--ink-100);
            --border:         var(--ink-200);
            --border-strong:  var(--ink-300);
            --rule:           var(--ink-100);
            --text:           var(--ink-900);
            --text-muted:     var(--ink-500);
            --text-faint:     var(--ink-300);
            --text-on-dark:   #FFFFFF;
            --danger:         var(--signal-danger);
            --danger-light:   #F7E2DD;
            --success:        var(--signal-success);
            --success-light:  rgba(31,122,61,.10);
            --warning:        var(--signal-warning);
            --warning-light:  #FBEFD7;
            /* Gold alias kept for legacy uses */
            --gold:           var(--gold-500);

            /* Typography — Fraunces display + Inter body (SCC v2) */
            --font-body:      'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif;
            --font-sans:      var(--font-body);
            --font-display:   'Fraunces', Georgia, serif;
            --font-mono:      ui-monospace, 'SF Mono', Menlo, Consolas, monospace;

            /* Shape */
            --radius-sm:      6px;
            --radius:         10px;
            --radius-lg:      14px;
            --radius-xl:      18px;

            /* Shadows */
            --shadow-card:    0 1px 2px rgba(15,23,42,.04), 0 4px 10px rgba(15,23,42,.04);
            --shadow-pop:     0 6px 20px rgba(15,23,42,.10), 0 0 0 1px rgba(15,23,42,.04);
            --shadow-xs:      0 1px 2px rgba(15,23,42,.04);
            --shadow-sm:      var(--shadow-card);
            --shadow-md:      var(--shadow-pop);
            --shadow-lg:      0 12px 28px rgba(15,23,42,.12);

            /* Motion */
            --transition:     150ms ease;
            --transition-slow: 250ms ease;
        }

        /* ═══════════════════════════════════════════════════════════════
           RESET & BASE
        ═══════════════════════════════════════════════════════════════ */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { font-size: 16px; -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale; }
        body {
            font-family: var(--font-body);
            font-size: 14px;
            line-height: 1.55;
            color: var(--ink-900);
            background: var(--paper);
            min-height: 100vh;
        }
        a           { color: var(--teal); text-decoration: none; transition: color var(--transition); }
        a:hover     { color: var(--teal-hover); text-decoration: none; }
        img, svg    { display: block; }
        button      { font-family: inherit; cursor: pointer; }
        input, select, textarea { font-family: inherit; }

        ::selection { background: var(--teal-light); color: var(--teal-deep); }

        /* ═══════════════════════════════════════════════════════════════
           TYPOGRAPHIC UTILITIES
        ═══════════════════════════════════════════════════════════════ */
        .font-display     { font-family: var(--font-display); }
        .font-mono        { font-family: var(--font-mono); font-feature-settings: "liga" 0; }
        .num-tabular      { font-variant-numeric: tabular-nums; }
        .text-eyebrow {
            font-family: var(--font-mono);
            font-size: .6875rem;
            font-weight: 500;
            letter-spacing: .14em;
            text-transform: uppercase;
            color: var(--text-muted);
        }
        .rule-gold { display: none; } /* legacy — no longer used */

        /* ═══════════════════════════════════════════════════════════════
           HEADER  — translucent paper with hairline rule
        ═══════════════════════════════════════════════════════════════ */
        .app-header {
            position: fixed;
            top: 0; left: 0; right: 0;
            height: var(--header-height);
            background: rgba(255,254,252,.86);
            backdrop-filter: saturate(140%) blur(14px);
            -webkit-backdrop-filter: saturate(140%) blur(14px);
            border-bottom: 1px solid var(--border);
            z-index: 200;
            display: flex;
            align-items: center;
            padding: 0 1.25rem 0 0;
            position: fixed;
        }
        /* Header: clean white, no gradient */

        /* Logo panel — solid teal sidebar, green tile mark */
        .header-logo-panel {
            width: var(--sidebar-width);
            flex-shrink: 0;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 0 18px;
            height: 100%;
            background: var(--sidebar-bg);
            color: #fff;
        }
        .header-logo-mark {
            width: 36px; height: 36px;
            background: var(--gold-500);
            color: var(--sidebar-bg);
            border-radius: 8px;
            display: grid;
            place-items: center;
            flex-shrink: 0;
            font-family: var(--font-display);
            font-weight: 700;
            font-size: 17px;
            box-shadow: 0 1px 3px rgba(200,166,90,.35);
        }
        .header-logo-mark svg { color: var(--sidebar-bg); }
        .header-logo-name {
            font-family: var(--font-display);
            font-size: 18px;
            font-weight: 600;
            color: #fff;
            letter-spacing: -.01em;
            line-height: 1.05;
        }
        .header-logo-name span { display: none; }

        /* Mobile hamburger */
        .header-hamburger {
            display: none;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            border: none;
            background: transparent;
            color: var(--text-muted);
            border-radius: var(--radius-sm);
            margin-left: .75rem;
            flex-shrink: 0;
            transition: background var(--transition), color var(--transition);
        }
        .header-hamburger:hover { background: var(--teal-light); color: var(--teal); }

        /* Page title in header */
        .header-platform {
            flex: 1;
            padding: 0 1.25rem;
            font-size: .9375rem;
            font-weight: 600;
            color: var(--text);
            letter-spacing: -.01em;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* User menu */
        .header-right { display: flex; align-items: center; padding-right: .25rem; }
        .user-menu    { position: relative; }
        .user-btn {
            display: flex;
            align-items: center;
            gap: .5rem;
            background: transparent;
            border: 1px solid transparent;
            border-radius: var(--radius);
            padding: .35rem .6rem .35rem .4rem;
            color: var(--text);
            font-size: .875rem;
            font-weight: 500;
            transition: background var(--transition), border-color var(--transition);
            cursor: pointer;
        }
        .user-btn:hover { background: var(--teal-light); border-color: var(--teal-mid); }
        .user-avatar {
            width: 30px; height: 30px;
            border-radius: 50%;
            background: var(--teal);
            color: #fff;
            font-size: .75rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .user-btn-name {
            max-width: 120px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .user-chevron {
            color: var(--text-faint);
            flex-shrink: 0;
            transition: transform var(--transition);
        }
        .user-btn[aria-expanded="true"] .user-chevron { transform: rotate(180deg); }

        /* Dropdown */
        .user-dropdown {
            position: absolute;
            top: calc(100% + 6px);
            right: 0;
            min-width: 200px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow-md);
            z-index: 300;
            overflow: hidden;
            animation: dropIn .12s ease;
        }
        .user-dropdown[hidden] { display: none; }
        @keyframes dropIn {
            from { opacity: 0; transform: translateY(-6px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .user-dropdown-info {
            padding: .85rem 1rem;
            border-bottom: 1px solid var(--border);
        }
        .user-dropdown-name  { font-size: .875rem; font-weight: 600; color: var(--text); }
        .user-dropdown-email { font-size: .75rem; color: var(--text-muted); margin-top: .15rem; word-break: break-all; }
        .user-dropdown-item {
            display: flex;
            align-items: center;
            gap: .5rem;
            width: 100%;
            padding: .6rem 1rem;
            font-size: .875rem;
            font-weight: 500;
            color: var(--text);
            background: transparent;
            border: none;
            text-align: left;
            cursor: pointer;
            transition: background var(--transition);
            text-decoration: none;
        }
        .user-dropdown-item:hover       { background: var(--bg); text-decoration: none; }
        .user-dropdown-item.danger      { color: var(--danger); }
        .user-dropdown-item.danger:hover { background: #FEF2F2; }

        /* ═══════════════════════════════════════════════════════════════
           SIDEBAR  — fixed, dark teal
        ═══════════════════════════════════════════════════════════════ */
        .app-sidebar {
            position: fixed;
            top: var(--header-height);
            left: 0;
            width: var(--sidebar-width);
            bottom: 0;
            background: linear-gradient(180deg, var(--sidebar-bg) 0%, var(--sidebar-bg-2) 100%);
            color: var(--sidebar-fg);
            overflow-y: auto;
            overflow-x: hidden;
            z-index: 100;
            scrollbar-width: thin;
            scrollbar-color: rgba(255,255,255,.1) transparent;
            transition: transform .25s ease;
        }
        .app-sidebar::-webkit-scrollbar       { width: 4px; }
        .app-sidebar::-webkit-scrollbar-track { background: transparent; }
        .app-sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,.1); border-radius: 2px; }
        .app-sidebar.sidebar-open             { transform: translateX(0) !important; }

        /* Mobile overlay */
        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.4);
            z-index: 99;
            backdrop-filter: blur(2px);
        }
        .sidebar-overlay.visible { display: block; }

        /* ═══════════════════════════════════════════════════════════════
           MAIN CONTENT
        ═══════════════════════════════════════════════════════════════ */
        .app-content             { padding-top: var(--header-height); min-height: 100vh; background: var(--bg); }
        .app-content.with-sidebar { margin-left: var(--sidebar-width); }

        /* ═══════════════════════════════════════════════════════════════
           PAGE WRAPPER — content fills to right of sidebar, no centering gap
           (matches SCC v2 .main-col which has no max-width).
        ═══════════════════════════════════════════════════════════════ */
        .page-wrap {
            max-width: none;
            margin: 0;
            padding: 1.75rem 2rem;
        }
        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.75rem;
            flex-wrap: wrap;
            gap: .75rem;
        }
        .page-title {
            font-family: var(--font-display);
            font-size: 32px;
            font-weight: 500;
            color: var(--ink-900);
            letter-spacing: -.02em;
            line-height: 1.1;
        }
        .page-title em {
            font-style: italic;
            font-weight: 500;
            color: var(--teal-700);
        }
        .page-subtitle {
            font-size: 13px;
            color: var(--ink-500);
            max-width: 64ch;
            margin-top: .35rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .page-subtitle::before {
            content: '📍';
            font-size: 14px;
        }

        /* ── Page header layout helpers ── */
        .page-header-left    { display: flex; flex-direction: column; gap: .25rem; min-width: 0; }
        .page-header-meta    { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
        .page-header-actions { display: flex; align-items: center; gap: .5rem; flex-shrink: 0; flex-wrap: wrap; }

        /* ═══════════════════════════════════════════════════════════════
           BREADCRUMB
        ═══════════════════════════════════════════════════════════════ */
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: .35rem;
            flex-wrap: wrap;
            font-size: .8125rem;
            color: var(--text-muted);
            margin-bottom: .35rem;
        }
        .breadcrumb a             { color: var(--text-muted); text-decoration: none; }
        .breadcrumb a:hover       { color: var(--teal); text-decoration: underline; }
        .breadcrumb__sep          { color: var(--text-faint); font-size: .75rem; }
        .breadcrumb__current      { color: var(--text); font-weight: 500; }

        /* ═══════════════════════════════════════════════════════════════
           STATUS BADGE  — soft pill, semantic colour
        ═══════════════════════════════════════════════════════════════ */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            padding: 3px 10px;
            border-radius: 999px;
            font-family: var(--font-body);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .05em;
            text-transform: uppercase;
            white-space: nowrap;
            background: var(--green-100);
            color: #166534;
        }
        .status-badge__dot {
            width: 6px; height: 6px;
            border-radius: 50%;
            flex-shrink: 0;
            background: var(--green-600);
        }

        .sb--green  { background: var(--green-100); color: #166534; }
        .sb--green  .status-badge__dot { background: var(--green-600); }
        .sb--amber  { background: var(--warning-light); color: #92400E; }
        .sb--amber  .status-badge__dot { background: var(--warning); }
        .sb--red    { background: var(--danger-light); color: #991B1B; }
        .sb--red    .status-badge__dot { background: var(--danger); }
        .sb--grey   { background: var(--ink-100); color: var(--ink-500); }
        .sb--grey   .status-badge__dot { background: var(--ink-500); }
        .sb--blue   { background: #DBEAFE; color: #1E40AF; }
        .sb--blue   .status-badge__dot { background: #2563EB; }
        .sb--purple { background: #EDE9FE; color: #5B21B6; }
        .sb--purple .status-badge__dot { background: #7C3AED; }
        .sb--cyan   { background: var(--teal-100); color: var(--teal-700); }
        .sb--cyan   .status-badge__dot { background: var(--teal-700); }
        .sb--teal   { background: var(--teal-100); color: var(--teal-700); }
        .sb--teal   .status-badge__dot { background: var(--teal-700); }

        /* Animated pulse dot */
        .sb--pulse .status-badge__dot {
            animation: sbPulse 1.8s ease-in-out infinite;
        }
        @keyframes sbPulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50%       { opacity: .5; transform: scale(.7); }
        }

        /* ═══════════════════════════════════════════════════════════════
           CARDS — v2 paper surface
        ═══════════════════════════════════════════════════════════════ */
        .card {
            background: #FFF;
            border-radius: 12px;
            border: 1px solid var(--ink-300);
            box-shadow: var(--shadow-card);
            padding: 18px;
            margin-bottom: 18px;
            /* No overflow clipping — allows row-action dropdowns to escape the card. */
        }
        .card-sm    { padding: 14px 16px; }
        .card-flush { padding: 0; }
        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 14px 18px;
            border-bottom: 1px solid var(--ink-100);
        }
        .card-title {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .10em;
            text-transform: uppercase;
            color: var(--ink-700);
        }
        .card-body  { padding: 18px; }

        /* Stat tiles (v2 KPI tile style) */
        .stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 14px;
            margin-bottom: 22px;
        }
        .stat-card {
            background: #FFF;
            border: 1px solid var(--ink-300);
            border-radius: 10px;
            box-shadow: var(--shadow-card);
            padding: 16px 18px;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .stat-label {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .10em;
            text-transform: uppercase;
            color: var(--ink-500);
        }
        .stat-value {
            font-family: var(--font-display);
            font-size: 28px;
            font-weight: 600;
            color: var(--ink-900);
            letter-spacing: -.02em;
            line-height: 1;
            font-variant-numeric: tabular-nums;
        }
        .stat-sub   { font-size: 11px; color: var(--ink-500); }
        .stat-icon  { width: 36px; height: 36px; border-radius: 8px; background: var(--teal-100); color: var(--teal-700); display: grid; place-items: center; margin-bottom: .5rem; }

        /* ── Summary card — KV grid ── */
        .kv-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem 1.5rem;
        }
        .kv-item__label {
            font-size: .7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: var(--text-muted);
            margin-bottom: .25rem;
        }
        .kv-item__value        { font-size: .9375rem; font-weight: 500; color: var(--text); }
        .kv-item__value--muted { color: var(--text-muted); font-style: italic; }

        /* ── Section card ── */
        .section-card__header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.1rem;
            padding-bottom: .6rem;
            border-bottom: 1px solid var(--border);
        }
        .section-card__title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text);
            letter-spacing: -.01em;
        }
        .section-card__actions { display: flex; align-items: center; gap: .4rem; flex-wrap: wrap; }

        /* ═══════════════════════════════════════════════════════════════
           BUTTONS — v2 style
        ═══════════════════════════════════════════════════════════════ */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 9px 16px;
            border-radius: 7px;
            border: 1px solid transparent;
            font-family: var(--font-body);
            font-weight: 600;
            font-size: 13px;
            letter-spacing: .01em;
            cursor: pointer;
            transition: background .12s, border-color .12s, color .12s;
            text-decoration: none;
            white-space: nowrap;
        }
        .btn:hover         { text-decoration: none; }
        .btn:focus-visible { outline: 2px solid var(--teal-700); outline-offset: 2px; }
        .btn:disabled      { opacity: .5; cursor: not-allowed; pointer-events: none; }

        .btn-primary, .btn-teal {
            background: var(--teal-700);
            border-color: var(--teal-700);
            color: #FFF;
        }
        .btn-primary:hover, .btn-teal:hover { background: #0A4951; border-color: #0A4951; color: #FFF; }

        .btn-secondary {
            background: #FFF;
            border-color: var(--ink-300);
            color: var(--ink-900);
        }
        .btn-secondary:hover { border-color: var(--ink-500); background: #FAFAF7; }

        /* Legacy gold variant maps to gold accent button */
        .btn-gold {
            background: var(--gold-500);
            color: var(--ink-900);
            border-color: var(--gold-500);
        }
        .btn-gold:hover { background: #B8993D; border-color: #B8993D; color: var(--ink-900); }

        .btn-outline       { background: var(--surface); color: var(--text); border-color: var(--border-strong); }
        .btn-outline:hover { background: var(--surface-soft); border-color: var(--text-muted); color: var(--text); }
        .btn-ghost         { background: transparent; color: var(--text-muted); border-color: transparent; }
        .btn-ghost:hover   { background: var(--surface-soft); color: var(--text); }

        .btn-danger            { background: var(--danger); color: #fff; border-color: var(--danger); }
        .btn-danger:hover      { background: #B91C1C; border-color: #B91C1C; color: #fff; }
        .btn-danger-outline    { background: transparent; color: var(--danger); border-color: #FECACA; }
        .btn-danger-outline:hover { background: var(--danger-light); border-color: #FCA5A5; }

        .btn-sm   { padding: .3rem .75rem; font-size: .8125rem; }
        .btn-full { width: 100%; justify-content: center; font-size: 1rem; padding: .75rem; }

        /* ═══════════════════════════════════════════════════════════════
           ALERTS  (simple inline)
        ═══════════════════════════════════════════════════════════════ */
        .alert {
            padding: .85rem 1rem;
            border-radius: var(--radius-sm);
            margin-bottom: 1.25rem;
            font-size: .875rem;
            border: 1px solid transparent;
        }
        .alert-success { background: #F0FDF4; color: #15803D; border-color: #BBF7D0; }
        .alert-error   { background: #FEF2F2; color: #B91C1C; border-color: #FECACA; }
        .alert-info    { background: var(--teal-light); color: #0C5A62; border-color: var(--teal-mid); }
        .alert-warning { background: #FFFBEB; color: #92400E; border-color: #FDE68A; }

        /* ── Alert banner  (richer, dismissible) ── */
        .alert-banner {
            display: flex;
            align-items: flex-start;
            gap: .75rem;
            padding: .85rem 1rem;
            border-radius: var(--radius-sm);
            border: 1px solid transparent;
            margin-bottom: 1.25rem;
            font-size: .875rem;
        }
        .alert-banner__icon    { flex-shrink: 0; margin-top: .05rem; }
        .alert-banner__body    { flex: 1; min-width: 0; }
        .alert-banner__dismiss {
            background: transparent;
            border: none;
            cursor: pointer;
            color: inherit;
            opacity: .6;
            padding: .1rem;
            flex-shrink: 0;
            border-radius: 4px;
            transition: opacity var(--transition);
        }
        .alert-banner__dismiss:hover { opacity: 1; }

        .alert-banner--success { background: #F0FDF4; color: #15803D; border-color: #BBF7D0; }
        .alert-banner--error   { background: #FEF2F2; color: #B91C1C; border-color: #FECACA; }
        .alert-banner--info    { background: var(--teal-light); color: #0C5A62; border-color: var(--teal-mid); }
        .alert-banner--warning { background: #FFFBEB; color: #92400E; border-color: #FDE68A; }

        /* ═══════════════════════════════════════════════════════════════
           FORMS
        ═══════════════════════════════════════════════════════════════ */
        .form-group { margin-bottom: 1.1rem; }
        .form-label {
            display: block;
            font-size: .875rem;
            font-weight: 600;
            color: var(--text);
            margin-bottom: .35rem;
        }
        .form-label .req { color: var(--danger); margin-left: .2rem; }
        .form-control {
            width: 100%;
            padding: .5rem .75rem;
            border: 1px solid #D1D5DB;
            border-radius: var(--radius-sm);
            font-size: .9rem;
            font-family: inherit;
            color: var(--text);
            background: var(--surface);
            transition: border-color var(--transition), box-shadow var(--transition);
            appearance: none;
        }
        .form-control::placeholder { color: var(--text-faint); }
        .form-control:hover  { border-color: #9CA3AF; }
        .form-control:focus  { outline: none; border-color: var(--teal); box-shadow: 0 0 0 3px rgba(23,138,149,.15); }
        .form-control.is-invalid { border-color: var(--danger); box-shadow: 0 0 0 3px rgba(220,38,38,.1); }
        select.form-control  { background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236B7280' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right .75rem center; padding-right: 2.25rem; }
        textarea.form-control { resize: vertical; min-height: 90px; }
        .form-help { font-size: .8rem; color: var(--text-muted); margin-top: .3rem; }
        .invalid-feedback { color: var(--danger); font-size: .8125rem; margin-top: .3rem; }

        /* ═══════════════════════════════════════════════════════════════
           CHECKBOX GRIDS
        ═══════════════════════════════════════════════════════════════ */
        .check-grid   { display: grid; gap: .5rem .75rem; }
        .check-grid-3 { grid-template-columns: repeat(3, 1fr); }
        .check-grid-2 { grid-template-columns: repeat(2, 1fr); }
        .check-grid-5 { grid-template-columns: repeat(5, 1fr); }
        .check-item {
            display: flex;
            align-items: center;
            gap: .45rem;
            padding: .45rem .65rem;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: border-color var(--transition), background var(--transition);
            font-size: .875rem;
            background: var(--surface);
            user-select: none;
        }
        .check-item:hover                          { border-color: var(--teal); background: var(--teal-light); }
        .check-item input[type=checkbox]:checked + span { color: var(--teal); font-weight: 600; }
        .check-item:has(input:checked)             { border-color: var(--teal); background: var(--teal-light); }
        .check-item input[type=checkbox]           { accent-color: var(--teal); width: 15px; height: 15px; flex-shrink: 0; }

        /* ═══════════════════════════════════════════════════════════════
           SECTION HEADINGS — uppercase tracked teal (SCC v2 .card-head-title)
        ═══════════════════════════════════════════════════════════════ */
        .section-heading {
            font-size: .75rem;
            font-weight: 700;
            color: var(--teal-700);
            letter-spacing: .10em;
            text-transform: uppercase;
            padding-bottom: .55rem;
            margin-bottom: 1rem;
            border-bottom: 1px solid var(--ink-100);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .section-heading::before {
            content: '';
            display: inline-block;
            width: 4px;
            height: 14px;
            background: var(--teal-700);
            border-radius: 2px;
        }
        /* Larger panel-style heading for project sub-sections (Project Summary etc.) */
        .section-heading--panel {
            font-size: 1rem;
            text-transform: none;
            letter-spacing: -.01em;
            color: var(--ink-900);
            font-family: var(--font-display);
            font-weight: 600;
            padding-bottom: .65rem;
            margin-bottom: 1rem;
            border-bottom: 1px solid var(--ink-100);
        }
        .section-heading--panel::before {
            background: var(--teal-700);
            width: 3px;
            height: 18px;
        }
        .section-block {
            background: var(--surface);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            box-shadow: var(--shadow-xs);
            padding: 1.5rem;
            margin-bottom: 1.25rem;
        }

        /* ═══════════════════════════════════════════════════════════════
           FORM SECTIONS — card wrapper for logical blocks of inputs
           Pairs with the existing .section-heading (teal accent strip)
        ═══════════════════════════════════════════════════════════════ */
        .form-section {
            background: var(--surface);
            border: 1px solid var(--ink-300);
            border-left: 3px solid var(--teal-700);
            border-radius: var(--radius);
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
            overflow: hidden; /* keeps the cream header tint inside the radius */
        }
        .form-section__header {
            background: linear-gradient(180deg, #EFEBDF 0%, #E5DFCE 100%);
            border-bottom: 1px solid var(--ink-200);
            padding: .9rem 1.25rem;
        }
        /* When a .section-heading sits inside .form-section__header, drop its
           trailing border + bottom margin (the header bar already provides them). */
        .form-section__header .section-heading {
            margin: 0;
            padding-bottom: 0;
            border-bottom: 0;
        }
        .form-section__body {
            padding: 1.25rem 1.5rem 1rem;
        }
        @media (max-width: 768px) {
            .form-section__header { padding: .7rem 1rem; }
            .form-section__body   { padding: 1rem 1rem .5rem; }
        }

        /* ═══════════════════════════════════════════════════════════════
           EDIT ACTION BAR — sticky Save/Cancel under the app header.
           Used on every editable form page via <x-edit-action-bar/>.
           Negative margins counter .page-wrap padding for full-bleed.
        ═══════════════════════════════════════════════════════════════ */
        .edit-action-bar {
            position: sticky;
            top: var(--header-height);
            z-index: 90;
            background: var(--surface);
            border-bottom: 1px solid var(--ink-200);
            box-shadow: 0 2px 6px rgba(15, 23, 42, .04);
            padding: .65rem 1.5rem;
            margin: -1.75rem -2rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .edit-action-bar__title {
            font-family: var(--font-display);
            font-size: 1.05rem;
            font-weight: 600;
            color: var(--ink-900);
            flex: 1 1 auto;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .edit-action-bar__actions {
            display: flex;
            gap: .5rem;
            flex-shrink: 0;
        }

        /* ═══════════════════════════════════════════════════════════════
           ROOM SUB-SECTION — lightweight inline divider for grouping
           fields inside an already-bordered card (e.g. .room-card). Avoids
           nested .form-section chrome that produces 3-deep visual stacks.
        ═══════════════════════════════════════════════════════════════ */
        .room-subsection {
            margin-top: 1.25rem;
            padding-top: 1rem;
            border-top: 1px solid var(--rule, var(--ink-200));
        }
        .room-subsection:first-child { margin-top: 0; padding-top: 0; border-top: 0; }
        .room-subsection__heading {
            font-size: .72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: var(--teal-700);
            margin: 0 0 .6rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .room-subsection__heading::before {
            content: '';
            width: 3px;
            height: 12px;
            background: var(--teal-700);
        }

        /* ═══════════════════════════════════════════════════════════════
           EMPTY-FIELD HIGHLIGHT — pure CSS, opt-out via [data-optional]
           or .is-optional. Fires on ANY empty input/textarea/select unless
           explicitly opted out. Clears instantly when the user types.
        ═══════════════════════════════════════════════════════════════ */
        /* Text-style inputs and textareas — :placeholder-shown is true when
           the input has a placeholder attribute and no user-typed value.
           Inputs missing a placeholder get one (single space) in markup. */
        .form-control:placeholder-shown:not([data-optional]):not(.is-optional):not(:focus):not([readonly]):not([disabled]) {
            background-color: #FDF1EE;
            border-color: #E8B7AE;
        }
        /* Selects — :invalid fires when no option is selected OR the
           selected option has value="". Project convention: the first
           "Choose…" option uses value="". Falls back to required:invalid
           when the field is HTML-required. */
        select.form-control:not([data-optional]):not(.is-optional):not(:focus):invalid {
            background-color: #FDF1EE;
            border-color: #E8B7AE;
        }
        /* Pure-CSS empty detection for selects without `required`: target
           the implicit empty value via :has(option[value=""]:checked).
           Modern Chrome/Safari/Firefox 121+ support :has. */
        select.form-control:not([data-optional]):not(.is-optional):not(:focus):has(option[value=""]:checked) {
            background-color: #FDF1EE;
            border-color: #E8B7AE;
        }
        /* Project rule: server-side .is-invalid (validation failures from
           Laravel) must always win — keep the existing red ring at full
           strength even on otherwise-empty inputs. */
        .form-control.is-invalid {
            background-color: #FDF1EE;
            border-color: var(--danger);
        }

        /* ═══════════════════════════════════════════════════════════════
           TABLES
        ═══════════════════════════════════════════════════════════════ */
        .data-table { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .data-table th {
            background: var(--surface-soft);
            color: var(--text-muted);
            padding: .65rem 1rem;
            text-align: left;
            font-weight: 600;
            font-size: .7rem;
            text-transform: uppercase;
            letter-spacing: .06em;
            border-bottom: 1px solid var(--border);
        }
        .data-table td {
            padding: .75rem 1rem;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
            color: var(--text);
        }
        .data-table tbody tr:last-child td { border-bottom: none; }
        .data-table tbody tr                { transition: background var(--transition); }
        .data-table tbody tr:hover          { background: var(--surface-soft); }
        .data-table .actions                { display: flex; gap: .4rem; align-items: center; flex-wrap: wrap; }

        /* ═══════════════════════════════════════════════════════════════
           TEAM ROW / FORM GRID
        ═══════════════════════════════════════════════════════════════ */
        .team-row {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr auto;
            gap: .6rem;
            align-items: center;
            margin-bottom: .6rem;
        }
        .team-row select.form-control { height: 37px; }
        .form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }

        /* ═══════════════════════════════════════════════════════════════
           BADGES  (pill)
        ═══════════════════════════════════════════════════════════════ */
        .badge {
            display: inline-flex;
            align-items: center;
            padding: .2rem .6rem;
            border-radius: 9999px;
            font-size: .6875rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .05em;
        }
        .badge-teal  { background: var(--teal-light); color: var(--teal); }
        .badge-grey  { background: #F3F4F6; color: #4B5563; }
        .badge-red   { background: #FEF2F2; color: #B91C1C; }
        .badge-green { background: #F0FDF4; color: #15803D; }

        /* ═══════════════════════════════════════════════════════════════
           PAGINATION
        ═══════════════════════════════════════════════════════════════ */
        .pagination-wrap { margin-top: 1.25rem; display: flex; justify-content: center; }
        .pag-block       { display: flex; flex-direction: column; align-items: flex-end; gap: .5rem; }
        .pag-meta        { font-size: .8125rem; color: var(--text-muted); margin: 0; }
        .pag-meta strong { color: var(--text); font-weight: 600; }
        .pagination-wrap nav { display: flex; gap: .3rem; align-items: center; flex-wrap: wrap; }
        .pagination-wrap nav a,
        .pagination-wrap nav span {
            padding: .4rem .75rem;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: .875rem;
            color: var(--text);
            background: var(--surface);
            transition: background var(--transition), border-color var(--transition);
            min-width: 2rem;
            text-align: center;
        }
        .pagination-wrap nav a:hover              { background: var(--bg); border-color: #D1D5DB; text-decoration: none; color: var(--text); }
        .pagination-wrap nav span[aria-current]   { background: var(--teal); color: #fff; border-color: var(--teal); }
        .pagination-wrap nav span.is-disabled     { opacity: .35; cursor: not-allowed; }
        .pagination-wrap nav span.is-separator    { border: none; background: transparent; color: var(--text-muted); padding: .4rem .25rem; }

        /* ═══════════════════════════════════════════════════════════════
           DOCUMENT-EDIT CHAT DRAWER  — shared across all doc show pages
        ═══════════════════════════════════════════════════════════════ */
        .chat-fab {
            display: inline-flex; align-items: center; gap: .35rem;
            padding: .35rem .75rem; border: 1px solid var(--teal); background: #fff; color: var(--teal);
            border-radius: var(--radius-sm); font-size: .8125rem; font-weight: 600; cursor: pointer;
            transition: background 150ms ease;
        }
        .chat-fab:hover { background: var(--teal-light); text-decoration: none; }

        .chat-drawer-backdrop {
            position: fixed; inset: 0; background: rgba(11,60,69,.35);
            z-index: 998; opacity: 0; pointer-events: none;
            transition: opacity 180ms ease;
        }
        .chat-drawer-backdrop.is-open { opacity: 1; pointer-events: auto; }

        .chat-drawer {
            position: fixed; top: 0; right: 0; bottom: 0; width: min(460px, 100vw);
            background: #fff; box-shadow: -4px 0 18px rgba(0,0,0,.12);
            display: flex; flex-direction: column; z-index: 999;
            transform: translateX(100%); transition: transform 220ms ease;
        }
        .chat-drawer.is-open { transform: translateX(0); }

        .chat-drawer-hdr {
            background: var(--teal); color: #fff;
            padding: .9rem 1rem; display: flex; align-items: center; justify-content: space-between; gap: .5rem;
        }
        .chat-drawer-hdr strong { font-size: .95rem; font-weight: 600; }
        .chat-drawer-hdr-sub    { font-size: .72rem; opacity: .85; margin-top: 2px; }
        .chat-drawer-hdr button {
            background: transparent; border: none; color: #fff; font-size: 1.4rem; line-height: 1;
            cursor: pointer; padding: 0 .25rem; opacity: .85;
        }
        .chat-drawer-hdr button:hover { opacity: 1; }

        .chat-drawer-body {
            flex: 1; overflow-y: auto; padding: 1rem; background: var(--bg);
            display: flex; flex-direction: column; gap: .6rem;
        }

        .chat-msg {
            max-width: 88%; padding: .55rem .75rem; border-radius: 12px;
            font-size: .875rem; line-height: 1.4; word-wrap: break-word;
        }
        .chat-msg--user       { align-self: flex-end;   background: var(--teal); color: #fff; border-bottom-right-radius: 4px; }
        .chat-msg--assistant  { align-self: flex-start; background: #fff; color: var(--text); border: 1px solid var(--border); border-bottom-left-radius: 4px; }
        .chat-msg--system     { align-self: center;     background: transparent; color: var(--text-muted); font-size: .78rem; font-style: italic; }
        .chat-msg--error      { align-self: center;     background: #FEF2F2; color: #B91C1C; border: 1px solid #FECACA; font-size: .8rem; }

        .chat-apply-pill {
            display: inline-flex; align-items: center; gap: .35rem;
            margin-top: .4rem; padding: .3rem .7rem;
            background: var(--teal); color: #fff; border: none; border-radius: 999px;
            font-size: .78rem; font-weight: 600; cursor: pointer;
            transition: background 150ms ease, opacity 150ms ease;
        }
        .chat-apply-pill:hover      { background: var(--teal-hover); }
        .chat-apply-pill:disabled   { opacity: .5; cursor: wait; }
        .chat-apply-pill--applied   { background: var(--success); cursor: default; }
        .chat-apply-pill--restart   { background: #B45309; }
        .chat-apply-pill--restart:hover { background: #92400E; }

        .chat-diff {
            margin-top: .45rem; padding: .4rem .55rem;
            background: var(--bg); border: 1px solid var(--border); border-radius: 6px;
            font-size: .78rem;
        }
        .chat-diff summary {
            cursor: pointer; color: var(--teal); font-weight: 600;
            list-style: none; user-select: none;
        }
        .chat-diff summary::-webkit-details-marker { display: none; }
        .chat-diff summary::after { content: " ▾"; opacity: .6; font-size: .7rem; }
        .chat-diff[open] summary::after { content: " ▴"; }
        .chat-diff-row   { display: flex; gap: .4rem; margin-top: .35rem; }
        .chat-diff-label { min-width: 150px; color: var(--text-muted); }
        .chat-diff-val   { color: var(--text); font-variant-numeric: tabular-nums; }
        .chat-diff-before{ color: #991B1B; text-decoration: line-through; margin-right: .3rem; }
        .chat-diff-after { color: #065F46; font-weight: 600; }
        .chat-diff-list  { margin: .2rem 0 0; padding-left: 1rem; color: var(--text); }
        .chat-diff-list li { margin: .1rem 0; }

        .chat-drawer-ftr {
            border-top: 1px solid var(--border); background: #fff;
            padding: .65rem .75rem; display: flex; gap: .5rem; align-items: flex-end;
        }
        .chat-drawer-ftr textarea {
            flex: 1; resize: none; min-height: 40px; max-height: 140px;
            border: 1px solid var(--border); border-radius: 8px; padding: .5rem .6rem;
            font-family: inherit; font-size: .875rem; line-height: 1.4; color: var(--text);
        }
        .chat-drawer-ftr textarea:focus {
            outline: none; border-color: var(--teal);
            box-shadow: 0 0 0 3px rgba(23,138,149,.15);
        }
        .chat-drawer-ftr button[type="submit"] {
            background: var(--teal); color: #fff; border: none; border-radius: 8px;
            padding: .5rem .9rem; font-weight: 600; cursor: pointer;
            transition: background 150ms ease, opacity 150ms ease;
        }
        .chat-drawer-ftr button[type="submit"]:hover    { background: var(--teal-hover); }
        .chat-drawer-ftr button[type="submit"]:disabled { opacity: .5; cursor: wait; }

        .chat-empty { color: var(--text-muted); font-size: .85rem; text-align: center; padding: 2rem 1rem; }
        .chat-empty p { margin: 0 0 .5rem; }
        .chat-empty code {
            font-size: .78rem; background: #fff; padding: .1rem .3rem; border-radius: 4px;
            border: 1px solid var(--border);
        }

        /* ═══════════════════════════════════════════════════════════════
           DETAILS / SUMMARY
        ═══════════════════════════════════════════════════════════════ */
        details.secondary-section { border: 1px solid var(--border); border-radius: var(--radius-sm); padding: .75rem 1rem; }
        details.secondary-section summary {
            cursor: pointer;
            font-size: .875rem;
            color: var(--text-muted);
            font-weight: 500;
            list-style: none;
            display: flex;
            align-items: center;
            gap: .4rem;
        }
        details.secondary-section summary::before         { content: '▶'; font-size: .6rem; transition: transform .2s; }
        details.secondary-section[open] summary::before   { transform: rotate(90deg); }
        details.secondary-section .details-body           { margin-top: 1rem; }

        /* ═══════════════════════════════════════════════════════════════
           EMPTY STATE
        ═══════════════════════════════════════════════════════════════ */
        .empty-state { text-align: center; padding: 4rem 1.5rem; color: var(--text-muted); }
        .empty-state-icon {
            width: 52px; height: 52px;
            border-radius: 12px;
            background: var(--teal-light);
            color: var(--teal);
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 1.25rem;
        }
        .empty-state h3 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text);
            margin-bottom: .4rem;
        }
        .empty-state p { margin-bottom: 1.5rem; font-size: .875rem; max-width: 320px; margin-left: auto; margin-right: auto; }

        /* ── Empty state v2 (component version) ── */
        .empty-state-v2 { text-align: center; padding: 4rem 1.5rem; color: var(--text-muted); }
        .empty-state-v2__icon {
            width: 52px; height: 52px;
            border-radius: 12px;
            background: var(--teal-light);
            color: var(--teal);
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 1.25rem;
        }
        .empty-state-v2__title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text);
            margin-bottom: .4rem;
        }
        .empty-state-v2__desc {
            font-size: .875rem;
            max-width: 320px;
            margin: 0 auto 1.5rem;
        }

        /* ═══════════════════════════════════════════════════════════════
           BLOCKING BANNER  — workflow gate messages
        ═══════════════════════════════════════════════════════════════ */
        .blocking-banner {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            padding: 1rem 1.25rem;
            border-radius: var(--radius);
            border: 1px solid transparent;
            margin-bottom: 1.5rem;
        }
        .blocking-banner__icon  { flex-shrink: 0; margin-top: .05rem; }
        .blocking-banner__body  { flex: 1; min-width: 0; }
        .blocking-banner__title { font-size: .9375rem; font-weight: 700; margin-bottom: .25rem; }
        .blocking-banner__desc  { font-size: .875rem; }
        .blocking-banner__cta   { margin-top: .75rem; }

        .blocking-banner--warning { background: #FFFBEB; color: #92400E; border-color: #FDE68A; }
        .blocking-banner--warning .blocking-banner__title { color: #78350F; }

        .blocking-banner--error   { background: #FEF2F2; color: #B91C1C; border-color: #FECACA; }
        .blocking-banner--error   .blocking-banner__title { color: #991B1B; }

        .blocking-banner--info    { background: var(--teal-light); color: #0C5A62; border-color: var(--teal-mid); }
        .blocking-banner--info    .blocking-banner__title { color: #0A4A52; }

        /* ═══════════════════════════════════════════════════════════════
           MOBILE TAB BAR  — bottom nav, mobile only
        ═══════════════════════════════════════════════════════════════ */
        .mobile-tab-bar {
            display: none;
            position: fixed;
            bottom: 0; left: 0; right: 0;
            background: var(--surface);
            border-top: 1px solid var(--border);
            z-index: 150;
            padding-bottom: env(safe-area-inset-bottom, 0);
        }
        .mobile-tab-bar__inner {
            display: flex;
            align-items: stretch;
        }
        .mobile-tab-bar__item {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: .2rem;
            padding: .55rem .25rem;
            font-size: .65rem;
            font-weight: 500;
            color: var(--text-muted);
            text-decoration: none;
            border-top: 2px solid transparent;
            transition: color var(--transition), border-color var(--transition), background var(--transition);
        }
        .mobile-tab-bar__item:hover       { color: var(--teal); text-decoration: none; background: var(--teal-light); }
        .mobile-tab-bar__item.active,
        .mobile-tab-bar__item[aria-current="page"] {
            color: var(--teal);
            border-top-color: var(--teal);
        }

        /* ═══════════════════════════════════════════════════════════════
           RESPONSIVE
        ═══════════════════════════════════════════════════════════════ */
        @media (max-width: 1024px) {
            .app-sidebar { transform: translateX(calc(-1 * var(--sidebar-width))); }
            .app-content.with-sidebar { margin-left: 0; }
            .header-hamburger { display: flex; }
            .header-logo-panel { border-right: none; }
        }
        @media (max-width: 768px) {
            .page-wrap    { padding: 1.25rem 1rem; }
            .card         { padding: 1.25rem; }
            .check-grid-3 { grid-template-columns: repeat(2, 1fr); }
            .check-grid-5 { grid-template-columns: repeat(2, 1fr); }
            .form-grid-2  { grid-template-columns: 1fr; }
            .team-row     { grid-template-columns: 1fr 1fr; }
            .page-header  { flex-direction: column; align-items: flex-start; }
            .stat-grid    { grid-template-columns: repeat(2, 1fr); }
            .user-btn-name { display: none; }
            .kv-grid      { grid-template-columns: repeat(2, 1fr); }
            .mobile-tab-bar { display: block; }
            .app-content  { padding-bottom: calc(60px + env(safe-area-inset-bottom, 0)); }
            .page-header-actions { width: 100%; }
            .edit-action-bar         { padding: .55rem .9rem; margin: -1.25rem -1rem 1rem; }
            .edit-action-bar__title  { display: none; }
        }
        @media (max-width: 480px) {
            .stat-grid { grid-template-columns: 1fr; }
            .kv-grid   { grid-template-columns: 1fr; }
        }
    </style>
    @stack('styles')
</head>
<body>

    {{-- ── HEADER ──────────────────────────────────────────────────────── --}}
    <header class="app-header">
        <div class="header-logo-panel">
            <div class="header-logo-mark" aria-hidden="true">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2.4" stroke-linecap="round"
                     stroke-linejoin="round">
                    <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 1 1 18 0z"/>
                    <circle cx="12" cy="10" r="3"/>
                </svg>
            </div>
            <div class="header-logo-name">
                RAMS
                <span>Operations Platform</span>
            </div>
        </div>

        @auth
        <button class="header-hamburger" id="sidebarToggle" aria-label="Toggle sidebar">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round"
                 stroke-linejoin="round" aria-hidden="true">
                <line x1="3" y1="6"  x2="21" y2="6"/>
                <line x1="3" y1="12" x2="21" y2="12"/>
                <line x1="3" y1="18" x2="21" y2="18"/>
            </svg>
        </button>
        @endauth

        <div class="header-platform">@yield('title', 'Operations Dashboard')</div>

        @auth
        <div class="header-right">
            <div class="user-menu" id="userMenuContainer">
                <button class="user-btn" id="userMenuBtn" aria-haspopup="true" aria-expanded="false">
                    <div class="user-avatar" aria-hidden="true">
                        {{ strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}
                    </div>
                    <span class="user-btn-name">{{ auth()->user()->name }}</span>
                    <svg class="user-chevron" width="13" height="13" viewBox="0 0 24 24"
                         fill="none" stroke="currentColor" stroke-width="2.5"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <polyline points="6 9 12 15 18 9"/>
                    </svg>
                </button>

                <div class="user-dropdown" id="userDropdown" hidden>
                    <div class="user-dropdown-info">
                        <div class="user-dropdown-name">{{ auth()->user()->name }}</div>
                        @if(auth()->user()->email ?? false)
                        <div class="user-dropdown-email">{{ auth()->user()->email }}</div>
                        @endif
                    </div>
                    <form method="POST" action="{{ route('logout') }}" style="margin:0">
                        @csrf
                        <button type="submit" class="user-dropdown-item danger">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                 stroke-linejoin="round" aria-hidden="true">
                                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                                <polyline points="16 17 21 12 16 7"/>
                                <line x1="21" y1="12" x2="9" y2="12"/>
                            </svg>
                            Sign out
                        </button>
                    </form>
                </div>
            </div>
        </div>
        @endauth
    </header>

    {{-- ── MOBILE OVERLAY ──────────────────────────────────────────────── --}}
    @auth
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    @endauth

    {{-- ── SIDEBAR ──────────────────────────────────────────────────────── --}}
    @auth
    <aside class="app-sidebar" id="appSidebar">
        @include('layouts.navigation')
    </aside>
    @endauth

    {{-- ── MAIN CONTENT ────────────────────────────────────────────────── --}}
    <main class="app-content @auth with-sidebar @endauth">
        <div class="page-wrap">
            @hasSection('content')
                @yield('content')
            @else
                {{ $slot ?? '' }}
            @endif
        </div>
    </main>

    @auth
    <x-mobile-tab-bar />
    @endauth

    {{-- ── GLOBAL CONFIRM MODAL (SCC v2 styled) ──────────────────────────
         Replaces native window.confirm(). Activated via:
           • <form data-confirm="...">                — intercepts submit
           • <button|a data-confirm="...">            — intercepts click
           • window.appConfirm('...').then(ok=>...)   — programmatic Promise
         Optional attrs: data-confirm-title, data-confirm-label, data-confirm-danger="1"
    --}}
    <div x-data="appConfirm()"
         x-show="open"
         x-transition.opacity
         @keydown.escape.window="cancel"
         @keydown.enter.window="confirmAction"
         style="display:none; position:fixed; inset:0; background:rgba(15,20,24,.55); z-index:9999; align-items:center; justify-content:center; padding:1rem;">
        <div @click.away="cancel"
             x-transition
             style="background:#fff; border-radius:var(--radius-lg, 14px); box-shadow:var(--shadow-pop, 0 6px 20px rgba(15,23,42,.10), 0 0 0 1px rgba(15,23,42,.04)); max-width:420px; width:100%; padding:1.75rem;">
            <h3 class="font-display"
                x-text="title"
                style="font-size:1.35rem; font-weight:600; color:var(--ink-900); margin:0 0 .85rem;"></h3>
            <p x-text="message"
               style="font-size:.95rem; line-height:1.5; color:var(--ink-700); margin:0 0 1.4rem; white-space:pre-line;"></p>
            <div style="display:flex; gap:.6rem; justify-content:flex-end;">
                <button type="button" @click="cancel" class="btn btn-outline btn-sm" x-text="cancelLabel"></button>
                <button type="button" @click="confirmAction"
                        :class="danger ? 'btn btn-danger btn-sm' : 'btn btn-teal btn-sm'"
                        x-text="confirmLabel"
                        x-ref="confirmBtn"></button>
            </div>
        </div>
    </div>

    <script>
        /* ── SCC v2 styled confirm() replacement ──────────────────────────
           Alpine x-data component declared above; this script wires it up
           to capture-phase form submit + click handlers AND exposes
           window.appConfirm(message, opts) for programmatic JS use.
        ─────────────────────────────────────────────────────────────── */
        function appConfirm() {
            return {
                open: false,
                title: 'Are you sure?',
                message: '',
                confirmLabel: 'Confirm',
                cancelLabel: 'Cancel',
                danger: false,
                _resolve: null,
                _trigger: null,        // 'submit' | 'click'
                _targetEl: null,       // form or button/link

                show(opts) {
                    this.title        = opts.title || 'Are you sure?';
                    this.message      = opts.message || '';
                    this.confirmLabel = opts.confirmLabel || 'Confirm';
                    this.cancelLabel  = opts.cancelLabel || 'Cancel';
                    this.danger       = !!opts.danger;
                    this.open         = true;
                    this.$nextTick(() => this.$refs.confirmBtn && this.$refs.confirmBtn.focus());
                    return new Promise((resolve) => { this._resolve = resolve; });
                },

                cancel() {
                    if (!this.open) return;
                    this.open = false;
                    const r = this._resolve;
                    this._resolve = null;
                    this._trigger = null;
                    this._targetEl = null;
                    if (r) r(false);
                },

                confirmAction() {
                    if (!this.open) return;
                    this.open = false;
                    const r = this._resolve;
                    const trig = this._trigger;
                    const tgt = this._targetEl;
                    this._resolve = null;
                    this._trigger = null;
                    this._targetEl = null;
                    if (r) r(true);
                    // If invoked from a form/button intercept, trigger the actual action.
                    // Uses requestSubmit() with the original submit button when available
                    // — this preserves form association, hidden _method (DELETE/PUT) and
                    // formaction/formmethod attributes that plain form.submit() can drop
                    // on Safari + display:contents forms.
                    if (trig === 'submit' && tgt) {
                        tgt.removeAttribute('data-confirm');
                        const submitBtn = tgt.querySelector('button[type=submit], input[type=submit]');
                        try {
                            if (typeof tgt.requestSubmit === 'function') {
                                // requestSubmit() fires a real submit event we already
                                // intercepted once; data-confirm is now removed so the
                                // capture handler short-circuits and the browser submits
                                // natively, preserving _method spoof + CSRF.
                                tgt.requestSubmit(submitBtn || undefined);
                            } else {
                                // Older browsers: fall back to plain submit().
                                tgt.submit();
                            }
                        } catch (e) {
                            // Last resort if requestSubmit throws (very old Safari):
                            try { tgt.submit(); } catch (_) {}
                        }
                    } else if (trig === 'click' && tgt) {
                        tgt.dataset._confirmBypass = '1';
                        tgt.click();
                        delete tgt.dataset._confirmBypass;
                    }
                },
            };
        }

        // Global helper for inline JS calls (replaces window.confirm in app code)
        window.appConfirm = function(message, opts) {
            opts = opts || {};
            const root = document.querySelector('[x-data="appConfirm()"]');
            if (!root || !root._x_dataStack) {
                // Fallback to native confirm if Alpine isn't ready
                return Promise.resolve(window.confirm(message));
            }
            const cmp = root._x_dataStack[0];
            return cmp.show(Object.assign({ message: message }, opts));
        };

        // Capture-phase form submit interceptor
        document.addEventListener('submit', function(e) {
            const form = e.target;
            if (!(form instanceof HTMLFormElement)) return;
            const msg = form.getAttribute('data-confirm');
            if (!msg) return;
            e.preventDefault();
            e.stopPropagation();
            const root = document.querySelector('[x-data="appConfirm()"]');
            if (!root || !root._x_dataStack) {
                if (window.confirm(msg)) form.submit();
                return;
            }
            const cmp = root._x_dataStack[0];
            cmp._trigger = 'submit';
            cmp._targetEl = form;
            cmp.show({
                title:        form.getAttribute('data-confirm-title') || 'Are you sure?',
                message:      msg,
                confirmLabel: form.getAttribute('data-confirm-label') || 'Confirm',
                danger:       form.getAttribute('data-confirm-danger') === '1',
            });
        }, true);

        // Capture-phase click interceptor for buttons/links with data-confirm
        document.addEventListener('click', function(e) {
            const el = e.target.closest && e.target.closest('[data-confirm]');
            if (!el) return;
            if (el.dataset._confirmBypass === '1') return;
            if (el.tagName === 'FORM') return;  // forms handled by submit handler
            e.preventDefault();
            e.stopPropagation();
            const root = document.querySelector('[x-data="appConfirm()"]');
            if (!root || !root._x_dataStack) {
                if (window.confirm(el.getAttribute('data-confirm'))) el.click();
                return;
            }
            const cmp = root._x_dataStack[0];
            cmp._trigger = 'click';
            cmp._targetEl = el;
            cmp.show({
                title:        el.getAttribute('data-confirm-title') || 'Are you sure?',
                message:      el.getAttribute('data-confirm'),
                confirmLabel: el.getAttribute('data-confirm-label') || 'Confirm',
                danger:       el.getAttribute('data-confirm-danger') === '1',
            });
        }, true);
    </script>

    {{-- Phase 16 W-11 — global sign-pad bundle load (creagia/laravel-sign-pad).
         Loaded synchronously, unconditionally, BEFORE @stack('scripts') so any
         page-level pushed Alpine factory (Plan 05) can reference the canvas
         shell without a defer race. See 16-02-DPI-SPIKE-NOTES.md for why
         Plan 05 additionally loads signature_pad UMD from CDN. --}}
    <script src="{{ asset('vendor/sign-pad/sign-pad.min.js') }}"></script>
    {{-- Phase 16 Plan 05 B-06 Option C — additional szimek/signature_pad@5.1.3 UMD
         load from CDN so window.SignaturePad is a reliable global for the Alpine
         signoffSheet factory. Creagia's bundle is a webpack IIFE that does not
         expose its SignaturePad class (see 16-02-DPI-SPIKE-NOTES.md); this line
         fills that gap without forking the vendor bundle. --}}
    <script src="https://cdn.jsdelivr.net/npm/signature_pad@5.1.3/dist/signature_pad.umd.min.js"></script>
    @stack('scripts')

    <script>
        /* ── Silent file download (no blank tab / no navigation) ─── */
        function triggerFileDownload(url) {
            var iframe = document.createElement('iframe');
            iframe.style.display = 'none';
            iframe.src = url;
            document.body.appendChild(iframe);
            setTimeout(function () { document.body.removeChild(iframe); }, 120000);
        }

        /* ── User dropdown ──────────────────────────────────────── */
        (function () {
            var btn       = document.getElementById('userMenuBtn');
            var dropdown  = document.getElementById('userDropdown');
            var container = document.getElementById('userMenuContainer');
            if (!btn || !dropdown) return;
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                var open = !dropdown.hidden;
                dropdown.hidden = open;
                btn.setAttribute('aria-expanded', !open);
            });
            document.addEventListener('click', function (e) {
                if (container && !container.contains(e.target)) {
                    dropdown.hidden = true;
                    btn.setAttribute('aria-expanded', 'false');
                }
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    dropdown.hidden = true;
                    btn.setAttribute('aria-expanded', 'false');
                }
            });
        }());

        /* ── Mobile sidebar toggle ──────────────────────────────── */
        (function () {
            var toggle  = document.getElementById('sidebarToggle');
            var sidebar = document.getElementById('appSidebar');
            var overlay = document.getElementById('sidebarOverlay');
            if (!toggle || !sidebar) return;
            function open()  { sidebar.classList.add('sidebar-open'); overlay && overlay.classList.add('visible'); }
            function close() { sidebar.classList.remove('sidebar-open'); overlay && overlay.classList.remove('visible'); }
            toggle.addEventListener('click', function () {
                sidebar.classList.contains('sidebar-open') ? close() : open();
            });
            overlay && overlay.addEventListener('click', close);
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') close();
            });
        }());
    </script>
</body>
</html>
