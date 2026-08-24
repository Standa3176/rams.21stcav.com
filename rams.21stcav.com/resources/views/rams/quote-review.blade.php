@extends('layouts.app')

@section('title', 'Review Extracted Data — ' . ($rams->project_name ?: 'New RAMS'))

@push('styles')
<style>
/* ── Review page specifics ─────────────────────────────────────────── */
.review-section {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow-sm);
    margin-bottom: 1.5rem;
}
.review-section-header {
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}
.review-section-header h2 {
    font-size: 1rem;
    font-weight: 600;
    color: var(--text);
    margin: 0;
}
.review-section-body {
    padding: 1.25rem;
}
.review-grid-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}
@media (max-width: 640px) {
    .review-grid-2 { grid-template-columns: 1fr; }
}

/* ── Repeater tables ───────────────────────────────────────────────── */
.repeater-table {
    width: 100%;
    border-collapse: collapse;
    font-size: .875rem;
}
.repeater-table th {
    background: var(--teal-light);
    color: var(--teal);
    font-weight: 600;
    font-size: .8rem;
    text-transform: uppercase;
    letter-spacing: .03em;
    padding: .5rem .75rem;
    text-align: left;
    border-bottom: 1px solid var(--teal-mid);
}
.repeater-table td {
    padding: .45rem .5rem;
    vertical-align: top;
    border-bottom: 1px solid var(--border);
}
.repeater-table tr:last-child td { border-bottom: none; }
.repeater-table input[type="text"],
.repeater-table input[type="number"],
.repeater-table select,
.repeater-table textarea {
    width: 100%;
    padding: .35rem .5rem;
    border: 1px solid #d1d5db;
    border-radius: 5px;
    font-size: .875rem;
    font-family: inherit;
    background: #fff;
    transition: border-color var(--transition);
}
.repeater-table input:focus,
.repeater-table select:focus,
.repeater-table textarea:focus {
    outline: none;
    border-color: var(--teal);
    box-shadow: var(--shadow-focus);
}
.repeater-table textarea { resize: vertical; min-height: 60px; }
.col-qty   { width: 70px; }
.col-area  { width: 150px; }
.col-score { width: 58px; }
.col-act   { width: 140px; }
.col-del   { width: 40px; text-align: center; }

/* ── Phase 26-05 (HAZ-04): needs-confirmation hazard rows ────────────── */
.badge-needs-confirmation {
    margin-left: .5rem;
    font-size: .7rem;
    vertical-align: middle;
    white-space: nowrap;
}
.hazard-needs-confirmation {
    border-left: 3px solid #f59e0b !important;
    background: #fffbeb !important;
}

/* ── PPE checkboxes ────────────────────────────────────────────────── */
.ppe-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: .5rem;
}
.ppe-check {
    display: flex;
    align-items: center;
    gap: .5rem;
    font-size: .875rem;
    padding: .35rem .5rem;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: background var(--transition), border-color var(--transition);
}
.ppe-check:hover { background: var(--teal-light); border-color: var(--teal-mid); }
.ppe-check input[type="checkbox"] { cursor: pointer; }

/* ── Access checkboxes ─────────────────────────────────────────────── */
.access-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: .5rem;
}

/* ── Confidence badge ──────────────────────────────────────────────── */
.confidence-alert {
    background: #fffbeb;
    border: 1px solid #f59e0b;
    border-radius: var(--radius-sm);
    padding: .75rem 1rem;
    font-size: .875rem;
    color: #92400e;
    display: flex;
    gap: .625rem;
    align-items: flex-start;
    margin-bottom: 1.5rem;
}
.confidence-icon { font-size: 1.1rem; flex-shrink: 0; }

/* ── Action bar ────────────────────────────────────────────────────── */
.action-bar {
    position: sticky;
    bottom: 0;
    background: var(--surface);
    border-top: 1px solid var(--border);
    padding: 1rem 1.25rem;
    display: flex;
    align-items: center;
    gap: .75rem;
    justify-content: flex-end;
    z-index: 50;
    box-shadow: 0 -2px 8px rgba(0,0,0,.07);
}

/* ── Remove button ─────────────────────────────────────────────────── */
.btn-remove {
    background: none;
    border: none;
    color: #ef4444;
    cursor: pointer;
    font-size: 1rem;
    line-height: 1;
    padding: .25rem;
    border-radius: 3px;
    transition: background var(--transition);
}
.btn-remove:hover { background: #fee2e2; }

/* ── Status badge overrides for pipeline statuses ──────────────────── */
.badge-warning { background: #fffbeb; color: #92400e; border: 1px solid #f59e0b; }
.badge-green   { background: #f0fdf4; color: #166534; border: 1px solid #86efac; }
.badge-red     { background: #fef2f2; color: #991b1b; border: 1px solid #fca5a5; }

/* ── Diff highlighting ────────────────────────────────────────────── */
.diff-modified { border-left: 3px solid #f59e0b !important; background: #fffdf5 !important; }
.diff-added    { border-left: 3px solid #22c55e !important; background: #f7fef9 !important; }
.diff-removed  { border-left: 3px solid #ef4444 !important; background: #fefafa !important; }
.diff-hint {
    font-size: .75rem;
    margin-top: .25rem;
    padding: .2rem .5rem;
    border-radius: 3px;
    display: inline-block;
}
.diff-hint-modified { color: #92400e; background: #fef3c7; }
.diff-hint-added    { color: #166534; background: #dcfce7; }
.diff-hint-removed  { color: #991b1b; background: #fee2e2; }
.diff-legend {
    display: flex; gap: .75rem; align-items: center;
    padding: .5rem .75rem; font-size: .8125rem;
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius-sm); margin-bottom: 1rem;
}
.diff-legend-dot {
    width: 10px; height: 10px; border-radius: 2px; display: inline-block;
    margin-right: .3rem; vertical-align: middle;
}
.diff-summary {
    background: #eff6ff; border: 1px solid #bfdbfe; border-radius: var(--radius-sm);
    padding: .6rem 1rem; font-size: .8125rem; color: #1e40af;
    margin-bottom: 1rem; display: flex; gap: .75rem; align-items: center;
}
</style>
@endpush

@section('content')

{{-- Page header --}}
<div class="page-header">
    <div>
        <h1 class="page-title">Review Extracted Data</h1>
        <p style="color:var(--text-muted);font-size:.875rem;margin-top:.25rem;">
            Review and correct the data extracted from your quote PDF before generating the RAMS document.
        </p>
    </div>
    <div style="display:flex;gap:.5rem;align-items:center;">
        <span class="badge {{ $rams->statusBadgeClass() }}">{{ $rams->statusLabel() }}</span>
        @if ($rams->project_id && $rams->project)
            <a href="{{ route('projects.show', $rams->project_id) }}" class="btn btn-outline btn-sm">← Back to Project</a>
        @else
            <a href="{{ route('projects.index') }}" class="btn btn-outline btn-sm">← Back to Projects</a>
        @endif
    </div>
</div>

{{-- Flash messages --}}
@if (session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if (session('error'))
    <div class="alert alert-error">{{ session('error') }}</div>
@endif

{{-- Validation errors --}}
@if ($errors->any())
    <div class="alert alert-error">
        <strong>Please fix the following errors:</strong>
        <ul style="margin:.5rem 0 0 1.25rem;padding:0;">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

{{-- Low confidence warning --}}
@php
    $confidence = $reviewPayload['meta']['parser_confidence'] ?? null;
    $isLowConfidence = $confidence !== null && $confidence < 0.5;
@endphp
@if ($isLowConfidence)
    <div class="confidence-alert">
        <span class="confidence-icon">⚠️</span>
        <div>
            <strong>Low parser confidence ({{ round($confidence * 100) }}%)</strong> — the system had difficulty
            reading this PDF. Please review all extracted data carefully, correct any errors, and ensure
            all equipment, activities, and hazards are accurate before approving.
        </div>
    </div>
@elseif ($confidence !== null)
    <div style="background:var(--teal-light);border:1px solid var(--teal-mid);border-radius:var(--radius-sm);padding:.6rem 1rem;font-size:.8125rem;color:#0f5460;margin-bottom:1.25rem;display:flex;gap:.5rem;align-items:center;">
        <span>✓</span>
        <span>Parser confidence: <strong>{{ round($confidence * 100) }}%</strong> — data looks good. Review below and approve when ready.</span>
    </div>
@endif

{{-- ── Diff helper + legend ──────────────────────────────────────────── --}}
@php
    use App\Services\Rams\RamsDiffService;

    // Helper: check if a field has a change in the diff
    $fieldChanged = function (string $field) use ($diff) {
        return RamsDiffService::fieldChange($diff ?? [], $field);
    };
    // Helper: CSS class for a change type
    $diffClass = function (?array $change): string {
        if (! $change) return '';
        return 'diff-' . ($change['type'] ?? '');
    };
    // Helper: hint label for a change
    $diffHint = function (?array $change): string {
        if (! $change) return '';
        $type = $change['type'] ?? '';
        $old  = $change['old'] ?? '';
        if (is_array($old)) $old = json_encode($old);
        $old = e(\Illuminate\Support\Str::limit((string) $old, 80));
        return match ($type) {
            'modified' => $old !== '' ? "Changed from: {$old}" : 'Modified',
            'added'    => 'New — not in extracted data',
            'removed'  => 'Removed from extracted data',
            default    => '',
        };
    };
@endphp

@if (! empty($diff['changes']))
    <div class="diff-summary">
        <span>📋</span>
        <span>
            <strong>{{ $diff['summary']['total'] }}</strong> change{{ $diff['summary']['total'] !== 1 ? 's' : '' }} detected between extracted and reviewed data
            @if ($diff['summary']['added'] > 0)
                — <strong>{{ $diff['summary']['added'] }}</strong> added
            @endif
            @if ($diff['summary']['modified'] > 0)
                — <strong>{{ $diff['summary']['modified'] }}</strong> modified
            @endif
            @if ($diff['summary']['removed'] > 0)
                — <strong>{{ $diff['summary']['removed'] }}</strong> removed
            @endif
        </span>
    </div>
    <div class="diff-legend">
        <span><span class="diff-legend-dot" style="background:#22c55e;"></span> Added</span>
        <span><span class="diff-legend-dot" style="background:#f59e0b;"></span> Modified</span>
        <span><span class="diff-legend-dot" style="background:#ef4444;"></span> Removed</span>
        <span style="color:var(--text-muted);margin-left:auto;font-size:.75rem;">Fields with changes are highlighted below</span>
    </div>
@endif

{{-- ================================================================== --}}
{{-- MAIN FORM                                                           --}}
{{-- ================================================================== --}}
{{-- Single form. The "Save Review" button posts to the save endpoint.  --}}
{{-- The "Approve & Generate" button posts to the approve endpoint.     --}}
{{-- JavaScript sets the action dynamically based on which button was   --}}
{{-- clicked — no Livewire or Alpine required.                          --}}
{{-- ================================================================== --}}

<form id="review-form" method="POST" action="{{ route('rams.approve', $rams) }}" novalidate>
    @csrf

    {{-- ── 1. Project Details ──────────────────────────────────────── --}}
    <div class="review-section">
        <div class="review-section-header">
            <h2>1. Project Details</h2>
        </div>
        <div class="review-section-body">
            <div class="review-grid-2">
                @php $c_pname = $fieldChanged('project.project_name'); @endphp
                <div class="form-group {{ $diffClass($c_pname) }}">
                    <label class="form-label">
                        Project Name <span style="color:var(--danger)">*</span>
                    </label>
                    <input type="text"
                           name="project[project_name]"
                           class="form-control @error('project.project_name') is-invalid @enderror"
                           value="{{ old('project.project_name', $reviewPayload['project']['project_name']) }}"
                           maxlength="255"
                           required>
                    @error('project.project_name')
                        <p class="form-error">{{ $message }}</p>
                    @enderror
                    @if ($c_pname)
                        <span class="diff-hint diff-hint-{{ $c_pname['type'] }}">{{ $diffHint($c_pname) }}</span>
                    @endif
                </div>
                @php $c_ref = $fieldChanged('project.quote_ref'); @endphp
                <div class="form-group {{ $diffClass($c_ref) }}">
                    <label class="form-label">Quote / Project Ref</label>
                    <input type="text"
                           name="project[quote_ref]"
                           class="form-control"
                           value="{{ old('project.quote_ref', $reviewPayload['project']['quote_ref']) }}"
                           maxlength="100">
                    @if ($c_ref)
                        <span class="diff-hint diff-hint-{{ $c_ref['type'] }}">{{ $diffHint($c_ref) }}</span>
                    @endif
                </div>
                @php $c_client = $fieldChanged('project.client_name'); @endphp
                <div class="form-group {{ $diffClass($c_client) }}">
                    <label class="form-label">Client Name</label>
                    <input type="text"
                           name="project[client_name]"
                           class="form-control"
                           value="{{ old('project.client_name', $reviewPayload['project']['client_name']) }}"
                           maxlength="255">
                    @if ($c_client)
                        <span class="diff-hint diff-hint-{{ $c_client['type'] }}">{{ $diffHint($c_client) }}</span>
                    @endif
                </div>
                @php $c_sname = $fieldChanged('project.site_name'); @endphp
                <div class="form-group {{ $diffClass($c_sname) }}">
                    <label class="form-label">Site Name</label>
                    <input type="text"
                           name="project[site_name]"
                           class="form-control"
                           value="{{ old('project.site_name', $reviewPayload['project']['site_name']) }}"
                           maxlength="255">
                    @if ($c_sname)
                        <span class="diff-hint diff-hint-{{ $c_sname['type'] }}">{{ $diffHint($c_sname) }}</span>
                    @endif
                </div>
                @php $c_saddr = $fieldChanged('project.site_address'); @endphp
                <div class="form-group {{ $diffClass($c_saddr) }}" style="grid-column:span 2;">
                    <label class="form-label">Site Address</label>
                    <input type="text"
                           name="project[site_address]"
                           class="form-control"
                           value="{{ old('project.site_address', $reviewPayload['project']['site_address']) }}"
                           maxlength="500">
                    @if ($c_saddr)
                        <span class="diff-hint diff-hint-{{ $c_saddr['type'] }}">{{ $diffHint($c_saddr) }}</span>
                    @endif
                </div>
                @php $c_scontact = $fieldChanged('project.site_contact'); @endphp
                <div class="form-group {{ $diffClass($c_scontact) }}">
                    <label class="form-label">Site Contact</label>
                    <input type="text"
                           name="project[site_contact]"
                           class="form-control"
                           value="{{ old('project.site_contact', $reviewPayload['project']['site_contact'] ?? '') }}"
                           maxlength="200">
                    @if ($c_scontact)
                        <span class="diff-hint diff-hint-{{ $c_scontact['type'] }}">{{ $diffHint($c_scontact) }}</span>
                    @endif
                </div>
                @php $c_prepby = $fieldChanged('project.prepared_by'); @endphp
                <div class="form-group {{ $diffClass($c_prepby) }}">
                    <label class="form-label">Prepared By</label>
                    <input type="text"
                           name="project[prepared_by]"
                           class="form-control"
                           value="{{ old('project.prepared_by', $reviewPayload['project']['prepared_by']) }}"
                           maxlength="255">
                    @if ($c_prepby)
                        <span class="diff-hint diff-hint-{{ $c_prepby['type'] }}">{{ $diffHint($c_prepby) }}</span>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- ── 2. Operations Info ─────────────────────────────────────── --}}
    <div class="review-section">
        <div class="review-section-header">
            <h2>2. Operations Info</h2>
        </div>
        <div class="review-section-body">
            <div class="review-grid-2">
                <div class="form-group">
                    <label class="form-label">Project Manager</label>
                    <input type="text"
                           name="project[project_manager]"
                           class="form-control"
                           value="{{ old('project.project_manager', $reviewPayload['project']['project_manager'] ?? '') }}"
                           maxlength="200">
                </div>
                <div class="form-group">
                    <label class="form-label">Lead Engineer</label>
                    <input type="text"
                           name="project[lead_engineer]"
                           class="form-control"
                           value="{{ old('project.lead_engineer', $reviewPayload['project']['lead_engineer'] ?? '') }}"
                           maxlength="200">
                </div>
                <div class="form-group" style="grid-column:span 2;">
                    <label class="form-label">Additional Engineer(s)</label>
                    <input type="text"
                           name="project[additional_engineers]"
                           class="form-control"
                           value="{{ old('project.additional_engineers', $reviewPayload['project']['additional_engineers'] ?? '') }}"
                           maxlength="500">
                </div>
                <div class="form-group">
                    <label class="form-label">Programmer</label>
                    <input type="text"
                           name="project[programmer]"
                           class="form-control"
                           value="{{ old('project.programmer', $reviewPayload['project']['programmer'] ?? '') }}"
                           maxlength="200">
                </div>
            </div>
        </div>
    </div>

    {{-- ── 3. Equipment ────────────────────────────────────────────── --}}
    <div class="review-section">
        <div class="review-section-header">
            <h2>3. Equipment</h2>
            <span style="font-size:.78rem;color:var(--text-muted);">
                Categorised lists — only Hardware feeds RAMS &amp; O&amp;M.
            </span>
        </div>
        <div class="review-section-body" style="padding:0;overflow:hidden;">
            <p style="padding:.75rem 1.25rem;font-size:.8125rem;color:var(--text-muted);border-bottom:1px solid var(--border);margin:0;">
                Categorise each line item. Only items marked <strong>Hardware</strong> will appear in RAMS &amp; O&amp;M lists.
            </p>
            @error('equipment')
                <p class="form-error" style="padding:.75rem 1.25rem;">{{ $message }}</p>
            @enderror
            @php
                $categoryOptions = [
                    'hardware'    => 'Hardware',
                    'cables'      => 'Cables',
                    'consumables' => 'Consumables',
                    'services'    => 'Services / Professional',
                    'option'      => 'Option (Optional Items)',
                ];
                $rawEquipment = session()->hasOldInput()
                    ? (old('equipment', []) ?? [])
                    : ($reviewPayload['equipment'] ?? []);

                $equipmentRows = [];
                foreach ($rawEquipment as $i => $item) {
                    $equipmentRows[] = [
                        'idx'  => $i,
                        'item' => $item,
                    ];
                }

                $equipmentByCategory = [
                    'hardware'    => [],
                    'cables'      => [],
                    'consumables' => [],
                    'services'    => [],
                    'option'      => [],
                ];

                foreach ($equipmentRows as $row) {
                    $item = $row['item'];
                    $cat  = strtolower((string) ($item['category'] ?? ''));
                    if ($cat === '' || ! array_key_exists($cat, $equipmentByCategory)) {
                        // Auto-detect category from description/part number for records
                        // that pre-date category extraction in the draft builder.
                        $desc = strtolower(($item['name'] ?? '') . ' ' . ($item['part_number'] ?? ''));
                        if (str_contains($desc, 'optional') || str_contains($desc, 'option')) {
                            $cat = 'option';
                        } elseif (preg_match('/\b(?:cable|cat6a?|cat5|hdmi|sdi|utp|ftp|stp|patch\s+lead|usb|fibre|fiber|rg6|rg59)\b/', $desc)) {
                            $cat = 'cables';
                        } elseif (preg_match('/\b(?:install(?:ation)?|commission|configuration|programming|labour|support|survey|management|training)\b/', $desc)) {
                            $cat = 'services';
                        } elseif (preg_match('/\b(?:consumable|fixing|fastener|rawlplug|anchor|screw|bolt|tape|label|cleat|tie|strap)\b/', $desc)) {
                            $cat = 'consumables';
                        } else {
                            $cat = 'hardware';
                        }
                    }
                    $equipmentByCategory[$cat][] = $row;
                }

                $roomOverviewMap = [];
                foreach (($reviewPayload['room_overviews'] ?? []) as $ro) {
                    $name = trim((string) ($ro['room'] ?? ''));
                    if ($name !== '') {
                        $roomOverviewMap[$name] = $ro;
                    }
                }

                $roomNames = [];
                foreach ($equipmentRows as $row) {
                    $room = trim((string) ($row['item']['area'] ?? ''));
                    if ($room === '') { $room = 'General'; }
                    $roomNames[$room] = true;
                }
                foreach (array_keys($roomOverviewMap) as $roomName) {
                    $roomNames[$roomName] = true;
                }
                $roomNames = array_keys($roomNames);
                sort($roomNames, SORT_NATURAL | SORT_FLAG_CASE);
            @endphp

            @if (! empty($roomNames))
                <div style="padding:1rem 1.25rem; border-bottom:1px solid var(--border); background:#fbfcfd; display:flex; align-items:center; justify-content:space-between; gap:.75rem;">
                    <div>
                        <strong style="color:#0f5460;">Room / Space Overviews</strong>
                        <p style="margin:.35rem 0 0;font-size:.78rem;color:var(--text-muted);">
                            Add a client-friendly overview for each space. AI summary updates when you Generate RAMS.
                        </p>
                    </div>
                    <button type="button" id="btn-gen-ai-summary" class="btn btn-outline btn-sm">
                        Gen AI Summary
                    </button>
                </div>
                <table class="repeater-table" style="margin-bottom:.75rem;">
                    <thead>
                        <tr>
                            <th style="width:180px;">Room / Space</th>
                            <th>Overview (Editable)</th>
                            <th>AI Summary (Auto)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($roomNames as $roomIdx => $roomName)
                            @php
                                $roomData = $roomOverviewMap[$roomName] ?? ['room' => $roomName, 'overview' => '', 'summary' => ''];
                            @endphp
                            <tr>
                                <td style="font-weight:600;color:#0f5460;">{{ $roomName }}</td>
                                <td>
                                    <input type="hidden" name="room_overviews[{{ $roomIdx }}][room]" value="{{ $roomName }}">
                                    <textarea name="room_overviews[{{ $roomIdx }}][overview]"
                                              rows="2"
                                              placeholder="Short client-facing summary of works in this room…">{{ old("room_overviews.{$roomIdx}.overview", $roomData['overview'] ?? '') }}</textarea>
                                </td>
                                <td>
                                    <textarea name="room_overviews[{{ $roomIdx }}][summary]"
                                              rows="2"
                                              readonly
                                              placeholder="AI summary will appear after you Generate RAMS…">{{ old("room_overviews.{$roomIdx}.summary", $roomData['summary'] ?? '') }}</textarea>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            @foreach ($categoryOptions as $catKey => $catLabel)
                <div style="padding:1rem 1.25rem; border-bottom:1px solid var(--border); background:#fbfcfd; display:flex; align-items:center; justify-content:space-between;">
                    <strong style="color:#0f5460;">{{ $catLabel }}</strong>
                    <button type="button" class="btn btn-outline btn-sm"
                            onclick="addRow('equipment-tbody-{{ $catKey }}', equipmentRowTemplate, '{{ $catKey }}')">
                        + Add {{ $catLabel }}
                    </button>
                </div>
                <table class="repeater-table">
                    <thead>
                        <tr>
                            <th class="col-qty">Qty</th>
                            <th style="width:140px;">Part Number</th>
                            <th>Equipment / Item Description</th>
                            <th style="width:150px;">Category</th>
                            <th class="col-area">Title / Section</th>
                            <th class="col-del"></th>
                        </tr>
                    </thead>
                    <tbody id="equipment-tbody-{{ $catKey }}">
                        @php
                            $rowsForCat = $equipmentByCategory[$catKey] ?? [];
                            $rowsByRoom = [];
                            foreach ($rowsForCat as $row) {
                                $item = $row['item'];
                                $room = trim((string) ($item['area'] ?? ''));
                                if ($room === '') { $room = 'General'; }
                                $rowsByRoom[$room][] = $row;
                            }
                            ksort($rowsByRoom, SORT_NATURAL | SORT_FLAG_CASE);
                        @endphp
                        @forelse ($rowsByRoom as $roomName => $roomRows)
                            <tr data-room-row="1" style="background:#f7fafb;">
                                <td colspan="6" style="font-weight:600;color:#0f5460;padding:.5rem .75rem;border-bottom:1px solid var(--border);">
                                    {{ $roomName }}
                                </td>
                            </tr>
                            @foreach ($roomRows as $row)
                                @php
                                    $i    = $row['idx'];
                                    $item = $row['item'];
                                    $selectedCategory = old("equipment.{$i}.category", $item['category'] ?? $catKey);
                                @endphp
                                <tr data-equip-row="1">
                                <td class="col-qty">
                                    <input type="number"
                                           name="equipment[{{ $i }}][quantity]"
                                           value="{{ old("equipment.{$i}.quantity", $item['quantity'] ?? 1) }}"
                                           min="1" max="999">
                                </td>
                                <td style="width:140px;">
                                    <input type="text"
                                           name="equipment[{{ $i }}][part_number]"
                                           value="{{ old("equipment.{$i}.part_number", $item['part_number'] ?? '') }}"
                                           placeholder="e.g. YEA-MVC-S90"
                                           maxlength="60"
                                           style="font-family:monospace;font-size:.82rem;text-transform:uppercase;"
                                           oninput="this.value=this.value.toUpperCase()">
                                </td>
                                <td>
                                    <input type="text"
                                           name="equipment[{{ $i }}][name]"
                                           value="{{ old("equipment.{$i}.name", $item['name'] ?? '') }}"
                                           placeholder="e.g. 55&quot; Samsung Display"
                                           maxlength="500">
                                    @error("equipment.{$i}.name")
                                        <p class="form-error" style="font-size:.75rem;">{{ $message }}</p>
                                    @enderror
                                </td>
                                <td style="width:150px;">
                                <select name="equipment[{{ $i }}][category]" data-equip-category>
                                        @foreach ($categoryOptions as $value => $label)
                                            <option value="{{ $value }}" {{ $selectedCategory === $value ? 'selected' : '' }}>
                                                {{ $label }}
                                            </option>
                                        @endforeach
                                    </select>
                                </td>
                                <td class="col-area">
                                    <input type="text"
                                           name="equipment[{{ $i }}][area]"
                                           value="{{ old("equipment.{$i}.area", $item['area'] ?? '') }}"
                                           placeholder="e.g. Meeting Room 1"
                                           maxlength="150"
                                           style="font-size:.82rem;">
                                </td>
                                <td class="col-del">
                                    <button type="button" class="btn-remove" onclick="removeRow(this)" title="Remove">✕</button>
                                </td>
                                </tr>
                            @endforeach
                            <tr data-room-row="1"><td colspan="6" style="height:6px;border:0;"></td></tr>
                        @empty
                            <tr data-empty-row="1">
                                <td colspan="6" style="color:#888;font-size:.82rem;padding:.75rem 1rem;">
                                    No items in this category yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            @endforeach
        </div>
    </div>

    {{-- ── 3. Activities ───────────────────────────────────────────── --}}
    <div class="review-section">
        <div class="review-section-header">
            <h2>4. Work Activities</h2>
            <button type="button" class="btn btn-outline btn-sm" onclick="addRow('activities-tbody', activityRowTemplate)">
                + Add Row
            </button>
        </div>
        <div class="review-section-body" style="padding:0;overflow:hidden;">
            @error('activities')
                <p class="form-error" style="padding:.75rem 1.25rem;">{{ $message }}</p>
            @enderror
            <table class="repeater-table">
                <thead>
                    <tr>
                        <th style="width:200px;">Activity Key</th>
                        <th>Activity Label / Description</th>
                        <th class="col-del"></th>
                    </tr>
                </thead>
                <tbody id="activities-tbody">
                    @foreach ($reviewPayload['activities'] as $i => $activity)
                        <tr>
                            <td>
                                <input type="text"
                                       name="activities[{{ $i }}][key]"
                                       value="{{ old("activities.{$i}.key", $activity['key']) }}"
                                       placeholder="e.g. display_installation"
                                       maxlength="100"
                                       style="font-family:monospace;font-size:.8rem;">
                            </td>
                            <td>
                                <input type="text"
                                       name="activities[{{ $i }}][label]"
                                       value="{{ old("activities.{$i}.label", $activity['label']) }}"
                                       placeholder="e.g. Display & Screen Installation"
                                       maxlength="255">
                            </td>
                            <td class="col-del">
                                <button type="button" class="btn-remove" onclick="removeRow(this)" title="Remove">✕</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- ── 4. Hazards ──────────────────────────────────────────────── --}}
    <div class="review-section">
        <div class="review-section-header">
            <h2>5. Hazards</h2>
            <button type="button" class="btn btn-outline btn-sm" onclick="addRow('hazards-tbody', hazardRowTemplate)">
                + Add Row
            </button>
        </div>
        <div class="review-section-body" style="padding:0;overflow:hidden;">
            <p style="padding:.75rem 1.25rem;font-size:.8125rem;color:var(--text-muted);border-bottom:1px solid var(--border);margin:0;">
                Enter one control measure per line in the Control Measures column.
            </p>
            <table class="repeater-table">
                <thead>
                    <tr>
                        <th class="col-act">Activity</th>
                        <th>Hazard</th>
                        <th class="col-score" title="Initial Likelihood (1-5)">Init L</th>
                        <th class="col-score" title="Initial Severity (1-5)">Init S</th>
                        <th class="col-score" title="Residual Likelihood (1-5)">Res L</th>
                        <th class="col-score" title="Residual Severity (1-5)">Res S</th>
                        <th>Control Measures <span style="font-weight:400;font-size:.75rem;">(one per line)</span></th>
                        <th class="col-del"></th>
                    </tr>
                </thead>
                <tbody id="hazards-tbody">
                    @foreach ($reviewPayload['hazards'] as $i => $hazard)
                        @php
                            $c_haz = $fieldChanged("hazards.{$i}.hazard");
                            $hazardNeedsConfirmation = ! empty($hazard['needs_confirmation']);
                            $hazardRowClass = trim($diffClass($c_haz) . ($hazardNeedsConfirmation ? ' hazard-needs-confirmation' : ''));
                        @endphp
                        <tr class="{{ $hazardRowClass }}">
                            <td class="col-act">
                                <input type="text"
                                       name="hazards[{{ $i }}][activity_key]"
                                       value="{{ old("hazards.{$i}.activity_key", $hazard['activity_key']) }}"
                                       placeholder="optional"
                                       maxlength="100"
                                       style="font-family:monospace;font-size:.78rem;">
                            </td>
                            <td>
                                <input type="text"
                                       name="hazards[{{ $i }}][hazard]"
                                       value="{{ old("hazards.{$i}.hazard", $hazard['hazard']) }}"
                                       placeholder="e.g. Working at Height"
                                       maxlength="500">
                                @if ($hazardNeedsConfirmation)
                                    <span class="badge badge-warning badge-needs-confirmation">Needs confirmation</span>
                                @endif
                            </td>
                            <td class="col-score">
                                <input type="number" min="1" max="5"
                                       name="hazards[{{ $i }}][pre_likelihood]"
                                       value="{{ old("hazards.{$i}.pre_likelihood", $hazard['pre_likelihood']) }}"
                                       oninput="markHazardReviewed(this)">
                            </td>
                            <td class="col-score">
                                <input type="number" min="1" max="5"
                                       name="hazards[{{ $i }}][pre_severity]"
                                       value="{{ old("hazards.{$i}.pre_severity", $hazard['pre_severity']) }}"
                                       oninput="markHazardReviewed(this)">
                            </td>
                            <td class="col-score">
                                <input type="number" min="1" max="5"
                                       name="hazards[{{ $i }}][post_likelihood]"
                                       value="{{ old("hazards.{$i}.post_likelihood", $hazard['post_likelihood']) }}"
                                       oninput="markHazardReviewed(this)">
                            </td>
                            <td class="col-score">
                                <input type="number" min="1" max="5"
                                       name="hazards[{{ $i }}][post_severity]"
                                       value="{{ old("hazards.{$i}.post_severity", $hazard['post_severity']) }}"
                                       oninput="markHazardReviewed(this)">
                            </td>
                            <td>
                                <textarea name="hazards[{{ $i }}][control_measures]"
                                          rows="3"
                                          placeholder="Enter each control measure on a new line…">{{ old("hazards.{$i}.control_measures", implode("\n", $hazard['control_measures'])) }}</textarea>
                            </td>
                            <td class="col-del">
                                <input type="hidden"
                                       name="hazards[{{ $i }}][score_reviewed]"
                                       value="{{ old("hazards.{$i}.score_reviewed", ! empty($hazard['score_reviewed']) ? '1' : '0') }}"
                                       data-score-reviewed-flag>
                                {{-- Round-trips the resolver's needs_confirmation flag through
                                     Save/Approve — not user-editable, so a save never silently
                                     clears a row the tiered resolver flagged for confirmation. --}}
                                <input type="hidden"
                                       name="hazards[{{ $i }}][needs_confirmation]"
                                       value="{{ old("hazards.{$i}.needs_confirmation", $hazardNeedsConfirmation ? '1' : '0') }}">
                                <button type="button" class="btn-remove" onclick="removeRow(this)" title="Remove">✕</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- ── 5. PPE ───────────────────────────────────────────────────── --}}
    @php
        $ppeChanges = \App\Services\Rams\RamsDiffService::fieldChangesUnder($diff ?? [], 'ppe');
        $ppeHasChanges = ! empty($ppeChanges);
    @endphp
    <div class="review-section {{ $ppeHasChanges ? 'diff-modified' : '' }}">
        <div class="review-section-header">
            <h2>6. PPE Required</h2>
            @if ($ppeHasChanges)
                <span class="diff-hint diff-hint-modified">{{ count($ppeChanges) }} PPE change{{ count($ppeChanges) !== 1 ? 's' : '' }}</span>
            @endif
        </div>
        <div class="review-section-body">
            @error('ppe')
                <p class="form-error" style="margin-bottom:.75rem;">{{ $message }}</p>
            @enderror
            <div class="ppe-grid">
                @foreach ($ppeOptions as $ppeItem)
                    @php
                        // When old input exists (validation failure re-render), absent checkbox key = unchecked.
                        // When no old input (first render), use saved/extracted value.
                        $checked = session()->hasOldInput()
                            ? in_array($ppeItem, old('ppe', []), true)
                            : in_array($ppeItem, $reviewPayload['ppe'], true);
                    @endphp
                    <label class="ppe-check">
                        <input type="checkbox" name="ppe[]" value="{{ $ppeItem }}" {{ $checked ? 'checked' : '' }}>
                        {{ $ppeItem }}
                    </label>
                @endforeach
            </div>

            {{-- Preserve any PPE items from extracted data that are not in the standard list --}}
            @foreach ($reviewPayload['ppe'] as $ppeItem)
                @if (! in_array($ppeItem, $ppeOptions, true))
                    <div style="margin-top:.75rem;display:flex;align-items:center;gap:.5rem;">
                        <label class="ppe-check" style="flex:1;">
                            <input type="checkbox" name="ppe[]" value="{{ $ppeItem }}"
                                   {{ (session()->hasOldInput() ? in_array($ppeItem, old('ppe', []), true) : in_array($ppeItem, $reviewPayload['ppe'], true)) ? 'checked' : '' }}>
                            {{ $ppeItem }} <em style="font-size:.75rem;color:var(--text-muted);">(custom)</em>
                        </label>
                    </div>
                @endif
            @endforeach
        </div>
    </div>

    {{-- ── 6. Access / Site Constraints ───────────────────────────── --}}
    <div class="review-section">
        <div class="review-section-header">
            <h2>7. Access &amp; Site Constraints</h2>
        </div>
        <div class="review-section-body">
            <div class="access-grid">
                @php
                    $accessFields = [
                        'ladders'          => 'Podium Steps / Ladders required',
                        'tower'            => 'Access Tower required',
                        'scissor_lift'     => 'Scissor Lift / MEWP required',
                        'out_of_hours'     => 'Out-of-hours working',
                        'live_environment' => 'Live / occupied environment',
                    ];
                @endphp
                @foreach ($accessFields as $fieldKey => $fieldLabel)
                    @php
                        // When old input exists (validation failure re-render), absent checkbox key = unchecked.
                        // When no old input (first render), use saved/extracted value.
                        $checked = session()->hasOldInput()
                            ? !empty(old("access.{$fieldKey}"))
                            : (bool) ($reviewPayload['access'][$fieldKey] ?? false);
                    @endphp
                    <label class="ppe-check">
                        <input type="checkbox"
                               name="access[{{ $fieldKey }}]"
                               value="1"
                               {{ $checked ? 'checked' : '' }}>
                        {{ $fieldLabel }}
                    </label>
                @endforeach
            </div>
        </div>
    </div>

    {{-- ── 7. Method Statement Notes ───────────────────────────────── --}}
    <div class="review-section">
        <div class="review-section-header">
            <h2>8. Method Statement Notes</h2>
        </div>
        <div class="review-section-body">
            <div class="form-group" style="margin:0;">
                <label class="form-label" style="margin-bottom:.5rem;">
                    Additional scope notes or instructions for the AI method statement generator
                    <span style="color:var(--text-muted);font-weight:400;">(optional)</span>
                </label>
                <textarea name="method_statement_notes"
                          class="form-control"
                          rows="4"
                          maxlength="5000"
                          placeholder="e.g. All works to be carried out during school holiday period. Ceiling works in main hall require MEWP access…">{{ old('method_statement_notes', $reviewPayload['method_statement_notes']) }}</textarea>
            </div>
        </div>
    </div>

    {{-- ── 8. Action bar ───────────────────────────────────────────── --}}
    <div class="action-bar">
        <a href="{{ route('rams.index') }}" class="btn btn-ghost btn-sm">Cancel</a>

        {{-- Save Review: posts to the save/update endpoint --}}
        <button type="button"
                id="btn-save-review"
                class="btn btn-outline">
            💾 Save Review
        </button>

        {{-- Approve: posts to the approve endpoint (generation triggered from project page) --}}
        <button type="button"
                id="btn-approve"
                class="btn btn-teal"
                onclick="confirmApprove().then(function(ok){ if (ok) { var f = document.getElementById('review-form'); if (f) f.submit(); } });">
            ✓ Approve
        </button>
    </div>

</form>

{{-- Hidden save form — shares the same data but posts to the save endpoint --}}
<form id="save-form" method="POST" action="{{ route('rams.quote-review.update', $rams) }}" style="display:none;">
    @csrf
</form>
<form id="summary-form" method="POST" action="{{ route('rams.room-overviews.summarize', $rams) }}" style="display:none;">
    @csrf
</form>

@endsection

@push('scripts')
<script>
// ─── Row counter (used for unique indices when adding new rows) ───────────────
let equipmentCount  = {{ count($reviewPayload['equipment']) }};
let activityCount   = {{ count($reviewPayload['activities']) }};
let hazardCount     = {{ count($reviewPayload['hazards']) }};

// ─── Row templates ────────────────────────────────────────────────────────────
function equipmentRowTemplate(idx, category) {
    return `<tr data-equip-row="1">
        <td class="col-qty">
            <input type="number" name="equipment[${idx}][quantity]" value="1" min="1" max="999">
        </td>
        <td style="width:140px;">
            <input type="text" name="equipment[${idx}][part_number]" placeholder="e.g. YEA-MVC-S90"
                   maxlength="60" style="font-family:monospace;font-size:.82rem;text-transform:uppercase;"
                   oninput="this.value=this.value.toUpperCase()">
        </td>
        <td>
            <input type="text" name="equipment[${idx}][name]" placeholder="e.g. 55&quot; Display" maxlength="500">
        </td>
        <td style="width:150px;">
            <select name="equipment[${idx}][category]" data-equip-category>
                <option value="hardware" ${category === 'hardware' ? 'selected' : ''}>Hardware</option>
                <option value="cables" ${category === 'cables' ? 'selected' : ''}>Cables</option>
                <option value="consumables" ${category === 'consumables' ? 'selected' : ''}>Consumables</option>
                <option value="services" ${category === 'services' ? 'selected' : ''}>Services / Professional</option>
                <option value="option" ${category === 'option' ? 'selected' : ''}>Option (Optional Items)</option>
            </select>
        </td>
        <td class="col-area">
            <input type="text" name="equipment[${idx}][area]" placeholder="e.g. Meeting Room 1"
                   maxlength="150" style="font-size:.82rem;">
        </td>
        <td class="col-del">
            <button type="button" class="btn-remove" onclick="removeRow(this)" title="Remove">✕</button>
        </td>
    </tr>`;
}

function activityRowTemplate(idx) {
    return `<tr>
        <td>
            <input type="text" name="activities[${idx}][key]" placeholder="e.g. display_installation" maxlength="100" style="font-family:monospace;font-size:.8rem;">
        </td>
        <td>
            <input type="text" name="activities[${idx}][label]" placeholder="e.g. Display &amp; Screen Installation" maxlength="255">
        </td>
        <td class="col-del">
            <button type="button" class="btn-remove" onclick="removeRow(this)" title="Remove">✕</button>
        </td>
    </tr>`;
}

function hazardRowTemplate(idx) {
    // Phase 26-05 (HAZ-04): a manually-added row was just typed by the
    // engineer — it is inherently reviewed, so score_reviewed defaults to
    // "1" here (unlike server-rendered pre-filled rows, which default to
    // "0" until touched). No needs-confirmation badge — that flag only ever
    // arrives from the tiered resolver on server-rendered rows.
    return `<tr>
        <td class="col-act">
            <input type="text" name="hazards[${idx}][activity_key]" placeholder="optional" maxlength="100" style="font-family:monospace;font-size:.78rem;">
        </td>
        <td>
            <input type="text" name="hazards[${idx}][hazard]" placeholder="e.g. Working at Height" maxlength="500">
        </td>
        <td class="col-score">
            <input type="number" min="1" max="5" name="hazards[${idx}][pre_likelihood]" value="3" oninput="markHazardReviewed(this)">
        </td>
        <td class="col-score">
            <input type="number" min="1" max="5" name="hazards[${idx}][pre_severity]" value="3" oninput="markHazardReviewed(this)">
        </td>
        <td class="col-score">
            <input type="number" min="1" max="5" name="hazards[${idx}][post_likelihood]" value="2" oninput="markHazardReviewed(this)">
        </td>
        <td class="col-score">
            <input type="number" min="1" max="5" name="hazards[${idx}][post_severity]" value="2" oninput="markHazardReviewed(this)">
        </td>
        <td>
            <textarea name="hazards[${idx}][control_measures]" rows="3" placeholder="Enter each control measure on a new line…"></textarea>
        </td>
        <td class="col-del">
            <input type="hidden" name="hazards[${idx}][score_reviewed]" value="1" data-score-reviewed-flag>
            <input type="hidden" name="hazards[${idx}][needs_confirmation]" value="0">
            <button type="button" class="btn-remove" onclick="removeRow(this)" title="Remove">✕</button>
        </td>
    </tr>`;
}

// Phase 26-05 (HAZ-04): flip the hidden score_reviewed marker to "1" the
// moment an engineer edits any of a hazard row's 4 numeric score inputs.
function markHazardReviewed(el) {
    const row = el.closest('tr');
    if (! row) return;
    const flag = row.querySelector('[data-score-reviewed-flag]');
    if (flag) flag.value = '1';
}

// ─── Generic add / remove ─────────────────────────────────────────────────────
function addRow(tbodyId, templateFn, category) {
    const tbody = document.getElementById(tbodyId);
    // Use a timestamp-based index to guarantee uniqueness without caring about gaps.
    const idx = Date.now();
    const div = document.createElement('tbody');
    div.innerHTML = templateFn(idx, category || 'hardware');
    const row = div.firstElementChild;
    tbody.appendChild(row);
    ensureEquipmentEmptyState(tbody);
    // Focus first input in the new row
    const first = row.querySelector('input, textarea, select');
    if (first) first.focus();
}

function removeRow(btn) {
    const row = btn.closest('tr');
    if (row) {
        const tbody = row.closest('tbody');
        row.remove();
        if (tbody) ensureEquipmentEmptyState(tbody);
    }
}

// ─── Save Review + AI Summary Actions ────────────────────────────────────────
// Serialise the main form and re-submit via hidden forms to change endpoint.
function submitFromReview(targetFormId) {
    const reviewForm = document.getElementById('review-form');
    const targetForm = document.getElementById(targetFormId);
    if (!reviewForm || !targetForm) return;

    // Remove any previously cloned fields first.
    targetForm.querySelectorAll('[data-cloned]').forEach(el => el.remove());

    const data = new FormData(reviewForm);
    for (const [key, value] of data.entries()) {
        if (key === '_token') continue; // target form already has its own CSRF token
        const hidden = document.createElement('input');
        hidden.type  = 'hidden';
        hidden.name  = key;
        hidden.value = value;
        hidden.setAttribute('data-cloned', '1');
        targetForm.appendChild(hidden);
    }

    targetForm.submit();
}

document.getElementById('btn-save-review').addEventListener('click', function () {
    submitFromReview('save-form');
});

const summaryButton = document.getElementById('btn-gen-ai-summary');
if (summaryButton) {
    summaryButton.addEventListener('click', function () {
        submitFromReview('summary-form');
    });
}

// ─── Move row when category changes ──────────────────────────────────────────
document.addEventListener('change', function (e) {
    if (!e.target.matches('select[data-equip-category]')) return;
    const select = e.target;
    const row = select.closest('tr');
    const category = select.value || 'hardware';
    const tbody = document.getElementById('equipment-tbody-' + category);
    const prevTbody = row ? row.closest('tbody') : null;
    if (row && tbody) {
        tbody.appendChild(row);
        if (prevTbody) ensureEquipmentEmptyState(prevTbody);
        ensureEquipmentEmptyState(tbody);
    }
});

// ─── Empty state handling for equipment categories ───────────────────────────
function ensureEquipmentEmptyState(tbody) {
    if (!tbody) return;
    const hasRows = tbody.querySelectorAll('tr[data-equip-row]').length > 0;
    let emptyRow = tbody.querySelector('tr[data-empty-row]');
    if (hasRows && emptyRow) {
        emptyRow.remove();
        return;
    }
    if (!hasRows && !emptyRow) {
        emptyRow = document.createElement('tr');
        emptyRow.setAttribute('data-empty-row', '1');
        emptyRow.innerHTML = `<td colspan="6" style="color:#888;font-size:.82rem;padding:.75rem 1rem;">
            No items in this category yet.
        </td>`;
        tbody.appendChild(emptyRow);
    }
}

// Initialise empty state on load (handles categories with zero rows)
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('tbody[id^="equipment-tbody-"]').forEach(ensureEquipmentEmptyState);
});

// ─── Approve confirmation ─────────────────────────────────────────────────────
// Returns a Promise<boolean> — callers must `await` or `.then()`.
function confirmApprove() {
    return window.appConfirm(
        'Approve this reviewed data? Once approved, return to the project page and click Generate to build the RAMS document. You can still edit and re-approve at any time.',
        { title: 'Approve reviewed data?', confirmLabel: 'Approve' }
    );
}
</script>
@endpush
