@extends('layouts.app')

@section('title', 'Admin — AI Usage')

@section('content')

@php
    $formatCost = fn($v) => $v > 0 ? ('$' . number_format($v, 4)) : '—';
    $formatTokens = fn($n) => number_format((int) $n);
@endphp

<x-dashboard.page-header title="AI Usage" breadcrumb="Admin">
    <x-slot name="actions">
        <a href="{{ route('admin.worker.index') }}" class="btn btn-outline btn-sm">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>
            </svg>
            Queue Worker
        </a>
        <a href="{{ route('admin.ai-usage.index') }}" class="btn btn-outline btn-sm">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
            </svg>
            Refresh
        </a>
    </x-slot>
</x-dashboard.page-header>

{{-- Tier-one KPI row — semantic palette (was mixed teal/blue/violet/amber
     hex baked in) — matches the dashboard KPI treatment. --}}
<div class="dash-stats-grid">
    <x-dashboard.stat-card
        title="Today"
        :value="$formatCost($stats['today']['cost_usd'])"
        subtitle="{{ $formatTokens($stats['today']['total_tokens']) }} tokens · {{ $stats['today']['calls'] }} calls"
        color="#4F46E5">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
        </svg>
    </x-dashboard.stat-card>

    <x-dashboard.stat-card
        title="This Week"
        :value="$formatCost($stats['week']['cost_usd'])"
        subtitle="{{ $formatTokens($stats['week']['total_tokens']) }} tokens · {{ $stats['week']['calls'] }} calls"
        color="#0284C7">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <rect x="3" y="4" width="18" height="18" rx="2"/>
            <line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/>
        </svg>
    </x-dashboard.stat-card>

    <x-dashboard.stat-card
        title="This Month"
        :value="$formatCost($stats['month']['cost_usd'])"
        subtitle="{{ $formatTokens($stats['month']['total_tokens']) }} tokens · {{ $stats['month']['calls'] }} calls"
        color="#7C3AED">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <rect x="3" y="4" width="18" height="18" rx="2"/>
            <line x1="3" y1="10" x2="21" y2="10"/>
        </svg>
    </x-dashboard.stat-card>

    <x-dashboard.stat-card
        title="Last Month"
        :value="$formatCost($stats['last_month']['cost_usd'])"
        subtitle="{{ $formatTokens($stats['last_month']['total_tokens']) }} tokens · {{ $stats['last_month']['calls'] }} calls"
        color="#059669">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <rect x="3" y="4" width="18" height="18" rx="2"/>
            <line x1="3" y1="10" x2="21" y2="10"/>
            <line x1="8" y1="14" x2="16" y2="14"/>
        </svg>
    </x-dashboard.stat-card>
</div>

<x-dashboard.table-wrapper title="Recent AI Usage (Last 20 Calls)">
    @if($recent->isEmpty())
        <x-dashboard.empty-state
            title="No AI usage yet"
            message="AI usage will appear here once a document is generated." />
    @else
    <table class="data-table">
        <thead>
            <tr>
                <th>When</th>
                <th>Provider</th>
                <th>Prompt</th>
                <th style="text-align:right;">Input</th>
                <th style="text-align:right;">Output</th>
                <th style="text-align:right;">Total</th>
                <th style="text-align:right;">Cost</th>
            </tr>
        </thead>
        <tbody>
        @foreach($recent as $row)
            <tr>
                <td style="white-space:nowrap; font-size:12px; color:var(--text-muted); font-variant-numeric: tabular-nums;">
                    {{ $row->created_at->format('d M Y H:i') }}
                </td>
                <td style="font-size:13px; color:var(--ink-900); font-weight:500;">
                    {{ ucfirst($row->provider) }}
                    @if($row->model)
                        <div style="font-size:11px; color:var(--text-muted); font-family:var(--font-mono); font-weight:400; margin-top:1px;">{{ $row->model }}</div>
                    @endif
                </td>
                <td style="font-size:12px; color:var(--body);">
                    {{ $row->prompt ?? '—' }}
                </td>
                <td style="text-align:right; font-variant-numeric: tabular-nums; color:var(--body);">
                    {{ $row->input_tokens !== null ? number_format($row->input_tokens) : '—' }}
                </td>
                <td style="text-align:right; font-variant-numeric: tabular-nums; color:var(--body);">
                    {{ $row->output_tokens !== null ? number_format($row->output_tokens) : '—' }}
                </td>
                <td style="text-align:right; font-variant-numeric: tabular-nums; color:var(--ink-900); font-weight:600;">
                    {{ $row->total_tokens !== null ? number_format($row->total_tokens) : '—' }}
                </td>
                <td style="text-align:right; font-variant-numeric: tabular-nums; color:var(--ink-900); font-weight:600;">
                    {{ $row->cost_usd ? ('$' . number_format((float) $row->cost_usd, 4)) : '—' }}
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
    @endif
</x-dashboard.table-wrapper>

<style>
.dash-stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1rem;
    margin-bottom: 1.5rem;
}
@media (max-width: 1100px) {
    .dash-stats-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 540px) {
    .dash-stats-grid { grid-template-columns: 1fr; }
}
</style>

@endsection
