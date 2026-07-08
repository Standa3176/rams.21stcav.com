@extends('layouts.app')

@section('title', 'Design System')

@push('styles')
<style>
/*
 * Design system reference gallery — internal contributor doc.
 * Every widget on this page is composed from the shared tokens in
 * layouts/app.blade.php :root — no bespoke hex, no per-page overrides.
 * If a colour or shape reads wrong here, the token is wrong; fix once
 * and the whole app follows.
 *
 * Route: /design (admin-only, auth-gated).
 */

.dg-section { margin-bottom: 40px; }
.dg-section-title {
    font-size: 20px;
    font-weight: 700;
    color: var(--ink-900);
    letter-spacing: -0.02em;
    margin-bottom: 4px;
}
.dg-section-desc {
    color: var(--text-muted);
    font-size: 13px;
    max-width: 640px;
    margin-bottom: 20px;
    line-height: 1.5;
}
.dg-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: var(--muted);
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    margin-bottom: 10px;
}
.dg-eyebrow::before {
    content: "";
    width: 16px; height: 1px;
    background: var(--hairline-strong);
}

.dg-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-card);
    padding: 20px;
}
.dg-card + .dg-card { margin-top: 12px; }

.dg-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
}
.dg-row + .dg-row { margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--rule); }

/* Palette swatches */
.dg-swatch-grid { display: grid; grid-template-columns: repeat(6, 1fr); gap: 10px; }
.dg-swatch { background: var(--card); border: 1px solid var(--border); border-radius: 6px; overflow: hidden; }
.dg-swatch-chip { height: 56px; border-bottom: 1px solid var(--border); }
.dg-swatch-body { padding: 8px 10px; }
.dg-swatch-name { color: var(--ink-900); font-size: 11px; font-weight: 600; letter-spacing: -0.005em; }
.dg-swatch-value { color: var(--text-muted); font-size: 10px; font-family: var(--font-mono); margin-top: 1px; }

/* Type ramp */
.dg-type-ramp { display: grid; gap: 14px; }
.dg-type-row {
    display: grid;
    grid-template-columns: 100px 1fr 120px;
    gap: 16px;
    align-items: baseline;
    padding-bottom: 12px;
    border-bottom: 1px dashed var(--rule);
}
.dg-type-row:last-child { border-bottom: none; padding-bottom: 0; }
.dg-type-label { color: var(--muted); font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; }
.dg-type-sample { color: var(--ink-900); }
.dg-type-spec { color: var(--muted); font-size: 11px; font-family: var(--font-mono); text-align: right; }

/* Focus ring demo */
.dg-focus-demo input {
    padding: 8px 12px;
    border: 1px solid var(--hairline-strong);
    border-radius: 6px;
    font-family: inherit;
    font-size: 13px;
    color: var(--ink-900);
    background: var(--surface);
    transition: border-color 120ms, box-shadow 120ms;
}
.dg-focus-demo input:focus {
    outline: none;
    border-color: var(--teal-500);
    box-shadow: var(--shadow-focus);
}

/* KPI demo — direct mount of dashboard/stat-card semantics */
.dg-kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; }
.dg-kpi {
    padding: 16px 18px;
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-card);
    display: flex; align-items: flex-start; justify-content: space-between; gap: 12px;
}
.dg-kpi-label { color: var(--muted); font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; }
.dg-kpi-value { color: var(--ink-900); font-size: 26px; font-weight: 700; letter-spacing: -0.025em; line-height: 1; margin-top: 6px; font-variant-numeric: tabular-nums; }
.dg-kpi-sub { color: var(--text-muted); font-size: 11px; margin-top: 4px; }
.dg-kpi-icon {
    width: 40px; height: 40px; border-radius: 8px;
    display: grid; place-items: center;
    flex-shrink: 0;
}

/* Sparkline SVG */
.dg-spark-card { display: flex; align-items: center; gap: 16px; padding: 14px 18px; }
.dg-radial { position: relative; width: 68px; height: 68px; flex-shrink: 0; }
.dg-radial svg { width: 100%; height: 100%; transform: rotate(-90deg); }
.dg-radial-bg { stroke: var(--border); }
.dg-radial-fg { stroke: var(--success); stroke-linecap: round; }
.dg-radial-value {
    position: absolute; inset: 0; display: grid; place-items: center;
    color: var(--ink-900); font-size: 16px; font-weight: 700; font-variant-numeric: tabular-nums;
}

/* Progress bar */
.dg-progress-bar {
    height: 6px;
    background: var(--ink-100);
    border-radius: 999px;
    overflow: hidden;
    max-width: 320px;
}
.dg-progress-fill {
    height: 100%;
    background: linear-gradient(90deg, var(--teal-500), var(--teal-700));
    border-radius: 999px;
    box-shadow: 0 0 6px 0 color-mix(in oklab, var(--teal-500) 40%, transparent);
}

/* Sidebar nav item mock */
.dg-nav-item {
    position: relative;
    display: flex; align-items: center; gap: 10px;
    padding: 7px 12px;
    border-radius: 6px;
    color: var(--body);
    font-size: 12px;
    font-weight: 500;
    text-decoration: none;
}
.dg-nav-item.active { background: var(--sidebar-active-bg); color: var(--sidebar-active-fg); font-weight: 600; }
.dg-nav-item.active::before {
    content: "";
    position: absolute; left: -10px; top: 5px; bottom: 5px; width: 2px;
    background: var(--teal-700); border-radius: 0 2px 2px 0;
}

/* Diff row */
.dg-diff {
    padding: 10px 14px;
    background: var(--surface);
    border-radius: 6px;
    border-left: 3px solid var(--border);
    font-size: 12px;
    color: var(--body);
}
.dg-diff.modified {
    border-left-color: var(--warning);
    background: color-mix(in oklab, var(--warning) 6%, var(--surface));
}
.dg-diff.added {
    border-left-color: var(--success);
    background: color-mix(in oklab, var(--success) 6%, var(--surface));
}
.dg-diff.removed {
    border-left-color: var(--danger);
    background: color-mix(in oklab, var(--danger) 6%, var(--surface));
    text-decoration: line-through;
    opacity: 0.7;
}
.dg-diff-stack { display: grid; gap: 6px; max-width: 480px; }

/* Doc-kind chip (mirrors ⌘K palette) */
.dg-doc-kind {
    display: inline-grid; place-items: center;
    width: 26px; height: 26px;
    border-radius: 6px;
    color: #fff;
    font-size: 11px; font-weight: 700; letter-spacing: -0.02em;
}
.dg-doc-kind.k-project   { background: linear-gradient(135deg, var(--teal-500), var(--teal-700)); }
.dg-doc-kind.k-rams      { background: linear-gradient(135deg, #38BDF8, #0284C7); }
.dg-doc-kind.k-survey    { background: linear-gradient(135deg, #A78BFA, #7C3AED); }
.dg-doc-kind.k-om        { background: linear-gradient(135deg, #FBBF24, #D97706); }
.dg-doc-kind.k-worksheet { background: linear-gradient(135deg, #34D399, #059669); }

/* Split layout for wide previews */
.dg-split { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
@media (max-width: 900px) {
    .dg-split { grid-template-columns: 1fr; }
    .dg-kpi-grid { grid-template-columns: repeat(2, 1fr); }
    .dg-swatch-grid { grid-template-columns: repeat(3, 1fr); }
}
</style>
@endpush

@section('content')

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">Design System</h1>
        <div class="page-subtitle">
            Every primitive, colour, and pattern used across the RAMS Platform.
            Compose new screens from these; if a token reads wrong here, fix
            it in <code style="font-family:var(--font-mono);font-size:12px;background:var(--surface-soft);padding:1px 5px;border-radius:4px;">layouts/app.blade.php :root</code>
            and the whole app follows.
        </div>
    </div>
    <div class="page-header-actions">
        <a href="/dashboard" class="btn btn-outline btn-sm">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M19 12H5M12 19l-7-7 7-7"/>
            </svg>
            Back to Dashboard
        </a>
    </div>
</div>

{{-- ═══════════ Palette ═══════════ --}}
<section class="dg-section">
    <div class="dg-eyebrow">1. Palette</div>
    <h2 class="dg-section-title">Colour tokens</h2>
    <p class="dg-section-desc">
        Cool slate neutrals + indigo brand + semantic status. Every screen inherits these
        through the <code style="font-family:var(--font-mono);">--*</code> CSS variables in the layout.
    </p>

    <h3 style="font-size:13px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:10px;">Neutrals</h3>
    <div class="dg-swatch-grid">
        @foreach ([
            ['ink',          '#0B1220', 'ink'],
            ['ink-2',        '#1E293B', 'ink-2'],
            ['body',         '#334155', 'body'],
            ['muted',        '#64748B', 'muted'],
            ['subtle',       '#94A3B8', 'subtle'],
            ['hairline-strong','#CBD5E1', 'hairline-strong'],
            ['hairline',     '#E2E8F0', 'hairline'],
            ['hairline-soft','#EEF2F7', 'hairline-soft'],
            ['canvas-soft',  '#F1F5F9', 'canvas-soft'],
            ['canvas',       '#F6F8FB', 'canvas'],
            ['card',         '#FFFFFF', 'card'],
            ['sidebar',      '#FBFBFD', 'sidebar'],
        ] as [$name, $hex, $var])
            <div class="dg-swatch">
                <div class="dg-swatch-chip" style="background:{{ $hex }};@if(in_array($name,['card','sidebar','canvas','canvas-soft','hairline-soft']))border-right:1px solid var(--border);@endif"></div>
                <div class="dg-swatch-body">
                    <div class="dg-swatch-name">{{ $name }}</div>
                    <div class="dg-swatch-value">{{ $hex }}</div>
                </div>
            </div>
        @endforeach
    </div>

    <h3 style="font-size:13px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.06em;margin:24px 0 10px;">Brand (indigo)</h3>
    <div class="dg-swatch-grid">
        @foreach ([
            ['brand-50',  '#EEF2FF'],
            ['brand-100', '#E0E7FF'],
            ['brand-500', '#6366F1'],
            ['brand-600', '#4F46E5'],
            ['brand-700', '#4338CA'],
            ['brand-800', '#3730A3'],
        ] as [$name, $hex])
            <div class="dg-swatch">
                <div class="dg-swatch-chip" style="background:{{ $hex }};"></div>
                <div class="dg-swatch-body">
                    <div class="dg-swatch-name">{{ $name }}</div>
                    <div class="dg-swatch-value">{{ $hex }}</div>
                </div>
            </div>
        @endforeach
    </div>

    <h3 style="font-size:13px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.06em;margin:24px 0 10px;">Semantic status</h3>
    <div class="dg-swatch-grid">
        @foreach ([
            ['success',       '#059669'],
            ['success-light', '#ECFDF5'],
            ['warning',       '#D97706'],
            ['warning-light', '#FEF3C7'],
            ['danger',        '#DC2626'],
            ['danger-light',  '#FEE2E2'],
        ] as [$name, $hex])
            <div class="dg-swatch">
                <div class="dg-swatch-chip" style="background:{{ $hex }};@if(str_contains($name,'-light'))border-right:1px solid var(--border);@endif"></div>
                <div class="dg-swatch-body">
                    <div class="dg-swatch-name">{{ $name }}</div>
                    <div class="dg-swatch-value">{{ $hex }}</div>
                </div>
            </div>
        @endforeach
    </div>
</section>

{{-- ═══════════ Typography ═══════════ --}}
<section class="dg-section">
    <div class="dg-eyebrow">2. Typography</div>
    <h2 class="dg-section-title">Inter Variable</h2>
    <p class="dg-section-desc">
        Self-hosted via <code style="font-family:var(--font-mono);">@fontsource-variable/inter</code>.
        Font-feature-settings <code style="font-family:var(--font-mono);">'cv11','ss01','ss03','zero'</code>
        applied globally — open-loop g, alternate a, slashed 0, cursive 6/9.
    </p>

    <div class="dg-card">
        <div class="dg-type-ramp">
            <div class="dg-type-row">
                <div class="dg-type-label">Hero</div>
                <div class="dg-type-sample" style="font-size:30px;font-weight:700;letter-spacing:-0.02em;">Volkswagen Group boardroom refresh</div>
                <div class="dg-type-spec">30 / 700 / -0.02em</div>
            </div>
            <div class="dg-type-row">
                <div class="dg-type-label">Page title</div>
                <div class="dg-type-sample" style="font-size:24px;font-weight:700;letter-spacing:-0.02em;">Design System</div>
                <div class="dg-type-spec">24 / 700 / -0.02em</div>
            </div>
            <div class="dg-type-row">
                <div class="dg-type-label">Section</div>
                <div class="dg-type-sample" style="font-size:17px;font-weight:700;letter-spacing:-0.015em;">Project details</div>
                <div class="dg-type-spec">17 / 700 / -0.015em</div>
            </div>
            <div class="dg-type-row">
                <div class="dg-type-label">Card</div>
                <div class="dg-type-sample" style="font-size:14px;font-weight:600;">Recent activity</div>
                <div class="dg-type-spec">14 / 600</div>
            </div>
            <div class="dg-type-row">
                <div class="dg-type-label">Body</div>
                <div class="dg-type-sample" style="font-size:13px;font-weight:400;line-height:1.5;">Boardroom + 4× huddle refresh — LG 65" displays, ceiling mics, DSP with room control.</div>
                <div class="dg-type-spec">13 / 400 / 1.5</div>
            </div>
            <div class="dg-type-row">
                <div class="dg-type-label">Label</div>
                <div class="dg-type-sample" style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.06em;color:var(--muted);">Active projects</div>
                <div class="dg-type-spec">10 / 600 uppercase</div>
            </div>
            <div class="dg-type-row">
                <div class="dg-type-label">Data</div>
                <div class="dg-type-sample tabular" style="font-size:26px;font-weight:700;letter-spacing:-0.025em;">£84,720.00</div>
                <div class="dg-type-spec">26 / 700 / tabular</div>
            </div>
            <div class="dg-type-row">
                <div class="dg-type-label">Mono</div>
                <div class="dg-type-sample" style="font-family:var(--font-mono);font-size:12px;">21CQ30698</div>
                <div class="dg-type-spec">12 / mono</div>
            </div>
        </div>
    </div>
</section>

{{-- ═══════════ Buttons ═══════════ --}}
<section class="dg-section">
    <div class="dg-eyebrow">3. Buttons</div>
    <h2 class="dg-section-title">Action variants</h2>
    <p class="dg-section-desc">
        Every variant lives in the shared <code style="font-family:var(--font-mono);">.btn</code> scale in the layout.
        Prefix with <code style="font-family:var(--font-mono);">.btn-sm</code> for compact rows.
    </p>

    <div class="dg-card">
        <div class="dg-row">
            <button class="btn btn-teal">Primary · brand-600</button>
            <button class="btn btn-outline">Outline · hairline</button>
            <button class="btn btn-ghost">Ghost · transparent</button>
            <button class="btn btn-danger">Destructive</button>
            <button class="btn btn-danger-outline">Danger outline</button>
        </div>
        <div class="dg-row">
            <button class="btn btn-teal btn-sm">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                New Project
            </button>
            <button class="btn btn-outline btn-sm">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
                Download
            </button>
            <button class="btn btn-outline btn-sm">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                Refresh
            </button>
            <button class="btn btn-danger-outline btn-sm">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M6 6l1 14a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-14"/></svg>
                Delete
            </button>
        </div>
    </div>
</section>

{{-- ═══════════ Status pills ═══════════ --}}
<section class="dg-section">
    <div class="dg-eyebrow">4. Status pills</div>
    <h2 class="dg-section-title">Badge treatment</h2>
    <p class="dg-section-desc">
        Rounded 999px + hairline border + tinted background + 5px coloured dot.
        Same shape across Users, RAMS, Cable Schedules and dropdowns.
    </p>

    <div class="dg-card">
        <div class="dg-row">
            @foreach ([
                ['brand', 'In Progress'],
                ['success', 'Approved'],
                ['warning', 'Draft'],
                ['info', 'Awaiting client'],
                ['danger', 'Failed'],
                ['violet', 'In Review'],
                ['muted', 'Not started'],
            ] as [$kind, $label])
                <span class="badge badge-{{ $kind }}" style="display:inline-flex;align-items:center;gap:5px;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:500;border:1px solid transparent;
                    @if($kind==='brand')  background:var(--brand-50);color:var(--brand-700);border-color:var(--brand-100);
                    @elseif($kind==='success')background:var(--success-light);color:var(--success);border-color:color-mix(in oklab, var(--success) 30%, transparent);
                    @elseif($kind==='warning')background:var(--warning-light);color:#92400E;border-color:color-mix(in oklab, var(--warning) 30%, transparent);
                    @elseif($kind==='info')   background:#F0F9FF;color:#0369A1;border-color:#BAE6FD;
                    @elseif($kind==='danger') background:var(--danger-light);color:#991B1B;border-color:color-mix(in oklab, var(--danger) 30%, transparent);
                    @elseif($kind==='violet') background:#F5F3FF;color:#7C3AED;border-color:color-mix(in oklab, #8B5CF6 30%, transparent);
                    @else                     background:var(--surface-soft);color:var(--text-muted);border-color:var(--border);
                    @endif">
                    <span style="width:5px;height:5px;border-radius:50%;background:currentColor;"></span>{{ $label }}
                </span>
            @endforeach
        </div>
    </div>
</section>

{{-- ═══════════ Form controls ═══════════ --}}
<section class="dg-section">
    <div class="dg-eyebrow">5. Form controls</div>
    <h2 class="dg-section-title">Inputs, selects, focus</h2>
    <p class="dg-section-desc">
        Focus rings use the shared <code style="font-family:var(--font-mono);">--shadow-focus</code> token
        — indigo at 24% for a soft glow that reads as accessible without shouting.
    </p>

    <div class="dg-card">
        <div class="dg-split">
            <div class="form-group">
                <label class="form-label" for="dg-input-a">Client name</label>
                <input class="form-control" id="dg-input-a" type="text" value="Volkswagen Group" placeholder="e.g. Acme Boardroom">
                <div class="form-help">Renders on the RAMS cover page and every generated document.</div>
            </div>
            <div class="form-group">
                <label class="form-label" for="dg-select">Solution Type</label>
                <select class="form-control" id="dg-select">
                    <option>Conferencing (Teams / Zoom Room)</option>
                    <option>BYOD Conferencing</option>
                    <option>Split / Divisible Room</option>
                </select>
            </div>
        </div>

        <div class="dg-focus-demo" style="margin-top:16px;">
            <div class="dg-eyebrow">Focus ring — click into either input</div>
            <div class="dg-row">
                <input type="text" placeholder="Standalone input" style="width:280px;">
                <input type="text" placeholder="Tabular numerals" class="tabular" value="21CQ30698" style="width:180px;font-family:var(--font-mono);">
            </div>
        </div>
    </div>
</section>

{{-- ═══════════ KPI cards + charts ═══════════ --}}
<section class="dg-section">
    <div class="dg-eyebrow">6. KPI + data visualisation</div>
    <h2 class="dg-section-title">Stat cards, sparklines, radial score</h2>
    <p class="dg-section-desc">
        KPI numbers stay in ink; the accent lives in the icon tile or trend line.
        Four stats read as a rank-ordered set, not four competing colours.
    </p>

    <div class="dg-kpi-grid">
        <div class="dg-kpi">
            <div>
                <div class="dg-kpi-label">Active projects</div>
                <div class="dg-kpi-value">27</div>
                <div class="dg-kpi-sub" style="color:var(--success);">+3 this week</div>
            </div>
            <div class="dg-kpi-icon" style="background:var(--brand-50);color:var(--brand-600);">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18"/></svg>
            </div>
        </div>
        <div class="dg-kpi">
            <div>
                <div class="dg-kpi-label">RAMS in draft</div>
                <div class="dg-kpi-value">8</div>
                <div class="dg-kpi-sub" style="color:var(--warning);">2 awaiting review</div>
            </div>
            <div class="dg-kpi-icon" style="background:#F0F9FF;color:#0284C7;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
            </div>
        </div>
        <div class="dg-kpi">
            <div>
                <div class="dg-kpi-label">O&amp;M pending</div>
                <div class="dg-kpi-value">4</div>
                <div class="dg-kpi-sub">All on schedule</div>
            </div>
            <div class="dg-kpi-icon" style="background:#F5F3FF;color:#7C3AED;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
            </div>
        </div>
        <div class="dg-kpi">
            <div>
                <div class="dg-kpi-label">Signed this month</div>
                <div class="dg-kpi-value">12</div>
                <div class="dg-kpi-sub" style="color:var(--success);">+40% vs last</div>
            </div>
            <div class="dg-kpi-icon" style="background:var(--success-light);color:var(--success);">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
            </div>
        </div>
    </div>

    <div class="dg-card dg-spark-card" style="margin-top:16px;">
        <div class="dg-radial">
            <svg viewBox="0 0 100 100">
                <circle class="dg-radial-bg" cx="50" cy="50" r="42" fill="none" stroke-width="8"/>
                <circle class="dg-radial-fg" cx="50" cy="50" r="42" fill="none" stroke-width="8"
                        stroke-dasharray="264" stroke-dashoffset="32"/>
            </svg>
            <div class="dg-radial-value">88%</div>
        </div>
        <div style="min-width:0;">
            <div style="color:var(--ink-900);font-size:13px;font-weight:600;">Health Score</div>
            <div style="color:var(--text-muted);font-size:11px;margin-top:1px;">7-day trend · above target (85%)</div>
            <svg width="200" height="30" viewBox="0 0 200 30" fill="none" style="margin-top:6px;">
                <defs>
                    <linearGradient id="dg-spark-fill" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="#10B981" stop-opacity="0.28"/>
                        <stop offset="100%" stop-color="#10B981" stop-opacity="0"/>
                    </linearGradient>
                </defs>
                <path d="M0 22 L15 20 L30 24 L45 18 L60 15 L75 17 L90 12 L105 10 L120 14 L135 8 L150 6 L165 9 L200 4"
                      stroke="var(--success)" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M0 22 L15 20 L30 24 L45 18 L60 15 L75 17 L90 12 L105 10 L120 14 L135 8 L150 6 L165 9 L200 4 L200 30 L0 30 Z"
                      fill="url(#dg-spark-fill)"/>
                <circle cx="200" cy="4" r="2.5" fill="var(--success)"/>
            </svg>
        </div>
    </div>

    <div class="dg-card" style="margin-top:16px;">
        <div class="dg-eyebrow">Progress bar</div>
        <div class="dg-progress-bar">
            <div class="dg-progress-fill" style="width:78%;"></div>
        </div>
        <div style="color:var(--text-muted);font-size:11px;margin-top:6px;font-variant-numeric:tabular-nums;">78% · Delivery progress</div>
    </div>
</section>

{{-- ═══════════ Nav / doc-kind chips ═══════════ --}}
<section class="dg-section">
    <div class="dg-eyebrow">7. Navigation + doc-kind chips</div>
    <h2 class="dg-section-title">Sidebar rail + ⌘K palette hints</h2>

    <div class="dg-split">
        <div class="dg-card">
            <div class="dg-eyebrow">Sidebar nav item</div>
            <div style="display:grid;gap:2px;">
                <a class="dg-nav-item">Dashboard</a>
                <a class="dg-nav-item">Projects</a>
                <a class="dg-nav-item active">RAMS <span style="margin-left:auto;background:var(--card);color:var(--brand-700);font-size:10px;font-weight:600;padding:0 5px;border-radius:4px;">7</span></a>
                <a class="dg-nav-item">Site Surveys</a>
            </div>
        </div>

        <div class="dg-card">
            <div class="dg-eyebrow">Doc-kind chips (used by ⌘K palette)</div>
            <div class="dg-row">
                <div class="dg-doc-kind k-project">P</div>
                <div class="dg-doc-kind k-rams">R</div>
                <div class="dg-doc-kind k-survey">S</div>
                <div class="dg-doc-kind k-om">O</div>
                <div class="dg-doc-kind k-worksheet">W</div>
            </div>
            <p style="color:var(--text-muted);font-size:11px;margin-top:12px;">
                Each kind gets a distinct gradient so results are scannable at 26px.
            </p>
        </div>
    </div>
</section>

{{-- ═══════════ Diff treatments ═══════════ --}}
<section class="dg-section">
    <div class="dg-eyebrow">8. Diff row treatments</div>
    <h2 class="dg-section-title">Modified / added / removed</h2>
    <p class="dg-section-desc">
        Used across RAMS review and elsewhere to show what changed between AI-generated
        content and the reviewed state. Ultra-light <code style="font-family:var(--font-mono);">color-mix()</code>
        row backgrounds so the row reads as "changed" without a shout.
    </p>

    <div class="dg-diff-stack">
        <div class="dg-diff modified">
            <strong style="color:var(--ink-900);">Method statement</strong> · phase 2 modified
        </div>
        <div class="dg-diff added">
            <strong style="color:var(--ink-900);">Hazard</strong> · working at height added
        </div>
        <div class="dg-diff removed">
            <strong style="color:var(--ink-900);">Person at risk</strong> · site visitor removed
        </div>
        <div class="dg-diff">
            <strong style="color:var(--ink-900);">PPE</strong> · hard hat (unchanged)
        </div>
    </div>
</section>

{{-- ═══════════ Card treatments ═══════════ --}}
<section class="dg-section">
    <div class="dg-eyebrow">9. Card treatments</div>
    <h2 class="dg-section-title">Card, card-hover, lift</h2>

    <div class="dg-split">
        <div>
            <div class="dg-eyebrow">Card (default)</div>
            <div style="background:var(--card);border:1px solid var(--border);border-radius:var(--radius-lg);box-shadow:var(--shadow-card);padding:16px;">
                <div style="color:var(--ink-900);font-weight:600;font-size:13px;">Project Summary</div>
                <div style="color:var(--text-muted);font-size:12px;margin-top:4px;">Hairline border + shadow-card. Standard section container.</div>
            </div>
        </div>
        <div>
            <div class="dg-eyebrow">Lift (modals, palette)</div>
            <div style="background:var(--card);border:1px solid var(--border);border-radius:var(--radius-lg);box-shadow:var(--shadow-lg);padding:16px;">
                <div style="color:var(--ink-900);font-weight:600;font-size:13px;">⌘K global search</div>
                <div style="color:var(--text-muted);font-size:12px;margin-top:4px;">Uses shadow-lg — for elements that float above the page.</div>
            </div>
        </div>
    </div>
</section>

{{-- ═══════════ Footer ═══════════ --}}
<div style="color:var(--muted);font-size:11px;margin-top:40px;padding-top:20px;border-top:1px solid var(--border);">
    Design system reference · tokens live in <code style="font-family:var(--font-mono);">layouts/app.blade.php :root</code> and <code style="font-family:var(--font-mono);">tailwind.config.js</code>.
    Route: <code style="font-family:var(--font-mono);">GET /design</code> (admin only).
</div>

@endsection
