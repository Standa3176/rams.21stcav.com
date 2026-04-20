{{--
    Phase 14 Plan 04 — minimal field view placeholder.

    This file exists so the field() controller action can render a View and
    downstream HTTP tests (FieldPageTest) can assert 200 + task title visibility.

    Plan 14-05 replaces this entirely with the mobile-first Blade + Alpine +
    Tailwind implementation (UI-SPEC task-row markers, sticky bar, photo strip,
    etc). Keep this file minimal — no service-worker registration (INST-03h),
    no fixed wide pixel widths (FieldViewResponsivenessTest heuristics).
--}}
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ $project->name ?? 'Project' }} — Field View
        </h2>
    </x-slot>

    <div class="py-4 sm:py-6">
        <div class="max-w-3xl mx-auto px-4">
            {{-- Sticky bar / clock-in status (stub — Plan 05 delivers the real chrome) --}}
            <div class="mb-4 flex items-center justify-between text-sm">
                <span>
                    @if($openEntry)
                        Clocked in since {{ $openEntry->clocked_in_at?->format('H:i') }}
                    @else
                        Not clocked in
                    @endif
                </span>
                <span>
                    {{ $counters['programme']['complete'] }} of {{ $counters['programme']['total'] }} tasks complete
                </span>
            </div>

            @if(! $programme)
                <p class="text-gray-600">No active install programme yet for this project.</p>
            @elseif($rooms->isEmpty())
                <p class="text-gray-600">
                    @if($isOwnerOrAdmin)
                        No tasks on the active programme yet.
                    @else
                        No tasks assigned to you yet.
                        <a href="?scope=all" class="text-blue-600 underline">Show all programme tasks</a>
                    @endif
                </p>
            @else
                @foreach($rooms as $roomName => $tasks)
                    <section class="mb-6">
                        <h3 class="font-semibold text-base mb-2">
                            {{ $roomName ?: 'Unassigned room' }}
                            <span class="text-sm text-gray-500">
                                ({{ $counters['room'][$roomName]['complete'] ?? 0 }} of
                                {{ $counters['room'][$roomName]['total'] ?? 0 }})
                            </span>
                        </h3>
                        <ul class="space-y-2">
                            @foreach($tasks as $task)
                                <li class="p-3 rounded border border-gray-200 bg-white">
                                    <div class="font-medium">{{ $task->title }}</div>
                                    @if($task->description)
                                        <div class="text-sm text-gray-600">{{ $task->description }}</div>
                                    @endif
                                    <div class="text-xs text-gray-500 mt-1">
                                        Status: {{ $task->statusLabel() }}
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endforeach
            @endif
        </div>
    </div>
</x-app-layout>
