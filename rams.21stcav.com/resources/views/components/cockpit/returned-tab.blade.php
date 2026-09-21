{{--
    The Returned tab (Phase 46.1, Plan 46.1-03, D-01) — what the engineer
    actually sent back from site.

    Plan 46.1-03 Task 1 lands the SHELL: the tab exists, the state line reads,
    and an empty drawer says so in one sentence. Task 2 fills the evidence.
--}}
@props([
    'project',
    'module',
    'visits'   => null,
    'evidence' => [],
])

@php
    $visits = $visits ?? collect();
@endphp

@forelse ($visits as $visit)
    @php($payload = $evidence[$visit->id] ?? null)

    <div class="cav-panel__card">
        <span class="cav-panel__card-head">{{ $visit->title }}</span>

        @if ($payload === null || ! $payload['has_anything'])
            <x-cockpit.hint>Nothing has come back from site yet.</x-cockpit.hint>
        @endif
    </div>
@empty
    <x-cockpit.hint>{{ $module['title'] }} has no visit with an engineer record behind it.</x-cockpit.hint>
@endforelse
