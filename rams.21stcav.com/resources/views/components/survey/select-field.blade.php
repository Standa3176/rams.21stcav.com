{{--
    survey/select-field

    A labelled <select> element bound to an Alpine.js field.

    Props:
      $field       (string) — Alpine field key on the room object
      $label       (string) — visible label
      $options     (array)  — associative [value => display] array
      $placeholder (string) — blank first option text
      $parent      (string) — Alpine.js path prefix
--}}
@props([
    'field',
    'label',
    'options'     => [],
    'placeholder' => 'Select…',
    'parent'      => 'rooms[currentRoomIdx]',
])

<div {{ $attributes->merge(['class' => '']) }}>
    <label class="block text-xs font-medium text-gray-600 mb-1">{{ $label }}</label>
    <select x-model="{{ $parent }}.{{ $field }}"
            class="w-full border border-gray-300 rounded-xl px-3 py-3 text-base bg-white
                   focus:outline-none focus:ring-2 focus:ring-[#178A95] focus:border-transparent
                   min-h-[44px]">
        <option value="">{{ $placeholder }}</option>
        @foreach ($options as $val => $display)
            <option value="{{ $val }}">{{ $display }}</option>
        @endforeach
    </select>
</div>
