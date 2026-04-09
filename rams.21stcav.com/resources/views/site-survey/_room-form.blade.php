{{--
  Partial: _room-form.blade.php
  Variables:
    $ri         — integer index (0-based)
    $room       — array (from old()) or SiteSurveyRoom model
    $isNew      — bool: true = creating (no IDs), false = editing (has room->id)
    $surveyType — string: default type hint from survey header (used as fallback)
    $kitItems   — array of equipment items from project quote (optional, read-only kit list)
--}}
@php
    $isModel    = $room instanceof \App\Models\SiteSurveyRoom;
    $val        = fn(string $key) => $isModel ? $room->$key : ($room[$key] ?? '');
    $chk        = fn(string $key) => $isModel ? (bool) $room->$key : !empty($room[$key]);
    $opt        = fn(string $key, string $v) => ($val($key) == $v) ? 'selected' : '';

    // Per-room type — falls back to survey default if not yet set
    $spaceType   = $isModel
        ? ($room->space_type ?: ($surveyType ?? 'general'))
        : ($room['space_type'] ?? $surveyType ?? 'general');

    $showPaRm    = in_array($spaceType, ['pa_system',      'mixed']);
    $showSignRm  = in_array($spaceType, ['signage',         'mixed']);
    $showUpgRm   = in_array($spaceType, ['upgrade',         'mixed']);
    $showAreaType = $spaceType !== 'general';
    $kitItems     = $kitItems ?? [];

    // Collapsible: existing rooms start collapsed to reduce clutter
    $startCollapsed = $isModel && !$isNew;

    // Header colour: green = complete, amber = incomplete (only for saved rooms)
    $isComplete  = $isModel && !empty($room->is_completed);
    $headerBg    = $isComplete ? '#D1FAE5' : ($isModel && !$isNew ? '#FEF3C7' : '#EBF6F7');
    $headerBorder = $isComplete ? '#6EE7B7' : ($isModel && !$isNew ? '#FCD34D' : '#c4dde0');
@endphp

<div class="room-card" data-room-index="{{ $ri }}"
     style="border:1.5px solid #e0e0e0;border-radius:6px;margin-bottom:.6rem;background:#fafafa;position:relative;">

    @if($isModel && !$isNew)
        <input type="hidden" name="rooms[{{ $ri }}][id]" value="{{ $room->id }}">
    @endif

    {{-- ── Collapsible header ─────────────────────────────────────────────── --}}
    <div class="room-card-header"
         onclick="toggleAdminCard(this)"
         style="display:flex;align-items:center;gap:.6rem;padding:.75rem 1rem;cursor:pointer;user-select:none;border-radius:6px 6px 0 0;background:{{ $headerBg }};border-bottom:1px solid {{ $headerBorder }};">
        <span class="room-card-chevron" style="color:#6B7280;font-size:.9rem;transition:transform 200ms;{{ $startCollapsed ? '' : 'transform:rotate(90deg)' }}">&#9654;</span>
        <strong class="room-card-label" style="color:#0B3C45;flex:1;font-size:.9rem;">
            {{ $isModel ? $room->room_name : ('Space ' . ($ri + 1)) }}
        </strong>
        @if($isComplete)
            <span style="font-size:.72rem;font-weight:700;color:#065F46;background:#A7F3D0;padding:.15rem .5rem;border-radius:20px;margin-right:.3rem;">&#10003; Complete</span>
        @elseif($isModel && !$isNew)
            <span style="font-size:.72rem;font-weight:700;color:#92400E;background:#FDE68A;padding:.15rem .5rem;border-radius:20px;margin-right:.3rem;">In Progress</span>
        @endif
        <button type="button" onclick="event.stopPropagation();if(confirm('Remove this space from the survey? This cannot be undone.'))this.closest('.room-card').remove()"
                style="background:none;border:none;color:#c0392b;cursor:pointer;font-size:1rem;padding:0 .25rem;line-height:1;">&#10005;</button>
    </div>

    {{-- ── Collapsible body ───────────────────────────────────────────────── --}}
    <div class="room-card-body" style="padding:0 1rem 1rem;{{ $startCollapsed ? 'display:none' : '' }}">

        {{-- ── Kit list drawer (TOP — most prominent) ─────────────────────── --}}
        @if(count($kitItems) > 0)
        <div style="background:#EBF8FA;border:1px solid #94C4C9;border-radius:6px;margin-bottom:1rem;">
            <button type="button"
                    onclick="toggleKit(this)"
                    style="display:flex;align-items:center;gap:.5rem;width:100%;background:none;border:none;padding:.6rem .85rem;color:#0B3C45;font-size:.82rem;font-weight:700;cursor:pointer;text-align:left;border-radius:6px;">
                <span style="background:#178A95;color:#fff;border-radius:4px;padding:.1rem .45rem;font-size:.75rem;letter-spacing:.03em;">KIT</span>
                <span style="flex:1;">Quote Kit List — {{ count($kitItems) }} item{{ count($kitItems) !== 1 ? 's' : '' }}</span>
                <span class="kit-chevron" style="transition:transform 200ms;display:inline-block;color:#178A95;">&#9660;</span>
            </button>
            <div class="kit-drawer" style="overflow:hidden;max-height:0;transition:max-height 350ms ease;">
                <div style="padding:.25rem .85rem .6rem;border-top:1px solid #c4dde0;">
                    @foreach($kitItems as $kitItem)
                    @php
                        $kitQty  = $kitItem['quantity'] ?? $kitItem['qty'] ?? 1;
                        $kitPart = trim((string) ($kitItem['part_number'] ?? $kitItem['part_no'] ?? ''));
                        $kitName = $kitItem['name'] ?? $kitItem['description'] ?? '';
                    @endphp
                    <div style="display:flex;gap:.6rem;align-items:baseline;padding:.22rem 0;border-bottom:1px solid #ddeef0;font-size:.82rem;">
                        <span style="color:#178A95;font-weight:700;min-width:2rem;text-align:right;flex-shrink:0;">{{ $kitQty }}</span>
                        <span style="flex:1;color:#1F2937;">
                            @if($kitPart !== '')
                                <span style="font-family:monospace;background:#d0eff3;padding:.05rem .3rem;border-radius:3px;font-size:.78rem;color:#0B3C45;margin-right:.3rem;">{{ $kitPart }}</span>
                            @endif
                            {{ $kitName }}
                        </span>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
        @endif

        {{-- ── Per-space type selector + qty (new rooms only) ─────────────── --}}
        <div class="form-grid-2" style="margin-bottom:.75rem;">
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">Space / Survey Type</label>
                <select name="rooms[{{ $ri }}][space_type]" class="form-control"
                        onchange="onSpaceTypeChange(this)">
                    <option value="general"        {{ $spaceType === 'general'        ? 'selected' : '' }}>General AV / Meeting Room</option>
                    <option value="pa_system"      {{ $spaceType === 'pa_system'      ? 'selected' : '' }}>PA / Background Music</option>
                    <option value="infrastructure" {{ $spaceType === 'infrastructure' ? 'selected' : '' }}>Infrastructure / Cable Route</option>
                    <option value="signage"        {{ $spaceType === 'signage'        ? 'selected' : '' }}>Digital Signage</option>
                    <option value="upgrade"        {{ $spaceType === 'upgrade'        ? 'selected' : '' }}>Upgrade / Strip-out</option>
                    <option value="mixed"          {{ $spaceType === 'mixed'          ? 'selected' : '' }}>Mixed (all sections)</option>
                </select>
            </div>
            <div class="area-type-group form-group" style="margin-bottom:0;{{ !$showAreaType ? 'display:none' : '' }}">
                <label class="form-label">Area Classification</label>
                <select name="rooms[{{ $ri }}][area_type]" class="form-control">
                    <option value="">— Select —</option>
                    @foreach([
                        'room'             => 'Meeting Room',
                        'open_plan'        => 'Open Plan Area',
                        'lobby'            => 'Lobby / Reception',
                        'auditorium'       => 'Auditorium / Theatre',
                        'outdoor_area'     => 'Outdoor Area',
                        'zone'             => 'PA Zone / Coverage Area',
                        'rack_location'    => 'Rack / Equipment Room',
                        'cable_route'      => 'Cable Route / Riser',
                        'display_position' => 'Display Position',
                        'stairwell'        => 'Stairwell / Corridor',
                        'other'            => 'Other',
                    ] as $atVal => $atLabel)
                        <option value="{{ $atVal }}" {{ $opt('area_type', $atVal) }}>{{ $atLabel }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        {{-- Core identification + qty (qty only on new rooms) --}}
        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label room-name-label">Space Name <span class="req">*</span></label>
                <input type="text" name="rooms[{{ $ri }}][room_name]" class="form-control"
                       value="{{ $val('room_name') }}" required maxlength="150"
                       placeholder="e.g. Boardroom, Reception, Zone A"
                       oninput="updateCardLabel(this)">
            </div>
            @if(!$isModel || $isNew)
            <div class="form-group">
                <label class="form-label">Qty <span style="font-weight:400;color:#6B7280;">(creates multiple rooms)</span></label>
                <input type="number" name="rooms[{{ $ri }}][qty]" class="form-control"
                       value="1" min="1" max="99" step="1">
            </div>
            @endif
            <div class="form-group">
                <label class="form-label">Ref / Number</label>
                <input type="text" name="rooms[{{ $ri }}][room_ref]" class="form-control"
                       value="{{ $val('room_ref') }}" maxlength="50">
            </div>
            <div class="form-group">
                <label class="form-label">Floor / Level</label>
                <input type="text" name="rooms[{{ $ri }}][floor]" class="form-control"
                       value="{{ $val('floor') }}" maxlength="50" placeholder="e.g. Ground, 1st, B1">
            </div>
        </div>

        {{-- AV requirements --}}
        <div class="form-group">
            <label class="form-label">AV Requirements / Scope Notes</label>
            <textarea name="rooms[{{ $ri }}][av_requirements]" class="form-control"
                      rows="2" maxlength="5000">{{ $val('av_requirements') }}</textarea>
        </div>

        {{-- Power / network --}}
        <div style="display:flex;gap:1.5rem;flex-wrap:wrap;margin-bottom:.75rem;">
            <label class="check-item" style="cursor:pointer;">
                <input type="hidden" name="rooms[{{ $ri }}][has_power]" value="0">
                <input type="checkbox" name="rooms[{{ $ri }}][has_power]" value="1"
                       {{ $chk('has_power') ? 'checked' : '' }}>
                <span>Power present</span>
            </label>
            <label class="check-item" style="cursor:pointer;">
                <input type="hidden" name="rooms[{{ $ri }}][has_network]" value="0">
                <input type="checkbox" name="rooms[{{ $ri }}][has_network]" value="1"
                       {{ $chk('has_network') ? 'checked' : '' }}>
                <span>Network present</span>
            </label>
            <label class="check-item" style="cursor:pointer;">
                <input type="hidden" name="rooms[{{ $ri }}][requires_additional_power]" value="0">
                <input type="checkbox" name="rooms[{{ $ri }}][requires_additional_power]" value="1"
                       {{ $chk('requires_additional_power') ? 'checked' : '' }}>
                <span>Additional power required</span>
            </label>
        </div>

        {{-- Measurements / infrastructure (always available, collapsed) --}}
        <button type="button" class="btn btn-outline btn-sm" style="margin-bottom:.75rem;"
                onclick="toggleInfra(this)">&#9660; Measurements &amp; Infrastructure</button>

        <div class="infra-panel" style="display:none;">
            <div class="form-grid-2">
                <div class="form-group">
                    <label class="form-label">Width (m)</label>
                    <input type="number" name="rooms[{{ $ri }}][room_width_m]" class="form-control"
                           value="{{ $val('room_width_m') }}" min="0" max="999" step="0.01">
                </div>
                <div class="form-group">
                    <label class="form-label">Depth (m)</label>
                    <input type="number" name="rooms[{{ $ri }}][room_depth_m]" class="form-control"
                           value="{{ $val('room_depth_m') }}" min="0" max="999" step="0.01">
                </div>
                <div class="form-group">
                    <label class="form-label">Height (m)</label>
                    <input type="number" name="rooms[{{ $ri }}][room_height_m]" class="form-control"
                           value="{{ $val('room_height_m') }}" min="0" max="99" step="0.01">
                </div>
                <div class="form-group">
                    <label class="form-label">Ceiling Type</label>
                    <select name="rooms[{{ $ri }}][ceiling_type]" class="form-control">
                        <option value="">— Select —</option>
                        <option value="concrete"     {{ $opt('ceiling_type', 'concrete') }}>Concrete</option>
                        <option value="suspended"    {{ $opt('ceiling_type', 'suspended') }}>Suspended</option>
                        <option value="plasterboard" {{ $opt('ceiling_type', 'plasterboard') }}>Plasterboard</option>
                        <option value="open"         {{ $opt('ceiling_type', 'open') }}>Open (exposed)</option>
                        <option value="other"        {{ $opt('ceiling_type', 'other') }}>Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Ceiling Height (m)</label>
                    <input type="number" name="rooms[{{ $ri }}][ceiling_height_m]" class="form-control"
                           value="{{ $val('ceiling_height_m') }}" min="0" max="99" step="0.01">
                </div>
                <div class="form-group">
                    <label class="form-label">Wall Material</label>
                    <select name="rooms[{{ $ri }}][wall_material]" class="form-control">
                        <option value="">— Select —</option>
                        <option value="brick"        {{ $opt('wall_material', 'brick') }}>Brick</option>
                        <option value="plasterboard" {{ $opt('wall_material', 'plasterboard') }}>Plasterboard</option>
                        <option value="glass"        {{ $opt('wall_material', 'glass') }}>Glass</option>
                        <option value="concrete"     {{ $opt('wall_material', 'concrete') }}>Concrete</option>
                        <option value="other"        {{ $opt('wall_material', 'other') }}>Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Floor Type</label>
                    <select name="rooms[{{ $ri }}][floor_type]" class="form-control">
                        <option value="">— Select —</option>
                        <option value="concrete" {{ $opt('floor_type', 'concrete') }}>Concrete</option>
                        <option value="carpet"   {{ $opt('floor_type', 'carpet') }}>Carpet</option>
                        <option value="tiles"    {{ $opt('floor_type', 'tiles') }}>Tiles</option>
                        <option value="raised"   {{ $opt('floor_type', 'raised') }}>Raised Access</option>
                        <option value="other"    {{ $opt('floor_type', 'other') }}>Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Power Outlets</label>
                    <input type="number" name="rooms[{{ $ri }}][power_outlet_count]" class="form-control"
                           value="{{ $val('power_outlet_count') ?: 0 }}" min="0" max="999" step="1">
                </div>
                <div class="form-group">
                    <label class="form-label">Network Ports</label>
                    <input type="number" name="rooms[{{ $ri }}][network_port_count]" class="form-control"
                           value="{{ $val('network_port_count') ?: 0 }}" min="0" max="999" step="1">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Existing Cabling</label>
                <textarea name="rooms[{{ $ri }}][existing_cabling]" class="form-control"
                          rows="2" maxlength="500">{{ $val('existing_cabling') }}</textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Existing AV Equipment</label>
                <textarea name="rooms[{{ $ri }}][av_equipment_list]" class="form-control"
                          rows="2" maxlength="5000">{{ $val('av_equipment_list') }}</textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Access / Hazard Notes</label>
                <textarea name="rooms[{{ $ri }}][access_notes]" class="form-control"
                          rows="2" maxlength="500">{{ $val('access_notes') }}</textarea>
            </div>
        </div>

        {{-- ── PA SYSTEM ────────────────────────────────────────────────────────── --}}
        <div class="type-panel type-panel--pa" @if(!$showPaRm)style="display:none"@endif>
            <div class="type-panel-heading">PA System Details</div>
            <div class="form-grid-2">
                <div class="form-group">
                    <label class="form-label">Number of Speakers</label>
                    <input type="number" name="rooms[{{ $ri }}][speaker_count]" class="form-control"
                           value="{{ $val('speaker_count') }}" min="0" max="999" step="1">
                </div>
                <div class="form-group">
                    <label class="form-label">Speaker Type</label>
                    <select name="rooms[{{ $ri }}][speaker_type]" class="form-control">
                        <option value="">— Select —</option>
                        <option value="ceiling"    {{ $opt('speaker_type', 'ceiling') }}>Ceiling (flush)</option>
                        <option value="pendant"    {{ $opt('speaker_type', 'pendant') }}>Pendant</option>
                        <option value="surface"    {{ $opt('speaker_type', 'surface') }}>Surface mount</option>
                        <option value="column"     {{ $opt('speaker_type', 'column') }}>Column array</option>
                        <option value="horn"       {{ $opt('speaker_type', 'horn') }}>Horn / outdoor</option>
                        <option value="sub"        {{ $opt('speaker_type', 'sub') }}>Subwoofer</option>
                        <option value="line_array" {{ $opt('speaker_type', 'line_array') }}>Line array</option>
                        <option value="other"      {{ $opt('speaker_type', 'other') }}>Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Speaker Mounting</label>
                    <select name="rooms[{{ $ri }}][speaker_mounting]" class="form-control">
                        <option value="">— Select —</option>
                        <option value="ceiling_recessed" {{ $opt('speaker_mounting', 'ceiling_recessed') }}>Ceiling — recessed</option>
                        <option value="ceiling_surface"  {{ $opt('speaker_mounting', 'ceiling_surface') }}>Ceiling — surface</option>
                        <option value="pendant"          {{ $opt('speaker_mounting', 'pendant') }}>Pendant drop</option>
                        <option value="wall"             {{ $opt('speaker_mounting', 'wall') }}>Wall mount</option>
                        <option value="bracket"          {{ $opt('speaker_mounting', 'bracket') }}>Bracket / truss</option>
                        <option value="floor_stand"      {{ $opt('speaker_mounting', 'floor_stand') }}>Floor stand</option>
                        <option value="other"            {{ $opt('speaker_mounting', 'other') }}>Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Background Noise (dB)</label>
                    <input type="number" name="rooms[{{ $ri }}][bg_noise_db]" class="form-control"
                           value="{{ $val('bg_noise_db') }}" min="0" max="120" step="1"
                           placeholder="Measured dB(A)">
                </div>
            </div>
        </div>

        {{-- ── DIGITAL SIGNAGE ──────────────────────────────────────────────────── --}}
        <div class="type-panel type-panel--signage" @if(!$showSignRm)style="display:none"@endif>
            <div class="type-panel-heading">Digital Signage Details</div>
            <div class="form-grid-2">
                <div class="form-group">
                    <label class="form-label">Display Size (inches)</label>
                    <input type="number" name="rooms[{{ $ri }}][display_size_in]" class="form-control"
                           value="{{ $val('display_size_in') }}" min="0" max="999" step="0.1"
                           placeholder="e.g. 55, 75, 86">
                </div>
                <div class="form-group">
                    <label class="form-label">Orientation</label>
                    <select name="rooms[{{ $ri }}][display_orient]" class="form-control">
                        <option value="">— Select —</option>
                        <option value="landscape" {{ $opt('display_orient', 'landscape') }}>Landscape</option>
                        <option value="portrait"  {{ $opt('display_orient', 'portrait') }}>Portrait</option>
                    </select>
                </div>
                <div class="form-group" style="grid-column:1/-1;">
                    <label class="form-label">Mounting Type</label>
                    <select name="rooms[{{ $ri }}][display_mounting]" class="form-control">
                        <option value="">— Select —</option>
                        <option value="wall_flush"  {{ $opt('display_mounting', 'wall_flush') }}>Wall — flush / fixed</option>
                        <option value="wall_tilt"   {{ $opt('display_mounting', 'wall_tilt') }}>Wall — tilt / articulating</option>
                        <option value="ceiling"     {{ $opt('display_mounting', 'ceiling') }}>Ceiling drop mount</option>
                        <option value="floor_stand" {{ $opt('display_mounting', 'floor_stand') }}>Floor stand / totem</option>
                        <option value="desk_stand"  {{ $opt('display_mounting', 'desk_stand') }}>Desk / counter stand</option>
                        <option value="other"       {{ $opt('display_mounting', 'other') }}>Other</option>
                    </select>
                </div>
            </div>
        </div>

        {{-- ── UPGRADE / STRIP-OUT ──────────────────────────────────────────────── --}}
        <div class="type-panel type-panel--upgrade" @if(!$showUpgRm)style="display:none"@endif>
            <div class="type-panel-heading">Upgrade / Strip-out Details</div>
            <div class="form-group">
                <label class="form-label">Existing Equipment Condition</label>
                <textarea name="rooms[{{ $ri }}][existing_condition]" class="form-control" rows="2" maxlength="3000"
                          placeholder="Describe condition of existing AV kit…">{{ $val('existing_condition') }}</textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Items to Remove / Strip Out</label>
                <textarea name="rooms[{{ $ri }}][items_to_remove]" class="form-control" rows="2" maxlength="3000"
                          placeholder="List equipment to be decommissioned…">{{ $val('items_to_remove') }}</textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Items to Retain / Reuse</label>
                <textarea name="rooms[{{ $ri }}][items_to_retain]" class="form-control" rows="2" maxlength="3000"
                          placeholder="List equipment to keep and integrate…">{{ $val('items_to_retain') }}</textarea>
            </div>
        </div>

        {{-- Other notes (always shown) --}}
        <div class="form-group" style="margin-bottom:0;margin-top:.5rem;">
            <label class="form-label">Other Notes</label>
            <textarea name="rooms[{{ $ri }}][notes]" class="form-control"
                      rows="2" maxlength="500">{{ $val('notes') }}</textarea>
        </div>

    </div>{{-- /room-card-body --}}
</div>
