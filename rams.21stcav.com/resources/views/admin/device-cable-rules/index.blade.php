@extends('layouts.app')
@section('title', 'Device Cable Rules')

@section('content')

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">Device Cable Rules</h1>
        <div class="page-subtitle">
            Priority-ordered inference rules powering
            <code>CableScheduleGeneratorService::inferCableRun()</code>. First matching keyword wins per equipment name. Cache flushes automatically on save / delete.
        </div>
    </div>
    <div class="page-header-actions">
        <a href="{{ route('admin.device-cable-rules.create') }}" class="btn btn-teal btn-sm">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            Add rule
        </a>
    </div>
</div>

@if (session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if (session('error'))
    <div class="alert alert-error">{{ session('error') }}</div>
@endif

{{-- 260712-ip3: inline rule tester — hits the JSON preview endpoint and
     renders the full walker trace so admins can eyeball rule behaviour
     without SSH-ing to the box. Alpine.js card, no build step. --}}
<div class="section-block" style="margin-bottom:1.25rem;" x-data="rulePreview()">
    <h2 class="section-heading" style="display:flex;align-items:center;gap:.5rem;">
        🧪 Test a rule
    </h2>
    <p style="font-size:.825rem;color:var(--text-muted);margin-bottom:.75rem;">
        Type an equipment name (and optionally a cable length in metres) to walk the priority-ordered rule set and see which rule wins. Read-only — nothing is saved.
    </p>
    <div class="form-grid-2" style="align-items:end;">
        <div class="form-group">
            <label class="form-label">Equipment name</label>
            <input type="text" x-model="equipment" class="form-control"
                   placeholder="e.g. Logitech USB 3.0 Webcam"
                   @keydown.enter.prevent="run()">
        </div>
        <div class="form-group">
            <label class="form-label">Length (m) — optional</label>
            <input type="number" step="0.1" min="0" x-model.number="lengthM" class="form-control"
                   placeholder="e.g. 20"
                   style="max-width:150px;"
                   @keydown.enter.prevent="run()">
        </div>
    </div>
    <div style="margin-top:.5rem;">
        <button type="button" class="btn btn-teal btn-sm" @click="run()" x-bind:disabled="loading || !equipment">
            <span x-show="!loading">Preview</span>
            <span x-show="loading">Running…</span>
        </button>
    </div>

    <template x-if="error">
        <div class="alert alert-error" style="margin-top:.75rem;" x-text="error"></div>
    </template>

    <template x-if="result">
        <div style="margin-top:1rem;">
            <div style="padding:.75rem;border:1px solid var(--border);border-radius:6px;background:var(--surface-soft);margin-bottom:.75rem;">
                <template x-if="result.matched_rule_id !== null">
                    <div>
                        <strong>Matched rule #<span x-text="result.matched_rule_id"></span> (priority <span x-text="result.matched_priority"></span>)</strong>
                        → cable_type <code x-text="result.cable_type"></code>,
                        signal_type <code x-text="result.signal_type"></code>,
                        to <span x-text="result.to"></span>.
                        <template x-if="result.tier_used">
                            <div style="margin-top:.25rem;font-size:.85rem;color:var(--text-muted);">
                                Tier used: max_m <span x-text="result.tier_used.max_m"></span>
                                (<span x-text="result.tier_used.cable_type"></span>)
                            </div>
                        </template>
                        <template x-if="result.notes">
                            <div style="margin-top:.25rem;font-size:.85rem;color:var(--text-muted);">
                                Notes: <span x-text="result.notes"></span>
                            </div>
                        </template>
                    </div>
                </template>
                <template x-if="result.matched_rule_id === null">
                    <div>
                        <strong>No rule matched.</strong> Cable falls through to the <code>TBC</code> placeholder — confirm on survey.
                    </div>
                </template>
            </div>

            <details open>
                <summary style="cursor:pointer;font-weight:600;font-size:.875rem;">
                    Walker trace (<span x-text="result.trace.length"></span> rules inspected)
                </summary>
                <table class="data-table" style="margin-top:.5rem;font-size:.8rem;">
                    <thead>
                        <tr>
                            <th style="width:70px;">Priority</th>
                            <th>Keywords</th>
                            <th style="width:140px;">Verdict</th>
                            <th>Reason</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="row in result.trace" :key="row.rule_id">
                            <tr>
                                <td style="font-variant-numeric:tabular-nums;color:var(--text-muted);" x-text="row.priority"></td>
                                <td x-text="row.keywords.join(', ')"></td>
                                <td>
                                    <span x-bind:style="row.verdict === 'matched' ? 'color:var(--success);font-weight:600;' : 'color:var(--text-muted);'"
                                          x-text="row.verdict"></span>
                                </td>
                                <td style="color:var(--text-muted);" x-text="row.reason"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </details>
        </div>
    </template>
</div>

@push('scripts')
<script>
    function rulePreview() {
        return {
            equipment: '',
            lengthM: null,
            loading: false,
            result: null,
            error: null,
            async run() {
                if (!this.equipment) return;
                this.loading = true;
                this.error = null;
                this.result = null;
                try {
                    const params = new URLSearchParams({ equipment: this.equipment });
                    if (this.lengthM) params.append('length_m', this.lengthM);
                    const res = await fetch("{{ route('admin.device-cable-rules.preview') }}?" + params.toString(), {
                        headers: { 'Accept': 'application/json' },
                    });
                    if (!res.ok) {
                        this.error = 'Preview endpoint returned HTTP ' + res.status + '.';
                        return;
                    }
                    this.result = await res.json();
                } catch (e) {
                    this.error = e.message || 'Preview request failed.';
                } finally {
                    this.loading = false;
                }
            },
        };
    }
</script>
@endpush

<div class="card" style="padding:0;overflow:hidden;">
    <table class="data-table">
        <thead>
            <tr>
                <th style="width:70px;">Priority</th>
                <th>Keywords</th>
                <th>Cable Type</th>
                <th style="width:110px;">Signal</th>
                <th>To</th>
                <th style="width:90px;">Status</th>
                <th style="text-align:right;"></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rules as $rule)
            <tr>
                <td style="color:var(--text-muted);font-size:12px;font-variant-numeric:tabular-nums;">
                    {{ $rule->priority }}
                </td>
                <td>
                    <div style="color:var(--body);max-width:340px;line-height:1.5;">
                        {{ implode(', ', (array) $rule->keywords) }}
                        {{-- 260712-ip3: exclusion count hint --}}
                        @if (! empty($rule->negative_keywords))
                            <span style="font-size:.7rem;color:var(--text-muted);margin-left:.25rem;" title="Negative keywords: {{ implode(', ', (array) $rule->negative_keywords) }}">{{ count($rule->negative_keywords) }} excl</span>
                        @endif
                    </div>
                </td>
                <td>
                    <div style="color:var(--ink-900);font-weight:500;">
                        {{ $rule->cable_type }}
                        {{-- 260712-euh: length-tier count badge --}}
                        @if (! empty($rule->length_tiers))
                            <span style="display:inline-block;margin-left:6px;padding:1px 8px;border-radius:999px;font-size:10px;font-weight:600;background:var(--accent-soft, var(--surface-soft));color:var(--accent, var(--text-muted));border:1px solid var(--border);">
                                {{ count($rule->length_tiers) }} tier{{ count($rule->length_tiers) === 1 ? '' : 's' }}
                            </span>
                        @endif
                    </div>
                    @if ($rule->cores)
                        <div style="font-size:11px;color:var(--text-muted);margin-top:1px;">{{ $rule->cores }} core</div>
                    @endif
                </td>
                <td>
                    <span style="display:inline-flex;align-items:center;gap:5px;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:500;background:var(--surface-soft);color:var(--text-muted);border:1px solid var(--border);">
                        {{ ucfirst($rule->signal_type) }}
                    </span>
                </td>
                <td style="color:var(--body);max-width:220px;">
                    {{ $rule->to_endpoint }}
                </td>
                <td>
                    @if ($rule->is_active)
                        <span class="badge badge-success" style="display:inline-flex;align-items:center;gap:5px;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:500;background:var(--success-light);color:var(--success);border:1px solid color-mix(in oklab, var(--success) 30%, transparent);">
                            <span style="width:5px;height:5px;border-radius:50%;background:currentColor;"></span>Active
                        </span>
                    @else
                        <span class="badge badge-muted" style="display:inline-flex;align-items:center;gap:5px;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:500;background:var(--surface-soft);color:var(--text-muted);border:1px solid var(--border);">
                            <span style="width:5px;height:5px;border-radius:50%;background:currentColor;"></span>Inactive
                        </span>
                    @endif
                </td>
                <td style="text-align:right;">
                    <div style="display:flex;gap:6px;justify-content:flex-end;">
                        <a href="{{ route('admin.device-cable-rules.edit', $rule) }}" class="btn btn-outline btn-sm">Edit</a>
                        <form method="POST" action="{{ route('admin.device-cable-rules.destroy', $rule) }}"
                              data-confirm="Delete rule #{{ $rule->id }} (priority {{ $rule->priority }})?"
                              data-confirm-label="Delete"
                              data-confirm-danger="1" style="margin:0;">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-danger-outline btn-sm">Delete</button>
                        </form>
                    </div>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="7" style="padding:32px;text-align:center;color:var(--text-muted);font-size:13px;">
                    No cable rules yet.
                    <a href="{{ route('admin.device-cable-rules.create') }}" style="color:var(--teal-700);font-weight:600;">Create one →</a>
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

@if ($rules->hasPages())
    <div style="margin-top:16px;">
        {{ $rules->links() }}
    </div>
@endif
@endsection
