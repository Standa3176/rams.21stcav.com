{{-- Fail-reason bottom sheet (D-14 / W-12 atomic fail endpoint).

     Rendered once on the page; visibility driven by failSheet.open. When the
     engineer taps Fail on an item row, patchStatus() opens this sheet rather
     than firing the PATCH immediately — photo + note are then posted together
     to /commissioning-items/{id}/fail-with-evidence in a single DB::transaction
     so an interrupted flow never leaves an orphan photo on a still-pending item. --}}
<div x-show="failSheet.open"
     x-cloak
     @keydown.escape.window="failSheet.open = false"
     class="fail-sheet"
     x-transition.opacity>

    {{-- Backdrop --}}
    <div @click="failSheet.open = false" class="fail-sheet__backdrop"></div>

    {{-- Sheet --}}
    <div class="fail-sheet__panel"
         x-transition:enter="fail-sheet__panel--entering"
         x-transition:enter-start="fail-sheet__panel--enter-start"
         x-transition:enter-end="fail-sheet__panel--enter-end">

        <div class="fail-sheet__header">
            <h3 class="fail-sheet__title">Mark as Fail — evidence required</h3>
            <button type="button" @click="failSheet.open = false" class="fail-sheet__close" aria-label="Close">&times;</button>
        </div>

        <p class="fail-sheet__hint">
            A fail must include a note explaining the issue and a photo of the problem (D-14).
        </p>

        <div class="fail-sheet__field">
            <label class="fail-sheet__label">Note</label>
            <textarea x-model="failSheet.note"
                      class="fail-sheet__textarea"
                      rows="3"
                      maxlength="2000"
                      placeholder="Describe what failed and why..."></textarea>
        </div>

        <div class="fail-sheet__field">
            <label class="fail-sheet__label">Evidence photo</label>
            <input type="file"
                   accept="image/*"
                   @change="failSheet.photoFile = $event.target.files[0]"
                   class="fail-sheet__file">
            <p class="fail-sheet__help">HEIC from iPhone is converted to JPEG automatically.</p>
        </div>

        <template x-if="failSheet.error">
            <p class="fail-sheet__error" x-text="failSheet.error"></p>
        </template>

        <div class="fail-sheet__actions">
            <button type="button"
                    @click="failSheet.open = false"
                    class="btn btn-outline">Cancel</button>
            <button type="button"
                    @click="confirmFailWithNoteAndPhoto()"
                    :disabled="failSheet.uploading || ! failSheet.note.trim() || ! failSheet.photoFile"
                    class="btn btn-danger">
                <span x-show="! failSheet.uploading">Save fail</span>
                <span x-show="failSheet.uploading">Saving…</span>
            </button>
        </div>
    </div>
</div>
