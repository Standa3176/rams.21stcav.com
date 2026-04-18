{{--
    survey/toggle-field

    A tap-friendly toggle (switch) bound to an Alpine.js field on the current room.

    Props:
      $field   (string) — key name on the Alpine room object, e.g. "has_power"
      $label   (string) — display label
      $icon    (string) — optional emoji icon
      $parent  (string) — Alpine.js path prefix (default: rooms[currentRoomIdx])
      $active  (string) — Tailwind bg class when on (default: bg-[#178A95])
--}}
@props([
    'field',
    'label',
    'icon'   => null,
    'parent' => 'rooms[currentRoomIdx]',
    'active' => 'bg-[#178A95]',
])

<div class="flex items-center justify-between py-3 px-4">
    <div class="flex items-center gap-2">
        @if ($icon)
            <span class="text-lg leading-none select-none">{{ $icon }}</span>
        @endif
        <span class="text-sm font-medium text-gray-800">{{ $label }}</span>
    </div>

    {{-- Toggle button — injects the PHP $field name into the Alpine expression --}}
    <button type="button"
            @click="{{ $parent }}['{{ $field }}'] = !{{ $parent }}['{{ $field }}']"
            :class="{{ $parent }}['{{ $field }}'] ? '{{ $active }}' : 'bg-gray-300'"
            class="relative w-12 h-6 rounded-full transition-colors duration-200 flex-shrink-0 focus:outline-none focus:ring-2 focus:ring-offset-1 focus:ring-[#178A95]"
            :aria-checked="{{ $parent }}['{{ $field }}'] ? 'true' : 'false'"
            role="switch">
        <span class="absolute top-0.5 left-0.5 w-5 h-5 rounded-full bg-white shadow transition-transform duration-200"
              :class="{{ $parent }}['{{ $field }}'] ? 'translate-x-6' : 'translate-x-0'"></span>
    </button>
</div>
