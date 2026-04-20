{{--
    Bottom-sheet for blocked/skipped reason entry (D-06).

    Exactly one instance at page root; controlled by root Alpine's `sheet` state:
      sheet.open     bool
      sheet.mode     'blocked' | 'skipped'
      sheet.taskId   int
      sheet.reason   string
      sheet.saving   bool
      sheet.error    string|null

    UI-SPEC:
      - translate-y-full → translate-y-0 enter 250ms ease-out
      - translate-y-0 → translate-y-full leave 200ms ease-in
      - max-h-[75vh] overflow-y-auto
      - rounded-t-2xl shadow-2xl surface
      - Save reason button disabled while textarea empty-trimmed
      - motion-safe: prefix so reduced-motion users get an instant change
--}}
<template x-if="sheet.open">
    <div class="fixed inset-0 z-40" x-cloak>
        {{-- Backdrop --}}
        <div class="absolute inset-0 bg-black/40"
             x-transition:enter="motion-safe:transition-opacity motion-safe:duration-200"
             x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
             x-transition:leave="motion-safe:transition-opacity motion-safe:duration-200"
             x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
             @click="dismissSheet()"></div>

        {{-- Sheet --}}
        <section role="dialog" aria-modal="true" aria-labelledby="sheet-title"
                 class="absolute inset-x-0 bottom-0 bg-white rounded-t-2xl shadow-2xl
                        p-6 max-h-[75vh] overflow-y-auto pb-[env(safe-area-inset-bottom)]"
                 x-transition:enter="motion-safe:transition motion-safe:ease-out motion-safe:duration-[250ms]"
                 x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0"
                 x-transition:leave="motion-safe:transition motion-safe:ease-in motion-safe:duration-200"
                 x-transition:leave-start="translate-y-0" x-transition:leave-end="translate-y-full"
                 @keydown.escape.window="dismissSheet()">
            <div class="flex items-start justify-between mb-3">
                <h2 id="sheet-title" class="text-lg font-semibold text-gray-800"
                    x-text="sheet.mode === 'blocked' ? 'Mark blocked' : 'Mark skipped'"></h2>
                <button type="button" @click="dismissSheet()"
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
                Tell the team why — this note will be visible on the schedule page.
            </p>

            <textarea x-model="sheet.reason"
                      x-init="$nextTick(() => $el.focus())"
                      placeholder="Reason (required)"
                      rows="4" maxlength="500"
                      class="w-full rounded-lg border-gray-300 focus:border-[#178A95] focus:ring-[#178A95]
                             text-sm leading-normal"></textarea>

            <p x-show="sheet.error" x-text="sheet.error" x-cloak
               class="text-red-600 text-sm mt-2" role="alert"></p>

            <div class="flex gap-3 mt-4">
                <button type="button" @click="dismissSheet()"
                        class="flex-1 h-11 rounded-lg bg-gray-100 text-gray-700 text-sm font-semibold
                               hover:bg-gray-200 focus-visible:ring-2 focus-visible:ring-[#178A95] focus-visible:outline-none">
                    Cancel
                </button>
                <button type="button" @click="submitSheet()"
                        :disabled="!sheet.reason.trim() || sheet.saving"
                        class="flex-1 h-11 rounded-lg bg-[#178A95] text-white text-sm font-semibold
                               disabled:opacity-50 disabled:pointer-events-none
                               focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-[#178A95] focus-visible:outline-none">
                    <span x-show="!sheet.saving">Save reason</span>
                    <span x-show="sheet.saving" x-cloak>Saving…</span>
                </button>
            </div>
        </section>
    </div>
</template>
