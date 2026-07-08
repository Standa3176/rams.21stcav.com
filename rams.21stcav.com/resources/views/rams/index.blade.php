@extends('layouts.app')

@section('title', 'RAMS Documents')

@push('styles')
<style>
/*
 * RAMS index — tier-one polish (2026-07-08, PLAN 260708-b7i follow-up).
 * Was: hardcoded warm hex (#FFF3CD, #856404, #f0f0f0, #ccc) that
 * bypassed the token retune from task 2. Now every colour flows
 * through the semantic vars in :root so the palette shifts once from
 * layouts/app.blade.php.
 */

/* Status select ─────────────────────────────────────────────────────── */
.status-select {
    border: 1px solid var(--border-strong);
    border-radius: 6px;
    font-size: .8125rem;
    font-weight: 600;
    padding: .25rem .55rem;
    cursor: pointer;
    appearance: none;
    -webkit-appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%2364748B'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right .5rem center;
    padding-right: 1.5rem;
    transition: background .12s, color .12s, border-color .12s;
}
.status-select[data-status="draft"]       { background-color: var(--ink-100);       color: var(--ink-700); }
.status-select[data-status="for_review"]  { background-color: var(--warning-light); color: #92400E;
                                            border-color: color-mix(in oklab, var(--warning) 30%, transparent); }
.status-select[data-status="approved"]    { background-color: var(--success-light); color: var(--success);
                                            border-color: color-mix(in oklab, var(--success) 30%, transparent); }
.status-select[data-status="superseded"]  { background-color: var(--danger-light);  color: #991B1B;
                                            border-color: color-mix(in oklab, var(--danger) 30%, transparent); }

/* Pipeline status badges — soft pills, thin border to match tier-one
   badge treatment across the app. */
.status-badge {
    display: inline-flex;
    align-items: center;
    font-size: .75rem;
    font-weight: 500;
    padding: .15rem .55rem;
    border-radius: 999px;
    white-space: nowrap;
    border: 1px solid transparent;
    letter-spacing: -0.005em;
}
.status-badge.uploaded            { background: var(--ink-100);       color: var(--ink-700);
                                    border-color: var(--border); }
.status-badge.awaiting_review     { background: var(--warning-light); color: #92400E;
                                    border-color: color-mix(in oklab, var(--warning) 30%, transparent); }
.status-badge.approved            { background: var(--teal-100);      color: var(--teal-700);
                                    border-color: color-mix(in oklab, var(--teal-700) 25%, transparent); }
.status-badge.approved_generation { background: var(--teal-100);      color: var(--teal-700); }
.status-badge.generating          { background: var(--success-light); color: var(--success); }
.status-badge.completed           { background: var(--success-light); color: var(--success);
                                    border-color: color-mix(in oklab, var(--success) 30%, transparent); }
.status-badge.failed              { background: var(--danger-light);  color: #991B1B;
                                    border-color: color-mix(in oklab, var(--danger) 30%, transparent); }

/* Failed error message ───────────────────────────────────────────────── */
.error-detail {
    display: block;
    font-size: .73rem;
    color: #991B1B;
    margin-top: .3rem;
    max-width: 260px;
    word-break: break-word;
    line-height: 1.35;
}

/* Superseded row — muted; blocked from interacting. */
tr.superseded td { opacity: .45; }
tr.superseded td.actions-cell * { pointer-events: none; }

/* Soft-deleted row — subtle danger-tint background; actions stay
   clickable for admin restore. */
tr.soft-deleted td { opacity: .55; background: color-mix(in oklab, var(--danger-light) 40%, var(--card)); }
tr.soft-deleted td.actions-cell * { pointer-events: auto; }

/* Email modal overlay — tier-one dialog treatment. */
.modal-overlay {
    display: none;
    position: fixed; inset: 0;
    background: color-mix(in oklab, var(--ink-900) 55%, transparent);
    backdrop-filter: blur(4px);
    z-index: 999;
    align-items: center;
    justify-content: center;
}
.modal-overlay.open { display: flex; }
.modal-box {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 24px 28px;
    width: 100%;
    max-width: 480px;
    box-shadow: var(--shadow-lg);
    position: relative;
}
.modal-close {
    position: absolute;
    top: 12px; right: 14px;
    background: transparent; border: none;
    font-size: 22px;
    cursor: pointer;
    color: var(--text-muted);
    line-height: 1;
    padding: 4px 8px;
    border-radius: 6px;
    transition: color .12s, background .12s;
}
.modal-close:hover { color: var(--ink-900); background: var(--surface-soft); }
</style>
@endpush

@section('content')

    {{-- Page header — tier-one polish (2026-07-08). Was custom inline
         flex + emoji-prefixed button labels. Now uses the shared
         .page-header-left / .page-header-actions helpers so this screen
         inherits the same hierarchy pattern as the dashboard + project
         detail. --}}
    <div class="page-header">
        <div class="page-header-left">
            <h1 class="page-title">RAMS Documents</h1>
            <div class="page-subtitle">Risk assessments and method statements generated from approved projects.</div>
        </div>
        <div class="page-header-actions">
            @if ($isAdmin)
                @if (request()->boolean('show_deleted'))
                    <a href="{{ route('rams.index') }}" class="btn btn-danger-outline btn-sm">
                        Hide Deleted
                    </a>
                @else
                    <a href="{{ route('rams.index', ['show_deleted' => 1]) }}" class="btn btn-outline btn-sm">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M6 6l1 14a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-14"/>
                        </svg>
                        View Deleted
                    </a>
                @endif
            @endif
            <a href="{{ route('rams.upload.create') }}" class="btn btn-outline btn-sm">
                From Quote PDF
            </a>
            <a href="{{ route('rams.create') }}" class="btn btn-teal btn-sm">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
                    <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                Create RAMS
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
                                                  data-confirm="Permanently delete this RAMS document? This CANNOT be undone — the record and file will be removed forever."
                                                  data-confirm-label="Delete Forever"
                                                  data-confirm-danger="1">
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
                                              data-confirm="Generate the RAMS document now? The AI will produce the method statement based on your approved data."
                                              data-confirm-label="Generate"
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
                                            {{-- Primary visible actions: PDF + DOCX only. Everything else
                                                 (Regen / Re-extract / Email / O&M shortcut / Delete) collapses
                                                 into the ⋯ overflow menu so each row reads as "here's the doc"
                                                 not "here's a control panel". --}}
                                            <a href="{{ route('rams.download-pdf', $doc) }}"
                                               class="btn btn-teal btn-sm" title="Download PDF"
                                               onclick="triggerFileDownload(this.href); return false;">
                                                ↓ PDF
                                            </a>

                                            <a href="{{ route('rams.download', $doc) }}"
                                               class="btn btn-outline btn-sm" title="Download Word doc">
                                                ↓ .docx
                                            </a>

                                            @if (! $sup)
                                                <x-row-actions-menu label="Row actions">
                                                    {{-- Regenerate --}}
                                                    <form method="POST"
                                                          action="{{ route('rams.retry-generation', $doc) }}"
                                                          data-confirm="Rebuild the DOCX from the approved data? The document will be regenerated in the background."
                                                          data-confirm-label="Regenerate"
                                                          style="margin:0;">
                                                        @csrf
                                                        <button type="submit" class="row-actions-item" title="Rebuild document">
                                                            <span class="row-actions-item__icon" aria-hidden="true">↻</span>
                                                            <span>Regenerate</span>
                                                        </button>
                                                    </form>

                                                    {{-- Re-extract (rerun PDF extraction) --}}
                                                    @if (! empty($doc->form_data['original_filename'] ?? null))
                                                        <form method="POST"
                                                              action="{{ route('rams.retry-extraction', $doc) }}"
                                                              data-confirm="Re-extract the quote PDF and rebuild the review data? This will overwrite the extracted data for this RAMS."
                                                              data-confirm-label="Re-extract"
                                                              style="margin:0;">
                                                            @csrf
                                                            <button type="submit" class="row-actions-item" title="Re-extract quote PDF">
                                                                <span class="row-actions-item__icon" aria-hidden="true">↺</span>
                                                                <span>Re-extract PDF</span>
                                                            </button>
                                                        </form>
                                                    @endif

                                                    {{-- Email --}}
                                                    <button type="button"
                                                            class="row-actions-item"
                                                            onclick="openEmailModal('{{ route('rams.email', $doc) }}', '{{ addslashes($doc->project_name) }}')"
                                                            title="Email document">
                                                        <span class="row-actions-item__icon" aria-hidden="true">✉</span>
                                                        <span>Email document</span>
                                                    </button>

                                                    {{-- O&M Manual shortcut --}}
                                                    @if ($doc->omManual)
                                                        <a href="{{ route('om-manuals.index') }}" class="row-actions-item" title="View linked O&M Manual">
                                                            <span class="row-actions-item__icon" aria-hidden="true">📗</span>
                                                            <span>View O&amp;M Manual</span>
                                                        </a>
                                                    @else
                                                        <a href="{{ route('om-manuals.create') }}" class="row-actions-item" title="Create O&M Manual for this project">
                                                            <span class="row-actions-item__icon" aria-hidden="true">→</span>
                                                            <span>Create O&amp;M Manual</span>
                                                        </a>
                                                    @endif

                                                    {{-- Delete (moved into overflow for completed rows so the
                                                         destructive action never sits inline next to Download). --}}
                                                    <form method="POST"
                                                          action="{{ route('rams.destroy', $doc) }}"
                                                          data-confirm="Delete this RAMS document? Admins can restore it later."
                                                          data-confirm-label="Delete"
                                                          data-confirm-danger="1"
                                                          style="margin:0;">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="row-actions-item row-actions-item--danger" title="Delete">
                                                            <span class="row-actions-item__icon" aria-hidden="true">✕</span>
                                                            <span>Delete document</span>
                                                        </button>
                                                    </form>
                                                </x-row-actions-menu>
                                            @endif
                                        @endif

                                        {{-- Skip the trailing "always-visible Delete" — for completed rows
                                             the destroy form lives inside the ⋯ menu above. Flag with $moved
                                             so the outer delete block doesn't duplicate it. --}}
                                        @php $ramsDeleteMoved = true; @endphp

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

                                    {{-- Delete — only for non-deleted, non-completed records. Completed
                                         rows already have Delete inside the ⋯ overflow menu (see $ramsDeleteMoved
                                         flag set above). --}}
                                    @unless (! empty($ramsDeleteMoved))
                                        <form method="POST"
                                              action="{{ route('rams.destroy', $doc) }}"
                                              data-confirm="Delete this RAMS document? Admins can restore it later."
                                              data-confirm-label="Delete"
                                              data-confirm-danger="1"
                                              style="margin:0;">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-danger-outline btn-sm" title="Delete">
                                                ✕
                                            </button>
                                        </form>
                                    @endunless

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
