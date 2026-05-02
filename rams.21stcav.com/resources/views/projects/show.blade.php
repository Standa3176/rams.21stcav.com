@extends('layouts.app')

@section('title', $project->name)

@push('styles')
    {{-- Opt this page into the Vite bundle so Alpine + Tailwind utilities are available.
         layouts/app.blade.php uses inline design-token CSS by default and does NOT
         include @vite — pages opt in via the styles stack. Matches the pattern used
         by commissioning/show.blade.php and install-programmes/field.blade.php. --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        /* Hide Alpine x-cloak elements until Alpine boots. */
        [x-cloak] { display: none !important; }

        /* ═══════════════════════════════════════════════════════════════
           PROJECT SHOW VIEW — modern dashboard
        ═══════════════════════════════════════════════════════════════ */

        .page-header .page-title {
            font-family: var(--font-display);
            font-size: 1.85rem;
            line-height: 1.15;
            font-weight: 500;
            color: var(--ink-900);
            letter-spacing: -.02em;
            margin: 0.25rem 0 0.35rem;
        }

        /* Main tabbed workspace cards */
        .psv__main > .section-block {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-xs);
            padding: 1.5rem 1.5rem;
            margin: 0;
        }
        .psv__main > .section-block .section-card__header {
            border-bottom: 1px solid var(--border);
            padding-bottom: 0.7rem;
            margin-bottom: 1rem;
        }
        .psv__main > .section-block .section-card__title {
            font-family: var(--font-display);
            font-size: 1rem;
            font-weight: 600;
            color: var(--ink-900);
            letter-spacing: -.01em;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .psv__main > .section-block .section-card__title::before {
            content: '';
            display: inline-block;
            width: 3px;
            height: 18px;
            background: var(--teal-700);
            border-radius: 2px;
        }

        /* Right sticky panel cards — flush header bar with cream tint for weight */
        .psv__sticky > .section-block {
            background: var(--surface);
            border: 1px solid var(--ink-300);
            border-radius: var(--radius);
            box-shadow: var(--shadow-xs);
            padding: 0;
            margin: 0;
            overflow: hidden;
        }
        .psv__sticky > .section-block .section-card__header {
            background: var(--paper-2);
            border-bottom: 1px solid var(--ink-200);
            padding: .65rem 1.1rem;
            margin: 0;
        }
        .psv__sticky > .section-block .section-card__title {
            font-size: .72rem;
            font-weight: 700;
            color: var(--teal-700);
            text-transform: uppercase;
            letter-spacing: .10em;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .psv__sticky > .section-block .section-card__title::before {
            content: '';
            display: inline-block;
            width: 4px;
            height: 14px;
            background: var(--teal-700);
            border-radius: 2px;
        }
        .psv__sticky > .section-block .section-card__body { padding: 1rem 1.25rem; }

        /* Workflow card — same flush-header treatment as the right column */
        .psv__workflow > .section-block {
            background: var(--surface);
            border: 1px solid var(--ink-300);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-xs);
            padding: 0;
            overflow: hidden;
        }
        .psv__workflow > .section-block .section-card__header {
            background: var(--paper-2);
            border-bottom: 1px solid var(--ink-200);
            padding: .65rem 1.25rem;
            margin: 0;
        }
        .psv__workflow > .section-block .section-card__title {
            font-size: .72rem;
            font-weight: 700;
            color: var(--teal-700);
            text-transform: uppercase;
            letter-spacing: .10em;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .psv__workflow > .section-block .section-card__title::before {
            content: '';
            display: inline-block;
            width: 4px;
            height: 14px;
            background: var(--teal-700);
            border-radius: 2px;
        }
        .psv__workflow > .section-block .section-card__body { padding: 1.25rem 1.5rem; }

        /* Workspace tab strip — flat horizontal nav (SCC v2 style) */
        .ws { background: transparent; padding: 0; border-radius: 0; }
        .ws-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 0;
            margin-bottom: 0;
            border-bottom: 1px solid var(--ink-200);
        }
        .ws-tab {
            background: transparent;
            border: none;
            padding: .65rem 1rem;
            margin-bottom: -1px;
            font-size: .8125rem;
            font-weight: 500;
            color: var(--ink-500);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            border-bottom: 2px solid transparent;
            transition: color .12s, border-color .12s;
            white-space: nowrap;
        }
        .ws-tab:hover { color: var(--ink-900); }
        .ws-tab.is-active {
            color: var(--teal-700);
            border-bottom-color: var(--teal-700);
            font-weight: 600;
        }
        .ws-tab__count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 20px;
            height: 18px;
            padding: 0 6px;
            background: var(--ink-100);
            color: var(--ink-700);
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
            line-height: 1;
        }
        .ws-tab.is-active .ws-tab__count {
            background: var(--teal-700);
            color: #FFF;
        }
        /* Workspace body sits flush below the tab strip */
        .ws > .bg-white {
            border-top-left-radius: 0;
            border-top-right-radius: 0;
            border-top: none;
        }

        /* Table-row hover */
        .psv__main table tbody tr { transition: background-color var(--transition); }
        .psv__main table tbody tr:hover { background-color: var(--surface-soft); }

        /* Project ref / breadcrumb */
        .psv-ref {
            font-family: var(--font-mono);
            font-size: .8125rem;
            color: var(--text-muted);
            letter-spacing: .02em;
        }

        /* Workflow stepper — pill steps with progress bar */
        .psv-stepper {
            display: flex;
            gap: .5rem;
            flex-wrap: nowrap;
            overflow-x: auto;
            padding-bottom: .25rem;
            margin-bottom: 1rem;
        }
        .psv-stepper::-webkit-scrollbar { height: 6px; }
        .psv-stepper::-webkit-scrollbar-track { background: var(--bg-deep); border-radius: 3px; }
        .psv-stepper::-webkit-scrollbar-thumb { background: var(--border-strong); border-radius: 3px; }

        .psv-step {
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            padding: .45rem .9rem .45rem .55rem;
            border-radius: 999px;
            font-size: .8125rem;
            font-weight: 500;
            border: 1px solid var(--border);
            background: var(--surface);
            color: var(--text-muted);
            white-space: nowrap;
            transition: all var(--transition);
            flex-shrink: 0;
        }
        .psv-step__num {
            width: 22px; height: 22px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: .7rem;
            font-weight: 600;
            background: var(--surface-deep);
            color: var(--text-muted);
        }
        .psv-step.is-current {
            border-color: var(--teal);
            background: var(--teal);
            color: #fff;
            font-weight: 600;
        }
        .psv-step.is-current .psv-step__num {
            background: rgba(255,255,255,.25);
            color: #fff;
        }
        .psv-step.is-done {
            border-color: var(--success);
            background: var(--success-light);
            color: #166534;
        }
        .psv-step.is-done .psv-step__num {
            background: var(--success);
            color: #fff;
        }

        /* Workflow progress bar */
        .psv-progress {
            display: flex;
            align-items: center;
            gap: .85rem;
            margin-top: 0.25rem;
        }
        .psv-progress__label {
            font-size: .75rem;
            color: var(--text-muted);
            white-space: nowrap;
            flex-shrink: 0;
        }
        .psv-progress__track {
            flex: 1;
            height: 6px;
            background: var(--surface-deep);
            border-radius: 3px;
            overflow: hidden;
        }
        .psv-progress__fill {
            height: 100%;
            background: var(--teal);
            border-radius: 3px;
            transition: width 400ms ease;
        }

        /* Document tabs (inside workspace card) */
        .psv-tabs {
            display: flex;
            gap: 1.5rem;
            flex-wrap: wrap;
            border-bottom: 1px solid var(--border);
            margin-bottom: 1.25rem;
        }
        .psv-tab {
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            padding: .75rem 0;
            border: none;
            background: transparent;
            color: var(--text-muted);
            font-size: .875rem;
            font-weight: 500;
            cursor: pointer;
            position: relative;
            transition: color var(--transition);
            white-space: nowrap;
        }
        .psv-tab:hover { color: var(--text); }
        .psv-tab.is-active {
            color: var(--teal);
            font-weight: 600;
        }
        .psv-tab.is-active::after {
            content: '';
            position: absolute;
            left: 0; right: 0; bottom: -1px;
            height: 2px;
            background: var(--teal);
        }
        .psv-tab-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 20px; height: 20px;
            padding: 0 .4rem;
            border-radius: 999px;
            font-size: .68rem;
            font-weight: 700;
            background: var(--surface-deep);
            color: var(--text-muted);
        }
        .psv-tab.is-active .psv-tab-count {
            background: var(--teal);
            color: #fff;
        }

        /* Status badge weight */
        .psv .status-badge { font-weight: 500; }
    </style>
@endpush

@section('content')

@php
    // ── Setup ────────────────────────────────────────────────────────────────
    $isAdmin            = auth()->user()?->isAdmin();
    $lifecycle          = \App\Models\Project::LIFECYCLE;
    $currentIdx         = array_search($project->status, $lifecycle);
    $primaryPackage     = $project->latestPackage ?: $project->packages()->latest()->first();
    $headerAwaitingRams = $project->ramsDocuments
                              ->where('status', \App\Models\RamsDocument::STATUS_AWAITING_REVIEW)
                              ->sortByDesc('id')->first();
    $generatingRams     = $project->ramsDocuments
                              ->whereIn('status', [
                                  \App\Models\RamsDocument::STATUS_GENERATING,
                                  \App\Models\RamsDocument::STATUS_APPROVED_FOR_GENERATION,
                              ])->first();
    $hasCompletedRams   = $project->ramsDocuments
                              ->whereIn('status', [
                                  \App\Models\RamsDocument::STATUS_COMPLETED,
                                  \App\Models\RamsDocument::STATUS_FOR_REVIEW,
                                  \App\Models\RamsDocument::STATUS_DRAFT,
                              ])->isNotEmpty();
    $generatingOm       = $project->omManuals->where('status', \App\Models\OmManual::STATUS_GENERATING)->first();
    $hasCompletedOm     = $project->omManuals
                              ->whereIn('status', [\App\Models\OmManual::STATUS_DRAFT, \App\Models\OmManual::STATUS_FINAL])
                              ->isNotEmpty();

    $countRams       = $project->ramsDocuments->count();
    $countWorksheet  = $project->worksheets->count();
    $countSurvey     = $project->siteSurveys->count();
    $countOm         = $project->omManuals->count();
    $countCable      = $project->cableSchedules->count();
    $countInstall    = $project->installProgrammes->count();
    $countQuotes     = $project->projectQuotes->count();
    // Phase 17 v1.3 — Drawings count (current revisions only; superseded
    // versions excluded so the badge never inflates with archived rows).
    $countDrawings   = $project->drawings()->whereNull('superseded_by_id')->count();

    $linkedByType = collect($linkedRecords)->keyBy('type');

    $quoteRamsMap = $project->ramsDocuments
        ->filter(fn ($r) => ! empty($r->form_data['original_filename'] ?? null))
        ->groupBy(fn ($r) => $r->form_data['original_filename'])
        ->map(fn ($g) => $g->sortByDesc('id')->first());

    $ramsVersionMap = $project->ramsDocuments
        ->sortBy('created_at')->values()
        ->mapWithKeys(fn ($doc, $i) => [$doc->id => $i + 1]);

    // ── Next Step decision ───────────────────────────────────────────────────
    $nextStep = null;
    if ($headerAwaitingRams) {
        $nextStep = [
            'icon'  => '✎',
            'title' => 'Review & Generate RAMS',
            'desc'  => 'A RAMS extraction is ready — confirm the parsed scope, then generate the document.',
            'cta'   => 'Review & Generate',
            'href'  => route('rams.quote-review.show', $headerAwaitingRams),
            'tab'   => 'rams',
        ];
    } elseif ($generatingRams || $generatingOm) {
        $nextStep = [
            'icon'  => '⏳',
            'title' => 'Generation in progress',
            'desc'  => 'A document is being built in the background. The page refreshes automatically when it is ready.',
            'cta'   => null,
            'tab'   => $generatingRams ? 'rams' : 'om',
        ];
    } elseif (! $primaryPackage) {
        $nextStep = [
            'icon'  => '↑',
            'title' => 'Upload Quote PDF',
            'desc'  => 'Seed this project with equipment, rooms, and works data by uploading the QuoteWerks PDF.',
            'cta'   => 'Upload Quote',
            'href'  => route('quote-import.create', ['project_id' => $project->id]),
            'tab'   => 'quotes',
        ];
    } elseif ($primaryPackage->status !== \App\Models\ProjectPackage::STATUS_REVIEWED) {
        $nextStep = [
            'icon'  => '✎',
            'title' => 'Review Project Data',
            'desc'  => 'Confirm the parsed scope, equipment, and rooms — this is the canonical source for every document.',
            'cta'   => 'Review Project Data',
            'href'  => route('project-packages.review.show', $primaryPackage),
            'tab'   => 'data',
        ];
    } elseif ($countSurvey === 0) {
        $nextStep = [
            'icon'  => '📍',
            'title' => 'Create Site Survey',
            'desc'  => 'Generate a shareable survey link so the on-site engineer can capture site conditions.',
            'cta'   => 'Create Survey',
            'href'  => route('site-surveys.from-project', $project),
            'tab'   => 'surveys',
        ];
    } elseif ($countRams === 0) {
        $nextStep = [
            'icon'  => '🛡',
            'title' => 'Generate RAMS Document',
            'desc'  => 'Project data is reviewed. Generate the RAMS for this job.',
            'cta'   => 'Create RAMS',
            'form_action' => route('rams.from-project', $project),
            'tab'   => 'rams',
        ];
    } elseif ($countWorksheet === 0) {
        $nextStep = [
            'icon'  => '📋',
            'title' => 'Generate Worksheet',
            'desc'  => 'Build the engineer\'s job card for the install team.',
            'cta'   => 'Generate Worksheet',
            'form_action' => route('worksheets.generate-from-project', $project),
            'tab'   => 'worksheets',
        ];
    } elseif ($countOm === 0) {
        $nextStep = [
            'icon'  => '📘',
            'title' => 'Generate O&M Manual',
            'desc'  => 'Build the handover documentation for the client.',
            'cta'   => 'Generate O&M',
            'form_action' => route('om-manuals.generate-from-project', $project),
            'tab'   => 'om',
        ];
    }

    $defaultTab = $nextStep['tab'] ?? 'surveys';

    $outputs = [
        ['key' => 'rams',       'icon' => '🛡',  'label' => 'RAMS',              'count' => $countRams,      'tab' => 'rams'],
        ['key' => 'worksheet',  'icon' => '📋',  'label' => 'Worksheet',         'count' => $countWorksheet, 'tab' => 'worksheets'],
        ['key' => 'survey',     'icon' => '📍',  'label' => 'Survey',            'count' => $countSurvey,    'tab' => 'surveys'],
        ['key' => 'om',         'icon' => '📘',  'label' => 'O&M',               'count' => $countOm,        'tab' => 'om'],
        ['key' => 'cable',      'icon' => '⚡', 'label' => 'Cable Schedule',     'count' => $countCable,     'tab' => 'cable'],
        ['key' => 'install',    'icon' => '📅',  'label' => 'Install Programme', 'count' => $countInstall,   'tab' => 'install'],
    ];
@endphp

<x-app-shell>

{{-- ══════════════════════════════════════════════════════════════════════════ --}}
{{-- HEADER                                                                     --}}
{{-- ══════════════════════════════════════════════════════════════════════════ --}}
<x-page-header
    :title="$project->name"
    :subtitle="$project->client_name . ($project->site_address ? ' · ' . $project->site_address : '')"
    :status="$project->status"
    :breadcrumb="[
        ['label' => 'Projects', 'url' => route('projects.index')],
        ['label' => $project->name],
    ]">
    <x-slot name="actions">
        {{-- Phase 17 v1.3 — Drawings link (DRAW-06 / DRAW-27 entry point).
             Visible when at least one drawing exists OR a primary package
             exists (engineers can generate the first schematic). Count
             reflects current revisions only (superseded excluded). --}}
        @if ($countDrawings > 0 || $primaryPackage)
            <a href="{{ route('projects.drawings.index', $project) }}"
               class="inline-flex items-center gap-2 border border-gray-300 hover:bg-gray-50 text-gray-700 font-medium px-4 py-2 rounded-lg text-sm transition-colors">
                <span aria-hidden="true">📐</span>
                <span>Drawings</span>
                @if ($countDrawings > 0)
                    <span class="ml-1 inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 text-xs font-semibold rounded-full bg-teal-100 text-teal-700 border border-teal-200">
                        {{ $countDrawings }}
                    </span>
                @endif
            </a>
        @endif

        @if ($primaryPackage)
            <a href="{{ route('project-packages.review.show', $primaryPackage) }}"
               class="inline-flex items-center gap-2 border border-gray-300 hover:bg-gray-50 text-gray-700 font-medium px-4 py-2 rounded-lg text-sm transition-colors">
                <span aria-hidden="true">✎</span>
                <span>Edit Project Data</span>
            </a>
        @endif

        @if ($nextStep && ! empty($nextStep['cta']) && ! empty($nextStep['href']))
            <a href="{{ $nextStep['href'] }}"
               class="inline-flex items-center gap-2 bg-teal-600 hover:bg-teal-700 text-white font-medium px-5 py-2.5 rounded-lg text-sm shadow-sm hover:shadow hover:-translate-y-px active:translate-y-0 active:shadow-sm transition-all duration-150">
                {{ $nextStep['cta'] }}
            </a>
        @elseif ($nextStep && ! empty($nextStep['cta']) && ! empty($nextStep['form_action']))
            <form method="POST" action="{{ $nextStep['form_action'] }}" class="m-0">
                @csrf
                <button type="submit"
                        class="inline-flex items-center gap-2 bg-teal-600 hover:bg-teal-700 text-white font-medium px-5 py-2.5 rounded-lg text-sm shadow-sm hover:shadow hover:-translate-y-px active:translate-y-0 active:shadow-sm transition-all duration-150">
                    {{ $nextStep['cta'] }}
                </button>
            </form>
        @elseif (! $primaryPackage)
            <a href="{{ route('quote-import.create', ['project_id' => $project->id]) }}"
               class="inline-flex items-center gap-2 bg-teal-600 hover:bg-teal-700 text-white font-medium px-5 py-2.5 rounded-lg text-sm shadow-sm hover:shadow hover:-translate-y-px active:translate-y-0 active:shadow-sm transition-all duration-150">
                ↑ Upload Quote
            </a>
        @endif
    </x-slot>
</x-page-header>

{{-- Reopen banner --}}
@if ($project->canReopen())
<x-workflow.blocking-banner title="Project can be Reopened" severity="info">
    This project has been completed or archived. Provide a reason to reopen it and resume work.
    <div class="mt-3">
        <form method="POST" action="{{ route('projects.reopen', $project) }}" class="m-0">
            @csrf
            <div class="flex flex-wrap items-end gap-2">
                <div class="flex-1 min-w-[200px]">
                    <label class="block text-xs font-semibold text-gray-700 mb-1" for="reopen_reason">
                        Reason for Reopening <span class="text-red-600">*</span>
                    </label>
                    <input id="reopen_reason" name="reopen_reason" type="text"
                           class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500"
                           placeholder="e.g. Customer requested additional works" required>
                </div>
                <div class="shrink-0">
                    <button type="submit"
                            class="inline-flex items-center border border-gray-300 hover:bg-gray-50 text-gray-700 px-3 py-2 rounded-md text-sm">
                        Reopen
                    </button>
                </div>
            </div>
        </form>
    </div>
</x-workflow.blocking-banner>
@endif

{{-- ══════════════════════════════════════════════════════════════════════════ --}}
{{-- 2-COLUMN LAYOUT                                                            --}}
{{-- ══════════════════════════════════════════════════════════════════════════ --}}
<div class="psv grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_360px] gap-6 items-start">

    {{-- ─────────────────── MAIN COLUMN ─────────────────── --}}
    <div class="psv__main flex flex-col gap-6 min-w-0">

        {{-- ── Next Step Card (PRIMARY FOCUS) ─────────────────────────────── --}}
        @if ($nextStep)
        <section class="relative bg-gradient-to-r from-teal-50 to-cyan-50 border-2 border-teal-300 rounded-xl p-7 shadow-md ring-1 ring-teal-200/50 flex items-start gap-5 mb-2" role="region" aria-label="Next Step">
            <div class="flex-none w-14 h-14 bg-teal-600 text-white rounded-full flex items-center justify-center text-2xl shadow-sm ring-4 ring-teal-100" aria-hidden="true">
                {{ $nextStep['icon'] }}
            </div>
            <div class="flex-1 min-w-0">
                <div class="text-xs font-bold uppercase tracking-wider text-teal-700 mb-1">Next Step</div>
                <h3 class="text-xl font-bold text-gray-900 leading-tight">{{ $nextStep['title'] }}</h3>
                <p class="text-sm text-gray-600 mt-1.5 max-w-prose leading-relaxed">{{ $nextStep['desc'] }}</p>
            </div>
            <div class="flex-none flex items-center">
                @if (! empty($nextStep['cta']) && ! empty($nextStep['href']))
                    <a href="{{ $nextStep['href'] }}"
                       class="inline-flex items-center gap-2 bg-teal-600 hover:bg-teal-700 text-white font-semibold px-6 py-3 rounded-lg text-base shadow-md hover:shadow-lg hover:-translate-y-px active:translate-y-0 active:shadow-md transition-all duration-150 whitespace-nowrap">
                        {{ $nextStep['cta'] }} →
                    </a>
                @elseif (! empty($nextStep['cta']) && ! empty($nextStep['form_action']))
                    <form method="POST" action="{{ $nextStep['form_action'] }}" class="m-0">
                        @csrf
                        <button type="submit"
                                class="inline-flex items-center gap-2 bg-teal-600 hover:bg-teal-700 text-white font-semibold px-6 py-3 rounded-lg text-base shadow-md hover:shadow-lg hover:-translate-y-px active:translate-y-0 active:shadow-md transition-all duration-150 whitespace-nowrap">
                            {{ $nextStep['cta'] }} →
                        </button>
                    </form>
                @endif
            </div>
        </section>
        @endif

        {{-- ── Workflow Progress (TERTIARY: reference state, not action) ─── --}}
        <div class="psv__workflow">
        <x-section-card title="Project Workflow">
            <x-slot name="actions">
                @if (! $project->isArchived() && $nextStatus)
                    @php $nextLabel = \App\Models\Project::STATUS_LABELS[$nextStatus]; @endphp
                    <form method="POST" action="{{ route('projects.transition', $project) }}" class="m-0">
                        @csrf
                        <input type="hidden" name="to_status" value="{{ $nextStatus }}">
                        <button type="submit"
                                class="inline-flex items-center border border-gray-300 hover:bg-gray-50 text-gray-700 px-3 py-1.5 rounded-md text-sm"
                                onclick="return confirm('Advance project to {{ $nextLabel }}?')">
                            Advance → {{ $nextLabel }}
                        </button>
                    </form>
                @endif
                @if (! $project->isArchived())
                    <form method="POST" action="{{ route('projects.archive', $project) }}" class="m-0">
                        @csrf
                        <button type="submit"
                                class="inline-flex items-center border border-gray-300 hover:bg-gray-50 text-gray-700 px-3 py-1.5 rounded-md text-sm"
                                onclick="return confirm('Archive this project?')">
                            Archive
                        </button>
                    </form>
                @endif
            </x-slot>

            <div class="flex items-center gap-3 overflow-x-auto py-1">
                @foreach ($lifecycle as $i => $step)
                    @php
                        $stepLabel  = \App\Models\Project::STATUS_LABELS[$step];
                        $isActive   = $step === $project->status;
                        $isPast     = $i < $currentIdx;
                    @endphp
                    @if ($isActive)
                        <div class="flex-none inline-flex items-center gap-2 border-2 border-teal-600 text-teal-700 bg-white px-4 py-2 rounded-full text-sm font-semibold whitespace-nowrap">
                            <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-teal-600 text-white text-xs">●</span>
                            {{ $stepLabel }}
                        </div>
                    @elseif ($isPast)
                        <div class="flex-none inline-flex items-center gap-2 bg-teal-600 text-white px-4 py-2 rounded-full text-sm font-medium whitespace-nowrap">
                            <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-white/25 text-white text-xs">✓</span>
                            {{ $stepLabel }}
                        </div>
                    @else
                        <div class="flex-none inline-flex items-center gap-2 bg-gray-100 text-gray-500 border border-gray-200 px-4 py-2 rounded-full text-sm whitespace-nowrap">
                            <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-white text-gray-500 border border-gray-300 text-xs">{{ $i + 1 }}</span>
                            {{ $stepLabel }}
                        </div>
                    @endif

                    @if (! $loop->last)
                        <div class="flex-none w-3 h-px {{ $isPast ? 'bg-teal-600' : 'bg-gray-300' }}"></div>
                    @endif
                @endforeach
            </div>
        </x-section-card>
        </div>{{-- /psv__workflow --}}

        {{-- ── Tabbed Workspace — wrapped in a teal-tinted zone to give the
             page a single visible branded workspace. Inside the teal zone:
              · Tabs strip = bg-teal-50 with thin teal-100 border (rounded card)
              · Active tab = bg-white text-teal-700 border-teal-200 (lifts off the strip)
              · Tables = bg-white rounded-lg border-gray-200 (clean reading surface)
             Active tab is persisted in localStorage per-project so a page reload
             after a Regen / Generate form submit restores the user's last tab
             (fixes "I regen a worksheet and the page defaults to OM"). --}}
        <div x-data="{
                activeTab: (localStorage.getItem('psv-tab-{{ $project->id }}') || '{{ $defaultTab }}'),
                q: '',
                setTab(t) { this.activeTab = t; localStorage.setItem('psv-tab-{{ $project->id }}', t); }
             }" class="ws">

            {{-- Tab strip — flat horizontal nav with bottom-border active state.
                 No background tinting; the underline does the work. SCC v2 style. --}}
            <div class="ws-tabs" role="tablist">
                @php
                    $tabs = [
                        ['key' => 'surveys',    'label' => 'Surveys',           'count' => $countSurvey],
                        ['key' => 'rams',       'label' => 'RAMS',              'count' => $countRams],
                        ['key' => 'worksheets', 'label' => 'Worksheets',        'count' => $countWorksheet],
                        ['key' => 'cable',      'label' => 'Cable Schedule',    'count' => $countCable],
                        ['key' => 'om',         'label' => 'O&M',               'count' => $countOm],
                        ['key' => 'install',    'label' => 'Install Programme', 'count' => $countInstall],
                        ['key' => 'quotes',     'label' => 'Quotes',            'count' => $countQuotes],
                        ['key' => 'data',       'label' => 'Project Data',      'count' => null],
                    ];
                @endphp
                @foreach ($tabs as $t)
                    <button type="button" role="tab" class="ws-tab"
                            @click="setTab('{{ $t['key'] }}')"
                            :class="activeTab==='{{ $t['key'] }}' ? 'is-active' : ''"
                            :aria-selected="activeTab==='{{ $t['key'] }}'">
                        <span class="ws-tab__label">{{ $t['label'] }}</span>
                        @if ($t['count'] !== null && $t['count'] > 0)
                            <span class="ws-tab__count">{{ $t['count'] }}</span>
                        @endif
                    </button>
                @endforeach
            </div>

            {{-- Table container — clean white reading surface inside the teal zone --}}
            <div class="bg-white rounded-lg border border-gray-200 p-6">

                {{-- Search/filter row --}}
                <div class="flex flex-wrap gap-3 items-center mb-5">
                    <div class="relative flex-1 max-w-sm">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none" aria-hidden="true">🔍</span>
                        <input type="text" x-model.debounce.150ms="q"
                               class="w-full pl-9 pr-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500"
                               placeholder="Filter records by name or reference…">
                    </div>
                    <div class="ml-auto flex gap-2 items-center">
                        <span x-show="activeTab==='surveys'" x-cloak>
                            <a href="{{ route('site-surveys.from-project', $project) }}"
                               class="inline-flex items-center border border-gray-300 hover:bg-gray-50 text-gray-700 px-3 py-1.5 rounded-md text-sm">
                                + New Survey
                            </a>
                        </span>
                        <span x-show="activeTab==='worksheets'" x-cloak>
                            <form method="POST" action="{{ route('worksheets.generate-from-project', $project) }}" class="m-0 inline-block">
                                @csrf
                                <button type="submit"
                                        class="inline-flex items-center border border-gray-300 hover:bg-gray-50 text-gray-700 px-3 py-1.5 rounded-md text-sm">
                                    + Generate Worksheet
                                </button>
                            </form>
                        </span>
                        <span x-show="activeTab==='rams'" x-cloak>
                            @if ($headerAwaitingRams)
                                <a href="{{ route('rams.quote-review.show', $headerAwaitingRams) }}"
                                   class="inline-flex items-center bg-teal-600 hover:bg-teal-700 text-white px-3 py-1.5 rounded-md text-sm font-medium shadow-sm transition-all duration-150 hover:shadow hover:-translate-y-px active:translate-y-0 active:shadow-sm">
                                    ✎ Review & Generate
                                </a>
                            @elseif ($generatingRams)
                                <span class="inline-flex items-center gap-2 text-sm text-gray-500"><svg class="animate-spin h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 12a9 9 0 11-6.219-8.56" stroke-linecap="round"/></svg><span>Processing…</span></span>
                            @elseif ($primaryPackage && $primaryPackage->status === \App\Models\ProjectPackage::STATUS_REVIEWED)
                                <form method="POST" action="{{ route('rams.from-project', $project) }}" class="m-0 inline-block"
                                      onsubmit="return {{ $hasCompletedRams ? "confirm('Generate a new RAMS document from the current project data?')" : 'true' }};">
                                    @csrf
                                    <button type="submit"
                                            class="inline-flex items-center border border-gray-300 hover:bg-gray-50 text-gray-700 px-3 py-1.5 rounded-md text-sm">
                                        + {{ $hasCompletedRams ? 'New Version' : 'Create RAMS' }}
                                    </button>
                                </form>
                            @elseif ($primaryPackage)
                                <a href="{{ route('project-packages.review.show', $primaryPackage) }}"
                                   class="inline-flex items-center border border-gray-300 hover:bg-gray-50 text-gray-700 px-3 py-1.5 rounded-md text-sm">
                                    ✎ Review Quote Data
                                </a>
                            @else
                                <a href="{{ route('rams.create', ['project_id' => $project->id]) }}"
                                   class="inline-flex items-center border border-gray-300 hover:bg-gray-50 text-gray-700 px-3 py-1.5 rounded-md text-sm">
                                    + Create RAMS
                                </a>
                            @endif
                        </span>
                        <span x-show="activeTab==='om'" x-cloak>
                            @if ($generatingOm)
                                <span class="inline-flex items-center gap-2 text-sm text-gray-500"><svg class="animate-spin h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 12a9 9 0 11-6.219-8.56" stroke-linecap="round"/></svg><span>Processing…</span></span>
                            @elseif ($primaryPackage && $primaryPackage->status === \App\Models\ProjectPackage::STATUS_REVIEWED)
                                <form method="POST" action="{{ route('om-manuals.generate-from-project', $project) }}" class="m-0 inline-block">
                                    @csrf
                                    <button type="submit"
                                            class="inline-flex items-center border border-gray-300 hover:bg-gray-50 text-gray-700 px-3 py-1.5 rounded-md text-sm">
                                        + {{ $hasCompletedOm ? 'New Version' : 'Generate O&M' }}
                                    </button>
                                </form>
                            @elseif ($primaryPackage)
                                <a href="{{ route('project-packages.review.show', $primaryPackage) }}"
                                   class="inline-flex items-center border border-gray-300 hover:bg-gray-50 text-gray-700 px-3 py-1.5 rounded-md text-sm">
                                    ✎ Review Quote Data
                                </a>
                            @else
                                <a href="{{ route('om-manuals.create', ['project_id' => $project->id]) }}"
                                   class="inline-flex items-center border border-gray-300 hover:bg-gray-50 text-gray-700 px-3 py-1.5 rounded-md text-sm">
                                    + New O&M
                                </a>
                            @endif
                        </span>
                        <span x-show="activeTab==='cable'" x-cloak>
                            <form method="POST" action="{{ route('cable-schedules.generate-from-project', $project) }}" class="m-0 inline-block">
                                @csrf
                                <button type="submit"
                                        class="inline-flex items-center border border-gray-300 hover:bg-gray-50 text-gray-700 px-3 py-1.5 rounded-md text-sm">
                                    + Generate Cable Schedule
                                </button>
                            </form>
                        </span>
                        <span x-show="activeTab==='install'" x-cloak>
                            <form method="POST" action="{{ route('install-programmes.generate', $project) }}" class="m-0 inline-block">
                                @csrf
                                <button type="submit"
                                        class="inline-flex items-center border border-gray-300 hover:bg-gray-50 text-gray-700 px-3 py-1.5 rounded-md text-sm">
                                    + Generate Install Programme
                                </button>
                            </form>
                        </span>
                        <span x-show="activeTab==='quotes'" x-cloak>
                            <a href="{{ route('quote-import.create', ['project_id' => $project->id]) }}"
                               class="inline-flex items-center border border-gray-300 hover:bg-gray-50 text-gray-700 px-3 py-1.5 rounded-md text-sm">
                                ↑ Upload New Quote
                            </a>
                        </span>
                    </div>
                </div>

                {{-- ───── Surveys panel ───── --}}
                <div x-show="activeTab==='surveys'" x-cloak role="tabpanel">
                    @if ($project->siteSurveys->isEmpty())
                        <x-empty-state title="No site surveys yet"
                            description="Create a survey to share a pre-filled form with your on-site engineer."
                            :href="route('site-surveys.from-project', $project)"
                            action="Create Survey"/>
                    @else
                        <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left">
                            <thead class="text-xs uppercase tracking-wide text-gray-500 border-b border-gray-200">
                                <tr>
                                    <th class="py-2 px-3 font-medium">Survey</th>
                                    <th class="py-2 px-3 font-medium w-32">Status</th>
                                    <th class="py-2 px-3 font-medium w-28 whitespace-nowrap">Created</th>
                                    <th class="py-2 px-3 font-medium">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($project->siteSurveys->sortByDesc('created_at') as $survey)
                                <tr data-name="{{ \Illuminate\Support\Str::lower('Site Survey #'.$survey->id.' '.($survey->surveyor_name ?? '')) }}"
                                    class="border-b border-gray-100 last:border-0"
                                    x-show="q === '' || $el.dataset.name.includes(q.toLowerCase())">
                                    <td class="py-3 px-3">
                                        <div class="font-medium text-gray-900">Site Survey #{{ $survey->id }}</div>
                                        @if ($survey->surveyor_name)<div class="text-xs text-gray-500">By: {{ $survey->surveyor_name }}</div>@endif
                                        @if ($survey->survey_date)<div class="text-xs text-gray-500">{{ $survey->survey_date->format('d M Y') }}</div>@endif
                                    </td>
                                    <td class="py-3 px-3">
                                        <x-status-badge
                                            :status="$survey->status ?? 'draft'"
                                            :label="ucfirst($survey->status ?? 'draft') . ($survey->isSubmitted() ? ' · Submitted' : '')" />
                                    </td>
                                    <td class="py-3 px-3 text-gray-500 whitespace-nowrap">
                                        <div>{{ $survey->created_at->format('d M Y') }}</div>
                                        <div class="text-xs">{{ $survey->created_at->format('H:i') }}</div>
                                    </td>
                                    <td class="py-3 px-3">
                                        {{-- Visible primary actions; secondary actions live in the 3-dot menu. --}}
                                        <div class="flex flex-wrap gap-2 items-center">
                                            <a href="{{ route('site-surveys.show', $survey) }}"
                                               class="inline-flex items-center border border-gray-300 hover:bg-gray-50 text-gray-700 px-3 py-1.5 rounded-md text-sm">View</a>
                                            @if (! $survey->isCompleted())
                                                <a href="{{ route('site-surveys.edit', $survey) }}"
                                                   class="inline-flex items-center border border-gray-300 hover:bg-gray-50 text-gray-700 px-3 py-1.5 rounded-md text-sm">Edit</a>
                                                <form method="POST" action="{{ route('site-surveys.complete', $survey) }}" class="m-0 inline-block"
                                                      onsubmit="return confirm('Mark this survey as completed?');">
                                                    @csrf
                                                    <button type="submit"
                                                            class="inline-flex items-center bg-teal-600 hover:bg-teal-700 text-white px-3 py-1.5 rounded-md text-sm font-medium shadow-sm transition-all duration-150 hover:shadow hover:-translate-y-px active:translate-y-0 active:shadow-sm">✓ Complete</button>
                                                </form>
                                            @endif
                                            <a href="{{ route('site-surveys.show', $survey) }}?chat=1"
                                               class="inline-flex items-center bg-teal-600 hover:bg-teal-700 text-white px-3 py-1.5 rounded-md text-sm font-medium shadow-sm transition-all duration-150 hover:shadow hover:-translate-y-px active:translate-y-0 active:shadow-sm" title="Edit content via AI chat">✎ AI Chat</a>

                                            <x-row-actions-menu>
                                                @if ($survey->access_token && ! $survey->isTokenExpired())
                                                    <button type="button"
                                                            class="row-actions-item"
                                                            onclick="copyEngineerLink('{{ $survey->publicUrl() }}', this)">
                                                        <span class="row-actions-item__icon" aria-hidden="true">⎘</span>
                                                        <span>Copy public link</span>
                                                    </button>
                                                    <a href="{{ $survey->publicUrl() }}" target="_blank" class="row-actions-item">
                                                        <span class="row-actions-item__icon" aria-hidden="true">↗</span>
                                                        <span>Open public link</span>
                                                    </a>
                                                    <div class="row-actions-divider"></div>
                                                @endif
                                                <a href="{{ route('site-surveys.pdf', $survey) }}" target="_blank" class="row-actions-item">
                                                    <span class="row-actions-item__icon" aria-hidden="true">↓</span>
                                                    <span>Download PDF</span>
                                                </a>
                                                <div class="row-actions-divider"></div>
                                                <form method="POST" action="{{ route('site-surveys.destroy', $survey) }}"
                                                      onsubmit="return confirm('Delete this survey?');">
                                                    @csrf @method('DELETE')
                                                    <button type="submit" class="row-actions-item row-actions-item--danger">
                                                        <span class="row-actions-item__icon" aria-hidden="true">🗑</span>
                                                        <span>Delete survey</span>
                                                    </button>
                                                </form>
                                            </x-row-actions-menu>
                                        </div>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                        </div>
                    @endif
                </div>

                {{-- ───── RAMS panel ───── --}}
                <div x-show="activeTab==='rams'" x-cloak role="tabpanel">
                    @if ($project->ramsDocuments->isEmpty())
                        @if ($primaryPackage && $primaryPackage->status === \App\Models\ProjectPackage::STATUS_REVIEWED)
                            <x-empty-state title="No RAMS documents yet"
                                description="Project data has been reviewed and is ready for RAMS generation."/>
                        @elseif ($primaryPackage)
                            <x-empty-state title="No RAMS documents yet"
                                description="Review quote data to enable RAMS generation."
                                :href="route('project-packages.review.show', $primaryPackage)"
                                action="Review Quote Data"/>
                        @else
                            <x-empty-state title="No RAMS documents yet"
                                description="Upload a quote first, then review it to enable RAMS generation."
                                :href="route('quote-import.create', ['project_id' => $project->id])"
                                action="Upload Quote"/>
                        @endif
                    @else
                        @php $ramsSorted = $project->ramsDocuments->sortByDesc('created_at')->values(); @endphp
                        <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left">
                            <thead class="text-xs uppercase tracking-wide text-gray-500 border-b border-gray-200">
                                <tr>
                                    <th class="py-2 px-3 font-medium w-14">Ver.</th>
                                    <th class="py-2 px-3 font-medium">Project / Ref</th>
                                    <th class="py-2 px-3 font-medium w-32">Status</th>
                                    <th class="py-2 px-3 font-medium w-28 whitespace-nowrap">Created</th>
                                    <th class="py-2 px-3 font-medium">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($ramsSorted as $rams)
                                    @php
                                        $status     = $rams->status;
                                        $sup        = $rams->isSuperseded();
                                    @endphp
                                    <tr data-name="{{ \Illuminate\Support\Str::lower(($rams->project_name ?? '').' '.($rams->project_ref ?? '')) }}"
                                        class="border-b border-gray-100 last:border-0 {{ $sup ? 'opacity-50' : '' }}"
                                        x-show="q === '' || $el.dataset.name.includes(q.toLowerCase())">
                                        <td class="py-3 px-3 text-center font-semibold text-teal-600">v{{ $ramsVersionMap[$rams->id] ?? '—' }}</td>
                                        <td class="py-3 px-3">
                                            <div class="font-medium text-gray-900">{{ $rams->project_name ?: '—' }}</div>
                                            @if ($rams->project_ref)<div class="text-xs text-gray-500">{{ $rams->project_ref }}</div>@endif
                                            @if ($sup)<div class="text-xs text-red-600">Superseded</div>@endif
                                        </td>
                                        <td class="py-3 px-3"><x-status-badge :status="$status" /></td>
                                        <td class="py-3 px-3 text-gray-500 whitespace-nowrap">
                                            <div>{{ $rams->created_at->format('d M Y') }}</div>
                                            <div class="text-xs">{{ $rams->created_at->format('H:i') }}</div>
                                        </td>
                                        <td class="py-3 px-3">
                                            <div class="flex flex-wrap gap-2 items-center {{ $sup ? 'pointer-events-none' : '' }}">
                                                @if ($status === \App\Models\RamsDocument::STATUS_AWAITING_REVIEW)
                                                    <a href="{{ route('rams.quote-review.show', $rams) }}"
                                                       class="inline-flex items-center bg-teal-600 hover:bg-teal-700 text-white px-3 py-1.5 rounded-md text-sm font-medium shadow-sm transition-all duration-150 hover:shadow hover:-translate-y-px active:translate-y-0 active:shadow-sm">✎ Review</a>

                                                @elseif ($status === \App\Models\RamsDocument::STATUS_APPROVED)
                                                    <form method="POST" action="{{ route('rams.retry-generation', $rams) }}" class="m-0 inline-block">
                                                        @csrf
                                                        <button type="submit"
                                                                class="inline-flex items-center bg-teal-600 hover:bg-teal-700 text-white px-3 py-1.5 rounded-md text-sm font-medium shadow-sm transition-all duration-150 hover:shadow hover:-translate-y-px active:translate-y-0 active:shadow-sm">▶ Generate</button>
                                                    </form>

                                                @elseif (in_array($status, [
                                                    \App\Models\RamsDocument::STATUS_UPLOADED,
                                                    \App\Models\RamsDocument::STATUS_APPROVED_FOR_GENERATION,
                                                    \App\Models\RamsDocument::STATUS_GENERATING,
                                                ], true))
                                                    <span class="inline-flex items-center gap-2 text-sm text-gray-500"><svg class="animate-spin h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 12a9 9 0 11-6.219-8.56" stroke-linecap="round"/></svg><span>Processing…</span></span>

                                                @elseif ($status === \App\Models\RamsDocument::STATUS_COMPLETED && $rams->filename)
                                                    <a href="{{ route('rams.review', $rams) }}"
                                                       class="inline-flex items-center bg-gray-900 hover:bg-gray-800 text-white px-3 py-1.5 rounded-md text-sm font-medium shadow-sm transition-all duration-150 hover:shadow hover:-translate-y-px active:translate-y-0 active:shadow-sm">View</a>
                                                    <a href="{{ route('rams.review', $rams) }}?chat=1"
                                                       class="inline-flex items-center bg-teal-600 hover:bg-teal-700 text-white px-3 py-1.5 rounded-md text-sm font-medium shadow-sm transition-all duration-150 hover:shadow hover:-translate-y-px active:translate-y-0 active:shadow-sm" title="Edit content via AI chat">✎ AI Chat</a>
                                                    <x-row-actions-menu>
                                                        <a href="{{ route('rams.download', $rams) }}" class="row-actions-item">
                                                            <span class="row-actions-item__icon" aria-hidden="true">↓</span>
                                                            <span>Download DOCX</span>
                                                        </a>
                                                        <a href="{{ route('rams.download-pdf', $rams) }}" class="row-actions-item"
                                                           onclick="triggerFileDownload(this.href); return false;">
                                                            <span class="row-actions-item__icon" aria-hidden="true">↓</span>
                                                            <span>Download PDF</span>
                                                        </a>
                                                        <div class="row-actions-divider"></div>
                                                        <form method="POST" action="{{ route('rams.retry-generation', $rams) }}"
                                                              onsubmit="return confirm('Rebuild the DOCX from the approved data?');">
                                                            @csrf
                                                            <button type="submit" class="row-actions-item">
                                                                <span class="row-actions-item__icon" aria-hidden="true">↻</span>
                                                                <span>Regenerate document</span>
                                                            </button>
                                                        </form>
                                                        <div class="row-actions-divider"></div>
                                                        <form method="POST" action="{{ route('rams.destroy', $rams) }}"
                                                              onsubmit="return confirm('Delete this RAMS document? Admins can restore it later.');">
                                                            @csrf @method('DELETE')
                                                            <button type="submit" class="row-actions-item row-actions-item--danger">
                                                                <span class="row-actions-item__icon" aria-hidden="true">🗑</span>
                                                                <span>Delete RAMS</span>
                                                            </button>
                                                        </form>
                                                    </x-row-actions-menu>
                                                    @php $ramsHasMenu = true; @endphp

                                                @elseif ($status === \App\Models\RamsDocument::STATUS_FAILED)
                                                    {{-- PRIMARY: Retry — failed rows have nothing else to act on --}}
                                                    <span class="inline-flex items-center text-sm text-red-700 font-medium">⚠ Failed</span>
                                                    @if (! empty($rams->reviewed_data))
                                                        <form method="POST" action="{{ route('rams.retry-generation', $rams) }}" class="m-0 inline-block">
                                                            @csrf
                                                            <button type="submit"
                                                                    class="inline-flex items-center bg-teal-600 hover:bg-teal-700 text-white px-3 py-1.5 rounded-md text-sm font-medium shadow-sm transition-all duration-150 hover:shadow hover:-translate-y-px active:translate-y-0 active:shadow-sm">↻ Retry</button>
                                                        </form>
                                                    @else
                                                        <form method="POST" action="{{ route('rams.retry-extraction', $rams) }}" class="m-0 inline-block">
                                                            @csrf
                                                            <button type="submit"
                                                                    class="inline-flex items-center bg-teal-600 hover:bg-teal-700 text-white px-3 py-1.5 rounded-md text-sm font-medium shadow-sm transition-all duration-150 hover:shadow hover:-translate-y-px active:translate-y-0 active:shadow-sm">↻ Retry</button>
                                                        </form>
                                                    @endif

                                                @elseif ($rams->filename && in_array($status, [
                                                    \App\Models\RamsDocument::STATUS_FOR_REVIEW,
                                                    \App\Models\RamsDocument::STATUS_DRAFT,
                                                ], true))
                                                    <a href="{{ route('rams.review', $rams) }}"
                                                       class="inline-flex items-center bg-gray-900 hover:bg-gray-800 text-white px-3 py-1.5 rounded-md text-sm font-medium shadow-sm transition-all duration-150 hover:shadow hover:-translate-y-px active:translate-y-0 active:shadow-sm">View</a>
                                                    <a href="{{ route('rams.review', $rams) }}?chat=1"
                                                       class="inline-flex items-center bg-teal-600 hover:bg-teal-700 text-white px-3 py-1.5 rounded-md text-sm font-medium shadow-sm transition-all duration-150 hover:shadow hover:-translate-y-px active:translate-y-0 active:shadow-sm" title="Edit content via AI chat">✎ AI Chat</a>
                                                    <x-row-actions-menu>
                                                        <a href="{{ route('rams.download', $rams) }}" class="row-actions-item">
                                                            <span class="row-actions-item__icon" aria-hidden="true">↓</span>
                                                            <span>Download DOCX</span>
                                                        </a>
                                                        <a href="{{ route('rams.download-pdf', $rams) }}" class="row-actions-item"
                                                           onclick="triggerFileDownload(this.href); return false;">
                                                            <span class="row-actions-item__icon" aria-hidden="true">↓</span>
                                                            <span>Download PDF</span>
                                                        </a>
                                                        <div class="row-actions-divider"></div>
                                                        <form method="POST" action="{{ route('rams.retry-generation', $rams) }}"
                                                              onsubmit="return confirm('Rebuild the DOCX from the approved data?');">
                                                            @csrf
                                                            <button type="submit" class="row-actions-item">
                                                                <span class="row-actions-item__icon" aria-hidden="true">↻</span>
                                                                <span>Regenerate document</span>
                                                            </button>
                                                        </form>
                                                        <div class="row-actions-divider"></div>
                                                        <form method="POST" action="{{ route('rams.destroy', $rams) }}"
                                                              onsubmit="return confirm('Delete this RAMS document? Admins can restore it later.');">
                                                            @csrf @method('DELETE')
                                                            <button type="submit" class="row-actions-item row-actions-item--danger">
                                                                <span class="row-actions-item__icon" aria-hidden="true">🗑</span>
                                                                <span>Delete RAMS</span>
                                                            </button>
                                                        </form>
                                                    </x-row-actions-menu>
                                                    @php $ramsHasMenu = true; @endphp
                                                @endif

                                                @if (empty($ramsHasMenu))
                                                    <x-row-actions-menu>
                                                        <form method="POST" action="{{ route('rams.destroy', $rams) }}"
                                                              onsubmit="return confirm('Delete this RAMS document? Admins can restore it later.');">
                                                            @csrf @method('DELETE')
                                                            <button type="submit" class="row-actions-item row-actions-item--danger">
                                                                <span class="row-actions-item__icon" aria-hidden="true">🗑</span>
                                                                <span>Delete RAMS</span>
                                                            </button>
                                                        </form>
                                                    </x-row-actions-menu>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        </div>
                    @endif
                </div>

                {{-- ───── Worksheets panel ───── --}}
                <div x-show="activeTab==='worksheets'" x-cloak role="tabpanel">
                    @if ($project->worksheets->isEmpty())
                        <x-empty-state title="No worksheets yet"
                            description="Generate a worksheet to share an engineer job card with photo upload and client sign-off."/>
                    @else
                        <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left">
                            <thead class="text-xs uppercase tracking-wide text-gray-500 border-b border-gray-200">
                                <tr>
                                    <th class="py-2 px-3 font-medium">Worksheet</th>
                                    <th class="py-2 px-3 font-medium w-32">Status</th>
                                    <th class="py-2 px-3 font-medium w-28 whitespace-nowrap">Created</th>
                                    <th class="py-2 px-3 font-medium">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($project->worksheets->sortByDesc('created_at') as $ws)
                                <tr data-name="{{ \Illuminate\Support\Str::lower(($ws->project_name ?? '').' '.($ws->project_ref ?? '')) }}"
                                    class="border-b border-gray-100 last:border-0"
                                    x-show="q === '' || $el.dataset.name.includes(q.toLowerCase())">
                                    <td class="py-3 px-3">
                                        <div class="font-medium text-gray-900">{{ $ws->project_name ?: ('Worksheet #'.$ws->id) }}</div>
                                        @if ($ws->project_ref)<div class="text-xs text-gray-500">{{ $ws->project_ref }}</div>@endif
                                    </td>
                                    <td class="py-3 px-3"><x-status-badge :status="$ws->status" :label="$ws->statusLabel()" /></td>
                                    <td class="py-3 px-3 text-gray-500 whitespace-nowrap">
                                        <div>{{ $ws->created_at->format('d M Y') }}</div>
                                        <div class="text-xs">{{ $ws->created_at->format('H:i') }}</div>
                                    </td>
                                    <td class="py-3 px-3">
                                        <div class="flex flex-wrap gap-2 items-center">
                                            <a href="{{ route('worksheets.show', $ws) }}"
                                               class="inline-flex items-center bg-gray-900 hover:bg-gray-800 text-white px-3 py-1.5 rounded-md text-sm font-medium shadow-sm transition-all duration-150 hover:shadow hover:-translate-y-px active:translate-y-0 active:shadow-sm">View</a>
                                            @if ($ws->isGenerated())
                                                <a href="{{ route('worksheets.show', $ws) }}?chat=1"
                                                   class="inline-flex items-center bg-teal-600 hover:bg-teal-700 text-white px-3 py-1.5 rounded-md text-sm font-medium shadow-sm transition-all duration-150 hover:shadow hover:-translate-y-px active:translate-y-0 active:shadow-sm" title="Edit content via AI chat">✎ AI Chat</a>
                                            @endif

                                            <x-row-actions-menu>
                                                @if ($ws->access_token)
                                                    <button type="button"
                                                            class="row-actions-item"
                                                            onclick="copyEngineerLink('{{ $ws->publicUrl() }}', this)">
                                                        <span class="row-actions-item__icon" aria-hidden="true">⎘</span>
                                                        <span>Copy public link</span>
                                                    </button>
                                                    <a href="{{ $ws->publicUrl() }}" target="_blank" class="row-actions-item">
                                                        <span class="row-actions-item__icon" aria-hidden="true">↗</span>
                                                        <span>Open public link</span>
                                                    </a>
                                                    <div class="row-actions-divider"></div>
                                                @endif
                                                @if ($ws->isGenerated())
                                                    <a href="{{ route('worksheets.download', $ws) }}" target="_blank" class="row-actions-item">
                                                        <span class="row-actions-item__icon" aria-hidden="true">↓</span>
                                                        <span>Download DOCX</span>
                                                    </a>
                                                    <div class="row-actions-divider"></div>
                                                @endif
                                                <form method="POST" action="{{ route('worksheets.retry-generation', $ws) }}"
                                                      onsubmit="return confirm('Rebuild this worksheet from the current project data?');">
                                                    @csrf
                                                    <button type="submit" class="row-actions-item">
                                                        <span class="row-actions-item__icon" aria-hidden="true">↻</span>
                                                        <span>Regenerate worksheet</span>
                                                    </button>
                                                </form>
                                                <div class="row-actions-divider"></div>
                                                <form method="POST" action="{{ route('worksheets.destroy', $ws) }}"
                                                      onsubmit="return confirm('Delete this worksheet?');">
                                                    @csrf @method('DELETE')
                                                    <button type="submit" class="row-actions-item row-actions-item--danger">
                                                        <span class="row-actions-item__icon" aria-hidden="true">🗑</span>
                                                        <span>Delete worksheet</span>
                                                    </button>
                                                </form>
                                            </x-row-actions-menu>
                                        </div>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                        </div>
                    @endif
                </div>

                {{-- ───── Cable Schedule panel ───── --}}
                <div x-show="activeTab==='cable'" x-cloak role="tabpanel">
                    @if ($project->cableSchedules->isEmpty())
                        <x-empty-state title="No cable schedules yet"
                            description="Generate a cable schedule once project data has been reviewed."/>
                    @else
                        <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left">
                            <thead class="text-xs uppercase tracking-wide text-gray-500 border-b border-gray-200">
                                <tr>
                                    <th class="py-2 px-3 font-medium">Reference / Name</th>
                                    <th class="py-2 px-3 font-medium w-32">Status</th>
                                    <th class="py-2 px-3 font-medium w-28 whitespace-nowrap">Date</th>
                                    <th class="py-2 px-3 font-medium">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($project->cableSchedules->sortByDesc('updated_at') as $cs)
                                <tr data-name="{{ \Illuminate\Support\Str::lower(($cs->project_name ?? '').' '.($cs->name ?? '')) }}"
                                    class="border-b border-gray-100 last:border-0"
                                    x-show="q === '' || $el.dataset.name.includes(q.toLowerCase())">
                                    <td class="py-3 px-3 text-gray-900">{{ \Illuminate\Support\Str::limit($cs->project_name ?? $cs->name ?? ('Record #'.$cs->id), 55) }}</td>
                                    <td class="py-3 px-3">
                                        @if (isset($cs->status))<x-status-badge :status="$cs->status" />@else<span class="text-gray-400">—</span>@endif
                                    </td>
                                    <td class="py-3 px-3 text-gray-500 whitespace-nowrap">{{ $cs->updated_at->diffForHumans() }}</td>
                                    <td class="py-3 px-3">
                                        <div class="flex flex-wrap gap-2 items-center">
                                            <a href="{{ route('cable-schedules.edit', $cs) }}"
                                               class="inline-flex items-center bg-gray-900 hover:bg-gray-800 text-white px-3 py-1.5 rounded-md text-sm font-medium shadow-sm transition-all duration-150 hover:shadow hover:-translate-y-px active:translate-y-0 active:shadow-sm">View</a>
                                            <a href="{{ route('cable-schedules.edit', $cs) }}?chat=1"
                                               class="inline-flex items-center bg-teal-600 hover:bg-teal-700 text-white px-3 py-1.5 rounded-md text-sm font-medium shadow-sm transition-all duration-150 hover:shadow hover:-translate-y-px active:translate-y-0 active:shadow-sm" title="Edit content via AI chat">✎ AI Chat</a>

                                            <x-row-actions-menu>
                                                @if (! empty($cs->filename))
                                                    <a href="{{ route('cable-schedules.download', $cs) }}" target="_blank" class="row-actions-item">
                                                        <span class="row-actions-item__icon" aria-hidden="true">↓</span>
                                                        <span>Download XLSX</span>
                                                    </a>
                                                    <div class="row-actions-divider"></div>
                                                @endif
                                                <form method="POST" action="{{ route('cable-schedules.retry-generation', $cs) }}">
                                                    @csrf
                                                    <button type="submit" class="row-actions-item">
                                                        <span class="row-actions-item__icon" aria-hidden="true">↻</span>
                                                        <span>Regenerate schedule</span>
                                                    </button>
                                                </form>
                                                <div class="row-actions-divider"></div>
                                                <form method="POST" action="{{ route('cable-schedules.destroy', $cs) }}"
                                                      onsubmit="return confirm('Delete this cable schedule?');">
                                                    @csrf @method('DELETE')
                                                    <button type="submit" class="row-actions-item row-actions-item--danger">
                                                        <span class="row-actions-item__icon" aria-hidden="true">🗑</span>
                                                        <span>Delete schedule</span>
                                                    </button>
                                                </form>
                                            </x-row-actions-menu>
                                        </div>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                        </div>
                    @endif
                </div>

                {{-- ───── O&M panel ───── --}}
                <div x-show="activeTab==='om'" x-cloak role="tabpanel">
                    @if ($project->omManuals->isEmpty())
                        @if ($primaryPackage && $primaryPackage->status === \App\Models\ProjectPackage::STATUS_REVIEWED)
                            <x-empty-state title="No O&M manuals yet"
                                description="Project data is reviewed and ready for O&M generation."/>
                        @elseif ($primaryPackage)
                            <x-empty-state title="No O&M manuals yet"
                                description="Review quote data to enable O&M generation."
                                :href="route('project-packages.review.show', $primaryPackage)"
                                action="Review Quote Data"/>
                        @else
                            <x-empty-state title="No O&M manuals yet"
                                description="Upload a quote first to enable O&M generation."/>
                        @endif
                    @else
                        <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left">
                            <thead class="text-xs uppercase tracking-wide text-gray-500 border-b border-gray-200">
                                <tr>
                                    <th class="py-2 px-3 font-medium">Manual</th>
                                    <th class="py-2 px-3 font-medium w-32">Status</th>
                                    <th class="py-2 px-3 font-medium w-28 whitespace-nowrap">Created</th>
                                    <th class="py-2 px-3 font-medium">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($project->omManuals->sortByDesc('created_at') as $manual)
                                <tr data-name="{{ \Illuminate\Support\Str::lower(($manual->project_name ?? '').' '.($manual->project_ref ?? '')) }}"
                                    class="border-b border-gray-100 last:border-0"
                                    x-show="q === '' || $el.dataset.name.includes(q.toLowerCase())">
                                    <td class="py-3 px-3">
                                        <div class="font-medium text-gray-900">{{ $manual->project_name ?? 'O&M Manual #'.$manual->id }}</div>
                                        @if ($manual->project_ref)<div class="text-xs text-gray-500">{{ $manual->project_ref }}</div>@endif
                                    </td>
                                    <td class="py-3 px-3"><x-status-badge :status="$manual->status" :label="$manual->statusLabel()" /></td>
                                    <td class="py-3 px-3 text-gray-500 whitespace-nowrap">
                                        <div>{{ $manual->created_at->format('d M Y') }}</div>
                                        <div class="text-xs">{{ $manual->created_at->format('H:i') }}</div>
                                    </td>
                                    <td class="py-3 px-3">
                                        <div class="flex flex-wrap gap-2 items-center">
                                            @if ($manual->status === \App\Models\OmManual::STATUS_GENERATING)
                                                <span class="inline-flex items-center gap-2 text-sm text-gray-500"><svg class="animate-spin h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 12a9 9 0 11-6.219-8.56" stroke-linecap="round"/></svg><span>Processing…</span></span>
                                            @elseif ($manual->status === \App\Models\OmManual::STATUS_FAILED)
                                                {{-- PRIMARY: Retry — failed rows have nothing else to act on --}}
                                                <span class="inline-flex items-center text-sm text-red-700 font-medium" title="{{ $manual->error_message }}">⚠ Failed</span>
                                                @if (! empty($manual->extracted_data))
                                                    <form method="POST" action="{{ route('om-manuals.retry-generation', $manual) }}" class="m-0 inline-block">
                                                        @csrf
                                                        <button type="submit"
                                                                class="inline-flex items-center bg-teal-600 hover:bg-teal-700 text-white px-3 py-1.5 rounded-md text-sm font-medium shadow-sm transition-all duration-150 hover:shadow hover:-translate-y-px active:translate-y-0 active:shadow-sm">↻ Retry</button>
                                                    </form>
                                                @endif
                                            @elseif ($manual->isGenerated())
                                                <a href="{{ route('om-manuals.edit', $manual) }}"
                                                   class="inline-flex items-center bg-gray-900 hover:bg-gray-800 text-white px-3 py-1.5 rounded-md text-sm font-medium shadow-sm transition-all duration-150 hover:shadow hover:-translate-y-px active:translate-y-0 active:shadow-sm">View</a>
                                                <a href="{{ route('om-manuals.edit', $manual) }}?chat=1"
                                                   class="inline-flex items-center bg-teal-600 hover:bg-teal-700 text-white px-3 py-1.5 rounded-md text-sm font-medium shadow-sm transition-all duration-150 hover:shadow hover:-translate-y-px active:translate-y-0 active:shadow-sm" title="Edit content via AI chat">✎ AI Chat</a>

                                                <x-row-actions-menu>
                                                    @if ($manual->project_id)
                                                        <a href="{{ route('om-manuals.edit-devices', $manual) }}" class="row-actions-item">
                                                            <span class="row-actions-item__icon" aria-hidden="true">📋</span>
                                                            <span>Manage asset data</span>
                                                        </a>
                                                        <div class="row-actions-divider"></div>
                                                    @endif
                                                    <a href="{{ route('om-manuals.download', $manual) }}" class="row-actions-item">
                                                        <span class="row-actions-item__icon" aria-hidden="true">↓</span>
                                                        <span>Download DOCX</span>
                                                    </a>
                                                    <a href="{{ route('om-manuals.download-pdf', $manual) }}" class="row-actions-item">
                                                        <span class="row-actions-item__icon" aria-hidden="true">↓</span>
                                                        <span>Download PDF</span>
                                                    </a>
                                                    <div class="row-actions-divider"></div>
                                                    <form method="POST" action="{{ route('om-manuals.retry-generation', $manual) }}"
                                                          onsubmit="return confirm('Rebuild this O&M manual from the existing data?');">
                                                        @csrf
                                                        <button type="submit" class="row-actions-item">
                                                            <span class="row-actions-item__icon" aria-hidden="true">↻</span>
                                                            <span>Regenerate manual</span>
                                                        </button>
                                                    </form>
                                                    <div class="row-actions-divider"></div>
                                                    <form method="POST" action="{{ route('om-manuals.destroy', $manual) }}"
                                                          onsubmit="return confirm('Delete this O&M manual?');">
                                                        @csrf @method('DELETE')
                                                        <button type="submit" class="row-actions-item row-actions-item--danger">
                                                            <span class="row-actions-item__icon" aria-hidden="true">🗑</span>
                                                            <span>Delete manual</span>
                                                        </button>
                                                    </form>
                                                </x-row-actions-menu>
                                                @php $omHasMenu = true; @endphp
                                            @elseif ($manual->status === \App\Models\OmManual::STATUS_EXTRACTED)
                                                <a href="{{ route('om-manuals.edit', $manual) }}"
                                                   class="inline-flex items-center bg-teal-600 hover:bg-teal-700 text-white px-3 py-1.5 rounded-md text-sm font-medium shadow-sm transition-all duration-150 hover:shadow hover:-translate-y-px active:translate-y-0 active:shadow-sm">✎ Review</a>
                                            @else
                                                <a href="{{ route('om-manuals.edit', $manual) }}"
                                                   class="inline-flex items-center bg-gray-900 hover:bg-gray-800 text-white px-3 py-1.5 rounded-md text-sm font-medium shadow-sm transition-all duration-150 hover:shadow hover:-translate-y-px active:translate-y-0 active:shadow-sm">View</a>
                                            @endif
                                            @if (empty($omHasMenu))
                                                <x-row-actions-menu>
                                                    <form method="POST" action="{{ route('om-manuals.destroy', $manual) }}"
                                                          onsubmit="return confirm('Delete this O&M manual?');">
                                                        @csrf @method('DELETE')
                                                        <button type="submit" class="row-actions-item row-actions-item--danger">
                                                            <span class="row-actions-item__icon" aria-hidden="true">🗑</span>
                                                            <span>Delete manual</span>
                                                        </button>
                                                    </form>
                                                </x-row-actions-menu>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                        </div>
                    @endif
                </div>

                {{-- ───── Install Programme panel ───── --}}
                <div x-show="activeTab==='install'" x-cloak role="tabpanel">
                    @if ($project->installProgrammes->isEmpty())
                        <x-empty-state title="No install programme yet"
                            description="Generate an install programme to plan the on-site delivery schedule."/>
                    @else
                        <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left">
                            <thead class="text-xs uppercase tracking-wide text-gray-500 border-b border-gray-200">
                                <tr>
                                    <th class="py-2 px-3 font-medium">Reference / Name</th>
                                    <th class="py-2 px-3 font-medium w-32">Status</th>
                                    <th class="py-2 px-3 font-medium w-28 whitespace-nowrap">Date</th>
                                    <th class="py-2 px-3 font-medium">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($project->installProgrammes->sortByDesc('updated_at') as $ip)
                                <tr data-name="{{ \Illuminate\Support\Str::lower(($ip->project_name ?? '').' '.($ip->name ?? '')) }}"
                                    class="border-b border-gray-100 last:border-0"
                                    x-show="q === '' || $el.dataset.name.includes(q.toLowerCase())">
                                    <td class="py-3 px-3 text-gray-900">{{ \Illuminate\Support\Str::limit($ip->project_name ?? $ip->name ?? ('Record #'.$ip->id), 55) }}</td>
                                    <td class="py-3 px-3">
                                        @if (isset($ip->status))<x-status-badge :status="$ip->status" />@else<span class="text-gray-400">—</span>@endif
                                    </td>
                                    <td class="py-3 px-3 text-gray-500 whitespace-nowrap">{{ $ip->updated_at->diffForHumans() }}</td>
                                    <td class="py-3 px-3">
                                        <div class="flex flex-wrap gap-2 items-center">
                                            <a href="{{ route('install-programmes.review', $ip) }}"
                                               class="inline-flex items-center bg-gray-900 hover:bg-gray-800 text-white px-3 py-1.5 rounded-md text-sm font-medium shadow-sm transition-all duration-150 hover:shadow hover:-translate-y-px active:translate-y-0 active:shadow-sm">View</a>
                                        </div>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                        </div>
                    @endif
                </div>

                {{-- ───── Quotes panel ───── --}}
                <div x-show="activeTab==='quotes'" x-cloak role="tabpanel">
                    @if ($project->projectQuotes->isEmpty())
                        <x-empty-state title="No quotes uploaded yet"
                            description="Upload a quote PDF to link it to this project."
                            :href="route('quote-import.create', ['project_id' => $project->id])"
                            action="Upload Quote"/>
                    @else
                        <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left">
                            <thead class="text-xs uppercase tracking-wide text-gray-500 border-b border-gray-200">
                                <tr>
                                    <th class="py-2 px-3 font-medium w-12">Ver.</th>
                                    <th class="py-2 px-3 font-medium">Original File</th>
                                    <th class="py-2 px-3 font-medium">Quote Ref</th>
                                    <th class="py-2 px-3 font-medium">Client</th>
                                    <th class="py-2 px-3 font-medium w-32">RAMS Status</th>
                                    <th class="py-2 px-3 font-medium w-28 whitespace-nowrap">Uploaded</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($project->projectQuotes->sortByDesc('version_number') as $pq)
                                @php $linkedRams = $quoteRamsMap[$pq->original_filename] ?? null; @endphp
                                <tr data-name="{{ \Illuminate\Support\Str::lower(($pq->original_filename ?? '').' '.($pq->quote_reference ?? '')) }}"
                                    class="border-b border-gray-100 last:border-0"
                                    x-show="q === '' || $el.dataset.name.includes(q.toLowerCase())">
                                    <td class="py-3 px-3 text-center font-semibold text-teal-600">v{{ $pq->version_number }}</td>
                                    <td class="py-3 px-3">
                                        <span title="{{ $pq->original_filename }}" class="font-mono text-xs text-gray-700">
                                            {{ \Illuminate\Support\Str::limit($pq->original_filename, 45) }}
                                        </span>
                                    </td>
                                    <td class="py-3 px-3 text-gray-700">{{ $pq->quote_reference ?? '—' }}</td>
                                    <td class="py-3 px-3 text-gray-600">{{ $pq->client_name ?? '—' }}</td>
                                    <td class="py-3 px-3">
                                        @if ($linkedRams)<x-status-badge :status="$linkedRams->status" />@else<span class="text-gray-400">—</span>@endif
                                    </td>
                                    <td class="py-3 px-3 text-gray-500 whitespace-nowrap">
                                        <div>{{ $pq->created_at->format('d M Y') }}</div>
                                        <div class="text-xs">{{ $pq->created_at->format('H:i') }}</div>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                        </div>
                    @endif
                </div>

                {{-- ───── Project Data panel ───── --}}
                <div x-show="activeTab==='data'" x-cloak role="tabpanel">
                    <p class="text-sm text-gray-600 mb-4">
                        Read-only view of the merged project data.
                        @if (! empty($canonicalData['meta']))
                            Source: <span class="font-medium text-gray-900">{{ $canonicalData['meta']['data_source'] ?? 'unknown' }}</span>.
                            Overall confidence: {{ number_format(($canonicalData['meta']['confidence'] ?? 0) * 100, 0) }}%.
                        @endif
                    </p>

                    @if (! empty($canonicalData['equipment']))
                        <h3 class="text-sm font-semibold text-gray-900 mb-2">Equipment ({{ count($canonicalData['equipment']) }})</h3>
                        <div class="overflow-x-auto mb-5">
                        <table class="w-full text-sm text-left">
                            <thead class="text-xs uppercase tracking-wide text-gray-500 border-b border-gray-200">
                                <tr>
                                    <th class="py-2 px-3 font-medium">Name</th>
                                    <th class="py-2 px-3 font-medium">Qty</th>
                                    <th class="py-2 px-3 font-medium">Area</th>
                                    <th class="py-2 px-3 font-medium">Source</th>
                                    <th class="py-2 px-3 font-medium">Confidence</th>
                                </tr>
                            </thead>
                            <tbody>
                            @foreach ($canonicalData['equipment'] as $item)
                                <tr data-name="{{ \Illuminate\Support\Str::lower(($item['name'] ?? '').' '.($item['area'] ?? '')) }}"
                                    class="border-b border-gray-100 last:border-0"
                                    x-show="q === '' || $el.dataset.name.includes(q.toLowerCase())">
                                    <td class="py-2 px-3 text-gray-900">{{ $item['name'] ?? '—' }}</td>
                                    <td class="py-2 px-3 text-gray-700">{{ $item['quantity'] ?? '—' }}</td>
                                    <td class="py-2 px-3 text-gray-700">{{ $item['area'] ?? '—' }}</td>
                                    <td class="py-2 px-3 text-gray-600" title="Source: {{ ucfirst(str_replace('_', ' ', $item['data_source'] ?? '')) }}">{{ $item['data_source'] ?? '—' }}</td>
                                    <td class="py-2 px-3 {{ ($item['confidence'] ?? 1) < 0.7 ? 'text-red-600 font-semibold' : 'text-gray-700' }}">
                                        {{ number_format(($item['confidence'] ?? 1) * 100, 0) }}%
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                        </div>
                    @else
                        <p class="text-sm text-gray-500">No equipment data available.</p>
                    @endif

                    @if (! empty($canonicalData['rooms']))
                        <h3 class="text-sm font-semibold text-gray-900 mb-2">Rooms ({{ count($canonicalData['rooms']) }})</h3>
                        <div class="overflow-x-auto mb-3">
                        <table class="w-full text-sm text-left">
                            <thead class="text-xs uppercase tracking-wide text-gray-500 border-b border-gray-200">
                                <tr>
                                    <th class="py-2 px-3 font-medium">Name</th>
                                    <th class="py-2 px-3 font-medium">Source</th>
                                    <th class="py-2 px-3 font-medium">Confidence</th>
                                </tr>
                            </thead>
                            <tbody>
                            @foreach ($canonicalData['rooms'] as $room)
                                <tr data-name="{{ \Illuminate\Support\Str::lower($room['name'] ?? '') }}"
                                    class="border-b border-gray-100 last:border-0"
                                    x-show="q === '' || $el.dataset.name.includes(q.toLowerCase())">
                                    <td class="py-2 px-3 text-gray-900">{{ $room['name'] ?? '—' }}</td>
                                    <td class="py-2 px-3 text-gray-600" title="Source: {{ ucfirst(str_replace('_', ' ', $room['data_source'] ?? '')) }}">{{ $room['data_source'] ?? '—' }}</td>
                                    <td class="py-2 px-3 {{ ($room['confidence'] ?? 1) < 0.7 ? 'text-red-600 font-semibold' : 'text-gray-700' }}">
                                        {{ number_format(($room['confidence'] ?? 1) * 100, 0) }}%
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                        </div>
                    @endif

                    <p class="text-xs text-gray-500">Low confidence threshold: &lt;70%. Fields highlighted in red need review.</p>
                </div>

            </div>{{-- /panel --}}
        </div>{{-- /ws --}}

    </div>{{-- /psv__main --}}

    {{-- ─────────────────── ASIDE COLUMN (sticky) ─────────────────── --}}
    <aside class="psv__aside min-w-0">
        <div class="psv__sticky lg:sticky lg:top-4 flex flex-col gap-4 lg:max-h-[calc(100vh-2rem)] lg:overflow-y-auto">

            {{-- Project Summary --}}
            <x-section-card title="Project Summary">
                <dl class="space-y-3 text-sm">
                    <div>
                        <dt class="text-xs uppercase tracking-wide font-semibold text-gray-500 mb-0.5">Client</dt>
                        <dd class="text-gray-900 break-words">{{ $project->client_name }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide font-semibold text-gray-500 mb-0.5">Site</dt>
                        <dd class="text-gray-900 break-words">{{ $project->site_address ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide font-semibold text-gray-500 mb-0.5">Reference</dt>
                        <dd class="text-gray-900 break-words">{{ $project->ref ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide font-semibold text-gray-500 mb-0.5">Status</dt>
                        <dd><x-status-badge :status="$project->status" /></dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide font-semibold text-gray-500 mb-0.5">Last updated</dt>
                        <dd class="text-gray-500">{{ $project->updated_at->diffForHumans() }}</dd>
                    </div>
                </dl>
            </x-section-card>

            {{-- Project Details --}}
            <x-section-card title="Project Details">
                <x-slot name="actions">
                    <a href="{{ route('projects.edit', $project) }}"
                       class="inline-flex items-center border border-gray-300 hover:bg-white text-gray-700 px-2.5 py-1 rounded-md text-xs">
                        Edit
                    </a>
                </x-slot>
                <dl class="space-y-3 text-sm">
                    <div>
                        <dt class="text-xs uppercase tracking-wide font-semibold text-gray-500 mb-0.5">Quote ref</dt>
                        <dd class="text-gray-900 break-words">{{ $project->quote_reference ?? $project->ref ?? '—' }}</dd>
                    </div>
                    @if ($project->works_description)
                        <div>
                            <dt class="text-xs uppercase tracking-wide font-semibold text-gray-500 mb-0.5">Scope</dt>
                            <dd class="text-gray-900 break-words">{{ $project->works_description }}</dd>
                        </div>
                    @endif
                    @if ($project->notes)
                        <div>
                            <dt class="text-xs uppercase tracking-wide font-semibold text-gray-500 mb-0.5">Notes</dt>
                            <dd class="text-gray-600 break-words">{{ $project->notes }}</dd>
                        </div>
                    @endif
                    <div>
                        <dt class="text-xs uppercase tracking-wide font-semibold text-gray-500 mb-0.5">Created</dt>
                        <dd class="text-gray-900">{{ $project->created_at->format('d M Y') }}</dd>
                    </div>
                    @if ($project->reopened_at)
                        <div>
                            <dt class="text-xs uppercase tracking-wide font-semibold text-gray-500 mb-0.5">Reopened</dt>
                            <dd class="text-gray-900">
                                {{ $project->reopened_at->format('d M Y') }}
                                <div class="text-xs text-gray-600 mt-0.5">{{ $project->reopen_reason }}</div>
                            </dd>
                        </div>
                    @endif
                </dl>
            </x-section-card>

            {{-- Activity Log --}}
            <x-section-card title="Activity Log">
                @if ($project->activityLog->isEmpty())
                    <p class="text-sm text-gray-500">No activity recorded yet.</p>
                @else
                    <ul class="space-y-2">
                        @foreach ($project->activityLog->take(12) as $entry)
                        <li class="flex gap-2 text-xs leading-snug border-b border-gray-200 last:border-0 pb-2 last:pb-0">
                            <span class="text-gray-500 whitespace-nowrap shrink-0 w-20 tabular-nums">{{ $entry->created_at->format('d M H:i') }}</span>
                            <span class="text-gray-700">{{ $entry->description }}</span>
                        </li>
                        @endforeach
                    </ul>
                @endif
            </x-section-card>

        </div>{{-- /sticky --}}
    </aside>

</div>{{-- /psv --}}

{{-- Danger zone (only when archived) --}}
@if ($project->isArchived())
<div class="bg-white rounded-xl border-l-4 border-red-600 border-y border-r border-gray-100 shadow-sm p-6 mt-6">
    <h2 class="text-base font-semibold text-red-700 mb-2">Danger Zone</h2>
    <p class="text-sm text-gray-600 mb-3">
        Permanently delete this project and all associated data. This cannot be undone.
    </p>
    <form method="POST" action="{{ route('projects.destroy', $project) }}" class="m-0">
        @csrf @method('DELETE')
        <button type="submit"
                class="inline-flex items-center bg-red-600 hover:bg-red-700 text-white px-3 py-1.5 rounded-md text-sm font-medium"
                onclick="return confirm('Permanently delete project &quot;{{ addslashes($project->name) }}&quot;? This cannot be undone.')">
            Delete Project
        </button>
    </form>
</div>
@endif

{{-- Copy-to-clipboard for engineer/client links --}}
<script>
function copyEngineerLink(url, btn) {
    const orig = btn.textContent;
    const showSuccess = () => {
        btn.textContent = '✓';
        btn.style.background = '#059669';
        btn.style.color = '#fff';
        setTimeout(() => { btn.textContent = orig; btn.style.background = ''; btn.style.color = ''; }, 2500);
    };
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(url).then(showSuccess).catch(() => {
            fallbackCopyText(url); showSuccess();
        });
    } else {
        fallbackCopyText(url); showSuccess();
    }
}
function fallbackCopyText(text) {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); } catch(e) {}
    document.body.removeChild(ta);
}

</script>

</x-app-shell>

@endsection
