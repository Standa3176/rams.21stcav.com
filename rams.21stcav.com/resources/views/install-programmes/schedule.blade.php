@extends('layouts.app')

@section('title', 'Schedule — ' . $programme->project->name)

@section('content')

{{-- Breadcrumb --}}
<nav style="font-size:.875rem;margin-bottom:1rem;">
    <a href="{{ route('projects.index') }}" style="color:var(--teal);text-decoration:none;">Projects</a>
    &rsaquo;
    <a href="{{ route('projects.show', $programme->project) }}" style="color:var(--teal);text-decoration:none;">{{ $programme->project->name }}</a>
    &rsaquo;
    <span style="color:var(--text-muted);">Schedule</span>
</nav>

{{-- Page header --}}
<div class="page-header">
    <div>
        <h1 class="page-title">Install Schedule — {{ $programme->project->name }}</h1>
        <p class="page-subtitle" style="color:var(--text-muted);margin-top:.25rem;font-size:.875rem;">
            {{ $programme->project->client_name }}
            @if($programme->project->site_address) · {{ $programme->project->site_address }} @endif
        </p>
    </div>
    <div style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;">
        <span class="badge {{ $programme->statusBadgeClass() }}">{{ $programme->statusLabel() }}</span>
        <a href="{{ route('projects.show', $programme->project_id) }}" class="btn-outline btn-sm">← Back to Project</a>
    </div>
</div>

{{-- Flash messages --}}
@if(session('success'))
    <div style="background:#D1FAE5;border:1px solid #6EE7B7;border-radius:6px;padding:.75rem 1rem;margin-bottom:1rem;font-size:.875rem;color:#065F46;">
        {{ session('success') }}
    </div>
@endif

{{-- Empty state --}}
@if($byWeek->isEmpty() && $unscheduled->isEmpty())
    <div class="card card-sm" style="padding:2rem;text-align:center;color:var(--text-muted);">
        No tasks scheduled yet.
    </div>
@endif

{{-- ===================================================================== --}}
{{-- SECTION A — Unscheduled tasks (tasks with no planned_start_date)       --}}
{{-- ===================================================================== --}}
@if($unscheduled->isNotEmpty())
    <div class="card card-sm" style="margin-bottom:1.25rem;overflow:hidden;">
        <div style="padding:.85rem 1.25rem;border-bottom:1px solid var(--border);background:#FFFBEB;">
            <h3 style="margin:0;font-size:.9rem;font-weight:700;color:#92400E;">
                Unscheduled Tasks
                <span style="font-weight:400;font-size:.8rem;color:var(--text-muted);margin-left:.5rem;">
                    {{ $unscheduled->count() }} {{ Str::plural('task', $unscheduled->count()) }}
                </span>
            </h3>
        </div>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:.84rem;">
                <thead>
                    <tr style="background:#F3F6F7;">
                        <th style="padding:.5rem .75rem;text-align:left;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);border-bottom:1px solid var(--border);">Task</th>
                        <th style="padding:.5rem .75rem;text-align:left;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);border-bottom:1px solid var(--border);">Room</th>
                        <th style="padding:.5rem .75rem;text-align:left;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);border-bottom:1px solid var(--border);">Engineer</th>
                        <th style="padding:.5rem .75rem;text-align:left;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);border-bottom:1px solid var(--border);">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($unscheduled as $task)
                        <tr style="border-bottom:1px solid #f5f5f5;">
                            <td style="padding:.45rem .75rem;color:#374151;vertical-align:middle;">{{ $task->title }}</td>
                            <td style="padding:.45rem .75rem;color:var(--text-muted);vertical-align:middle;">{{ $task->room_name }}</td>
                            <td style="padding:.45rem .75rem;vertical-align:middle;">
                                @if($task->assignedUser)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $engineerColours[$task->assigned_to % 8] }}">
                                        {{ $task->assignedUser->name }}
                                    </span>
                                @else
                                    <span style="color:var(--text-muted);font-size:.75rem;">Unassigned</span>
                                @endif
                            </td>
                            <td style="padding:.45rem .75rem;vertical-align:middle;">
                                <span style="font-size:.75rem;font-weight:600;color:#6B7280;">{{ $task->statusLabel() }}</span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

{{-- ===================================================================== --}}
{{-- SECTION B — Week-view calendar (tasks grouped by ISO week)             --}}
{{-- ===================================================================== --}}
@foreach($byWeek as $isoWeekKey => $weekTasks)
    @php
        $firstTask  = $weekTasks->first();
        $weekStart  = \Carbon\Carbon::parse($firstTask->planned_start_date)->startOfWeek();
        $weekEnd    = $weekStart->copy()->endOfWeek();
        $weekLabel  = 'Week ' . $weekStart->isoWeek() . ' — '
                    . $weekStart->format('M j') . '–' . $weekEnd->format('M j, Y');
    @endphp
    <div class="card card-sm" style="margin-bottom:1.25rem;overflow:hidden;">
        <div style="padding:.85rem 1.25rem;border-bottom:1px solid var(--border);background:#F9FAFB;">
            <h3 style="margin:0;font-size:.9rem;font-weight:700;color:#0B3C45;">
                {{ $weekLabel }}
                <span style="font-weight:400;font-size:.8rem;color:var(--text-muted);margin-left:.5rem;">
                    {{ $weekTasks->count() }} {{ Str::plural('task', $weekTasks->count()) }}
                </span>
            </h3>
        </div>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:.84rem;">
                <thead>
                    <tr style="background:#F3F6F7;">
                        <th style="padding:.5rem .75rem;text-align:left;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);border-bottom:1px solid var(--border);">Task</th>
                        <th style="padding:.5rem .75rem;text-align:left;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);border-bottom:1px solid var(--border);">Room</th>
                        <th style="padding:.5rem .75rem;text-align:left;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);border-bottom:1px solid var(--border);">Engineer</th>
                        <th style="padding:.5rem .75rem;text-align:left;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);border-bottom:1px solid var(--border);">Start</th>
                        <th style="padding:.5rem .75rem;text-align:left;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);border-bottom:1px solid var(--border);">End</th>
                        <th style="padding:.5rem .75rem;text-align:left;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);border-bottom:1px solid var(--border);">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($weekTasks as $task)
                        <tr style="border-bottom:1px solid #f5f5f5;">
                            <td style="padding:.45rem .75rem;color:#374151;vertical-align:middle;">{{ $task->title }}</td>
                            <td style="padding:.45rem .75rem;color:var(--text-muted);vertical-align:middle;">{{ $task->room_name }}</td>
                            <td style="padding:.45rem .75rem;vertical-align:middle;">
                                @if($task->assignedUser)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $engineerColours[$task->assigned_to % 8] }}">
                                        {{ $task->assignedUser->name }}
                                    </span>
                                @else
                                    <span style="color:var(--text-muted);font-size:.75rem;">Unassigned</span>
                                @endif
                            </td>
                            <td style="padding:.45rem .75rem;color:var(--text-muted);vertical-align:middle;">
                                {{ $task->planned_start_date?->format('d M') ?? '—' }}
                            </td>
                            <td style="padding:.45rem .75rem;color:var(--text-muted);vertical-align:middle;">
                                {{ $task->planned_end_date?->format('d M') ?? '—' }}
                            </td>
                            <td style="padding:.45rem .75rem;vertical-align:middle;">
                                <span style="font-size:.75rem;font-weight:600;color:#6B7280;">{{ $task->statusLabel() }}</span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endforeach

{{-- ===================================================================== --}}
{{-- SECTION C — Conditional Gantt (INST-02e: only when $showGantt is true) --}}
{{-- ===================================================================== --}}
@if($showGantt)
    {{-- INST-02e: Gantt rendered when planned_end_date - planned_start_date > 4 days --}}
    {{-- INST-02f: Read-only — no drag handlers. Click opens Alpine slide-over panel. --}}
    <div
        class="card card-sm"
        style="margin-bottom:1.25rem;overflow:hidden;"
        x-data="{
            open: false,
            activeTask: null,
            openDetail(task) { this.activeTask = task; this.open = true; },
            closeDetail() { this.open = false; this.activeTask = null; }
        }"
        x-init="
            const tasks = {{ Js::from($ganttTasks) }};
            if (tasks.length > 0 && typeof window.Gantt !== 'undefined') {
                const gantt = new window.Gantt('#gantt-container', tasks, {
                    view_mode: 'Day',
                    date_format: 'YYYY-MM-DD',
                    on_click: (task) => {
                        // INST-02f: click opens detail panel, no reschedule
                        const found = tasks.find(t => t.id === task.id);
                        if (found) { $data.openDetail(found); }
                    },
                    on_date_change: () => {},
                    on_progress_change: () => {},
                    popup_trigger: null,
                });
            }
        "
    >
        <div style="padding:.85rem 1.25rem;border-bottom:1px solid var(--border);background:#F9FAFB;">
            <h3 style="margin:0;font-size:.9rem;font-weight:700;color:#0B3C45;">Gantt Timeline</h3>
        </div>
        <div style="padding:1rem;overflow-x:auto;">
            <div id="gantt-container"></div>
        </div>

        {{-- INST-02f: Task detail slide-over panel --}}
        <div
            x-show="open"
            x-transition
            class="fixed inset-y-0 right-0 z-50 w-80 bg-white shadow-xl p-6 overflow-y-auto"
            x-cloak
        >
            <button @click="closeDetail()" class="mb-4 text-gray-400 hover:text-gray-600" style="font-size:.875rem;">
                &larr; Close
            </button>
            <template x-if="activeTask">
                <div>
                    <h4 class="font-semibold text-gray-800 mb-4" x-text="activeTask.name" style="font-size:.95rem;"></h4>
                    <dl style="display:grid;gap:.75rem;">
                        <div>
                            <dt style="font-size:.75rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;">Start</dt>
                            <dd style="font-size:.875rem;margin-top:.15rem;" x-text="activeTask.start"></dd>
                        </div>
                        <div>
                            <dt style="font-size:.75rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;">End</dt>
                            <dd style="font-size:.875rem;margin-top:.15rem;" x-text="activeTask.end"></dd>
                        </div>
                        <div>
                            <dt style="font-size:.75rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;">Progress</dt>
                            <dd style="font-size:.875rem;margin-top:.15rem;" x-text="activeTask.progress + '%'"></dd>
                        </div>
                    </dl>
                </div>
            </template>
        </div>

        {{-- Backdrop --}}
        <div
            x-show="open"
            x-transition.opacity
            @click="closeDetail()"
            class="fixed inset-0 z-40 bg-black bg-opacity-20"
            x-cloak
        ></div>
    </div>
@endif

@endsection
