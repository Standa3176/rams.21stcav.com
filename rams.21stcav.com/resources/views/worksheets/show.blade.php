@extends('layouts.app')

@section('title', 'Worksheet: ' . $worksheet->project_name)

@push('styles')
<style>
/* ── Room cards (copied verbatim from site-survey/show.blade.php) ─── */
.survey-room-card {
    background:#fff;
    border:1.5px solid #e5e7eb;
    border-radius:8px;
    margin-bottom:.75rem;
    overflow:hidden;
    box-shadow:var(--shadow-sm);
}
.survey-room-card--complete {
    border-color:#6EE7B7;
}
.room-view-hdr {
    display:flex;
    align-items:center;
    gap:.75rem;
    padding:.9rem 1.1rem;
    cursor:pointer;
    user-select:none;
}
.room-view-hdr--complete   { background:#D1FAE5; }
.room-view-hdr--empty      { background:#F9FAFB; }
.room-view-name {
    flex:1;
    font-weight:700;
    font-size:.975rem;
    color:#0B3C45;
}
.room-view-badge {
    font-size:.7rem;
    font-weight:700;
    padding:.15rem .55rem;
    border-radius:20px;
    white-space:nowrap;
}
.room-view-badge--complete { background:#A7F3D0; color:#065F46; }
.room-view-badge--empty    { background:#E5E7EB; color:#6B7280; }
.room-view-chevron {
    color:#9CA3AF;
    font-size:.85rem;
    transition:transform 200ms;
}
.room-view-chevron.open { transform:rotate(90deg); }
.room-view-body {
    padding:0 1.1rem 1rem;
    display:none;
}
.room-view-body.open { display:block; }

/* ── Field table ─────────────────────────────────────────────────── */
.field-table {
    width:100%;
    border-collapse:collapse;
    font-size:.875rem;
    margin-bottom:1rem;
}
.field-table th {
    background:#F3F6F7;
    font-size:.7rem;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:.05em;
    color:var(--text-muted);
    padding:.5rem .75rem;
    text-align:left;
    border-bottom:1px solid var(--border);
}
.field-table td {
    padding:.45rem .75rem;
    border-bottom:1px solid #f5f5f5;
    vertical-align:top;
    color:#374151;
}
.field-table tr:last-child td { border-bottom:none; }
.field-table td:first-child {
    width:34%;
    font-weight:600;
    color:#4B5563;
    font-size:.82rem;
}
.field-table td:last-child {
    white-space:pre-wrap;
}

/* ── Section heading inside room ─────────────────────────────────── */
.room-section-hdr {
    font-size:.7rem;
    font-weight:800;
    text-transform:uppercase;
    letter-spacing:.07em;
    color:var(--teal);
    border-top:1px solid #f0f0f0;
    padding-top:.75rem;
    margin:.75rem 0 .5rem;
}

/* ── AI chat drawer ─────────────────────────────────────────────────────── */
.chat-drawer-backdrop {
    position:fixed; inset:0; background:rgba(11,60,69,.35);
    z-index:998; opacity:0; pointer-events:none;
    transition:opacity 180ms ease;
}
.chat-drawer-backdrop.is-open { opacity:1; pointer-events:auto; }

.chat-drawer {
    position:fixed; top:0; right:0; bottom:0; width:min(440px,100vw);
    background:#fff; box-shadow:-4px 0 18px rgba(0,0,0,.12);
    display:flex; flex-direction:column; z-index:999;
    transform:translateX(100%); transition:transform 220ms ease;
}
.chat-drawer.is-open { transform:translateX(0); }

.chat-drawer-hdr {
    background:var(--teal); color:#fff;
    padding:.9rem 1rem; display:flex; align-items:center; justify-content:space-between;
}
.chat-drawer-hdr strong { font-size:.95rem; font-weight:600; }
.chat-drawer-hdr button {
    background:transparent; border:none; color:#fff; font-size:1.4rem; line-height:1;
    cursor:pointer; padding:0 .25rem; opacity:.85;
}
.chat-drawer-hdr button:hover { opacity:1; }

.chat-drawer-body {
    flex:1; overflow-y:auto; padding:1rem; background:var(--bg);
    display:flex; flex-direction:column; gap:.6rem;
}

.chat-msg { max-width:85%; padding:.55rem .75rem; border-radius:12px; font-size:.875rem; line-height:1.4; word-wrap:break-word; }
.chat-msg--user      { align-self:flex-end; background:var(--teal); color:#fff; border-bottom-right-radius:4px; }
.chat-msg--assistant { align-self:flex-start; background:#fff; color:var(--text); border:1px solid var(--border); border-bottom-left-radius:4px; }
.chat-msg--system    { align-self:center; background:transparent; color:var(--text-muted); font-size:.78rem; font-style:italic; }

.chat-apply-pill {
    display:inline-flex; align-items:center; gap:.35rem;
    margin-top:.4rem; padding:.3rem .7rem;
    background:var(--teal); color:#fff; border:none; border-radius:999px;
    font-size:.78rem; font-weight:600; cursor:pointer;
    transition:background 150ms ease, opacity 150ms ease;
}
.chat-apply-pill:hover       { background:var(--teal-hover); }
.chat-apply-pill:disabled    { opacity:.5; cursor:wait; }
.chat-apply-pill--applied    { background:var(--success); cursor:default; }

.chat-drawer-ftr {
    border-top:1px solid var(--border); background:#fff;
    padding:.65rem .75rem; display:flex; gap:.5rem; align-items:flex-end;
}
.chat-drawer-ftr textarea {
    flex:1; resize:none; min-height:40px; max-height:140px;
    border:1px solid var(--border); border-radius:8px; padding:.5rem .6rem;
    font-family:inherit; font-size:.875rem; line-height:1.4; color:var(--text);
}
.chat-drawer-ftr textarea:focus {
    outline:none; border-color:var(--teal);
    box-shadow:0 0 0 3px rgba(23,138,149,.15);
}
.chat-drawer-ftr button[type="submit"] {
    background:var(--teal); color:#fff; border:none; border-radius:8px;
    padding:.5rem .9rem; font-weight:600; cursor:pointer;
    transition:background 150ms ease, opacity 150ms ease;
}
.chat-drawer-ftr button[type="submit"]:hover    { background:var(--teal-hover); }
.chat-drawer-ftr button[type="submit"]:disabled { opacity:.5; cursor:wait; }

.chat-empty { color:var(--text-muted); font-size:.85rem; text-align:center; padding:2rem 1rem; }
.chat-empty p { margin:0 0 .5rem; }
.chat-empty code { font-size:.78rem; background:#fff; padding:.1rem .3rem; border-radius:4px; border:1px solid var(--border); }

.chat-fab {
    display:inline-flex; align-items:center; gap:.35rem;
    padding:.35rem .75rem; border:1px solid var(--teal); background:#fff; color:var(--teal);
    border-radius:var(--radius-sm); font-size:.8125rem; font-weight:600; cursor:pointer;
    transition:background 150ms ease;
}
.chat-fab:hover { background:var(--teal-light); text-decoration:none; }
</style>
@endpush

@section('content')

{{-- Breadcrumb --}}
<nav style="font-size:.875rem;margin-bottom:1rem;">
    <a href="{{ route('projects.index') }}" style="color:var(--teal);text-decoration:none;">Projects</a>
    @if($worksheet->project)
        &rsaquo;
        <a href="{{ route('projects.show', $worksheet->project) }}" style="color:var(--teal);text-decoration:none;">{{ $worksheet->project->name }}</a>
    @endif
    &rsaquo;
    <span style="color:var(--text-muted);">Worksheet</span>
</nav>

{{-- Page header --}}
<div class="page-header">
    <div>
        <h1 class="page-title">Worksheet: {{ $worksheet->project_name }}</h1>
        <p class="page-subtitle" style="color:var(--text-muted);margin-top:.25rem;font-size:.875rem;">
            {{ $worksheet->client_name }}
            @if($worksheet->site_address) · {{ $worksheet->site_address }} @endif
            @if($worksheet->project_ref) · Ref: {{ $worksheet->project_ref }} @endif
        </p>
    </div>
    <div style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;">
        @if(in_array($worksheet->status, ['draft', 'final']))
            <a href="{{ route('worksheets.download', $worksheet) }}"
               class="btn-teal"
               target="_blank"
               aria-label="Download Worksheet DOCX">↓ Download</a>
        @endif
        @if($worksheet->project)
            <a href="{{ route('projects.show', $worksheet->project) }}" class="btn-outline btn-sm">← Back to Project</a>
        @else
            <a href="{{ route('worksheets.index') }}" class="btn-outline btn-sm">← All Worksheets</a>
        @endif
        <a href="{{ route('documents.revisions.view', ['type' => 'worksheet', 'id' => $worksheet->id]) }}" class="btn-outline btn-sm">↻ History</a>
        @if(in_array($worksheet->status, ['draft', 'final']))
            <button type="button" class="chat-fab"
                    onclick="window.dispatchEvent(new CustomEvent('open-doc-chat'))"
                    aria-label="Edit via AI chat">
                ✎ Edit via chat
            </button>
        @endif
    </div>
</div>

{{-- Status bar --}}
<div class="card card-sm" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;margin-bottom:1.25rem;">
    <div>
        <x-dashboard.status-badge :status="$worksheet->status" />
    </div>
    <div style="font-size:.875rem;color:var(--text-muted);">
        @if(in_array($worksheet->status, ['pending', 'generating']))
            <span style="display:inline-flex;align-items:center;gap:.4rem;">
                <span style="width:8px;height:8px;border-radius:50%;background:#D97706;display:inline-block;"></span>
                Generating…
            </span>
        @else
            Generated {{ $worksheet->updated_at->diffForHumans() }}
        @endif
    </div>
</div>

{{-- Error alert --}}
@if($worksheet->status === 'failed' && $worksheet->error_message)
    <div class="alert alert-error" style="margin-bottom:1.25rem;">
        Generation failed: {{ $worksheet->error_message }}. Click Retry Generation to try again.
    </div>
@endif

{{-- Room accordion --}}
@php
    $rooms = $worksheet->generated_data['rooms'] ?? [];
@endphp

@if(empty($rooms))
    <div class="card card-sm" style="color:var(--text-muted);font-size:.875rem;text-align:center;padding:2rem;">
        @if(in_array($worksheet->status, ['pending', 'generating']))
            Worksheet is being generated. This page will update when complete.
        @else
            No room data available.
        @endif
    </div>
@else
    @foreach($rooms as $room)
        @php
            $isSurveyed = $room['is_surveyed'] ?? false;
            $cardClass  = $isSurveyed ? 'survey-room-card survey-room-card--complete' : 'survey-room-card';
            $hdrClass   = $isSurveyed ? 'room-view-hdr room-view-hdr--complete' : 'room-view-hdr room-view-hdr--empty';
            $badgeClass = $isSurveyed ? 'room-view-badge room-view-badge--complete' : 'room-view-badge room-view-badge--empty';
            $badgeText  = $isSurveyed ? 'Surveyed' : 'Not surveyed';
        @endphp

        <div class="{{ $cardClass }}" x-data="{ open: false }">

            {{-- Room header --}}
            <div class="{{ $hdrClass }}"
                 role="button"
                 @click="open = !open"
                 :aria-expanded="open ? 'true' : 'false'">
                <span class="room-view-name">{{ $room['name'] ?? 'Unknown Room' }}</span>
                <span class="{{ $badgeClass }}">{{ $badgeText }}</span>
                <span class="room-view-chevron" :class="{ open: open }">▶</span>
            </div>

            {{-- Room body --}}
            <div class="room-view-body" x-show="open" x-cloak :class="{ open: open }">

                {{-- Section A: Equipment --}}
                <div class="room-section-hdr">Equipment</div>
                @php $equipment = $room['equipment'] ?? []; @endphp
                @if(empty($equipment))
                    <p style="color:var(--text-muted);font-size:.875rem;">No equipment listed for this room.</p>
                @else
                    <table class="field-table">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th style="width:15%;">Qty</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($equipment as $item)
                                <tr>
                                    <td>{{ $item['name'] ?? $item['description'] ?? '—' }}</td>
                                    <td>{{ $item['quantity'] ?? 1 }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif

                {{-- Section B: Install Steps --}}
                <div class="room-section-hdr">Install Steps</div>
                @if(! empty($room['install_steps']))
                    <div style="font-size:.875rem;line-height:1.6;color:var(--text);white-space:pre-wrap;">{{ $room['install_steps'] }}</div>
                @else
                    <div style="display:inline-flex;align-items:center;gap:.4rem;background:#FEF3C7;color:#92400E;padding:.3rem .85rem;border-radius:20px;font-size:.78rem;font-weight:700;">
                        Install steps being generated…
                    </div>
                @endif

                {{-- Section C: Cable Routes --}}
                <div class="room-section-hdr">Cable Routes</div>
                @if(! empty($room['cable_route_desc']))
                    <p style="font-size:.875rem;color:var(--text);">{{ $room['cable_route_desc'] }}</p>
                @else
                    <p style="color:var(--text-muted);font-size:.875rem;">Not surveyed</p>
                @endif

                {{-- Section D: Power & Network --}}
                <div class="room-section-hdr">Power & Network</div>
                <table class="field-table">
                    <tbody>
                        <tr>
                            <td>Power outlets</td>
                            <td>
                                @if(isset($room['power_outlet_count']) && $room['power_outlet_count'] !== null)
                                    {{ $room['power_outlet_count'] }}
                                @else
                                    <span style="color:var(--text-faint);">Not surveyed</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td>Additional power required</td>
                            <td>
                                @if(isset($room['requires_additional_power']) && $room['requires_additional_power'] !== null)
                                    {{ $room['requires_additional_power'] ? 'Yes' : 'No' }}
                                @else
                                    <span style="color:var(--text-faint);">Not surveyed</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td>Network ports</td>
                            <td>
                                @if(isset($room['network_port_count']) && $room['network_port_count'] !== null)
                                    {{ $room['network_port_count'] }}
                                @else
                                    <span style="color:var(--text-faint);">Not surveyed</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td>Existing cabling</td>
                            <td>
                                @if(isset($room['existing_cabling']) && $room['existing_cabling'] !== null)
                                    {{ $room['existing_cabling'] }}
                                @else
                                    <span style="color:var(--text-faint);">Not surveyed</span>
                                @endif
                            </td>
                        </tr>
                    </tbody>
                </table>

            </div>{{-- /.room-view-body --}}
        </div>{{-- /.survey-room-card --}}
    @endforeach
@endif

{{-- Footer action row --}}
<div class="card card-sm" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;margin-top:1.25rem;">
    <div>
        @if(in_array($worksheet->status, ['draft', 'final']))
            <a href="{{ route('worksheets.download', $worksheet) }}"
               class="btn-teal"
               target="_blank"
               aria-label="Download Worksheet DOCX">Download DOCX</a>
        @else
            <span style="font-size:.875rem;color:var(--text-muted);">DOCX available once generation is complete.</span>
        @endif
    </div>
    <div>
        @if($worksheet->project)
            <a href="{{ route('projects.show', $worksheet->project) }}" class="btn-outline btn-sm">← Back to Project</a>
        @else
            <a href="{{ route('worksheets.index') }}" class="btn-outline btn-sm">← All Worksheets</a>
        @endif
        <a href="{{ route('documents.revisions.view', ['type' => 'worksheet', 'id' => $worksheet->id]) }}" class="btn-outline btn-sm">↻ History</a>
    </div>
</div>

{{-- ── AI chat drawer ─────────────────────────────────────────────────── --}}
@if(in_array($worksheet->status, ['draft', 'final']))
<div x-data="docChat({
        documentType: 'worksheet',
        documentId: {{ (int) $worksheet->id }},
        routes: {
            createThread:  @js(route('documents.threads.create', ['type' => 'worksheet', 'id' => $worksheet->id])),
            parseTemplate: @js(route('documents.threads.parse',  ['type' => 'worksheet', 'id' => $worksheet->id, 'thread' => '__TID__'])),
            applyTemplate: @js(route('documents.changes.apply',  ['type' => 'worksheet', 'id' => $worksheet->id, 'changeSet' => '__CID__'])),
        },
        csrf: @js(csrf_token()),
     })"
     x-init="init()"
     @open-doc-chat.window="openDrawer()"
     @keydown.escape.window="closeDrawer()">

    <div class="chat-drawer-backdrop"
         :class="open ? 'is-open' : ''"
         @click="closeDrawer()"
         aria-hidden="true"></div>

    <aside class="chat-drawer"
           :class="open ? 'is-open' : ''"
           role="dialog"
           aria-label="Edit Worksheet via AI chat"
           aria-modal="true">

        <header class="chat-drawer-hdr">
            <div>
                <strong>Edit Worksheet via chat</strong>
                <div style="font-size:.72rem;opacity:.85;margin-top:2px;">
                    Changes preview before apply · regenerates the DOCX
                </div>
            </div>
            <button type="button" @click="closeDrawer()" aria-label="Close">✕</button>
        </header>

        <div class="chat-drawer-body" x-ref="body">
            <template x-if="messages.length === 0">
                <div class="chat-empty">
                    <p>Describe what you want to change, for example:</p>
                    <p><code>Set the engineer name to John Smith</code></p>
                    <p><code>Add a note about working at height in Room 3</code></p>
                    <p style="opacity:.7;margin-top:.75rem;font-size:.75rem;">Each change previews before it's applied.</p>
                </div>
            </template>

            <template x-for="(m, idx) in messages" :key="idx">
                <div :class="'chat-msg chat-msg--' + m.role">
                    <div x-text="m.content"></div>

                    <template x-if="m.changeSetId && !m.applied">
                        <button type="button"
                                class="chat-apply-pill"
                                :disabled="applying === m.changeSetId"
                                @click="applyChanges(m)">
                            <span x-show="applying !== m.changeSetId">✓ Apply these changes</span>
                            <span x-show="applying === m.changeSetId">Applying…</span>
                        </button>
                    </template>

                    <template x-if="m.applied">
                        <span class="chat-apply-pill chat-apply-pill--applied">✓ Applied — regenerating</span>
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
                      placeholder="Describe a change…"
                      rows="1"
                      :disabled="sending"></textarea>
            <button type="submit" :disabled="sending || !input.trim()">Send</button>
        </form>
    </aside>
</div>

@push('scripts')
@vite(['resources/js/app.js'])
<script>
    // Alpine factory for the document-edit chat drawer. Registered on
    // alpine:init so it's available to x-data="docChat(…)" evaluation.
    document.addEventListener('alpine:init', () => {
        Alpine.data('docChat', (config) => ({
            open:        false,
            threadId:    null,
            messages:    [],
            input:       '',
            sending:     false,
            applying:    null,
            _busy:       false,   // prevents double createThread

            init() {
                // Nothing to do until the drawer is opened.
            },

            async openDrawer() {
                this.open = true;
                await this.ensureThread();
                this.$nextTick(() => this.$refs.input?.focus());
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
                        this._pushSystem('Could not start chat: ' + (err.message || res.status));
                        return;
                    }
                    const body = await res.json();
                    this.threadId = body.thread?.id ?? null;
                } catch (e) {
                    this._pushSystem('Network error starting chat.');
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
                        this.messages.push({
                            role:        'assistant',
                            content:     body.summary || 'Proposed changes are ready.',
                            changeSetId: body.change_set_id,
                            applied:     false,
                        });
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
                    }
                } catch (e) {
                    this._pushSystem('Network error while parsing.');
                } finally {
                    this.sending = false;
                    this._scroll();
                }
            },

            async applyChanges(msg) {
                if (this.applying) return;
                this.applying = msg.changeSetId;

                const url = config.routes.applyTemplate.replace('__CID__', msg.changeSetId);
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
                        this._pushSystem('Applied. Regenerating document…');
                        setTimeout(() => window.location.reload(), 1400);
                    } else {
                        const err = await res.json().catch(() => ({}));
                        this._pushSystem('Apply failed: ' + (err.message || err.code || res.status));
                    }
                } catch (e) {
                    this._pushSystem('Network error applying changes.');
                } finally {
                    this.applying = null;
                    this._scroll();
                }
            },

            _pushSystem(text) {
                this.messages.push({ role: 'system', content: text });
            },

            _scroll() {
                this.$nextTick(() => {
                    const el = this.$refs.body;
                    if (el) el.scrollTop = el.scrollHeight;
                });
            },
        }));
    });
</script>
@endpush
@endif

@endsection
