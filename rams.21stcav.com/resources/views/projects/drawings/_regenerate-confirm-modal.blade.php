{{-- Phase 17 Plan 03 — regenerate confirm modal (DRAW-05 lock-on-edit prompt).

     Functional schematic editor lands in Phase 19; this modal is the UX
     scaffolding so users see the lock-on-edit warning today. Triggered via
     `$dispatch('open-regenerate-confirm', { id, hasUserEdits })` from the
     index/show pages — does NOT require a server-rendered $drawing in scope.

     The form action is built dynamically in JavaScript using the dispatched
     drawingId so the same modal serves every drawing on the index page. --}}
<div x-data="{ open: false, drawingId: null, hasUserEdits: false }"
     @open-regenerate-confirm.window="open = true; drawingId = $event.detail.id; hasUserEdits = $event.detail.hasUserEdits"
     @keydown.escape.window="open = false">
    <template x-if="open">
        <div class="fixed inset-0 bg-black/50 flex items-center justify-center z-50"
             @click.self="open = false"
             role="dialog"
             aria-modal="true">
            <div class="bg-white rounded-lg shadow-xl p-6 max-w-md w-full mx-4">
                <h3 class="font-semibold text-lg text-gray-900">Regenerate drawing</h3>

                <template x-if="hasUserEdits">
                    <p class="mt-2 text-sm text-red-700">
                        This drawing has manual edits saved. Regenerating will archive the current revision and create a new one from canonical project data — your edits will be preserved on the archived revision but not carried forward.
                    </p>
                </template>
                <template x-if="!hasUserEdits">
                    <p class="mt-2 text-sm text-gray-700">
                        Regenerate from project data? This will archive the current revision as a prior version. You can still access archived revisions via the version history.
                    </p>
                </template>

                <div class="mt-5 flex gap-2 justify-end">
                    <button type="button"
                            @click="open = false"
                            class="px-3 py-1.5 rounded border border-gray-300 text-sm text-gray-700 hover:bg-gray-50">
                        Cancel
                    </button>
                    <form :action="`{{ url('projects/'.$project->id.'/drawings') }}/${drawingId}/regenerate`"
                          method="POST"
                          class="inline">
                        @csrf
                        <button type="submit"
                                class="px-3 py-1.5 rounded bg-accent-600 text-white text-sm font-medium hover:bg-accent-700">
                            Yes, regenerate
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </template>
</div>
