@extends('layouts.app')

@section('title', 'Confirm Deliverables')

@section('content')

<div class="page-header">
    <h1 class="page-title">Confirm Deliverables</h1>
    <a href="{{ route('quote-import.review', $package) }}" class="btn btn-outline btn-sm">← Back to review</a>
</div>

@if (session('error'))
    <div class="alert alert-error">{{ session('error') }}</div>
@endif

{{--
    260822-08 (D-16): this is the distinct interstitial step between review
    and confirm — a real route/view/controller action, not a fieldset bolted
    onto review.blade.php and not a modal. review.blade.php's confirm form
    now POSTs here (quote-import.deliverables-step); THIS form is what
    finally POSTs to quote-import.confirm.

    D-15: defaults ($defaults, computed server-side in
    QuoteImportController::deliverablesStep()) pre-fill this checklist from
    quote content — no labour/install/commissioning/RAMS/site-survey line
    on the quote (EquipmentCategoryClassifier's single collapsed `services`
    bucket) means Site Survey, RAMS and Worksheet default to Not required.
    Every other deliverable always defaults to Not yet decided — the
    classifier signal does not support a positive "definitely required"
    default for anything.
--}}

<div class="card" style="max-width:720px;">
    <p style="color:var(--muted); font-size:13px; margin-bottom:1.25rem;">
        Confirm what this project actually needs before it's created. Each item
        below is pre-filled from the quote where possible — review and adjust,
        then confirm to open the project.
    </p>

    <form method="POST" action="{{ route('quote-import.confirm', $package) }}" id="deliverablesForm">
        @csrf

        {{-- Carry forward the 7 review-form fields unchanged. --}}
        <input type="hidden" name="name" value="{{ $validated['name'] }}">
        <input type="hidden" name="ref" value="{{ $validated['ref'] ?? '' }}">
        <input type="hidden" name="client_name" value="{{ $validated['client_name'] }}">
        <input type="hidden" name="site_address" value="{{ $validated['site_address'] }}">
        <input type="hidden" name="works_description" value="{{ $validated['works_description'] ?? '' }}">
        <input type="hidden" name="project_id" value="{{ $validated['project_id'] ?? '' }}">
        <input type="hidden" name="new_project" value="{{ ! empty($validated['new_project']) ? '1' : '0' }}">

        @php
            $deliverableLabels = [
                \App\Models\ProjectDeliverable::KEY_SITE_SURVEY       => 'Site Survey',
                \App\Models\ProjectDeliverable::KEY_RAMS              => 'RAMS',
                \App\Models\ProjectDeliverable::KEY_WORKSHEET         => 'Worksheet',
                \App\Models\ProjectDeliverable::KEY_OM                => 'O&M',
                \App\Models\ProjectDeliverable::KEY_CABLE_SCHEDULE    => 'Cable Schedule',
                \App\Models\ProjectDeliverable::KEY_INSTALL_PROGRAMME => 'Install Programme',
                \App\Models\ProjectDeliverable::KEY_DRAWINGS          => 'Drawings',
                \App\Models\ProjectDeliverable::KEY_SNAGGING          => 'Snagging',
                \App\Models\ProjectDeliverable::KEY_PROGRAMMING       => 'Programming',
            ];
        @endphp

        <div style="display:flex; flex-direction:column; gap:.75rem; margin-bottom:1.25rem;">
            @foreach (\App\Models\ProjectDeliverable::ALL_KEYS as $key)
                @php $defaultState = $defaults[$key]; @endphp
                <div class="form-group" style="display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap;">
                    <label class="form-label" style="margin-bottom:0;">{{ $deliverableLabels[$key] }}</label>
                    <div style="display:flex; align-items:center; gap:1rem; font-size:13px; color:var(--ink-700, #374151);">
                        <label style="display:inline-flex; align-items:center; gap:.35rem;">
                            <input type="radio" name="deliverables[{{ $key }}]"
                                   value="{{ \App\Models\ProjectDeliverable::STATE_REQUIRED }}"
                                   {{ $defaultState === \App\Models\ProjectDeliverable::STATE_REQUIRED ? 'checked' : '' }}>
                            Required
                        </label>
                        <label style="display:inline-flex; align-items:center; gap:.35rem;">
                            <input type="radio" name="deliverables[{{ $key }}]"
                                   value="{{ \App\Models\ProjectDeliverable::STATE_NOT_REQUIRED }}"
                                   {{ $defaultState === \App\Models\ProjectDeliverable::STATE_NOT_REQUIRED ? 'checked' : '' }}>
                            Not required
                        </label>
                        <label style="display:inline-flex; align-items:center; gap:.35rem;">
                            <input type="radio" name="deliverables[{{ $key }}]"
                                   value="{{ \App\Models\ProjectDeliverable::STATE_NOT_YET_DECIDED }}"
                                   {{ $defaultState === \App\Models\ProjectDeliverable::STATE_NOT_YET_DECIDED ? 'checked' : '' }}>
                            Not yet decided
                        </label>
                    </div>
                </div>
            @endforeach
        </div>

        <div style="display:flex; gap:.75rem; flex-wrap:wrap;">
            <button type="submit" class="btn btn-teal">Confirm &amp; Open Project</button>
            <a href="{{ route('quote-import.review', $package) }}" class="btn btn-outline">← Back to review</a>
        </div>
    </form>
</div>

@endsection
