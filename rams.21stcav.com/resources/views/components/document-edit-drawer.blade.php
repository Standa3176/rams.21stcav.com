@props([
    /** Adapter key: rams | worksheet | om | survey | cable */
    'type'    => null,
    /** Document primary-key id */
    'id'      => null,
    /** Human label for drawer header, e.g. "RAMS", "Worksheet", "O&M Manual" */
    'label'   => 'Document',
    /** Whether the trigger + drawer should render. Caller decides based on status. */
    'visible' => true,
    /** Optional override for the trigger button class/label */
    'buttonLabel' => '✎ Edit via chat',
])

@if($visible && $type && $id)
{{--
    Shared chat drawer for the Document Edit pipeline.

    Trigger button uses vanilla window.dispatchEvent('open-doc-chat:{type}:{id}')
    so multiple drawers on the same page (unlikely but possible) route to the
    right one. Drawer listens on window with the same scoped event name.

    Alpine factory registered once per request via @once — safe to include this
    component N times on a single page (e.g. rare cases) without redefining.
--}}

<button type="button"
        class="chat-fab"
        onclick="window.dispatchEvent(new CustomEvent('open-doc-chat', { detail: { type: {{ json_encode($type) }}, id: {{ (int) $id }} } }))"
        aria-label="Edit {{ $label }} via AI chat">
    {{ $buttonLabel }}
</button>

<div x-data="docChat({
        documentType: @js($type),
        documentId:   {{ (int) $id }},
        label:        @js($label),
        routes: {
            createThread:  @js(route('documents.threads.create',      ['type' => $type, 'id' => $id])),
            parseTemplate: @js(route('documents.threads.parse',       ['type' => $type, 'id' => $id, 'thread' => '__TID__'])),
            showTemplate:  @js(route('documents.changes.show',        ['type' => $type, 'id' => $id, 'changeSet' => '__CID__'])),
            applyTemplate: @js(route('documents.changes.apply',       ['type' => $type, 'id' => $id, 'changeSet' => '__CID__'])),
        },
        csrf: @js(csrf_token()),
     })"
     x-init="init()"
     @open-doc-chat.window="$event.detail && $event.detail.type === documentType && Number($event.detail.id) === documentId && openDrawer()"
     @keydown.escape.window="open && closeDrawer()">

    <div class="chat-drawer-backdrop"
         :class="open ? 'is-open' : ''"
         @click="closeDrawer()"
         aria-hidden="true"></div>

    <aside class="chat-drawer"
           :class="open ? 'is-open' : ''"
           role="dialog"
           :aria-label="'Edit ' + label + ' via AI chat'"
           aria-modal="true">

        <header class="chat-drawer-hdr">
            <div>
                <strong>Edit <span x-text="label"></span> via chat</strong>
                <div class="chat-drawer-hdr-sub">
                    Preview every change before apply · regenerates the document
                </div>
            </div>
            <button type="button" @click="closeDrawer()" aria-label="Close">✕</button>
        </header>

        <div class="chat-drawer-body" x-ref="body">
            <template x-if="messages.length === 0">
                <div class="chat-empty">
                    <p>Describe the change you want to make, for example:</p>
                    <p><code>Set the engineer name to John Smith</code></p>
                    <p><code>Add a note about working at height in Room 3</code></p>
                    <p style="opacity:.7;margin-top:.75rem;font-size:.75rem;">
                        Each proposed change previews before it's applied.
                    </p>
                </div>
            </template>

            <template x-for="(m, idx) in messages" :key="idx">
                <div :class="'chat-msg chat-msg--' + m.role">
                    <div x-text="m.content" style="white-space:pre-wrap;"></div>

                    {{-- Inline diff preview (collapsible) --}}
                    <template x-if="m.diff">
                        <details class="chat-diff">
                            <summary>Show proposed changes</summary>
                            <div x-html="renderDiff(m.diff)"></div>
                        </details>
                    </template>

                    {{-- Apply action --}}
                    <template x-if="m.changeSetId && !m.applied && !m.stale">
                        <button type="button"
                                class="chat-apply-pill"
                                :disabled="applying === m.changeSetId"
                                @click="applyChanges(m)">
                            <span x-show="applying !== m.changeSetId">✓ Apply these changes</span>
                            <span x-show="applying === m.changeSetId">Applying…</span>
                        </button>
                    </template>

                    {{-- Applied state --}}
                    <template x-if="m.applied">
                        <span class="chat-apply-pill chat-apply-pill--applied">✓ Applied — regenerating</span>
                    </template>

                    {{-- Stale base revision (409) — offer rebase-apply first, restart as fallback --}}
                    <template x-if="m.stale && !m.applied">
                        <div class="chat-apply-stale-actions" style="display:flex;gap:.4rem;flex-wrap:wrap;">
                            <button type="button"
                                    class="chat-apply-pill"
                                    :disabled="applying === m.changeSetId"
                                    @click="applyChanges(m, { rebase: true })">
                                <span x-show="applying !== m.changeSetId">↻ Apply on current revision</span>
                                <span x-show="applying === m.changeSetId">Applying…</span>
                            </button>
                            <button type="button" class="chat-apply-pill chat-apply-pill--restart" @click="restart()">
                                ⟳ Restart conversation
                            </button>
                        </div>
                    </template>
                </div>
            </template>

            <template x-if="sending">
                <div class="chat-msg chat-msg--assistant" style="opacity:.7;">
                    <em>Thinking…</em>
                </div>
            </template>
        </div>

        <form class="chat-drawer-ftr" @submit.prevent="send()">
            <textarea x-ref="input"
                      x-model="input"
                      @keydown.enter.prevent="if (!$event.shiftKey) send()"
                      @input="autoSize($event.target)"
                      placeholder="Describe a change…"
                      rows="1"
                      :disabled="sending"></textarea>
            <button type="submit" :disabled="sending || !input.trim()">Send</button>
        </form>
    </aside>
</div>

{{--
    Script block registered once per request. Scoped @vite ensures Alpine is
    loaded even on layouts that don't globally include it.
--}}
@once
    @push('scripts')
    @vite(['resources/js/app.js'])
    <script>
        // Defensive factory registration — covers both timings:
        //   (a) Alpine not yet loaded → register listener for alpine:init
        //   (b) Alpine already running (another bundle got there first, or this
        //       script is late in the stack) → register the factory immediately.
        //
        // Without (b), late-arriving scripts silently miss the alpine:init
        // event, leaving x-data="docChat(...)" bound to an undefined factory.
        // Alpine then swallows the error, the button dispatches its event into
        // the void, and the drawer does nothing when clicked.
        (function registerDocChatFactory() {
            const define = (AlpineRef) => {
                AlpineRef.data('docChat', (config) => ({
                    _factoryLoaded: true,
                open:         false,
                threadId:     null,
                messages:     [],
                input:        '',
                sending:      false,
                applying:     null,
                // Expose config values on the reactive scope so template
                // expressions like @open-doc-chat.window="... type === documentType"
                // can see them. Previously these stayed in the factory closure
                // and the listener short-circuited on 'rams' === undefined.
                label:        config.label,
                documentType: config.documentType,
                documentId:   config.documentId,
                _busy:        false,
                _storageKey:  `docChat:${config.documentType}:${config.documentId}`,

                init() {
                    // Restore thread + messages from sessionStorage if present.
                    try {
                        const raw = sessionStorage.getItem(this._storageKey);
                        if (raw) {
                            const saved = JSON.parse(raw);
                            if (saved && saved.threadId) {
                                this.threadId = saved.threadId;
                                this.messages = Array.isArray(saved.messages) ? saved.messages : [];
                            }
                        }
                    } catch (e) { /* ignore corrupt state */ }

                    // Auto-open drawer when arriving with ?chat=1 (e.g. from
                    // a project page shortcut). Defer to next tick so the
                    // drawer DOM and event listeners are ready.
                    try {
                        const params = new URLSearchParams(window.location.search);
                        if (params.get('chat') === '1') {
                            this.$nextTick(() => this.openDrawer());
                        }
                    } catch (e) { /* ignore — non-critical */ }
                },

                async openDrawer() {
                    this.open = true;
                    await this.ensureThread();
                    this.$nextTick(() => this.$refs.input?.focus());
                    this._scroll();
                },

                closeDrawer() {
                    this.open = false;
                },

                async ensureThread() {
                    if (this.threadId || this._busy) return;
                    this._busy = true;
                    try {
                        const res = await fetch(config.routes.createThread, {
                            method:  'POST',
                            headers: {
                                'X-CSRF-TOKEN': config.csrf,
                                'Accept':       'application/json',
                            },
                        });
                        if (!res.ok) {
                            const err = await res.json().catch(() => ({}));
                            this._pushError('Could not start chat: ' + (err.message || res.status));
                            return;
                        }
                        const body = await res.json();
                        this.threadId = body.thread?.id ?? null;
                        this._persist();
                    } catch (e) {
                        this._pushError('Network error starting chat.');
                    } finally {
                        this._busy = false;
                    }
                },

                async send() {
                    const msg = this.input.trim();
                    if (!msg || this.sending) return;
                    if (!this.threadId) await this.ensureThread();
                    if (!this.threadId) return;

                    this.messages.push({ role: 'user', content: msg });
                    this.input   = '';
                    this.sending = true;
                    this._persist();
                    this._scroll();

                    const url = config.routes.parseTemplate.replace('__TID__', this.threadId);
                    try {
                        const res  = await fetch(url, {
                            method:  'POST',
                            headers: {
                                'X-CSRF-TOKEN': config.csrf,
                                'Accept':       'application/json',
                                'Content-Type': 'application/json',
                            },
                            body: JSON.stringify({ message: msg }),
                        });
                        const body = await res.json().catch(() => ({}));

                        if (body.parse_status === 'validated') {
                            const m = {
                                role:        'assistant',
                                content:     body.summary || 'Proposed changes are ready.',
                                changeSetId: body.change_set_id,
                                applied:     false,
                                stale:       false,
                                diff:        null,
                            };
                            this.messages.push(m);
                            this._persist();

                            // Fetch the preview diff so the PM can see the
                            // before/after summary without having to Apply first.
                            this._loadPreview(m);
                        } else {
                            const reasons = (body.validation_errors || [])
                                .map((e) => (e.message || e.code || 'unknown'))
                                .join('; ');
                            this.messages.push({
                                role:    'assistant',
                                content: reasons
                                    ? "I couldn't parse that: " + reasons + ". Try rephrasing."
                                    : "I couldn't parse that. Try rephrasing.",
                            });
                            this._persist();
                        }
                    } catch (e) {
                        this._pushError('Network error while parsing.');
                    } finally {
                        this.sending = false;
                        this._scroll();
                    }
                },

                async _loadPreview(msg) {
                    if (!msg.changeSetId) return;
                    const url = config.routes.showTemplate.replace('__CID__', msg.changeSetId);
                    try {
                        const res  = await fetch(url, { headers: { 'Accept': 'application/json' } });
                        if (!res.ok) return;
                        const body = await res.json();
                        if (body.preview && Object.keys(body.preview).length > 0) {
                            msg.diff = body.preview;
                            this._persist();
                        }
                    } catch (e) { /* preview is optional — don't block apply */ }
                },

                async applyChanges(msg, opts = {}) {
                    if (this.applying) return;
                    this.applying = msg.changeSetId;

                    const base = config.routes.applyTemplate.replace('__CID__', msg.changeSetId);
                    // opts.rebase=true → server retargets the change-set to the current
                    // revision before applying. Used by the "Apply on current revision"
                    // button that appears after a 409 base_revision_stale response.
                    const url  = opts.rebase ? `${base}?rebase=1` : base;
                    try {
                        const res  = await fetch(url, {
                            method:  'POST',
                            headers: {
                                'X-CSRF-TOKEN': config.csrf,
                                'Accept':       'application/json',
                            },
                        });
                        if (res.ok) {
                            msg.applied = true;
                            msg.stale   = false;
                            this._pushSystem(opts.rebase
                                ? 'Rebased and applied. Regenerating document…'
                                : 'Applied. Regenerating document…');
                            // Clear stored thread — once applied, base revision
                            // has moved. Next open will start a fresh thread.
                            sessionStorage.removeItem(this._storageKey);
                            setTimeout(() => window.location.reload(), 1400);
                        } else if (res.status === 409) {
                            // Base revision stale — the doc was updated behind
                            // the chat's back. Mark this message stale; the UI
                            // will show an "Apply on current revision" button that
                            // retries with rebase=1, plus a Restart fallback.
                            const err = await res.json().catch(() => ({}));
                            msg.stale = true;
                            this._pushError(err.message || 'The document was updated since this change was proposed. You can apply against the current revision, or restart to re-plan from scratch.');
                            this._persist();
                        } else {
                            const err = await res.json().catch(() => ({}));
                            this._pushError('Apply failed: ' + (err.message || err.code || res.status));
                        }
                    } catch (e) {
                        this._pushError('Network error applying changes.');
                    } finally {
                        this.applying = null;
                        this._scroll();
                    }
                },

                restart() {
                    sessionStorage.removeItem(this._storageKey);
                    this.threadId = null;
                    this.messages = [];
                    this.applying = null;
                    // Kick off a fresh thread immediately so the PM can keep typing.
                    this.ensureThread();
                },

                renderDiff(diff) {
                    // Very small HTML renderer. Walks top-level keys and
                    // produces a label/value row. Arrays render as bulleted
                    // lists, objects as nested key/value pairs, before/after
                    // pairs from summariseDiff are merged into a single row.
                    const esc = (v) => String(v == null ? '' : v)
                        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

                    const before = diff.before_summary || {};
                    const after  = diff.after_summary  || {};
                    const rows = [];

                    // Merged before/after summary rows.
                    const summaryKeys = new Set([
                        ...Object.keys(before),
                        ...Object.keys(after),
                    ]);
                    for (const k of summaryKeys) {
                        const b = before[k], a = after[k];
                        if (JSON.stringify(b) === JSON.stringify(a)) continue;
                        rows.push(`
                            <div class="chat-diff-row">
                                <span class="chat-diff-label">${esc(this._prettyLabel(k))}</span>
                                <span class="chat-diff-val">
                                    <span class="chat-diff-before">${esc(b)}</span>
                                    <span class="chat-diff-after">${esc(a)}</span>
                                </span>
                            </div>`);
                    }

                    // Other top-level keys (items_added, items_removed, project_fields_changed, etc.)
                    const reservedKeys = new Set(['before_summary', 'after_summary']);
                    for (const k of Object.keys(diff)) {
                        if (reservedKeys.has(k)) continue;
                        const v = diff[k];
                        if (v == null) continue;
                        if (Array.isArray(v)) {
                            if (v.length === 0) continue;
                            const items = v.map((item) => `<li>${esc(item)}</li>`).join('');
                            rows.push(`
                                <div class="chat-diff-row">
                                    <span class="chat-diff-label">${esc(this._prettyLabel(k))}</span>
                                    <ul class="chat-diff-list">${items}</ul>
                                </div>`);
                        } else if (typeof v === 'object') {
                            const pairs = Object.entries(v)
                                .map(([kk, vv]) => `<li>${esc(this._prettyLabel(kk))}: ${esc(vv)}</li>`)
                                .join('');
                            rows.push(`
                                <div class="chat-diff-row">
                                    <span class="chat-diff-label">${esc(this._prettyLabel(k))}</span>
                                    <ul class="chat-diff-list">${pairs}</ul>
                                </div>`);
                        } else {
                            rows.push(`
                                <div class="chat-diff-row">
                                    <span class="chat-diff-label">${esc(this._prettyLabel(k))}</span>
                                    <span class="chat-diff-val">${esc(v)}</span>
                                </div>`);
                        }
                    }

                    return rows.length
                        ? rows.join('')
                        : '<div class="chat-diff-row"><span class="chat-diff-val" style="color:var(--text-muted);">No visible changes</span></div>';
                },

                _prettyLabel(key) {
                    return String(key).replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
                },

                autoSize(el) {
                    el.style.height = 'auto';
                    el.style.height = Math.min(el.scrollHeight, 140) + 'px';
                },

                _pushSystem(text) {
                    this.messages.push({ role: 'system', content: text });
                    this._persist();
                },

                _pushError(text) {
                    this.messages.push({ role: 'error', content: text });
                    this._persist();
                },

                _persist() {
                    try {
                        sessionStorage.setItem(this._storageKey, JSON.stringify({
                            threadId: this.threadId,
                            messages: this.messages,
                        }));
                    } catch (e) { /* quota or privacy mode — not fatal */ }
                },

                _scroll() {
                    this.$nextTick(() => {
                        const el = this.$refs.body;
                        if (el) el.scrollTop = el.scrollHeight;
                    });
                },
                }));
            };

            // Path (b): Alpine already running → register now.
            if (window.Alpine && typeof window.Alpine.data === 'function') {
                define(window.Alpine);
                return;
            }

            // Path (a): Alpine not loaded yet → wait for alpine:init.
            document.addEventListener('alpine:init', () => {
                if (window.Alpine) define(window.Alpine);
            });

            // Last-resort watchdog. If neither alpine:init nor window.Alpine
            // has arrived after 3s, surface a console warning so the dev
            // knows Alpine never booted (usually: the Vite directive fell
            // back to the hot server but `npm run dev` isn't running, or
            // CSP blocked the module script).
            setTimeout(() => {
                if (!window.Alpine) {
                    console.warn('[docChat] Alpine did not start after 3s. ' +
                        'Run `composer dev` (or `npm run build` + reload) to ensure the ' +
                        'Vite bundle is served.');
                }
            }, 3000);
        })();
    </script>
    @endpush
@endonce
@endif
