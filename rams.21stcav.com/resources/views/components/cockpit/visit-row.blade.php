{{--
    Visit row — [52px date] [title] [sub-line] [chips] [status].

    ROWS ARE STATIC. No cursor:pointer, no hover colour shift, no tabindex, no
    onclick. Rows become openable in Phase 46; until then they must not
    advertise it, because a row that looks clickable and is not is worse than
    one that looks inert. No pip either — pips live on drawer summaries only.

    RECONSTRUCTED (D-02) — all four treatments, none optional:
      1. the Reconstructed chip, FIRST in the chip strip
      2. a 2px DASHED left edge (.cav-visit--reconstructed)
      3. the verbatim sub-line, different for worksheet- and survey-derived
      4. worksheet-derived only: the inferred type word is dotted-underlined
         and aria-describedby points at that sub-line. A survey-derived
         visit's TYPE is not in doubt, only its provenance.
    DASHED MEANS INFERRED, SOLID MEANS RECORDED — a line style, not a hue, so
    it survives greyscale. No gold (not actionable) and no teal (not
    confirmed): reconstructed rows sit in the neutral tier.

    SUPERSEDED (D-04):
      - position UNCHANGED, in date order, never hidden and never behind a
        toggle: superseding the paperwork does not un-happen the trip to site
      - the Superseded chip, SOLID border
      - line-through scoped to the DELIVERABLE NAME only. Striking the whole
        row would obscure the very fact D-04 exists to preserve
      - NO OPACITY on this row: the meta text is --cav-light, and 0.7 would
        take it to 3.03:1 and fail AA
    A row may legitimately carry BOTH chips, Reconstructed first.

    Neither qualifier is ever gold and neither is counted as attention.

    Source force-deleted: the visit still renders, with the Record unavailable
    chip. A broken source must never remove a visit from the spine.
--}}
{{--
    ── THE ACTION AREA (Phase 46, Plan 46-06) ───────────────────────────────

    Two of D-02's four PM acts, on the row of the visit they act on: ACCEPT and
    SEND BACK. 46-07 adds Add note and Raise a snag, making FOUR — and four is
    the cap (VL-11), asserted over every factory state x every visit type by
    CockpitVisitActionsTest::test_no_visit_row_ever_renders_more_than_four_controls().
    A fifth control means removing one, not widening the row.

    WHO GETS WHAT:
      planned   none        sent_back  Accept (there is no second send-back)
      sent      none        accepted   none — "Accepted by {name} on {date}"
      returned  Accept + Send back     closed (reconstructed) none

    NOTHING IS EVER DISABLED. A disabled control is still an offer, and one
    that never explains itself is how a PM decides the page is broken. A state
    with no act renders a SENTENCE instead.

    D-06 — THERE IS NO "EDIT VISIT" CONTROL, so the scope lock has nothing to
    grey out. It says "Scope locked — returned {date}" and a PM who needs a
    change sends the visit back.

    NO JAVASCRIPT. Each act is its own small form POST; the reason field is
    disclosed by `&action=send-back&visit={id}` on the module's own URL and
    closed by an anchor back — the same query-string mechanism the panel has
    used since 45-11. All nine of the fence's banned handler attributes stay
    absent and there is no <select>.

    ── WHERE THE ACTION AREA RENDERS (Phase 46.1, Plan 46.1-05, D-02) ───────

    IT NO LONGER RENDERS ON OVERVIEW. The four acts are now drawn ONLY where
    `controls` is passed true, and the only caller that passes it is
    `returned-tab.blade.php`, beneath that visit's evidence. The reason is the
    user's own, recorded as D-02 of `46.1-CONTEXT.md`: A PM SHOULD NOT BE ABLE
    TO ACCEPT A VISIT WITHOUT HAVING LOOKED AT IT. Before this move, Accept sat
    on a row showing a date, a title and a status chip, and nothing that came
    back from site.

    THE DEFAULT IS FALSE, NOT TRUE. A component that offers a write by default
    is one bad include away from offering it somewhere nobody looked.

    OVERVIEW KEEPS EVERY SENTENCE AND LOSES ONLY THE OFFERS. "Accepted by {name}
    on {date}", "Sent back {date} — awaiting the engineer", "Awaiting the
    engineer", the scope lock and the snag count all still render there. What
    moved is the OFFER, never the record of what happened.

    THE GATES ARE UNCHANGED. `controls` is an AND on top of the eight booleans
    below, never a replacement for them: a reconstructed visit still offers
    ZERO on the Returned tab (D-06, and 24 backfilled rows on live), and VL-11's
    cap of four is still a property of this row wherever the row is drawn.

    The three disclosure links carry `tab=returned` so that disclosing a form
    from the Returned tab does not throw the PM back to Overview.
--}}
@props([
    'visit',
    'project'       => null,
    'module'        => null,
    'action'        => null,
    'actionVisitId' => null,
    // Plan 46.1-05 (D-02). FALSE BY DEFAULT — see the action-area docblock.
    'controls'      => false,
])

@php
    $isReconstructed = $visit->isBackfilled();
    $isSuperseded    = $visit->isSuperseded();

    $source        = $visit->source();
    $sourceMissing = $visit->source_id !== null && $source === null;

    $fromWorksheet = $visit->source_type === \App\Models\Visit::SOURCE_WORKSHEET;

    $typeLabels = [
        \App\Models\Visit::TYPE_SITE_SURVEY   => 'Site survey',
        \App\Models\Visit::TYPE_FIRST_FIX     => 'First fix',
        \App\Models\Visit::TYPE_INSTALL       => 'Install',
        \App\Models\Visit::TYPE_PROGRAMMING   => 'Programming',
        \App\Models\Visit::TYPE_SNAG          => 'Snagging',
        \App\Models\Visit::TYPE_COMMISSIONING => 'Commissioning',
    ];

    $typeLabel = $typeLabels[$visit->type] ?? 'Visit';
    $title     = filled($visit->title) ? $visit->title : $typeLabel . ' visit';

    $subId = 'cav-visit-' . $visit->id . '-inferred';

    $supersededOn = null;

    if ($source !== null) {
        $supersededOn = $source->getAttribute('superseded_at') ?? $source->getAttribute('deleted_at');
    }

    // isClosed() (Phase 46) rather than a literal status comparison, for the
    // same reason the progress ring uses it: an accepted visit is finished.
    $statusLabel = $visit->isClosed() ? 'Completed' : 'Planned';

    // ── The lifecycle, said in words (Phase 46, Plan 46-06) ──────────────
    //
    // A RECONSTRUCTED VISIT IS EXCLUDED FROM ALL OF IT. `Visit::state()`
    // reads RETURNED for a backfilled visit whose worksheet carries a
    // sign-off (46-01's recorded finding), and there are 24 such rows on
    // live. Gating on `isClosed()` as well as on `isBackfilled()` is what
    // stops two dozen years-old trips to site reading as work awaiting a PM's
    // attention on the day this ships.
    $isReviewable = ! $isReconstructed && ! $visit->isClosed();
    $state        = $visit->state();

    // The lock is a SENTENCE, never a greyed-out control. D-06: there is no
    // edit affordance to disable, because a PM who needs a change sends the
    // visit back. A disabled control that never explains itself is how a PM
    // concludes the page is broken.
    $lockedOn  = $isReconstructed ? null : $visit->returnedAt();
    $acceptedBy = $visit->accepted_at === null ? null : optional($visit->acceptedBy)->name;

    // The acts this row offers. `$project` is null only where a caller renders
    // the row outside the panel, in which case it offers nothing rather than
    // building a route it cannot address.
    $canAct      = $isReviewable && $project !== null && $module !== null;
    $canAccept   = $canAct && in_array($state, [\App\Models\Visit::STATE_RETURNED, \App\Models\Visit::STATE_SENT_BACK], true);
    $canSendBack = $canAct && $state === \App\Models\Visit::STATE_RETURNED;

    // -- The other two acts (Phase 46, Plan 46-07) -----------------------
    //
    // A NOTE IS OFFERED ON AN ACCEPTED VISIT AND A SNAG IS NOT, which is why
    // these two are derived separately rather than sharing `$canAct`. An
    // accepted visit is CLOSED, so `$canAct` is false for it -- but a note
    // after acceptance is exactly the annotation D-02 describes and changes
    // nothing, whereas a snag found after acceptance belongs in Phase 47's
    // register rather than in a retroactive edit to a closed visit.
    //
    // BOTH STILL EXCLUDE A RECONSTRUCTED VISIT. 24 backfilled rows on live
    // derive STATE_RETURNED while being closed, and none of them is asking a
    // PM for a reading.
    $hasContext = ! $isReconstructed && $project !== null && $module !== null;
    $canNote    = $hasContext && in_array($state, [
        \App\Models\Visit::STATE_RETURNED,
        \App\Models\Visit::STATE_SENT_BACK,
        \App\Models\Visit::STATE_ACCEPTED,
    ], true);
    $canSnag    = $canAct && in_array($state, [\App\Models\Visit::STATE_RETURNED, \App\Models\Visit::STATE_SENT_BACK], true);

    // A plain COUNT, never a link: there is no snag register in Phase 46 and
    // `Open register` is still a banned affordance. Eager-loaded by the read
    // controller (`visits.snags`), so this is not a query per row.
    $snagCount = $hasContext ? $visit->snags->count() : 0;

    $moduleUrl   = $canAct
        ? route('projects.cockpit', ['project' => $project, 'module' => $module['key']])
        : null;
    $sendBackUrl = $canAct
        ? route('projects.cockpit', [
            'project' => $project,
            'module'  => $module['key'],
            'action'  => 'send-back',
            'visit'   => $visit->id,
            // Plan 46.1-05: the acts live on the Returned tab now, so the
            // disclosure has to come back to it rather than to Overview.
            'tab'     => 'returned',
        ])
        : null;

    // The same disclosure mechanism, for the two acts 46-07 adds.
    $noteUrl = $canNote
        ? route('projects.cockpit', [
            'project' => $project,
            'module'  => $module['key'],
            'action'  => 'note',
            'visit'   => $visit->id,
            'tab'     => 'returned',
        ])
        : null;

    $snagUrl = $canSnag
        ? route('projects.cockpit', [
            'project' => $project,
            'module'  => $module['key'],
            'action'  => 'snag',
            'visit'   => $visit->id,
            'tab'     => 'returned',
        ])
        : null;

    // COMPARED, never looked up: the id came off the query string.
    $sendBackOpen = $canSendBack && $action === 'send-back' && (int) $actionVisitId === $visit->id;
    $noteOpen     = $canNote && $action === 'note' && (int) $actionVisitId === $visit->id;
    $snagOpen     = $canSnag && $action === 'snag' && (int) $actionVisitId === $visit->id;

    // ONLY ONE FORM IS EVER OPEN, because only one `action` fits in the URL --
    // and while one IS open, the other triggers step aside. That is what keeps
    // the row at FOUR: Accept plus an open form's submit and Cancel is three,
    // and the closed row's four controls ARE the cap.
    $formOpen = $sendBackOpen || $noteOpen || $snagOpen;
@endphp

<div class="cav-visit{{ $isReconstructed ? ' cav-visit--reconstructed' : '' }}">
    <span class="cav-visit__date">{{ optional($visit->scheduled_date)->format('d M') }}</span>

    <span class="cav-visit__main">
        <span class="{{ $isSuperseded ? 'cav-superseded-name' : 'cav-visit__name' }}">{{ $title }}</span>

        @if ($isReconstructed && $fromWorksheet)
            <span class="cav-inferred-type" aria-describedby="{{ $subId }}">{{ $typeLabel }}</span>
        @endif

        @if ($isReconstructed)
            <span class="cav-visit__sub" id="{{ $subId }}">
                @if ($fromWorksheet)
                    Type inferred from a signed worksheet — the work actually done was not recorded.
                @else
                    Built from the existing survey record, not captured as a visit.
                @endif
            </span>
        @endif

        @if ($isSuperseded)
            <span class="cav-visit__sub">
                Superseded {{ optional($supersededOn)->format('d M Y') }} — the visit still happened.
            </span>
        @endif

        @if ($sourceMissing)
            <span class="cav-visit__sub">
                The visit is recorded; the document behind it could not be read.
            </span>
        @endif

        @if ($isReconstructed || $isSuperseded || $sourceMissing)
            <span class="cav-chips">
                @if ($isReconstructed)
                    <x-cockpit.chip variant="reconstructed" />
                @endif

                @if ($isSuperseded)
                    <x-cockpit.chip variant="superseded" />
                @endif

                @if ($sourceMissing)
                    <x-cockpit.chip variant="record-unavailable" />
                @endif
            </span>
        @endif

        @if ($visit->accepted_at !== null && ! $isReconstructed)
            {{-- WHO and WHEN, because an acceptance with no accountable actor
                 is repudiable (T-46-06-03). A deleted staff login reads NULL
                 at the database, so the actor degrades to "the office" rather
                 than silently becoming somebody else. --}}
            <span class="cav-visit__state">
                Accepted by {{ $acceptedBy ?? 'the office' }} on {{ $visit->accepted_at->format('d M Y') }}
            </span>
        @elseif ($state === \App\Models\Visit::STATE_SENT_BACK && ! $isReconstructed)
            <span class="cav-visit__state">
                Sent back {{ optional($visit->sent_back_at)->format('d M Y') }} — awaiting the engineer
            </span>
        @elseif ($visit->isAwaitingReturn() && ! $isReconstructed)
            <span class="cav-visit__state">Awaiting the engineer</span>
        @endif

        @if ($lockedOn !== null)
            {{-- Scope locks ON RETURN, not on acceptance (Visit::isLocked()).
                 The sentence is the whole affordance. --}}
            <span class="cav-visit__lock">Scope locked — returned {{ $lockedOn->format('d M Y') }}</span>
        @endif

        {{-- `$controls` is an AND on top of the gates, never a replacement:
             D-02 moved WHERE these render, not WHO gets them. --}}
        @if ($controls && ($canAccept || $canSendBack || $canNote || $canSnag))
            <span class="cav-visit__actions">
                @if ($canAccept)
                    {{-- Its own small form POST. Acceptance is FINAL in this
                         phase: there is no un-accept, because one would erase
                         the record of who said yes. --}}
                    <form class="cav-visit__act" method="POST"
                          action="{{ route('projects.cockpit.visits.accept', ['project' => $project, 'visit' => $visit->id]) }}">
                        @csrf
                        <button class="cav-visit__control" type="submit">Accept</button>
                    </form>
                @endif

                @if ($canSendBack && ! $formOpen)
                    <a class="cav-visit__control cav-visit__control--quiet" href="{{ $sendBackUrl }}">Send back</a>
                @endif

                @if ($canNote && ! $formOpen)
                    {{-- `Add note`, never `Add document` (Phase 48, and still
                         a banned affordance). --}}
                    <a class="cav-visit__control cav-visit__control--quiet" href="{{ $noteUrl }}">Add note</a>
                @endif

                @if ($canSnag && ! $formOpen)
                    {{-- `Raise a snag`, never `Add a snag` or `Book a visit` --
                         both are Phase 47 wording and both are still banned by
                         name in the fence. Raising a snag creates one open
                         record and stops (D-03); Phase 47 extends it. --}}
                    <a class="cav-visit__control cav-visit__control--quiet" href="{{ $snagUrl }}">Raise a snag</a>
                @endif

                @if ($sendBackOpen)
                    <form class="cav-visit__act cav-visit__act--reason" method="POST"
                          action="{{ route('projects.cockpit.visits.send-back', ['project' => $project, 'visit' => $visit->id]) }}">
                        @csrf

                        {{-- THE ONE PLACE IN THIS PHASE WHERE AN INPUT'S
                             AUDIENCE IS NOT OBVIOUS FROM WHERE IT SITS. The
                             PM is writing to the engineer, on a page the PM
                             never sees. --}}
                        <span class="cav-visit__hint">The engineer will read this on their link.</span>

                        @error('reason')
                            {{-- Escaped, always. A submitted value is never
                                 reflected raw (T-46-06-04). --}}
                            <span class="cav-visit__error">{{ $message }}</span>
                        @enderror

                        <textarea class="cav-visit__reason" name="reason" rows="3"
                                  aria-label="Why this visit is going back">{{ old('reason') }}</textarea>

                        <span class="cav-visit__row">
                            <button class="cav-visit__control" type="submit">Send back</button>
                            <a class="cav-visit__cancel" href="{{ $moduleUrl }}">Cancel</a>
                        </span>
                    </form>
                @endif

                @if ($noteOpen)
                    {{-- THE OFFICE'S READING OF THE RETURN. It goes to
                         `visit_notes`, never to the engineer's record, and it
                         never appears on an engineer link. --}}
                    <form class="cav-visit__act cav-visit__act--reason" method="POST"
                          action="{{ route('projects.cockpit.visits.notes', ['project' => $project, 'visit' => $visit->id]) }}">
                        @csrf

                        <span class="cav-visit__hint">Office only - the engineer does not see this.</span>

                        @error('body')
                            <span class="cav-visit__error">{{ $message }}</span>
                        @enderror

                        <textarea class="cav-visit__reason" name="body" rows="3"
                                  aria-label="The office note on this visit">{{ old('body') }}</textarea>

                        <span class="cav-visit__row">
                            <button class="cav-visit__control" type="submit">Add note</button>
                            <a class="cav-visit__cancel" href="{{ $moduleUrl }}">Cancel</a>
                        </span>
                    </form>
                @endif

                @if ($snagOpen)
                    {{-- D-03: THREE FIELDS. No outcome, no parts, no assignee,
                         no follow-up chain and no cost -- each is owned by a
                         named Phase 47 criterion and none of them exists in
                         the schema. Raising a snag is not managing one. --}}
                    <form class="cav-visit__act cav-visit__act--reason" method="POST"
                          action="{{ route('projects.cockpit.visits.snags', ['project' => $project, 'visit' => $visit->id]) }}">
                        @csrf

                        <span class="cav-visit__hint">What was found, and where.</span>

                        @error('title')
                            <span class="cav-visit__error">{{ $message }}</span>
                        @enderror

                        <input class="cav-visit__field" type="text" name="title" maxlength="200"
                               aria-label="What the snag is" value="{{ old('title') }}">

                        @error('room_name')
                            <span class="cav-visit__error">{{ $message }}</span>
                        @enderror

                        {{-- A plain string, not a room picker: a snag is often
                             raised about somewhere the room list does not yet
                             name, and a select element is still banned here. --}}
                        <input class="cav-visit__field" type="text" name="room_name" maxlength="200"
                               aria-label="Where on site (optional)" value="{{ old('room_name') }}">

                        @error('detail')
                            <span class="cav-visit__error">{{ $message }}</span>
                        @enderror

                        <textarea class="cav-visit__reason" name="detail" rows="2"
                                  aria-label="More detail (optional)">{{ old('detail') }}</textarea>

                        <span class="cav-visit__row">
                            <button class="cav-visit__control" type="submit">Raise a snag</button>
                            <a class="cav-visit__cancel" href="{{ $moduleUrl }}">Cancel</a>
                        </span>
                    </form>
                @endif
            </span>
        @endif

        @if ($snagCount > 0)
            {{-- A COUNT WITH NO DESTINATION. Phase 46 raises a snag; it does
                 not manage one, so there is nowhere honest to send a PM yet
                 and `Open register` stays banned. A span, not a control, so
                 the four-control cap is untouched. --}}
            <span class="cav-visit__snags">{{ $snagCount }} {{ $snagCount === 1 ? 'snag' : 'snags' }} raised</span>
        @endif
    </span>

    <span class="cav-visit__status">{{ $statusLabel }}</span>
</div>
