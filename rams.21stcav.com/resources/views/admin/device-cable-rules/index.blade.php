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
