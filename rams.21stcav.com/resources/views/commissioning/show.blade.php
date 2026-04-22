@extends('layouts.app')

@section('title', 'Commissioning — ' . $project->name)

@push('styles')
    {{-- Phase 16 checklist view shares the Phase 14 mobile-first utility layer.
         layouts/app.blade.php uses inline design-token CSS by default and does
         NOT include @vite; we opt in here via the styles stack so this page
         gets Tailwind + Alpine loaded. Matches install-programmes/field.blade.php. --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        /* Commissioning checklist — mobile-first, matches Phase 14 field view. */
        .commissioning-wrap   { max-width: 48rem; margin: 0 auto; padding: 1rem; display: flex; flex-direction: column; gap: 1rem; }
        .commissioning-header { position: sticky; top: 0; z-index: 10; background: #fff; border-bottom: 1px solid var(--border); padding-bottom: .75rem; }
        .commissioning-header h1 { font-size: 1.25rem; font-weight: 600; margin-bottom: .25rem; }
        .commissioning-header p  { font-size: .875rem; color: var(--text-muted); }
        .commissioning-signoff   { display: inline-flex; align-items: center; gap: .25rem; color: #15803D; font-weight: 500; }

        .commissioning-empty     { border: 1px dashed var(--border); border-radius: var(--radius); padding: 1.5rem; text-align: center; color: var(--text-muted); }

        .commissioning-room      { background: #fff; border-radius: var(--radius); box-shadow: var(--shadow-xs); border: 1px solid var(--border); }
        .commissioning-room__hdr { display: flex; align-items: center; justify-content: space-between; padding: .75rem 1rem; border-bottom: 1px solid var(--border); }
        .commissioning-room__hdr h2 { font-weight: 500; font-size: 1rem; }
        .commissioning-room__count  { font-size: .75rem; color: var(--text-muted); }
        .commissioning-room__list   { list-style: none; margin: 0; padding: 0; }
        .commissioning-room__list li + li { border-top: 1px solid var(--border); }

        .item-row            { padding: .75rem 1rem; display: flex; align-items: flex-start; gap: .75rem; }
        .item-row__body      { flex: 1; min-width: 0; }
        .item-row__equipment { font-size: .875rem; font-weight: 500; }
        .item-row__category  { font-size: .75rem; color: var(--text-muted); margin-top: .1rem; }
        .item-row__notes     { font-size: .75rem; color: var(--text); font-style: italic; margin-top: .25rem; }
        .item-row__notes:empty { display: none; }
        .item-row__photo-flag { font-size: .75rem; color: var(--teal); margin-top: .25rem; }
        .item-row__actions   { display: flex; gap: .25rem; flex-shrink: 0; }
        .item-row__btn       { padding: .35rem .75rem; border-radius: 6px; font-size: .75rem; font-weight: 600; border: none; cursor: pointer; }
        .item-row__btn--pass { background: #F0FDF4; color: #15803D; }
        .item-row__btn--pass.is-active { background: #16A34A; color: #fff; }
        .item-row__btn--fail { background: #FEF2F2; color: #B91C1C; }
        .item-row__btn--fail.is-active { background: #DC2626; color: #fff; }
        .item-row__btn--na   { background: #F3F4F6; color: #4B5563; }
        .item-row__btn--na.is-active   { background: #4B5563; color: #fff; }
        .item-row__btn:disabled { opacity: .4; cursor: not-allowed; }

        .commissioning-complete       { padding-top: 1rem; }
        .commissioning-complete__btn  { width: 100%; padding: .85rem; background: var(--teal); color: #fff; border: none; border-radius: 6px; font-weight: 500; font-size: 1rem; cursor: pointer; }
        .commissioning-complete__btn:disabled { opacity: .4; cursor: not-allowed; }
        .commissioning-complete__meta { font-size: .75rem; color: var(--text-muted); text-align: center; margin-top: .5rem; }

        /* Fail bottom-sheet */
        .fail-sheet              { position: fixed; inset: 0; z-index: 40; display: flex; align-items: flex-end; }
        .fail-sheet__backdrop    { position: absolute; inset: 0; background: rgba(0,0,0,.3); }
        .fail-sheet__panel       { position: relative; width: 100%; background: #fff; border-top-left-radius: 12px; border-top-right-radius: 12px; box-shadow: var(--shadow-md); padding: 1.25rem; display: flex; flex-direction: column; gap: 1rem; }
        .fail-sheet__header      { display: flex; align-items: center; justify-content: space-between; }
        .fail-sheet__title       { font-weight: 600; font-size: 1rem; }
        .fail-sheet__close       { background: transparent; border: none; color: var(--text-faint); font-size: 1.25rem; cursor: pointer; }
        .fail-sheet__hint        { font-size: .75rem; color: var(--text-muted); }
        .fail-sheet__label       { display: block; font-size: .75rem; font-weight: 500; color: var(--text); margin-bottom: .25rem; }
        .fail-sheet__textarea    { width: 100%; border: 1px solid var(--border); border-radius: 6px; padding: .5rem; font-size: .875rem; font-family: inherit; }
        .fail-sheet__file        { font-size: .875rem; }
        .fail-sheet__help        { font-size: .75rem; color: var(--text-faint); margin-top: .25rem; }
        .fail-sheet__error       { font-size: .875rem; color: var(--danger); }
        .fail-sheet__actions     { display: flex; justify-content: flex-end; gap: .5rem; padding-top: .5rem; }

        [x-cloak] { display: none !important; }
    </style>
@endpush

@section('content')
<div x-data="commissioningPage(@js([
    'projectId'      => $project->id,
    'programmeId'    => $programme?->id,
    'isOwnerOrAdmin' => $isOwnerOrAdmin,
    'locked'         => $signoff !== null,
    'counters'       => $counters,
]))" class="commissioning-wrap">

    {{-- ─── Sticky header ──────────────────────────────────────────── --}}
    <header class="commissioning-header">
        <h1>Commissioning — {{ $project->name }}</h1>
        <p>
            @if ($signoff)
                <span class="commissioning-signoff">
                    Signed off {{ $signoff->signed_at->setTimezone('Europe/London')->format('d M Y H:i') }}
                    by {{ $signoff->client_name }}
                </span>
            @elseif ($counters['programme']['total'] === 0)
                No commissioning items applicable for this programme.
            @else
                {{ $counters['programme']['complete'] }} of {{ $counters['programme']['total'] }} complete
            @endif
        </p>
    </header>

    {{-- ─── Empty state (D-13) ──────────────────────────────────────── --}}
    @if ($counters['programme']['total'] === 0)
        <div class="commissioning-empty">
            <p>No commissioning items applicable for this programme.</p>
            <p class="mt-2" style="font-size: .875rem;">You can still sign off and advance the project to Commissioning.</p>
        </div>
    @endif

    {{-- ─── Per-room groups ─────────────────────────────────────────── --}}
    @foreach ($rooms as $roomName => $items)
        <section class="commissioning-room">
            <header class="commissioning-room__hdr">
                <h2>{{ $roomName }}</h2>
                <span class="commissioning-room__count">
                    {{ $counters['byRoom'][$roomName]['complete'] }}/{{ $counters['byRoom'][$roomName]['total'] }}
                </span>
            </header>
            <ul class="commissioning-room__list">
                @foreach ($items as $item)
                    @include('commissioning._item-row', [
                        'item'           => $item,
                        'categoryLabels' => $categoryLabels,
                        'locked'         => $signoff !== null,
                    ])
                @endforeach
            </ul>
        </section>
    @endforeach

    {{-- ─── Complete Commissioning button (sign-off sheet wired in Plan 05) ─── --}}
    @if (! $signoff)
        <div class="commissioning-complete">
            <button type="button"
                    data-role="complete-commissioning"
                    :disabled="! counters.programme.unlocked"
                    @click="openSignoffSheet()"
                    class="commissioning-complete__btn">
                Complete Commissioning
            </button>
            <p x-show="! counters.programme.unlocked"
               class="commissioning-complete__meta"
               x-text="`${counters.programme.total - counters.programme.complete} item(s) still pending`"></p>
        </div>
    @endif

    {{-- Fail-reason bottom sheet (D-14 photo + note required) --}}
    @include('commissioning._commissioning-fail-sheet')

    {{-- Sign-off sheet placeholder — implemented in Plan 05 --}}
    <div data-role="signoff-sheet-slot"></div>

</div>
@endsection

@push('scripts')
<script>
    function commissioningPage(initial) {
        return {
            projectId: initial.projectId,
            programmeId: initial.programmeId,
            isOwnerOrAdmin: initial.isOwnerOrAdmin,
            locked: initial.locked,
            counters: initial.counters,
            failSheet: { open: false, itemId: null, note: '', photoFile: null, uploading: false, error: null },

            csrf() {
                return document.querySelector('meta[name="csrf-token"]').content;
            },

            // ─── Status PATCH ──────────────────────────────────────────────
            // Client-side D-14 short-circuit: fail triggers the photo+note
            // sheet rather than a bare PATCH. Server still enforces the rule
            // (422 without a note + photo) as the canonical guard.
            async patchStatus(itemId, status, note = null) {
                if (this.locked) return;

                if (status === 'fail') {
                    this.failSheet = {
                        open: true,
                        itemId,
                        note: '',
                        photoFile: null,
                        uploading: false,
                        error: null,
                    };
                    return;
                }

                const res = await fetch(`/commissioning-items/${itemId}/status`, {
                    method: 'PATCH',
                    headers: {
                        'X-CSRF-TOKEN': this.csrf(),
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ status, note }),
                });
                await this.handleStatusResponse(res, itemId);
            },

            // ─── W-12 atomic fail-with-evidence ────────────────────────────
            // Single multipart POST combining photo + note + status
            // transition; the server wraps the lot in DB::transaction so an
            // interrupted request never leaves an orphan photo on a still-
            // pending item.
            async confirmFailWithNoteAndPhoto() {
                this.failSheet.uploading = true;
                this.failSheet.error = null;

                if (! this.failSheet.photoFile || ! this.failSheet.note.trim()) {
                    this.failSheet.error = 'Photo and note are both required.';
                    this.failSheet.uploading = false;
                    return;
                }

                const fd = new FormData();
                fd.append('photo', this.failSheet.photoFile);
                fd.append('note',  this.failSheet.note);

                const res = await fetch(`/commissioning-items/${this.failSheet.itemId}/fail-with-evidence`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': this.csrf(),
                        'Accept': 'application/json',
                    },
                    body: fd,
                });
                if (! res.ok) {
                    const j = await res.json().catch(() => ({ message: 'Fail save rejected' }));
                    this.failSheet.error = j.message || 'Fail save rejected';
                    this.failSheet.uploading = false;
                    return;
                }
                await this.handleStatusResponse(res, this.failSheet.itemId);
                this.failSheet = { open: false, itemId: null, note: '', photoFile: null, uploading: false, error: null };
            },

            // ─── Shared response handler (W-08 in-place swap) ──────────────
            // Preserves the AJAX UX promised by INST-05c — no full-page
            // reload. The row data-item-id element is updated with the
            // authoritative server state directly.
            async handleStatusResponse(res, itemId) {
                if (! res.ok) return;
                const json = await res.json();
                if (json.counters) this.counters = json.counters;

                if (itemId && json.id) {
                    const row = document.querySelector(`[data-item-id="${json.id}"]`);
                    if (row) {
                        row.dataset.status = json.status || '';
                        const notePreview = row.querySelector('[data-role="note-preview"]');
                        if (notePreview) notePreview.textContent = json.notes || '';
                        const photoFlag = row.querySelector('[data-role="photo-flag"]');
                        if (photoFlag) photoFlag.hidden = ! json.evidence_photo_path;
                        // Sync the is-active pill among pass/fail/na buttons
                        const statusBtns = row.querySelectorAll('[data-role="status-btn"]');
                        statusBtns.forEach(btn => {
                            if (btn.dataset.status === json.status) {
                                btn.classList.add('is-active');
                            } else {
                                btn.classList.remove('is-active');
                            }
                        });
                    }
                }
            },

            // ─── Notes PATCH ───────────────────────────────────────────────
            async patchNotes(itemId, notes) {
                if (this.locked) return;
                await fetch(`/commissioning-items/${itemId}/notes`, {
                    method: 'PATCH',
                    headers: {
                        'X-CSRF-TOKEN': this.csrf(),
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ notes }),
                });
            },

            // ─── Sign-off sheet trigger ────────────────────────────────────
            // Dispatches a custom event that Plan 05 will listen for. Leaves
            // a working shim so the click never dies silently before Plan 05
            // ships the actual sheet implementation.
            openSignoffSheet() {
                const ev = new CustomEvent('commissioning:open-signoff-sheet', {
                    detail: { programmeId: this.programmeId },
                });
                window.dispatchEvent(ev);
            },
        };
    }
</script>
@endpush
