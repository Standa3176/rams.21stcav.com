{{-- Phase 18 Plan 01 — unified "+ Create Drawing" picker.

     Mirrors _regenerate-confirm-modal.blade.php Alpine pattern. Three kind
     cards stacked vertically:
       1. Signal Flow Schematic — Yes/No auto-generate radio (Phase 17 flow
          preserved; Yes = existing R0 auto-gen, No = blank schematic for
          engineer to build).
       2. Rack Elevation        — single Create button (no auto-gen toggle,
          per CONTEXT.md — engineer always builds the rack manually). Submits
          a 42U empty rack and redirects to the rack show page.
       3. Floor Plan            — DISABLED with "Coming in v2.0" tooltip
          (Phase 19 deferred from v1.3 scope 2026-05-02). POSTs are rejected
          server-side via the picker action's match expression with a
          session 'kind' validation error.

     Triggered via `$dispatch('open-create-drawing')` from the index page.
     CSRF tokens on every form (T-18.01-04). Blade-escaped output only
     (T-18.01-05). --}}
<div x-data="{ open: false }"
     @open-create-drawing.window="open = true"
     @keydown.escape.window="open = false">
    <template x-if="open">
        <div class="fixed inset-0 bg-black/50 flex items-center justify-center z-50"
             @click.self="open = false"
             role="dialog"
             aria-modal="true"
             aria-labelledby="create-drawing-title">
            <div class="bg-white rounded-lg shadow-xl p-6 max-w-2xl w-full mx-4">
                <h3 id="create-drawing-title" class="font-semibold text-lg text-gray-900 mb-4">
                    Create Drawing
                </h3>

                {{-- Signal Flow card (Phase 17 flow preserved — auto-gen Yes/No) --}}
                <form method="POST"
                      action="{{ route('projects.drawings.picker', $project) }}"
                      class="border border-gray-200 rounded-lg p-4 mb-3 hover:border-accent-600 transition">
                    @csrf
                    <input type="hidden" name="kind" value="schematic">
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex-1">
                            <div class="font-medium text-gray-900">Signal Flow Schematic</div>
                            <p class="text-sm text-gray-500 mt-1">
                                Per-room signal-flow diagram with cable IDs, port labels, and AVIXA-style symbols.
                            </p>
                            <label class="inline-flex items-center mt-3 text-sm text-gray-700">
                                <input type="radio" name="auto_generate" value="yes" checked
                                       class="mr-2">
                                Auto-generate from project data
                            </label>
                            <label class="inline-flex items-center ml-4 mt-3 text-sm text-gray-700">
                                <input type="radio" name="auto_generate" value="no"
                                       class="mr-2">
                                Start blank
                            </label>
                        </div>
                        <button type="submit"
                                class="bg-accent-600 hover:bg-accent-700 text-white px-4 py-2 rounded-md text-sm font-medium">
                            Create
                        </button>
                    </div>
                </form>

                {{-- Rack Elevation card — NO auto-gen toggle.
                     CONTEXT.md "engineer always builds the rack manually". --}}
                <form method="POST"
                      action="{{ route('projects.drawings.picker', $project) }}"
                      class="border border-gray-200 rounded-lg p-4 mb-3 hover:border-accent-600 transition">
                    @csrf
                    <input type="hidden" name="kind" value="rack">
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex-1">
                            <div class="font-medium text-gray-900">Rack Elevation</div>
                            <p class="text-sm text-gray-500 mt-1">
                                1U-precise rack drawing with U-numbered rail. You'll drag equipment from a palette into U-slots.
                            </p>
                            <p class="text-xs text-gray-400 mt-2">
                                A 42U rack will be created — you can adjust the height in the editor.
                            </p>
                        </div>
                        <button type="submit"
                                class="bg-accent-600 hover:bg-accent-700 text-white px-4 py-2 rounded-md text-sm font-medium">
                            Create
                        </button>
                    </div>
                </form>

                {{-- Floor Plan card — DISABLED, deferred to v2.0.
                     Per CONTEXT.md decision 2026-05-02, Phase 19 was dropped
                     from v1.3 scope; the engineering-grade renderer in v2.0
                     will rebuild floor plans properly with port catalog. --}}
                <div class="border border-gray-200 rounded-lg p-4 mb-3 opacity-50 cursor-not-allowed"
                     title="Coming in v2.0">
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex-1">
                            <div class="font-medium text-gray-700">Floor Plan / Elevation</div>
                            <p class="text-sm text-gray-500 mt-1">
                                In-browser canvas drawing tool with walls, doors, windows, and equipment glyphs.
                            </p>
                        </div>
                        <span class="bg-gray-200 text-gray-500 px-3 py-2 rounded-md text-xs font-medium whitespace-nowrap">
                            Coming in v2.0
                        </span>
                    </div>
                </div>

                @if ($errors->any())
                    <div class="mt-3 bg-red-50 border border-red-200 text-red-800 p-3 rounded text-sm">
                        {{ $errors->first() }}
                    </div>
                @endif

                <div class="mt-4 flex justify-end">
                    <button type="button"
                            @click="open = false"
                            class="px-4 py-2 rounded border border-gray-300 text-sm text-gray-700 hover:bg-gray-50">
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    </template>
</div>
