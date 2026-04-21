@props(['roomName', 'tasks', 'counter', 'isOwnerOrAdmin'])

{{--
    Per-room collapsible section (D-01).
    Expanded by default; chevron-down when open, chevron-right when collapsed.
    Counter: "N of M" normally; "✓ Complete" green-600 when all tasks done.
--}}
<section class="mb-6"
         x-data="{ open: true }"
         data-testid="task-room"
         data-room-name="{{ $roomName }}">
    <header class="flex items-center justify-between mb-2">
        <button type="button"
                class="flex items-center gap-2 text-base font-semibold text-gray-800
                       focus-visible:ring-2 focus-visible:ring-[#178A95] focus-visible:outline-none
                       rounded min-h-[44px] -ml-1 px-1"
                :aria-label="open ? 'Collapse ' + {{ \Illuminate\Support\Js::from($roomName) }} : 'Expand ' + {{ \Illuminate\Support\Js::from($roomName) }}"
                :aria-expanded="open.toString()"
                @click="open = !open">
            <svg x-show="open" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                 stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
                <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
            </svg>
            <svg x-show="!open" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                 stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
                <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
            </svg>
            <span>{{ $roomName ?: 'Unassigned room' }}</span>
        </button>
        <span class="text-xs text-gray-500" data-testid="room-counter">
            @if ($counter['complete'] === $counter['total'] && $counter['total'] > 0)
                <span class="text-green-600 font-semibold">✓ Complete</span>
            @else
                {{ $counter['complete'] }} of {{ $counter['total'] }}
            @endif
        </span>
    </header>

    <div x-show="open" x-cloak>
        @foreach ($tasks as $task)
            @include('install-programmes._field-task-row', [
                'task' => $task,
                'isOwnerOrAdmin' => $isOwnerOrAdmin,
            ])
        @endforeach
    </div>
</section>
