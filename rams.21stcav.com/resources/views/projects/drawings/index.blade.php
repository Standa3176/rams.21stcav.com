@extends('layouts.app')

@section('title', 'Drawings — '.$project->name)

@push('styles')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        [x-cloak] { display: none !important; }
        .badge-grey   { background-color: #E5E7EB; color: #374151; }
        .badge-yellow { background-color: #FEF3C7; color: #92400E; }
        .badge-green  { background-color: #D1FAE5; color: #065F46; }
        .badge-teal   { background-color: #CCFBF1; color: #115E59; }
        .badge-blue   { background-color: #DBEAFE; color: #1E40AF; }
        .badge-red    { background-color: #FEE2E2; color: #991B1B; }
    </style>
@endpush

@section('content')
<div class="max-w-7xl mx-auto p-6">
    <div class="flex items-center justify-between mb-6">
        <div>
            <a href="{{ route('projects.show', $project) }}" class="text-sm text-teal-600 hover:underline">← Back to project</a>
            <h1 class="text-2xl font-semibold mt-1">Drawings — {{ $project->name }}</h1>
            <p class="text-sm text-gray-500">Project ref: {{ $project->ref ?? '—' }}</p>
        </div>
        <form method="POST" action="{{ route('projects.drawings.create-schematic', $project) }}">
            @csrf
            <button type="submit" class="inline-flex items-center gap-2 bg-teal-600 hover:bg-teal-700 text-white font-medium px-4 py-2 rounded-lg text-sm shadow-sm">
                <span aria-hidden="true">＋</span>
                <span>Generate Schematic</span>
            </button>
        </form>
    </div>

    @if (session('status'))
        <div class="mb-4 bg-green-50 border border-green-200 text-green-800 p-3 rounded">
            {{ session('status') }}
        </div>
    @endif
    @if (session('error'))
        <div class="mb-4 bg-red-50 border border-red-200 text-red-800 p-3 rounded">
            {{ session('error') }}
        </div>
    @endif

    {{-- ───── Schematics ───────────────────────────────────────────── --}}
    <h2 class="text-lg font-semibold mt-2 mb-3 text-gray-800">
        System Schematics
        @php($schematics = $drawings->where('kind', \App\Models\ProjectDrawing::KIND_SCHEMATIC))
        <span class="text-sm text-gray-500 font-normal">({{ $schematics->count() }})</span>
    </h2>

    @forelse ($schematics as $drawing)
        <div class="bg-white border border-gray-200 rounded-lg p-4 mb-3 flex items-center justify-between">
            <div class="min-w-0">
                <div class="font-medium text-gray-900 truncate">
                    {{ $drawing->kindLabel() }} — {{ $drawing->room?->name ?? 'Whole project' }}
                </div>
                <div class="text-xs text-gray-500">
                    Revision {{ $drawing->revisionLabel() }}
                    · Updated {{ $drawing->updated_at?->diffForHumans() }}
                </div>
            </div>
            <div class="flex items-center gap-3 flex-wrap justify-end">
                @include('projects.drawings._status-pill', ['drawing' => $drawing])

                @if ($drawing->isReady())
                    <a href="{{ route('projects.drawings.download', [$project, $drawing, 'pdf']) }}" class="text-sm text-teal-700 hover:underline">PDF</a>
                    <a href="{{ route('projects.drawings.download', [$project, $drawing, 'svg']) }}" class="text-sm text-teal-700 hover:underline">SVG</a>
                    <a href="{{ route('projects.drawings.download', [$project, $drawing, 'png']) }}" class="text-sm text-teal-700 hover:underline">PNG</a>
                @endif

                <a href="{{ route('projects.drawings.show', [$project, $drawing]) }}"
                   class="inline-flex items-center border border-gray-300 hover:bg-gray-50 text-gray-700 px-3 py-1 rounded-md text-sm">
                    Open
                </a>

                <button type="button"
                        x-data
                        @click="$dispatch('open-regenerate-confirm', { id: {{ $drawing->id }}, hasUserEdits: {{ $drawing->hasUserEdits() ? 'true' : 'false' }} })"
                        class="inline-flex items-center border border-gray-300 hover:bg-gray-50 text-gray-700 px-3 py-1 rounded-md text-sm">
                    Regenerate
                </button>
            </div>
        </div>
    @empty
        <div class="bg-gray-50 border border-dashed border-gray-300 rounded-lg p-6 text-center text-sm text-gray-500">
            No schematics yet — click <span class="font-medium text-gray-700">Generate Schematic</span> above to create one from canonical project data.
        </div>
    @endforelse

    {{-- Phase 18 will list racks here, Phase 19 will list floor plans here. --}}

    @include('projects.drawings._regenerate-confirm-modal', ['project' => $project])
</div>
@endsection
