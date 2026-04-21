{{--
    Bottom-sheet for category selection on clock-in (D-01, D-02).

    Controlled by root Alpine's `categorySheet` state:
      categorySheet.open           bool
      categorySheet.lastUsed       'installation' | 'commissioning' | 'testing' | 'other' | null
      categorySheet.saving         bool
      categorySheet.error          string | null

    Entry flow: user taps clock-in chip when not clocked in -> openCategorySheet()
    sets state, reads localStorage('last-category-' + user_id). Pills render
    with the last-used category pre-highlighted but NO submit happens until
    the user taps a pill (D-02 still requires explicit tap).

    On pill tap -> submitCategory(cat) posts to /projects/{project}/time-entries/start
    with body { category: cat }, then writes localStorage and closes the sheet.
--}}
<template x-if="categorySheet.open">
    <div class="fixed inset-0 z-40" x-cloak>
        <div class="absolute inset-0 bg-black/40"
             x-transition:enter="motion-safe:transition-opacity motion-safe:duration-200"
             x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
             x-transition:leave="motion-safe:transition-opacity motion-safe:duration-200"
             x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
             @click="dismissCategorySheet()"></div>

        <section role="dialog" aria-modal="true" aria-labelledby="category-sheet-title"
                 class="absolute inset-x-0 bottom-0 bg-white rounded-t-2xl shadow-2xl
                        p-6 max-h-[75vh] overflow-y-auto pb-[env(safe-area-inset-bottom)]"
                 x-transition:enter="motion-safe:transition motion-safe:ease-out motion-safe:duration-[250ms]"
                 x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0"
                 x-transition:leave="motion-safe:transition motion-safe:ease-in motion-safe:duration-200"
                 x-transition:leave-start="translate-y-0" x-transition:leave-end="translate-y-full"
                 @keydown.escape.window="dismissCategorySheet()">

            <div class="flex items-start justify-between mb-3">
                <h2 id="category-sheet-title" class="text-lg font-semibold text-gray-800">
                    What are you working on?
                </h2>
                <button type="button" @click="dismissCategorySheet()"
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
                Tap to start your shift. You can change this later by editing the entry.
            </p>

            <div class="grid grid-cols-2 gap-3" data-testid="category-pills">
                {{-- IN-02: pull category value→label map from TimeEntry::CATEGORY_LABELS
                     (single source of truth — see app/Models/TimeEntry.php). --}}
                @foreach (\App\Models\TimeEntry::CATEGORY_LABELS as $value => $label)
                    <button type="button"
                            @click="submitCategory('{{ $value }}')"
                            :disabled="categorySheet.saving"
                            :class="categorySheet.lastUsed === '{{ $value }}'
                                ? 'bg-[#178A95] text-white ring-2 ring-[#178A95] ring-offset-2'
                                : 'bg-white text-[#0B3C45] border border-gray-300 hover:bg-gray-50'"
                            class="h-14 rounded-xl text-base font-semibold
                                   disabled:opacity-50 disabled:pointer-events-none
                                   focus-visible:ring-2 focus-visible:ring-[#178A95] focus-visible:outline-none
                                   transition-colors"
                            data-category="{{ $value }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            <p x-show="categorySheet.error" x-text="categorySheet.error" x-cloak
               class="text-red-600 text-sm mt-3" role="alert"></p>

            <p x-show="categorySheet.saving" x-cloak class="text-gray-500 text-sm mt-3">
                Clocking in&hellip;
            </p>
        </section>
    </div>
</template>
