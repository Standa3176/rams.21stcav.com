@extends('layouts.app')
@section('title', 'Solution Types')

@section('content')

<div class="page-header">
    <div>
        <h1 class="page-title">Solution Types</h1>
        <p style="color:var(--text-muted);font-size:.875rem;margin-top:.25rem;">
            Define room/space solution types. Assigned per space in Project Data. Drives site survey checklists and method statement generation.
        </p>
    </div>
    <div style="display:flex;gap:.5rem;align-items:center;">
        <a href="{{ route('admin.solution-types.create') }}" class="btn btn-teal btn-sm">+ New Solution Type</a>
    </div>
</div>

@if (session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif

<div class="section-block" style="padding:0;overflow:hidden;">
    <table style="width:100%;border-collapse:collapse;font-size:.875rem;">
        <thead>
            <tr style="background:var(--bg);border-bottom:2px solid var(--border);">
                <th style="padding:.6rem 1rem;text-align:left;font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);">Order</th>
                <th style="padding:.6rem 1rem;text-align:left;font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);">Name</th>
                <th style="padding:.6rem 1rem;text-align:left;font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);">Description</th>
                <th style="padding:.6rem 1rem;text-align:left;font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);">Checklist Items</th>
                <th style="padding:.6rem 1rem;text-align:left;font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);">Status</th>
                <th style="padding:.6rem 1rem;text-align:right;font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($types as $type)
            <tr style="border-bottom:1px solid var(--border);">
                <td style="padding:.6rem 1rem;color:var(--text-muted);font-size:.8rem;width:60px;">{{ $type->sort_order }}</td>
                <td style="padding:.6rem 1rem;font-weight:700;color:#0B3C45;">
                    {{ $type->name }}
                    <div style="font-size:.73rem;color:var(--text-muted);font-weight:400;font-family:monospace;">{{ $type->slug }}</div>
                </td>
                <td style="padding:.6rem 1rem;color:#374151;max-width:280px;">
                    {{ Str::limit($type->description, 80) }}
                </td>
                <td style="padding:.6rem 1rem;color:var(--text-muted);font-size:.82rem;">
                    {{ count($type->checklistLines()) }} items
                </td>
                <td style="padding:.6rem 1rem;">
                    @if($type->is_active)
                        <span style="background:#D1FAE5;color:#065F46;padding:.15rem .55rem;border-radius:12px;font-size:.72rem;font-weight:700;">Active</span>
                    @else
                        <span style="background:#F3F4F6;color:#6B7280;padding:.15rem .55rem;border-radius:12px;font-size:.72rem;font-weight:700;">Inactive</span>
                    @endif
                </td>
                <td style="padding:.6rem 1rem;text-align:right;">
                    <div style="display:flex;gap:.35rem;justify-content:flex-end;">
                        <a href="{{ route('admin.solution-types.edit', $type) }}"
                           class="btn btn-outline btn-sm" style="font-size:.75rem;">Edit</a>
                        <form method="POST" action="{{ route('admin.solution-types.destroy', $type) }}"
                              data-confirm="Delete {{ $type->name }}?"
                              data-confirm-label="Delete"
                              data-confirm-danger="1" style="margin:0;">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-danger-outline btn-sm" style="font-size:.75rem;">Delete</button>
                        </form>
                    </div>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="6" style="padding:2rem;text-align:center;color:var(--text-muted);">
                    No solution types yet.
                    <a href="{{ route('admin.solution-types.create') }}" style="color:var(--teal);">Create one →</a>
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

@endsection
