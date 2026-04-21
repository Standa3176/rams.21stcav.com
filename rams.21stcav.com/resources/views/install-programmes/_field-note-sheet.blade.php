{{--
    Bottom-sheet for optional clock-out note (D-06).

    Controlled by root Alpine's `noteSheet` state:
      noteSheet.open    bool
      noteSheet.note    string         (max 500 chars — frontend enforces, server validates)
      noteSheet.saving  bool
      noteSheet.error   string | null

    On "Skip" tap -> submitNote(null) fires the stop with no note.
    On "Save & clock out" -> submitNote(this.noteSheet.note).
    Either path hits POST /projects/{project}/time-entries/stop and resolves the
    chip back to "Clock in" state.
--}}
<template x-if="noteSheet.open">
    <div class="fixed inset-0 z-40" x-cloak>
        <div class="absolute inset-0 bg-black/40"
             x-transition:enter="motion-safe:transition-opacity motion-safe:duration-200"
             x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
             x-transition:leave="motion-safe:transition-opacity motion-safe:duration-200"
             x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
             @click="dismissNoteSheet()"></div>

        <section role="dialog" aria-modal="true" aria-labelledby="note-sheet-title"
                 class="absolute inset-x-0 bottom-0 bg-white rounded-t-2xl shadow-2xl
                        p-6 max-h-[75vh] overflow-y-auto pb-[env(safe-area-inset-bottom)]"
                 x-transition:enter="motion-safe:transition motion-safe:ease-out motion-safe:duration-[250ms]"
                 x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0"
                 x-transition:leave="motion-safe:transition motion-safe:ease-in motion-safe:duration-200"
                 x-transition:leave-start="translate-y-0" x-transition:leave-end="translate-y-full"
                 @keydown.escape.window="dismissNoteSheet()">

            <div class="flex items-start justify-between mb-3">
                <h2 id="note-sheet-title" class="text-lg font-semibold text-gray-800">
                    Add a note?
                </h2>
                <button type="button" @click="dismissNoteSheet()"
                        class="w-11 h-11 flex items-center justify-center text-gray-500 hover:text-gray-700
                               focus-visible:ring-2 focus-visible:ring-[#178A95] focus-visible:outline-none rounded"
                        aria-label="Close">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                         stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <p class="text-sm text-gray-600 mb-4">
                Optional — add context for this session, or skip to clock out straight away.
            </p>

            <textarea x-model="noteSheet.note"
                      x-init="$nextTick(() => $el.focus())"
                      placeholder="What did you do this session? (optional)"
                      rows="4" maxlength="500"
                      class="w-full rounded-lg border-gray-300 focus:border-[#178A95] focus:ring-[#178A95]
                             text-sm leading-normal"></textarea>

            <p class="text-xs text-gray-400 mt-1">
                <span x-text="noteSheet.note.length"></span>/500
            </p>

            <p x-show="noteSheet.error" x-text="noteSheet.error" x-cloak
               class="text-red-600 text-sm mt-2" role="alert"></p>

            <div class="flex gap-3 mt-4">
                <button type="button" @click="submitNote(null)"
                        :disabled="noteSheet.saving"
                        class="flex-1 h-11 rounded-lg bg-gray-100 text-gray-700 text-sm font-semibold
                               hover:bg-gray-200 disabled:opacity-50 disabled:pointer-events-none
                               focus-visible:ring-2 focus-visible:ring-[#178A95] focus-visible:outline-none">
                    Skip
                </button>
                <button type="button" @click="submitNote(noteSheet.note)"
                        :disabled="noteSheet.saving"
                        class="flex-1 h-11 rounded-lg bg-[#178A95] text-white text-sm font-semibold
                               disabled:opacity-50 disabled:pointer-events-none
                               focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-[#178A95] focus-visible:outline-none">
                    <span x-show="!noteSheet.saving">Save &amp; clock out</span>
                    <span x-show="noteSheet.saving" x-cloak>Clocking out&hellip;</span>
                </button>
            </div>
        </section>
    </div>
</template>
