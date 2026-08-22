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
           PROJECT SHOW VIEW — Jetbuilt-clean (2026-07-09).
           Was a 340-line tier-one stylesheet with coloured left-rail
           accents on every card title, gradient CTA pills, and glowing
           progress bars. Rewritten to a lean sheet that leans on the
           layout tokens: flat cards, no left-rails, single accent for
           active/current state, plain type hierarchy.
        ═══════════════════════════════════════════════════════════════ */

        /* Main workspace cards — flat surface, no shadow, single accent
           only if something is truly interactive. Class names retained
           so the deep Blade markup below doesn't need to move. */
        .psv__main > .section-block {
            background: var(--surface);
            border: 1px solid var(--ink-200);
            border-radius: var(--radius-lg);
            box-shadow: none;
            padding: 20px 22px;
            margin: 0;
        }
        .psv__main > .section-block .section-card__header {
            border-bottom: 1px solid var(--ink-100);
            padding-bottom: 12px;
            margin-bottom: 16px;
        }
        .psv__main > .section-block .section-card__title {
            font-size: var(--fs-h3);
            font-weight: 600;
            color: var(--ink-900);
            letter-spacing: -.015em;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        /* Left-rail accent retired — Jetbuilt uses type weight, not a
           coloured bar, to mark headings. */
        .psv__main > .section-block .section-card__title::before { content: none; }

        /* Right sticky panel cards — same flat treatment, tighter body. */
        .psv__sticky > .section-block {
            background: var(--surface);
            border: 1px solid var(--ink-200);
            border-radius: var(--radius-lg);
            box-shadow: none;
            padding: 0;
            margin: 0;
            overflow: hidden;
        }
        .psv__sticky > .section-block .section-card__header {
            background: var(--surface);
            border-bottom: 1px solid var(--ink-100);
            padding: 12px 18px;
            margin: 0;
        }
        .psv__sticky > .section-block .section-card__title {
            font-size: var(--fs-small);
            font-weight: 600;
            color: var(--ink-900);
            text-transform: none;
            letter-spacing: -0.005em;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .psv__sticky > .section-block .section-card__title::before { content: none; }
        .psv__sticky > .section-block .section-card__body { padding: 16px 18px; }

        /* Workflow card — same flat header. */
        .psv__workflow > .section-block {
            background: var(--surface);
            border: 1px solid var(--ink-200);
            border-radius: var(--radius-lg);
            box-shadow: none;
            padding: 0;
            /* Re-audit UI-02 — was overflow:hidden, which clipped the
               "Completed" + "Archived" chips at the right edge of the
               workflow stepper on 1280px viewports. Switch to
               overflow-x:auto so the inner stepper strip can scroll
               without the header/body corners breaking their radius. */
            overflow-x: auto;
        }
        .psv__workflow > .section-block .section-card__header {
            background: var(--surface);
            border-bottom: 1px solid var(--ink-100);
            padding: 12px 22px;
            margin: 0;
        }
        .psv__workflow > .section-block .section-card__title {
            font-size: var(--fs-small);
            font-weight: 600;
            color: var(--ink-900);
            text-transform: none;
            letter-spacing: -0.005em;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .psv__workflow > .section-block .section-card__title::before { content: none; }
        .psv__workflow > .section-block .section-card__body { padding: 18px 22px; }

        /* Workspace tab strip — accent underline (matches top-nav). */
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
            padding: 10px 14px;
            margin-bottom: -1px;
            font-size: var(--fs-small);
            font-weight: 500;
            color: var(--ink-500);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border-bottom: 2px solid transparent;
            transition: color var(--transition), border-color var(--transition);
            white-space: nowrap;
        }
        .ws-tab:hover { color: var(--ink-900); }
        .ws-tab.is-active {
            color: var(--ink-900);
            border-bottom-color: var(--accent-600);
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
            font-weight: 500;
            line-height: 1;
        }
        .ws-tab.is-active .ws-tab__count {
            background: var(--accent-50);
            color: var(--accent-700);
        }
        /* Re-audit UX-05 — empty-tab affordance. Muted background +
           faint text so populated tabs still pop against the row. */
        .ws-tab__count--empty {
            background: transparent;
            color: var(--ink-400);
            border: 1px solid var(--ink-200);
        }
        .ws-tab.is-active .ws-tab__count--empty {
            background: transparent;
            color: var(--accent-700);
            border-color: var(--accent-100);
        }
        /* 260822-04 (D-08) — "Not required" muted tab grouping. Mirrors the
           .ws-tab__count--empty visual language above (low-opacity, not
           hidden) rather than inventing a new colour system. */
        .ws-tabs__divider {
            display: inline-flex;
            align-items: center;
            padding: 10px 6px 10px 14px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--ink-400);
            white-space: nowrap;
        }
        .ws-tab-group {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .ws-tab.ws-tab--muted {
            opacity: 0.6;
        }
        .ws-tab.ws-tab--muted:hover { opacity: 1; }
        .ws-tab__add-anyway { margin: 0; display: inline-flex; }
        .ws-tab__add-anyway-btn {
            background: transparent;
            border: none;
            padding: 4px 8px;
            font-size: 11px;
            font-weight: 500;
            color: var(--accent-700);
            text-decoration: underline;
            cursor: pointer;
            white-space: nowrap;
        }
        .ws-tab__add-anyway-btn:hover { color: var(--accent-900, var(--accent-700)); }
        .ws > .bg-white {
            border-top-left-radius: 0;
            border-top-right-radius: 0;
            border-top: none;
        }

        /* Table-row hover — matches the accent-50 hover the .data-table
           primitive uses everywhere else. */
        .psv__main table tbody tr { transition: background-color var(--transition); }
        .psv__main table tbody tr:hover { background-color: var(--accent-50); }

        /* Project ref / breadcrumb — mono meta, plain colour. */
        .psv-ref {
            font-family: var(--font-mono);
            font-size: var(--fs-small);
            color: var(--ink-500);
            letter-spacing: 0;
        }

        /* Workflow stepper — pill steps, flat accent for current, muted
           for done. No gradients, no shadow, no pulse ring. */
        .psv-stepper {
            display: flex;
            gap: 8px;
            flex-wrap: nowrap;
            overflow-x: auto;
            padding-bottom: 4px;
            margin-bottom: 16px;
        }
        .psv-stepper::-webkit-scrollbar { height: 4px; }
        .psv-stepper::-webkit-scrollbar-track { background: transparent; }
        .psv-stepper::-webkit-scrollbar-thumb { background: var(--ink-200); border-radius: 2px; }

        .psv-step {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 14px 6px 8px;
            border-radius: 999px;
            font-size: var(--fs-small);
            font-weight: 500;
            border: 1px solid var(--ink-200);
            background: var(--surface);
            color: var(--ink-500);
            white-space: nowrap;
            transition: color var(--transition), border-color var(--transition), background var(--transition);
            flex-shrink: 0;
        }
        .psv-step__num {
            width: 20px; height: 20px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 600;
            background: var(--ink-100);
            color: var(--ink-500);
        }
        .psv-step.is-current {
            border-color: var(--accent-600);
            background: var(--accent-600);
            color: #fff;
            font-weight: 600;
            box-shadow: none;
        }
        .psv-step.is-current .psv-step__num {
            background: rgba(255,255,255,.22);
            color: #fff;
        }
        .psv-step.is-done {
            border-color: color-mix(in oklab, var(--success) 30%, transparent);
            background: var(--success-light);
            color: #065F46;
        }
        .psv-step.is-done .psv-step__num {
            background: var(--success);
            color: #fff;
        }

        /* Workflow progress bar — flat accent fill. */
        .psv-progress {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-top: 4px;
        }
        .psv-progress__label {
            font-size: var(--fs-small);
            color: var(--ink-500);
            white-space: nowrap;
            flex-shrink: 0;
        }
        .psv-progress__track {
            flex: 1;
            height: 6px;
            background: var(--ink-100);
            border-radius: 999px;
            overflow: hidden;
        }
        .psv-progress__fill {
            height: 100%;
            background: var(--accent-600);
            border-radius: 999px;
            box-shadow: none;
            transition: width 400ms ease;
        }

        /* Document tabs inside the workspace card — same accent underline
           as the top-nav so the whole app tabs consistently. */
        .psv-tabs {
            display: flex;
            gap: 24px;
            flex-wrap: wrap;
            border-bottom: 1px solid var(--ink-200);
            margin-bottom: 20px;
        }
        .psv-tab {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 0;
            border: none;
            background: transparent;
            color: var(--ink-500);
            font-size: var(--fs-body);
            font-weight: 500;
            cursor: pointer;
            position: relative;
            transition: color var(--transition);
            white-space: nowrap;
        }
        .psv-tab:hover { color: var(--ink-900); }
        .psv-tab.is-active {
            color: var(--ink-900);
            font-weight: 600;
        }
        .psv-tab.is-active::after {
            content: '';
            position: absolute;
            left: 0; right: 0; bottom: -1px;
            height: 2px;
            background: var(--accent-600);
            border-radius: 2px 2px 0 0;
        }
        .psv-tab-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 20px; height: 20px;
            padding: 0 6px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 500;
            background: var(--ink-100);
            color: var(--ink-500);
        }
        .psv-tab.is-active .psv-tab-count {
            background: var(--accent-50);
            color: var(--accent-700);
        }

        .psv .status-badge { font-weight: 500; }
    /* Audit D-11 (2026-07-08) — 340-line stylesheet shipped zero @media
       breakpoints, so the stepper/tabs/sticky aside all overflowed
       below `lg`. PMs open this on iPads on-site. Added defensive
       responsive rules for the busiest screen in the app. */
    @media (max-width: 900px) {
        .psv-tabs { gap: 12px; overflow-x: auto; }
        .psv-tab { padding: 8px 2px; font-size: 12px; }
        .psv-stepper { padding-bottom: 8px; }
        .psv-step { font-size: 12px; }
        .psv-step__num { width: 20px; height: 20px; font-size: 10px; }
        .psv__main > .section-block { padding: 16px; }
    }
    @media (max-width: 700px) {
        /* Device rows crush into a single line on the label-photo panel
           without flex-wrap. Force wrap so thumb + name stack. */
        .psv__main .flex.items-start { flex-wrap: wrap; }
    }
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
    // 260504-q19 — Asset register count (all devices captured for this project).
    $countAssets     = \App\Models\Device::where('project_id', $project->id)->count();
    // 260822-04 (D-04/D-07) — Snagging tab addition. Not eager-loaded, same
    // live-query pattern as $countDrawings/$countAssets above.
    $countSnagging   = $project->snaggingSignoffs()->count();

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
    } elseif (
        $countSurvey === 0
        && $project->deliverableState(\App\Models\ProjectDeliverable::KEY_SITE_SURVEY) !== \App\Models\ProjectDeliverable::STATE_NOT_REQUIRED
    ) {
        // 260822-04 (D-11 Pitfall 3): never prompt to create a deliverable
        // explicitly marked Not required. "Not yet decided" and "Required"
        // both keep prompting exactly as before.
        $nextStep = [
            'icon'  => '📍',
            'title' => 'Create Site Survey',
            'desc'  => 'Generate a shareable survey link so the on-site engineer can capture site conditions.',
            'cta'   => 'Create Survey',
            'href'  => route('site-surveys.from-project', $project),
            'tab'   => 'surveys',
        ];
    } elseif (
        $countRams === 0
        && $project->deliverableState(\App\Models\ProjectDeliverable::KEY_RAMS) !== \App\Models\ProjectDeliverable::STATE_NOT_REQUIRED
    ) {
        $nextStep = [
            'icon'  => '🛡',
            'title' => 'Generate RAMS Document',
            'desc'  => 'Project data is reviewed. Generate the RAMS for this job.',
            'cta'   => 'Create RAMS',
            'form_action' => route('rams.from-project', $project),
            'tab'   => 'rams',
        ];
    } elseif (
        $countWorksheet === 0
        && $project->deliverableState(\App\Models\ProjectDeliverable::KEY_WORKSHEET) !== \App\Models\ProjectDeliverable::STATE_NOT_REQUIRED
    ) {
        $nextStep = [
            'icon'  => '📋',
            'title' => 'Generate Worksheet',
            'desc'  => 'Build the engineer\'s job card for the install team.',
            'cta'   => 'Generate Worksheet',
            'form_action' => route('worksheets.generate-from-project', $project),
            'tab'   => 'worksheets',
        ];
    } elseif (
        $countOm === 0
        && $project->deliverableState(\App\Models\ProjectDeliverable::KEY_OM) !== \App\Models\ProjectDeliverable::STATE_NOT_REQUIRED
    ) {
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
                    <span class="ml-1 inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 text-xs font-semibold rounded-full bg-accent-100 text-accent-700 border border-accent-100">
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

        {{-- 260506-qa9 Mini O&M — always-visible header action so it's not
             gated behind a tab. Status pill conveys "ready vs awaiting photos". --}}
        @php
            $hasAnyWorksheetPhoto = $project->worksheets
                ->loadMissing('photos')
                ->sum(fn ($w) => $w->photos->count()) > 0;
        @endphp
        <a href="{{ route('projects.mini-om.pdf', $project) }}"
           class="btn btn-outline"
           title="Auto-built client-facing PDF — rooms, photos, asset list, sign-offs"
           target="_blank" rel="noopener">
            <span aria-hidden="true">📄</span>
            <span>Mini O&amp;M</span>
            {{-- Re-audit UX-08 — was a title-tooltip only, invisible on
                 touch and keyboard. Now the pill carries a visible sub-
                 label so the state reads without hover. --}}
            @if ($hasAnyWorksheetPhoto)
                <span class="ml-1 inline-flex items-center gap-1 px-1.5 py-0.5 text-xs font-semibold rounded-full bg-green-100 text-green-700 border border-green-200"
                      aria-label="Mini O&M is ready — photos captured">
                    <span aria-hidden="true">✓</span>
                    <span>Ready</span>
                </span>
            @else
                <span class="ml-1 inline-flex items-center gap-1 px-1.5 py-0.5 text-xs font-semibold rounded-full bg-amber-100 text-amber-700 border border-amber-200"
                      aria-label="Draft — Mini O&M still generates but uses brand-only cover and placeholder room blocks until worksheet photos arrive"
                      title="Mini O&M still works — uses brand-only cover + placeholder room blocks until photos arrive">
                    <span aria-hidden="true">⚠</span>
                    <span>Draft</span>
                </span>
            @endif
        </a>

        @if ($nextStep && ! empty($nextStep['cta']) && ! empty($nextStep['href']))
            <a href="{{ $nextStep['href'] }}"
               class="btn btn-primary">
                {{ $nextStep['cta'] }}
            </a>
        @elseif ($nextStep && ! empty($nextStep['cta']) && ! empty($nextStep['form_action']))
            <form method="POST" action="{{ $nextStep['form_action'] }}" class="m-0">
                @csrf
                <button type="submit"
                        class="btn btn-primary">
                    {{ $nextStep['cta'] }}
                </button>
            </form>
        @elseif (! $primaryPackage)
            <a href="{{ route('quote-import.create', ['project_id' => $project->id]) }}"
               class="btn btn-primary">
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
                           class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-600 focus:border-accent-600"
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

        {{-- ── Next Step Card (PRIMARY FOCUS) ───────────────────────────────
             Jetbuilt-clean (2026-07-09) — was a teal→cyan gradient panel
             with a dark-teal circle icon and lift/glow CTA. Retunes to a
             flat accent-50 canvas with the accent-600 solid mark + button
             so the hero speaks the same visual language as the top-nav. --}}
        @if ($nextStep)
        <section role="region" aria-label="Next Step"
                 style="position:relative; display:flex; align-items:center; gap:16px; padding:18px 20px; margin-bottom:8px;
                        background: var(--accent-50);
                        border: 1px solid color-mix(in oklab, var(--accent-600) 22%, transparent);
                        border-radius: var(--radius-lg);">
            <div aria-hidden="true"
                 style="flex:none; width:40px; height:40px; border-radius: var(--radius-sm);
                        background: var(--accent-600); color: #fff;
                        display:flex; align-items:center; justify-content:center;
                        font-size:18px;">
                {{ $nextStep['icon'] }}
            </div>
            <div style="flex:1; min-width:0;">
                <div style="font-size: var(--fs-micro); font-weight:600; text-transform:uppercase; letter-spacing:.08em; color: var(--accent-700); margin-bottom:2px;">Next Step</div>
                <h3 style="font-size: var(--fs-body); font-weight:600; color: var(--ink-900); line-height:1.35; letter-spacing:-.01em;">{{ $nextStep['title'] }}</h3>
                <p style="font-size: var(--fs-small); color: var(--ink-700); margin-top:2px; max-width: 60ch; line-height:1.45;">{{ $nextStep['desc'] }}</p>
            </div>
            <div style="flex:none; display:flex; align-items:center;">
                @if (! empty($nextStep['cta']) && ! empty($nextStep['href']))
                    <a href="{{ $nextStep['href'] }}" class="btn btn-primary" style="white-space:nowrap;">
                        {{ $nextStep['cta'] }} →
                    </a>
                @elseif (! empty($nextStep['cta']) && ! empty($nextStep['form_action']))
                    <form method="POST" action="{{ $nextStep['form_action'] }}" class="m-0">
                        @csrf
                        <button type="submit" class="btn btn-primary" style="white-space:nowrap;">
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
                    <form method="POST" action="{{ route('projects.transition', $project) }}" class="m-0"
                          data-confirm="Advance project to {{ $nextLabel }}?"
                          data-confirm-label="Advance">
                        @csrf
                        <input type="hidden" name="to_status" value="{{ $nextStatus }}">
                        <button type="submit"
                                class="inline-flex items-center border border-gray-300 hover:bg-gray-50 text-gray-700 px-3 py-1.5 rounded-md text-sm">
                            Advance → {{ $nextLabel }}
                        </button>
                    </form>
                @endif
                @if (! $project->isArchived())
                    <form method="POST" action="{{ route('projects.archive', $project) }}" class="m-0"
                          data-confirm="Archive this project?"
                          data-confirm-label="Archive"
                          data-confirm-danger="1">
                        @csrf
                        <button type="submit"
                                class="inline-flex items-center border border-gray-300 hover:bg-gray-50 text-gray-700 px-3 py-1.5 rounded-md text-sm">
                            Archive
                        </button>
                    </form>
                @endif
            </x-slot>

            {{-- Workflow stepper — Jetbuilt-clean (2026-07-09).
                 Was Tailwind teal-* utility classes → stock Tailwind
                 teal palette, ignoring our accent tokens. Retuned to
                 inline styles that pull from the CSS vars so past/current/
                 future states speak the same language as everything else. --}}
            @php
                // 260822-04 (D-11 Pitfall 2): a not-required Survey skips
                // STATUS_SURVEY_PENDING entirely (Project::canTransitionTo()),
                // so that stage genuinely never happened — it must render as
                // skipped, never as a false "done" checkmark. Only Survey
                // Pending can ever be skipped today, so this is computed once
                // rather than per-iteration.
                $surveySkipped = $project->deliverableState(\App\Models\ProjectDeliverable::KEY_SITE_SURVEY) === \App\Models\ProjectDeliverable::STATE_NOT_REQUIRED
                    && $currentIdx > array_search(\App\Models\Project::STATUS_SURVEY_PENDING, $lifecycle);
            @endphp
            <div class="flex items-center gap-2 overflow-x-auto py-1">
                @foreach ($lifecycle as $i => $step)
                    @php
                        $stepLabel = \App\Models\Project::STATUS_LABELS[$step];
                        $isActive  = $step === $project->status;
                        $isPast    = $i < $currentIdx;
                        $isSkipped = $step === \App\Models\Project::STATUS_SURVEY_PENDING && $surveySkipped;
                    @endphp
                    @if ($isSkipped)
                        {{-- Skipped step: same visual weight as the future/grey
                             branch below — never the done-tick treatment, so a
                             stage that genuinely never happened cannot read as
                             completed. --}}
                        <div class="flex-none inline-flex items-center gap-1.5 whitespace-nowrap ws-step--skipped"
                             style="padding: 4px 12px; border-radius: 999px; font-size: 12px; font-weight: 500;
                                    background: var(--ink-100); color: var(--ink-500);
                                    border: 1px solid var(--ink-200);">
                            <span style="display:inline-flex; align-items:center; justify-content:center; width:16px; height:16px; border-radius:50%; background: #fff; color: var(--ink-500); border: 1px solid var(--ink-200); font-size:9px;">—</span>
                            {{ $stepLabel }} (skipped — not required)
                        </div>
                    @elseif ($isActive)
                        {{-- Current step: solid accent pill so the eye lands here first. --}}
                        <div class="flex-none inline-flex items-center gap-1.5 whitespace-nowrap"
                             style="padding: 4px 12px; border-radius: 999px; font-size: 12px; font-weight: 600;
                                    background: var(--accent-600); color: #fff; border: 1px solid var(--accent-600);">
                            <span style="display:inline-flex; align-items:center; justify-content:center; width:16px; height:16px; border-radius:50%; background: rgba(255,255,255,.22); color:#fff; font-size:9px;">●</span>
                            {{ $stepLabel }}
                        </div>
                    @elseif ($isPast)
                        {{-- Done step: quiet accent-50 pill + accent tick so the trail
                             behind us reads as a set, not as new load-bearing UI. --}}
                        <div class="flex-none inline-flex items-center gap-1.5 whitespace-nowrap"
                             style="padding: 4px 12px; border-radius: 999px; font-size: 12px; font-weight: 500;
                                    background: var(--accent-50); color: var(--accent-700);
                                    border: 1px solid color-mix(in oklab, var(--accent-600) 22%, transparent);">
                            <span style="display:inline-flex; align-items:center; justify-content:center; width:16px; height:16px; border-radius:50%; background: var(--accent-600); color:#fff; font-size:9px;">✓</span>
                            {{ $stepLabel }}
                        </div>
                    @else
                        <div class="flex-none inline-flex items-center gap-1.5 whitespace-nowrap"
                             style="padding: 4px 12px; border-radius: 999px; font-size: 12px; font-weight: 500;
                                    background: var(--ink-100); color: var(--ink-500);
                                    border: 1px solid var(--ink-200);">
                            <span style="display:inline-flex; align-items:center; justify-content:center; width:16px; height:16px; border-radius:50%; background: #fff; color: var(--ink-500); border: 1px solid var(--ink-200); font-size:9px;">{{ $i + 1 }}</span>
                            {{ $stepLabel }}
                        </div>
                    @endif

                    @if (! $loop->last)
                        {{-- 260822-04: a skipped stage must not render as a
                             completed accent-coloured connector either — that
                             would visually contradict the grey pill above it. --}}
                        <div class="flex-none"
                             style="width: 8px; height: 1px; background: {{ ($isPast && ! $isSkipped) ? 'var(--accent-600)' : 'var(--ink-200)' }};"></div>
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
                    // 260822-04 (D-04/D-07): reconciled against the canonical
                    // nine-item deliverable list. Drawings and Snagging are
                    // new tabs (they had none before this phase); Programming
                    // is deliberately absent — D-05 keeps it a flag with no
                    // tab, generator or storage type. Quotes/Assets/Project
                    // Data keep 'deliverable_key' => null — D-06 excludes
                    // them from selection, so they are never eligible for
                    // D-08/D-09 muting or grouping.
                    $tabs = [
                        ['key' => 'surveys',    'label' => 'Surveys',           'count' => $countSurvey,    'deliverable_key' => \App\Models\ProjectDeliverable::KEY_SITE_SURVEY],
                        ['key' => 'rams',       'label' => 'RAMS',              'count' => $countRams,      'deliverable_key' => \App\Models\ProjectDeliverable::KEY_RAMS],
                        ['key' => 'worksheets', 'label' => 'Worksheets',        'count' => $countWorksheet, 'deliverable_key' => \App\Models\ProjectDeliverable::KEY_WORKSHEET],
                        ['key' => 'cable',      'label' => 'Cable Schedule',    'count' => $countCable,     'deliverable_key' => \App\Models\ProjectDeliverable::KEY_CABLE_SCHEDULE],
                        ['key' => 'om',         'label' => 'O&M',               'count' => $countOm,        'deliverable_key' => \App\Models\ProjectDeliverable::KEY_OM],
                        ['key' => 'install',    'label' => 'Install Programme', 'count' => $countInstall,   'deliverable_key' => \App\Models\ProjectDeliverable::KEY_INSTALL_PROGRAMME],
                        ['key' => 'drawings',   'label' => 'Drawings',          'count' => $countDrawings,  'deliverable_key' => \App\Models\ProjectDeliverable::KEY_DRAWINGS],
                        ['key' => 'snagging',   'label' => 'Snagging',          'count' => $countSnagging,  'deliverable_key' => \App\Models\ProjectDeliverable::KEY_SNAGGING],
                        ['key' => 'quotes',     'label' => 'Quotes',            'count' => $countQuotes,    'deliverable_key' => null],
                        ['key' => 'assets',     'label' => 'Asset Register',    'count' => $countAssets,    'deliverable_key' => null],
                        ['key' => 'data',       'label' => 'Project Data',      'count' => null,            'deliverable_key' => null],
                    ];

                    // D-08/D-09: a tab is "not-required-and-empty" — and only
                    // then eligible for the muted "Not required" grouping —
                    // when it carries a deliverable_key, that deliverable is
                    // explicitly Not required, AND it holds zero records.
                    // D-09 is enforced by the `=== 0` / null check: any count
                    // greater than 0 fails this test regardless of flag state,
                    // so a populated-but-not-required tab always stays primary.
                    $isNotRequiredEmpty = fn (array $t) => $t['deliverable_key'] !== null
                        && $project->deliverableState($t['deliverable_key']) === \App\Models\ProjectDeliverable::STATE_NOT_REQUIRED
                        && ($t['count'] === null || $t['count'] === 0);

                    [$primaryTabs, $mutedTabs] = collect($tabs)->partition(fn ($t) => ! $isNotRequiredEmpty($t));
                @endphp
                @foreach ($primaryTabs as $t)
                    {{-- Re-audit UX-05 — was `@if($count > 0)` gate, so on a
                         fresh project 7/9 tabs rendered label-only and the
                         user couldn't tell which held data. Now render the
                         count pill unconditionally (muted "0" for empties);
                         populated tabs still pop via the accent-50 fill
                         when active. Project Data has no count → skip. --}}
                    <button type="button" role="tab" class="ws-tab"
                            @click="setTab('{{ $t['key'] }}')"
                            :class="activeTab==='{{ $t['key'] }}' ? 'is-active' : ''"
                            :aria-selected="activeTab==='{{ $t['key'] }}'">
                        <span class="ws-tab__label">{{ $t['label'] }}</span>
                        @if ($t['count'] !== null)
                            <span class="ws-tab__count {{ $t['count'] === 0 ? 'ws-tab__count--empty' : '' }}">{{ $t['count'] }}</span>
                        @endif
                    </button>
                @endforeach
                @if ($mutedTabs->isNotEmpty())
                    {{-- D-08: muted and moved to the end — NEVER hidden. This
                         is a styling variation on the tab strip, not a gate;
                         see the UX-05 comment above for the regression this
                         must not repeat. Each muted tab keeps its count pill
                         and gets a visible, working "Add anyway" recovery
                         action (D-02's soft-gate auto-flip). --}}
                    <span class="ws-tabs__divider" aria-hidden="true">Not required</span>
                    @foreach ($mutedTabs as $t)
                        <div class="ws-tab-group ws-tab-group--muted">
                            <button type="button" role="tab" class="ws-tab ws-tab--muted"
                                    @click="setTab('{{ $t['key'] }}')"
                                    :class="activeTab==='{{ $t['key'] }}' ? 'is-active' : ''"
                                    :aria-selected="activeTab==='{{ $t['key'] }}'">
                                <span class="ws-tab__label">{{ $t['label'] }}</span>
                                @if ($t['count'] !== null)
                                    <span class="ws-tab__count {{ $t['count'] === 0 ? 'ws-tab__count--empty' : '' }}">{{ $t['count'] }}</span>
                                @endif
                            </button>
                            {{-- The target route (projects.deliverables.update)
                                 lands in Plan 07 — it does not exist yet, so this
                                 posts to the plain path Plan 07's controller will
                                 register (PATTERNS.md Pattern 7), NOT the route()
                                 helper, which would throw RouteNotFoundException
                                 on every render of this page until Plan 07 ships. --}}
                            <form method="POST" action="{{ url('/projects/'.$project->id.'/deliverables') }}" class="ws-tab__add-anyway">
                                @csrf
                                <input type="hidden" name="deliverable_key" value="{{ $t['deliverable_key'] }}">
                                <input type="hidden" name="state" value="{{ \App\Models\ProjectDeliverable::STATE_REQUIRED }}">
                                <button type="submit" class="ws-tab__add-anyway-btn">+ Add anyway</button>
                            </form>
                        </div>
                    @endforeach
                @endif
            </div>

            {{-- Table container — clean white reading surface inside the teal zone --}}
            <div class="bg-white rounded-lg border border-gray-200 p-6">

                {{-- Search/filter row --}}
                <div class="flex flex-wrap gap-3 items-center mb-5">
                    <div class="relative flex-1 max-w-sm">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none" aria-hidden="true">🔍</span>
                        <input type="text" x-model.debounce.150ms="q"
                               class="w-full pl-9 pr-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-accent-600 focus:border-accent-600"
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
                                   class="btn btn-primary btn-sm">
                                    ✎ Review & Generate
                                </a>
                            @elseif ($generatingRams)
                                <span class="inline-flex items-center gap-2 text-sm text-gray-500"><svg class="animate-spin h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 12a9 9 0 11-6.219-8.56" stroke-linecap="round"/></svg><span>Processing…</span></span>
                            @elseif ($primaryPackage && $primaryPackage->status === \App\Models\ProjectPackage::STATUS_REVIEWED)
                                <form method="POST" action="{{ route('rams.from-project', $project) }}" class="m-0 inline-block"
                                      @if($hasCompletedRams) data-confirm="Generate a new RAMS document from the current project data?" data-confirm-label="Generate" @endif>
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
                                {{-- Draft mode — seeds [TBC] placeholders for handover_date + drawings
                                     so early-stage projects (no scheduled handover, no engineering
                                     drawings yet) can still produce a preview O&M. Final-issue mode
                                     stays the default to preserve Tier-1 NO-TBC compliance. --}}
                                <form method="POST" action="{{ route('om-manuals.generate-from-project', $project) }}?draft=1" class="m-0 inline-block ml-1">
                                    @csrf
                                    <button type="submit"
                                            class="inline-flex items-center border border-amber-300 bg-amber-50 hover:bg-amber-100 text-amber-800 px-3 py-1.5 rounded-md text-sm"
                                            title="Generate a draft O&M with [TBC] placeholders for handover date and drawings. Use when those fields aren't ready yet.">
                                        + Draft (TBC)
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
                            {{-- 260506-qa9 Mini O&M button moved to the always-visible header
                                 actions slot (alongside Drawings + Edit Project Data) so it's
                                 not gated behind the O&M tab. --}}
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
                                        {{-- Tier-1 v2 — v1 showed View + Edit + ✓ Complete + ✎ AI Chat + ⋯ (5 elements per row).
                                             View stays as the primary; Edit / Complete / AI Chat collapse into the
                                             existing overflow menu. Frees the row from feeling like a control panel
                                             and reduces the "which button is the right button" hesitation. --}}
                                        <div class="flex flex-wrap gap-2 items-center">
                                            <a href="{{ route('site-surveys.show', $survey) }}"
                                               class="inline-flex items-center border border-gray-300 hover:bg-gray-50 text-gray-700 px-3 py-1.5 rounded-md text-sm">View</a>

                                            <x-row-actions-menu>
                                                @if (! $survey->isCompleted())
                                                    <a href="{{ route('site-surveys.edit', $survey) }}" class="row-actions-item">
                                                        <span class="row-actions-item__icon" aria-hidden="true">✎</span>
                                                        <span>Edit</span>
                                                    </a>
                                                    <form method="POST" action="{{ route('site-surveys.complete', $survey) }}" class="m-0"
                                                          data-confirm="Mark this survey as completed?"
                                                          data-confirm-label="Complete">
                                                        @csrf
                                                        <button type="submit" class="row-actions-item">
                                                            <span class="row-actions-item__icon" aria-hidden="true">✓</span>
                                                            <span>Mark complete</span>
                                                        </button>
                                                    </form>
                                                @endif
                                                <a href="{{ route('site-surveys.show', $survey) }}?chat=1" class="row-actions-item" title="Edit content via AI chat">
                                                    <span class="row-actions-item__icon" aria-hidden="true">✎</span>
                                                    <span>Edit via AI chat</span>
                                                </a>
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
                                                {{-- Unified survey PDF (260517-su1): same blade renders both;
                                                     ?internal=0 = client-facing, ?internal=1 = engineer-internal. --}}
                                                <a href="{{ route('site-surveys.pdf', $survey) }}?internal=0" target="_blank" class="row-actions-item">
                                                    <span class="row-actions-item__icon" aria-hidden="true">📄</span>
                                                    <span>Download for Client</span>
                                                </a>
                                                <a href="{{ route('site-surveys.pdf', $survey) }}?internal=1" target="_blank" class="row-actions-item">
                                                    <span class="row-actions-item__icon" aria-hidden="true">↓</span>
                                                    <span>Download Internal</span>
                                                </a>
                                                <div class="row-actions-divider"></div>
                                                <form method="POST" action="{{ route('site-surveys.destroy', $survey) }}"
                                                      data-confirm="Delete this survey?"
                                                      data-confirm-label="Delete"
                                                      data-confirm-danger="1">
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
                                        <td class="py-3 px-3 text-center font-semibold text-accent-700">v{{ $ramsVersionMap[$rams->id] ?? '—' }}</td>
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
                                                       class="btn btn-primary btn-sm">✎ Review</a>

                                                @elseif ($status === \App\Models\RamsDocument::STATUS_APPROVED)
                                                    <form method="POST" action="{{ route('rams.retry-generation', $rams) }}" class="m-0 inline-block">
                                                        @csrf
                                                        <button type="submit"
                                                                class="btn btn-primary btn-sm">▶ Generate</button>
                                                    </form>

                                                @elseif (in_array($status, [
                                                    \App\Models\RamsDocument::STATUS_UPLOADED,
                                                    \App\Models\RamsDocument::STATUS_APPROVED_FOR_GENERATION,
                                                    \App\Models\RamsDocument::STATUS_GENERATING,
                                                ], true))
                                                    {{-- Batch 11 UX-05 — surface elapsed time on the processing
                                                         state. Past 5 minutes we shift the pill to amber and add
                                                         "taking longer than expected" so the operator knows to
                                                         check the worker log rather than waiting silently. --}}
                                                    @php
                                                        $elapsed = $rams->updated_at?->diffInMinutes(now()) ?? 0;
                                                        $stalled = $elapsed >= 5;
                                                    @endphp
                                                    <span class="inline-flex items-center gap-2 text-sm {{ $stalled ? 'text-amber-700' : 'text-gray-500' }}"
                                                          @if($stalled) title="Started {{ $rams->updated_at->diffForHumans() }} — this is longer than a typical run. Check the worker log." @endif>
                                                        <svg class="animate-spin h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 12a9 9 0 11-6.219-8.56" stroke-linecap="round"/></svg>
                                                        <span>{{ $stalled ? 'Taking longer than expected…' : 'Processing…' }}</span>
                                                        @if($stalled)
                                                            <span class="text-xs" style="font-variant-numeric: tabular-nums;">({{ $rams->updated_at->diffForHumans(null, true) }})</span>
                                                        @endif
                                                    </span>

                                                @elseif ($status === \App\Models\RamsDocument::STATUS_COMPLETED && $rams->filename)
                                                    <a href="{{ route('rams.review', $rams) }}"
                                                       class="inline-flex items-center bg-gray-900 hover:bg-gray-800 text-white px-3 py-1.5 rounded-md text-sm font-medium shadow-sm transition-all duration-150 hover:shadow hover:-translate-y-px active:translate-y-0 active:shadow-sm">View</a>
                                                    <a href="{{ route('rams.review', $rams) }}?chat=1"
                                                       class="btn btn-primary btn-sm" title="Edit content via AI chat">✎ AI Chat</a>
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
                                                              data-confirm="Rebuild the DOCX from the approved data?"
                                                              data-confirm-label="Regenerate">
                                                            @csrf
                                                            <button type="submit" class="row-actions-item">
                                                                <span class="row-actions-item__icon" aria-hidden="true">↻</span>
                                                                <span>Regenerate document</span>
                                                            </button>
                                                        </form>
                                                        <div class="row-actions-divider"></div>
                                                        <form method="POST" action="{{ route('rams.destroy', $rams) }}"
                                                              data-confirm="Delete this RAMS document? Admins can restore it later."
                                                              data-confirm-label="Delete"
                                                              data-confirm-danger="1">
                                                            @csrf @method('DELETE')
                                                            <button type="submit" class="row-actions-item row-actions-item--danger">
                                                                <span class="row-actions-item__icon" aria-hidden="true">🗑</span>
                                                                <span>Delete RAMS</span>
                                                            </button>
                                                        </form>
                                                    </x-row-actions-menu>
                                                    @php $ramsHasMenu = true; @endphp

                                                @elseif ($status === \App\Models\RamsDocument::STATUS_FAILED)
                                                    {{-- PRIMARY: Retry — failed rows have nothing else to act on.
                                                         Batch 11 UX-04 — surface the actual failure reason so the
                                                         user isn't left guessing why the job died. --}}
                                                    <span class="inline-flex items-center text-sm text-red-700 font-medium">⚠ Failed</span>
                                                    @if (! empty($rams->error_message))
                                                        <details class="inline-block m-0"
                                                                 style="vertical-align:middle;font-size:12px;">
                                                            <summary style="cursor:pointer;color:var(--ink-500);text-decoration:underline;text-decoration-style:dotted;">
                                                                See why
                                                            </summary>
                                                            <div style="max-width:360px;margin-top:4px;padding:8px 10px;background:var(--danger-light);color:#991B1B;border:1px solid color-mix(in oklab, var(--danger) 30%, transparent);border-radius:var(--radius-sm);white-space:pre-wrap;word-break:break-word;line-height:1.4;">
                                                                {{ $rams->error_message }}
                                                            </div>
                                                        </details>
                                                    @endif
                                                    @if (! empty($rams->reviewed_data))
                                                        <form method="POST" action="{{ route('rams.retry-generation', $rams) }}" class="m-0 inline-block">
                                                            @csrf
                                                            <button type="submit" class="btn btn-primary btn-sm">↻ Retry</button>
                                                        </form>
                                                    @else
                                                        <form method="POST" action="{{ route('rams.retry-extraction', $rams) }}" class="m-0 inline-block">
                                                            @csrf
                                                            <button type="submit" class="btn btn-primary btn-sm">↻ Retry</button>
                                                        </form>
                                                    @endif

                                                @elseif ($rams->filename && in_array($status, [
                                                    \App\Models\RamsDocument::STATUS_FOR_REVIEW,
                                                    \App\Models\RamsDocument::STATUS_DRAFT,
                                                ], true))
                                                    <a href="{{ route('rams.review', $rams) }}"
                                                       class="inline-flex items-center bg-gray-900 hover:bg-gray-800 text-white px-3 py-1.5 rounded-md text-sm font-medium shadow-sm transition-all duration-150 hover:shadow hover:-translate-y-px active:translate-y-0 active:shadow-sm">View</a>
                                                    <a href="{{ route('rams.review', $rams) }}?chat=1"
                                                       class="btn btn-primary btn-sm" title="Edit content via AI chat">✎ AI Chat</a>
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
                                                              data-confirm="Rebuild the DOCX from the approved data?"
                                                              data-confirm-label="Regenerate">
                                                            @csrf
                                                            <button type="submit" class="row-actions-item">
                                                                <span class="row-actions-item__icon" aria-hidden="true">↻</span>
                                                                <span>Regenerate document</span>
                                                            </button>
                                                        </form>
                                                        <div class="row-actions-divider"></div>
                                                        <form method="POST" action="{{ route('rams.destroy', $rams) }}"
                                                              data-confirm="Delete this RAMS document? Admins can restore it later."
                                                              data-confirm-label="Delete"
                                                              data-confirm-danger="1">
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
                                                              data-confirm="Delete this RAMS document? Admins can restore it later."
                                                              data-confirm-label="Delete"
                                                              data-confirm-danger="1">
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
                                        {{-- Stale-data pill (260602-o2a) — renders only when stale --}}
                                        @include('worksheets._stale-banner', ['worksheet' => $ws, 'variant' => 'pill'])
                                        {{-- Batch 11 UX-07 — ready-for-signoff pill. Green cue so
                                             the PM can see at a glance which worksheets to send
                                             out for client sign-off. --}}
                                        @if ($ws->isReadyForSignoff())
                                            <span class="inline-block ml-1 px-2 py-1 text-xs rounded"
                                                  style="background: var(--success-light); color: var(--success); border:1px solid color-mix(in oklab, var(--success) 30%, transparent);"
                                                  title="Engineer work captured — ready for the client to sign off">
                                                Ready to sign
                                            </span>
                                        @endif
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
                                                   class="btn btn-primary btn-sm" title="Edit content via AI chat">✎ AI Chat</a>
                                            @endif
                                            {{-- Engineer Report PDF (260602-rcd) — always visible; greyed
                                                 when no engineer activity yet (per locked decision). Uses
                                                 the project's defined .btn classes (NOT bg-brand-teal which
                                                 is undefined — see 260601-r4c hotfix lesson). --}}
                                            @if ($ws->hasEngineerActivity())
                                                <a href="{{ route('worksheets.engineer-report-pdf', $ws) }}"
                                                   target="_blank"
                                                   class="btn btn-outline btn-sm"
                                                   title="Download engineer report PDF">📄 Engineer Report</a>
                                            @else
                                                <button type="button" class="btn btn-outline btn-sm" disabled
                                                        title="No engineer activity yet">📄 Engineer Report</button>
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
                                                      data-confirm="Rebuild this worksheet from the current project data?"
                                                      data-confirm-label="Regenerate">
                                                    @csrf
                                                    <button type="submit" class="row-actions-item">
                                                        <span class="row-actions-item__icon" aria-hidden="true">↻</span>
                                                        <span>Regenerate worksheet</span>
                                                    </button>
                                                </form>
                                                <div class="row-actions-divider"></div>
                                                <form method="POST" action="{{ route('worksheets.destroy', $ws) }}"
                                                      data-confirm="Delete this worksheet?"
                                                      data-confirm-label="Delete"
                                                      data-confirm-danger="1">
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
                                               class="btn btn-primary btn-sm" title="Edit content via AI chat">✎ AI Chat</a>

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
                                                      data-confirm="Delete this cable schedule?"
                                                      data-confirm-label="Delete"
                                                      data-confirm-danger="1">
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
                                                {{-- Batch 11 UX-05 — same stalled-processing surface as the RAMS row above. --}}
                                                @php
                                                    $omElapsed = $manual->updated_at?->diffInMinutes(now()) ?? 0;
                                                    $omStalled = $omElapsed >= 5;
                                                @endphp
                                                <span class="inline-flex items-center gap-2 text-sm {{ $omStalled ? 'text-amber-700' : 'text-gray-500' }}"
                                                      @if($omStalled) title="Started {{ $manual->updated_at->diffForHumans() }} — this is longer than a typical run. Check the worker log." @endif>
                                                    <svg class="animate-spin h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 12a9 9 0 11-6.219-8.56" stroke-linecap="round"/></svg>
                                                    <span>{{ $omStalled ? 'Taking longer than expected…' : 'Processing…' }}</span>
                                                    @if($omStalled)
                                                        <span class="text-xs" style="font-variant-numeric: tabular-nums;">({{ $manual->updated_at->diffForHumans(null, true) }})</span>
                                                    @endif
                                                </span>
                                            @elseif ($manual->status === \App\Models\OmManual::STATUS_FAILED)
                                                {{-- 260727-om3 — failed O&M rows now lead with "Edit & Fix"
                                                     when the failure is a validation error (missing fields).
                                                     Blind retries just re-fail; the PM needs to open the edit
                                                     page, fill the gaps, then regenerate. Legacy Retry button
                                                     kept as a secondary action for transient AI/queue failures
                                                     that aren't data-related. --}}
                                                @php
                                                    // Detect validation-style failure by presence of the
                                                    // OmManualValidationException prefix in the stored message.
                                                    $isValidationFail = ! empty($manual->error_message)
                                                        && str_contains($manual->error_message, 'required fields missing');
                                                @endphp
                                                <span class="inline-flex items-center text-sm text-red-700 font-medium">⚠ Failed</span>
                                                @if (! empty($manual->error_message))
                                                    <details class="inline-block m-0" style="vertical-align:middle;font-size:12px;">
                                                        <summary style="cursor:pointer;color:var(--ink-500);text-decoration:underline;text-decoration-style:dotted;">See why</summary>
                                                        <div style="max-width:360px;margin-top:4px;padding:8px 10px;background:var(--danger-light);color:#991B1B;border:1px solid color-mix(in oklab, var(--danger) 30%, transparent);border-radius:var(--radius-sm);white-space:pre-wrap;word-break:break-word;line-height:1.4;">
                                                            {{ $manual->error_message }}
                                                        </div>
                                                    </details>
                                                @endif
                                                @if ($isValidationFail)
                                                    {{-- Primary CTA — jumps to edit page with an anchor so the
                                                         missing-fields banner scrolls into view immediately. --}}
                                                    <a href="{{ route('om-manuals.edit', $manual) }}#om-validation-errors"
                                                       class="btn btn-primary btn-sm"
                                                       title="Open the O&M edit page and fill in the missing fields">
                                                        🔧 Edit &amp; Fix
                                                    </a>
                                                @endif
                                                @if (! empty($manual->extracted_data))
                                                    <form method="POST" action="{{ route('om-manuals.retry-generation', $manual) }}" class="m-0 inline-block">
                                                        @csrf
                                                        <button type="submit" class="btn {{ $isValidationFail ? 'btn-outline' : 'btn-primary' }} btn-sm">↻ Retry</button>
                                                    </form>
                                                @endif
                                            @elseif ($manual->isGenerated())
                                                <a href="{{ route('om-manuals.edit', $manual) }}"
                                                   class="inline-flex items-center bg-gray-900 hover:bg-gray-800 text-white px-3 py-1.5 rounded-md text-sm font-medium shadow-sm transition-all duration-150 hover:shadow hover:-translate-y-px active:translate-y-0 active:shadow-sm">View</a>
                                                <a href="{{ route('om-manuals.edit', $manual) }}?chat=1"
                                                   class="btn btn-primary btn-sm" title="Edit content via AI chat">✎ AI Chat</a>

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
                                                          data-confirm="Rebuild this O&M manual from the existing data?"
                                                          data-confirm-label="Regenerate">
                                                        @csrf
                                                        <button type="submit" class="row-actions-item">
                                                            <span class="row-actions-item__icon" aria-hidden="true">↻</span>
                                                            <span>Regenerate manual</span>
                                                        </button>
                                                    </form>
                                                    <div class="row-actions-divider"></div>
                                                    <form method="POST" action="{{ route('om-manuals.destroy', $manual) }}"
                                                          data-confirm="Delete this O&M manual?"
                                                          data-confirm-label="Delete"
                                                          data-confirm-danger="1">
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
                                                   class="btn btn-primary btn-sm">✎ Review</a>
                                            @else
                                                <a href="{{ route('om-manuals.edit', $manual) }}"
                                                   class="inline-flex items-center bg-gray-900 hover:bg-gray-800 text-white px-3 py-1.5 rounded-md text-sm font-medium shadow-sm transition-all duration-150 hover:shadow hover:-translate-y-px active:translate-y-0 active:shadow-sm">View</a>
                                            @endif
                                            @if (empty($omHasMenu))
                                                <x-row-actions-menu>
                                                    <form method="POST" action="{{ route('om-manuals.destroy', $manual) }}"
                                                          data-confirm="Delete this O&M manual?"
                                                          data-confirm-label="Delete"
                                                          data-confirm-danger="1">
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
                                    <td class="py-3 px-3 text-center font-semibold text-accent-700">v{{ $pq->version_number }}</td>
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

                {{-- ───── Asset Register panel (260504-q19) ─────
                     Hardware-only filter (260506-qa9 hotfix): only show devices
                     whose part_no matches a quoted hardware line. Hides test
                     photos and any orphan label captures from the asset view
                     without touching the data — Project::hardwarePartNumbers()
                     is the single source of truth, also used by the Mini O&M. --}}
                <div x-show="activeTab==='assets'" x-cloak role="tabpanel">
                    @php
                        $hardwareParts = $project->hardwarePartNumbers();
                        $assetDevices = \App\Models\Device::where('project_id', $project->id)
                            ->with(['labelPhotos' => fn($q) => $q->orderByDesc('captured_at')])
                            ->orderBy('room_name')
                            ->orderBy('description')
                            ->get()
                            ->when(! empty($hardwareParts), fn($c) => $c->filter(function ($d) use ($hardwareParts) {
                                $part = strtolower(trim((string) ($d->part_no ?? '')));
                                return $part !== '' && in_array($part, $hardwareParts, true);
                            })->values())
                            ->groupBy(fn($d) => $d->room_name ?: 'Unassigned room');
                        $totalDevices    = $assetDevices->flatten()->count();
                        $totalPhotos     = $assetDevices->flatten()->sum(fn($d) => $d->labelPhotos->count());
                        $totalConfirmed  = $assetDevices->flatten()->sum(fn($d) => $d->labelPhotos->where('confirmed', true)->count());
                    @endphp

                    @if ($totalDevices === 0)
                        <x-empty-state title="No devices captured yet"
                            description="The asset register populates as engineers capture equipment labels via the public worksheet link. Generate a worksheet, share the link with the engineer, and they'll capture serial photos as part of the install."/>
                    @else
                        {{-- Counter strip --}}
                        <div class="flex flex-wrap gap-3 items-center mb-4 text-sm text-gray-700">
                            <span><strong class="text-gray-900">{{ $totalDevices }}</strong> device{{ $totalDevices === 1 ? '' : 's' }}</span>
                            <span class="text-gray-300">•</span>
                            <span><strong class="text-gray-900">{{ $totalPhotos }}</strong> label{{ $totalPhotos === 1 ? '' : 's' }} captured</span>
                            <span class="text-gray-300">•</span>
                            <span><strong class="text-gray-900">{{ $totalConfirmed }}</strong> confirmed</span>
                        </div>

                        {{-- Per-room device list --}}
                        @foreach ($assetDevices as $roomName => $roomDevices)
                            <div class="bg-white border border-gray-200 rounded-lg p-5 mb-4"
                                 data-name="{{ \Illuminate\Support\Str::lower($roomName) }}"
                                 x-show="q === '' || $el.dataset.name.includes(q.toLowerCase())">
                                <h3 class="text-base font-semibold text-accent-700 mb-3 flex items-center gap-2">
                                    {{ $roomName }}
                                    <span class="text-xs font-normal text-gray-500">
                                        {{ $roomDevices->count() }} {{ \Illuminate\Support\Str::plural('device', $roomDevices->count()) }}
                                    </span>
                                </h3>
                                <div class="flex flex-col gap-2">
                                    @foreach ($roomDevices as $d)
                                        @php
                                            $photos = $d->labelPhotos;
                                            $confirmedCount = $photos->where('confirmed', true)->count();
                                            // 260508 — full photo set (not just the first 3 thumbnails) so the
                                            // lightbox can cycle through every label captured for this device.
                                            $devicePhotosLb = $photos->values()->map(fn ($lp) => [
                                                'url'     => \Illuminate\Support\Facades\Storage::url($lp->photo_path),
                                                'caption' => trim(($d->description ?? '') . ' · ' . optional($lp->captured_at)->format('d M Y H:i'), ' ·'),
                                            ])->all();
                                        @endphp
                                        <div class="flex items-start gap-3 p-2.5 border border-gray-200 rounded-md bg-gray-50">
                                            {{-- Photo thumbnails --}}
                                            @if ($photos->isNotEmpty())
                                                <div class="flex gap-1 flex-shrink-0">
                                                    @foreach ($photos->take(3) as $lp)
                                                        <a href="{{ \Illuminate\Support\Facades\Storage::url($lp->photo_path) }}"
                                                           target="_blank" rel="noopener"
                                                           onclick="event.preventDefault(); openPhotoLightbox(@js($devicePhotosLb), {{ $loop->index }});"
                                                           title="Captured {{ optional($lp->captured_at)->format('d M Y H:i') }}{{ $lp->confirmed ? ' • confirmed' : ' • pending' }}"
                                                           class="block w-12 h-12 rounded overflow-hidden border"
                                                           style="border-color: {{ $lp->confirmed ? '#86EFAC' : '#FCD34D' }};">
                                                            <img src="{{ \Illuminate\Support\Facades\Storage::url($lp->photo_path) }}" alt=""
                                                                 class="w-full h-full object-cover block">
                                                        </a>
                                                    @endforeach
                                                    @if ($photos->count() > 3)
                                                        {{-- Overflow chip — clicking it opens the lightbox at the
                                                             first hidden photo (index 3) so the user can flip to
                                                             the rest without an extra click. --}}
                                                        <button type="button"
                                                                onclick="openPhotoLightbox(@js($devicePhotosLb), 3);"
                                                                class="inline-flex items-center justify-center w-12 h-12 rounded border border-dashed border-gray-300 text-xs text-gray-500 font-semibold hover:bg-gray-100 cursor-pointer"
                                                                title="View all {{ $photos->count() }} photos">
                                                            +{{ $photos->count() - 3 }}
                                                        </button>
                                                    @endif
                                                </div>
                                            @else
                                                <div class="w-12 h-12 border border-dashed border-gray-300 rounded flex items-center justify-center text-gray-400 text-xs flex-shrink-0">
                                                    📷
                                                </div>
                                            @endif

                                            {{-- Device details --}}
                                            <div class="flex-1 min-w-0 text-sm">
                                                <div class="font-semibold text-gray-900">{{ $d->description }}</div>
                                                <div class="text-xs text-gray-500 mt-0.5">
                                                    @if ($d->manufacturer){{ $d->manufacturer }}@if($d->model) — {{ $d->model }}@endif @endif
                                                    @if ($d->qty && $d->qty > 1) &middot; qty {{ $d->qty }}@endif
                                                </div>
                                                <div class="text-xs text-gray-700 mt-1.5 flex flex-wrap gap-x-3 gap-y-0.5">
                                                    <span><strong>Part:</strong> {{ $d->part_no ?? '—' }}</span>
                                                    <span><strong>Serial:</strong> {{ $d->serial_number ?? '—' }}</span>
                                                    <span><strong>MAC:</strong> {{ $d->mac_address ?? '—' }}</span>
                                                </div>
                                            </div>

                                            {{-- Status pill --}}
                                            <div class="flex-shrink-0">
                                                @if ($confirmedCount > 0)
                                                    <span class="inline-block px-2 py-0.5 rounded-full bg-green-100 text-green-800 font-bold text-[0.65rem] uppercase tracking-wider">✓ Confirmed</span>
                                                @elseif ($photos->count() > 0)
                                                    <span class="inline-block px-2 py-0.5 rounded-full bg-amber-100 text-amber-800 font-bold text-[0.65rem] uppercase tracking-wider">📷 {{ $photos->count() }} pending</span>
                                                @else
                                                    <span class="inline-block px-2 py-0.5 rounded-full bg-gray-100 text-gray-600 font-bold text-[0.65rem] uppercase tracking-wider">— No labels</span>
                                                @endif
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
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

            {{-- Actual Hours widget (Phase 15 D-13/D-14/D-15/D-16).
                 Controller passes $canSeeActualHours (bool) + $actualHours
                 (array|null). The partial trusts this include gate — it
                 does not re-check the flag internally. --}}
            @if ($canSeeActualHours && $actualHours !== null)
                @include('projects._actual-hours-widget', ['actualHours' => $actualHours])
            @endif

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

{{-- Engineer reference files (quick task 260601-r4c) — project-wide artifact
     channel for site plans / CAD / cable schedules / method statements that
     the on-site engineer pulls up via the worksheet or survey public link. --}}
@include('projects._engineer-reference-files-card', ['project' => $project])

{{-- Danger zone (only when archived) --}}
@if ($project->isArchived())
<div class="bg-white rounded-xl border-l-4 border-red-600 border-y border-r border-gray-100 shadow-sm p-6 mt-6">
    <h2 class="text-base font-semibold text-red-700 mb-2">Danger Zone</h2>
    <p class="text-sm text-gray-600 mb-3">
        Permanently delete this project and all associated data. This cannot be undone.
    </p>
    <form method="POST" action="{{ route('projects.destroy', $project) }}" class="m-0"
          data-confirm="Permanently delete project &quot;{{ $project->name }}&quot;? This cannot be undone."
          data-confirm-label="Delete Project"
          data-confirm-danger="1">
        @csrf @method('DELETE')
        <button type="submit"
                class="inline-flex items-center bg-red-600 hover:bg-red-700 text-white px-3 py-1.5 rounded-md text-sm font-medium">
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
