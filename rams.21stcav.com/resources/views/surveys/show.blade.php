<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0B3C45">
    <title>Site Survey — {{ $survey->project_name }}</title>

    {{-- Tailwind CDN — play build processes arbitrary classes at runtime via MutationObserver --}}
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: { dark: '#0B3C45', teal: '#178A95', gold: '#C9922A' },
                    },
                },
            },
        };
    </script>

    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js"></script>

    {{-- Single functional style rule required by Alpine.js --}}
    <style>[x-cloak] { display: none !important; }</style>
</head>

<body class="bg-gray-100 min-h-screen" x-data="surveyWizard()" x-cloak>

{{-- ═══════════════════════════════════════════════════════════
     HEADER — sticky, shows project info + step progress bar
════════════════════════════════════════════════════════════ --}}
<header class="bg-brand-dark text-white px-4 pt-4 pb-3 sticky top-0 z-20 shadow-md">
    <div class="max-w-xl mx-auto">
        <p class="text-[10px] font-bold uppercase tracking-widest text-white/50 mb-0.5">
            21st Century AV · Site Survey
        </p>
        <h1 class="text-lg font-bold leading-tight">{{ $survey->project_name }}</h1>
        <p class="text-xs text-white/60 mt-0.5 truncate">{{ $survey->site_address }}</p>

        {{-- ── 260602-mlt — Site contact line ──────────────────────────────
             Mirror of the worksheet header line. Reads the same flat package
             keys (extracted_data['ship_contact'/'ship_phone']) and falls back
             to SiteSurvey.site_contact_name / site_contact_phone for projects
             whose package was never re-extracted post-mlt. UK normalisation
             (leading '0' → '+44') on the tel: href; original formatting kept
             in the visible label. Renders nothing when BOTH are empty. --}}
        @php
            $pkg = optional($survey->project)->latestPackage;
            $ed  = is_array($pkg?->extracted_data) ? $pkg->extracted_data : [];
            $siteContactName  = trim((string) (
                ($ed['ship_contact'] ?? '') !== ''
                    ? $ed['ship_contact']
                    : ($survey->site_contact_name ?? '')
            ));
            $siteContactPhone = trim((string) (
                ($ed['ship_phone'] ?? '') !== ''
                    ? $ed['ship_phone']
                    : ($survey->site_contact_phone ?? '')
            ));
            $telHref = '';
            if ($siteContactPhone !== '') {
                $digits = preg_replace('/\s+/', '', $siteContactPhone);
                $telHref = (str_starts_with($digits, '0'))
                    ? '+44' . substr($digits, 1)
                    : $digits;
            }
        @endphp
        @if($siteContactName !== '' || $siteContactPhone !== '')
            <p class="text-xs text-white/60 mt-0.5 truncate">
                Site contact:
                @if($siteContactName !== ''){{ ' ' . $siteContactName }}@endif
                @if($siteContactName !== '' && $siteContactPhone !== '') · @endif
                @if($siteContactPhone !== '')<a href="tel:{{ $telHref }}" class="underline text-white/80">{{ $siteContactPhone }}</a>@endif
            </p>
        @endif

        {{-- Step progress — only visible during step wizard --}}
        <div x-show="screen === 'step'" x-cloak class="mt-2.5">
            <div class="flex justify-between items-center mb-1">
                <span class="text-xs text-white/70 font-medium truncate pr-2"
                      x-text="currentRoom?.name || ''"></span>
                <span class="text-xs text-white/60 flex-shrink-0"
                      x-text="'Step ' + currentStep + ' of 8 — ' + stepTitle"></span>
            </div>
            {{-- Progress bar width via Tailwind CDN JIT arbitrary class — no style= attribute --}}
            <div class="bg-white/20 rounded-full h-1.5 overflow-hidden">
                <div class="bg-emerald-400 h-full rounded-full transition-all duration-300"
                     :class="'w-[' + Math.round(currentStep / 8 * 100) + '%]'"></div>
            </div>
        </div>
    </div>
</header>

{{-- ── Save / validation status ribbons ───────────────────────── --}}
<div x-show="saving"
     class="bg-yellow-50 border-b border-yellow-200 text-yellow-800 text-xs text-center
            py-1.5 sticky top-[72px] z-10">
    Saving…
</div>
<div x-show="!saving && lastSaved" x-cloak
     class="bg-emerald-50 border-b border-emerald-200 text-emerald-700 text-xs text-center
            py-1.5 sticky top-[72px] z-10">
    ✓ Saved
    <span x-text="lastSaved
        ? lastSaved.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'})
        : ''"></span>
</div>
<div x-show="saveError"
     class="bg-red-50 border-b border-red-200 text-red-700 text-xs text-center
            py-1.5 sticky top-[72px] z-10"
     x-text="saveError"></div>
<div x-show="screen === 'step' && validationError" x-cloak
     class="bg-orange-50 border-b border-orange-200 text-orange-700 text-xs text-center
            py-1.5 sticky top-[72px] z-10"
     x-text="validationError"></div>

{{-- ═══════════════════════════════════════════════════════════
     MAIN CONTENT
════════════════════════════════════════════════════════════ --}}
<main class="max-w-xl mx-auto px-4 py-4 pb-28">

    {{-- ──────────────────────────────────────────────────────
         SCREEN: ROOM LIST
    ─────────────────────────────────────────────────────── --}}
    <div x-show="screen === 'rooms'">

        {{-- Summary card --}}
        <div class="bg-white rounded-2xl p-4 mb-4 shadow-sm">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-semibold text-gray-900">{{ $survey->client_name }}</p>
                    <p class="text-xs text-gray-500 mt-0.5">
                        {{ $survey->survey_date?->format('d M Y') ?? 'Date TBC' }}
                        @if($survey->surveyor_name) · {{ $survey->surveyor_name }} @endif
                    </p>
                </div>
                <div class="text-right">
                    <p class="text-3xl font-bold text-brand-teal leading-none"
                       x-text="completedCount + '/' + rooms.length"></p>
                    <p class="text-xs text-gray-500 mt-0.5">rooms done</p>
                </div>
            </div>
            {{-- Completion bar — :class with CDN JIT arbitrary value, no style= --}}
            <div class="mt-3 bg-gray-100 rounded-full h-2 overflow-hidden">
                <div class="bg-emerald-400 h-full rounded-full transition-all duration-500"
                     :class="'w-[' + Math.round(rooms.length ? completedCount / rooms.length * 100 : 0) + '%]'"></div>
            </div>

        </div>

        {{-- ── Engineer reference files (quick task 260601-r4c) ─────────
             Project-level uploaded artifacts visible to the engineer on the
             rooms-list screen, ABOVE Site Logistics. Drawer hides itself
             when there are no files. --}}
        @include('partials._engineer-reference-drawer', [
            'files'          => optional($survey->project)->referenceFiles?->sortByDesc('uploaded_at') ?? collect(),
            'serveRouteName' => 'public-survey.files.serve',
            'token'          => $token,
        ])

        {{-- ── Site Logistics — engineer-feedback site-level capture ──────
             (quick task 260503-u2x) Captured once per visit on the rooms-list
             screen; persists via stepSave step=0; mirrored to SiteSurvey DB
             columns so RAMS pipeline (260503-tfb) sees the data immediately. --}}
        <div class="bg-white rounded-2xl p-4 mb-4 shadow-sm">
            <div class="flex items-center gap-2 mb-3">
                <span class="text-base">🏢</span>
                <h2 class="text-sm font-bold text-gray-900">Site Logistics</h2>
                <span class="text-xs text-gray-500 ml-auto" x-show="!siteLogisticsSaving && siteLogisticsLastSaved" x-cloak>
                    ✓ saved
                </span>
                <span class="text-xs text-amber-700" x-show="siteLogisticsSaving" x-cloak>saving…</span>
            </div>
            <p class="text-xs text-gray-500 mb-3 leading-snug">
                Capture once per visit — feeds RAMS, install programme, and access planning.
            </p>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Comms Room Access</label>
                    <select x-model="engineerFeedbackSite.comms_room_access_status"
                            :disabled="readonly"
                            class="w-full border border-gray-300 rounded-xl px-3 py-2.5 text-base
                                   focus:outline-none focus:ring-2 focus:ring-brand-teal min-h-[44px]">
                        <option value="">— Select —</option>
                        <option value="yes">Yes — engineer needs permission</option>
                        <option value="no">No — open access</option>
                        <option value="outsourced">Outsourced (third-party)</option>
                        <option value="unknown">Unknown</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Distance from Base (miles)</label>
                    <input type="number" min="0" max="9999" step="0.1"
                           x-model.number="engineerFeedbackSite.distance_from_base_miles"
                           :disabled="readonly"
                           placeholder="e.g. 47.5"
                           class="w-full border border-gray-300 rounded-xl px-3 py-2.5 text-base
                                  focus:outline-none focus:ring-2 focus:ring-brand-teal min-h-[44px]">
                </div>
            </div>

            <div class="space-y-3">
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Comms Room Access — Notes</label>
                    <textarea x-model="engineerFeedbackSite.comms_room_access_notes"
                              :disabled="readonly" rows="2" maxlength="2000"
                              placeholder="e.g. Permit required 48h notice; key from FM desk Mon-Fri 9-5"
                              class="w-full border border-gray-300 rounded-xl px-3 py-2 text-sm
                                     focus:outline-none focus:ring-2 focus:ring-brand-teal"></textarea>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Route / Travel Notes</label>
                    <textarea x-model="engineerFeedbackSite.distance_from_base_notes"
                              :disabled="readonly" rows="2" maxlength="2000"
                              placeholder="e.g. M25 J7 then 12mi A23; allow 2h in rush hour"
                              class="w-full border border-gray-300 rounded-xl px-3 py-2 text-sm
                                     focus:outline-none focus:ring-2 focus:ring-brand-teal"></textarea>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Parking Restraints</label>
                    <textarea x-model="engineerFeedbackSite.parking_restraints"
                              :disabled="readonly" rows="2" maxlength="2000"
                              placeholder="e.g. No on-street parking, NCP £18/day; loading bay 8am-10am only"
                              class="w-full border border-gray-300 rounded-xl px-3 py-2 text-sm
                                     focus:outline-none focus:ring-2 focus:ring-brand-teal"></textarea>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Site Access Notes</label>
                    <textarea x-model="engineerFeedbackSite.site_access_notes"
                              :disabled="readonly" rows="3" maxlength="3000"
                              placeholder="e.g. Loading bay south side; goods lift 1.8×1.4×2.2m, 500kg; security pass from reception"
                              class="w-full border border-gray-300 rounded-xl px-3 py-2 text-sm
                                     focus:outline-none focus:ring-2 focus:ring-brand-teal"></textarea>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Delivery Routes</label>
                    <textarea x-model="engineerFeedbackSite.delivery_routes"
                              :disabled="readonly" rows="3" maxlength="3000"
                              placeholder="e.g. Deliveries to bay 4, 7am-11am; contact Site Manager 0207… 1h before arrival"
                              class="w-full border border-gray-300 rounded-xl px-3 py-2 text-sm
                                     focus:outline-none focus:ring-2 focus:ring-brand-teal"></textarea>
                </div>
            </div>
        </div>

        @if ($readonly)
            <div class="bg-amber-50 border border-amber-200 rounded-2xl p-3 mb-4
                        text-amber-800 text-sm font-medium text-center">
                This survey has been submitted and is read-only.
            </div>
        @endif

        @if (!$readonly)
            {{-- Submit panel — pinned at the top so engineers don't miss it
                 once every room is complete. Only renders when allComplete. --}}
            <div x-show="allComplete" x-cloak
                 class="bg-brand-gold/10 border border-brand-gold rounded-2xl p-4 shadow-sm mb-4">
                <p class="text-sm font-semibold text-gray-800 mb-2">
                    ✅ All rooms complete — ready to submit
                </p>
                <label class="block text-xs font-semibold text-gray-700 mb-1">
                    Your Name <span class="text-red-500">*</span>
                </label>
                <input type="text"
                       x-model="surveyorName"
                       placeholder="Full name for submission"
                       class="w-full border border-gray-300 rounded-xl px-3 py-2.5 text-base
                              focus:outline-none focus:ring-2 focus:ring-brand-teal min-h-[44px] mb-3">
                <form method="POST" action="{{ route('survey.submit', $token) }}" id="submit-form-top"
                      @submit.prevent="submitSurveyTop()">
                    @csrf
                    <input type="hidden" name="surveyor_name" :value="surveyorName">
                    <input type="hidden" name="survey_date" value="{{ now()->format('Y-m-d') }}">
                    <button type="submit"
                            :disabled="submitting || !surveyorName.trim()"
                            class="w-full py-4 rounded-2xl font-bold text-base min-h-[56px]
                                   transition-colors shadow-md"
                            :class="(surveyorName.trim() && !submitting)
                                ? 'bg-brand-gold text-white hover:bg-amber-600'
                                : 'bg-gray-200 text-gray-400 cursor-not-allowed'">
                        <span x-show="!submitting">Submit Survey ✓</span>
                        <span x-show="submitting">Submitting…</span>
                    </button>
                </form>
            </div>
        @endif

        {{-- Download printable PDF form — offline/manual completion fallback --}}
        <a href="{{ route('survey.download.form', ['token' => $token]) }}"
           target="_blank" rel="noopener"
           class="block w-full mb-4 px-4 py-3 rounded-2xl bg-white border border-gray-200
                  text-center text-sm font-semibold text-brand-teal shadow-sm
                  hover:bg-gray-50 min-h-[44px]">
            📄 Download PDF Form
            <span class="block text-[11px] font-normal text-gray-500 mt-0.5">
                Complete on paper if offline — return to office for processing
            </span>
        </a>

        {{-- Room cards --}}
        <template x-for="(room, idx) in rooms" :key="room._ui.room_id">
            <div class="bg-white rounded-2xl mb-3 shadow-sm overflow-hidden">
                <div class="flex items-center p-4 gap-3">

                    <div class="flex-shrink-0 w-9 h-9 rounded-full flex items-center justify-center"
                         :class="room._ui.is_completed
                             ? 'bg-emerald-100 text-emerald-600'
                             : 'bg-gray-100 text-gray-400'">
                        <svg x-show="room._ui.is_completed" class="w-5 h-5"
                             fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd"
                                  d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414
                                     0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"
                                  clip-rule="evenodd"/>
                        </svg>
                        <svg x-show="!room._ui.is_completed" class="w-5 h-5"
                             fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <circle cx="12" cy="12" r="9"/>
                        </svg>
                    </div>

                    <button type="button"
                            @click="expandedRoomIdx = (expandedRoomIdx === idx ? null : idx)"
                            class="flex-1 min-w-0 text-left flex items-center gap-2 cursor-pointer"
                            aria-label="Toggle room details">
                        <div class="flex-1 min-w-0">
                            <p class="font-semibold text-gray-900 leading-tight"
                               x-text="room.name || 'Unnamed Room'"></p>
                            <p class="text-xs text-gray-500 mt-0.5"
                               x-text="[room.type, room._ui.work_type]
                                   .filter(Boolean)
                                   .map(s => s.replace(/_/g, ' '))
                                   .join(' · ') || 'Tap to expand'"></p>
                        </div>
                        <svg class="w-4 h-4 text-gray-400 transition-transform flex-shrink-0"
                             :class="expandedRoomIdx === idx ? 'rotate-180' : ''"
                             fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>

                    <button @click="selectRoom(idx)"
                            class="flex-shrink-0 flex items-center gap-1 px-4 py-2.5 rounded-xl
                                   text-sm font-bold min-h-[44px] transition-colors"
                            :class="room._ui.is_completed
                                ? 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                                : 'bg-brand-teal text-white hover:bg-[#0d6e77]'">
                        <span x-text="(@json($readonly) || room._ui.is_completed) ? 'Review' : 'Start'"></span>
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24"
                             stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                        </svg>
                    </button>

                </div>

                {{-- Job context — per-room planned works, quote kit, and
                     checklist guidance count. Hidden by default; chevron in
                     the card header expands. Read-only, informational. --}}
                <template x-if="expandedRoomIdx === idx && room._ctx && (room._ctx.av_requirements || room._ctx.av_equipment_list || room._ctx.question_count > 0 || (room._ctx.checklist_lines && room._ctx.checklist_lines.length > 0) || (room._ctx.planned_actions && room._ctx.planned_actions.length > 0))">
                    <div class="px-3 pb-3 pt-2.5 border-t border-gray-100 bg-gray-50/60 text-xs space-y-2">
                        {{-- INSTALL ACTIONS — collapsed by default --}}
                        <template x-if="room._ctx.planned_actions && room._ctx.planned_actions.length > 0">
                            <details class="group bg-white rounded-xl border-2 border-brand-teal/30 shadow-sm overflow-hidden hover:border-brand-teal transition-colors">
                                <summary class="px-3.5 py-3 cursor-pointer select-none flex items-center justify-between list-none bg-brand-teal/5 hover:bg-brand-teal/10 transition-colors min-h-[44px]">
                                    <span class="font-bold text-brand-teal flex items-center gap-2 text-sm">
                                        <span class="text-base">📋</span>
                                        <span>Install actions</span>
                                        <span class="inline-flex items-center justify-center min-w-[22px] h-5 px-1.5 rounded-full bg-brand-teal text-white text-[11px] font-bold tabular-nums"
                                              x-text="room._ctx.planned_actions.length"></span>
                                    </span>
                                    <span class="text-brand-teal text-base group-open:rotate-180 transition-transform">▾</span>
                                </summary>
                                <ul class="list-disc pl-7 pr-3 pb-3 pt-2.5 text-gray-700 space-y-0.5 leading-snug">
                                    <template x-for="(b, bi) in room._ctx.planned_actions" :key="bi">
                                        <li x-text="b.replace(/^[-•]\s*/, '')"></li>
                                    </template>
                                </ul>
                            </details>
                        </template>
                        {{-- PLANNED AV WORKS prose (only when no bullet list) --}}
                        <template x-if="room._ctx.av_requirements && (!room._ctx.planned_actions || room._ctx.planned_actions.length === 0)">
                            <details class="group bg-white rounded-xl border-2 border-brand-teal/30 shadow-sm overflow-hidden hover:border-brand-teal transition-colors">
                                <summary class="px-3.5 py-3 cursor-pointer select-none flex items-center justify-between list-none bg-brand-teal/5 hover:bg-brand-teal/10 transition-colors min-h-[44px]">
                                    <span class="font-bold text-brand-teal flex items-center gap-2 text-sm">
                                        <span class="text-base">📋</span>
                                        <span>Planned AV works</span>
                                    </span>
                                    <span class="text-brand-teal text-base group-open:rotate-180 transition-transform">▾</span>
                                </summary>
                                <p class="px-3.5 pb-3 pt-2.5 text-gray-700 leading-snug" x-text="room._ctx.av_requirements"></p>
                            </details>
                        </template>
                        {{-- QUOTE KIT — rendered as a parsed list --}}
                        <template x-if="room._ctx.av_equipment_list">
                            <details class="group bg-white rounded-xl border-2 border-brand-gold/40 shadow-sm overflow-hidden hover:border-brand-gold transition-colors">
                                <summary class="px-3.5 py-3 cursor-pointer select-none flex items-center justify-between list-none bg-brand-gold/5 hover:bg-brand-gold/10 transition-colors min-h-[44px]">
                                    <span class="font-bold text-brand-gold flex items-center gap-2 text-sm">
                                        <span class="text-base">📦</span>
                                        <span>Quote kit</span>
                                        <span class="inline-flex items-center justify-center min-w-[22px] h-5 px-1.5 rounded-full bg-brand-gold text-white text-[11px] font-bold tabular-nums"
                                              x-text="parseKitLines(room._ctx.av_equipment_list).length"></span>
                                    </span>
                                    <span class="text-brand-gold text-base group-open:rotate-180 transition-transform">▾</span>
                                </summary>
                                <ul class="px-3.5 pb-3 pt-2 divide-y divide-gray-100 text-gray-800">
                                    <template x-for="(item, ki) in parseKitLines(room._ctx.av_equipment_list)" :key="ki">
                                        <li class="flex items-start gap-2 leading-snug py-1.5">
                                            <span class="inline-flex items-center justify-center min-w-[32px] h-6 px-2 rounded bg-brand-gold/15 text-brand-gold text-[11px] font-bold tabular-nums flex-shrink-0"
                                                  x-text="item.qty ? item.qty + '×' : '·'"></span>
                                            <span class="flex-1" x-text="item.name"></span>
                                        </li>
                                    </template>
                                </ul>
                            </details>
                        </template>
                        {{-- REFERENCE CHECKLIST --}}
                        <template x-if="room._ctx.checklist_lines && room._ctx.checklist_lines.length > 0">
                            <details class="group bg-white rounded-xl border border-amber-200 shadow-sm overflow-hidden hover:border-amber-400 transition-colors">
                                <summary class="px-3.5 py-2.5 cursor-pointer select-none flex items-center justify-between list-none bg-amber-50/60 hover:bg-amber-50 transition-colors min-h-[44px]">
                                    <span class="font-semibold text-amber-800 flex items-center gap-2 text-sm">
                                        <span>🔖</span>
                                        <span>Reference checklist</span>
                                        <template x-if="room._ctx.solution_type_name">
                                            <span class="text-amber-600/80 font-normal text-xs">— <span x-text="room._ctx.solution_type_name"></span></span>
                                        </template>
                                    </span>
                                    <span class="text-amber-700 group-open:rotate-180 transition-transform">▾</span>
                                </summary>
                                <ul class="list-disc pl-7 pr-3 pb-3 pt-2 text-gray-700 space-y-0.5 leading-snug">
                                    <template x-for="(line, li) in room._ctx.checklist_lines" :key="li">
                                        <li x-text="line"></li>
                                    </template>
                                </ul>
                            </details>
                        </template>
                        {{-- PRE-INSTALL CHECKLIST --}}
                        <template x-if="room._ctx.question_count > 0">
                            <details class="group bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden hover:border-gray-300 transition-colors">
                                <summary class="px-3.5 py-2.5 cursor-pointer select-none flex items-center justify-between list-none bg-gray-50 hover:bg-gray-100 transition-colors min-h-[44px]">
                                    <span class="font-semibold text-gray-700 flex items-center gap-2 text-sm">
                                        <span>✅</span>
                                        <span>Pre-install checklist</span>
                                        <span class="inline-flex items-center justify-center min-w-[22px] h-5 px-1.5 rounded-full bg-gray-300 text-gray-800 text-[11px] font-bold tabular-nums"
                                              x-text="room._ctx.question_count"></span>
                                    </span>
                                    <span class="text-gray-500 group-open:rotate-180 transition-transform">▾</span>
                                </summary>
                                <ul class="list-disc pl-7 pr-3 pb-3 pt-2 text-gray-700 space-y-0.5 leading-snug">
                                    <template x-for="(q, qi) in room._ctx.questions" :key="qi">
                                        <li>
                                            <span x-text="q.question"></span>
                                            <span x-show="q.answered" class="text-emerald-600">✓</span>
                                        </li>
                                    </template>
                                </ul>
                            </details>
                        </template>
                    </div>
                </template>
            </div>
        </template>

        @if (!$readonly)
            <div x-show="!allComplete"
                 class="mt-2 text-center text-xs text-gray-400 py-2">
                Complete all rooms to unlock submission.
            </div>
        @endif

    </div>{{-- /screen:rooms --}}


    {{-- ──────────────────────────────────────────────────────
         SCREEN: STEP WIZARD
    ─────────────────────────────────────────────────────── --}}
    <div x-show="screen === 'step'">

        {{-- Step heading row --}}
        <div class="flex items-center gap-3 mb-4">
            <button @click="prevStep()"
                    class="w-10 h-10 bg-white rounded-full flex items-center justify-center
                           shadow-sm hover:bg-gray-50 transition-colors flex-shrink-0 min-h-[44px]">
                <svg class="w-5 h-5 text-gray-600" fill="none" viewBox="0 0 24 24"
                     stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                </svg>
            </button>
            <div class="min-w-0 flex-1">
                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide"
                   x-text="'Step ' + currentStep + ' — ' + stepTitle"></p>
                <p class="font-bold text-gray-900 text-lg leading-tight truncate"
                   x-text="currentRoom?.name || 'Room'"></p>
            </div>
            {{-- Description + Kit drawer toggles — branded so engineers can
                 spot them at a glance: teal for AV works description, gold
                 for the quote kit list. Both work from any wizard step. --}}
            <div class="flex items-center gap-2 flex-shrink-0">
                <button type="button"
                        @click="descriptionDrawerOpen = true"
                        class="h-11 px-3 rounded-xl flex items-center gap-1.5
                               bg-brand-teal text-white shadow-md hover:bg-[#0d6e77]
                               transition-colors min-h-[44px] font-bold text-sm"
                        aria-label="Show AV works description for this room"
                        title="AV Works Description">
                    <span class="text-base leading-none">📋</span>
                    <span>AV Works</span>
                </button>
                <button type="button"
                        @click="kitDrawerOpen = true"
                        class="h-11 px-3 rounded-xl flex items-center gap-1.5
                               bg-brand-gold text-white shadow-md hover:bg-amber-600
                               transition-colors min-h-[44px] font-bold text-sm"
                        aria-label="Show kit list for this room"
                        title="Kit List">
                    <span class="text-base leading-none">📦</span>
                    <span>Kit List</span>
                </button>
            </div>
        </div>

        {{-- ── DESCRIPTION DRAWER ────────────────────────────────────
             Step screen → current room's planned works / install actions /
             reference checklist.
             Rooms screen → project-wide rollup of every room's description. --}}
        <div x-show="descriptionDrawerOpen" x-cloak
             x-transition.opacity
             @click="descriptionDrawerOpen = false"
             class="fixed inset-0 z-40 bg-black/50"></div>

        <aside x-show="descriptionDrawerOpen" x-cloak
               x-transition:enter="transition transform ease-out duration-200"
               x-transition:enter-start="translate-x-full"
               x-transition:enter-end="translate-x-0"
               x-transition:leave="transition transform ease-in duration-150"
               x-transition:leave-start="translate-x-0"
               x-transition:leave-end="translate-x-full"
               class="fixed top-0 right-0 bottom-0 z-50 w-[88%] max-w-md bg-white shadow-2xl
                      flex flex-col">
            <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200">
                <div class="min-w-0">
                    <p class="text-xs uppercase tracking-wide text-gray-500 font-semibold">📋 Description</p>
                    <p class="font-bold text-gray-900 truncate"
                       x-text="screen === 'step' ? (currentRoom?.name || 'Room') : 'All rooms'"></p>
                </div>
                <button type="button"
                        @click="descriptionDrawerOpen = false"
                        class="w-10 h-10 rounded-full hover:bg-gray-100 flex items-center justify-center min-h-[44px]"
                        aria-label="Close">
                    <svg class="w-5 h-5 text-gray-600" fill="none" viewBox="0 0 24 24"
                         stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
            <div class="flex-1 overflow-y-auto px-4 py-4 space-y-4 text-sm">
                {{-- WIZARD CONTEXT — current room only --}}
                <template x-if="screen === 'step'">
                    <div class="space-y-4">
                        <template x-if="(currentRoom?._ctx?.planned_actions ?? []).length > 0">
                            <div>
                                <p class="text-[10px] font-semibold uppercase tracking-wide text-gray-500 mb-1">Install actions</p>
                                <ul class="list-disc pl-5 text-gray-800 space-y-1 leading-snug">
                                    <template x-for="(b, bi) in currentRoom._ctx.planned_actions" :key="bi">
                                        <li x-text="b.replace(/^[-•]\s*/, '')"></li>
                                    </template>
                                </ul>
                            </div>
                        </template>
                        <template x-if="currentRoom?._ctx?.av_requirements && (!currentRoom?._ctx?.planned_actions || currentRoom._ctx.planned_actions.length === 0)">
                            <div>
                                <p class="text-[10px] font-semibold uppercase tracking-wide text-gray-500 mb-1">Planned AV works</p>
                                <p class="text-gray-800 leading-snug" x-text="currentRoom._ctx.av_requirements"></p>
                            </div>
                        </template>
                        <template x-if="currentRoom?._ctx?.checklist_lines?.length > 0">
                            <div>
                                <p class="text-[10px] font-semibold uppercase tracking-wide text-gray-500 mb-1">
                                    Reference checklist
                                    <template x-if="currentRoom._ctx.solution_type_name">
                                        <span class="text-gray-400 normal-case font-normal">— <span x-text="currentRoom._ctx.solution_type_name"></span></span>
                                    </template>
                                </p>
                                <ul class="list-disc pl-5 text-gray-800 space-y-1 leading-snug">
                                    <template x-for="(line, li) in currentRoom._ctx.checklist_lines" :key="li">
                                        <li x-text="line"></li>
                                    </template>
                                </ul>
                            </div>
                        </template>
                        <template x-if="!currentRoom?._ctx?.av_requirements && !currentRoom?._ctx?.checklist_lines?.length && (!currentRoom?._ctx?.planned_actions || currentRoom._ctx.planned_actions.length === 0)">
                            <p class="text-gray-500 italic">No description recorded for this room.</p>
                        </template>
                    </div>
                </template>
                {{-- ROOMS-LIST CONTEXT — every room rolled up --}}
                <template x-if="screen === 'rooms'">
                    <div class="space-y-4">
                        <template x-for="(room, idx) in rooms" :key="room._ui.room_id">
                            <div class="border border-gray-200 rounded-xl p-3">
                                <p class="font-semibold text-gray-900 mb-1.5"
                                   x-text="room.name || 'Unnamed room'"></p>
                                <template x-if="(room._ctx?.planned_actions ?? []).length > 0">
                                    <ul class="list-disc pl-5 text-gray-700 space-y-0.5 leading-snug text-xs">
                                        <template x-for="(b, bi) in room._ctx.planned_actions" :key="bi">
                                            <li x-text="b.replace(/^[-•]\s*/, '')"></li>
                                        </template>
                                    </ul>
                                </template>
                                <template x-if="(!room._ctx?.planned_actions || room._ctx.planned_actions.length === 0) && room._ctx?.av_requirements">
                                    <p class="text-xs text-gray-700 leading-snug" x-text="room._ctx.av_requirements"></p>
                                </template>
                                <template x-if="(!room._ctx?.planned_actions || room._ctx.planned_actions.length === 0) && !room._ctx?.av_requirements">
                                    <p class="text-xs text-gray-400 italic">No description.</p>
                                </template>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
        </aside>

        {{-- ── KIT DRAWER ─────────────────────────────────────────────
             Step screen → current room's quote kit list.
             Rooms screen → every room's quote kit grouped by room. --}}
        <div x-show="kitDrawerOpen" x-cloak
             x-transition.opacity
             @click="kitDrawerOpen = false"
             class="fixed inset-0 z-40 bg-black/50"></div>

        <aside x-show="kitDrawerOpen" x-cloak
               x-transition:enter="transition transform ease-out duration-200"
               x-transition:enter-start="translate-x-full"
               x-transition:enter-end="translate-x-0"
               x-transition:leave="transition transform ease-in duration-150"
               x-transition:leave-start="translate-x-0"
               x-transition:leave-end="translate-x-full"
               class="fixed top-0 right-0 bottom-0 z-50 w-[88%] max-w-md bg-white shadow-2xl
                      flex flex-col">
            <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200">
                <div class="min-w-0">
                    <p class="text-xs uppercase tracking-wide text-gray-500 font-semibold">📦 Kit</p>
                    <p class="font-bold text-gray-900 truncate"
                       x-text="screen === 'step' ? (currentRoom?.name || 'Room') : 'All rooms'"></p>
                </div>
                <button type="button"
                        @click="kitDrawerOpen = false"
                        class="w-10 h-10 rounded-full hover:bg-gray-100 flex items-center justify-center min-h-[44px]"
                        aria-label="Close">
                    <svg class="w-5 h-5 text-gray-600" fill="none" viewBox="0 0 24 24"
                         stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
            <div class="flex-1 overflow-y-auto px-4 py-4 text-sm">
                {{-- WIZARD CONTEXT — current room only --}}
                <template x-if="screen === 'step'">
                    <div>
                        <template x-if="currentRoom?._ctx?.av_equipment_list">
                            <ul class="space-y-1.5 text-gray-800">
                                <template x-for="(item, ki) in parseKitLines(currentRoom._ctx.av_equipment_list)" :key="ki">
                                    <li class="flex items-start gap-2 leading-snug">
                                        <span class="inline-flex items-center justify-center min-w-[32px] h-6 px-2 rounded bg-brand-teal/10 text-brand-teal text-xs font-bold tabular-nums flex-shrink-0"
                                              x-text="item.qty ? item.qty + '×' : '·'"></span>
                                        <span class="flex-1" x-text="item.name"></span>
                                    </li>
                                </template>
                            </ul>
                        </template>
                        <template x-if="!currentRoom?._ctx?.av_equipment_list">
                            <p class="text-gray-500 italic">No kit recorded for this room.</p>
                        </template>
                    </div>
                </template>
                {{-- ROOMS-LIST CONTEXT — every room's kit, grouped --}}
                <template x-if="screen === 'rooms'">
                    <div class="space-y-3">
                        <template x-for="(room, idx) in rooms" :key="room._ui.room_id">
                            <div class="border border-gray-200 rounded-xl p-3">
                                <p class="font-semibold text-gray-900 mb-2"
                                   x-text="room.name || 'Unnamed room'"></p>
                                <template x-if="room._ctx?.av_equipment_list">
                                    <ul class="space-y-1 text-xs text-gray-800">
                                        <template x-for="(item, ki) in parseKitLines(room._ctx.av_equipment_list)" :key="ki">
                                            <li class="flex items-start gap-2 leading-snug">
                                                <span class="inline-flex items-center justify-center min-w-[28px] h-5 px-1.5 rounded bg-brand-teal/10 text-brand-teal text-[11px] font-bold tabular-nums flex-shrink-0"
                                                      x-text="item.qty ? item.qty + '×' : '·'"></span>
                                                <span class="flex-1" x-text="item.name"></span>
                                            </li>
                                        </template>
                                    </ul>
                                </template>
                                <template x-if="!room._ctx?.av_equipment_list">
                                    <p class="text-xs text-gray-400 italic">No kit.</p>
                                </template>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
        </aside>

        {{-- View-only banner shown after submission. The wizard area below
             shows captured data with pointer-events disabled so engineers
             can review what they sent without being able to edit. --}}
        @if ($readonly)
            <div class="bg-amber-50 border border-amber-200 rounded-2xl p-3 mb-4
                        text-amber-800 text-sm font-medium text-center">
                Viewing submitted survey — data is read-only.
            </div>
        @endif

        <div :class="@json($readonly) ? 'pointer-events-none select-text' : ''">

        {{-- ── STEP 1: ROOM CONTEXT ────────────────────────────── --}}
        <x-survey.step-container :step="1">

            {{-- Reference checklist — collapsible. Pulled from the office
                 master checklist for this room's solution type. Engineers
                 cross-reference against it as they capture data. --}}
            <template x-if="currentRoom?._ctx?.checklist_lines?.length > 0">
                <details class="bg-amber-50 border border-amber-200 rounded-2xl overflow-hidden"
                         x-data="{ open: false }">
                    <summary @click.prevent="open = !open"
                             class="px-4 py-3 cursor-pointer select-none flex items-center justify-between text-sm">
                        <span class="font-semibold text-amber-900">
                            📋 Reference checklist
                            <template x-if="currentRoom?._ctx?.solution_type_name">
                                <span class="text-amber-700 font-normal">— <span x-text="currentRoom._ctx.solution_type_name"></span></span>
                            </template>
                            <span class="text-amber-700 font-normal text-xs ml-1"
                                  x-text="'(' + (currentRoom?._ctx?.checklist_lines?.length || 0) + ' items)'"></span>
                        </span>
                        <span class="text-amber-700 text-xs" x-text="open ? '▲' : '▼'"></span>
                    </summary>
                    <div x-show="open" x-cloak class="px-4 pb-3 border-t border-amber-200 bg-amber-50">
                        <p class="text-xs text-amber-800 mt-2 mb-2 italic leading-snug">
                            Office master checklist — verify these as you capture data below.
                        </p>
                        <ul class="list-disc pl-5 text-sm text-amber-900 space-y-1 leading-snug">
                            <template x-for="(line, li) in currentRoom._ctx.checklist_lines" :key="li">
                                <li x-text="line"></li>
                            </template>
                        </ul>
                    </div>
                </details>
            </template>

            {{-- Room name (canonical: name) --}}
            <div class="bg-white rounded-2xl p-4 shadow-sm">
                <label class="block text-sm font-semibold text-gray-700 mb-1.5">
                    Room Name <span class="text-red-500">*</span>
                </label>
                <input type="text"
                       x-model="rooms[currentRoomIdx].name"
                       placeholder="e.g. Boardroom 1"
                       class="w-full border border-gray-300 rounded-xl px-3 py-3 text-base
                              focus:outline-none focus:ring-2 focus:ring-brand-teal min-h-[44px]">
            </div>

            {{-- Room type (canonical: type) --}}
            <div class="bg-white rounded-2xl p-4 shadow-sm">
                <p class="text-sm font-semibold text-gray-700 mb-2.5">
                    Room Type <span class="text-red-500">*</span>
                </p>
                <div class="grid grid-cols-2 gap-2">
                    @foreach ([
                        'meeting_room' => 'Meeting Room',
                        'boardroom'    => 'Boardroom',
                        'divisible'    => 'Divisible',
                        'auditorium'   => 'Auditorium',
                        'conference'   => 'Conference',
                        'breakout'     => 'Breakout',
                        'classroom'    => 'Classroom',
                        'other'        => 'Other',
                    ] as $val => $display)
                        <button type="button"
                                @click="rooms[currentRoomIdx].type = '{{ $val }}'"
                                :class="rooms[currentRoomIdx].type === '{{ $val }}'
                                    ? 'border-brand-teal bg-accent-50 text-brand-teal'
                                    : 'border-gray-200 text-gray-600 hover:border-gray-300'"
                                class="py-3 px-2 rounded-xl border-2 text-sm font-medium
                                       text-center min-h-[44px] transition-colors">
                            {{ $display }}
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- Work type (UI-only: _ui.work_type — drives step 4 conditional) --}}
            <div class="bg-white rounded-2xl p-4 shadow-sm">
                <p class="text-sm font-semibold text-gray-700 mb-2.5">Work Type</p>
                <div class="grid grid-cols-2 gap-2">
                    @foreach ([
                        'new_install' => 'New Install',
                        'upgrade'     => 'Upgrade',
                        'retrofit'    => 'Retrofit',
                        'fault'       => 'Fault / Repair',
                    ] as $val => $display)
                        <button type="button"
                                @click="rooms[currentRoomIdx]._ui.work_type = '{{ $val }}'"
                                :class="rooms[currentRoomIdx]._ui.work_type === '{{ $val }}'
                                    ? 'border-brand-gold bg-amber-50 text-brand-gold'
                                    : 'border-gray-200 text-gray-600 hover:border-gray-300'"
                                class="py-3 px-2 rounded-xl border-2 text-sm font-medium
                                       text-center min-h-[44px] transition-colors">
                            {{ $display }}
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- Pre-install checks — interactive yes/no/other for each AI question.
                 Source of truth: site_survey_room_questions table. Each tap
                 fires a POST to /survey/{token}/rooms/{room}/questions/{q}. --}}
            <template x-if="(currentRoom?._ctx?.questions ?? []).length > 0">
                <div class="bg-white rounded-2xl p-4 shadow-sm">
                    <p class="text-sm font-semibold text-gray-700 mb-2.5">
                        Pre-install Checks
                        <span class="text-xs text-gray-500 font-normal ml-1">
                            (<span x-text="currentRoom._ctx.questions.filter(q => q.answer).length"></span>
                            / <span x-text="currentRoom._ctx.questions.length"></span> answered)
                        </span>
                    </p>
                    <div class="space-y-3">
                        <template x-for="(q, qi) in currentRoom._ctx.questions" :key="q.id">
                            <div class="border border-gray-200 rounded-xl p-3">
                                <p class="text-sm text-gray-800 leading-snug mb-2" x-text="q.question"></p>
                                <div class="grid grid-cols-3 gap-1.5">
                                    <template x-for="opt in ['yes','no','other']" :key="opt">
                                        <button type="button"
                                                @click="answerCheck(q, opt)"
                                                :class="q.answer === opt
                                                    ? (opt === 'yes' ? 'bg-emerald-600 text-white border-emerald-600'
                                                      : opt === 'no'  ? 'bg-rose-600 text-white border-rose-600'
                                                                       : 'bg-amber-500 text-white border-amber-500')
                                                    : 'bg-white text-gray-600 border-gray-300 hover:border-gray-400'"
                                                class="py-2 rounded-lg border text-sm font-medium uppercase
                                                       min-h-[40px] transition-colors"
                                                x-text="opt"></button>
                                    </template>
                                </div>
                                <template x-if="q.answer === 'other'">
                                    <textarea rows="2"
                                              :value="q.other_text ?? ''"
                                              @blur="saveCheckOtherText(q, $event.target.value)"
                                              placeholder="Add detail…"
                                              maxlength="2000"
                                              class="mt-2 w-full text-sm rounded-lg border-gray-300
                                                     focus:border-amber-500 focus:ring-amber-500"></textarea>
                                </template>
                            </div>
                        </template>
                    </div>
                </div>
            </template>

        </x-survey.step-container>

        {{-- ── STEP 2: QUICK CAPTURE ───────────────────────────── --}}
        <x-survey.step-container :step="2">

            {{-- Voice note (UI-only: _ui.voice_note — not persisted) --}}
            <div class="bg-white rounded-2xl p-4 shadow-sm">
                <label class="block text-sm font-semibold text-gray-700 mb-1.5">
                    Voice Note Transcript
                    <span class="text-xs font-normal text-gray-400 ml-1">optional</span>
                </label>
                <textarea x-model="rooms[currentRoomIdx]._ui.voice_note"
                          rows="2"
                          placeholder="Dictate or paste a voice note transcript…"
                          class="w-full border border-gray-300 rounded-xl px-3 py-3 text-base
                                 focus:outline-none focus:ring-2 focus:ring-brand-teal resize-none"></textarea>
            </div>

            {{-- Quick notes (UI-only: _ui.quick_notes — mapped to canonical notes on save) --}}
            <div class="bg-white rounded-2xl p-4 shadow-sm">
                <label class="block text-sm font-semibold text-gray-700 mb-1.5">Quick Notes</label>
                <textarea x-model="rooms[currentRoomIdx]._ui.quick_notes"
                          rows="4"
                          placeholder="Anything notable about this room…"
                          class="w-full border border-gray-300 rounded-xl px-3 py-3 text-base
                                 focus:outline-none focus:ring-2 focus:ring-brand-teal resize-none"></textarea>
            </div>

            {{-- Site condition toggles (all UI-only: _ui.* fields) --}}
            <div class="bg-white rounded-2xl shadow-sm divide-y divide-gray-100 overflow-hidden">
                <x-survey.toggle-field field="power_available"   label="Power Available"   icon="⚡"
                                       parent="rooms[currentRoomIdx]._ui" />
                <x-survey.toggle-field field="network_available" label="Network Available" icon="📶"
                                       parent="rooms[currentRoomIdx]._ui" />
                <x-survey.toggle-field field="access_issues"     label="Access Issues"     icon="🚧"
                                       parent="rooms[currentRoomIdx]._ui" />
                <x-survey.toggle-field field="working_at_height" label="Working at Height" icon="🪜"
                                       parent="rooms[currentRoomIdx]._ui" />
                <x-survey.toggle-field field="client_present"    label="Client on Site"    icon="👤"
                                       parent="rooms[currentRoomIdx]._ui" />
            </div>

        </x-survey.step-container>

        {{-- ── STEP 3: PHOTOS ──────────────────────────────────── --}}
        <x-survey.step-container :step="3" label="Capture photos for each category">

            <x-survey.photo-upload category="room_overview"        label="Room Overview"          icon="🏠" />
            <x-survey.photo-upload category="display_wall"         label="Display Wall"           icon="🖥️" />
            <x-survey.photo-upload category="ceiling"              label="Ceiling"                icon="⬆️" />
            <x-survey.photo-upload category="rack_comms"           label="Rack / Comms"           icon="🗄️" />
            <x-survey.photo-upload category="cable_routes"         label="Cable Routes"           icon="🔌" />
            <x-survey.photo-upload category="power_network_points" label="Power & Network Points" icon="🔋" />

        </x-survey.step-container>

        {{-- ── STEP 4: INFRASTRUCTURE ──────────────────────────── --}}
        <x-survey.step-container :step="4">

            {{-- Full detail shown only for new_install (canonical fields) --}}
            <div x-show="rooms[currentRoomIdx]?._ui?.work_type === 'new_install'" class="space-y-4">

                {{-- Power: socket_locations, distance_to_screen, spare_capacity --}}
                <div class="bg-white rounded-2xl p-4 shadow-sm space-y-3">
                    <h3 class="text-sm font-bold text-gray-900">⚡ Power</h3>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">
                            Socket Locations / Count
                        </label>
                        <input type="text"
                               x-model="rooms[currentRoomIdx].infrastructure.power.socket_locations"
                               placeholder="e.g. 4 × 13A behind screen"
                               class="w-full border border-gray-300 rounded-xl px-3 py-3 text-base
                                      focus:outline-none focus:ring-2 focus:ring-brand-teal min-h-[44px]">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">
                            Distance to Screen (m)
                        </label>
                        <input type="number"
                               x-model.number="rooms[currentRoomIdx].infrastructure.power.distance_to_screen"
                               min="0" step="0.5" placeholder="e.g. 2.5"
                               class="w-full border border-gray-300 rounded-xl px-3 py-3 text-base
                                      focus:outline-none focus:ring-2 focus:ring-brand-teal min-h-[44px]">
                    </div>
                    <x-survey.toggle-field
                        field="spare_capacity"
                        label="Spare Capacity Available"
                        parent="rooms[currentRoomIdx].infrastructure.power"
                    />
                </div>

                {{-- Network: ports_available, switch_location, vlan_required --}}
                <div class="bg-white rounded-2xl p-4 shadow-sm space-y-3">
                    <h3 class="text-sm font-bold text-gray-900">📶 Network</h3>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">Ports Available</label>
                        <input type="number"
                               x-model.number="rooms[currentRoomIdx].infrastructure.network.ports_available"
                               min="0" max="100" placeholder="0"
                               class="w-full border border-gray-300 rounded-xl px-3 py-3 text-base
                                      focus:outline-none focus:ring-2 focus:ring-brand-teal min-h-[44px]">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">
                            Switch / Patch Panel Location
                        </label>
                        <input type="text"
                               x-model="rooms[currentRoomIdx].infrastructure.network.switch_location"
                               placeholder="e.g. IDF cabinet, room 101"
                               class="w-full border border-gray-300 rounded-xl px-3 py-3 text-base
                                      focus:outline-none focus:ring-2 focus:ring-brand-teal min-h-[44px]">
                    </div>
                    <x-survey.toggle-field
                        field="vlan_required"
                        label="VLAN Required"
                        parent="rooms[currentRoomIdx].infrastructure.network"
                    />
                </div>

                {{-- Cable routes: route_type, estimated_distance --}}
                <div class="bg-white rounded-2xl p-4 shadow-sm space-y-3">
                    <h3 class="text-sm font-bold text-gray-900">🔌 Cable Routes</h3>
                    <x-survey.select-field
                        field="route_type"
                        label="Route Type"
                        placeholder="Select route type…"
                        parent="rooms[currentRoomIdx].infrastructure.cable_routes"
                        :options="[
                            'existing'          => 'Existing route',
                            'ceiling_void'      => 'Ceiling void',
                            'trunking_required' => 'Trunking required',
                            'under_floor'       => 'Under floor',
                            'surface_mount'     => 'Surface mount',
                        ]"
                    />
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">
                            Estimated Distance (m)
                        </label>
                        <input type="number"
                               x-model.number="rooms[currentRoomIdx].infrastructure.cable_routes.estimated_distance"
                               min="0" step="1" placeholder="e.g. 25"
                               class="w-full border border-gray-300 rounded-xl px-3 py-3 text-base
                                      focus:outline-none focus:ring-2 focus:ring-brand-teal min-h-[44px]">
                    </div>
                </div>

            </div>{{-- /new_install --}}

            {{-- Other work types — brief note only --}}
            <div x-show="rooms[currentRoomIdx]?._ui?.work_type &&
                         rooms[currentRoomIdx]?._ui?.work_type !== 'new_install'">
                <div class="bg-white rounded-2xl p-4 shadow-sm">
                    <label class="block text-xs font-medium text-gray-500 mb-1.5">
                        Infrastructure notes (optional)
                    </label>
                    <textarea x-model="rooms[currentRoomIdx]._ui.quick_notes"
                              rows="3"
                              placeholder="Note any infrastructure observations…"
                              class="w-full border border-gray-300 rounded-xl px-3 py-3 text-base
                                     focus:outline-none focus:ring-2 focus:ring-brand-teal resize-none"></textarea>
                </div>
            </div>

            {{-- Prompt when no work type selected --}}
            <div x-show="!rooms[currentRoomIdx]?._ui?.work_type">
                <div class="bg-amber-50 border border-amber-200 rounded-2xl p-4 text-sm text-amber-800">
                    Select a work type in Step 1 to see the relevant infrastructure fields.
                </div>
            </div>

            {{-- ── Engineer Build-out Detail (quick task 260503-u2x) ─────────────
                 Captures the 7 sub-sections needed for accurate RAMS auto-
                 classification (working at height, dust+debris, hidden services,
                 fixings/substrate, manual handling). Shown for ALL work types —
                 engineer-feedback data is needed regardless of new_install vs
                 upgrade/retrofit/fault. Auto-saves on Step 4 → Step 5 navigation
                 and via deep-watch on the engineer_feedback object. --}}
            <div class="space-y-4 pt-2 border-t border-gray-100 mt-4">

                <div class="text-[11px] font-bold uppercase tracking-wide text-brand-teal mb-1">
                    📐 Engineer Build-out Detail
                </div>

                {{-- (a) Mounting Heights ──────────────────────────────────────── --}}
                <div class="bg-white rounded-2xl p-4 shadow-sm">
                    <h3 class="text-sm font-bold text-gray-900 mb-3">📏 Mounting Heights</h3>
                    <div class="grid grid-cols-2 gap-3">
                        @foreach ([
                            'screen_h_m'        => 'Screen height (m)',
                            'camera_h_m'        => 'Camera height (m)',
                            'booking_panel_h_m' => 'Booking panel height (m)',
                            'speaker_h_m'       => 'Speaker height (m)',
                        ] as $key => $label)
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">{{ $label }}</label>
                                <input type="number" min="0" max="99" step="0.01"
                                       x-model.number="rooms[currentRoomIdx].engineer_feedback.mounting_heights.{{ $key }}"
                                       placeholder=" "
                                       class="w-full border border-gray-300 rounded-xl px-3 py-2.5 text-base
                                              focus:outline-none focus:ring-2 focus:ring-brand-teal min-h-[44px]">
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-3">
                        <div class="flex items-center justify-between mb-2">
                            <label class="text-xs font-medium text-gray-500">Other Mounting Heights</label>
                            <button type="button" @click="addMountingOther()"
                                    class="px-3 py-1.5 rounded-lg bg-brand-teal/10 text-brand-teal text-xs font-bold hover:bg-brand-teal/20 min-h-[36px]">
                                + Add
                            </button>
                        </div>
                        <template x-if="(rooms[currentRoomIdx].engineer_feedback.mounting_heights.other || []).length === 0">
                            <p class="text-xs text-gray-400 italic">No additional mounting heights — tap "Add" to record one (e.g. mic boom).</p>
                        </template>
                        <template x-for="(row, oi) in (rooms[currentRoomIdx].engineer_feedback.mounting_heights.other || [])" :key="oi">
                            <div class="flex gap-2 mb-2">
                                <input type="text" x-model="row.label" maxlength="150"
                                       placeholder="e.g. Mic boom"
                                       class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-brand-teal focus:ring-brand-teal">
                                <input type="number" x-model.number="row.height_m" min="0" max="99" step="0.01"
                                       placeholder="height (m)"
                                       class="w-28 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-brand-teal focus:ring-brand-teal">
                                <button type="button" @click="removeMountingOther(oi)"
                                        class="px-3 rounded-lg text-rose-600 border border-rose-200 hover:bg-rose-50 text-xs font-bold min-w-[40px]">✕</button>
                            </div>
                        </template>
                    </div>
                </div>

                {{-- (b) Working at Height Methods ─────────────────────────────── --}}
                <div class="bg-white rounded-2xl p-4 shadow-sm">
                    <h3 class="text-sm font-bold text-gray-900 mb-3">🪜 Working at Height Methods</h3>
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                        @foreach ([
                            'ladder'   => 'Ladder',
                            'podium'   => 'Podium step',
                            'tower'    => 'Mobile tower',
                            'mewp'     => 'MEWP / cherry picker',
                            'scaffold' => 'Scaffold',
                            'na'       => 'N/A — ground only',
                        ] as $val => $display)
                            <button type="button"
                                    @click="toggleWahMethod('{{ $val }}')"
                                    :class="(rooms[currentRoomIdx].engineer_feedback.work_at_height_methods || []).includes('{{ $val }}')
                                        ? 'border-amber-500 bg-amber-50 text-amber-700'
                                        : 'border-gray-200 text-gray-600 hover:border-gray-300'"
                                    class="py-3 px-2 rounded-xl border-2 text-sm font-medium text-center min-h-[44px] transition-colors">
                                {{ $display }}
                            </button>
                        @endforeach
                    </div>
                </div>

                {{-- (c) Cable Routes ──────────────────────────────────────────── --}}
                <div class="bg-white rounded-2xl p-4 shadow-sm">
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="text-sm font-bold text-gray-900">🔌 Cable Routes</h3>
                        <button type="button" @click="addCableRoute()"
                                class="px-3 py-1.5 rounded-lg bg-brand-teal text-white text-xs font-bold hover:bg-[#0d6e77] min-h-[36px]">
                            + Add
                        </button>
                    </div>
                    <p class="text-xs text-gray-500 mb-2 leading-snug">
                        One row per cable category. Length captures total run including slack.
                    </p>
                    <template x-if="(rooms[currentRoomIdx].engineer_feedback.cable_routes || []).length === 0">
                        <p class="text-xs text-gray-400 italic">No cable routes recorded — tap "Add" to record one.</p>
                    </template>
                    <template x-for="(row, ci) in (rooms[currentRoomIdx].engineer_feedback.cable_routes || [])" :key="ci">
                        <div class="border border-gray-200 rounded-xl p-3 mb-2 space-y-2">
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="block text-[11px] text-gray-500 mb-0.5">Category</label>
                                    <select x-model="row.category"
                                            class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm focus:border-brand-teal focus:ring-brand-teal">
                                        <option value="">— Select —</option>
                                        <option value="ceiling_speakers">Ceiling speakers</option>
                                        <option value="desk_cables">Desk cables</option>
                                        <option value="mic_cables">Mic cables</option>
                                        <option value="booking_panel_cables">Booking panel cables</option>
                                        <option value="screen_cables">Screen cables</option>
                                        <option value="rack_to_room">Rack to room</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-[11px] text-gray-500 mb-0.5">Length (m)</label>
                                    <input type="number" x-model.number="row.length_m" min="0" max="9999" step="0.1"
                                           placeholder=" "
                                           class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm focus:border-brand-teal focus:ring-brand-teal">
                                </div>
                                <div>
                                    <label class="block text-[11px] text-gray-500 mb-0.5">From</label>
                                    <input type="text" x-model="row.from" maxlength="255" placeholder="e.g. Rack room A"
                                           class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm focus:border-brand-teal focus:ring-brand-teal">
                                </div>
                                <div>
                                    <label class="block text-[11px] text-gray-500 mb-0.5">To</label>
                                    <input type="text" x-model="row.to" maxlength="255" placeholder="e.g. Display position"
                                           class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm focus:border-brand-teal focus:ring-brand-teal">
                                </div>
                            </div>
                            <div>
                                <label class="block text-[11px] text-gray-500 mb-0.5">Notes</label>
                                <input type="text" x-model="row.notes" maxlength="500"
                                       placeholder="e.g. Through ceiling void, drop into floor box"
                                       class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm focus:border-brand-teal focus:ring-brand-teal">
                            </div>
                            <div class="text-right">
                                <button type="button" @click="removeCableRoute(ci)"
                                        class="px-2 py-1 rounded-lg text-rose-600 text-xs font-bold border border-rose-200 hover:bg-rose-50">
                                    Remove route
                                </button>
                            </div>
                        </div>
                    </template>
                </div>

                {{-- (d) Wall Construction & Prep ──────────────────────────────── --}}
                <div class="bg-white rounded-2xl p-4 shadow-sm">
                    <h3 class="text-sm font-bold text-gray-900 mb-3">🧱 Wall Construction &amp; Prep</h3>

                    <p class="text-xs font-medium text-gray-500 mb-2">Wall construction (multi-select)</p>
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-2 mb-4">
                        @foreach ([
                            'ply_lined'    => 'Ply-lined',
                            'solid'        => 'Solid',
                            'plasterboard' => 'Plasterboard',
                            'masonry'      => 'Masonry',
                            'metal_stud'   => 'Metal stud',
                            'concrete'     => 'Concrete',
                        ] as $val => $display)
                            <button type="button"
                                    @click="toggleWallConstruction('{{ $val }}')"
                                    :class="(rooms[currentRoomIdx].engineer_feedback.wall_construction || []).includes('{{ $val }}')
                                        ? 'border-brand-teal bg-accent-50 text-brand-teal'
                                        : 'border-gray-200 text-gray-600 hover:border-gray-300'"
                                    class="py-3 px-2 rounded-xl border-2 text-sm font-medium text-center min-h-[44px] transition-colors">
                                {{ $display }}
                            </button>
                        @endforeach
                    </div>

                    <p class="text-xs font-medium text-gray-500 mb-2">Wall preparation needed</p>
                    <div class="space-y-2">
                        @foreach ([
                            'wall_needs_reinforcement' => 'Wall needs reinforcement',
                            'wall_needs_chase_out'     => 'Wall needs chase-out / chasing',
                            'wall_needs_conduit'       => 'Wall needs conduit run',
                        ] as $key => $label)
                            <button type="button"
                                    @click="rooms[currentRoomIdx].engineer_feedback.{{ $key }} = !rooms[currentRoomIdx].engineer_feedback.{{ $key }}"
                                    class="flex items-center gap-3 w-full p-2 rounded-xl hover:bg-gray-50 min-h-[44px] text-left">
                                <span class="flex-shrink-0 w-6 h-6 rounded-lg border-2 flex items-center justify-center"
                                      :class="rooms[currentRoomIdx].engineer_feedback.{{ $key }}
                                          ? 'bg-brand-teal border-brand-teal text-white'
                                          : 'border-gray-300 bg-white'">
                                    <svg x-show="rooms[currentRoomIdx].engineer_feedback.{{ $key }}" class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                                    </svg>
                                </span>
                                <span class="text-sm text-gray-700">{{ $label }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>

                {{-- (e) Table Info ────────────────────────────────────────────── --}}
                <div class="bg-white rounded-2xl p-4 shadow-sm">
                    <h3 class="text-sm font-bold text-gray-900 mb-3">🪑 Table Info</h3>
                    <button type="button"
                            @click="rooms[currentRoomIdx].engineer_feedback.table_info.has_grommets = !rooms[currentRoomIdx].engineer_feedback.table_info.has_grommets"
                            class="flex items-center gap-3 w-full p-2 rounded-xl hover:bg-gray-50 min-h-[44px] text-left mb-3">
                        <span class="flex-shrink-0 w-6 h-6 rounded-lg border-2 flex items-center justify-center"
                              :class="rooms[currentRoomIdx].engineer_feedback.table_info.has_grommets
                                  ? 'bg-brand-teal border-brand-teal text-white'
                                  : 'border-gray-300 bg-white'">
                            <svg x-show="rooms[currentRoomIdx].engineer_feedback.table_info.has_grommets" class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                            </svg>
                        </span>
                        <span class="text-sm text-gray-700">Table has grommets</span>
                    </button>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1">Grommet count</label>
                            <input type="number" min="0" max="99"
                                   x-model.number="rooms[currentRoomIdx].engineer_feedback.table_info.grommet_count"
                                   placeholder=" "
                                   class="w-full border border-gray-300 rounded-xl px-3 py-2.5 text-base focus:outline-none focus:ring-2 focus:ring-brand-teal min-h-[44px]">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1">Grommet size</label>
                            <select x-model="rooms[currentRoomIdx].engineer_feedback.table_info.grommet_size"
                                    class="w-full border border-gray-300 rounded-xl px-3 py-2.5 text-base focus:outline-none focus:ring-2 focus:ring-brand-teal min-h-[44px]">
                                <option value="">— Select —</option>
                                <option value="small">Small</option>
                                <option value="standard">Standard</option>
                                <option value="large">Large</option>
                            </select>
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="block text-xs font-medium text-gray-500 mb-1">Table notes</label>
                        <input type="text" maxlength="500"
                               x-model="rooms[currentRoomIdx].engineer_feedback.table_info.notes"
                               placeholder="e.g. Solid oak, hidden raceway"
                               class="w-full border border-gray-300 rounded-xl px-3 py-2.5 text-base focus:outline-none focus:ring-2 focus:ring-brand-teal min-h-[44px]">
                    </div>
                </div>

                {{-- (f) Floor Box Info ────────────────────────────────────────── --}}
                <div class="bg-white rounded-2xl p-4 shadow-sm">
                    <h3 class="text-sm font-bold text-gray-900 mb-3">🔋 Floor Box Info</h3>
                    <button type="button"
                            @click="rooms[currentRoomIdx].engineer_feedback.floor_box_info.has_floor_box = !rooms[currentRoomIdx].engineer_feedback.floor_box_info.has_floor_box"
                            class="flex items-center gap-3 w-full p-2 rounded-xl hover:bg-gray-50 min-h-[44px] text-left mb-3">
                        <span class="flex-shrink-0 w-6 h-6 rounded-lg border-2 flex items-center justify-center"
                              :class="rooms[currentRoomIdx].engineer_feedback.floor_box_info.has_floor_box
                                  ? 'bg-brand-teal border-brand-teal text-white'
                                  : 'border-gray-300 bg-white'">
                            <svg x-show="rooms[currentRoomIdx].engineer_feedback.floor_box_info.has_floor_box" class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                            </svg>
                        </span>
                        <span class="text-sm text-gray-700">Room has floor box</span>
                    </button>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1">Power outlets</label>
                            <input type="number" min="0" max="99"
                                   x-model.number="rooms[currentRoomIdx].engineer_feedback.floor_box_info.power_outlets"
                                   placeholder=" "
                                   class="w-full border border-gray-300 rounded-xl px-3 py-2.5 text-base focus:outline-none focus:ring-2 focus:ring-brand-teal min-h-[44px]">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1">Data outlets</label>
                            <input type="number" min="0" max="99"
                                   x-model.number="rooms[currentRoomIdx].engineer_feedback.floor_box_info.data_outlets"
                                   placeholder=" "
                                   class="w-full border border-gray-300 rounded-xl px-3 py-2.5 text-base focus:outline-none focus:ring-2 focus:ring-brand-teal min-h-[44px]">
                        </div>
                        <div class="col-span-2">
                            <label class="block text-xs font-medium text-gray-500 mb-1">Cable space</label>
                            <select x-model="rooms[currentRoomIdx].engineer_feedback.floor_box_info.cable_space"
                                    class="w-full border border-gray-300 rounded-xl px-3 py-2.5 text-base focus:outline-none focus:ring-2 focus:ring-brand-teal min-h-[44px]">
                                <option value="">— Select —</option>
                                <option value="tight">Tight</option>
                                <option value="adequate">Adequate</option>
                                <option value="spacious">Spacious</option>
                            </select>
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="block text-xs font-medium text-gray-500 mb-1">Floor box notes</label>
                        <input type="text" maxlength="500"
                               x-model="rooms[currentRoomIdx].engineer_feedback.floor_box_info.notes"
                               placeholder="e.g. 4-gang under boardroom table, lid 200mm × 300mm"
                               class="w-full border border-gray-300 rounded-xl px-3 py-2.5 text-base focus:outline-none focus:ring-2 focus:ring-brand-teal min-h-[44px]">
                    </div>
                </div>

                {{-- (g) Brackets Required ─────────────────────────────────────── --}}
                <div class="bg-white rounded-2xl p-4 shadow-sm">
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="text-sm font-bold text-gray-900">🔩 Brackets Required</h3>
                        <button type="button" @click="addBracket()"
                                class="px-3 py-1.5 rounded-lg bg-brand-teal text-white text-xs font-bold hover:bg-[#0d6e77] min-h-[36px]">
                            + Add
                        </button>
                    </div>
                    <template x-if="(rooms[currentRoomIdx].engineer_feedback.brackets_required || []).length === 0">
                        <p class="text-xs text-gray-400 italic">No brackets recorded — tap "Add" to record one.</p>
                    </template>
                    <template x-for="(row, bi) in (rooms[currentRoomIdx].engineer_feedback.brackets_required || [])" :key="bi">
                        <div class="border border-gray-200 rounded-xl p-3 mb-2 space-y-2">
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="block text-[11px] text-gray-500 mb-0.5">Equipment</label>
                                    <input type="text" x-model="row.equipment" maxlength="255"
                                           placeholder='e.g. 75" display'
                                           class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm focus:border-brand-teal focus:ring-brand-teal">
                                </div>
                                <div>
                                    <label class="block text-[11px] text-gray-500 mb-0.5">Bracket model</label>
                                    <input type="text" x-model="row.model" maxlength="255"
                                           placeholder="e.g. Vogels PFW6880"
                                           class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm focus:border-brand-teal focus:ring-brand-teal">
                                </div>
                            </div>
                            <button type="button"
                                    @click="row.pull_out = !row.pull_out"
                                    class="flex items-center gap-2 w-full p-1 rounded-lg hover:bg-gray-50 min-h-[36px] text-left">
                                <span class="flex-shrink-0 w-5 h-5 rounded border-2 flex items-center justify-center"
                                      :class="row.pull_out ? 'bg-brand-teal border-brand-teal text-white' : 'border-gray-300 bg-white'">
                                    <svg x-show="row.pull_out" class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                                    </svg>
                                </span>
                                <span class="text-xs text-gray-700">Pull-out / articulating bracket</span>
                            </button>
                            <div>
                                <label class="block text-[11px] text-gray-500 mb-0.5">Notes</label>
                                <input type="text" x-model="row.notes" maxlength="500"
                                       placeholder="e.g. VESA 600×400; check wall plate weight rating"
                                       class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm focus:border-brand-teal focus:ring-brand-teal">
                            </div>
                            <div class="text-right">
                                <button type="button" @click="removeBracket(bi)"
                                        class="px-2 py-1 rounded-lg text-rose-600 text-xs font-bold border border-rose-200 hover:bg-rose-50">
                                    Remove bracket
                                </button>
                            </div>
                        </div>
                    </template>
                </div>

            </div>{{-- /Engineer Build-out Detail --}}

        </x-survey.step-container>

        {{-- ── STEP 5: EQUIPMENT ───────────────────────────────── --}}
        <x-survey.step-container :step="5" label="Add each item of AV equipment">
            <x-survey.repeater-equipment />

            {{-- Additional items needed — extras the engineer needs the office
                 to source (cables, brackets, faceplates, etc). Aggregated
                 across rooms in the Kit Info burger on the rooms list. --}}
            <div class="bg-white rounded-2xl p-4 shadow-sm">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-sm font-bold text-gray-900">🛒 Additional Items Needed</h3>
                    <button type="button"
                            @click="addAdditionalItem()"
                            class="px-3 py-2 rounded-xl bg-brand-teal text-white text-xs font-bold
                                   hover:bg-[#0d6e77] min-h-[36px]">
                        + Add
                    </button>
                </div>
                <p class="text-xs text-gray-500 mb-3 leading-snug">
                    Anything the office needs to source for this room beyond the quoted kit —
                    cables, brackets, faceplates, consumables, tools.
                </p>
                <template x-if="!Array.isArray(currentRoom?.additional_items) || currentRoom.additional_items.length === 0">
                    <p class="text-xs text-gray-400 italic">No additional items added.</p>
                </template>
                <template x-for="(item, ii) in (currentRoom?.additional_items ?? [])" :key="ii">
                    <div class="border border-gray-200 rounded-xl p-3 mb-2 space-y-2">
                        <div class="flex gap-2">
                            <input type="text"
                                   placeholder="Qty"
                                   x-model="currentRoom.additional_items[ii].qty"
                                   class="w-16 text-sm rounded-lg border-gray-300 focus:border-brand-teal focus:ring-brand-teal">
                            <input type="text"
                                   placeholder="Item description (e.g. 5m HDMI cable)"
                                   maxlength="200"
                                   x-model="currentRoom.additional_items[ii].description"
                                   class="flex-1 text-sm rounded-lg border-gray-300 focus:border-brand-teal focus:ring-brand-teal">
                            <button type="button"
                                    @click="removeAdditionalItem(ii)"
                                    class="px-3 rounded-lg text-rose-600 border border-rose-200 hover:bg-rose-50 text-xs font-bold min-w-[40px]">✕</button>
                        </div>
                        <input type="text"
                               placeholder="Note (optional, e.g. why needed)"
                               maxlength="200"
                               x-model="currentRoom.additional_items[ii].note"
                               class="w-full text-sm rounded-lg border-gray-300 focus:border-brand-teal focus:ring-brand-teal">
                    </div>
                </template>
            </div>
        </x-survey.step-container>

        {{-- ── STEP 6: ACCESS & H&S ────────────────────────────── --}}
        <x-survey.step-container :step="6">

            <div class="bg-amber-50 border border-amber-200 rounded-2xl p-3
                        text-xs text-amber-800 font-medium">
                ⚠️ This information feeds directly into your RAMS document. Answer accurately.
            </div>

            {{-- Working height (canonical: risks[0].working_height) --}}
            <div class="bg-white rounded-2xl p-4 shadow-sm">
                <p class="text-sm font-semibold text-gray-700 mb-2.5">Working Height</p>
                <div class="grid grid-cols-3 gap-2">
                    @foreach (['under_2m' => 'Under 2m', '2_to_4m' => '2–4m', 'over_4m' => '4m+'] as $val => $display)
                        <button type="button"
                                @click="rooms[currentRoomIdx].risks[0].working_height = '{{ $val }}'"
                                :class="rooms[currentRoomIdx].risks[0]?.working_height === '{{ $val }}'
                                    ? 'border-amber-500 bg-amber-50 text-amber-700'
                                    : 'border-gray-200 text-gray-600 hover:border-gray-300'"
                                class="py-3 rounded-xl border-2 text-sm font-medium
                                       text-center min-h-[44px] transition-colors">
                            {{ $display }}
                        </button>
                    @endforeach
                </div>
            </div>

            {{--
                Access equipment — only shown when working_at_height is true (UI-only _ui field).
                Server also enforces: clears access_equipment when working_at_height=false.
            --}}
            <div class="bg-white rounded-2xl p-4 shadow-sm"
                 x-show="rooms[currentRoomIdx]?._ui?.working_at_height">
                <p class="text-sm font-semibold text-gray-700 mb-2.5">Access Equipment Required</p>
                <div class="grid grid-cols-2 gap-2">
                    @foreach ([
                        'steps'  => 'Steps',
                        'ladder' => 'Ladder',
                        'pasma'  => 'PASMA Tower',
                        'mewp'   => 'MEWP / Cherry Picker',
                    ] as $val => $display)
                        <button type="button"
                                @click="rooms[currentRoomIdx].risks[0].access_equipment = '{{ $val }}'"
                                :class="rooms[currentRoomIdx].risks[0]?.access_equipment === '{{ $val }}'
                                    ? 'border-amber-500 bg-amber-50 text-amber-700'
                                    : 'border-gray-200 text-gray-600 hover:border-gray-300'"
                                class="py-3 px-2 rounded-xl border-2 text-sm font-medium
                                       text-center min-h-[44px] transition-colors">
                            {{ $display }}
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- H&S toggles (canonical: risks[0].*) --}}
            <div class="bg-white rounded-2xl shadow-sm divide-y divide-gray-100 overflow-hidden">
                <x-survey.toggle-field
                    field="out_of_hours"
                    label="Out of Hours Work"
                    icon="🌙"
                    parent="rooms[currentRoomIdx].risks[0]"
                    active="bg-amber-500"
                />
                <x-survey.toggle-field
                    field="permits_required"
                    label="Permits Required"
                    icon="📋"
                    parent="rooms[currentRoomIdx].risks[0]"
                    active="bg-amber-500"
                />
                <x-survey.toggle-field
                    field="manual_handling_risk"
                    label="Manual Handling Risk"
                    icon="⚠️"
                    parent="rooms[currentRoomIdx].risks[0]"
                    active="bg-amber-500"
                />
            </div>

        </x-survey.step-container>

        {{-- ── STEP 7: CONSTRAINTS ─────────────────────────────── --}}
        {{--
            Constraints are UI-only capture fields (not in the canonical room shape).
            They are stored in _ui.constraints and are not sent to the server.
            These fields are ephemeral: they reset on each visit to the survey.
        --}}
        <x-survey.step-container :step="7" label="Note any factors that affect the install">

            @foreach ([
                'obstructions'          => ['Obstructions',         'Describe any physical obstructions…'],
                'noise_restrictions'    => ['Noise Restrictions',    'e.g. Quiet hours 09:00–17:00…'],
                'client_constraints'    => ['Client Constraints',    'e.g. Restricted areas, client on site…'],
                'programme_constraints' => ['Programme Constraints', 'e.g. 3 days available, phased access…'],
            ] as $field => [$label, $placeholder])
                <div class="bg-white rounded-2xl p-4 shadow-sm">
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">{{ $label }}</label>
                    <textarea x-model="rooms[currentRoomIdx]._ui.constraints.{{ $field }}"
                              rows="2"
                              placeholder="{{ $placeholder }}"
                              class="w-full border border-gray-300 rounded-xl px-3 py-3 text-base
                                     focus:outline-none focus:ring-2 focus:ring-brand-teal resize-none"></textarea>
                </div>
            @endforeach

            <div class="bg-gray-50 border border-gray-200 rounded-2xl p-3 text-xs text-gray-500">
                These notes support your on-site assessment. They are not stored in the final
                survey record — add anything important to Quick Notes in Step 2.
            </div>

        </x-survey.step-container>

        {{-- ── STEP 8: SIGN-OFF ─────────────────────────────────── --}}
        {{--
            Sign-off fields are UI-only workflow capture fields (not in canonical room shape).
            engineer_name, client_signature, and is_confirmed live in _ui.signoff.
            The room is marked complete via the /rooms/{room}/complete endpoint.
        --}}
        <x-survey.step-container :step="8">

            <div class="bg-white rounded-2xl p-4 shadow-sm space-y-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">
                        Engineer Name <span class="text-red-500">*</span>
                    </label>
                    <input type="text"
                           x-model="rooms[currentRoomIdx]._ui.signoff.engineer_name"
                           placeholder="Your full name"
                           class="w-full border border-gray-300 rounded-xl px-3 py-3 text-base
                                  focus:outline-none focus:ring-2 focus:ring-brand-teal min-h-[44px]">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">
                        Client Signature / Name
                    </label>
                    <input type="text"
                           x-model="rooms[currentRoomIdx]._ui.signoff.client_signature"
                           placeholder="Client name or initials (if present)"
                           class="w-full border border-gray-300 rounded-xl px-3 py-3 text-base
                                  focus:outline-none focus:ring-2 focus:ring-brand-teal min-h-[44px]">
                </div>
            </div>

            {{-- Confirmation checkbox --}}
            <div class="bg-white rounded-2xl p-4 shadow-sm">
                <button type="button"
                        @click="toggleSignoffConfirm()"
                        class="flex items-start gap-3 text-left w-full min-h-[44px]">
                    <span class="flex-shrink-0 mt-0.5 w-6 h-6 rounded-lg border-2 flex items-center
                                 justify-center transition-colors"
                          :class="rooms[currentRoomIdx]._ui.signoff.is_confirmed
                              ? 'bg-brand-teal border-brand-teal'
                              : 'border-gray-400 bg-white'">
                        <svg x-show="rooms[currentRoomIdx]._ui.signoff.is_confirmed"
                             class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd"
                                  d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414
                                     0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"
                                  clip-rule="evenodd"/>
                        </svg>
                    </span>
                    <span class="text-sm text-gray-700 leading-relaxed">
                        I confirm I have physically inspected this room and the information
                        provided is accurate to the best of my knowledge.
                    </span>
                </button>
            </div>

            <div class="bg-gray-50 rounded-2xl p-4 text-xs text-gray-500 space-y-0.5">
                <p>Survey date: {{ now()->format('d M Y') }}</p>
                <p>Time: <span x-text="new Date().toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'})"></span></p>
            </div>

            <button type="button"
                    @click="markRoomComplete()"
                    :disabled="!rooms[currentRoomIdx]?._ui?.signoff?.is_confirmed ||
                               !rooms[currentRoomIdx]?._ui?.signoff?.engineer_name?.trim()"
                    class="w-full py-4 rounded-2xl font-bold text-base min-h-[56px] transition-colors"
                    :class="(rooms[currentRoomIdx]?._ui?.signoff?.is_confirmed &&
                             rooms[currentRoomIdx]?._ui?.signoff?.engineer_name?.trim())
                        ? 'bg-emerald-500 text-white hover:bg-emerald-600 shadow-md'
                        : 'bg-gray-200 text-gray-400 cursor-not-allowed'">
                Mark Room Complete ✓
            </button>

        </x-survey.step-container>

        </div>{{-- /:class pointer-events when readonly --}}

    </div>{{-- /screen:step --}}

</main>

{{-- ═══════════════════════════════════════════════════════════
     STICKY BOTTOM NAV (Steps 1–7) — hidden in readonly mode
════════════════════════════════════════════════════════════ --}}
<div x-show="screen === 'step' && currentStep < 8 && !readonly"
     class="fixed bottom-0 left-0 right-0 bg-white/95 backdrop-blur border-t border-gray-200
            px-4 py-3 pb-6 z-30">
    <div class="max-w-xl mx-auto flex gap-3">
        <button @click="prevStep()"
                class="flex-none w-24 py-3.5 border-2 border-gray-300 rounded-2xl font-semibold
                       text-gray-700 text-sm min-h-[50px] hover:bg-gray-50 transition-colors">
            ← Back
        </button>
        <button @click="nextStep()"
                :disabled="saving"
                class="flex-1 py-3.5 bg-brand-teal text-white rounded-2xl font-bold text-sm
                       min-h-[50px] hover:bg-[#0d6e77] transition-colors"
                :class="saving ? 'opacity-60 cursor-wait' : ''">
            <span x-show="!saving">Next →</span>
            <span x-show="saving">Saving…</span>
        </button>
    </div>
</div>

{{-- ═══════════════════════════════════════════════════════════
     READ-ONLY NAV (Steps 1–8) — visible only after submission so
     reviewers can step through without triggering save logic.
════════════════════════════════════════════════════════════ --}}
<div x-show="screen === 'step' && readonly"
     class="fixed bottom-0 left-0 right-0 bg-white/95 backdrop-blur border-t border-gray-200
            px-4 py-3 pb-6 z-30">
    <div class="max-w-xl mx-auto flex gap-3">
        <button @click="prevStep()"
                class="flex-none w-24 py-3.5 border-2 border-gray-300 rounded-2xl font-semibold
                       text-gray-700 text-sm min-h-[50px] hover:bg-gray-50 transition-colors">
            ← Back
        </button>
        <button x-show="currentStep < 8"
                @click="currentStep = Math.min(currentStep + 1, 8); window.scrollTo(0,0);"
                class="flex-1 py-3.5 bg-brand-teal text-white rounded-2xl font-bold text-sm
                       min-h-[50px] hover:bg-[#0d6e77] transition-colors">
            Next →
        </button>
        <button x-show="currentStep === 8"
                @click="screen = 'rooms'; window.scrollTo(0,0);"
                class="flex-1 py-3.5 bg-gray-700 text-white rounded-2xl font-bold text-sm
                       min-h-[50px] hover:bg-gray-800 transition-colors">
            Back to rooms
        </button>
    </div>
</div>

{{-- ═══════════════════════════════════════════════════════════
     ALPINE.JS COMPONENT
════════════════════════════════════════════════════════════ --}}
<script>
function surveyWizard() {
    return {
        // ── State ─────────────────────────────────────────────────────────────
        screen:          'rooms',  // 'rooms' | 'step'
        currentRoomIdx:  null,
        currentStep:     1,
        kitDrawerOpen:         false,
        descriptionDrawerOpen: false,
        saving:          false,
        submitting:      false,
        lastSaved:       null,
        saveError:       null,
        validationError: null,

        // ── PHP-injected data ─────────────────────────────────────────────────
        token:        @json($token),
        readonly:     @json($readonly),
        surveyorName: @json($survey->surveyor_name ?? ''),

        // rooms: canonical fields (name, type, photos, infrastructure, equipment, risks, notes)
        //        + _ui block (UI-only fields: room_id, is_completed, work_type, quick_notes, etc.)
        //        + engineer_feedback block (quick task 260503-u2x — Step 4 sub-sections)
        rooms: @json($rooms),

        // Site-level engineer feedback (quick task 260503-u2x). Captured once
        // per visit on the rooms-list screen, persists via stepSave step=0.
        // Server seeds from SiteSurvey columns (post-completion source of truth)
        // → falls back to canonical survey_data → falls back to defaults.
        engineerFeedbackSite:    @json($engineerFeedbackSite),
        siteLogisticsSaving:     false,
        siteLogisticsLastSaved:  null,
        _siteAutosaveTimer:      null,

        // Per-room expand state on the rooms list (collapsed by default so the
        // list stays scannable on mobile). Toggled by the chevron on each card.
        expandedRoomIdx: null,

        // ── Init — set up debounced autosave on toggle / selection changes
        //          so engineers don't lose Step 2 toggles or Step 1 work_type
        //          when they reload mid-step without clicking Next.
        // ──────────────────────────────────────────────────────────────────────
        _autosaveTimer: null,

        init() {
            const watchedFields = [
                'work_type', 'voice_note', 'quick_notes',
                'power_available', 'network_available',
                'access_issues', 'working_at_height', 'client_present',
            ];
            this.rooms.forEach((_, idx) => {
                watchedFields.forEach(f => {
                    this.$watch(`rooms.${idx}._ui.${f}`, () => this.debouncedAutosave());
                });
            });

            // Site-level engineer feedback (quick task 260503-u2x) — debounced
            // autosave via stepSave step=0. Independent timer so typing in
            // Site Logistics never collides with per-room Step 4 saves.
            const siteFields = [
                'comms_room_access_status', 'comms_room_access_notes',
                'parking_restraints', 'distance_from_base_miles', 'distance_from_base_notes',
                'site_access_notes', 'delivery_routes',
            ];
            siteFields.forEach(f => {
                this.$watch(`engineerFeedbackSite.${f}`, () => this.debouncedAutosaveSite());
            });

            // Per-room engineer feedback — deep watch so changes anywhere in the
            // nested object (toggling a multi-select chip, editing a cable_route
            // row, etc.) trigger the existing debounced autosave. The Step 4
            // payload includes engineer_feedback alongside infrastructure, so
            // both ride the same stepSave call.
            this.rooms.forEach((_, idx) => {
                this.$watch(`rooms.${idx}.engineer_feedback`, () => this.debouncedAutosave(), { deep: true });
            });
        },

        debouncedAutosave() {
            if (this._autosaveTimer) clearTimeout(this._autosaveTimer);
            this._autosaveTimer = setTimeout(() => {
                // Only fire when actively editing a room and not read-only.
                if (this.screen === 'step' && this.currentRoom && !this.readonly) {
                    this.autosave();
                }
            }, 600);
        },

        // ── Site Logistics autosave (quick task 260503-u2x) ───────────────────
        // Independent debounce + timer so typing in the rooms-list Site Logistics
        // card doesn't collide with the per-room Step 4 autosave path.
        debouncedAutosaveSite() {
            if (this._siteAutosaveTimer) clearTimeout(this._siteAutosaveTimer);
            this._siteAutosaveTimer = setTimeout(() => {
                if (this.readonly) return;
                this.autosaveSite();
            }, 600);
        },

        async autosaveSite() {
            if (this.readonly) return;
            this.siteLogisticsSaving = true;
            try {
                const resp = await fetch('/survey/' + this.token + '/step-save', {
                    method:  'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept':       'application/json',
                    },
                    body: JSON.stringify({
                        room_index: 0,                           // sentinel — server ignores for step=0
                        step:       0,
                        data:       { engineer_feedback_site: this.engineerFeedbackSite },
                    }),
                });
                if (!resp.ok) throw new Error('Save failed');
                this.siteLogisticsLastSaved = new Date();
            } catch (_) {
                // Soft failure — site logistics is captured once per visit;
                // engineer can re-fill if connectivity drops mid-edit.
            } finally {
                this.siteLogisticsSaving = false;
            }
        },

        // ── Computed ──────────────────────────────────────────────────────────
        get currentRoom() {
            return this.currentRoomIdx !== null ? this.rooms[this.currentRoomIdx] : null;
        },

        get completedCount() {
            return this.rooms.filter(r => r._ui.is_completed).length;
        },

        get allComplete() {
            return this.rooms.length > 0 && this.rooms.every(r => r._ui.is_completed);
        },

        get stepTitle() {
            return [
                '', 'Room Context', 'Quick Capture', 'Photos',
                'Infrastructure', 'Equipment', 'Access & H&S', 'Constraints', 'Sign-off',
            ][this.currentStep] ?? '';
        },

        // ── Navigation ────────────────────────────────────────────────────────
        selectRoom(idx) {
            this.currentRoomIdx  = idx;
            this.currentStep     = 1;
            this.validationError = null;
            this.screen          = 'step';
            window.scrollTo(0, 0);
        },

        validateCurrentStep() {
            const r = this.rooms[this.currentRoomIdx];
            if (this.currentStep === 1) {
                if (!r.name?.trim()) {
                    this.validationError = 'Room name is required before proceeding.';
                    return false;
                }
                if (!r.type) {
                    this.validationError = 'Please select a room type before proceeding.';
                    return false;
                }
            }
            this.validationError = null;
            return true;
        },

        async nextStep() {
            if (!this.validateCurrentStep()) return;
            await this.autosave();
            if (this.currentStep < 8) {
                this.currentStep++;
                window.scrollTo(0, 0);
            }
        },

        prevStep() {
            this.validationError = null;
            if (this.currentStep > 1) {
                this.currentStep--;
                window.scrollTo(0, 0);
            } else {
                this.autosave().finally(() => {
                    this.screen         = 'rooms';
                    this.currentRoomIdx = null;
                    window.scrollTo(0, 0);
                });
            }
        },

        // ── Sign-off confirm — sets timestamp on check ────────────────────────
        toggleSignoffConfirm() {
            const s = this.rooms[this.currentRoomIdx]._ui.signoff;
            s.is_confirmed = !s.is_confirmed;
            s.timestamp    = s.is_confirmed ? new Date().toISOString() : null;
        },

        // ── Auto-save ─────────────────────────────────────────────────────────
        async autosave() {
            if (!this.currentRoom || this.readonly) return;
            this.saving    = true;
            this.saveError = null;
            try {
                const resp = await fetch('/survey/' + this.token + '/step-save', {
                    method:  'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept':       'application/json',
                    },
                    body: JSON.stringify(this.buildStepPayload()),
                });
                if (!resp.ok) {
                    const body = await resp.json().catch(() => ({}));
                    throw new Error(body.message || 'Save failed');
                }
                this.lastSaved = new Date();
            } catch (e) {
                this.saveError = e.message || 'Could not save. Check your connection.';
            } finally {
                this.saving = false;
            }
        },

        /**
         * Build { room_index, step, data } payload for the stepSave endpoint.
         *
         * Canonical fields only are sent for steps 1–6.
         * Steps 7 and 8 are UI-only — no canonical contribution, data is empty.
         *
         * Step 2 mapping: quick_notes → notes (server maps this to canonical notes).
         * Step 6 mapping: working_at_height sent as normalization context (not persisted).
         */
        buildStepPayload() {
            const r    = this.currentRoom;
            const step = this.currentStep;
            let data   = {};

            switch (step) {
                case 1:
                    // name + type are canonical room fields.
                    // work_type is persisted into canonical ui_state so the
                    // engineer's Step 1 selection survives a reload.
                    data = {
                        name:      r.name,
                        type:      r.type,
                        work_type: r._ui.work_type ?? '',
                    };
                    break;
                case 2:
                    // quick_notes maps to canonical notes server-side.
                    // Toggles + voice_note are persisted into canonical
                    // ui_state so reload restores the engineer's selections.
                    data = {
                        quick_notes:       r._ui.quick_notes,
                        power_available:   !! r._ui.power_available,
                        network_available: !! r._ui.network_available,
                        access_issues:     !! r._ui.access_issues,
                        working_at_height: !! r._ui.working_at_height,
                        client_present:    !! r._ui.client_present,
                        voice_note:        r._ui.voice_note ?? null,
                    };
                    break;
                case 3:
                    // Sync current photo list (from DB, hydrated on page load) to canonical payload.
                    data = { photos: r.photos };
                    break;
                case 4:
                    // engineer_feedback rides alongside infrastructure on the
                    // same Step 4 save (quick task 260503-u2x). Server normalizes
                    // the shape via normalizeEngineerFeedback() and mirrors to
                    // SiteSurveyRoom DB columns via writeEngineerFeedbackToColumns().
                    data = {
                        infrastructure:    r.infrastructure,
                        engineer_feedback: r.engineer_feedback,
                    };
                    break;
                case 5:
                    data = {
                        equipment:        r.equipment        ?? [],
                        additional_items: r.additional_items ?? [],
                    };
                    break;
                case 6:
                    // working_at_height sent as normalization context — server clears
                    // access_equipment from risks when false, then discards the field.
                    data = {
                        working_at_height: r._ui.working_at_height,
                        risks:             r.risks,
                    };
                    break;
                case 7:
                case 8:
                    // Constraints and sign-off are UI-only — no canonical contribution.
                    data = {};
                    break;
            }

            return { room_index: this.currentRoomIdx, step, data };
        },

        // ── Mark room complete ────────────────────────────────────────────────
        async markRoomComplete() {
            const r = this.currentRoom;

            // Photo gate — soft-block if no photos uploaded.
            // Engineers consistently skip photos when rushing; that costs
            // the office downstream because they have no visual record of
            // the room state. Confirm-dialog warning, NOT a hard block —
            // some surveys (e.g. cabling-only routes) genuinely have no
            // photo subject. Engineer can override.
            const photoCount = Array.isArray(r.photos) ? r.photos.length : 0;
            if (photoCount === 0) {
                const proceed = await window.appConfirm(
                    'No photos have been captured for this room. Photos are critical for the office to plan the install without a return visit. Continue without photos?',
                    { title: 'No photos captured', confirmLabel: 'Continue', danger: true }
                );
                if (!proceed) return;
            }

            await this.autosave();
            try {
                const resp = await fetch(
                    '/survey/' + this.token + '/rooms/' + r._ui.room_id + '/complete',
                    {
                        method:  'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                        body: JSON.stringify({}),
                    }
                );
                const result = await resp.json();
                if (result.completed) {
                    this.rooms[this.currentRoomIdx]._ui.is_completed = true;
                }
            } catch (_) {}
            this.screen         = 'rooms';
            this.currentRoomIdx = null;
            window.scrollTo(0, 0);
        },

        // ── Equipment repeater ────────────────────────────────────────────────
        addEquipment() {
            const r = this.rooms[this.currentRoomIdx];
            if (!Array.isArray(r.equipment)) r.equipment = [];
            r.equipment.push({ type: '', status: 'new', location: '' });
        },

        removeEquipment(idx) {
            this.rooms[this.currentRoomIdx].equipment.splice(idx, 1);
        },

        // ── Additional items ──────────────────────────────────────────────────
        addAdditionalItem() {
            const r = this.rooms[this.currentRoomIdx];
            if (!Array.isArray(r.additional_items)) r.additional_items = [];
            r.additional_items.push({ qty: '', description: '', note: '' });
        },

        removeAdditionalItem(idx) {
            const r = this.rooms[this.currentRoomIdx];
            if (Array.isArray(r.additional_items)) r.additional_items.splice(idx, 1);
        },

        // Parse the per-room kit string ("1 × Sony 98″ display\n2 × Crestron Saros…")
        // into structured rows so the wizard can render them as a clean table.
        parseKitLines(kitText) {
            const out = [];
            for (const raw of String(kitText ?? '').split(/\r?\n/)) {
                const line = raw.trim();
                if (line === '') continue;
                const m = line.match(/^(\d+)\s*[×xX]\s*(.+)$/);
                if (m) {
                    out.push({ qty: m[1], name: m[2].trim() });
                } else {
                    out.push({ qty: '',   name: line });
                }
            }
            return out;
        },

        // Aggregate across all rooms — drives the rooms-list "Items" burger.
        get allAdditionalItems() {
            const out = [];
            for (const r of (this.rooms || [])) {
                for (const it of (r.additional_items ?? [])) {
                    const desc = (it?.description ?? '').trim();
                    if (desc === '') continue;
                    out.push({ room: r.name || 'Unnamed', qty: it.qty || '', description: desc, note: it.note || '' });
                }
            }
            return out;
        },

        // ── Pre-install checks ───────────────────────────────────────────────
        async answerCheck(q, value) {
            if (!q || !q.id) return;
            const prev = q.answer;
            q.answer = value;                        // optimistic
            if (value !== 'other') q.other_text = '';
            const roomId = this.currentRoom?._ui?.room_id;
            if (!roomId) return;
            try {
                await fetch(
                    '/survey/' + this.token + '/rooms/' + roomId + '/questions/' + q.id,
                    {
                        method:  'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept':       'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                        body: JSON.stringify({ answer: value }),
                    }
                );
            } catch (_) { q.answer = prev; }
        },

        async saveCheckOtherText(q, value) {
            if (!q || !q.id) return;
            const next = (value ?? '').trim();
            if ((q.other_text ?? '') === next) return;
            q.other_text = next;
            const roomId = this.currentRoom?._ui?.room_id;
            if (!roomId) return;
            try {
                await fetch(
                    '/survey/' + this.token + '/rooms/' + roomId + '/questions/' + q.id,
                    {
                        method:  'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept':       'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                        body: JSON.stringify({ other_text: next }),
                    }
                );
            } catch (_) {}
        },

        // ── Photo upload ──────────────────────────────────────────────────────
        async uploadPhoto(roomId, input) {
            const file = input.files[0];
            if (!file) return;
            const category = input.dataset.category || '';
            const formData = new FormData();
            formData.append('photo',    file);
            formData.append('category', category);
            try {
                const resp = await fetch(
                    '/survey/' + this.token + '/rooms/' + roomId + '/photos',
                    {
                        method:  'POST',
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                        body: formData,
                    }
                );
                const res = await resp.json();
                if (res.id && this.currentRoom) {
                    // Push into canonical photos array with full shape so the
                    // caption editor can mutate it without a page reload.
                    this.rooms[this.currentRoomIdx].photos.push({
                        id:        res.id,
                        type:      res.category ?? category,
                        caption:   res.caption ?? '',
                        file_path: res.url ?? '',
                    });
                }
            } catch (_) {}
            input.value = '';
        },

        // ── Photo caption ─────────────────────────────────────────────────────
        async savePhotoCaption(photo, value) {
            if (!photo || !photo.id) return;
            const next = (value ?? '').trim();
            if ((photo.caption ?? '') === next) return;
            photo.caption = next;
            try {
                await fetch('/survey/' + this.token + '/photos/' + photo.id, {
                    method:  'PATCH',
                    headers: {
                        'Content-Type':   'application/json',
                        'Accept':         'application/json',
                        'X-CSRF-TOKEN':   document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({ caption: next }),
                });
            } catch (_) {}
        },

        // ── Final submit ──────────────────────────────────────────────────────
        async submitSurveyTop() {
            if (!this.surveyorName.trim()) return;
            this.submitting = true;
            document.getElementById('submit-form-top').submit();
        },

        // ── Engineer-feedback helpers (quick task 260503-u2x) ─────────────────
        addMountingOther() {
            const r = this.rooms[this.currentRoomIdx];
            if (!Array.isArray(r.engineer_feedback.mounting_heights.other)) {
                r.engineer_feedback.mounting_heights.other = [];
            }
            r.engineer_feedback.mounting_heights.other.push({ label: '', height_m: null });
        },
        removeMountingOther(idx) {
            this.rooms[this.currentRoomIdx].engineer_feedback.mounting_heights.other.splice(idx, 1);
        },

        addCableRoute() {
            const r = this.rooms[this.currentRoomIdx];
            if (!Array.isArray(r.engineer_feedback.cable_routes)) {
                r.engineer_feedback.cable_routes = [];
            }
            r.engineer_feedback.cable_routes.push({ category: '', from: '', to: '', length_m: null, notes: '' });
        },
        removeCableRoute(idx) {
            this.rooms[this.currentRoomIdx].engineer_feedback.cable_routes.splice(idx, 1);
        },

        addBracket() {
            const r = this.rooms[this.currentRoomIdx];
            if (!Array.isArray(r.engineer_feedback.brackets_required)) {
                r.engineer_feedback.brackets_required = [];
            }
            r.engineer_feedback.brackets_required.push({ equipment: '', model: '', pull_out: false, notes: '' });
        },
        removeBracket(idx) {
            this.rooms[this.currentRoomIdx].engineer_feedback.brackets_required.splice(idx, 1);
        },

        toggleWahMethod(method) {
            const r = this.rooms[this.currentRoomIdx];
            if (!Array.isArray(r.engineer_feedback.work_at_height_methods)) {
                r.engineer_feedback.work_at_height_methods = [];
            }
            const i = r.engineer_feedback.work_at_height_methods.indexOf(method);
            if (i >= 0) r.engineer_feedback.work_at_height_methods.splice(i, 1);
            else        r.engineer_feedback.work_at_height_methods.push(method);
        },

        toggleWallConstruction(material) {
            const r = this.rooms[this.currentRoomIdx];
            if (!Array.isArray(r.engineer_feedback.wall_construction)) {
                r.engineer_feedback.wall_construction = [];
            }
            const i = r.engineer_feedback.wall_construction.indexOf(material);
            if (i >= 0) r.engineer_feedback.wall_construction.splice(i, 1);
            else        r.engineer_feedback.wall_construction.push(material);
        },
    };
}
</script>

</body>
</html>
