@props(['task', 'isOwnerOrAdmin'])

{{--
    Per-task row partial (D-05, D-06, D-07, D-08).

    Alpine state (fieldTaskRow factory, defined at page root):
      id, status, blockedReason, notes, notesDirty, notesError,
      savedPulse, errorPulse, menuOpen

    Status classes per 14-UI-SPEC.md Status colour contract.
    Dispatches `task-saved` on window so the root listener can refresh
    programme-progress + room counters using the server-returned `counters`
    payload.

    UI-SPEC:
      - data-testid="task-row" (FieldViewResponsivenessTest asserts this)
      - role="button" tabindex="0"
      - Enter/Space → advance()
      - Tap the body → advance() (pending→in_progress→complete; no-op on complete)
      - ⋮ overflow menu: Mark blocked / Mark skipped (bottom-sheet), Reopen (only on complete)
      - Photo strip via <x-install-task.photo-upload>
      - Notes textarea: collapsed single-line, focus expands, auto-grow, blur-save
--}}
<article
    data-testid="task-row"
    data-task-id="{{ $task->id }}"
    data-room="{{ $task->room_name }}"
    x-data="fieldTaskRow({
        id: {{ $task->id }},
        status: '{{ $task->status }}',
        blockedReason: @js($task->blocked_reason),
        notes: @js($task->notes ?? ''),
    })"
    role="button"
    tabindex="0"
    :aria-label="statusLabel() + ' — ' + @js($task->title)"
    @click="advance()"
    @keydown.enter.prevent="advance()"
    @keydown.space.prevent="advance()"
    :class="rowClasses()"
    class="relative rounded-xl border p-3 pr-14 mb-2 motion-safe:transition-all focus-visible:ring-2 focus-visible:ring-[#178A95] focus-visible:outline-none"
>
    <div class="flex items-start gap-3">
        {{-- Status icon (UI-SPEC Icon Map) --}}
        <span class="shrink-0 w-5 h-5 mt-0.5" :class="iconColor()" aria-hidden="true">
            <template x-if="status === 'pending'">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
                    <circle cx="12" cy="12" r="9"/>
                </svg>
            </template>
            <template x-if="status === 'in_progress'">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm.75-13a.75.75 0 0 0-1.5 0v5c0 .2.08.39.22.53l3 3a.75.75 0 0 0 1.06-1.06l-2.78-2.78V5Z" clip-rule="evenodd"/>
                </svg>
            </template>
            <template x-if="status === 'complete'">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.86-9.47a.75.75 0 0 0-1.22-.87l-3.24 4.53L7.7 10.3a.75.75 0 0 0-1.06 1.07l2.25 2.25a.75.75 0 0 0 1.14-.09l3.83-5.5Z" clip-rule="evenodd"/>
                </svg>
            </template>
            <template x-if="status === 'blocked'">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5">
                    <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495ZM10 5a.75.75 0 0 1 .75.75v4a.75.75 0 0 1-1.5 0v-4A.75.75 0 0 1 10 5Zm0 9a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd"/>
                </svg>
            </template>
            <template x-if="status === 'skipped'">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16ZM6.75 9.25a.75.75 0 0 0 0 1.5h6.5a.75.75 0 0 0 0-1.5h-6.5Z" clip-rule="evenodd"/>
                </svg>
            </template>
        </span>

        <div class="flex-1 min-w-0">
            <p class="text-sm font-normal text-gray-800 truncate">{{ $task->title }}</p>
            <p class="text-xs text-gray-500 truncate">{{ $task->quantity ?? 1 }} × {{ $task->equipment_name }}</p>
            @if ($isOwnerOrAdmin && $task->assignedUser)
                <p class="text-xs text-gray-400">Assigned: {{ $task->assignedUser->name }}</p>
            @endif
            <p x-show="(status === 'blocked' || status === 'skipped') && blockedReason" x-cloak
               class="text-xs italic text-gray-600 mt-1" x-text="blockedReason"></p>
        </div>
    </div>

    {{-- Overflow (⋮) button + menu.  @click.stop prevents bubbling to the row advance() handler. --}}
    <div class="absolute right-1 top-1" @click.stop @keydown.stop>
        <button type="button"
                class="w-11 h-11 flex items-center justify-center text-gray-500 hover:text-gray-800
                       focus-visible:ring-2 focus-visible:ring-[#178A95] focus-visible:outline-none rounded"
                :aria-label="'More actions for ' + @js($task->title)"
                aria-haspopup="menu"
                :aria-expanded="menuOpen.toString()"
                @click="menuOpen = !menuOpen">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                 stroke="currentColor" class="w-5 h-5">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M12 6.75a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5ZM12 12.75a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5ZM12 18.75a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5Z" />
            </svg>
        </button>

        <div x-show="menuOpen" x-cloak @click.outside="menuOpen = false"
             class="absolute right-0 top-12 w-48 bg-white rounded-lg shadow-md ring-1 ring-black/5
                    divide-y divide-gray-100 z-20"
             role="menu">
            <button type="button" role="menuitem"
                    class="w-full text-left px-4 py-3 text-sm hover:bg-gray-50 focus-visible:bg-gray-50 focus-visible:outline-none"
                    @click="openSheet('blocked'); menuOpen = false">Mark blocked</button>
            <button type="button" role="menuitem"
                    class="w-full text-left px-4 py-3 text-sm hover:bg-gray-50 focus-visible:bg-gray-50 focus-visible:outline-none"
                    @click="openSheet('skipped'); menuOpen = false">Mark skipped</button>
            <button type="button" role="menuitem" x-show="status === 'complete'"
                    class="w-full text-left px-4 py-3 text-sm hover:bg-gray-50 focus-visible:bg-gray-50 focus-visible:outline-none"
                    @click="reopen(); menuOpen = false">Reopen task</button>
        </div>
    </div>

    {{-- Photo strip (Blade component from Task 1).  @click.stop so tapping a thumbnail
         or the caption input doesn't fire the row's advance() handler. --}}
    <div @click.stop @keydown.stop>
        <x-install-task.photo-upload :task="$task" />
    </div>

    {{-- Notes textarea — collapsed single-line → focus expands + autogrow + blur saves --}}
    <div class="mt-2" @click.stop @keydown.stop>
        <label class="sr-only" for="notes-{{ $task->id }}">Notes for {{ $task->title }}</label>
        <textarea id="notes-{{ $task->id }}" x-model="notes" rows="1" maxlength="5000"
                  @focus="$el.style.height = 'auto'; $el.style.height = $el.scrollHeight + 'px'"
                  @input="notesDirty = true; $el.style.height = 'auto'; $el.style.height = $el.scrollHeight + 'px'"
                  @blur="saveNotes()"
                  placeholder="Add a note…"
                  class="block w-full text-sm leading-normal rounded-lg border-gray-200
                         focus:border-[#178A95] focus:ring-[#178A95] resize-none max-h-[200px]"></textarea>
        <p x-show="notesError" x-text="notesError" x-cloak
           class="text-red-600 text-xs mt-1" role="alert"></p>
    </div>
</article>
