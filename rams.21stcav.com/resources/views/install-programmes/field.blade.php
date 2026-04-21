@extends('layouts.app')

@section('title', 'Field — ' . $project->name . ' | RAMS')

@push('styles')
    {{-- Phase 14 requires the Tailwind utility CSS + Alpine.js bundle.
         layouts/app.blade.php uses inline design-token CSS by default and does
         NOT include @vite; we opt in here via the styles stack so this page
         gets the mobile-first utility layer declared by 14-UI-SPEC.md while
         other authenticated pages keep the existing chrome unchanged. --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
@endpush

@section('content')
<div
    x-data="fieldRoot({
        projectId: {{ $project->id }},
        openEntry: @js($openEntry ? ['id' => $openEntry->id, 'clocked_in_at' => $openEntry->clocked_in_at->toIso8601String()] : null),
        progCounter: @js($counters['programme']),
    })"
    x-init="init()"
    data-user-id="{{ auth()->id() }}"
    class="pt-[env(safe-area-inset-top)] pb-24 md:pb-6 bg-[#F3F6F7] min-h-screen -mx-8 -my-8 sm:-mx-4"
>
    {{-- ══════════════════════════════════════════════════════════════════════
         STICKY BAR (D-03) — h-14, bg-[#0B3C45], project name + clock chip
         ══════════════════════════════════════════════════════════════════ --}}
    <header class="sticky top-0 z-30 h-14 bg-[#0B3C45] text-white flex items-center
                   justify-between px-4 gap-3 shadow-sm">
        <a href="{{ route('projects.show', $project->id) }}"
           class="w-11 h-11 flex items-center justify-center -ml-2 text-white/80 hover:text-white
                  focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none rounded"
           aria-label="Back to project">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                 stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
            </svg>
        </a>

        <div class="min-w-0 flex-1">
            <p class="text-lg font-semibold leading-tight truncate">{{ $project->name }}</p>
            <p class="text-xs text-white/70 truncate">{{ $project->ref ?? $project->client_name ?? '' }}</p>
        </div>

        {{-- Clock-in chip (D-03).  aria-live="polite" on the time span so the
             elapsed H:MM gets a polite update without shouting every minute. --}}
        <button type="button"
                @click="toggleClock()"
                :disabled="clock.saving"
                :class="clockChipClasses()"
                :aria-label="clockAriaLabel()"
                class="h-11 px-3 rounded-full text-sm font-semibold flex items-center gap-2
                       transition-colors focus-visible:ring-2 focus-visible:ring-white
                       focus-visible:ring-offset-2 focus-visible:ring-offset-[#0B3C45]
                       focus-visible:outline-none">
            <span x-show="!clock.openEntry && !clock.saving && !clock.error">
                <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 9.75 15 12l-5.25 2.25V9.75Z" />
                </svg>
            </span>
            <span x-show="clock.openEntry && !clock.saving" x-cloak>
                <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M4.5 5.5A1.5 1.5 0 0 1 6 4h8a1.5 1.5 0 0 1 1.5 1.5v9A1.5 1.5 0 0 1 14 16H6a1.5 1.5 0 0 1-1.5-1.5v-9Z" clip-rule="evenodd" />
                </svg>
            </span>
            <span x-show="clock.saving" x-cloak class="animate-spin">
                <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                </svg>
            </span>
            <span class="hidden sm:inline">
                <span x-show="!clock.openEntry && !clock.saving && !clock.error">Clock in</span>
                <span x-show="clock.openEntry && !clock.saving" x-cloak aria-live="polite">
                    On the clock · <span x-text="clock.elapsed"></span>
                </span>
                <span x-show="clock.saving" x-cloak>Clocking…</span>
                <span x-show="clock.error" x-cloak>Try again</span>
            </span>
        </button>
    </header>

    {{-- Clock-in error inline below sticky bar --}}
    <div x-show="clock.error" x-cloak class="bg-red-50 text-red-700 text-sm px-4 py-2"
         role="alert" x-text="clock.error"></div>

    {{-- ══════════════════════════════════════════════════════════════════════
         PROGRAMME PROGRESS (D-04)
         ══════════════════════════════════════════════════════════════════ --}}
    <section class="px-4 py-4 bg-white border-b border-gray-200">
        <p class="text-base font-semibold text-gray-800 mb-2">Programme progress</p>
        @php
            $progTotal = max(1, $counters['programme']['total']);
            $progDone = $counters['programme']['complete'];
            $progPct = (int) round(($progDone / $progTotal) * 100);
            $progComplete = $counters['programme']['total'] > 0 && $progDone === $counters['programme']['total'];
        @endphp
        <div class="h-2 w-full bg-gray-200 rounded-full overflow-hidden" aria-hidden="true">
            <div class="h-full {{ $progComplete ? 'bg-green-600' : 'bg-[#178A95]' }}
                        motion-safe:transition-[width] motion-safe:duration-300"
                 style="width: {{ $progPct }}%"
                 data-testid="programme-progress-bar"></div>
        </div>
        <p class="text-xs text-gray-500 mt-2" aria-live="polite" data-testid="programme-progress-text">
            @if ($counters['programme']['total'] === 0)
                Programme not generated yet
            @elseif ($progComplete)
                All tasks complete · ready for commissioning
            @else
                {{ $progDone }} of {{ $counters['programme']['total'] }} tasks complete
            @endif
        </p>
    </section>

    {{-- ══════════════════════════════════════════════════════════════════════
         SCOPE TOGGLE (D-02) — engineers only
         ══════════════════════════════════════════════════════════════════ --}}
    @if (! $isOwnerOrAdmin)
        <section class="px-4 py-3 bg-white border-b border-gray-200">
            <div class="inline-flex rounded-full bg-gray-100 p-1" role="tablist" aria-label="Task scope">
                <a href="{{ route('install-programmes.field', ['project' => $project->id, 'scope' => 'mine']) }}"
                   role="tab" aria-selected="{{ $scope === 'mine' ? 'true' : 'false' }}"
                   class="h-9 px-4 rounded-full text-sm font-semibold flex items-center
                          {{ $scope === 'mine' ? 'bg-white shadow-sm text-[#0B3C45]' : 'text-gray-500' }}">
                    My tasks
                </a>
                <a href="{{ route('install-programmes.field', ['project' => $project->id, 'scope' => 'all']) }}"
                   role="tab" aria-selected="{{ $scope === 'all' ? 'true' : 'false' }}"
                   class="h-9 px-4 rounded-full text-sm font-semibold flex items-center
                          {{ $scope === 'all' ? 'bg-white shadow-sm text-[#0B3C45]' : 'text-gray-500' }}">
                    Show all
                </a>
            </div>
        </section>
    @endif

    {{-- ══════════════════════════════════════════════════════════════════════
         TASK LIST (grouped by room) — D-01
         ══════════════════════════════════════════════════════════════════ --}}
    <div class="px-4 py-4">
        @if ($programme === null || $counters['programme']['total'] === 0)
            {{-- Empty state: programme not generated --}}
            <div class="bg-white rounded-xl p-8 text-center" data-testid="empty-state-programme">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                     stroke-width="1.5" stroke="currentColor" class="w-12 h-12 mx-auto text-gray-300 mb-3">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25ZM6.75 12h.008v.008H6.75V12Zm0 3h.008v.008H6.75V15Zm0 3h.008v.008H6.75V18Z" />
                </svg>
                <h2 class="text-base font-semibold text-gray-800 mb-1">Programme not generated yet</h2>
                <p class="text-sm text-gray-500">Ask your PM to generate the task list from the project page.</p>
            </div>
        @elseif ($rooms->isEmpty())
            {{-- Empty state: engineer with no assigned tasks --}}
            <div class="bg-white rounded-xl p-8 text-center" data-testid="empty-state-engineer">
                <h2 class="text-base font-semibold text-gray-800 mb-1">No tasks assigned to you yet</h2>
                <p class="text-sm text-gray-500 mb-4">Your PM hasn't allocated any tasks on this project.</p>
                @if (! $isOwnerOrAdmin)
                    <a href="{{ route('install-programmes.field', ['project' => $project->id, 'scope' => 'all']) }}"
                       class="text-[#178A95] font-semibold text-sm hover:underline">
                        Show all programme tasks
                    </a>
                @endif
            </div>
        @else
            @foreach ($rooms as $roomName => $roomTasks)
                @include('install-programmes._field-room', [
                    'roomName' => $roomName,
                    'tasks' => $roomTasks,
                    'counter' => $counters['room'][$roomName] ?? ['complete' => 0, 'total' => $roomTasks->count()],
                    'isOwnerOrAdmin' => $isOwnerOrAdmin,
                ])
            @endforeach
        @endif
    </div>

    {{-- Bottom-sheet (blocked/skipped reason) — single instance --}}
    @include('install-programmes._field-sheet')

    {{-- Category sheet (opens on clock-in; Phase 15 D-01) --}}
    @include('install-programmes._field-category-sheet')

    {{-- Note sheet (opens on clock-out; Phase 15 D-06, optional) --}}
    @include('install-programmes._field-note-sheet')
</div>

{{-- ══════════════════════════════════════════════════════════════════════════
     ALPINE FACTORIES — inline so we stay in the Blade-per-page convention.
     (Moving these to app.js would work but is a bigger change to the bundler.)
     ══════════════════════════════════════════════════════════════════════ --}}
@push('scripts')
<script>
    function csrf() { return document.querySelector('meta[name="csrf-token"]').content; }

    function fieldRoot({ projectId, openEntry, progCounter }) {
        return {
            clock: {
                openEntry: openEntry,   // { id, clocked_in_at } | null
                saving: false,
                error: null,
                elapsed: '0:00',
                _tickHandle: null,
            },
            sheet: {
                open: false,
                mode: 'blocked',        // 'blocked' | 'skipped'
                taskId: null,
                reason: '',
                saving: false,
                error: null,
            },
            categorySheet: {
                open: false,
                lastUsed: null,          // 'installation' | 'commissioning' | 'testing' | 'other' | null
                saving: false,
                error: null,
            },
            noteSheet: {
                open: false,
                note: '',
                saving: false,
                error: null,
            },
            heartbeat: {
                handle: null,            // setTimeout id (NOT setInterval — so we can back off)
                delayMs: 60000,          // current retry delay; resets to 60000 on success
                consecutiveFailures: 0,  // exponential-retry counter
                currentEntryId: null,    // the entry we're heartbeating
                visibilityPaused: false, // true while document.hidden === true
            },
            lastRoomName: null,
            init() {
                // Pre-load last-used category per D-02 (localStorage keyed by user_id).
                // user_id is rendered server-side via a data attribute on the container;
                // fallback to 'anon' if absent so we don't crash on edge cases.
                const userId = document.querySelector('[data-user-id]')?.dataset.userId ?? 'anon';
                this.categorySheet.lastUsed = localStorage.getItem('last-category-' + userId);

                if (this.clock.openEntry) {
                    this.startClockTicker();
                    this.heartbeat.currentEntryId = this.clock.openEntry.id;
                    this.startHeartbeat();
                }

                // Task rows dispatch these (Phase 14 mechanism preserved verbatim)
                window.addEventListener('task-saved', (e) => {
                    if (e.detail?.room_name) { this.lastRoomName = e.detail.room_name; }
                    this.applyCounters(e.detail?.counters);
                });
                window.addEventListener('open-blocked-sheet', (e) => {
                    this.openSheet(e.detail.mode, e.detail.taskId, e.detail.roomName);
                });

                // Phase 15 D-10: pause heartbeat when tab hidden; resume + catch-up on re-show.
                document.addEventListener('visibilitychange', () => {
                    if (document.hidden) {
                        this.heartbeat.visibilityPaused = true;
                        this.stopHeartbeat();
                    } else {
                        this.heartbeat.visibilityPaused = false;
                        if (this.clock.openEntry) {
                            this.sendHeartbeat();   // catch-up ping immediately
                            this.startHeartbeat();
                        }
                    }
                });
            },
            // ── Clock chip (Phase 15) ──
            // Clock-in path: opens the category sheet (D-01) — actual POST happens in submitCategory().
            // Clock-out path: opens the note sheet (D-06) — actual POST happens in submitNote().
            async toggleClock() {
                this.clock.error = null;
                if (this.clock.openEntry) {
                    // Clock out path: open the note sheet rather than firing POST immediately
                    this.openNoteSheet();
                } else {
                    // Clock in path: open the category sheet
                    this.openCategorySheet();
                }
            },
            startClockTicker() {
                this.tickClock();
                this.clock._tickHandle = setInterval(() => this.tickClock(), 30000);
            },
            stopClockTicker() {
                if (this.clock._tickHandle) { clearInterval(this.clock._tickHandle); this.clock._tickHandle = null; }
            },
            // Phase 15 D-19 note: this renders a DURATION (not a wall-clock time) —
            // elapsed minutes since clocked_in_at. Duration math is timezone-invariant,
            // so no Carbon::setTimezone / Europe/London conversion is needed at this
            // presentation layer. D-19's UTC-storage requirement is satisfied by
            // Laravel persisting clocked_in_at in UTC; the subtraction (Date.now() -
            // since.getTime()) produces a timezone-agnostic millisecond delta.
            //
            // If a future Phase wants to display clocked_in_at as a wall-clock time
            // (e.g. "Started at 09:15 BST"), THAT is where a Europe/London conversion
            // must happen — not here. Deferred, not in Phase 15 scope.
            tickClock() {
                if (!this.clock.openEntry) return;
                const since = new Date(this.clock.openEntry.clocked_in_at);
                const mins = Math.max(0, Math.floor((Date.now() - since.getTime()) / 60000));
                const h = Math.floor(mins / 60), m = mins % 60;
                if (h >= 10) {
                    this.clock.elapsed = `${h}h ${String(m).padStart(2, '0')}m`;
                } else {
                    this.clock.elapsed = `${h}:${String(m).padStart(2, '0')}`;
                }
            },
            clockChipClasses() {
                if (this.clock.error) return 'bg-red-50 text-red-700 ring-1 ring-red-300';
                if (this.clock.openEntry) return 'bg-[#178A95] text-white';
                return 'bg-white text-[#0B3C45]';
            },
            clockAriaLabel() {
                if (this.clock.error) return 'Try again';
                if (this.clock.openEntry) return 'On the clock — tap to clock out';
                return 'Clock in';
            },
            // ── Category sheet (D-01, D-02) ──
            openCategorySheet() {
                this.categorySheet.open = true;
                this.categorySheet.error = null;
                this.categorySheet.saving = false;
                // lastUsed is pre-loaded in init(); highlight happens declaratively in the partial
            },
            dismissCategorySheet() {
                if (this.categorySheet.saving) return;   // don't allow dismiss mid-POST
                this.categorySheet.open = false;
            },
            async submitCategory(category) {
                if (!['installation', 'commissioning', 'testing', 'other'].includes(category)) return;
                this.categorySheet.saving = true;
                this.categorySheet.error = null;
                this.clock.saving = true;
                try {
                    // projectId is in closure scope from fieldRoot({ projectId, ... })
                    const res = await fetch(`/projects/${projectId}/time-entries/start`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json',
                                   'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' },
                        body: JSON.stringify({ category }),
                    });
                    if (!res.ok) {
                        const body = await res.json().catch(() => ({}));
                        this.categorySheet.error = body.message ?? "Couldn't clock in — try again.";
                        return;
                    }
                    const data = await res.json();
                    this.clock.openEntry = { id: data.id, clocked_in_at: data.clocked_in_at };
                    this.startClockTicker();
                    this.heartbeat.currentEntryId = data.id;
                    this.startHeartbeat();
                    // Persist the chosen category for next clock-in (D-02)
                    const userId = document.querySelector('[data-user-id]')?.dataset.userId ?? 'anon';
                    localStorage.setItem('last-category-' + userId, category);
                    this.categorySheet.lastUsed = category;
                    this.categorySheet.open = false;
                } catch (e) {
                    this.categorySheet.error = "Couldn't reach the server — try again in a moment.";
                } finally {
                    this.categorySheet.saving = false;
                    this.clock.saving = false;
                }
            },

            // ── Note sheet (D-06) ──
            openNoteSheet() {
                this.noteSheet.note = '';
                this.noteSheet.error = null;
                this.noteSheet.saving = false;
                this.noteSheet.open = true;
            },
            dismissNoteSheet() {
                if (this.noteSheet.saving) return;
                this.noteSheet.open = false;
            },
            async submitNote(note) {
                // note === null  -> Skip tapped (no note)
                // note is string -> Save & clock out tapped
                this.noteSheet.saving = true;
                this.noteSheet.error = null;
                this.clock.saving = true;
                try {
                    const body = {};
                    if (note !== null && typeof note === 'string' && note.trim() !== '') {
                        body.note = note.trim().slice(0, 500);
                    }
                    // projectId is in closure scope from fieldRoot({ projectId, ... })
                    const res = await fetch(`/projects/${projectId}/time-entries/stop`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json',
                                   'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' },
                        body: JSON.stringify(body),
                    });
                    if (!res.ok) {
                        const bodyErr = await res.json().catch(() => ({}));
                        this.noteSheet.error = bodyErr.message ?? "Couldn't clock out — try again.";
                        return;
                    }
                    // On success: reset chip state + tear down heartbeat
                    this.clock.openEntry = null;
                    this.stopClockTicker();
                    this.clock.elapsed = '0:00';
                    this.stopHeartbeat();
                    this.heartbeat.currentEntryId = null;
                    this.noteSheet.open = false;
                } catch (e) {
                    this.noteSheet.error = "Couldn't reach the server — try again in a moment.";
                } finally {
                    this.noteSheet.saving = false;
                    this.clock.saving = false;
                }
            },

            // ── Heartbeat (D-08, D-09, D-10) ──
            // Uses setTimeout + recursive re-schedule so we can vary delay per retry.
            startHeartbeat() {
                this.stopHeartbeat();    // idempotent — clears any pending timeout
                if (!this.heartbeat.currentEntryId) return;
                this.heartbeat.handle = setTimeout(
                    () => this.sendHeartbeat(),
                    this.heartbeat.delayMs,
                );
            },
            stopHeartbeat() {
                if (this.heartbeat.handle) {
                    clearTimeout(this.heartbeat.handle);
                    this.heartbeat.handle = null;
                }
            },
            async sendHeartbeat() {
                if (!this.heartbeat.currentEntryId || this.heartbeat.visibilityPaused) return;
                try {
                    const res = await fetch(`/time-entries/${this.heartbeat.currentEntryId}/heartbeat`, {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' },
                    });
                    if (res.ok || res.status === 204) {
                        // Reset delay to baseline on success
                        this.heartbeat.delayMs = 60000;
                        this.heartbeat.consecutiveFailures = 0;
                    } else if (res.status === 422) {
                        // Server says entry is closed (stale-close or peer-close). Give up silently.
                        this.stopHeartbeat();
                        return;
                    } else {
                        this._backoffHeartbeat();
                    }
                } catch (e) {
                    this._backoffHeartbeat();
                }
                // Re-schedule the next tick with the (possibly updated) delay
                if (this.heartbeat.currentEntryId && !this.heartbeat.visibilityPaused) {
                    this.heartbeat.handle = setTimeout(
                        () => this.sendHeartbeat(),
                        this.heartbeat.delayMs,
                    );
                }
            },
            _backoffHeartbeat() {
                this.heartbeat.consecutiveFailures++;
                // D-09 schedule: 60s → 120s → 300s → stay at 300s
                const ladder = [60000, 120000, 300000, 300000];
                const idx = Math.min(this.heartbeat.consecutiveFailures, ladder.length - 1);
                this.heartbeat.delayMs = ladder[idx];
            },
            // ── Bottom-sheet ──
            openSheet(mode, taskId, roomName) {
                this.sheet.mode = mode;
                this.sheet.taskId = taskId;
                this.sheet.reason = '';
                this.sheet.saving = false;
                this.sheet.error = null;
                this.sheet.open = true;
                if (roomName) this.lastRoomName = roomName;
            },
            dismissSheet() {
                if (this.sheet.reason.trim() && !confirm('Discard this reason?')) return;
                this.sheet.open = false;
            },
            async submitSheet() {
                if (!this.sheet.reason.trim()) return;
                this.sheet.saving = true;
                this.sheet.error = null;
                try {
                    const res = await fetch(`/install-tasks/${this.sheet.taskId}/status`, {
                        method: 'PATCH',
                        headers: { 'Content-Type': 'application/json',
                                   'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' },
                        body: JSON.stringify({ status: this.sheet.mode, blocked_reason: this.sheet.reason }),
                    });
                    if (!res.ok) {
                        this.sheet.error = 'Couldn\'t save — check your signal and retry.';
                        return;
                    }
                    const data = await res.json();
                    window.dispatchEvent(new CustomEvent('task-saved', {
                        detail: { ...data, room_name: this.lastRoomName },
                    }));
                    this.sheet.open = false;
                } catch (e) {
                    this.sheet.error = 'Couldn\'t save — check your signal and retry.';
                } finally {
                    this.sheet.saving = false;
                }
            },
            // ── Progress bar + room counter refresh ──
            applyCounters(counters) {
                if (!counters?.programme) return;
                const bar = document.querySelector('[data-testid="programme-progress-bar"]');
                if (bar) {
                    const total = Math.max(1, counters.programme.total);
                    const done = counters.programme.complete;
                    bar.style.width = `${Math.round((done / total) * 100)}%`;
                    if (done === counters.programme.total && counters.programme.total > 0) {
                        bar.classList.remove('bg-[#178A95]'); bar.classList.add('bg-green-600');
                    } else {
                        bar.classList.add('bg-[#178A95]'); bar.classList.remove('bg-green-600');
                    }
                }
                const text = document.querySelector('[data-testid="programme-progress-text"]');
                if (text && counters.programme.total > 0) {
                    if (counters.programme.complete === counters.programme.total) {
                        text.textContent = 'All tasks complete · ready for commissioning';
                    } else {
                        text.textContent = `${counters.programme.complete} of ${counters.programme.total} tasks complete`;
                    }
                }
                if (counters.room && this.lastRoomName) {
                    const roomEl = document.querySelector(`[data-room-name="${CSS.escape(this.lastRoomName)}"] [data-testid="room-counter"]`);
                    if (roomEl) {
                        if (counters.room.complete === counters.room.total && counters.room.total > 0) {
                            roomEl.innerHTML = '<span class="text-green-600 font-semibold">✓ Complete</span>';
                        } else {
                            roomEl.textContent = `${counters.room.complete} of ${counters.room.total}`;
                        }
                    }
                }
            },
        };
    }

    function fieldTaskRow({ id, status, blockedReason, notes }) {
        return {
            id, status, blockedReason,
            notes, notesDirty: false, notesError: null,
            savedPulse: false, errorPulse: false, menuOpen: false,
            statusLabel() {
                return ({
                    pending: 'Pending — tap to start',
                    in_progress: 'In progress — tap to complete',
                    complete: 'Complete',
                    blocked: 'Blocked',
                    skipped: 'Skipped',
                })[this.status] ?? this.status;
            },
            iconColor() {
                return ({
                    pending: 'text-gray-400',
                    in_progress: 'text-amber-600',
                    complete: 'text-green-600',
                    blocked: 'text-red-600',
                    skipped: 'text-gray-500',
                })[this.status] ?? 'text-gray-400';
            },
            rowClasses() {
                const base = ({
                    pending: 'bg-white border-gray-200',
                    in_progress: 'bg-amber-50 border-amber-300',
                    complete: 'bg-green-50 border-green-300',
                    blocked: 'bg-red-50 border-red-300',
                    skipped: 'bg-gray-100 border-gray-300',
                })[this.status] ?? 'bg-white border-gray-200';
                const pulse = this.savedPulse ? 'ring-2 ring-green-400' : '';
                const err = this.errorPulse ? 'ring-2 ring-red-400' : '';
                return `${base} ${pulse} ${err}`.trim();
            },
            advance() {
                const next = ({ pending: 'in_progress', in_progress: 'complete' })[this.status];
                if (!next) return; // D-05 no-op on complete / blocked / skipped
                this.patch(next);
            },
            reopen() {
                if (this.status !== 'complete') return;
                this.patch('in_progress');
            },
            openSheet(mode) {
                const roomName = this.$root?.dataset?.room ?? null;
                window.dispatchEvent(new CustomEvent('open-blocked-sheet', {
                    detail: { mode, taskId: this.id, roomName },
                }));
            },
            async patch(newStatus, reason = null) {
                const body = { status: newStatus };
                if (reason !== null) body.blocked_reason = reason;
                try {
                    const res = await fetch(`/install-tasks/${this.id}/status`, {
                        method: 'PATCH',
                        headers: { 'Content-Type': 'application/json',
                                   'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' },
                        body: JSON.stringify(body),
                    });
                    if (!res.ok) throw new Error('save failed');
                    const data = await res.json();
                    this.status = data.status;
                    this.blockedReason = data.blocked_reason;
                    this.savedPulse = true;
                    setTimeout(() => { this.savedPulse = false; }, 400);
                    const roomName = this.$root?.dataset?.room ?? null;
                    window.dispatchEvent(new CustomEvent('task-saved', {
                        detail: { ...data, room_name: roomName },
                    }));
                } catch (e) {
                    this.errorPulse = true;
                    setTimeout(() => { this.errorPulse = false; }, 4000);
                }
            },
            async saveNotes() {
                if (!this.notesDirty) return;
                try {
                    const res = await fetch(`/install-tasks/${this.id}/notes`, {
                        method: 'PATCH',
                        headers: { 'Content-Type': 'application/json',
                                   'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' },
                        body: JSON.stringify({ notes: this.notes }),
                    });
                    if (!res.ok) {
                        this.notesError = 'Didn\'t save. Check your signal and retype.';
                        setTimeout(() => { this.notesError = null; }, 4000);
                        return;
                    }
                    this.notesDirty = false;
                } catch (e) {
                    this.notesError = 'Didn\'t save. Check your signal and retype.';
                }
            },
        };
    }
</script>
@endpush
@endsection
