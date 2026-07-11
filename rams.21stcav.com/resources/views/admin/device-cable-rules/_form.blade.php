{{-- Shared form partial for create + edit of a DeviceCableRule (quick task 260711-q7q). --}}

@php
    /** @var \App\Models\DeviceCableRule $rule */
    $signalTypes = ['video', 'audio', 'network', 'speaker', 'control', 'power', 'usb', 'unknown'];
    $keywordsRaw = old('keywords_raw', implode("\n", (array) ($rule->keywords ?? [])));
@endphp

<div class="section-block" style="margin-bottom:1.25rem;">
    <h2 class="section-heading">Rule Basics</h2>
    <div class="form-grid-2">
        <div class="form-group">
            <label class="form-label">Priority <span style="color:var(--danger)">*</span></label>
            <input type="number" name="priority" class="form-control"
                   value="{{ old('priority', $rule->priority ?? 500) }}"
                   min="0" max="9999" required style="max-width:120px;">
            <p style="font-size:.75rem;color:var(--text-muted);margin-top:4px;">
                Lower priority is matched first. Seeded canonical rules use 10–130; use higher values for admin-added overrides / brand-specific packs.
            </p>
        </div>
        <div class="form-group">
            <label class="form-label">Status</label>
            <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-weight:400;">
                {{-- Hidden 0 sentinel so unchecking writes false, not "missing". --}}
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1"
                       {{ old('is_active', $rule->is_active ?? true) ? 'checked' : '' }}>
                Active (walked by inferCableRun())
            </label>
        </div>
    </div>
</div>

<div class="section-block" style="margin-bottom:1.25rem;">
    <h2 class="section-heading">Keywords</h2>
    <p style="font-size:.825rem;color:var(--text-muted);margin-bottom:.75rem;">
        One keyword per line. Matched word-boundary <em>case-insensitive</em> against the equipment name (manufacturer + model). Multi-word tokens like "patch panel" work — the boundary is between tokens.
    </p>
    <textarea name="keywords_raw"
              class="form-control"
              rows="8"
              placeholder="samsung&#10;qm85&#10;projector">{{ $keywordsRaw }}</textarea>
</div>

<div class="section-block" style="margin-bottom:1.25rem;">
    <h2 class="section-heading">Cable Output</h2>
    <div class="form-grid-2">
        <div class="form-group">
            <label class="form-label">Cable type <span style="color:var(--danger)">*</span></label>
            <input type="text" name="cable_type" class="form-control"
                   value="{{ old('cable_type', $rule->cable_type) }}"
                   maxlength="120" required placeholder="e.g. HDMI 2.0">
        </div>
        <div class="form-group">
            <label class="form-label">Cores</label>
            <input type="text" name="cores" class="form-control"
                   value="{{ old('cores', $rule->cores) }}"
                   maxlength="20" placeholder="e.g. 2 (speaker) or blank">
        </div>
        <div class="form-group">
            <label class="form-label">Signal type <span style="color:var(--danger)">*</span></label>
            <select name="signal_type" class="form-control" required>
                @foreach ($signalTypes as $st)
                    <option value="{{ $st }}" {{ old('signal_type', $rule->signal_type) === $st ? 'selected' : '' }}>
                        {{ ucfirst($st) }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">To endpoint <span style="color:var(--danger)">*</span></label>
            <input type="text" name="to_endpoint" class="form-control"
                   value="{{ old('to_endpoint', $rule->to_endpoint) }}"
                   maxlength="200" required placeholder="e.g. Network switch (AV VLAN)">
        </div>
        <div class="form-group" style="grid-column:span 2;">
            <label class="form-label">Notes</label>
            <input type="text" name="notes" class="form-control"
                   value="{{ old('notes', $rule->notes) }}"
                   maxlength="500" placeholder="Row-level context prefixed to notes column">
        </div>
    </div>
</div>
