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

        {{-- ── PRE-INSTALL CHECKS PANEL (Alpine.js collapsible) ──────────── --}}
        @if($isModel && $room->relationLoaded('questions') && $room->questions->isNotEmpty())
        @php
            $totalChecks07    = $room->questions->count();
            $answeredChecks07 = $room->questions->whereNotNull('answer')->count();
            $internalAnswerBase = route('site-survey.question.answer', [
                'siteSurvey' => $room->site_survey_id,
                'room'       => $room->id,
                'question'   => '__QID__',
            ]);
        @endphp
        <div x-data="{ checksOpen: false }"
             style="background:#FFFBEB;border:1.5px solid #FCD34D;border-radius:6px;margin-bottom:1rem;">
            <button type="button"
                    @click="checksOpen = !checksOpen"
                    :aria-expanded="checksOpen.toString()"
                    style="display:flex;align-items:center;gap:.5rem;width:100%;background:none;border:none;padding:.6rem .85rem;color:#92400E;font-size:.82rem;font-weight:700;cursor:pointer;text-align:left;border-radius:6px;min-height:48px;">
                <span style="background:#0B3C45;color:#fff;border-radius:4px;padding:.1rem .45rem;font-size:.7rem;font-weight:700;letter-spacing:.04em;flex-shrink:0;">PRE-INSTALL</span>
                <span style="flex:1;">Pre-Install Checks &mdash; {{ $totalChecks07 }} {{ Str::plural('question', $totalChecks07) }}</span>
                <span style="display:inline-block;transition:transform 200ms;color:#92400E;" :style="checksOpen ? 'transform:rotate(180deg)' : ''">&#9660;</span>
            </button>
            <div x-show="checksOpen"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 style="border-top:1.5px solid #FCD34D;padding:.5rem .85rem .75rem;">
                @foreach($room->questions as $qi07 => $question07)
                <div style="padding:.6rem 0;border-bottom:{{ !$loop->last ? '1px solid #FDE68A' : 'none' }};"
                     id="check-internal-{{ $question07->id }}"
                     data-answer-url="{{ str_replace('__QID__', $question07->id, $internalAnswerBase) }}">
                    <p style="font-size:.875rem;color:#1F2937;line-height:1.5;margin:0 0 .5rem;">
                        <strong>{{ $qi07 + 1 }}.</strong> {{ $question07->question }}
                    </p>
                    <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:.25rem;">
                        @foreach(['yes' => ['#D1FAE5','#059669','#065F46'], 'no' => ['#FEE2E2','#FCA5A5','#991B1B'], 'other' => ['#FEF3C7','#FCD34D','#92400E']] as $ans => [$bg, $border, $fg])
                        <button type="button"
                                data-answer="{{ $ans }}"
                                aria-label="Answer {{ ucfirst($ans) }} to question {{ $qi07 + 1 }}"
                                onclick="answerCheckInternal({{ $question07->id }}, '{{ $ans }}')"
                                style="min-height:44px;padding:.45rem 1rem;border-radius:6px;font-size:.82rem;font-weight:700;cursor:pointer;border:1.5px solid {{ $question07->answer === $ans ? $border : '#D1D5DB' }};background:{{ $question07->answer === $ans ? $bg : '#ffffff' }};color:{{ $question07->answer === $ans ? $fg : '#374151' }};">
                            {{ ucfirst($ans) }}
                        </button>
                        @endforeach
                    </div>
                    <div style="display:{{ $question07->answer === 'other' ? 'block' : 'none' }};" id="other-wrap-{{ $question07->id }}">
                        <label for="other-int-{{ $question07->id }}" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);">Explanation for "Other"</label>
                        <textarea id="other-int-{{ $question07->id }}"
                                  rows="2"
                                  placeholder="Please explain…"
                                  onblur="saveOtherTextInternal({{ $question07->id }}, this)"
                                  style="width:100%;border:1.5px solid #D1D5DB;border-radius:7px;padding:.72rem .8rem;font-size:.875rem;color:#1F2937;resize:vertical;font-family:inherit;box-sizing:border-box;">{{ $question07->other_text }}</textarea>
                    </div>
                </div>
                @endforeach
                @if($answeredChecks07 < $totalChecks07)
                <p style="font-size:.82rem;color:#6B7280;text-align:right;padding-top:.4rem;margin:0;">{{ $answeredChecks07 }} of {{ $totalChecks07 }} answered</p>
                @endif
            </div>
        </div>
        @endif

        {{-- ── Per-space type selector + qty (new rooms only) ─────────────── --}}
        <div class="form-grid-2" style="margin-bottom:.75rem;">
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">Space / Survey Type</label>
                <select name="rooms[{{ $ri }}][space_type]" class="form-control" data-optional
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
                <select name="rooms[{{ $ri }}][area_type]" class="form-control" data-optional>
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
                <input type="number" name="rooms[{{ $ri }}][qty]" class="form-control" data-optional
                       value="1" min="1" max="99" step="1">
            </div>
            @endif
            <div class="form-group">
                <label class="form-label">Ref / Number</label>
                <input type="text" name="rooms[{{ $ri }}][room_ref]" class="form-control" data-optional
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
                      rows="2" maxlength="5000" placeholder=" ">{{ $val('av_requirements') }}</textarea>
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
                    <label class="form-label">Width (m) — required for layout</label>
                    <input type="number" name="rooms[{{ $ri }}][room_width_m]" class="form-control"
                           value="{{ $val('room_width_m') }}" min="0" max="999" step="0.01" placeholder=" ">
                </div>
                <div class="form-group">
                    <label class="form-label">Depth (m) — required for layout</label>
                    <input type="number" name="rooms[{{ $ri }}][room_depth_m]" class="form-control"
                           value="{{ $val('room_depth_m') }}" min="0" max="999" step="0.01" placeholder=" ">
                </div>
                <div class="form-group">
                    <label class="form-label">Height (m) — finished floor to underside of slab</label>
                    <input type="number" name="rooms[{{ $ri }}][room_height_m]" class="form-control"
                           value="{{ $val('room_height_m') }}" min="0" max="99" step="0.01" placeholder=" ">
                </div>
                <div class="form-group">
                    <label class="form-label">Ceiling Type</label>
                    <select name="rooms[{{ $ri }}][ceiling_type]" class="form-control" data-optional>
                        <option value="">— Select —</option>
                        <option value="concrete"     {{ $opt('ceiling_type', 'concrete') }}>Concrete</option>
                        <option value="suspended"    {{ $opt('ceiling_type', 'suspended') }}>Suspended</option>
                        <option value="plasterboard" {{ $opt('ceiling_type', 'plasterboard') }}>Plasterboard</option>
                        <option value="open"         {{ $opt('ceiling_type', 'open') }}>Open (exposed)</option>
                        <option value="other"        {{ $opt('ceiling_type', 'other') }}>Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Ceiling Height (m) — finished floor to ceiling tile</label>
                    <input type="number" name="rooms[{{ $ri }}][ceiling_height_m]" class="form-control"
                           value="{{ $val('ceiling_height_m') }}" min="0" max="99" step="0.01" placeholder=" ">
                </div>
                <div class="form-group">
                    <label class="form-label">Wall Material</label>
                    <select name="rooms[{{ $ri }}][wall_material]" class="form-control" data-optional>
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
                    <select name="rooms[{{ $ri }}][floor_type]" class="form-control" data-optional>
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
                    <input type="number" name="rooms[{{ $ri }}][power_outlet_count]" class="form-control" data-optional
                           value="{{ $val('power_outlet_count') ?: 0 }}" min="0" max="999" step="1">
                </div>
                <div class="form-group">
                    <label class="form-label">Network Ports</label>
                    <input type="number" name="rooms[{{ $ri }}][network_port_count]" class="form-control" data-optional
                           value="{{ $val('network_port_count') ?: 0 }}" min="0" max="999" step="1">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Existing Cabling</label>
                <textarea name="rooms[{{ $ri }}][existing_cabling]" class="form-control" data-optional
                          rows="2" maxlength="500">{{ $val('existing_cabling') }}</textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Cable Route Description</label>
                <textarea name="rooms[{{ $ri }}][cable_route_desc]" class="form-control" data-optional
                          rows="2" maxlength="3000"
                          placeholder="Describe the planned cable routing path…">{{ $val('cable_route_desc') }}</textarea>
            </div>
            <div class="form-grid-2">
                <div class="form-group">
                    <label class="form-label">Cable Route From</label>
                    <input type="text" name="rooms[{{ $ri }}][cable_route_from]" class="form-control" data-optional
                           value="{{ $val('cable_route_from') }}" maxlength="500"
                           placeholder="e.g. Rack room, riser B1…">
                </div>
                <div class="form-group">
                    <label class="form-label">Cable Route To</label>
                    <input type="text" name="rooms[{{ $ri }}][cable_route_to]" class="form-control" data-optional
                           value="{{ $val('cable_route_to') }}" maxlength="500"
                           placeholder="e.g. Boardroom, display position…">
                </div>
            </div>
            <div class="form-grid-2">
                <div class="form-group">
                    <label class="form-label">Projection Throw (m)</label>
                    <input type="number" name="rooms[{{ $ri }}][projection_throw_m]" class="form-control" data-optional
                           value="{{ $val('projection_throw_m') }}" min="0" max="999" step="0.01"
                           placeholder="e.g. 3.5">
                </div>
                <div class="form-group">
                    <label class="form-label">Viewing Distance (m)</label>
                    <input type="number" name="rooms[{{ $ri }}][viewing_distance_m]" class="form-control" data-optional
                           value="{{ $val('viewing_distance_m') }}" min="0" max="999" step="0.01"
                           placeholder="e.g. 5.0">
                </div>
            </div>
            <div class="form-group">
                <label class="check-item" style="cursor:pointer;">
                    <input type="hidden" name="rooms[{{ $ri }}][is_rack_room]" value="0">
                    <input type="checkbox" name="rooms[{{ $ri }}][is_rack_room]" value="1"
                           {{ $chk('is_rack_room') ? 'checked' : '' }}>
                    <span>This is a rack / equipment room</span>
                </label>
            </div>
            <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#178A95;margin:.5rem 0 .4rem;">
                Network Details
            </div>
            <div class="form-grid-2">
                <div class="form-group">
                    <label class="form-label">SSID / Network Name</label>
                    <input type="text" name="rooms[{{ $ri }}][network_ssid]" class="form-control" data-optional
                           value="{{ $val('network_ssid') }}" maxlength="255"
                           placeholder="e.g. Corp-AV-Net">
                </div>
                <div class="form-group">
                    <label class="form-label">VLAN</label>
                    <input type="text" name="rooms[{{ $ri }}][network_vlan]" class="form-control" data-optional
                           value="{{ $val('network_vlan') }}" maxlength="100"
                           placeholder="e.g. 100">
                </div>
                <div class="form-group">
                    <label class="form-label">Switch Port</label>
                    <input type="text" name="rooms[{{ $ri }}][network_switch_port]" class="form-control" data-optional
                           value="{{ $val('network_switch_port') }}" maxlength="100"
                           placeholder="e.g. SW-1 Port 12">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Existing AV Equipment</label>
                <textarea name="rooms[{{ $ri }}][av_equipment_list]" class="form-control" data-optional
                          rows="2" maxlength="5000">{{ $val('av_equipment_list') }}</textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Access / Hazard Notes</label>
                <textarea name="rooms[{{ $ri }}][access_notes]" class="form-control" data-optional
                          rows="2" maxlength="500">{{ $val('access_notes') }}</textarea>
            </div>
        </div>

        {{-- ──────────────────────────────────────────────────────────────────── --}}
        {{-- ENGINEER-FEEDBACK SECTIONS (quick task 260503-rgg)                    --}}
        {{-- Mounting heights / WAH methods / Cable routes / Wall construction /  --}}
        {{-- Table info / Floor box info / Brackets — all nullable, all additive. --}}
        {{-- ──────────────────────────────────────────────────────────────────── --}}
        @php
            $heights     = $val('mounting_heights') ?: [];
            $cableRows   = $val('cable_routes') ?: [];
            $bracketRows = $val('brackets_required') ?: [];
            $wahMethods  = $val('work_at_height_methods') ?: [];
            $wallConstr  = $val('wall_construction') ?: [];
            $tableInfo   = $val('table_info') ?: [];
            $floorBox    = $val('floor_box_info') ?: [];
            // Defensive: $val() may hand back a JSON string rather than an array
            // for legacy SQLite drivers that don't auto-decode. Cast to array.
            $heights     = is_array($heights)     ? $heights     : (array) (json_decode((string) $heights, true) ?: []);
            $cableRows   = is_array($cableRows)   ? $cableRows   : (array) (json_decode((string) $cableRows, true) ?: []);
            $bracketRows = is_array($bracketRows) ? $bracketRows : (array) (json_decode((string) $bracketRows, true) ?: []);
            $wahMethods  = is_array($wahMethods)  ? $wahMethods  : (array) (json_decode((string) $wahMethods, true) ?: []);
            $wallConstr  = is_array($wallConstr)  ? $wallConstr  : (array) (json_decode((string) $wallConstr, true) ?: []);
            $tableInfo   = is_array($tableInfo)   ? $tableInfo   : (array) (json_decode((string) $tableInfo, true) ?: []);
            $floorBox    = is_array($floorBox)    ? $floorBox    : (array) (json_decode((string) $floorBox, true) ?: []);
        @endphp

        {{-- (a) Mounting Heights ─────────────────────────────────────────────── --}}
        <div class="room-subsection" id="r{{ $ri }}_mounting_heights">
            <div class="room-subsection__heading">Mounting Heights</div>
            <div class="form-grid-2">
                <div class="form-group">
                    <label class="form-label" for="r{{ $ri }}_screen_h_m">Screen height (m)</label>
                    <input type="number" id="r{{ $ri }}_screen_h_m"
                           name="rooms[{{ $ri }}][mounting_heights][screen_h_m]"
                           class="form-control" data-optional min="0" max="99" step="0.01" placeholder=" "
                           value="{{ $heights['screen_h_m'] ?? '' }}">
                </div>
                <div class="form-group">
                    <label class="form-label" for="r{{ $ri }}_camera_h_m">Camera height (m)</label>
                    <input type="number" id="r{{ $ri }}_camera_h_m"
                           name="rooms[{{ $ri }}][mounting_heights][camera_h_m]"
                           class="form-control" data-optional min="0" max="99" step="0.01" placeholder=" "
                           value="{{ $heights['camera_h_m'] ?? '' }}">
                </div>
                <div class="form-group">
                    <label class="form-label" for="r{{ $ri }}_booking_panel_h_m">Booking panel height (m)</label>
                    <input type="number" id="r{{ $ri }}_booking_panel_h_m"
                           name="rooms[{{ $ri }}][mounting_heights][booking_panel_h_m]"
                           class="form-control" data-optional min="0" max="99" step="0.01" placeholder=" "
                           value="{{ $heights['booking_panel_h_m'] ?? '' }}">
                </div>
                <div class="form-group">
                    <label class="form-label" for="r{{ $ri }}_speaker_h_m">Speaker height (m)</label>
                    <input type="number" id="r{{ $ri }}_speaker_h_m"
                           name="rooms[{{ $ri }}][mounting_heights][speaker_h_m]"
                           class="form-control" data-optional min="0" max="99" step="0.01" placeholder=" "
                           value="{{ $heights['speaker_h_m'] ?? '' }}">
                </div>
            </div>

            <div x-data="{ rows: @js(is_array($heights['other'] ?? null) ? array_values($heights['other']) : []) }" style="margin-top:.75rem;">
                <label class="form-label">Other Mounting Heights</label>
                <template x-if="rows.length === 0">
                    <p style="font-size:.82rem;color:var(--text-muted, #64748B);font-style:italic;margin:0 0 .5rem;">
                        No additional mounting heights added yet — click below to add one.
                    </p>
                </template>
                <template x-for="(row, i) in rows" :key="i">
                    <div class="form-grid-2" style="margin-bottom:.5rem;align-items:end;">
                        <div class="form-group" style="margin-bottom:0;">
                            <input type="text"
                                   :name="`rooms[{{ $ri }}][mounting_heights][other][${i}][label]`"
                                   x-model="row.label" class="form-control" maxlength="150"
                                   placeholder="e.g. Mic boom" data-optional>
                        </div>
                        <div class="form-group" style="margin-bottom:0;display:flex;gap:.5rem;">
                            <input type="number"
                                   :name="`rooms[{{ $ri }}][mounting_heights][other][${i}][height_m]`"
                                   x-model="row.height_m" class="form-control"
                                   min="0" max="99" step="0.01" placeholder="height (m)" data-optional>
                            <button type="button" @click="rows.splice(i,1)"
                                    style="background:none;border:1px solid #c0392b;color:#c0392b;border-radius:6px;padding:0 .65rem;cursor:pointer;">&#10005;</button>
                        </div>
                    </div>
                </template>
                <button type="button" @click="rows.push({label:'',height_m:''})"
                        class="btn btn-outline btn-sm">+ Add Mounting Height</button>
            </div>
        </div>

        {{-- (b) Working at Height Methods ─────────────────────────────────────── --}}
        <div class="room-subsection" id="r{{ $ri }}_wah_methods">
            <div class="room-subsection__heading">Working at Height Methods</div>
            <div style="display:flex;gap:1rem;flex-wrap:wrap;">
                @foreach([
                    'ladder'   => 'Ladder',
                    'podium'   => 'Podium step',
                    'tower'    => 'Mobile tower',
                    'mewp'     => 'MEWP / cherry picker',
                    'scaffold' => 'Scaffold',
                    'na'       => 'N/A — ground level only',
                ] as $wahVal => $wahLabel)
                    <label class="check-item" style="cursor:pointer;">
                        <input type="checkbox"
                               name="rooms[{{ $ri }}][work_at_height_methods][]"
                               value="{{ $wahVal }}"
                               {{ in_array($wahVal, $wahMethods, true) ? 'checked' : '' }}>
                        <span>{{ $wahLabel }}</span>
                    </label>
                @endforeach
            </div>
        </div>

        {{-- (c) Cable Routes ──────────────────────────────────────────────────── --}}
        <div class="room-subsection" id="r{{ $ri }}_cable_routes">
            <div class="room-subsection__heading">Cable Routes</div>
            <div x-data="{ rows: @js(array_values($cableRows)) }">
                <template x-if="rows.length === 0">
                    <p style="font-size:.82rem;color:var(--text-muted, #64748B);font-style:italic;margin:0 0 .5rem;">
                        No cable routes added yet — click below to add one.
                    </p>
                </template>
                <template x-for="(row, i) in rows" :key="i">
                    <div style="border:1px solid #e0e0e0;border-radius:6px;padding:.6rem;margin-bottom:.5rem;background:#fff;">
                        <div class="form-grid-2">
                            <div class="form-group" style="margin-bottom:.5rem;">
                                <label class="form-label">Category</label>
                                <select :name="`rooms[{{ $ri }}][cable_routes][${i}][category]`"
                                        x-model="row.category" class="form-control" data-optional>
                                    <option value="">— Select —</option>
                                    <option value="ceiling_speakers">Ceiling speakers</option>
                                    <option value="desk_cables">Desk cables</option>
                                    <option value="mic_cables">Mic cables</option>
                                    <option value="booking_panel_cables">Booking panel cables</option>
                                    <option value="screen_cables">Screen cables</option>
                                    <option value="rack_to_room">Rack to room</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div class="form-group" style="margin-bottom:.5rem;">
                                <label class="form-label">Length (m)</label>
                                <input type="number"
                                       :name="`rooms[{{ $ri }}][cable_routes][${i}][length_m]`"
                                       x-model="row.length_m" class="form-control" data-optional
                                       min="0" max="9999" step="0.1" placeholder=" ">
                            </div>
                            <div class="form-group" style="margin-bottom:.5rem;">
                                <label class="form-label">From</label>
                                <input type="text"
                                       :name="`rooms[{{ $ri }}][cable_routes][${i}][from]`"
                                       x-model="row.from" class="form-control" data-optional maxlength="255"
                                       placeholder="e.g. Rack room A">
                            </div>
                            <div class="form-group" style="margin-bottom:.5rem;">
                                <label class="form-label">To</label>
                                <input type="text"
                                       :name="`rooms[{{ $ri }}][cable_routes][${i}][to]`"
                                       x-model="row.to" class="form-control" data-optional maxlength="255"
                                       placeholder="e.g. Display position">
                            </div>
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label">Notes</label>
                            <input type="text"
                                   :name="`rooms[{{ $ri }}][cable_routes][${i}][notes]`"
                                   x-model="row.notes" class="form-control" maxlength="500"
                                   placeholder="e.g. Through ceiling void, drop into floor box" data-optional>
                        </div>
                        <div style="text-align:right;margin-top:.4rem;">
                            <button type="button" @click="rows.splice(i,1)"
                                    style="background:none;border:1px solid #c0392b;color:#c0392b;border-radius:6px;padding:.2rem .65rem;cursor:pointer;font-size:.78rem;">Remove route</button>
                        </div>
                    </div>
                </template>
                <button type="button" @click="rows.push({category:'',from:'',to:'',length_m:'',notes:''})"
                        class="btn btn-outline btn-sm">+ Add Cable Route</button>
                <p style="font-size:.78rem;color:#6B7280;margin:.5rem 0 0;">
                    Add one row per cable category. Length captures total cable run including slack.
                </p>
            </div>
        </div>

        {{-- (d) Wall Construction + Prep Flags ─────────────────────────────────── --}}
        <div class="room-subsection" id="r{{ $ri }}_wall_construction">
            <div class="room-subsection__heading">Wall Construction &amp; Prep</div>
            <label class="form-label" style="margin-bottom:.4rem;">Wall construction (multi-select)</label>
            <div style="display:flex;gap:1rem;flex-wrap:wrap;margin-bottom:.75rem;">
                @foreach([
                    'ply_lined'    => 'Ply-lined',
                    'solid'        => 'Solid',
                    'plasterboard' => 'Plasterboard',
                    'masonry'      => 'Masonry',
                    'metal_stud'   => 'Metal stud',
                    'concrete'     => 'Concrete',
                ] as $wcVal => $wcLabel)
                    <label class="check-item" style="cursor:pointer;">
                        <input type="checkbox"
                               name="rooms[{{ $ri }}][wall_construction][]"
                               value="{{ $wcVal }}"
                               {{ in_array($wcVal, $wallConstr, true) ? 'checked' : '' }}>
                        <span>{{ $wcLabel }}</span>
                    </label>
                @endforeach
            </div>

            <label class="form-label" style="margin-bottom:.4rem;">Wall preparation needed</label>
            <div style="display:flex;gap:1.5rem;flex-wrap:wrap;">
                <label class="check-item" style="cursor:pointer;">
                    <input type="hidden" name="rooms[{{ $ri }}][wall_needs_reinforcement]" value="0">
                    <input type="checkbox" name="rooms[{{ $ri }}][wall_needs_reinforcement]" value="1"
                           {{ $chk('wall_needs_reinforcement') ? 'checked' : '' }}>
                    <span>Wall needs reinforcement</span>
                </label>
                <label class="check-item" style="cursor:pointer;">
                    <input type="hidden" name="rooms[{{ $ri }}][wall_needs_chase_out]" value="0">
                    <input type="checkbox" name="rooms[{{ $ri }}][wall_needs_chase_out]" value="1"
                           {{ $chk('wall_needs_chase_out') ? 'checked' : '' }}>
                    <span>Wall needs chase-out / chasing</span>
                </label>
                <label class="check-item" style="cursor:pointer;">
                    <input type="hidden" name="rooms[{{ $ri }}][wall_needs_conduit]" value="0">
                    <input type="checkbox" name="rooms[{{ $ri }}][wall_needs_conduit]" value="1"
                           {{ $chk('wall_needs_conduit') ? 'checked' : '' }}>
                    <span>Wall needs conduit run</span>
                </label>
            </div>
        </div>

        {{-- (e) Table Info ────────────────────────────────────────────────────── --}}
        <div class="room-subsection" id="r{{ $ri }}_table_info">
            <div class="room-subsection__heading">Table Info</div>
            <div class="form-grid-2">
                <div class="form-group">
                    <label class="check-item" style="cursor:pointer;">
                        <input type="hidden" name="rooms[{{ $ri }}][table_info][has_grommets]" value="0">
                        <input type="checkbox" name="rooms[{{ $ri }}][table_info][has_grommets]" value="1"
                               {{ ! empty($tableInfo['has_grommets']) ? 'checked' : '' }}>
                        <span>Table has grommets</span>
                    </label>
                </div>
                <div class="form-group">
                    <label class="form-label" for="r{{ $ri }}_grommet_count">Grommet count</label>
                    <input type="number" id="r{{ $ri }}_grommet_count"
                           name="rooms[{{ $ri }}][table_info][grommet_count]"
                           class="form-control" data-optional min="0" max="99" step="1"
                           value="{{ $tableInfo['grommet_count'] ?? '' }}">
                </div>
                <div class="form-group">
                    <label class="form-label" for="r{{ $ri }}_grommet_size">Grommet size</label>
                    <select id="r{{ $ri }}_grommet_size"
                            name="rooms[{{ $ri }}][table_info][grommet_size]"
                            class="form-control" data-optional>
                        <option value="">— Select —</option>
                        @php $gs = $tableInfo['grommet_size'] ?? ''; @endphp
                        <option value="small"    @selected($gs === 'small')>Small</option>
                        <option value="standard" @selected($gs === 'standard')>Standard</option>
                        <option value="large"    @selected($gs === 'large')>Large</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="r{{ $ri }}_table_notes">Table notes</label>
                    <input type="text" id="r{{ $ri }}_table_notes"
                           name="rooms[{{ $ri }}][table_info][notes]"
                           class="form-control" data-optional maxlength="500"
                           value="{{ $tableInfo['notes'] ?? '' }}"
                           placeholder="e.g. Solid oak, hidden raceway">
                </div>
            </div>
        </div>

        {{-- (f) Floor Box Info ────────────────────────────────────────────────── --}}
        <div class="room-subsection" id="r{{ $ri }}_floor_box_info">
            <div class="room-subsection__heading">Floor Box Info</div>
            <div class="form-grid-2">
                <div class="form-group">
                    <label class="check-item" style="cursor:pointer;">
                        <input type="hidden" name="rooms[{{ $ri }}][floor_box_info][has_floor_box]" value="0">
                        <input type="checkbox" name="rooms[{{ $ri }}][floor_box_info][has_floor_box]" value="1"
                               {{ ! empty($floorBox['has_floor_box']) ? 'checked' : '' }}>
                        <span>Room has floor box</span>
                    </label>
                </div>
                <div class="form-group">
                    <label class="form-label" for="r{{ $ri }}_fb_power_outlets">Power outlets</label>
                    <input type="number" id="r{{ $ri }}_fb_power_outlets"
                           name="rooms[{{ $ri }}][floor_box_info][power_outlets]"
                           class="form-control" data-optional min="0" max="99" step="1"
                           value="{{ $floorBox['power_outlets'] ?? '' }}">
                </div>
                <div class="form-group">
                    <label class="form-label" for="r{{ $ri }}_fb_data_outlets">Data outlets</label>
                    <input type="number" id="r{{ $ri }}_fb_data_outlets"
                           name="rooms[{{ $ri }}][floor_box_info][data_outlets]"
                           class="form-control" data-optional min="0" max="99" step="1"
                           value="{{ $floorBox['data_outlets'] ?? '' }}">
                </div>
                <div class="form-group">
                    <label class="form-label" for="r{{ $ri }}_fb_cable_space">Cable space</label>
                    <select id="r{{ $ri }}_fb_cable_space"
                            name="rooms[{{ $ri }}][floor_box_info][cable_space]"
                            class="form-control" data-optional>
                        <option value="">— Select —</option>
                        @php $cs = $floorBox['cable_space'] ?? ''; @endphp
                        <option value="tight"    @selected($cs === 'tight')>Tight</option>
                        <option value="adequate" @selected($cs === 'adequate')>Adequate</option>
                        <option value="spacious" @selected($cs === 'spacious')>Spacious</option>
                    </select>
                </div>
                <div class="form-group" style="grid-column:1/-1;">
                    <label class="form-label" for="r{{ $ri }}_fb_notes">Floor box notes</label>
                    <input type="text" id="r{{ $ri }}_fb_notes"
                           name="rooms[{{ $ri }}][floor_box_info][notes]"
                           class="form-control" data-optional maxlength="500"
                           value="{{ $floorBox['notes'] ?? '' }}"
                           placeholder="e.g. 4-gang under boardroom table, lid 200mm × 300mm">
                </div>
            </div>
        </div>

        {{-- (g) Brackets Required ─────────────────────────────────────────────── --}}
        <div class="room-subsection" id="r{{ $ri }}_brackets_required">
            <div class="room-subsection__heading">Brackets Required</div>
            <div x-data="{ rows: @js(array_values($bracketRows)) }">
                <template x-if="rows.length === 0">
                    <p style="font-size:.82rem;color:var(--text-muted, #64748B);font-style:italic;margin:0 0 .5rem;">
                        No brackets added yet — click below to add one.
                    </p>
                </template>
                <template x-for="(row, i) in rows" :key="i">
                    <div style="border:1px solid #e0e0e0;border-radius:6px;padding:.6rem;margin-bottom:.5rem;background:#fff;">
                        <div class="form-grid-2">
                            <div class="form-group" style="margin-bottom:.5rem;">
                                <label class="form-label">Equipment</label>
                                <input type="text"
                                       :name="`rooms[{{ $ri }}][brackets_required][${i}][equipment]`"
                                       x-model="row.equipment" class="form-control" data-optional maxlength="255"
                                       placeholder='e.g. 75&quot; display'>
                            </div>
                            <div class="form-group" style="margin-bottom:.5rem;">
                                <label class="form-label">Bracket Model</label>
                                <input type="text"
                                       :name="`rooms[{{ $ri }}][brackets_required][${i}][model]`"
                                       x-model="row.model" class="form-control" data-optional maxlength="255"
                                       placeholder="e.g. Vogels PFW6880">
                            </div>
                        </div>
                        <div style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap;margin-bottom:.5rem;">
                            <label class="check-item" style="cursor:pointer;">
                                <input type="hidden"
                                       :name="`rooms[{{ $ri }}][brackets_required][${i}][pull_out]`" value="0">
                                <input type="checkbox"
                                       :name="`rooms[{{ $ri }}][brackets_required][${i}][pull_out]`" value="1"
                                       :checked="row.pull_out == 1 || row.pull_out === true"
                                       @change="row.pull_out = $event.target.checked ? 1 : 0">
                                <span>Pull-out / articulating bracket</span>
                            </label>
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label">Notes</label>
                            <input type="text"
                                   :name="`rooms[{{ $ri }}][brackets_required][${i}][notes]`"
                                   x-model="row.notes" class="form-control" maxlength="500"
                                   placeholder="e.g. VESA 600×400; check wall plate weight rating" data-optional>
                        </div>
                        <div style="text-align:right;margin-top:.4rem;">
                            <button type="button" @click="rows.splice(i,1)"
                                    style="background:none;border:1px solid #c0392b;color:#c0392b;border-radius:6px;padding:.2rem .65rem;cursor:pointer;font-size:.78rem;">Remove bracket</button>
                        </div>
                    </div>
                </template>
                <button type="button" @click="rows.push({equipment:'',model:'',pull_out:0,notes:''})"
                        class="btn btn-outline btn-sm">+ Add Bracket</button>
            </div>
        </div>

        {{-- ── Engineer sign-off ──────────────────────────────────────────────── --}}
        <div style="background:#F0FFF4;border:1.5px solid #86EFAC;border-radius:6px;padding:.75rem .85rem;margin-top:.75rem;">
            <div style="font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#14532D;margin-bottom:.5rem;">
                ✅ Engineer Sign-off
            </div>
            <div style="margin-bottom:.5rem;">
                <label class="check-item" style="cursor:pointer;">
                    <input type="hidden" name="rooms[{{ $ri }}][engineer_confirmed]" value="0">
                    <input type="checkbox" name="rooms[{{ $ri }}][engineer_confirmed]" value="1"
                           {{ $chk('engineer_confirmed') ? 'checked' : '' }}>
                    <span>I confirm the above survey data is accurate and complete for this room.</span>
                </label>
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">Engineer Name (Print)</label>
                <input type="text" name="rooms[{{ $ri }}][engineer_signature_name]" class="form-control" data-optional
                       value="{{ $val('engineer_signature_name') }}" maxlength="255"
                       placeholder="Full name of surveying engineer">
            </div>
        </div>

        {{-- ── PA SYSTEM ────────────────────────────────────────────────────────── --}}
        <div class="type-panel type-panel--pa" @if(!$showPaRm)style="display:none"@endif>
            <div class="type-panel-heading">PA System Details</div>
            <div class="form-grid-2">
                <div class="form-group">
                    <label class="form-label">Number of Speakers</label>
                    <input type="number" name="rooms[{{ $ri }}][speaker_count]" class="form-control" data-optional
                           value="{{ $val('speaker_count') }}" min="0" max="999" step="1">
                </div>
                <div class="form-group">
                    <label class="form-label">Speaker Type</label>
                    <select name="rooms[{{ $ri }}][speaker_type]" class="form-control" data-optional>
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
                    <select name="rooms[{{ $ri }}][speaker_mounting]" class="form-control" data-optional>
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
                    <input type="number" name="rooms[{{ $ri }}][bg_noise_db]" class="form-control" data-optional
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
                    <input type="number" name="rooms[{{ $ri }}][display_size_in]" class="form-control" data-optional
                           value="{{ $val('display_size_in') }}" min="0" max="999" step="0.1"
                           placeholder="e.g. 55, 75, 86">
                </div>
                <div class="form-group">
                    <label class="form-label">Orientation</label>
                    <select name="rooms[{{ $ri }}][display_orient]" class="form-control" data-optional>
                        <option value="">— Select —</option>
                        <option value="landscape" {{ $opt('display_orient', 'landscape') }}>Landscape</option>
                        <option value="portrait"  {{ $opt('display_orient', 'portrait') }}>Portrait</option>
                    </select>
                </div>
                <div class="form-group" style="grid-column:1/-1;">
                    <label class="form-label">Mounting Type</label>
                    <select name="rooms[{{ $ri }}][display_mounting]" class="form-control" data-optional>
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
                <textarea name="rooms[{{ $ri }}][existing_condition]" class="form-control" data-optional rows="2" maxlength="3000"
                          placeholder="Describe condition of existing AV kit…">{{ $val('existing_condition') }}</textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Items to Remove / Strip Out</label>
                <textarea name="rooms[{{ $ri }}][items_to_remove]" class="form-control" data-optional rows="2" maxlength="3000"
                          placeholder="List equipment to be decommissioned…">{{ $val('items_to_remove') }}</textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Items to Retain / Reuse</label>
                <textarea name="rooms[{{ $ri }}][items_to_retain]" class="form-control" data-optional rows="2" maxlength="3000"
                          placeholder="List equipment to keep and integrate…">{{ $val('items_to_retain') }}</textarea>
            </div>
        </div>

        {{-- Other notes (always shown) --}}
        <div class="form-group" style="margin-bottom:0;margin-top:.5rem;">
            <label class="form-label">Other Notes</label>
            <textarea name="rooms[{{ $ri }}][notes]" class="form-control" data-optional
                      rows="2" maxlength="500">{{ $val('notes') }}</textarea>
        </div>

    </div>{{-- /room-card-body --}}
</div>
