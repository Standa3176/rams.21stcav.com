<!DOCTYPE html>
<html lang="<?php echo e(str_replace('_', '-', app()->getLocale())); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
    <title><?php echo $__env->yieldContent('title', 'Operations Platform'); ?> — 21st Century AV</title>
    <style>
        /* ═══════════════════════════════════════════════════════════════
           DESIGN TOKENS — 21st Century AV brand palette
        ═══════════════════════════════════════════════════════════════ */
        :root {
            --teal:           #178A95;   /* primary brand teal          */
            --teal-hover:     #157B85;   /* hover / focus state         */
            --teal-light:     #EBF6F7;   /* teal-tinted light bg        */
            --teal-mid:       #C8E9EC;   /* teal border / divider       */
            --sidebar-bg:     #0B3C45;   /* dark teal sidebar           */
            --sidebar-hover:  rgba(255,255,255,.06);
            --sidebar-active: rgba(23,138,149,.18);
            --sidebar-width:  240px;
            --header-height:  64px;
            --bg:             #F3F6F7;   /* page background             */
            --surface:        #FFFFFF;   /* card / panel surface        */
            --border:         #E5E7EB;   /* borders & dividers          */
            --text:           #1F2937;   /* primary text                */
            --text-muted:     #6B7280;   /* secondary / helper text     */
            --text-faint:     #9CA3AF;   /* placeholder / disabled      */
            --danger:         #DC2626;
            --success:        #16A34A;
            --radius-sm:      6px;
            --radius:         10px;
            --radius-lg:      14px;
            --shadow-xs:      0 1px 2px rgba(0,0,0,.06);
            --shadow-sm:      0 1px 3px rgba(0,0,0,.08), 0 1px 2px rgba(0,0,0,.05);
            --shadow-md:      0 4px 12px rgba(0,0,0,.08), 0 1px 3px rgba(0,0,0,.06);
            --transition:     150ms ease;
        }

        /* ═══════════════════════════════════════════════════════════════
           RESET & BASE
        ═══════════════════════════════════════════════════════════════ */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { font-size: 16px; -webkit-font-smoothing: antialiased; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto,
                         'Inter', 'Helvetica Neue', Arial, sans-serif;
            font-size: 0.9375rem;
            line-height: 1.5;
            color: var(--text);
            background: var(--bg);
            min-height: 100vh;
        }
        a           { color: var(--teal); text-decoration: none; }
        a:hover     { color: var(--teal-hover); text-decoration: underline; }
        img, svg    { display: block; }
        button      { font-family: inherit; cursor: pointer; }
        input, select, textarea { font-family: inherit; }

        /* ═══════════════════════════════════════════════════════════════
           HEADER  — white, sticky, 64 px
        ═══════════════════════════════════════════════════════════════ */
        .app-header {
            position: fixed;
            top: 0; left: 0; right: 0;
            height: var(--header-height);
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            z-index: 200;
            display: flex;
            align-items: center;
            padding: 0 1.25rem 0 0;
        }

        /* Logo panel — matches sidebar width so title aligns with content */
        .header-logo-panel {
            width: var(--sidebar-width);
            flex-shrink: 0;
            display: flex;
            align-items: center;
            gap: .625rem;
            padding: 0 1.25rem;
            height: 100%;
            border-right: 1px solid var(--border);
        }
        .header-logo-mark {
            width: 32px;
            height: 32px;
            background: var(--teal);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .header-logo-mark svg { color: #fff; }
        .header-logo-name {
            font-size: .8125rem;
            font-weight: 700;
            color: var(--text);
            letter-spacing: -.01em;
            line-height: 1.25;
        }
        .header-logo-name span {
            display: block;
            font-size: .6875rem;
            font-weight: 500;
            color: var(--text-muted);
        }

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
            background: var(--sidebar-bg);
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
           PAGE WRAPPER
        ═══════════════════════════════════════════════════════════════ */
        .page-wrap {
            max-width: 1280px;
            margin: 0 auto;
            padding: 2rem 2rem;
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
            font-size: 1.375rem;
            font-weight: 700;
            color: var(--text);
            letter-spacing: -.02em;
        }
        .page-subtitle {
            font-size: .875rem;
            color: var(--text-muted);
            margin-top: .2rem;
        }

        /* ═══════════════════════════════════════════════════════════════
           CARDS
        ═══════════════════════════════════════════════════════════════ */
        .card {
            background: var(--surface);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow-sm);
            padding: 1.75rem 2rem;
            margin-bottom: 1.5rem;
        }
        .card-sm    { padding: 1.1rem 1.5rem; }
        .card-flush { padding: 0; }
        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1.1rem 1.5rem;
            border-bottom: 1px solid var(--border);
        }
        .card-title { font-size: .9375rem; font-weight: 600; color: var(--text); }
        .card-body  { padding: 1.5rem; }

        /* Stat cards (dashboard widgets) */
        .stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.75rem;
        }
        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow-xs);
            padding: 1.25rem 1.5rem;
            display: flex;
            flex-direction: column;
            gap: .35rem;
        }
        .stat-label { font-size: .75rem; font-weight: 600; text-transform: uppercase; letter-spacing: .06em; color: var(--text-muted); }
        .stat-value { font-size: 2rem; font-weight: 700; color: var(--teal); letter-spacing: -.03em; line-height: 1; }
        .stat-sub   { font-size: .75rem; color: var(--text-muted); }
        .stat-icon  { width: 36px; height: 36px; border-radius: 8px; background: var(--teal-light); color: var(--teal); display: flex; align-items: center; justify-content: center; margin-bottom: .5rem; }

        /* ═══════════════════════════════════════════════════════════════
           BUTTONS
        ═══════════════════════════════════════════════════════════════ */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            padding: .5rem 1.1rem;
            border-radius: var(--radius-sm);
            font-size: .875rem;
            font-weight: 500;
            cursor: pointer;
            border: 1px solid transparent;
            text-decoration: none;
            transition: background var(--transition), border-color var(--transition),
                        box-shadow var(--transition), color var(--transition);
            white-space: nowrap;
            letter-spacing: -.01em;
        }
        .btn:hover         { text-decoration: none; }
        .btn:focus-visible { outline: 2px solid var(--teal); outline-offset: 2px; }
        .btn:disabled      { opacity: .5; cursor: not-allowed; pointer-events: none; }

        .btn-teal          { background: var(--teal); color: #fff; border-color: var(--teal); box-shadow: var(--shadow-xs); }
        .btn-teal:hover    { background: var(--teal-hover); border-color: var(--teal-hover); color: #fff; }
        .btn-outline       { background: var(--surface); color: var(--text); border-color: var(--border); box-shadow: var(--shadow-xs); }
        .btn-outline:hover { background: var(--bg); border-color: #D1D5DB; color: var(--text); }
        .btn-ghost         { background: transparent; color: var(--text-muted); border-color: transparent; }
        .btn-ghost:hover   { background: var(--bg); color: var(--text); }

        .btn-danger            { background: var(--danger); color: #fff; border-color: var(--danger); }
        .btn-danger:hover      { background: #B91C1C; border-color: #B91C1C; color: #fff; }
        .btn-danger-outline    { background: transparent; color: var(--danger); border-color: #FECACA; }
        .btn-danger-outline:hover { background: #FEF2F2; border-color: #FCA5A5; }

        .btn-sm   { padding: .3rem .75rem; font-size: .8125rem; }
        .btn-full { width: 100%; justify-content: center; font-size: 1rem; padding: .75rem; }

        /* ═══════════════════════════════════════════════════════════════
           ALERTS
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
           SECTION HEADINGS
        ═══════════════════════════════════════════════════════════════ */
        .section-heading {
            font-size: .9375rem;
            font-weight: 700;
            color: var(--text);
            letter-spacing: -.01em;
            padding-bottom: .5rem;
            margin-bottom: 1.1rem;
            border-bottom: 2px solid var(--teal);
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
           TABLES
        ═══════════════════════════════════════════════════════════════ */
        .data-table { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .data-table th {
            background: var(--teal);
            color: #fff;
            padding: .7rem 1rem;
            text-align: left;
            font-weight: 600;
            font-size: .75rem;
            text-transform: uppercase;
            letter-spacing: .05em;
        }
        .data-table td {
            padding: .65rem 1rem;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
            color: var(--text);
        }
        .data-table tbody tr:last-child td { border-bottom: none; }
        .data-table tbody tr:hover         { background: #F9FBFB; }
        .data-table .actions               { display: flex; gap: .4rem; align-items: center; flex-wrap: wrap; }

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
           BADGES
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
        .pagination-wrap nav { display: flex; gap: .3rem; }
        .pagination-wrap a,
        .pagination-wrap span {
            padding: .4rem .75rem;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: .875rem;
            color: var(--text);
            background: var(--surface);
            transition: background var(--transition), border-color var(--transition);
        }
        .pagination-wrap a:hover          { background: var(--bg); border-color: #D1D5DB; text-decoration: none; color: var(--text); }
        .pagination-wrap span[aria-current] { background: var(--teal); color: #fff; border-color: var(--teal); }

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
        .empty-state-icon { width: 52px; height: 52px; border-radius: 12px; background: var(--teal-light); color: var(--teal); display: flex; align-items: center; justify-content: center; margin: 0 auto 1.25rem; }
        .empty-state h3   { font-size: 1rem; font-weight: 600; color: var(--text); margin-bottom: .4rem; }
        .empty-state p    { margin-bottom: 1.5rem; font-size: .875rem; max-width: 320px; margin-left: auto; margin-right: auto; }

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
        }
        @media (max-width: 480px) {
            .stat-grid { grid-template-columns: 1fr; }
        }
    </style>
    <?php echo $__env->yieldPushContent('styles'); ?>
</head>
<body>

    
    <header class="app-header">
        <div class="header-logo-panel">
            <div class="header-logo-mark">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
                     stroke-linejoin="round" aria-hidden="true">
                    <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
                </svg>
            </div>
            <div class="header-logo-name">
                21st Century AV
                <span>Operations Platform</span>
            </div>
        </div>

        <?php if(auth()->guard()->check()): ?>
        <button class="header-hamburger" id="sidebarToggle" aria-label="Toggle sidebar">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round"
                 stroke-linejoin="round" aria-hidden="true">
                <line x1="3" y1="6"  x2="21" y2="6"/>
                <line x1="3" y1="12" x2="21" y2="12"/>
                <line x1="3" y1="18" x2="21" y2="18"/>
            </svg>
        </button>
        <?php endif; ?>

        <div class="header-platform"><?php echo $__env->yieldContent('title', 'Operations Dashboard'); ?></div>

        <?php if(auth()->guard()->check()): ?>
        <div class="header-right">
            <div class="user-menu" id="userMenuContainer">
                <button class="user-btn" id="userMenuBtn" aria-haspopup="true" aria-expanded="false">
                    <div class="user-avatar" aria-hidden="true">
                        <?php echo e(strtoupper(mb_substr(auth()->user()->name, 0, 1))); ?>

                    </div>
                    <span class="user-btn-name"><?php echo e(auth()->user()->name); ?></span>
                    <svg class="user-chevron" width="13" height="13" viewBox="0 0 24 24"
                         fill="none" stroke="currentColor" stroke-width="2.5"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <polyline points="6 9 12 15 18 9"/>
                    </svg>
                </button>

                <div class="user-dropdown" id="userDropdown" hidden>
                    <div class="user-dropdown-info">
                        <div class="user-dropdown-name"><?php echo e(auth()->user()->name); ?></div>
                        <?php if(auth()->user()->email ?? false): ?>
                        <div class="user-dropdown-email"><?php echo e(auth()->user()->email); ?></div>
                        <?php endif; ?>
                    </div>
                    <form method="POST" action="<?php echo e(route('logout')); ?>" style="margin:0">
                        <?php echo csrf_field(); ?>
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
        <?php endif; ?>
    </header>

    
    <?php if(auth()->guard()->check()): ?>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <?php endif; ?>

    
    <?php if(auth()->guard()->check()): ?>
    <aside class="app-sidebar" id="appSidebar">
        <?php echo $__env->make('layouts.navigation', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
    </aside>
    <?php endif; ?>

    
    <main class="app-content <?php if(auth()->guard()->check()): ?> with-sidebar <?php endif; ?>">
        <div class="page-wrap">
            <?php if (! empty(trim($__env->yieldContent('content')))): ?>
                <?php echo $__env->yieldContent('content'); ?>
            <?php else: ?>
                <?php echo e($slot ?? ''); ?>

            <?php endif; ?>
        </div>
    </main>

    <?php echo $__env->yieldPushContent('scripts'); ?>

    <script>
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
<?php /**PATH /home/stcav/rams.21stcav.com/resources/views/layouts/app.blade.php ENDPATH**/ ?>