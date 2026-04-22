{{-- _commissioning-signoff-sheet.blade.php (Phase 16 Plan 05 Task 1 — INST-05f, INST-05g, D-10)

     Opens on the `commissioning:open-signoff-sheet` CustomEvent dispatched by
     commissioningPage().openSignoffSheet() in show.blade.php.

     Two-step flow (D-10 — preview-then-sign):
       Step 1: engineer + client review the preview snagging PDF in an iframe.
       Step 2: three client inputs (name/role/company) + signature canvas +
               certification text. On Sign, POST base64 PNG + form fields to
               the finalise endpoint (Plan 04).
       Step 3: success confirmation + link to final signed snagging PDF.

     DPI scaling (INST-05f / research §Pitfall 2):
       The canvas bitmap is scaled to window.devicePixelRatio each time the
       sheet opens and on every window resize / orientationchange. The Alpine
       factory keeps runtime branching across all three integration options
       documented in 16-02-DPI-SPIKE-NOTES.md:
         A  new window.SignaturePad(canvas, opts)         ← default today (CDN UMD)
         B  canvas.__signaturePad re-bind                 ← creagia attaches
         C  throws with a link to the CDN fallback        ← fail-loud if neither

     W-11: the sign-pad vendor bundle is loaded globally by layouts/app.blade.php.
     This partial MUST NOT re-inject it with `defer` — that would race the Alpine
     initCanvas against an unfinished script load on fast clickers.
--}}
<div x-data="signoffSheet(@js([
        'programmeId' => $programme?->id,
        'certificationText' => config('commissioning.certification_text'),
    ]))"
     x-show="open"
     x-cloak
     @commissioning:open-signoff-sheet.window="openSheet()"
     @keydown.escape.window="open = false"
     class="signoff-sheet">

    <div @click="open = false" class="signoff-sheet__backdrop"></div>

    <div class="signoff-sheet__panel"
         x-transition:enter="transform transition ease-out duration-200"
         x-transition:enter-start="translate-y-full"
         x-transition:enter-end="translate-y-0">

        <div class="signoff-sheet__header">
            <h3 class="signoff-sheet__title" x-text="step === 1 ? 'Review snagging report' : (step === 2 ? 'Client sign-off' : 'Signed off')"></h3>
            <button type="button" @click="open = false" class="signoff-sheet__close" aria-label="Close">&times;</button>
        </div>

        {{-- ── Step 1: preview PDF ─────────────────────────────────────────── --}}
        <template x-if="step === 1">
            <div class="signoff-sheet__body">
                <p class="signoff-sheet__hint">
                    The preview below shows every commissioning item plus any failed items
                    listed as "To Be Resolved". The client should read this before signing.
                </p>
                <div class="signoff-sheet__preview">
                    <template x-if="previewUrl">
                        <iframe :src="previewUrl" class="signoff-sheet__iframe" title="Snagging PDF preview"></iframe>
                    </template>
                    <template x-if="! previewUrl && ! previewError">
                        <div class="signoff-sheet__preview-status">Generating preview…</div>
                    </template>
                    <template x-if="previewError">
                        <div class="signoff-sheet__preview-status signoff-sheet__preview-status--error"
                             x-text="previewError"></div>
                    </template>
                </div>
                <div class="signoff-sheet__actions">
                    <button type="button" @click="open = false" class="btn btn-outline">Cancel</button>
                    <button type="button"
                            @click="step = 2"
                            :disabled="! previewUrl"
                            class="btn btn-primary">
                        Continue to signature
                    </button>
                </div>
            </div>
        </template>

        {{-- ── Step 2: signature capture ───────────────────────────────────── --}}
        <template x-if="step === 2">
            <div class="signoff-sheet__body">
                <div class="signoff-sheet__fields">
                    <label class="signoff-sheet__field">
                        <span class="signoff-sheet__label">Client name</span>
                        <input type="text" x-model="clientName" name="client_name"
                               maxlength="200" required
                               class="signoff-sheet__input">
                    </label>
                    <label class="signoff-sheet__field">
                        <span class="signoff-sheet__label">Role</span>
                        <input type="text" x-model="clientRole" name="client_role"
                               maxlength="200" required
                               class="signoff-sheet__input">
                    </label>
                    <label class="signoff-sheet__field">
                        <span class="signoff-sheet__label">Company</span>
                        <input type="text" x-model="clientCompany" name="client_company"
                               maxlength="200" required
                               class="signoff-sheet__input">
                    </label>
                </div>

                {{-- D-15 — certification text is snapshotted on the signoff row at
                     save time; display the live config value to the client so the
                     text they see on-screen is the text they agree to. --}}
                <p class="signoff-sheet__certification"
                   x-text="certificationText"
                   data-role="certification-text"></p>

                <div class="signoff-sheet__signature">
                    <p class="signoff-sheet__label">Signature</p>
                    <div class="signoff-sheet__canvas-wrap">
                        <canvas x-ref="signatureCanvas"
                                class="signoff-sheet__canvas"
                                data-sign-pad></canvas>
                    </div>
                    <div class="signoff-sheet__canvas-actions">
                        <button type="button" @click="clearCanvas()" class="signoff-sheet__clear">Clear</button>
                        <span x-show="isEmpty" class="signoff-sheet__hint signoff-sheet__hint--muted">Sign above</span>
                    </div>
                </div>

                <template x-if="errorMessage">
                    <p class="signoff-sheet__error" x-text="errorMessage"></p>
                </template>

                <div class="signoff-sheet__actions">
                    <button type="button" @click="step = 1" class="btn btn-outline">Back</button>
                    <button type="button"
                            @click="submitFinalise()"
                            :disabled="submitting || ! canSubmit"
                            class="btn btn-primary">
                        <span x-show="! submitting">Sign &amp; finalise</span>
                        <span x-show="submitting">Submitting…</span>
                    </button>
                </div>
            </div>
        </template>

        {{-- ── Step 3: success ─────────────────────────────────────────────── --}}
        <template x-if="step === 3">
            <div class="signoff-sheet__body signoff-sheet__body--success">
                <p class="signoff-sheet__success">Commissioning signed off.</p>
                <p class="signoff-sheet__hint">The final snagging PDF is now available to download.</p>
                <a :href="finalPdfUrl" target="_blank" rel="noopener"
                   class="btn btn-primary">Download signed snagging PDF</a>
                <button type="button"
                        @click="window.location.reload()"
                        class="signoff-sheet__reload">Reload checklist</button>
            </div>
        </template>

    </div>
</div>

{{-- W-11 — the sign-pad.min.js asset is loaded globally via layouts/app.blade.php
     (creagia's bundle) plus a CDN UMD copy of signature_pad@5.1.3 (Option C, see
     16-02-DPI-SPIKE-NOTES.md). This partial must NOT re-inject either with
     `defer` because the Alpine factory's initCanvas would race against an
     unfinished script load on fast clickers. --}}

@once
<style>
    /* Signoff bottom-sheet — mirrors the fail-sheet idiom (see
       _commissioning-fail-sheet.blade.php) but larger because of the PDF
       iframe + canvas. Full-bleed on mobile, centered on tablet. */
    .signoff-sheet            { position: fixed; inset: 0; z-index: 50; display: flex; align-items: flex-end; }
    .signoff-sheet__backdrop  { position: absolute; inset: 0; background: rgba(0,0,0,.4); }
    .signoff-sheet__panel     { position: relative; width: 100%; max-width: 48rem; margin: 0 auto; background: #fff; border-top-left-radius: 12px; border-top-right-radius: 12px; box-shadow: var(--shadow-md); padding: 1.25rem; display: flex; flex-direction: column; gap: 1rem; max-height: 90vh; overflow-y: auto; }
    .signoff-sheet__header    { display: flex; align-items: center; justify-content: space-between; }
    .signoff-sheet__title     { font-weight: 600; font-size: 1.05rem; }
    .signoff-sheet__close     { background: transparent; border: none; color: var(--text-faint); font-size: 1.5rem; cursor: pointer; line-height: 1; }
    .signoff-sheet__body      { display: flex; flex-direction: column; gap: .85rem; }
    .signoff-sheet__body--success { align-items: center; text-align: center; padding: 1rem 0; }
    .signoff-sheet__hint      { font-size: .85rem; color: var(--text-muted); }
    .signoff-sheet__hint--muted { color: var(--text-faint); font-style: italic; }
    .signoff-sheet__preview   { border: 1px solid var(--border); border-radius: 6px; height: 50vh; background: #F8FAFC; overflow: hidden; }
    .signoff-sheet__iframe    { width: 100%; height: 100%; border: 0; }
    .signoff-sheet__preview-status { display: flex; align-items: center; justify-content: center; height: 100%; font-size: .85rem; color: var(--text-muted); padding: 1rem; text-align: center; }
    .signoff-sheet__preview-status--error { color: var(--danger); }
    .signoff-sheet__fields    { display: grid; grid-template-columns: 1fr; gap: .75rem; }
    @media (min-width: 640px) { .signoff-sheet__fields { grid-template-columns: repeat(3, 1fr); } }
    .signoff-sheet__field     { display: flex; flex-direction: column; gap: .25rem; font-size: .875rem; }
    .signoff-sheet__label     { font-size: .75rem; font-weight: 600; color: var(--text); }
    .signoff-sheet__input     { width: 100%; border: 1px solid var(--border); border-radius: 6px; padding: .5rem; font-size: .875rem; font-family: inherit; }
    .signoff-sheet__certification { font-size: .75rem; color: var(--text); background: #F8FAFC; padding: .6rem .75rem; border-left: 3px solid var(--teal); border-radius: 4px; margin: 0; }
    .signoff-sheet__signature { display: flex; flex-direction: column; gap: .25rem; }
    .signoff-sheet__canvas-wrap { border: 1px solid var(--border); border-radius: 6px; background: #fff; }
    .signoff-sheet__canvas    { width: 100%; height: 10rem; display: block; touch-action: none; }
    .signoff-sheet__canvas-actions { display: flex; justify-content: space-between; align-items: center; font-size: .75rem; padding-top: .25rem; }
    .signoff-sheet__clear     { background: transparent; border: none; color: var(--text-muted); text-decoration: underline; cursor: pointer; font-size: .75rem; }
    .signoff-sheet__error     { font-size: .85rem; color: var(--danger); margin: 0; }
    .signoff-sheet__success   { font-size: 1.05rem; font-weight: 600; color: #15803D; }
    .signoff-sheet__actions   { display: flex; justify-content: flex-end; gap: .5rem; padding-top: .25rem; }
    .signoff-sheet__reload    { background: transparent; border: none; color: var(--text-muted); text-decoration: underline; cursor: pointer; font-size: .75rem; margin-top: .5rem; }
</style>
<script>
    // Signoff bottom-sheet Alpine factory. Registered once per page via Blade once-directive.
    //
    // Lifecycle:
    //   openSheet() — dispatched from commissioningPage.openSignoffSheet() via
    //     the commissioning:open-signoff-sheet CustomEvent. Kicks off the
    //     preview POST immediately; step flips to 2 once the engineer clicks
    //     Continue to signature.
    //   initCanvas() — runs on the first transition to step=2. Performs the
    //     DPI-scaled canvas resize + SignaturePad binding per
    //     16-02-DPI-SPIKE-NOTES.md.
    //   submitFinalise() — reads signaturePad.toDataURL('image/png') and POSTs
    //     to /install-programmes/{id}/commissioning/signoff/finalise.
    function signoffSheet(initial) {
        return {
            open: false,
            step: 1,                // 1 preview · 2 sign · 3 done
            programmeId: initial.programmeId,
            certificationText: initial.certificationText,
            previewUrl: null,
            previewError: null,
            clientName: '',
            clientRole: '',
            clientCompany: '',
            signaturePad: null,
            isEmpty: true,
            submitting: false,
            errorMessage: null,
            finalPdfUrl: null,
            _resizeHandler: null,
            _canvasWatcher: null,

            get canSubmit() {
                return this.clientName.trim().length > 0
                    && this.clientRole.trim().length > 0
                    && this.clientCompany.trim().length > 0
                    && ! this.isEmpty;
            },

            csrf() {
                const tag = document.querySelector('meta[name="csrf-token"]');
                return tag ? tag.content : '';
            },

            async openSheet() {
                this.open = true;
                this.step = 1;
                this.previewUrl = null;
                this.previewError = null;
                this.errorMessage = null;
                this.finalPdfUrl = null;
                this.clientName = '';
                this.clientRole = '';
                this.clientCompany = '';
                this.isEmpty = true;

                // Install a one-shot watcher so we initialise the canvas on the
                // first transition to step=2 and every time it's re-entered.
                if (! this._canvasWatcher) {
                    this._canvasWatcher = this.$watch('step', (val) => {
                        if (val === 2) {
                            this.$nextTick(() => this.initCanvas());
                        }
                    });
                }

                // Kick off preview generation immediately.
                try {
                    const res = await fetch(`/install-programmes/${this.programmeId}/commissioning/signoff/preview`, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': this.csrf(),
                            'Accept': 'application/json',
                        },
                    });
                    if (! res.ok) {
                        const body = await res.json().catch(() => ({ message: 'Preview failed.' }));
                        this.previewError = body.message || 'Preview failed.';
                        return;
                    }
                    const json = await res.json();
                    this.previewUrl = json.preview_url;
                } catch (err) {
                    this.previewError = 'Preview request failed — check connectivity and retry.';
                }
            },

            initCanvas() {
                // ── DPI-scaled canvas (research §Pitfall 2, verbatim snippet) ──
                //
                // B-06 — runtime branching over the three DPI integration options
                // documented in 16-02-DPI-SPIKE-NOTES.md. Keep all three paths in
                // code so a post-deploy iOS discovery can flip the option without
                // a code change.
                const canvas = this.$refs.signatureCanvas;
                if (! canvas) return;

                const resize = () => {
                    const ratio = Math.max(window.devicePixelRatio || 1, 1);
                    canvas.width = canvas.offsetWidth * ratio;
                    canvas.height = canvas.offsetHeight * ratio;
                    canvas.getContext('2d').scale(ratio, ratio);

                    const padOptions = {
                        minWidth: 0.5,
                        maxWidth: 2.0,
                        penColor: 'rgb(0,0,0)',
                        backgroundColor: 'rgba(255,255,255,0)',
                    };

                    // Option A — CDN UMD / creagia bundle re-exports SignaturePad globally.
                    if (typeof window.SignaturePad !== 'undefined') {
                        this.signaturePad = new window.SignaturePad(canvas, padOptions);
                    }
                    // Option B — creagia attaches the instance directly to the canvas.
                    else if (canvas.__signaturePad) {
                        this.signaturePad = canvas.__signaturePad;
                        // Re-apply our options — creagia's defaults differ.
                        try { Object.assign(this.signaturePad, padOptions); } catch (_) {}
                    }
                    // Option C — neither global nor canvas-attached instance available.
                    // Fail loud so the engineer sees the missing dependency rather
                    // than a silent no-op signature pad.
                    else {
                        throw new Error(
                            'SignaturePad not loaded. Plan 02 DPI spike chose Option C — '
                            + 'confirm the CDN <script src="https://cdn.jsdelivr.net/npm/'
                            + 'signature_pad@5.1.3/dist/signature_pad.umd.min.js"> is '
                            + 'present in layouts/app.blade.php before the Alpine bundle.'
                        );
                    }

                    this.signaturePad.clear();
                    this.isEmpty = true;
                    this.signaturePad.addEventListener('endStroke', () => {
                        this.isEmpty = this.signaturePad.isEmpty();
                    });
                };

                resize();

                // Register resize + orientationchange listeners. Detach any
                // previous handler to avoid leaks when the sheet is re-opened.
                if (this._resizeHandler) {
                    window.removeEventListener('resize', this._resizeHandler);
                    window.removeEventListener('orientationchange', this._resizeHandler);
                }
                this._resizeHandler = resize;
                window.addEventListener('resize', resize);
                window.addEventListener('orientationchange', resize);
            },

            clearCanvas() {
                if (this.signaturePad) {
                    this.signaturePad.clear();
                    this.isEmpty = true;
                }
            },

            async submitFinalise() {
                this.errorMessage = null;
                if (! this.canSubmit || ! this.signaturePad) return;
                this.submitting = true;

                const dataUrl = this.signaturePad.toDataURL('image/png');

                try {
                    const res = await fetch(`/install-programmes/${this.programmeId}/commissioning/signoff/finalise`, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': this.csrf(),
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify({
                            client_name:          this.clientName,
                            client_role:          this.clientRole,
                            client_company:       this.clientCompany,
                            signature_png_base64: dataUrl,
                        }),
                    });

                    if (! res.ok) {
                        const body = await res.json().catch(() => ({ message: 'Finalise failed.' }));
                        this.errorMessage = body.message || 'Finalise failed.';
                        this.submitting = false;
                        return;
                    }

                    const json = await res.json();
                    this.finalPdfUrl = json.final_pdf_url;
                    this.step = 3;
                } catch (err) {
                    this.errorMessage = 'Finalise request failed — check connectivity and retry.';
                } finally {
                    this.submitting = false;
                }
            },
        };
    }
</script>
@endonce
