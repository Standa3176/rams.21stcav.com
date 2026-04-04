{{--
  Partial: _room-form.blade.php
  Variables:
    $ri     — integer index (0-based)
    $room   — array (from old()) or SiteSurveyRoom model
    $isNew  — bool: true = creating (no IDs), false = editing (has room->id)
--}}
@php
    $isModel = $room instanceof \App\Models\SiteSurveyRoom;
    $val = fn(string $key) => $isModel ? $room->$key : ($room[$key] ?? '');
    $chk = fn(string $key) => $isModel ? (bool) $room->$key : !empty($room[$key]);
    $opt = fn(string $key, string $v) => ($val($key) == $v) ? 'selected' : '';
@endphp

<div class="room-card" style="border:1.5px solid #e0e0e0;border-radius:6px;padding:1.25rem;margin-bottom:1rem;background:#fafafa;position:relative;">

    @if($isModel && !$isNew)
        <input type="hidden" name="rooms[{{ $ri }}][id]" value="{{ $room->id }}">
    @endif

    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">
        <strong style="color:#007B8A;">Room {{ $ri + 1 }}</strong>
        <button type="button" onclick="this.closest('.room-card').remove()"
                style="background:none;border:none;color:#c0392b;cursor:pointer;font-size:1.1rem;padding:0 .25rem;">&#10005;</button>
    </div>

    <div class="form-grid-2">
        <div class="form-group">
            <label class="form-label">Room Name <span class="req">*</span></label>
            <input type="text" name="rooms[{{ $ri }}][room_name]" class="form-control"
                   value="{{ $val('room_name') }}" required maxlength="150">
        </div>
        <div class="form-group">
            <label class="form-label">Room Ref</label>
            <input type="text" name="rooms[{{ $ri }}][room_ref]" class="form-control"
                   value="{{ $val('room_ref') }}" maxlength="50">
        </div>
        <div class="form-group">
            <label class="form-label">Floor</label>
            <input type="text" name="rooms[{{ $ri }}][floor]" class="form-control"
                   value="{{ $val('floor') }}" maxlength="50" placeholder="e.g. Ground, 1st">
        </div>
    </div>

    <div class="form-group">
        <label class="form-label">AV Requirements</label>
        <textarea name="rooms[{{ $ri }}][av_requirements]" class="form-control"
                  rows="2" maxlength="1000">{{ $val('av_requirements') }}</textarea>
    </div>

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

    <button type="button" class="btn btn-outline btn-sm" style="margin-bottom:.75rem;"
            onclick="toggleInfra(this)">&#9660; Infrastructure Details</button>

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
                      rows="2" maxlength="1000">{{ $val('av_equipment_list') }}</textarea>
        </div>
        <div class="form-group">
            <label class="form-label">Access / Hazard Notes</label>
            <textarea name="rooms[{{ $ri }}][access_notes]" class="form-control"
                      rows="2" maxlength="500">{{ $val('access_notes') }}</textarea>
        </div>
    </div>

    <div class="form-group" style="margin-bottom:0;">
        <label class="form-label">Other Notes</label>
        <textarea name="rooms[{{ $ri }}][notes]" class="form-control"
                  rows="2" maxlength="500">{{ $val('notes') }}</textarea>
    </div>

</div>
