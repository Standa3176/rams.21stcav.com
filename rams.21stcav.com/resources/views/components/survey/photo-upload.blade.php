{{--
    survey/photo-upload

    A photo capture card for a single category.
    Uploads immediately on file selection via the uploadPhoto() Alpine method.
    Shows thumbnails for already-uploaded photos in this category.

    Props:
      $category (string) — type/category key, e.g. "room_overview"
      $label    (string) — human-readable label
      $icon     (string) — emoji icon

    Expects the parent Alpine component to expose:
      - currentRoom._ui.room_id  (DB room id for the upload endpoint)
      - currentRoom.photos        (canonical photo array: [{type, file_path}])
      - uploadPhoto(roomId, input)
--}}
@props(['category', 'label', 'icon' => '📷'])

<div class="bg-white rounded-2xl p-4 shadow-sm">
    <div class="flex items-center justify-between mb-2">
        <div class="flex items-center gap-2">
            <span class="text-xl leading-none select-none">{{ $icon }}</span>
            <span class="text-sm font-semibold text-gray-700">{{ $label }}</span>
        </div>

        {{-- Camera button — opens file picker / camera on mobile --}}
        <label class="flex items-center justify-center w-11 h-11 bg-[#178A95] rounded-xl
                       cursor-pointer text-white hover:bg-[#0d6e77] transition-colors flex-shrink-0">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86
                         a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0
                         01-2 2H5a2 2 0 01-2-2V9z"/>
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/>
            </svg>
            <input type="file"
                   accept="image/*"
                   data-category="{{ $category }}"
                   class="sr-only"
                   @change="uploadPhoto(currentRoom._ui.room_id, $event.target)">
        </label>
    </div>

    {{-- Uploaded photos for this category — thumbnail + editable caption --}}
    <div class="flex flex-col gap-2 mt-1">
        <template x-for="(photo, photoIdx) in (currentRoom?.photos ?? []).filter(p => p.type === '{{ $category }}')"
                  :key="photo.id ?? photo.file_path">
            <div class="flex items-start gap-2">
                <div class="relative w-16 h-16 rounded-lg overflow-hidden bg-gray-100 flex-shrink-0">
                    <img :src="photo.file_path"
                         class="w-full h-full object-cover"
                         :alt="'{{ $label }}'">
                </div>
                <input type="text"
                       maxlength="200"
                       placeholder="Add a note (e.g. 'crack above socket')"
                       class="flex-1 text-sm rounded-lg border-gray-300 focus:border-[#178A95] focus:ring-[#178A95]"
                       :value="photo.caption ?? ''"
                       @blur="savePhotoCaption(photo, $event.target.value)"
                       @keydown.enter.prevent="$event.target.blur()">
            </div>
        </template>
    </div>

    {{-- Empty state --}}
    <p x-show="(currentRoom?.photos ?? []).filter(p => p.type === '{{ $category }}').length === 0"
       class="text-xs text-gray-400 mt-1">No photos yet</p>
</div>
