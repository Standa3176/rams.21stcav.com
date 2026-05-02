@extends('layouts.app')

@section('title', ($drawing->rack_label ?? 'Rack').' — Edit')

@push('styles')
    @vite(['resources/css/app.css', 'resources/js/app.js', 'resources/js/rack-editor.js'])
    <style>
        [x-cloak] { display: none !important; }
        .rack-frame {
            border: 2px solid #374151; background: #f3f4f6; border-radius: 4px;
            position: relative; min-height: 600px; padding: 4px;
        }
        .u-rail {
            display: flex; flex-direction: column-reverse; /* U-1 at bottom */
            border-right: 1px solid #cbd5e1; width: 28px;
            font-size: 10px; color: #475569; text-align: center;
        }
        .u-rail .u-tick {
            height: 24px; display: flex; align-items: center; justify-content: center;
            border-bottom: 1px dotted #e2e8f0;
        }
        .rack-item {
            background: #fff; border: 1px solid #475569; padding: 4px 8px;
            font-size: 11px; cursor: move; display: flex;
            align-items: center; justify-content: space-between;
        }
        .rack-item-locked { background: #fef3c7; cursor: not-allowed; }
        .palette-item {
            background: #fff; border: 1px solid #cbd5e1; padding: 6px 10px;
            font-size: 12px; margin-bottom: 4px; cursor: grab;
        }
        .palette-item.greyed { opacity: 0.55; }
        .palette-item:focus-visible,
        .rack-item:focus-visible {
            outline: 2px solid #0d9488;
            outline-offset: 1px;
        }
    </style>
@endpush

@section('content')
<meta name="csrf-token" content="{{ csrf_token() }}">
<div class="max-w-7xl mx-auto p-6"
     x-data="rackEditor({{ Js::from([
         'rack_meta' => (array) ($drawing->source_data['rack_meta'] ?? []),
         'rack_items' => (array) ($drawing->source_data['rack_items'] ?? []),
         'palette_rack_mounted' => $palette_rack_mounted,
         'palette_other' => $palette_other,
         'save_url' => route('projects.drawings.rack-canvas', [$project, $drawing]),
         'flip_url' => route('projects.drawings.flip-rack-mounted', $project),
     ]) }})"
     x-init="init()">

    <div class="flex items-center justify-between mb-6">
        <div>
            <a href="{{ route('projects.drawings.index', $project) }}" class="text-sm text-teal-600 hover:underline">&larr; Back to drawings</a>
            <h1 class="text-2xl font-semibold mt-1" x-text="`${rackLabel} &mdash; Edit`"></h1>
            <p class="text-sm text-gray-500">Project: {{ $project->name }} &middot; Revision {{ $drawing->revisionLabel() }}</p>
        </div>
        <div class="flex items-center gap-3">
            <span class="text-xs text-gray-500" x-show="savedAt" x-cloak>Saved <span x-text="savedAt"></span></span>
            <button type="button"
                    @click="save()"
                    :disabled="saving"
                    class="bg-teal-600 hover:bg-teal-700 disabled:opacity-50 text-white font-medium px-4 py-2 rounded-lg text-sm shadow-sm">
                <span x-show="!saving">Save Rack</span>
                <span x-show="saving" x-cloak>Saving&hellip;</span>
            </button>
        </div>
    </div>

    <div x-show="error" x-cloak class="mb-3 bg-red-50 border border-red-200 text-red-800 p-3 rounded text-sm">
        <span x-text="error"></span>
    </div>

    <div class="bg-white border border-gray-200 rounded-lg p-4 mb-4 grid grid-cols-4 gap-4">
        <label class="text-sm">
            <span class="block text-gray-700 mb-1">Rack label</span>
            <input type="text" x-model="rackLabel" maxlength="120"
                   class="w-full border-gray-300 rounded-md text-sm">
        </label>
        <label class="text-sm">
            <span class="block text-gray-700 mb-1">Height (U)</span>
            <input type="number" x-model="rackHeightU" min="1" max="99"
                   class="w-full border-gray-300 rounded-md text-sm">
        </label>
        <label class="text-sm">
            <span class="block text-gray-700 mb-1">Nominal voltage (V)</span>
            <input type="number" x-model="nominalVoltageV" min="100" max="480"
                   class="w-full border-gray-300 rounded-md text-sm">
        </label>
        <label class="text-sm">
            <span class="block text-gray-700 mb-1">Floor</span>
            <input type="text" x-model="floor" maxlength="60"
                   class="w-full border-gray-300 rounded-md text-sm">
        </label>
    </div>

    <div class="grid grid-cols-3 gap-4">
        {{-- ── PALETTE (left, 1 col) ─────────────────────────────────── --}}
        <div class="col-span-1">
            <h2 class="text-sm font-semibold mb-2 text-gray-800">Equipment palette</h2>
            <div class="bg-white border border-gray-200 rounded-lg p-3 mb-3">
                <h3 class="text-xs uppercase text-gray-500 mb-2">
                    Rack-mounted ({{ count($palette_rack_mounted) }})
                </h3>
                <div x-ref="paletteRackMounted" role="list" aria-label="Rack-mounted equipment palette">
                    @foreach ($palette_rack_mounted as $row)
                        <div class="palette-item"
                             role="listitem"
                             tabindex="0"
                             aria-label="{{ $row['name'] }} — drag into rack"
                             data-equipment-id="{{ $row['equipment_id'] }}"
                             data-name="{{ $row['name'] }}"
                             data-part-no="{{ $row['part_no'] }}"
                             data-u-height="{{ $row['u_height'] ?? 1 }}"
                             data-weight-kg="{{ $row['weight_kg'] ?? '' }}"
                             data-current-draw-a="{{ $row['current_draw_a'] ?? '' }}"
                             data-btu-per-hour="{{ $row['btu_per_hour'] ?? '' }}">
                            <div class="font-medium">{{ $row['name'] }}</div>
                            <div class="text-xs text-gray-500">
                                {{ $row['manufacturer'] ?? '' }}
                                &middot; {{ $row['part_no'] ?? '' }}
                                &middot;
                                {{ $row['u_height'] !== null ? $row['u_height'].'U' : 'U-height unknown' }}
                            </div>
                        </div>
                    @endforeach
                    @if (empty($palette_rack_mounted))
                        <p class="text-xs text-gray-500">
                            No rack-mounted equipment yet — flip the checkbox below to mark items.
                        </p>
                    @endif
                </div>
            </div>
            <div class="bg-white border border-gray-200 rounded-lg p-3">
                <h3 class="text-xs uppercase text-gray-500 mb-2">
                    Other equipment ({{ count($palette_other) }})
                </h3>
                <div x-ref="paletteOther" role="list" aria-label="Other equipment palette">
                    @foreach ($palette_other as $row)
                        <div class="palette-item greyed"
                             role="listitem"
                             tabindex="0"
                             aria-label="{{ $row['name'] }} — not rack-mounted"
                             data-equipment-id="{{ $row['equipment_id'] }}"
                             data-name="{{ $row['name'] }}"
                             data-part-no="{{ $row['part_no'] }}"
                             data-u-height="{{ $row['u_height'] ?? 1 }}"
                             data-weight-kg="{{ $row['weight_kg'] ?? '' }}"
                             data-current-draw-a="{{ $row['current_draw_a'] ?? '' }}"
                             data-btu-per-hour="{{ $row['btu_per_hour'] ?? '' }}">
                            <div class="flex items-start justify-between gap-2">
                                <div class="flex-1 min-w-0">
                                    <div class="font-medium truncate">{{ $row['name'] }}</div>
                                    <div class="text-xs text-gray-500 truncate">
                                        {{ $row['manufacturer'] ?? '' }} &middot; {{ $row['part_no'] ?? '' }}
                                    </div>
                                </div>
                                @if (! empty($row['part_no']))
                                    <label class="text-xs flex items-center gap-1 whitespace-nowrap"
                                           title="Mark as rack-mounted">
                                        <input type="checkbox"
                                               aria-label="Mark {{ $row['name'] }} as rack-mounted"
                                               @change="flipRackMounted('{{ $row['part_no'] }}', $event.target.checked)">
                                        <span>Rack?</span>
                                    </label>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- ── RACK CANVAS (right, 2 cols) ───────────────────────────── --}}
        <div class="col-span-2">
            <h2 class="text-sm font-semibold mb-2 text-gray-800">Rack — drag equipment to build</h2>
            <div class="rack-frame flex">
                <div class="u-rail" aria-label="U-position rail">
                    <template x-for="i in parseInt(rackHeightU)" :key="i">
                        <div class="u-tick" x-text="i"></div>
                    </template>
                </div>
                <div class="flex-1 flex flex-col-reverse"
                     x-ref="rackColumn"
                     role="list"
                     aria-label="Rack U-slot drop zone">
                    <template x-for="item in rackItems" :key="item.equipment_id">
                        <div class="rack-item"
                             role="listitem"
                             tabindex="0"
                             :class="{ 'rack-item-locked': item.locked }"
                             :aria-label="`${item.name} at U-${item.u_position}, ${item.u_height || '?'}U high${item.locked ? ', locked' : ''}`"
                             :data-equipment-id="item.equipment_id"
                             :data-u-height="item.u_height"
                             :data-name="item.name"
                             :data-part-no="item.part_no"
                             :data-weight-kg="item.weight_kg"
                             :data-current-draw-a="item.current_draw_a"
                             :data-btu-per-hour="item.btu_per_hour"
                             :style="`height: ${(item.u_height || 1) * 24}px;`">
                            <span class="rack-item-handle flex-1 truncate"
                                  x-text="`U-${item.u_position} · ${item.name} (${item.u_height || '?'}U)`"></span>
                            <span class="flex items-center gap-2 ml-2">
                                <button type="button"
                                        @click="toggleLock(item.equipment_id)"
                                        class="text-xs"
                                        :aria-label="item.locked ? 'Unlock U-position' : 'Lock U-position'"
                                        :title="item.locked ? 'Unlock U-position' : 'Lock U-position'">
                                    <span x-text="item.locked ? 'Locked' : 'Unlock'"></span>
                                </button>
                                <button type="button"
                                        @click="removeItem(item.equipment_id)"
                                        aria-label="Remove from rack"
                                        title="Remove from rack"
                                        class="text-xs text-red-600 hover:underline">&times;</button>
                            </span>
                        </div>
                    </template>
                </div>
            </div>
            <p class="text-xs text-gray-500 mt-2">
                U-1 is at the bottom (AVIXA convention — PDU low, patches/IO high).
                Drag from the palette into this column. Use the lock button to pin a U-position.
            </p>
        </div>
    </div>
</div>
@endsection
