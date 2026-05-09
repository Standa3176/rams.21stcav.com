@extends('layouts.app')

{{-- Alpine.js CDN — the main app.blade.php layout doesn't include Alpine
     globally, only specific pages load it. Mirror surveys/show.blade.php
     line 24 which does the same. Without this, the x-data + @click +
     postMessage handlers below silently do nothing. --}}
@push('scripts')
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js"></script>
@endpush

@section('content')
<div x-data="drawIoSpike({
    embedUrl: '{{ $embed_url }}',
    initialXml: @js($xml),
    saveUrl: '{{ route('admin.drawings.draw-io-spike.save', $project) }}',
    exportSvgUrl: '{{ route('admin.drawings.draw-io-spike.export-svg', $project) }}',
    csrf: '{{ csrf_token() }}',
    isLocked: {{ $is_locked ? 'true' : 'false' }},
})" class="px-6 py-4">

  <header class="flex items-center justify-between mb-3">
    <div>
      <h1 class="text-xl font-semibold text-[#1B7A7A]">draw.io Spike — {{ $project->name }}</h1>
      <p class="text-sm text-gray-600">
        Quick task 260509-ibx — admin sandbox.
        <span class="ml-2 text-xs text-gray-500">Drawing #{{ $drawing->id }} v{{ $drawing->version }}</span>
        @if($is_locked)
          <span class="inline-block px-2 py-0.5 text-xs rounded bg-amber-100 text-amber-800 ml-2">
            🔒 Engineer-edited (locked)
          </span>
        @else
          <span class="inline-block px-2 py-0.5 text-xs rounded bg-gray-100 text-gray-700 ml-2">
            Auto-generated
          </span>
        @endif
      </p>
    </div>
    <div class="flex gap-2">
      <button type="button" @click="exportSvgNow()" class="px-3 py-1.5 text-sm border border-[#1B7A7A] text-[#1B7A7A] rounded hover:bg-[#1B7A7A] hover:text-white transition">📤 Export SVG</button>
      <button type="button" @click="saveNow()" class="px-3 py-1.5 text-sm bg-[#1B7A7A] text-white rounded hover:bg-[#155f5f] transition">💾 Save</button>
      <a href="{{ route('projects.show', $project) }}" class="px-3 py-1.5 text-sm border border-gray-300 text-gray-700 rounded hover:bg-gray-50 transition">← Back to project</a>
    </div>
  </header>

  <iframe
    x-ref="embed"
    src="{{ $embed_url }}"
    @load="onEmbedReady()"
    class="w-full"
    style="height: calc(100vh - 140px); border: 1px solid #d1d5db; background: #fff;"
    allow="clipboard-read; clipboard-write"
  ></iframe>

  <div x-show="status" x-text="status"
       class="fixed bottom-4 right-4 px-3 py-2 rounded shadow text-xs font-mono"
       :class="statusKind === 'error' ? 'bg-red-600 text-white' : 'bg-[#1B7A7A] text-white'"
       style="display: none; max-width: 600px; max-height: 400px; overflow: auto; white-space: pre-wrap;">
  </div>
</div>

@push('scripts')
<script>
function drawIoSpike(cfg) {
  return {
    embedUrl: cfg.embedUrl,
    initialXml: cfg.initialXml,
    saveUrl: cfg.saveUrl,
    exportSvgUrl: cfg.exportSvgUrl,
    csrf: cfg.csrf,
    isLocked: cfg.isLocked,
    status: '',
    statusKind: 'info',
    embedReady: false,

    init() {
      // draw.io embed sends postMessage events to window. Listen here;
      // dispatch by event.event field (per
      // https://www.drawio.com/doc/faq/embed-mode).
      window.addEventListener('message', (e) => this.onMessage(e));
      this.flashSticky('1/4 listener attached · xml=' + (this.initialXml || '').length + ' bytes', 'info');
    },

    onEmbedReady() {
      // Iframe finished loading draw.io's index.html. We don't send the
      // load action here — we wait for draw.io's own 'init' postMessage
      // first (per protocol). This handler exists for diagnostic flash.
      this.flashSticky('2/4 iframe loaded — waiting for draw.io init event…', 'info');
    },

    onMessage(e) {
      // Diagnostic: log EVERY message we receive, even ones we filter out,
      // so we can see if draw.io is talking at all.
      const sourceMatch = this.$refs.embed && e.source === this.$refs.embed.contentWindow;
      const dataPreview = (typeof e.data === 'string' ? e.data : JSON.stringify(e.data) || '').substring(0, 60);
      this.flashSticky('msg src=' + (sourceMatch ? 'iframe' : 'OTHER') + ' data=' + dataPreview, 'info');

      // T-260509-ibx-04 mitigation: filter to messages from our iframe only.
      if (!sourceMatch) return;
      let msg;
      try { msg = typeof e.data === 'string' ? JSON.parse(e.data) : e.data; }
      catch (_err) { this.flashSticky('parse failed: ' + dataPreview, 'error'); return; }
      if (!msg || !msg.event) { this.flashSticky('no event field: ' + dataPreview, 'error'); return; }

      switch (msg.event) {
        case 'init':
          this.embedReady = true;
          this.flashSticky('3/4 init received — sending load (' + (this.initialXml || '').length + ' bytes)', 'info');
          this.postToEmbed({ action: 'load', xml: this.initialXml, autosave: 1 });
          break;
        case 'load':
          this.flashSticky('4/4 ✓ diagram loaded', 'info');
          break;
        case 'save':
          this.persistXml(msg.xml);
          break;
        case 'export':
          if (msg.format === 'xmlsvg' || msg.format === 'svg') {
            this.persistSvg(msg.data);
          }
          break;
        case 'autosave':
          // Treat autosaves the same as explicit saves — round-trip
          // integrity test should preserve every change.
          if (msg.xml) this.persistXml(msg.xml);
          break;
      }
    },

    postToEmbed(payload) {
      this.$refs.embed.contentWindow.postMessage(JSON.stringify(payload), '*');
    },

    saveNow() {
      if (!this.embedReady) { this.flash('Editor not ready yet', 'error'); return; }
      // Trigger draw.io's save flow which round-trips back as a 'save' event.
      this.postToEmbed({ action: 'save' });
    },

    async persistXml(xml) {
      this.flash('Saving…', 'info');
      try {
        const r = await fetch(this.saveUrl, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': this.csrf,
            'Accept': 'application/json',
          },
          body: JSON.stringify({ xml }),
        });
        if (!r.ok) throw new Error('HTTP ' + r.status);
        const j = await r.json();
        this.isLocked = true;
        this.flash(j.previous_locked ? 'Saved as new version' : 'Saved', 'info');
      } catch (err) { this.flash('Save failed: ' + err.message, 'error'); }
    },

    async persistSvg(svg) {
      try {
        const r = await fetch(this.exportSvgUrl, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': this.csrf,
            'Accept': 'application/json',
          },
          body: JSON.stringify({ svg }),
        });
        if (!r.ok) throw new Error('HTTP ' + r.status);
        this.flash('SVG exported', 'info');
      } catch (err) { this.flash('SVG export failed: ' + err.message, 'error'); }
    },

    exportSvgNow() {
      if (!this.embedReady) { this.flash('Editor not ready yet', 'error'); return; }
      this.postToEmbed({ action: 'export', format: 'xmlsvg' });
    },

    flash(msg, kind = 'info') {
      this.status = msg; this.statusKind = kind;
      setTimeout(() => { this.status = ''; }, 2500);
    },

    // Diagnostics — sticky version that doesn't auto-clear, so we can see
    // every postMessage step while debugging the embed handshake.
    // Appends to a running log instead of overwriting.
    flashSticky(msg, kind = 'info') {
      const ts = new Date().toLocaleTimeString();
      const line = '[' + ts + '] ' + msg;
      this.status = (this.status ? this.status + '\n' : '') + line;
      this.statusKind = kind;
    },
  };
}
</script>
@endpush
@endsection
