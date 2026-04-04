@extends('layouts.app')

@section('title', 'RAMS Documents')

@push('styles')
<style>
/* Status select ─────────────────────────────────────────────────────── */
.status-select {
    border: 1.5px solid #ccc;
    border-radius: 4px;
    font-size: .8125rem;
    font-weight: 600;
    padding: .2rem .5rem;
    cursor: pointer;
    appearance: none;
    -webkit-appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%23666'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right .45rem center;
    padding-right: 1.4rem;
    transition: background .15s, color .15s;
}
.status-select[data-status="draft"]       { background-color:#eee;    color:#555; }
.status-select[data-status="for_review"]  { background-color:#FFF3CD; color:#856404; }
.status-select[data-status="approved"]    { background-color:#D4EDDA; color:#155724; }
.status-select[data-status="superseded"]  { background-color:#FFDEDE; color:#7b1c1c; }

/* Pipeline status badges ─────────────────────────────────────────────── */
.status-badge {
    display: inline-block;
    font-size: .78rem;
    font-weight: 600;
    padding: .2rem .55rem;
    border-radius: 4px;
    white-space: nowrap;
}
.status-badge.uploaded            { background:#f0f0f0; color:#555; }
.status-badge.awaiting_review     { background:#fffbeb; color:#92400e; border:1px solid #f59e0b; }
.status-badge.approved            { background:#eff6ff; color:#1d4ed8; border:1px solid #93c5fd; }
.status-badge.approved_generation { background:#eff6ff; color:#1d4ed8; }
.status-badge.generating          { background:#f0fdf4; color:#166534; }
.status-badge.completed           { background:#f0fdf4; color:#166534; border:1px solid #86efac; }
.status-badge.failed              { background:#fef2f2; color:#991b1b; border:1px solid #fca5a5; }

/* Failed error message ───────────────────────────────────────────────── */
.error-detail {
    display: block;
    font-size: .73rem;
    color: #991b1b;
    margin-top: .25rem;
    max-width: 260px;
    word-break: break-word;
    line-height: 1.35;
}

/* Superseded row ─────────────────────────────────────────────────────── */
tr.superseded td { opacity: .45; }
tr.superseded td.actions-cell * { pointer-events: none; }

/* Soft-deleted row ───────────────────────────────────────────────────── */
tr.soft-deleted td { opacity: .5; background:#fff8f8; }
tr.soft-deleted td.actions-cell * { pointer-events: auto; }

/* Email modal overlay ───────────────────────────────────────────────── */
.modal-overlay {
    display: none;
    position: fixed; inset: 0;
    background: rgba(0,0,0,.45);
    z-index: 999;
    align-items: center;
    justify-content: center;
}
.modal-overlay.open { display: flex; }
.modal-box {
    background: #fff;
    border-radius: 8px;
    padding: 1.75rem 2rem;
    width: 100%;
    max-width: 480px;
    box-shadow: 0 8px 32px rgba(0,0,0,.2);
    position: relative;
}
.modal-close {
    position: absolute;
    top: .75rem; right: 1rem;
    background: none; border: none;
    font-size: 1.3rem;
    cursor: pointer;
    color: #888;
    line-height: 1;
}
.modal-close:hover { color: #333; }
</style>
@endpush

@section('content')

    {{-- Page header --}}
    <div class="page-header">
        <h1 class="page-title">RAMS Documents</h1>
        <div style="display:flex;gap:.5rem;align-items:center;">
            @if ($isAdmin)
                @if (request()->boolean('show_deleted'))
                    <a href="{{ route('rams.index') }}" class="btn btn-outline btn-sm" style="color:#991b1b;border-color:#fca5a5;">
                        Hide Deleted
                    </a>
                @else
                    <a href="{{ route('rams.index', ['show_deleted' => 1]) }}" class="btn btn-outline btn-sm">
                        🗑 View Deleted
                    </a>
                @endif
            @endif
            <a href="{{ route('rams.upload.create') }}" class="btn btn-outline btn-sm">
                From Quote PDF
            </a>
            <a href="{{ route('rams.create') }}" class="btn btn-teal">
                + Create RAMS
            </a>
        </div>
    </div>

    {{-- Flash --}}
    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-error">{{ session('error') }}</div>
    @endif

    @if ($isAdmin)
        <div class="alert alert-info"><strong>Admin view</strong> — showing all users' documents.</div>
    @endif

    {{-- Table --}}
    <div class="card" style="padding:0;overflow:hidden;">
        @if ($rams->isEmpty())
            <div class="empty-state">
                <h3>No RAMS documents yet</h3>
                <p>Generate your first AI-powered Risk Assessment &amp; Method Statement.</p>
                <a href="{{ route('rams.create') }}" class="btn btn-teal">+ Create New RAMS</a>
            </div>
        @else
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Project</th>
                        <th>Client</th>
                        @if ($isAdmin) <th>Owner</th> @endif
                        <th>Status</th>
                        <th>Created</th>
                        <th>Provider</th>
                        <th style="min-width:260px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rams as $doc)
                        @php
                            $sup          = $doc->isSuperseded();
                            $isPipeline   = $doc->isPipelineStatus();
                            $status       = $doc->status;
                            $isDeleted    = $doc->trashed();
                        @endphp
                        <tr class="{{ $isDeleted ? 'soft-deleted' : ($sup ? 'superseded' : '') }}">
                            <td>
                                <strong>{{ $doc->project_name ?: '—' }}</strong>
                                @if ($doc->project_ref)
                                    <br><small style="color:#666;">{{ $doc->project_ref }}</small>
                                @endif
                                @if ($sup)
                                    <br><small style="color:#c0392b;font-size:.73rem;">Superseded</small>
                                @endif
                            </td>
                            <td>{{ $doc->client_name ?: '—' }}</td>
                            @if ($isAdmin)
                                <td>
                                    {{ $doc->user->name ?? '—' }}<br>
                                    <small style="color:#999;font-size:.75rem;">{{ $doc->user->email ?? '' }}</small>
                                </td>
                            @endif

                            {{-- Status ─────────────────────────────────── --}}
                            <td>
                                @if ($isDeleted)
                                    <span class="status-badge failed" style="background:#fef2f2;color:#991b1b;border-color:#fca5a5;">Deleted</span>

                                @elseif ($sup)
                                    <span class="badge badge-grey">Superseded</span>

                                @elseif ($isPipeline)
                                    {{-- Pipeline statuses are read-only badges --}}
                                    @php
                                        $badgeClass = match($status) {
                                            'uploaded'                => 'uploaded',
                                            'awaiting_review'         => 'awaiting_review',
                                            'approved'                => 'approved',
                                            'approved_for_generation' => 'approved_generation',
                                            'generating'              => 'generating',
                                            'completed'               => 'completed',
                                            'failed'                  => 'failed',
                                            default                   => 'uploaded',
                                        };
                                    @endphp
                                    <span class="status-badge {{ $badgeClass }}">
                                        {{ $doc->statusLabel() }}
                                    </span>

                                @else
                                    {{-- Workflow statuses are editable --}}
                                    <form method="POST"
                                          action="{{ route('rams.status', $doc) }}"
                                          id="status-form-{{ $doc->id }}"
                                          style="margin:0;">
                                        @csrf
                                        <select name="status"
                                                class="status-select"
                                                data-status="{{ $doc->status }}"
                                                onchange="this.dataset.status=this.value; document.getElementById('status-form-{{ $doc->id }}').submit();">
                                            <option value="draft"      {{ $doc->status==='draft'      ? 'selected':'' }}>Draft</option>
                                            <option value="for_review" {{ $doc->status==='for_review' ? 'selected':'' }}>For Review</option>
                                            <option value="approved"   {{ $doc->status==='approved'   ? 'selected':'' }}>Approved</option>
                                            <option value="superseded" {{ $doc->status==='superseded' ? 'selected':'' }}>Superseded</option>
                                        </select>
                                    </form>
                                @endif
                            </td>

                            <td style="white-space:nowrap;">
                                {{ $doc->created_at->format('d M Y') }}<br>
                                <small style="color:#999;">{{ $doc->created_at->format('H:i') }}</small>
                            </td>
                            <td>
                                <span class="badge badge-teal">{{ ucfirst($doc->ai_provider ?? 'AI') }}</span>
                            </td>

                            {{-- Actions ─────────────────────────────────── --}}
                            <td class="actions-cell">
                                <div class="actions" style="flex-wrap:wrap;gap:.35rem;">

                                    {{-- ── SOFT-DELETED: admin restore + permanent delete ── --}}
                                    @if ($isDeleted)
                                        @if ($isAdmin)
                                            <form method="POST"
                                                  action="{{ route('rams.restore', $doc->id) }}"
                                                  style="margin:0;">
                                                @csrf
                                                <button type="submit" class="btn btn-outline btn-sm" title="Restore deleted document">
                                                    ↩ Restore
                                                </button>
                                            </form>

                                            <form method="POST"
                                                  action="{{ route('rams.force-destroy', $doc->id) }}"
                                                  style="margin:0;"
                                                  onsubmit="return confirm('Permanently delete this RAMS document?\n\nThis CANNOT be undone — the record and file will be removed forever.');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-danger btn-sm" title="Permanently delete">
                                                    ✕ Delete Forever
                                                </button>
                                            </form>
                                        @else
                                            <span style="font-size:.8rem;color:#991b1b;">Deleted</span>
                                        @endif

                                    {{-- ── LIVE DOCUMENTS: normal actions ── --}}
                                    @else

                                    {{-- ── AWAITING REVIEW: show Review button ── --}}
                                    @if ($status === 'awaiting_review')
                                        <a href="{{ route('rams.quote-review.show', $doc) }}"
                                           class="btn btn-teal btn-sm"
                                           title="Review extracted data and approve for generation">
                                            ✎ Review
                                        </a>

                                    {{-- ── APPROVED: show Generate RAMS button ── --}}
                                    @elseif ($status === 'approved' && ! $doc->generated_data)
                                        <form method="POST"
                                              action="{{ route('rams.retry-generation', $doc) }}"
                                              onsubmit="return confirm('Generate the RAMS document now? The AI will produce the method statement based on your approved data.');"
                                              style="margin:0;">
                                            @csrf
                                            <button type="submit" class="btn btn-teal btn-sm" title="Generate RAMS document">
                                                ▶ Generate RAMS
                                            </button>
                                        </form>

                                    {{-- ── PIPELINE PROCESSING: in-progress states ── --}}
                                    @elseif (in_array($status, ['uploaded', 'approved_for_generation', 'generating'], true))
                                        <span style="font-size:.8rem;color:var(--text-muted);font-style:italic;">
                                            Processing…
                                        </span>

                                    {{-- ── COMPLETED / LEGACY WITH FILE: show download actions ── --}}
                                    @elseif (
                                        in_array($status, ['completed', 'for_review', 'draft'], true)
                                        || ($status === 'approved' && $doc->generated_data)
                                    )
                                        @if ($doc->filename)
                                            {{-- Download .docx --}}
                                            <a href="{{ route('rams.download', $doc) }}"
                                               class="btn btn-outline btn-sm" title="Download Word doc">
                                                ↓ .docx
                                            </a>

                                            {{-- Download PDF --}}
                                            <a href="{{ route('rams.download-pdf', $doc) }}"
                                               class="btn btn-outline btn-sm" title="Download PDF">
                                                ↓ PDF
                                            </a>

                                            {{-- Regenerate (rebuilds DOCX from reviewed data via queue) --}}
                                            @if (! $sup)
                                                <form method="POST"
                                                      action="{{ route('rams.retry-generation', $doc) }}"
                                                      onsubmit="return confirm('Rebuild the DOCX from the approved data? The document will be regenerated in the background.');"
                                                      style="margin:0;">
                                                    @csrf
                                                    <button type="submit" class="btn btn-outline btn-sm" title="Rebuild document">
                                                        &#x21BA; Regen
                                                    </button>
                                                </form>

                                                {{-- Re-extract (rerun PDF extraction) --}}
                                                @if (! empty($doc->form_data['original_filename'] ?? null))
                                                    <form method="POST"
                                                          action="{{ route('rams.retry-extraction', $doc) }}"
                                                          onsubmit="return confirm('Re-extract the quote PDF and rebuild the review data? This will overwrite the extracted data for this RAMS.');"
                                                          style="margin:0;">
                                                        @csrf
                                                        <button type="submit" class="btn btn-outline btn-sm" title="Re-extract quote PDF">
                                                            ↺ Re-extract
                                                        </button>
                                                    </form>
                                                @endif

                                                {{-- Email --}}
                                                <button type="button"
                                                        class="btn btn-outline btn-sm"
                                                        onclick="openEmailModal('{{ route('rams.email', $doc) }}', '{{ addslashes($doc->project_name) }}')"
                                                        title="Email document">
                                                    ✉ Email
                                                </button>

                                                {{-- O&M Manual shortcut --}}
                                                @if ($doc->omManual)
                                                    <a href="{{ route('om-manuals.index') }}"
                                                       class="btn btn-sm"
                                                       style="background:#e0f4f6;color:#007B8A;border:1.5px solid #007B8A;"
                                                       title="View linked O&M Manual">
                                                        O&amp;M ✓
                                                    </a>
                                                @else
                                                    <a href="{{ route('om-manuals.create') }}"
                                                       class="btn btn-outline btn-sm"
                                                       style="font-size:.75rem;"
                                                       title="Create O&M Manual for this project">
                                                        O&amp;M →
                                                    </a>
                                                @endif
                                            @endif
                                        @endif

                                    {{-- ── FAILED: show error + retry buttons ── --}}
                                    @elseif ($status === 'failed')
                                        <div style="display:flex;flex-direction:column;gap:.35rem;">
                                            <span style="font-size:.8rem;font-weight:600;color:#991b1b;">⚠ Failed</span>
                                            @if ($doc->error_message)
                                                <span class="error-detail" title="{{ $doc->error_message }}">
                                                    {{ Str::limit($doc->error_message, 120) }}
                                                </span>
                                            @endif
                                            <div style="display:flex;gap:.35rem;flex-wrap:wrap;margin-top:.1rem;">
                                                @if ($doc->reviewed_data)
                                                    {{-- Generation failed — retry generation --}}
                                                    <form method="POST"
                                                          action="{{ route('rams.retry-generation', $doc) }}"
                                                          style="margin:0;">
                                                        @csrf
                                                        <button type="submit" class="btn btn-outline btn-sm" title="Retry RAMS generation">
                                                            ↺ Retry Generation
                                                        </button>
                                                    </form>
                                                @else
                                                    {{-- Extraction failed — retry extraction --}}
                                                    <form method="POST"
                                                          action="{{ route('rams.retry-extraction', $doc) }}"
                                                          style="margin:0;">
                                                        @csrf
                                                        <button type="submit" class="btn btn-outline btn-sm" title="Retry PDF extraction">
                                                            ↺ Retry Extraction
                                                        </button>
                                                    </form>
                                                @endif
                                            </div>
                                        </div>
                                    @endif

                                    {{-- Delete — only for non-deleted records --}}
                                    <form method="POST"
                                          action="{{ route('rams.destroy', $doc) }}"
                                          onsubmit="return confirm('Delete this RAMS document? Admins can restore it later.');"
                                          style="margin:0;">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-danger-outline btn-sm" title="Delete">
                                            ✕
                                        </button>
                                    </form>

                                    @endif {{-- end soft-deleted else --}}
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @if ($rams->hasPages())
                <div class="pagination-wrap" style="padding:1rem;">
                    {{ $rams->links() }}
                </div>
            @endif
        @endif
    </div>

    {{-- Email modal --}}
    <div class="modal-overlay" id="email-modal" role="dialog" aria-modal="true">
        <div class="modal-box">
            <button class="modal-close" onclick="closeEmailModal()" aria-label="Close">&times;</button>
            <h2 style="font-size:1.1rem;margin-bottom:1.25rem;color:#007B8A;">Email RAMS Document</h2>
            <p id="email-modal-project" style="font-size:.875rem;color:#555;margin-bottom:1rem;"></p>
            <form id="email-modal-form" method="POST" action="">
                @csrf
                <div class="form-group">
                    <label class="form-label">Recipient name <span class="req">*</span></label>
                    <input type="text" name="recipient_name" class="form-control" required maxlength="100">
                </div>
                <div class="form-group">
                    <label class="form-label">Recipient email <span class="req">*</span></label>
                    <input type="email" name="recipient_email" class="form-control" required maxlength="254">
                </div>
                <div class="form-group">
                    <label class="form-label">Note (optional)</label>
                    <textarea name="sender_note" class="form-control" rows="3" maxlength="1000"
                              placeholder="Any covering message to include…"></textarea>
                </div>
                <div style="display:flex;gap:.75rem;justify-content:flex-end;margin-top:1.25rem;">
                    <button type="button" class="btn btn-outline" onclick="closeEmailModal()">Cancel</button>
                    <button type="submit" class="btn btn-teal">Send Email</button>
                </div>
            </form>
        </div>
    </div>

@endsection

@push('scripts')
<script>
function openEmailModal(actionUrl, projectName) {
    document.getElementById('email-modal-form').action = actionUrl;
    document.getElementById('email-modal-project').textContent = 'Document: ' + projectName;
    document.getElementById('email-modal').classList.add('open');
}

function closeEmailModal() {
    document.getElementById('email-modal').classList.remove('open');
}

document.getElementById('email-modal').addEventListener('click', function (e) {
    if (e.target === this) closeEmailModal();
});
</script>
@endpush
