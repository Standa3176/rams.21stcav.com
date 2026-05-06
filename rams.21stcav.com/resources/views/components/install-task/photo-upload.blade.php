@props(['task'])

{{--
    Phase 14 photo upload component (D-09 / D-12).
    Forked from components/survey/photo-upload.blade.php — retargeted to $task->photos.

    UI-SPEC:
      - 80×80 thumbnails, horizontal scroll strip
      - Dashed camera placeholder — opens iOS/Android picker (camera OR photo library)
      - Caption input blur-saves to /install-task-photos/{photo}
      - Tap thumbnail opens native <dialog> lightbox

    Endpoints used:
      POST   /install-tasks/{task}/photos     — upload (multipart FormData)
      PATCH  /install-task-photos/{photo}     — caption update
      DELETE /install-task-photos/{photo}     — delete
      GET    /install-task-photos/{photo}     — binary stream (thumbnail src)
--}}
<div
    data-testid="task-photo-upload"
    x-data="{
        photos: @js($task->photos->map(fn ($p) => [
            'id'            => $p->id,
            'url'           => route('install-task-photos.show', $p),
            'original_name' => $p->original_name,
            'caption'       => $p->caption ?? '',
            'mime_type'     => $p->mime_type,
        ])->values()),
        uploading: false,
        error: null,
        lightboxUrl: null,
        csrf() { return document.querySelector('meta[name=csrf-token]').content; },
        async upload(input) {
            const file = input.files[0];
            if (!file) return;
            this.error = null;

            if (file.size > 20 * 1024 * 1024) {
                this.error = 'Photo too large — 20 MB max. Try a smaller photo.';
                input.value = '';
                return;
            }

            const fd = new FormData();
            fd.append('photo', file);
            this.uploading = true;

            try {
                const res = await fetch('/install-tasks/{{ $task->id }}/photos', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this.csrf(), 'Accept': 'application/json' },
                    body: fd,
                });
                if (!res.ok) {
                    if (res.status === 500) {
                        this.error = 'Couldn\'t convert this photo — tell your PM the server needs checking.';
                    } else if (res.status === 422) {
                        this.error = 'Only photos (JPG, PNG, HEIC) — this file looks like something else.';
                    } else {
                        this.error = 'Couldn\'t upload. Check your signal and try again.';
                    }
                    return;
                }
                const data = await res.json();
                this.photos.push({ ...data, caption: data.caption ?? '' });
            } catch (e) {
                this.error = 'Couldn\'t upload. Check your signal and try again.';
            } finally {
                this.uploading = false;
                input.value = '';
            }
        },
        async saveCaption(photo) {
            try {
                await fetch('/install-task-photos/' + photo.id, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this.csrf(),
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ caption: photo.caption }),
                });
            } catch (e) { /* silent — spec: caption errors are inline, not blocking */ }
        },
        openLightbox(url) { this.lightboxUrl = url; this.$refs.lightbox?.showModal(); },
        closeLightbox() { this.$refs.lightbox?.close(); this.lightboxUrl = null; },
        async deletePhoto(photo) {
            if (!photo) return;
            if (!(await window.appConfirm('Delete this photo? This can\'t be undone.', { title: 'Delete photo?', confirmLabel: 'Delete', danger: true }))) return;
            try {
                const res = await fetch('/install-task-photos/' + photo.id, {
                    method: 'DELETE',
                    headers: { 'X-CSRF-TOKEN': this.csrf(), 'Accept': 'application/json' },
                });
                if (res.ok) {
                    this.photos = this.photos.filter(p => p.id !== photo.id);
                    this.closeLightbox();
                }
            } catch (e) { /* ignore */ }
        },
    }"
    class="mt-2"
>
    {{-- Inline error (only when present) --}}
    <p x-show="error" x-text="error" x-cloak
       class="text-red-600 text-xs mb-2" role="alert" aria-live="polite"></p>

    <div class="flex gap-2 overflow-x-auto pb-1">
        <template x-for="photo in photos" :key="photo.id">
            <div class="flex-shrink-0 w-20">
                <button type="button"
                        class="block w-20 h-20 rounded-lg overflow-hidden bg-gray-100 focus-visible:ring-2 focus-visible:ring-[#178A95] focus-visible:outline-none"
                        :aria-label="'Open photo ' + photo.original_name"
                        @click="openLightbox(photo.url)">
                    <img :src="photo.url" :alt="photo.original_name" class="w-20 h-20 object-cover">
                </button>
                <input type="text" x-model="photo.caption" maxlength="200"
                       @blur="saveCaption(photo)"
                       placeholder="Add caption"
                       class="mt-1 block w-20 text-xs bg-transparent border-0 border-b border-gray-200 focus:border-[#178A95] focus:ring-0 p-0 placeholder:text-gray-400">
            </div>
        </template>

        {{-- Dashed camera placeholder --}}
        <label class="flex-shrink-0 w-20 h-20 border-2 border-dashed border-gray-300 rounded-lg
                      flex flex-col items-center justify-center bg-gray-50 cursor-pointer
                      hover:bg-gray-100 focus-within:ring-2 focus-within:ring-[#178A95]"
               aria-label="Add photo to {{ $task->title }}">
            <svg x-show="!uploading" xmlns="http://www.w3.org/2000/svg" fill="none"
                 viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"
                 class="w-6 h-6 text-gray-500">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.822 1.316Z" />
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0ZM18.75 10.5h.008v.008h-.008V10.5Z" />
            </svg>
            <svg x-show="uploading" x-cloak class="w-6 h-6 text-gray-500 animate-spin"
                 xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
            <span x-show="!uploading" class="text-xs text-gray-500 mt-1">Add photo</span>
            <input type="file"
                   accept="image/*,image/heic,image/heif"
                   class="sr-only"
                   @change="upload($event.target)">
        </label>
    </div>

    {{-- Native <dialog> lightbox — no library required --}}
    <dialog x-ref="lightbox"
            class="backdrop:bg-black/60 p-0 rounded-xl max-w-[95vw] max-h-[90vh]"
            @close="lightboxUrl = null">
        <div class="relative">
            <button type="button" @click="closeLightbox()"
                    class="absolute top-2 right-2 w-11 h-11 flex items-center justify-center
                           bg-white/90 rounded-full shadow focus-visible:ring-2 focus-visible:ring-[#178A95] focus-visible:outline-none"
                    aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                     stroke-width="1.5" stroke="currentColor" class="w-6 h-6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                </svg>
            </button>
            <img :src="lightboxUrl" class="max-w-full max-h-[80vh] block" alt="">
            <div class="flex justify-center p-3 bg-white">
                <button type="button"
                        @click="deletePhoto(photos.find(p => p.url === lightboxUrl))"
                        class="h-11 px-4 text-sm text-red-600 hover:bg-red-50 rounded-lg focus-visible:ring-2 focus-visible:ring-red-400 focus-visible:outline-none">
                    Delete photo
                </button>
            </div>
        </div>
    </dialog>
</div>
