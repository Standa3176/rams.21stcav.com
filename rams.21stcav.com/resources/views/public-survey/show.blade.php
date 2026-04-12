<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Site Survey — {{ $survey->project_name }}</title>
    <style>
        /* ── Reset & base ─────────────────────────────────────────────── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { font-size: 17px; -webkit-font-smoothing: antialiased; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
            font-size: 1rem;
            line-height: 1.5;
            color: #1F2937;
            background: #F0F4F5;
            min-height: 100vh;
            padding-bottom: 5rem; /* space for sticky bar */
        }
        a { color: #178A95; text-decoration: none; }
        a:hover { text-decoration: underline; }
        input, select, textarea, button { font-family: inherit; font-size: inherit; }

        /* ── Layout ───────────────────────────────────────────────────── */
        .wrap {
            max-width: 860px;
            margin: 0 auto;
            padding: 0 .875rem 2rem;
        }

        /* ── Header ───────────────────────────────────────────────────── */
        .survey-header {
            background: #0B3C45;
            color: #fff;
            padding: 1rem 1.25rem .75rem;
            margin-bottom: 1.25rem;
        }
        .survey-header__inner {
            max-width: 860px;
            margin: 0 auto;
        }
        .survey-header__brand {
            font-size: .7rem;
            font-weight: 700;
            letter-spacing: .09em;
            text-transform: uppercase;
            color: rgba(255,255,255,.5);
            margin-bottom: .3rem;
        }
        .survey-header__title {
            font-size: 1.2rem;
            font-weight: 700;
            line-height: 1.3;
        }
        .survey-header__meta {
            font-size: .82rem;
            color: rgba(255,255,255,.65);
            margin-top: .25rem;
        }
        .survey-header__progress {
            margin-top: .75rem;
        }
        .progress-label {
            font-size: .78rem;
            color: rgba(255,255,255,.7);
            margin-bottom: .3rem;
        }
        .progress-bar-track {
            background: rgba(255,255,255,.18);
            border-radius: 99px;
            height: 5px;
            overflow: hidden;
        }
        .progress-bar-fill {
            height: 100%;
            background: #34D399;
            border-radius: 99px;
            transition: width .4s ease;
        }

        /* ── Alerts ───────────────────────────────────────────────────── */
        .alert {
            border-radius: 8px;
            padding: .9rem 1.1rem;
            margin-bottom: 1rem;
            font-size: .9rem;
            line-height: 1.5;
        }
        .alert-success { background: #D1FAE5; color: #065F46; border: 1px solid #6EE7B7; }
        .alert-error   { background: #FEE2E2; color: #991B1B; border: 1px solid #FCA5A5; }
        .alert-info    { background: #E0F2FE; color: #0C4A6E; border: 1px solid #7DD3FC; }
        .alert-warning { background: #FEF3C7; color: #92400E; border: 1px solid #FCD34D; }

        /* ── Cards ────────────────────────────────────────────────────── */
        .card {
            background: #fff;
            border-radius: 10px;
            border: 1px solid #E5E7EB;
            padding: 1.1rem 1.2rem;
            margin-bottom: 1rem;
        }
        .card-title {
            font-size: .9rem;
            font-weight: 700;
            color: #0B3C45;
            margin-bottom: .85rem;
            padding-bottom: .55rem;
            border-bottom: 1px solid #F0F0F0;
        }

        /* ── Forms ────────────────────────────────────────────────────── */
        .form-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: .6rem .85rem;
        }
        @media (max-width: 520px) {
            .form-grid-2 { grid-template-columns: 1fr; }
        }
        .form-group { margin-bottom: .55rem; }
        .form-label {
            display: block;
            font-size: .78rem;
            font-weight: 700;
            color: #374151;
            margin-bottom: .3rem;
            text-transform: uppercase;
            letter-spacing: .04em;
        }
        .form-label .req { color: #DC2626; }
        .form-control {
            display: block;
            width: 100%;
            padding: .72rem .8rem;
            border: 1.5px solid #D1D5DB;
            border-radius: 7px;
            font-size: 1rem;
            background: #fff;
            color: #1F2937;
            min-height: 52px;
            transition: border-color 150ms, box-shadow 150ms;
            -webkit-appearance: none;
        }
        .form-control:focus {
            outline: none;
            border-color: #178A95;
            box-shadow: 0 0 0 3px rgba(23,138,149,.15);
        }
        .form-control:disabled, .form-control[readonly] {
            background: #F9FAFB;
            color: #6B7280;
        }
        textarea.form-control { resize: vertical; min-height: unset; }
        select.form-control { appearance: auto; -webkit-appearance: auto; }

        /* ── Buttons ──────────────────────────────────────────────────── */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: .35rem;
            padding: .65rem 1.2rem;
            border-radius: 8px;
            border: 1.5px solid transparent;
            font-size: .92rem;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            transition: background 150ms, color 150ms, border-color 150ms;
            white-space: nowrap;
            min-height: 48px;
            touch-action: manipulation;
        }
        .btn-teal    { background: #178A95; color: #fff; border-color: #178A95; }
        .btn-teal:hover { background: #157B85; border-color: #157B85; color: #fff; text-decoration: none; }
        .btn-outline { background: transparent; color: #178A95; border-color: #178A95; }
        .btn-outline:hover { background: #EBF6F7; text-decoration: none; }
        .btn-sm { padding: .45rem .85rem; font-size: .82rem; min-height: 40px; }
        .btn-danger { background: #DC2626; color: #fff; border-color: #DC2626; }
        .btn-danger:hover { background: #B91C1C; color: #fff; text-decoration: none; }
        .btn:disabled { opacity: .5; cursor: not-allowed; }

        /* ── Section label ────────────────────────────────────────────── */
        .section-label {
            font-size: .72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .07em;
            color: #178A95;
            margin-bottom: .6rem;
        }

        /* ── Survey section blocks ────────────────────────────────────── */
        .survey-section {
            border-radius: 8px;
            border: 1.5px solid #E5E7EB;
            margin-bottom: .75rem;
            overflow: hidden;
        }
        .survey-section__hdr {
            font-size: .72rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .07em;
            padding: .5rem .85rem;
            border-bottom: 1.5px solid transparent;
        }
        .survey-section__body {
            padding: .85rem .85rem .65rem;
        }

        /* SPACE INFO — teal */
        .survey-section--info {
            background: #EBF8FA;
            border-color: #94C4C9;
        }
        .survey-section--info .survey-section__hdr {
            color: #0B5860;
            border-color: #94C4C9;
            background: #DCF2F5;
        }

        /* AV SCOPE — indigo */
        .survey-section--scope {
            background: #EEF2FF;
            border-color: #C7D2FE;
        }
        .survey-section--scope .survey-section__hdr {
            color: #3730A3;
            border-color: #C7D2FE;
            background: #E0E7FF;
        }

        /* SITE CONDITIONS — amber */
        .survey-section--conditions {
            background: #FFF7ED;
            border-color: #FDBA74;
        }
        .survey-section--conditions .survey-section__hdr {
            color: #7C2D12;
            border-color: #FDBA74;
            background: #FEE9CC;
        }

        /* NOTES & PHOTOS — grey */
        .survey-section--notes {
            background: #F9FAFB;
            border-color: #D1D5DB;
        }
        .survey-section--notes .survey-section__hdr {
            color: #374151;
            border-color: #D1D5DB;
            background: #F3F4F6;
        }

        /* PA — green tint */
        .survey-section--pa {
            background: #F0FFF4;
            border-color: #86EFAC;
        }
        .survey-section--pa .survey-section__hdr {
            color: #14532D;
            border-color: #86EFAC;
            background: #DCFCE7;
        }

        /* Signage — purple tint */
        .survey-section--signage {
            background: #FDF4FF;
            border-color: #E9D5FF;
        }
        .survey-section--signage .survey-section__hdr {
            color: #581C87;
            border-color: #E9D5FF;
            background: #F3E8FF;
        }

        /* Upgrade — pink tint */
        .survey-section--upgrade {
            background: #FFF1F2;
            border-color: #FECDD3;
        }
        .survey-section--upgrade .survey-section__hdr {
            color: #881337;
            border-color: #FECDD3;
            background: #FFE4E6;
        }

        /* ── Tap-card checkboxes ──────────────────────────────────────── */
        .tap-card-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: .5rem;
            margin: .1rem 0 .35rem;
        }
        .tap-card {
            position: relative;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: .25rem;
            padding: .7rem .4rem .6rem;
            border: 2px solid #D1D5DB;
            border-radius: 9px;
            background: #fff;
            cursor: pointer;
            user-select: none;
            touch-action: manipulation;
            transition: border-color 150ms, background 150ms;
            text-align: center;
            min-height: 72px;
        }
        .tap-card input[type="checkbox"] {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
            pointer-events: none;
        }
        .tap-card__icon {
            font-size: 1.4rem;
            line-height: 1;
        }
        .tap-card__label {
            font-size: .7rem;
            font-weight: 700;
            color: #6B7280;
            line-height: 1.2;
        }
        /* checked state via :has */
        .tap-card:has(input:checked) {
            border-color: #059669;
            background: #D1FAE5;
        }
        .tap-card:has(input:checked) .tap-card__label {
            color: #065F46;
        }
        /* fallback for browsers without :has — JS adds .is-checked */
        .tap-card.is-checked {
            border-color: #059669;
            background: #D1FAE5;
        }
        .tap-card.is-checked .tap-card__label {
            color: #065F46;
        }

        /* ── Infrastructure accordion ─────────────────────────────────── */
        .infra-toggle {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            font-size: .78rem;
            font-weight: 700;
            color: #7C2D12;
            background: rgba(253,186,116,.2);
            border: 1.5px solid #FDBA74;
            border-radius: 6px;
            padding: .45rem .85rem;
            cursor: pointer;
            margin-bottom: .65rem;
            touch-action: manipulation;
            min-height: 44px;
        }
        .infra-toggle:hover { background: rgba(253,186,116,.35); }
        .infra-panel { display: none; }
        .infra-panel.open { display: block; }

        /* ── Room card ────────────────────────────────────────────────── */
        .room-card {
            border-radius: 10px;
            margin-bottom: .85rem;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,.07);
        }
        .room-card__header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: .9rem 1rem;
            cursor: pointer;
            user-select: none;
            touch-action: manipulation;
            border-bottom: 2px solid transparent;
            gap: .5rem;
            min-height: 62px;
        }
        .room-card__header--inprogress {
            background: #FEF3C7;
            border-color: #FCD34D;
        }
        .room-card__header--complete {
            background: #D1FAE5;
            border-color: #6EE7B7;
        }
        .room-card__left {
            display: flex;
            flex-direction: column;
            gap: .15rem;
            flex: 1;
            min-width: 0;
        }
        .room-card__name {
            font-size: 1rem;
            font-weight: 800;
            color: #0B3C45;
            line-height: 1.25;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .room-card__floor {
            font-size: .78rem;
            color: #6B7280;
            font-weight: 500;
        }
        .room-card__right {
            display: flex;
            align-items: center;
            gap: .5rem;
            flex-shrink: 0;
        }
        .room-status-badge {
            font-size: .68rem;
            font-weight: 800;
            padding: .2rem .55rem;
            border-radius: 20px;
            white-space: nowrap;
        }
        .room-status-badge--inprogress {
            background: #FDE68A;
            color: #92400E;
        }
        .room-status-badge--complete {
            background: #A7F3D0;
            color: #065F46;
        }
        .room-card__chevron {
            font-size: 1rem;
            color: #6B7280;
            flex-shrink: 0;
            transition: transform 200ms;
        }
        .room-card__chevron.open {
            transform: rotate(180deg);
        }
        .room-body {
            display: block;
            background: #fff;
            border: 1.5px solid #E5E7EB;
            border-top: none;
            border-radius: 0 0 10px 10px;
            padding: .85rem .85rem 0;
        }
        .room-body.collapsed { display: none; }

        /* ── Mark Complete button ─────────────────────────────────────── */
        .btn-complete {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: .5rem;
            width: 100%;
            padding: 0 1rem;
            height: 56px;
            background: #059669;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 1.05rem;
            font-weight: 800;
            cursor: pointer;
            touch-action: manipulation;
            transition: background 150ms;
            margin-bottom: .85rem;
        }
        .btn-complete:hover { background: #047857; }
        .btn-complete:disabled { opacity: .5; cursor: not-allowed; }
        .btn-undo-complete {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: .5rem;
            width: 100%;
            padding: 0 1rem;
            height: 48px;
            background: #D1FAE5;
            color: #065F46;
            border: 1.5px solid #6EE7B7;
            border-radius: 8px;
            font-size: .9rem;
            font-weight: 700;
            cursor: pointer;
            touch-action: manipulation;
            margin-bottom: .85rem;
        }

        /* ── Photos ───────────────────────────────────────────────────── */
        .photo-grid { display: flex; flex-wrap: wrap; gap: .5rem; margin: .65rem 0; }
        .photo-thumb {
            position: relative;
            width: 86px;
            height: 86px;
            border-radius: 7px;
            overflow: hidden;
            border: 1px solid #E5E7EB;
            background: #F9FAFB;
        }
        .photo-thumb img { width: 100%; height: 100%; object-fit: cover; }
        .photo-upload-btn {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            font-size: .82rem;
            font-weight: 700;
            color: #178A95;
            border: 2px dashed #94C4C9;
            border-radius: 7px;
            padding: .5rem 1rem;
            background: none;
            cursor: pointer;
            touch-action: manipulation;
            min-height: 44px;
        }
        .photo-upload-btn:hover { background: #EBF6F7; }
        .photo-file-input { display: none; }

        /* ── Kit list drawer ───────────────────────────────────────────── */
        .kit-block {
            background: #EBF8FA;
            border: 1.5px solid #94C4C9;
            border-radius: 8px;
            margin-bottom: .75rem;
        }
        .kit-toggle {
            display: flex;
            align-items: center;
            gap: .5rem;
            width: 100%;
            background: none;
            border: none;
            padding: .55rem .85rem;
            color: #178A95;
            font-size: .82rem;
            font-weight: 700;
            cursor: pointer;
            text-align: left;
            touch-action: manipulation;
            min-height: 48px;
        }
        .kit-toggle:hover { background: rgba(23,138,149,.06); }
        .kit-chevron { display: inline-block; transition: transform 200ms; margin-left: auto; }
        .kit-drawer { overflow: hidden; max-height: 0; transition: max-height 350ms ease; }
        .kit-row {
            display: flex;
            gap: .6rem;
            align-items: baseline;
            padding: .25rem 0;
            border-bottom: 1px solid #d6eeef;
            font-size: .82rem;
        }
        .kit-row:last-child { border-bottom: none; }
        .kit-qty  { color: #178A95; font-weight: 800; min-width: 2rem; text-align: right; flex-shrink: 0; }
        .kit-part { font-family: monospace; background: #c7e8ec; padding: .05rem .3rem; border-radius: 3px; font-size: .76rem; color: #0B3C45; margin-right: .3rem; }
        .kit-drawer-inner { padding: .15rem .85rem .6rem; border-top: 1.5px solid #94C4C9; }

        /* ── Pre-Install Checks panel ─────────────────────────────────── */
        .checks-block { background:#FFFBEB; border:1.5px solid #FCD34D; border-radius:6px; margin-bottom:12px; }
        .checks-toggle { display:flex; align-items:center; gap:.5rem; width:100%; background:none; border:none;
            padding:.6rem .85rem; color:#92400E; font-size:.82rem; font-weight:700; cursor:pointer;
            text-align:left; border-radius:6px; min-height:48px; }
        .checks-drawer { overflow:hidden; max-height:0; transition:max-height 350ms ease; }
        .checks-drawer-inner { padding:.5rem .85rem .75rem; border-top:1.5px solid #FCD34D; }
        .check-item { padding:.6rem 0; border-bottom:1px solid #FDE68A; }
        .check-item:last-child { border-bottom:none; }
        .check-question { font-size:.875rem; color:#1F2937; line-height:1.5; margin-bottom:.5rem; }
        .check-answers { display:flex; gap:.5rem; flex-wrap:wrap; margin-bottom:.25rem; }
        .check-btn { min-height:44px; padding:.45rem 1rem; border-radius:6px; font-size:.82rem; font-weight:700;
            cursor:pointer; border:1.5px solid #D1D5DB; background:#ffffff; color:#374151; transition:opacity .15s; }
        .check-btn.is-yes  { background:#D1FAE5; border-color:#059669; color:#065F46; }
        .check-btn.is-no   { background:#FEE2E2; border-color:#FCA5A5; color:#991B1B; }
        .check-btn.is-other { background:#FEF3C7; border-color:#FCD34D; color:#92400E; }
        .check-btn.loading { opacity:.6; cursor:not-allowed; }
        .check-other-text { display:none; margin-top:.5rem; }
        .check-other-textarea { width:100%; border:1.5px solid #D1D5DB; border-radius:7px;
            padding:.72rem .8rem; font-size:.875rem; color:#1F2937; resize:vertical; font-family:inherit; }
        .check-other-textarea:focus { outline:none; border-color:#178A95; box-shadow:0 0 0 3px rgba(23,138,149,.15); }
        .check-progress { font-size:.82rem; color:#6B7280; text-align:right; padding-top:.4rem; }
        .checks-chevron { display:inline-block; transition:transform 200ms; color:#92400E; }
        .sr-only { position:absolute; width:1px; height:1px; padding:0; margin:-1px; overflow:hidden; clip:rect(0,0,0,0); white-space:nowrap; border:0; }

        /* ── Action bar ───────────────────────────────────────────────── */
        .action-bar {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: #fff;
            border-top: 1.5px solid #E5E7EB;
            padding: .65rem .875rem;
            display: flex;
            gap: .6rem;
            align-items: stretch;
            z-index: 200;
            box-shadow: 0 -3px 12px rgba(0,0,0,.08);
        }
        .action-bar .btn {
            flex: 1;
        }

        /* ── Submitted banner ─────────────────────────────────────────── */
        .submitted-banner {
            background: #D1FAE5;
            border: 1.5px solid #6EE7B7;
            border-radius: 10px;
            padding: 1rem 1.1rem;
            display: flex;
            align-items: center;
            gap: .75rem;
            margin-bottom: 1rem;
            color: #065F46;
            font-weight: 700;
        }
        .submitted-banner__icon { font-size: 1.6rem; flex-shrink: 0; }

        /* ── Validation errors ────────────────────────────────────────── */
        .is-invalid { border-color: #DC2626 !important; }
        .invalid-feedback { color: #DC2626; font-size: .78rem; margin-top: .2rem; }

        /* ── Divider ──────────────────────────────────────────────────── */
        .divider { border: 0; border-top: 1px solid #F0F0F0; margin: .7rem 0; }

        /* ── Read-only condition chips ────────────────────────────────── */
        .condition-chips {
            display: flex;
            gap: .5rem;
            flex-wrap: wrap;
            margin: .35rem 0;
        }
        .chip {
            display: inline-flex;
            align-items: center;
            gap: .3rem;
            padding: .3rem .7rem;
            border-radius: 20px;
            font-size: .8rem;
            font-weight: 700;
            border: 1.5px solid transparent;
        }
        .chip--on  { background: #D1FAE5; color: #065F46; border-color: #6EE7B7; }
        .chip--off { background: #F3F4F6; color: #9CA3AF; border-color: #E5E7EB; }
    </style>
</head>
<body>

@php
    $rooms = $survey->rooms->sortBy('sort_order');

    // Progress calculation
    $totalRooms     = $rooms->count();
    $completedRooms = $rooms->where('is_completed', true)->count();
    $progressPct    = $totalRooms > 0 ? round($completedRooms / $totalRooms * 100) : 0;

    // Survey-level type labels
    $surveyType      = $survey->survey_type ?? 'general';
    $areasHeadingPh  = match($surveyType) {
        'pa_system'      => 'PA Zones',
        'infrastructure' => 'Locations / Routes',
        'signage'        => 'Display Positions',
        default          => 'Spaces',
    };
    $areaLabelPh = $areasHeadingPh;
@endphp

{{-- Header --}}
<div class="survey-header">
    <div class="survey-header__inner">
        <div class="survey-header__brand">21st Century AV — Site Survey</div>
        <div class="survey-header__title">{{ $survey->project_name }}</div>
        <div class="survey-header__meta">
            @if($survey->client_name){{ $survey->client_name }}@endif
            @if($survey->client_name && $survey->site_address) &nbsp;·&nbsp; @endif
            @if($survey->site_address){{ $survey->site_address }}@endif
        </div>
        @if($totalRooms > 0)
        <div class="survey-header__progress">
            <div class="progress-label">{{ $completedRooms }} of {{ $totalRooms }} rooms complete</div>
            <div class="progress-bar-track">
                <div class="progress-bar-fill" id="progress-fill" style="width:{{ $progressPct }}%"></div>
            </div>
        </div>
        @endif
    </div>
</div>

<div class="wrap">

    {{-- Flash messages --}}
    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-error">{{ session('error') }}</div>
    @endif

    {{-- Validation errors --}}
    @if($errors->any())
        <div class="alert alert-error">
            <strong>Please correct the following:</strong>
            <ul style="margin:.5rem 0 0 1.2rem; font-size:.875rem;">
                @foreach($errors->all() as $err) <li>{{ $err }}</li> @endforeach
            </ul>
        </div>
    @endif

    {{-- Submitted confirmation --}}
    @if($readonly)
        <div class="submitted-banner">
            <span class="submitted-banner__icon">✓</span>
            <div>
                <div>Survey submitted successfully.</div>
                <div style="font-weight:500; font-size:.85rem; margin-top:.15rem;">
                    Submitted {{ $survey->submitted_at?->format('d M Y \a\t H:i') }}.
                    The project team has received your survey data.
                </div>
            </div>
        </div>
    @elseif($survey->isDraft())
        <div class="alert alert-info">
            <strong>Draft survey.</strong>
            Fill in the details for each room below, then use <strong>Save Draft</strong> to preserve your progress
            or <strong>Submit Survey</strong> when complete.
        </div>
    @endif

    {{-- Survey info card --}}
    <div class="card">
        <div class="card-title">Survey Details</div>
        @if($readonly)
            <div class="form-grid-2">
                <div>
                    <div class="form-label">Survey Date</div>
                    <div style="font-size:.95rem; color:#1F2937; margin-top:.2rem;">{{ $survey->survey_date?->format('d M Y') ?? '—' }}</div>
                </div>
                <div>
                    <div class="form-label">Surveyor</div>
                    <div style="font-size:.95rem; color:#1F2937; margin-top:.2rem;">{{ $survey->surveyor_name ?? '—' }}</div>
                </div>
            </div>
            @if($survey->general_notes)
                <div style="margin-top:.85rem;">
                    <div class="form-label">General Notes</div>
                    <div style="white-space:pre-wrap; color:#374151; font-size:.9rem; margin-top:.25rem;">{{ $survey->general_notes }}</div>
                </div>
            @endif
        @else
            <form id="survey-header-form" method="POST" action="{{ route('survey.save', $token) }}">
                @csrf
                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label" for="survey_date">Survey Date</label>
                        <input type="date" id="survey_date" name="survey_date"
                               class="form-control @error('survey_date') is-invalid @enderror"
                               value="{{ old('survey_date', $survey->survey_date?->format('Y-m-d')) }}">
                        @error('survey_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="surveyor_name">Your Name <span class="req">*</span></label>
                        <input type="text" id="surveyor_name" name="surveyor_name"
                               class="form-control @error('surveyor_name') is-invalid @enderror"
                               value="{{ old('surveyor_name', $survey->surveyor_name) }}"
                               placeholder="Engineer / Surveyor name" maxlength="100">
                        @error('surveyor_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>
                <div class="form-group" style="margin-top:.4rem;">
                    <label class="form-label" for="general_notes">General Site Notes</label>
                    <textarea id="general_notes" name="general_notes"
                              class="form-control @error('general_notes') is-invalid @enderror"
                              rows="3" maxlength="3000"
                              placeholder="Overall site access, parking, security, key contacts, constraints…">{{ old('general_notes', $survey->general_notes) }}</textarea>
                    @error('general_notes') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
            </form>
        @endif
    </div>

    {{-- Site Conditions — global amber section (site_risks, access_constraints, h_and_s_notes) --}}
    <div class="survey-section survey-section--conditions" style="border-radius:10px; border-width:1px; border-style:solid; margin-bottom:1rem; overflow:hidden;">
        <div class="survey-section__hdr" style="padding:.55rem 1rem; font-size:.78rem; font-weight:700; letter-spacing:.05em; text-transform:uppercase; border-bottom-width:1px; border-bottom-style:solid;">
            SITE CONDITIONS
        </div>
        <div class="survey-section__body" style="padding:1rem;">
            @if($readonly)
                @if($survey->site_risks || $survey->access_constraints || $survey->h_and_s_notes)
                    @if($survey->site_risks)
                        <div style="margin-bottom:.85rem;">
                            <div class="form-label">Site Risks</div>
                            <div style="white-space:pre-wrap; color:#374151; font-size:.9rem; margin-top:.25rem;">{{ $survey->site_risks }}</div>
                        </div>
                    @endif
                    @if($survey->access_constraints)
                        <div style="margin-bottom:.85rem;">
                            <div class="form-label">Access Constraints</div>
                            <div style="white-space:pre-wrap; color:#374151; font-size:.9rem; margin-top:.25rem;">{{ $survey->access_constraints }}</div>
                        </div>
                    @endif
                    @if($survey->h_and_s_notes)
                        <div>
                            <div class="form-label">Health &amp; Safety Notes</div>
                            <div style="white-space:pre-wrap; color:#374151; font-size:.9rem; margin-top:.25rem;">{{ $survey->h_and_s_notes }}</div>
                        </div>
                    @endif
                @else
                    <div style="font-size:.875rem; color:#6B7280; font-style:italic;">No site conditions recorded.</div>
                @endif
            @else
                <div class="form-group">
                    <label class="form-label" for="site_risks">Site Risks</label>
                    <textarea id="site_risks" name="site_risks"
                              class="form-control @error('site_risks') is-invalid @enderror"
                              rows="3" maxlength="3000"
                              placeholder="Describe any known site-specific hazards or risks…">{{ old('site_risks', $survey->site_risks) }}</textarea>
                    @error('site_risks') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="form-group" style="margin-top:.75rem;">
                    <label class="form-label" for="access_constraints">Access Constraints</label>
                    <textarea id="access_constraints" name="access_constraints"
                              class="form-control @error('access_constraints') is-invalid @enderror"
                              rows="3" maxlength="3000"
                              placeholder="Describe access restrictions, working hours, parking, permits…">{{ old('access_constraints', $survey->access_constraints) }}</textarea>
                    @error('access_constraints') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="form-group" style="margin-top:.75rem;">
                    <label class="form-label" for="h_and_s_notes">Health &amp; Safety Notes</label>
                    <textarea id="h_and_s_notes" name="h_and_s_notes"
                              class="form-control @error('h_and_s_notes') is-invalid @enderror"
                              rows="3" maxlength="3000"
                              placeholder="PPE requirements, induction details, site supervisor contact…">{{ old('h_and_s_notes', $survey->h_and_s_notes) }}</textarea>
                    @error('h_and_s_notes') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
            @endif
        </div>
    </div>

    {{-- Rooms --}}
    @if($rooms->isEmpty())
        <div class="card" style="text-align:center; color:#6B7280; padding:2.5rem 1.5rem;">
            <div style="font-size:2rem; margin-bottom:.6rem;">📋</div>
            <div style="font-weight:700; color:#374151;">No rooms have been added to this survey yet.</div>
            <div style="font-size:.84rem; margin-top:.4rem;">
                Please contact your project manager to have room details added.
            </div>
        </div>
    @else
        <form id="survey-form" method="POST" action="{{ $readonly ? '#' : route('survey.save', $token) }}">
            @csrf

            {{-- Mirror header form fields via JS before submit --}}
            @if(!$readonly)
                <input type="hidden" name="survey_date"        id="hf_survey_date">
                <input type="hidden" name="surveyor_name"      id="hf_surveyor_name">
                <input type="hidden" name="general_notes"      id="hf_general_notes">
                <input type="hidden" name="site_risks"         id="hf_site_risks">
                <input type="hidden" name="access_constraints" id="hf_access_constraints">
                <input type="hidden" name="h_and_s_notes"      id="hf_h_and_s_notes">
            @endif

            <p class="section-label">{{ $areasHeadingPh }} ({{ $rooms->count() }})</p>

            @foreach($rooms as $ri => $room)
                @php
                    $roomSpaceType    = $room->space_type ?? 'general';
                    $showPaRm         = in_array($roomSpaceType, ['pa_system', 'mixed']);
                    $showSignRm       = in_array($roomSpaceType, ['signage',   'mixed']);
                    $showUpgRm        = in_array($roomSpaceType, ['upgrade',   'mixed']);
                    $showAreaTypeRm   = $roomSpaceType !== 'general';
                    $roomComplete     = !empty($room->is_completed);
                    $hdrClass         = $roomComplete ? 'room-card__header--complete' : 'room-card__header--inprogress';
                    $badgeClass       = $roomComplete ? 'room-status-badge--complete' : 'room-status-badge--inprogress';
                    $badgeText        = $roomComplete ? '✓ Complete' : 'In Progress';
                    $roomKitItems     = $kitByArea[$room->room_name] ?? [];
                    $roomSolutionType = $solutionTypesByRoom[$room->room_name] ?? null;
                    $checklistLines   = $roomSolutionType ? $roomSolutionType->checklistLines() : [];
                @endphp

                <div class="room-card" id="room-card-{{ $room->id }}">
                    <input type="hidden" name="rooms[{{ $ri }}][id]" value="{{ $room->id }}">

                    {{-- Room card header — full-width tap area --}}
                    <div class="room-card__header {{ $hdrClass }}"
                         id="room-hdr-{{ $room->id }}"
                         onclick="toggleRoom({{ $room->id }})">
                        <div class="room-card__left">
                            <div class="room-card__name" id="room-title-{{ $room->id }}">{{ $room->room_name }}</div>
                            @if($room->floor)
                                <div class="room-card__floor">{{ $room->floor }}</div>
                            @endif
                        </div>
                        <div class="room-card__right">
                            <span class="room-status-badge {{ $badgeClass }}" id="room-badge-{{ $room->id }}">{{ $badgeText }}</span>
                            <span class="room-card__chevron" id="room-toggle-{{ $room->id }}">▼</span>
                        </div>
                    </div>

                    <div class="room-body collapsed" id="room-body-{{ $room->id }}">

                        {{-- ── KIT LIST DRAWER (at top of room body) ─────────── --}}
                        @if(count($roomKitItems) > 0)
                        <div class="kit-block">
                            <button type="button" class="kit-toggle" onclick="toggleKit(this)">
                                <span style="background:#178A95;color:#fff;border-radius:4px;padding:.1rem .45rem;font-size:.7rem;letter-spacing:.04em;flex-shrink:0;">KIT</span>
                                <span style="flex:1;">Quote Kit List — {{ count($roomKitItems) }} item{{ count($roomKitItems) !== 1 ? 's' : '' }}</span>
                                <span class="kit-chevron">&#9660;</span>
                            </button>
                            <div class="kit-drawer">
                                <div class="kit-drawer-inner">
                                    @foreach($roomKitItems as $kitItem)
                                    @php
                                        $kitQty  = $kitItem['quantity'] ?? $kitItem['qty'] ?? 1;
                                        $kitPart = trim((string) ($kitItem['part_number'] ?? $kitItem['part_no'] ?? ''));
                                        $kitName = $kitItem['name'] ?? $kitItem['description'] ?? '';
                                    @endphp
                                    <div class="kit-row">
                                        <span class="kit-qty">{{ $kitQty }}</span>
                                        <span style="flex:1;color:#1F2937;">
                                            @if($kitPart !== '') <span class="kit-part">{{ $kitPart }}</span> @endif
                                            {{ $kitName }}
                                        </span>
                                    </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                        @endif

                        {{-- ── PRE-INSTALL CHECKS PANEL ──────────────────────────────────── --}}
                        @if($room->questions->isNotEmpty())
                        @php
                            $totalChecks    = $room->questions->count();
                            $answeredChecks = $room->questions->whereNotNull('answer')->count();
                        @endphp
                        <div class="checks-block">
                            <button type="button" class="checks-toggle" onclick="toggleChecks(this)"
                                    aria-expanded="false">
                                <span style="background:#0B3C45;color:#fff;border-radius:4px;padding:.1rem .45rem;font-size:.7rem;font-weight:700;letter-spacing:.04em;flex-shrink:0;">PRE-INSTALL</span>
                                <span style="flex:1;">Pre-Install Checks &mdash; {{ $totalChecks }} {{ Str::plural('question', $totalChecks) }}</span>
                                <span class="checks-chevron">&#9660;</span>
                            </button>
                            <div class="checks-drawer">
                                <div class="checks-drawer-inner">
                                    @foreach($room->questions as $qi => $question)
                                    <div class="check-item" id="check-{{ $question->id }}"
                                         data-answer-url="{{ route('survey.question.answer', ['token' => $token, 'room' => $room->id, 'question' => $question->id]) }}">
                                        <p class="check-question"><strong>{{ $qi + 1 }}.</strong> {{ $question->question }}</p>
                                        <div class="check-answers">
                                            <button type="button" class="check-btn{{ $question->answer === 'yes' ? ' is-yes' : '' }}"
                                                    data-answer="yes"
                                                    aria-label="Answer Yes to question {{ $qi + 1 }}"
                                                    onclick="answerCheck({{ $question->id }}, 'yes', {{ $room->id }}, '{{ $token }}')">Yes</button>
                                            <button type="button" class="check-btn{{ $question->answer === 'no' ? ' is-no' : '' }}"
                                                    data-answer="no"
                                                    aria-label="Answer No to question {{ $qi + 1 }}"
                                                    onclick="answerCheck({{ $question->id }}, 'no', {{ $room->id }}, '{{ $token }}')">No</button>
                                            <button type="button" class="check-btn{{ $question->answer === 'other' ? ' is-other' : '' }}"
                                                    data-answer="other"
                                                    aria-label="Answer Other to question {{ $qi + 1 }}"
                                                    onclick="answerCheck({{ $question->id }}, 'other', {{ $room->id }}, '{{ $token }}')">Other</button>
                                        </div>
                                        <div class="check-other-text" style="display:{{ $question->answer === 'other' ? 'block' : 'none' }};">
                                            <label for="other-{{ $question->id }}" class="sr-only">Explanation for "Other"</label>
                                            <textarea id="other-{{ $question->id }}"
                                                      class="check-other-textarea"
                                                      rows="2"
                                                      maxlength="2000"
                                                      placeholder="Please explain&hellip;"
                                                      onblur="saveOtherText({{ $question->id }}, this)">{{ $question->other_text }}</textarea>
                                        </div>
                                        <div class="check-error" style="display:none;color:#991B1B;font-size:.82rem;margin-top:.25rem;"></div>
                                    </div>
                                    @endforeach
                                    @if($answeredChecks < $totalChecks)
                                    <p class="check-progress">{{ $answeredChecks }} of {{ $totalChecks }} answered</p>
                                    @endif
                                </div>
                            </div>
                        </div>
                        @endif

                        {{-- ═══════════════════════════════════════════════════ --}}
                        {{-- SECTION 1: SPACE INFORMATION                       --}}
                        {{-- ═══════════════════════════════════════════════════ --}}
                        <div class="survey-section survey-section--info">
                            <div class="survey-section__hdr">📍 Space Information</div>
                            <div class="survey-section__body">

                                @if(!$readonly)
                                <div class="form-grid-2" style="margin-bottom:.6rem;">
                                    <div class="form-group" style="margin-bottom:0;">
                                        <label class="form-label">Space / Survey Type</label>
                                        <select name="rooms[{{ $ri }}][space_type]" class="form-control"
                                                onchange="onSpaceTypeChange(this)">
                                            <option value="general"        {{ $roomSpaceType === 'general'        ? 'selected' : '' }}>General AV / Meeting Room</option>
                                            <option value="pa_system"      {{ $roomSpaceType === 'pa_system'      ? 'selected' : '' }}>PA / Background Music</option>
                                            <option value="infrastructure" {{ $roomSpaceType === 'infrastructure' ? 'selected' : '' }}>Infrastructure / Cable Route</option>
                                            <option value="signage"        {{ $roomSpaceType === 'signage'        ? 'selected' : '' }}>Digital Signage</option>
                                            <option value="upgrade"        {{ $roomSpaceType === 'upgrade'        ? 'selected' : '' }}>Upgrade / Strip-out</option>
                                            <option value="mixed"          {{ $roomSpaceType === 'mixed'          ? 'selected' : '' }}>Mixed (all sections)</option>
                                        </select>
                                    </div>
                                    @if($showAreaTypeRm)
                                    <div class="area-type-group form-group" style="margin-bottom:0;">
                                        <label class="form-label">Area Classification</label>
                                        <select name="rooms[{{ $ri }}][area_type]" class="form-control">
                                            <option value="">— Select —</option>
                                            @foreach(['room'=>'Meeting Room','open_plan'=>'Open Plan Area','lobby'=>'Lobby / Reception','auditorium'=>'Auditorium / Theatre','outdoor_area'=>'Outdoor Area','zone'=>'PA Zone / Coverage Area','rack_location'=>'Rack / Equipment Room','cable_route'=>'Cable Route / Riser','display_position'=>'Display Position','stairwell'=>'Stairwell / Corridor','other'=>'Other'] as $atVal => $atLbl)
                                                <option value="{{ $atVal }}" {{ $room->area_type === $atVal ? 'selected' : '' }}>{{ $atLbl }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    @endif
                                </div>
                                @endif

                                <div class="form-grid-2">
                                    <div class="form-group">
                                        <label class="form-label">{{ $areaLabelPh }} Name <span class="req">*</span></label>
                                        <input type="text" name="rooms[{{ $ri }}][room_name]"
                                               class="form-control"
                                               value="{{ $room->room_name }}"
                                               {{ $readonly ? 'readonly' : '' }} maxlength="150" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Room Ref</label>
                                        <input type="text" name="rooms[{{ $ri }}][room_ref]"
                                               class="form-control"
                                               value="{{ $room->room_ref }}"
                                               {{ $readonly ? 'readonly' : '' }} maxlength="50">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Floor / Level</label>
                                        <input type="text" name="rooms[{{ $ri }}][floor]"
                                               class="form-control"
                                               value="{{ $room->floor }}"
                                               {{ $readonly ? 'readonly' : '' }}
                                               maxlength="50" placeholder="e.g. Ground, 1st, B1">
                                    </div>
                                </div>

                            </div>
                        </div>

                        {{-- ═══════════════════════════════════════════════════ --}}
                        {{-- SECTION 2: AV SCOPE                                --}}
                        {{-- ═══════════════════════════════════════════════════ --}}
                        <div class="survey-section survey-section--scope">
                            <div class="survey-section__hdr">
                                📺 AV Scope
                                @if($roomSolutionType)
                                <span style="font-size:.72rem;font-weight:600;background:#0B3C45;color:#fff;padding:.1rem .5rem;border-radius:10px;margin-left:.5rem;">
                                    {{ $roomSolutionType->name }}
                                </span>
                                @endif
                            </div>
                            <div class="survey-section__body">

                                {{-- Solution type checklist — shown to engineer as a guide --}}
                                @if(count($checklistLines) > 0 && !$readonly)
                                <div style="background:#EBF8FA;border:1px solid #94C4C9;border-radius:7px;padding:.65rem .85rem;margin-bottom:.85rem;">
                                    <div style="font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#0B3C45;margin-bottom:.4rem;">
                                        📋 Survey Checklist — {{ $roomSolutionType->name }}
                                    </div>
                                    <ul style="margin:0;padding-left:1.1rem;font-size:.82rem;color:#0B3C45;line-height:1.75;">
                                        @foreach($checklistLines as $line)
                                        <li>{{ $line }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                                @endif

                                <div class="form-group" style="margin-bottom:0;">
                                    <label class="form-label">AV Requirements / Scope for this Room</label>
                                    <textarea name="rooms[{{ $ri }}][av_requirements]"
                                              class="form-control" rows="3" maxlength="5000"
                                              {{ $readonly ? 'readonly' : '' }}
                                              placeholder="Describe the AV requirements and scope for this room…">{{ $room->av_requirements }}</textarea>
                                </div>
                            </div>
                        </div>

                        {{-- ═══════════════════════════════════════════════════ --}}
                        {{-- SECTION 3: SITE CONDITIONS                         --}}
                        {{-- ═══════════════════════════════════════════════════ --}}
                        <div class="survey-section survey-section--conditions">
                            <div class="survey-section__hdr">🔌 Site Conditions</div>
                            <div class="survey-section__body">

                                @if(!$readonly)
                                {{-- Tap-card checkboxes --}}
                                <div class="tap-card-row">
                                    <label class="tap-card {{ $room->has_power ? 'is-checked' : '' }}">
                                        <input type="hidden" name="rooms[{{ $ri }}][has_power]" value="0">
                                        <input type="checkbox" name="rooms[{{ $ri }}][has_power]" value="1"
                                               {{ $room->has_power ? 'checked' : '' }}
                                               onchange="syncTapCard(this)">
                                        <span class="tap-card__icon">⚡</span>
                                        <span class="tap-card__label">Power<br>Present</span>
                                    </label>
                                    <label class="tap-card {{ $room->has_network ? 'is-checked' : '' }}">
                                        <input type="hidden" name="rooms[{{ $ri }}][has_network]" value="0">
                                        <input type="checkbox" name="rooms[{{ $ri }}][has_network]" value="1"
                                               {{ $room->has_network ? 'checked' : '' }}
                                               onchange="syncTapCard(this)">
                                        <span class="tap-card__icon">📡</span>
                                        <span class="tap-card__label">Network<br>Present</span>
                                    </label>
                                    <label class="tap-card {{ $room->requires_additional_power ? 'is-checked' : '' }}">
                                        <input type="hidden" name="rooms[{{ $ri }}][requires_additional_power]" value="0">
                                        <input type="checkbox" name="rooms[{{ $ri }}][requires_additional_power]" value="1"
                                               {{ $room->requires_additional_power ? 'checked' : '' }}
                                               onchange="syncTapCard(this)">
                                        <span class="tap-card__icon">➕</span>
                                        <span class="tap-card__label">Extra<br>Power</span>
                                    </label>
                                </div>
                                @else
                                <div class="condition-chips">
                                    <span class="chip {{ $room->has_power ? 'chip--on' : 'chip--off' }}">
                                        ⚡ Power {{ $room->has_power ? 'Present' : 'None' }}
                                    </span>
                                    <span class="chip {{ $room->has_network ? 'chip--on' : 'chip--off' }}">
                                        📡 Network {{ $room->has_network ? 'Present' : 'None' }}
                                    </span>
                                    <span class="chip {{ $room->requires_additional_power ? 'chip--on' : 'chip--off' }}">
                                        ➕ Extra Power {{ $room->requires_additional_power ? 'Needed' : 'Not needed' }}
                                    </span>
                                </div>
                                @endif

                                {{-- Infrastructure measurements accordion --}}
                                @if(!$readonly)
                                <button type="button" class="infra-toggle" onclick="toggleInfra(this)">
                                    ▶ Measurements &amp; Infrastructure
                                </button>
                                @else
                                    @php $hasInfra = $room->room_width_m || $room->room_depth_m || $room->ceiling_type || $room->wall_material; @endphp
                                    @if($hasInfra)
                                    <div style="font-size:.78rem; font-weight:700; color:#7C2D12; text-transform:uppercase; letter-spacing:.05em; margin:.5rem 0 .35rem;">Measurements</div>
                                    @endif
                                @endif
                                <div class="infra-panel {{ $readonly && ($room->room_width_m || $room->ceiling_type) ? 'open' : '' }}">
                                    <div class="form-grid-2">
                                        <div class="form-group">
                                            <label class="form-label">Width (m)</label>
                                            <input type="number" name="rooms[{{ $ri }}][room_width_m]" class="form-control"
                                                   value="{{ $room->room_width_m }}"
                                                   {{ $readonly ? 'readonly' : '' }} min="0" max="999" step="0.01">
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Depth (m)</label>
                                            <input type="number" name="rooms[{{ $ri }}][room_depth_m]" class="form-control"
                                                   value="{{ $room->room_depth_m }}"
                                                   {{ $readonly ? 'readonly' : '' }} min="0" max="999" step="0.01">
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Height (m)</label>
                                            <input type="number" name="rooms[{{ $ri }}][room_height_m]" class="form-control"
                                                   value="{{ $room->room_height_m }}"
                                                   {{ $readonly ? 'readonly' : '' }} min="0" max="99" step="0.01">
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Ceiling Type</label>
                                            <select name="rooms[{{ $ri }}][ceiling_type]" class="form-control" {{ $readonly ? 'disabled' : '' }}>
                                                <option value="">— Select —</option>
                                                @foreach(['concrete' => 'Concrete', 'suspended' => 'Suspended', 'plasterboard' => 'Plasterboard', 'open' => 'Open (exposed)', 'other' => 'Other'] as $val => $label)
                                                    <option value="{{ $val }}" {{ $room->ceiling_type === $val ? 'selected' : '' }}>{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Ceiling Height (m)</label>
                                            <input type="number" name="rooms[{{ $ri }}][ceiling_height_m]" class="form-control"
                                                   value="{{ $room->ceiling_height_m }}"
                                                   {{ $readonly ? 'readonly' : '' }} min="0" max="99" step="0.01">
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Wall Material</label>
                                            <select name="rooms[{{ $ri }}][wall_material]" class="form-control" {{ $readonly ? 'disabled' : '' }}>
                                                <option value="">— Select —</option>
                                                @foreach(['brick' => 'Brick', 'plasterboard' => 'Plasterboard', 'glass' => 'Glass', 'concrete' => 'Concrete', 'other' => 'Other'] as $val => $label)
                                                    <option value="{{ $val }}" {{ $room->wall_material === $val ? 'selected' : '' }}>{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Floor Type</label>
                                            <select name="rooms[{{ $ri }}][floor_type]" class="form-control" {{ $readonly ? 'disabled' : '' }}>
                                                <option value="">— Select —</option>
                                                @foreach(['concrete' => 'Concrete', 'carpet' => 'Carpet', 'tiles' => 'Tiles', 'raised' => 'Raised Access', 'other' => 'Other'] as $val => $label)
                                                    <option value="{{ $val }}" {{ $room->floor_type === $val ? 'selected' : '' }}>{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Power Outlets</label>
                                            <input type="number" name="rooms[{{ $ri }}][power_outlet_count]" class="form-control"
                                                   value="{{ $room->power_outlet_count ?? 0 }}"
                                                   {{ $readonly ? 'readonly' : '' }} min="0" max="999" step="1">
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Network Ports</label>
                                            <input type="number" name="rooms[{{ $ri }}][network_port_count]" class="form-control"
                                                   value="{{ $room->network_port_count ?? 0 }}"
                                                   {{ $readonly ? 'readonly' : '' }} min="0" max="999" step="1">
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Existing Cabling</label>
                                        <textarea name="rooms[{{ $ri }}][existing_cabling]" class="form-control"
                                                  rows="2" maxlength="500"
                                                  {{ $readonly ? 'readonly' : '' }}>{{ $room->existing_cabling }}</textarea>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Existing AV Equipment in Room</label>
                                        <textarea name="rooms[{{ $ri }}][av_equipment_list]" class="form-control"
                                                  rows="2" maxlength="5000"
                                                  {{ $readonly ? 'readonly' : '' }}
                                                  placeholder="List any existing AV equipment already installed…">{{ $room->av_equipment_list }}</textarea>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Access / Hazard Notes</label>
                                        <textarea name="rooms[{{ $ri }}][access_notes]" class="form-control"
                                                  rows="2" maxlength="500"
                                                  {{ $readonly ? 'readonly' : '' }}
                                                  placeholder="Restricted access, heights, asbestos risks, other hazards…">{{ $room->access_notes }}</textarea>
                                    </div>
                                </div>

                            </div>
                        </div>

                        {{-- ── PA System section ────────────────────────────── --}}
                        @if($showPaRm)
                        <div class="survey-section survey-section--pa type-panel type-panel--pa">
                            <div class="survey-section__hdr">🔊 PA System Details</div>
                            <div class="survey-section__body">
                                <div class="form-grid-2">
                                    <div class="form-group">
                                        <label class="form-label">Number of Speakers</label>
                                        <input type="number" name="rooms[{{ $ri }}][speaker_count]" class="form-control"
                                               value="{{ $room->speaker_count }}" min="0" max="999" step="1"
                                               {{ $readonly ? 'readonly' : '' }}>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Speaker Type</label>
                                        <select name="rooms[{{ $ri }}][speaker_type]" class="form-control" {{ $readonly ? 'disabled' : '' }}>
                                            <option value="">— Select —</option>
                                            @foreach(['ceiling'=>'Ceiling (flush)','pendant'=>'Pendant','surface'=>'Surface mount','column'=>'Column array','horn'=>'Horn / outdoor','sub'=>'Subwoofer','line_array'=>'Line array','other'=>'Other'] as $sv => $sl)
                                                <option value="{{ $sv }}" {{ $room->speaker_type === $sv ? 'selected' : '' }}>{{ $sl }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Speaker Mounting</label>
                                        <select name="rooms[{{ $ri }}][speaker_mounting]" class="form-control" {{ $readonly ? 'disabled' : '' }}>
                                            <option value="">— Select —</option>
                                            @foreach(['ceiling_recessed'=>'Ceiling — recessed','ceiling_surface'=>'Ceiling — surface','pendant'=>'Pendant drop','wall'=>'Wall mount','bracket'=>'Bracket / truss','floor_stand'=>'Floor stand','other'=>'Other'] as $sv => $sl)
                                                <option value="{{ $sv }}" {{ $room->speaker_mounting === $sv ? 'selected' : '' }}>{{ $sl }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Background Noise (dB)</label>
                                        <input type="number" name="rooms[{{ $ri }}][bg_noise_db]" class="form-control"
                                               value="{{ $room->bg_noise_db }}" min="0" max="120" step="1"
                                               placeholder="Measured dB(A)" {{ $readonly ? 'readonly' : '' }}>
                                    </div>
                                </div>
                            </div>
                        </div>
                        @endif

                        {{-- ── Signage section ──────────────────────────────── --}}
                        @if($showSignRm)
                        <div class="survey-section survey-section--signage type-panel type-panel--signage">
                            <div class="survey-section__hdr">🖥️ Digital Signage Details</div>
                            <div class="survey-section__body">
                                <div class="form-grid-2">
                                    <div class="form-group">
                                        <label class="form-label">Display Size (inches)</label>
                                        <input type="number" name="rooms[{{ $ri }}][display_size_in]" class="form-control"
                                               value="{{ $room->display_size_in }}" min="0" max="999" step="0.1"
                                               placeholder="e.g. 55, 75, 86" {{ $readonly ? 'readonly' : '' }}>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Orientation</label>
                                        <select name="rooms[{{ $ri }}][display_orient]" class="form-control" {{ $readonly ? 'disabled' : '' }}>
                                            <option value="">— Select —</option>
                                            <option value="landscape" {{ $room->display_orient === 'landscape' ? 'selected' : '' }}>Landscape</option>
                                            <option value="portrait"  {{ $room->display_orient === 'portrait'  ? 'selected' : '' }}>Portrait</option>
                                        </select>
                                    </div>
                                    <div class="form-group" style="grid-column:1/-1;">
                                        <label class="form-label">Mounting Type</label>
                                        <select name="rooms[{{ $ri }}][display_mounting]" class="form-control" {{ $readonly ? 'disabled' : '' }}>
                                            <option value="">— Select —</option>
                                            @foreach(['wall_flush'=>'Wall — flush / fixed','wall_tilt'=>'Wall — tilt / articulating','ceiling'=>'Ceiling drop mount','floor_stand'=>'Floor stand / totem','desk_stand'=>'Desk / counter stand','other'=>'Other'] as $dv => $dl)
                                                <option value="{{ $dv }}" {{ $room->display_mounting === $dv ? 'selected' : '' }}>{{ $dl }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                        @endif

                        {{-- ── Upgrade / strip-out section ──────────────────── --}}
                        @if($showUpgRm)
                        <div class="survey-section survey-section--upgrade type-panel type-panel--upgrade">
                            <div class="survey-section__hdr">🔧 Upgrade / Strip-out Details</div>
                            <div class="survey-section__body">
                                <div class="form-group">
                                    <label class="form-label">Existing Equipment Condition</label>
                                    <textarea name="rooms[{{ $ri }}][existing_condition]" class="form-control"
                                              rows="2" maxlength="3000" {{ $readonly ? 'readonly' : '' }}
                                              placeholder="Describe the condition of existing AV equipment…">{{ $room->existing_condition }}</textarea>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Items to Remove / Strip Out</label>
                                    <textarea name="rooms[{{ $ri }}][items_to_remove]" class="form-control"
                                              rows="2" maxlength="3000" {{ $readonly ? 'readonly' : '' }}
                                              placeholder="List equipment to be decommissioned and removed…">{{ $room->items_to_remove }}</textarea>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Items to Retain / Reuse</label>
                                    <textarea name="rooms[{{ $ri }}][items_to_retain]" class="form-control"
                                              rows="2" maxlength="3000" {{ $readonly ? 'readonly' : '' }}
                                              placeholder="List equipment that will be kept and integrated into the new system…">{{ $room->items_to_retain }}</textarea>
                                </div>
                            </div>
                        </div>
                        @endif

                        {{-- ═══════════════════════════════════════════════════ --}}
                        {{-- SECTION 4: NOTES & PHOTOS                          --}}
                        {{-- ═══════════════════════════════════════════════════ --}}
                        <div class="survey-section survey-section--notes">
                            <div class="survey-section__hdr">📝 Notes &amp; Photos</div>
                            <div class="survey-section__body">

                                <div class="form-group">
                                    <label class="form-label">Other Notes</label>
                                    <textarea name="rooms[{{ $ri }}][notes]" class="form-control"
                                              rows="2" maxlength="500"
                                              {{ $readonly ? 'readonly' : '' }}>{{ $room->notes }}</textarea>
                                </div>

                                {{-- Photos --}}
                                @if($room->photos->isNotEmpty() || !$readonly)
                                <hr class="divider">
                                <div class="form-label" style="margin-bottom:.45rem;">Photos</div>
                                <div class="photo-grid" id="photo-grid-{{ $room->id }}">
                                    @foreach($room->photos->sortBy('sort_order') as $photo)
                                        <div class="photo-thumb" title="{{ $photo->original_name }}">
                                            <img src="{{ route('survey.photos.serve', ['token' => $token, 'photo' => $photo->id]) }}"
                                                 alt="{{ $photo->original_name }}"
                                                 loading="lazy">
                                        </div>
                                    @endforeach
                                </div>
                                @if(!$readonly)
                                <div style="margin-top:.45rem;">
                                    <label class="photo-upload-btn" for="photo-input-{{ $room->id }}">
                                        📷 Add Photo
                                    </label>
                                    <input type="file" accept="image/*" capture="environment"
                                           class="photo-file-input"
                                           id="photo-input-{{ $room->id }}"
                                           data-room-id="{{ $room->id }}"
                                           data-upload-url="{{ route('survey.photos.upload', ['token' => $token, 'room' => $room->id]) }}"
                                           onchange="handlePhotoUpload(this)">
                                    <div class="photo-upload-status" id="photo-status-{{ $room->id }}"
                                         style="font-size:.78rem; color:#6B7280; margin-top:.3rem;"></div>
                                </div>
                                @endif
                                @endif

                            </div>
                        </div>

                        {{-- ── Mark Room Complete / Update / Undo buttons ─── --}}
                        @if(!$readonly)
                        @php
                            $completeUrl   = route('survey.room.complete',   ['token' => $token, 'room' => $room->id]);
                            $uncompleteUrl = route('survey.room.uncomplete', ['token' => $token, 'room' => $room->id]);
                        @endphp
                        <div id="complete-area-{{ $room->id }}">
                            @if($roomComplete)
                            {{-- Already complete: show Update + Undo side by side --}}
                            <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.25rem;">
                                <button type="button"
                                        class="btn-complete"
                                        style="flex:1;background:#0369a1;border-color:#0369a1;"
                                        onclick="completeRoom({{ $room->id }}, '{{ $completeUrl }}', this)">
                                    💾 Update Room Data
                                </button>
                                <button type="button"
                                        class="btn-undo-complete"
                                        onclick="uncompleteRoom({{ $room->id }}, '{{ $uncompleteUrl }}')">
                                    ↩ Undo
                                </button>
                            </div>
                            @else
                            <button type="button"
                                    class="btn-complete"
                                    onclick="completeRoom({{ $room->id }}, '{{ $completeUrl }}', this)">
                                ✓ Mark Room Complete
                            </button>
                            @endif
                        </div>
                        @endif

                    </div>{{-- /room-body --}}
                </div>{{-- /room-card --}}
            @endforeach

        </form>{{-- /survey-form --}}
    @endif

    @if(!$readonly && $rooms->isNotEmpty())
    {{-- Hidden submit form for final submission --}}
    <form id="submit-form" method="POST" action="{{ route('survey.submit', $token) }}" style="display:none;">
        @csrf
    </form>
    @endif

</div>{{-- /wrap --}}

{{-- Sticky action bar --}}
@if(!$readonly && $rooms->isNotEmpty())
<div class="action-bar">
    <button type="button" class="btn btn-outline" onclick="submitForm('save')" id="btn-save">
        💾 Save Draft
    </button>
    <button type="button" class="btn btn-teal" onclick="confirmSubmit()" id="btn-submit">
        ✓ Submit Survey
    </button>
</div>
@endif

@if(!$readonly)
<script>
    // ── Tap-card checkbox fallback for browsers without :has ─────────────
    function syncTapCard(checkbox) {
        const card = checkbox.closest('.tap-card');
        if (!card) return;
        card.classList.toggle('is-checked', checkbox.checked);
    }

    // ── Per-space type change (scoped to that card only) ──────────────────
    function onSpaceTypeChange(sel) {
        const card = sel.closest('.room-card');
        const type = sel.value;
        const showPa      = type === 'pa_system'  || type === 'mixed';
        const showSignage = type === 'signage'     || type === 'mixed';
        const showUpgrade = type === 'upgrade'     || type === 'mixed';
        const showAreaType = type !== 'general';

        card.querySelectorAll('.type-panel--pa').forEach(el => el.style.display = showPa ? 'block' : 'none');
        card.querySelectorAll('.type-panel--signage').forEach(el => el.style.display = showSignage ? 'block' : 'none');
        card.querySelectorAll('.type-panel--upgrade').forEach(el => el.style.display = showUpgrade ? 'block' : 'none');
        card.querySelectorAll('.area-type-group').forEach(el => el.style.display = showAreaType ? 'block' : 'none');
    }

    // ── Room collapse/expand ───────────────────────────────────────────────
    function toggleRoom(roomId) {
        const body    = document.getElementById('room-body-' + roomId);
        const toggle  = document.getElementById('room-toggle-' + roomId);
        const collapsed = body.classList.toggle('collapsed');
        if (collapsed) {
            toggle.classList.remove('open');
            toggle.textContent = '▼';
        } else {
            toggle.classList.add('open');
            toggle.textContent = '▼';
        }
    }

    // Start all rooms collapsed — expand the first incomplete one automatically
    document.addEventListener('DOMContentLoaded', () => {
        let expandedOne = false;
        document.querySelectorAll('.room-card').forEach(card => {
            const body    = card.querySelector('.room-body');
            const toggle  = card.querySelector('.room-card__chevron');
            const hdr     = card.querySelector('.room-card__header');
            const isGreen = hdr && hdr.classList.contains('room-card__header--complete');
            if (!expandedOne && !isGreen && body) {
                body.classList.remove('collapsed');
                if (toggle) toggle.classList.add('open');
                expandedOne = true;
            }
        });
    });

    // ── Infrastructure accordion ───────────────────────────────────────────
    function toggleInfra(btn) {
        const panel = btn.nextElementSibling;
        const open  = panel.classList.toggle('open');
        btn.textContent = (open ? '▼' : '▶') + ' Measurements & Infrastructure';
    }

    // ── Kit list drawer ───────────────────────────────────────────────────
    function toggleKit(btn) {
        const drawer  = btn.nextElementSibling;
        const chevron = btn.querySelector('.kit-chevron');
        const isOpen  = drawer.style.maxHeight && drawer.style.maxHeight !== '0px';
        drawer.style.maxHeight  = isOpen ? '0' : '600px';
        chevron.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(180deg)';
    }

    // ── Pre-Install Checks drawer ─────────────────────────────────────────
    function toggleChecks(btn) {
        const drawer  = btn.nextElementSibling;
        const chevron = btn.querySelector('.checks-chevron');
        const isOpen  = drawer.style.maxHeight && drawer.style.maxHeight !== '0px';
        drawer.style.maxHeight  = isOpen ? '0' : '800px';
        chevron.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(180deg)';
        btn.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
    }

    function answerCheck(questionId, answer, roomId, token) {
        const itemEl   = document.getElementById('check-' + questionId);
        if (!itemEl) return;
        const btns     = itemEl.querySelectorAll('.check-btn');
        const otherDiv = itemEl.querySelector('.check-other-text');

        // Loading state
        btns.forEach(b => b.classList.add('loading'));
        btns.forEach(b => b.disabled = true);

        fetch(itemEl.dataset.answerUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify({ answer: answer }),
        })
        .then(r => r.json())
        .then(data => {
            btns.forEach(b => {
                b.classList.remove('loading', 'is-yes', 'is-no', 'is-other');
                b.disabled = false;
            });
            const selectedBtn = itemEl.querySelector('[data-answer="' + answer + '"]');
            if (selectedBtn) selectedBtn.classList.add('is-' + answer);
            if (answer === 'other') {
                if (otherDiv) otherDiv.style.display = 'block';
            } else {
                if (otherDiv) otherDiv.style.display = 'none';
            }
            updateCheckProgress(itemEl.closest('.checks-drawer-inner'));
        })
        .catch(() => {
            btns.forEach(b => { b.classList.remove('loading'); b.disabled = false; });
            const errEl = itemEl.querySelector('.check-error');
            if (errEl) {
                errEl.textContent = 'Could not save your answer. Please check your connection and try again.';
                errEl.style.display = 'block';
            }
        });
    }

    function saveOtherText(questionId, textarea) {
        const itemEl = document.getElementById('check-' + questionId);
        if (!itemEl) return;
        fetch(itemEl.dataset.answerUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify({ other_text: textarea.value }),
        }).catch(() => { /* silent failure per UI-SPEC */ });
    }

    function updateCheckProgress(drawerInner) {
        if (!drawerInner) return;
        const items = drawerInner.querySelectorAll('.check-item');
        let answeredItems = 0;
        items.forEach(item => {
            if (item.querySelector('.check-btn.is-yes, .check-btn.is-no, .check-btn.is-other')) {
                answeredItems++;
            }
        });
        const progEl = drawerInner.querySelector('.check-progress');
        if (!progEl) return;
        if (answeredItems >= items.length) {
            progEl.style.display = 'none';
        } else {
            progEl.textContent  = answeredItems + ' of ' + items.length + ' answered';
            progEl.style.display = 'block';
        }
    }

    // ── Mark room complete (AJAX, saves data + marks complete) ────────────
    function completeRoom(roomId, url, btn) {
        const card            = document.getElementById('room-card-' + roomId);
        const wasAlreadyDone  = document.getElementById('room-hdr-' + roomId)
                                    ?.classList.contains('room-card__header--complete') ?? false;
        const formData = new FormData();
        formData.append('_token', document.querySelector('meta[name="csrf-token"]').content);

        // Gather all inputs/selects/textareas inside this room body
        card.querySelectorAll('input, select, textarea').forEach(el => {
            if (! el.name) return;
            if (el.type === 'checkbox') {
                // Include the hidden sibling (value=0) and the checkbox only if checked
                if (el.type === 'checkbox' && ! el.checked) return;
            }
            formData.append(el.name, el.value);
        });

        // Also copy the survey header fields
        ['survey_date','surveyor_name','general_notes','site_risks','access_constraints','h_and_s_notes'].forEach(f => {
            const el = document.getElementById(f) || document.getElementById('hf_' + f);
            if (el) formData.append(f, el.value);
        });

        btn.disabled = true;
        btn.textContent = 'Saving…';

        fetch(url, { method: 'POST', body: formData })
        .then(r => r.ok ? r.json() : Promise.reject(r))
        .then(() => {
            // Update header to green
            const hdr   = document.getElementById('room-hdr-' + roomId);
            const badge = document.getElementById('room-badge-' + roomId);
            hdr.classList.remove('room-card__header--inprogress');
            hdr.classList.add('room-card__header--complete');
            badge.textContent = '✓ Complete';
            badge.className   = 'room-status-badge room-status-badge--complete';

            // Update progress bar
            updateProgressBar();

            // Show "Update Room Data" + "Undo" buttons side by side
            const uncompleteUrl = url.replace('/complete', '/uncomplete');
            const area = document.getElementById('complete-area-' + roomId);
            area.innerHTML = `
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.25rem;">
                    <button type="button" class="btn-complete"
                            style="flex:1;background:#0369a1;border-color:#0369a1;"
                            onclick="completeRoom(${roomId}, '${url}', this)">
                        💾 Update Room Data
                    </button>
                    <button type="button" class="btn-undo-complete"
                            onclick="uncompleteRoom(${roomId}, '${uncompleteUrl}')">
                        ↩ Undo
                    </button>
                </div>`;

            // Auto-collapse only when first marking complete (not on "Update Room")
            if (!wasAlreadyDone) {
                const body   = document.getElementById('room-body-' + roomId);
                const toggle = document.getElementById('room-toggle-' + roomId);
                body.classList.add('collapsed');
                if (toggle) toggle.classList.remove('open');
            }
        })
        .catch(() => {
            btn.disabled    = false;
            btn.textContent = wasAlreadyDone ? '💾 Update Room Data' : '✓ Mark Room Complete';
            alert('Could not save. Please check your connection and try again.');
        });
    }

    function uncompleteRoom(roomId, url) {
        const formData = new FormData();
        formData.append('_token', document.querySelector('meta[name="csrf-token"]').content);

        fetch(url, { method: 'POST', body: formData })
        .then(r => r.ok ? r.json() : Promise.reject(r))
        .then(() => {
            const hdr   = document.getElementById('room-hdr-' + roomId);
            const badge = document.getElementById('room-badge-' + roomId);
            hdr.classList.remove('room-card__header--complete');
            hdr.classList.add('room-card__header--inprogress');
            badge.textContent = 'In Progress';
            badge.className   = 'room-status-badge room-status-badge--inprogress';

            // Update progress bar
            updateProgressBar();

            const area       = document.getElementById('complete-area-' + roomId);
            const completeUrl = url.replace('/uncomplete', '/complete');
            area.innerHTML = `<button type="button" class="btn-complete"
                onclick="completeRoom(${roomId}, '${completeUrl}', this)">
                ✓ Mark Room Complete</button>`;
        })
        .catch(() => alert('Could not update. Please try again.'));
    }

    // ── Live progress bar updater ─────────────────────────────────────────
    function updateProgressBar() {
        const total     = document.querySelectorAll('.room-card').length;
        const completed = document.querySelectorAll('.room-card__header--complete').length;
        const pct       = total > 0 ? Math.round(completed / total * 100) : 0;
        const fill      = document.getElementById('progress-fill');
        const label     = fill && fill.closest('.survey-header__progress')?.querySelector('.progress-label');
        if (fill) fill.style.width = pct + '%';
        if (label) label.textContent = completed + ' of ' + total + ' rooms complete';
    }

    // ── Copy header fields into main form before submit ────────────────────
    function syncHeaderFields() {
        ['survey_date','surveyor_name','general_notes','site_risks','access_constraints','h_and_s_notes'].forEach(f => {
            const src  = document.getElementById(f);
            const dest = document.getElementById('hf_' + f);
            if (src && dest) dest.value = src.value;
        });
    }

    // ── Submit main form (save draft) ──────────────────────────────────────
    function submitForm(action) {
        syncHeaderFields();
        const form = document.getElementById('survey-form');
        form.action = action === 'save'
            ? '{{ route('survey.save', $token) }}'
            : '{{ route('survey.submit', $token) }}';
        form.submit();
    }

    // ── Confirm + submit final survey ──────────────────────────────────────
    function confirmSubmit() {
        const name = document.getElementById('surveyor_name')?.value?.trim();
        if (!name) {
            alert('Please enter your name before submitting the survey.');
            document.getElementById('surveyor_name')?.focus();
            return;
        }
        if (!confirm('Submit this survey? You will not be able to edit it after submission.')) {
            return;
        }
        syncHeaderFields();
        const mainForm   = document.getElementById('survey-form');
        const submitForm = document.getElementById('submit-form');

        // Copy all inputs from survey-form into the hidden submit-form.
        const fd = new FormData(mainForm);
        for (const [key, value] of fd.entries()) {
            const hidden = document.createElement('input');
            hidden.type  = 'hidden';
            hidden.name  = key;
            hidden.value = value;
            submitForm.appendChild(hidden);
        }
        submitForm.submit();
    }

    // ── Photo upload ───────────────────────────────────────────────────────
    async function handlePhotoUpload(input) {
        const roomId    = input.dataset.roomId;
        const uploadUrl = input.dataset.uploadUrl;
        const statusEl  = document.getElementById('photo-status-' + roomId);
        const grid      = document.getElementById('photo-grid-' + roomId);
        const file      = input.files[0];

        if (!file) return;

        statusEl.textContent = 'Uploading…';

        const fd = new FormData();
        fd.append('photo', file);
        fd.append('_token', document.querySelector('meta[name="csrf-token"]').content);

        try {
            const resp = await fetch(uploadUrl, { method: 'POST', body: fd });
            if (!resp.ok) throw new Error('Upload failed (' + resp.status + ')');
            const data = await resp.json();

            // Append thumbnail to the grid.
            const thumb = document.createElement('div');
            thumb.className = 'photo-thumb';
            thumb.innerHTML = '<img src="' + data.url + '" alt="' + data.original_name + '" loading="lazy">';
            grid.appendChild(thumb);

            statusEl.textContent = '✓ Photo added.';
            setTimeout(() => statusEl.textContent = '', 2500);
        } catch (e) {
            statusEl.textContent = '⚠ Upload failed. Please try again.';
        }

        input.value = '';
    }
</script>
@endif

</body>
</html>
