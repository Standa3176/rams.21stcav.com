@extends('layouts.app')

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
       class="fixed bottom-4 right-4 px-3 py-2 rounded shadow text-sm"
       :class="statusKind === 'error' ? 'bg-red-600 text-white' : 'bg-[#1B7A7A] text-white'"
       style="display: none;">
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
    },

    onEmbedReady() {
      // Editor iframe loaded but may not yet have processed the xml.
      // The 'init' event from the embed signals readiness; we wait for it
      // before sending the load action.
    },

    onMessage(e) {
      // T-260509-ibx-04 mitigation: filter to messages from our iframe only.
      if (!this.$refs.embed || e.source !== this.$refs.embed.contentWindow) return;
      let msg;
      try { msg = typeof e.data === 'string' ? JSON.parse(e.data) : e.data; }
      catch (_err) { return; }
      if (!msg || !msg.event) return;

      switch (msg.event) {
        case 'init':
          this.embedReady = true;
          this.postToEmbed({ action: 'load', xml: this.initialXml, autosave: 1 });
          this.flash('Editor ready', 'info');
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
  };
}
</script>
@endpush
@endsection
