@extends('layouts.app')

@section('title', 'Edit Stencil')

@push('styles')
<style>
/*
 * Device Stencil edit — Phase 24 Plan 05 (DRAW-51). Two-column layout is
 * genuinely new markup — no existing admin screen has this shape (UI-SPEC
 * Component Inventory point 3). Port-table card + preview card both inherit
 * the shared .card 20px 22px padding (app.blade.php:490) unmodified.
 */

.stc-edit-grid {
    display: grid;
    grid-template-columns: 60% 40%;
    gap: 20px;
    align-items: start;
}
.stc-edit-preview {
    position: sticky;
    top: 84px; /* clears the fixed .app-header + sticky .edit-action-bar */
}

/* UI-SPEC Component Inventory point 3 — stack to single column under 900px. */
@media (max-width: 900px) {
    .stc-edit-grid {
        grid-template-columns: 1fr;
    }
    .stc-edit-preview {
        position: static;
    }
}

.stc-guard-banner {
    margin-bottom: 16px;
}

.stc-preview-state {
    font-size: var(--fs-small);
    color: var(--text-muted);
    margin-bottom: 12px;
}
.stc-preview-frame {
    border: 1px solid var(--ink-200);
    border-radius: var(--radius-sm);
    background: var(--surface-soft);
    padding: 12px;
    min-height: 180px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.stc-preview-frame svg { max-width: 100%; height: auto; }
.stc-preview-frame[data-loading="true"] { opacity: .5; }

.stc-footer-actions {
    display: flex;
    gap: 8px;
    align-items: center;
    margin-top: 16px;
}

/*
 * Phase 24 Plan 07 (DRAW-53) — D-04 promote-readiness reason lines. Hard
 * block = --danger token, "Blocked: " prefix baked into the copy itself
 * (UI-SPEC Copywriting Contract). Soft warn = --warning token, non-blocking.
 */
.stc-promote-reasons {
    margin-top: 12px;
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.stc-promote-reason--blocking {
    font-size: var(--fs-small);
    color: var(--danger);
}
.stc-promote-reason--warning {
    font-size: var(--fs-small);
    color: var(--warning);
}
</style>
@endpush

@push('scripts')
<script>
/*
 * Root Alpine component for the stencil edit screen (Phase 24 Plan 05,
 * D-01/D-02). ports[] is the single source of truth (D-01 — NOT
 * drag-on-canvas). $watch fires the 600ms-debounced, AbortController-
 * cancelled preview POST against admin.device-stencils.preview (D-16 —
 * returns rendered SVG, not mxGraph XML) every time the array changes.
 * 600ms is copied verbatim from resources/views/surveys/show.blade.php's
 * debouncedAutosave() — deliberately NOT the 200ms ⌘K-search value, because
 * this triggers a real network round-trip through the server-side builder
 * (D-02: "the preview must not be able to lie").
 */
function stencilPortEditor(initialPorts, previewUrl) {
    return {
        ports: initialPorts || [],
        previewSvg: null,
        previewState: 'idle', // idle | loading | clean | error
        _previewAbort: null,
        _debounceTimer: null,

        init() {
            this.$watch('ports', () => this.debouncedPreview(), { deep: true });
            this.runPreview();
        },

        addPort() {
            this.ports.push({
                label: '',
                side: 'left',
                connector_type: '',
                signal_type: '',
                direction: 'io',
                sort_order: this.ports.length + 1,
                port_id: '',
                x_pct: null,
                y_pct: null,
            });
        },

        removePort(idx) {
            this.ports.splice(idx, 1);
        },

        debouncedPreview() {
            if (this._debounceTimer) clearTimeout(this._debounceTimer);
            this._debounceTimer = setTimeout(() => {
                this.runPreview();
            }, 600);
        },

        async runPreview() {
            // Cancel a superseded in-flight request so a late response can
            // never clobber a newer one.
            if (this._previewAbort) {
                this._previewAbort.abort();
            }
            const controller = new AbortController();
            this._previewAbort = controller;
            this.previewState = 'loading';

            try {
                const resp = await fetch(previewUrl, {
                    method: 'POST',
                    signal: controller.signal,
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'image/svg+xml',
                    },
                    body: JSON.stringify({ ports: this.ports }),
                });
                if (!resp.ok) {
                    throw new Error('Preview request failed with status ' + resp.status);
                }
                this.previewSvg = await resp.text();
                this.previewState = 'clean';
            } catch (err) {
                if (err && err.name === 'AbortError') {
                    // Superseded request, not a real failure — swallow silently.
                    return;
                }
                // Never blank the pane on failure — keep the last good
                // render visible beneath the error banner (UI-SPEC Copy
                // Contract: "Preview failed to render — showing the last
                // successful preview.").
                this.previewState = 'error';
            }
        },

        rowTintClass(port) {
            const blocking = !port.label || !port.connector_type || !port.signal_type || !port.direction;
            if (blocking) return 'stc-port-row--danger';

            const warning = port.signal_type === 'unclassified' || (!port.x_pct && !port.y_pct);
            if (warning) return 'stc-port-row--warning';

            return '';
        },

        /*
         * Phase 24 Plan 07 (DRAW-53) — client-side mirror of
         * StencilPromotionValidator's D-04 hard-block rules, driving the
         * Promote button's disabled state and the reason lines above it.
         * UX ONLY — this array is never trusted server-side. promote()
         * re-runs the full check unconditionally on every request (T-24-17);
         * this method exists purely so the button reflects live edits
         * without a round-trip, and so a hostile client tampering with this
         * JS gains nothing (the server re-check is the only real boundary).
         */
        promotionBlockingReasons() {
            const reasons = [];

            if (this.ports.length === 0) {
                reasons.push('Blocked: this stencil has zero ports.');

                return reasons;
            }

            const fieldLabels = {
                label: 'label',
                connector_type: 'connector type',
                signal_type: 'signal type',
                direction: 'direction',
            };

            Object.keys(fieldLabels).forEach((field) => {
                const missing = this.ports.filter((p) => !p[field]).length;
                if (missing > 0) {
                    reasons.push(missing === 1
                        ? `Blocked: 1 port is missing a ${fieldLabels[field]}.`
                        : `Blocked: ${missing} ports are missing a ${fieldLabels[field]}.`);
                }
            });

            const seen = {};
            const duplicateIds = [];
            this.ports.forEach((p) => {
                const id = p.port_id;
                if (!id) return;
                if (seen[id] && duplicateIds.indexOf(id) === -1) {
                    duplicateIds.push(id);
                }
                seen[id] = true;
            });
            duplicateIds.forEach((id) => reasons.push(`Blocked: duplicate port ID "${id}".`));

            return reasons;
        },
    };
}
</script>
@endpush

@section('content')
<x-edit-action-bar :cancel-url="route('admin.device-stencils.index')">
    <x-slot name="title">
        {{ trim(($stencil->manufacturer ?? '') . ' ' . ($stencil->model ?? '')) ?: 'Stencil #'.$stencil->id }}
    </x-slot>
</x-edit-action-bar>

@if (session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if (session('warning'))
    <div class="alert alert-warning">{{ session('warning') }}</div>
@endif
@if ($errors->any())
    <div class="alert alert-error">
        <ul style="margin:0;padding-left:1.1rem;">
            @foreach ($errors->all() as $err)
                <li>{{ $err }}</li>
            @endforeach
        </ul>
    </div>
@endif

@php
    // D-17 guard trigger — MUST stay identical to the server-side condition in
    // DeviceStencilController::update(). A stencil with zero ports has no
    // hand-built artwork to protect, so the guard must not engage: 91 of the 96
    // seeded stencils are engineer-curated zero-port stubs, and firing on those
    // is exactly the friction UAT Gap 2 identified. `ports` is eager-loaded by
    // edit(), so isNotEmpty() costs no extra query.
    $isCurated = $stencil->source === \App\Models\DeviceStencil::SOURCE_ENGINEER_CURATED
        && $stencil->ports->isNotEmpty();
@endphp

<div class="stc-edit-grid"
     {{-- Use Js::from(), NOT @json(). @json() emits raw double quotes, which
          terminate this double-quoted HTML attribute early the moment the
          stencil has any ports — producing malformed HTML, an unparseable
          Alpine expression ("SyntaxError: Unexpected token ';'"), and a dead
          component (no port table, no live preview, no promote state). It
          looked fine only because a zero-port stencil renders @json([]) as
          "[]", which contains no quotes. Js::from() escapes for exactly this
          context. Found in browser UAT 2026-08-14; PHPUnit never caught it
          because feature tests assert server HTML and never execute JS. --}}
     x-data="stencilPortEditor({{ \Illuminate\Support\Js::from($stencil->ports->toArray()) }}, '{{ route('admin.device-stencils.preview', $stencil) }}')">

    {{-- ── Left column (~60%) — port table, source of truth (D-01) ────────── --}}
    <div>
        {{-- ⚠ D-17 GUARD — UI half. Persistent warning banner for an
             engineer-curated stencil; no banner at all for every other
             source value (the ordinary stub-curation path stays a
             zero-friction single-click save). --}}
        @if ($isCurated)
            <div class="alert alert-warning stc-guard-banner">
                This stencil has engineer-curated artwork. Saving will replace it with a generated shape. The previous version is kept in the audit trail.
            </div>
        @endif

        <form method="POST"
              action="{{ route('admin.device-stencils.update', $stencil) }}"
              id="stencil-ports-form"
              x-ref="form"
              @submit="if ({{ $isCurated ? 'true' : 'false' }} && $refs.confirmField.value !== '1') { $event.preventDefault(); }">
            @csrf
            @method('PUT')
            <input type="hidden" name="confirm_regenerate" x-ref="confirmField" value="">

            @include('admin.device-stencils._port-table')

            <div class="stc-footer-actions">
                @if ($isCurated)
                    {{-- Explicit confirm step, set imperatively on the hidden
                         field BEFORE submit — never via a reactive :value
                         binding, so there is no timing race between Alpine's
                         DOM patch and the browser's form serialisation. An
                         accidental Enter-key submit hits the @submit guard
                         above and is blocked because confirmField.value is
                         still empty. --}}
                    <button type="submit" class="btn btn-teal"
                            @click.prevent="
                                if (window.confirm('This stencil is engineer-curated. Saving will replace its existing artwork with a generated shape. Continue?')) {
                                    $refs.confirmField.value = '1';
                                    $refs.form.submit();
                                }
                            ">
                        Save Ports
                    </button>
                @else
                    <button type="submit" class="btn btn-teal">Save Ports</button>
                @endif
            </div>
        </form>

        {{-- ── Promote / Discard & Regenerate (Plan 24-07, DRAW-53, D-04) ──
             Separate <form> elements from the ports-Save form above (native
             HTML forbids nesting <form> elements) — both POST to their own
             admin.device-stencils.{promote,discard} routes. Visually still
             reads as "the same footer row" per 24-05's placeholder comment,
             because .stc-footer-actions is a flex row and each <form> here
             is just another flex item containing one button. --}}
        <div class="stc-promote-reasons" x-show="promotionBlockingReasons().length > 0">
            <template x-for="reason in promotionBlockingReasons()" :key="reason">
                <div class="stc-promote-reason--blocking" x-text="reason"></div>
            </template>
        </div>

        <div class="stc-footer-actions">
            <form method="POST" action="{{ route('admin.device-stencils.promote', $stencil) }}">
                @csrf
                <button type="submit" class="btn btn-primary" :disabled="promotionBlockingReasons().length > 0">
                    Promote to Engineer-Curated
                </button>
            </form>

            <form method="POST"
                  action="{{ route('admin.device-stencils.discard', $stencil) }}"
                  data-confirm="Discard all edits and regenerate this stencil from its category template?"
                  data-confirm-label="Discard &amp; Regenerate"
                  data-confirm-danger="1">
                @csrf
                <button type="submit" class="btn btn-danger-outline">Discard &amp; Regenerate</button>
            </form>

            <a href="{{ route('admin.device-stencils.index') }}" class="btn btn-outline btn-sm">Cancel</a>
        </div>
    </div>

    {{-- ── Right column (~40%), sticky — live server-rendered preview (D-02/D-16) ── --}}
    <div class="card stc-edit-preview">
        <div class="section-heading">Live Preview</div>

        <div class="stc-preview-state">
            <span x-show="previewState === 'loading'" style="opacity:.5;">Rendering…</span>
            <span x-show="previewState === 'clean'">Up to date</span>
            <span x-show="previewState === 'idle'">&nbsp;</span>
        </div>

        <div x-show="previewState === 'error'" class="alert alert-error" style="margin-bottom:12px;">
            Preview failed to render — showing the last successful preview.
        </div>

        <div class="stc-preview-frame" :data-loading="previewState === 'loading'">
            <div x-show="previewSvg" x-html="previewSvg"></div>
            <span x-show="!previewSvg" class="stc-muted">No preview yet.</span>
        </div>

        {{-- ── Manufacturer Logo (Plan 24-06, DRAW-52, D-12/D-15) ────────────
             Sits below the preview pane, same right column — does not
             restructure the two-column grid Plan 24-05 built. --}}
        <div class="stc-logo-widget" style="margin-top:20px;padding-top:20px;border-top:1px solid var(--ink-200);">
            <div class="section-heading">Manufacturer Logo</div>

            @if ($stencil->logo_path)
                <img src="{{ asset($stencil->logo_path) }}" alt="" style="width:64px;height:64px;object-fit:contain;display:block;margin-bottom:10px;">
            @else
                @php
                    $fallbackAssetPath = app(\App\Services\Drawings\ManufacturerLogoResolver::class)->resolveAssetPath($stencil->manufacturer);
                @endphp
                @if ($fallbackAssetPath)
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;opacity:.6;">
                        <img src="{{ asset($fallbackAssetPath) }}" alt="" style="width:48px;height:48px;object-fit:contain;">
                        <span class="stc-muted" style="font-size:var(--fs-small);">Using the built-in {{ $stencil->manufacturer }} wordmark until a custom logo is uploaded.</span>
                    </div>
                @endif
            @endif

            <form method="POST"
                  action="{{ route('admin.device-stencils.upload-logo', $stencil) }}"
                  enctype="multipart/form-data">
                @csrf
                <input type="file" name="logo" accept=".svg,.png,.jpg,.jpeg">
                <button type="submit" class="btn btn-outline btn-sm">Upload</button>
            </form>

            <p class="stc-muted" style="font-size:var(--fs-small);margin-top:6px;">
                PNG or SVG, up to 2MB. SVG uploads are automatically sanitised (scripts and embedded event handlers are stripped).
            </p>

            @if ($errors->has('logo'))
                <div class="alert alert-error" style="margin-top:8px;">{{ $errors->first('logo') }}</div>
            @endif
        </div>
    </div>

</div>
@endsection
