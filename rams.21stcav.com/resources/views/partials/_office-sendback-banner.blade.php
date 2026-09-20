{{--
    Office send-back banner (Phase 46, Plan 46-05).

    Shared partial consumed by:
      - resources/views/surveys/show.blade.php (the ENGINEER's survey link) —
        passes the reason.
      - resources/views/worksheets/public-show.blade.php (the link a CLIENT
        signs) — passes reason = null. DELIBERATE, see below.

    Required params:
      - $reopened bool          whether the office has asked for more info and
                                not yet had it. Renders nothing when false.
      - $at       ?Carbon       when the office asked.
      - $reason   ?string       the PM's own words. NULL on the client link.
      - $rooms    ?array        rooms_in_scope, worksheet link only. Optional.

    ── TWO AUDIENCES, TWO AMOUNTS OF DETAIL ────────────────────────────────

    `/survey/{token}` is the engineer's own link (routes/web.php says so in
    words). The engineer is the person being asked, and asking without saying
    what for is how a second incomplete return happens. They get the reason.

    `/worksheet/{token}` is signed by the CLIENT. `send_back_reason` is the
    office's internal wording about its own engineer. The client learns the
    visit is not finished; they do not read the office's opinion of the return.
    THIS IS A DELIBERATE RULING (threat T-46-05-02) recorded here so a later
    agent does not "fix" the missing reason by passing it through. A test
    puts a sentinel in `send_back_reason` and requires it ABSENT from the
    worksheet body and PRESENT on the survey body.

    ── ESCAPING ────────────────────────────────────────────────────────────

    `$reason` is PM-authored FREE TEXT rendered on an unauthenticated public
    page. It goes through an ESCAPED echo and must NEVER be switched to Blade's
    unescaped-output form (threat T-46-05-03, asserted against a <script>
    payload on the raw body, and by a test that greps this file for the
    unescaped-echo token -- which is why that token is not written out here).

    ── LR-04 ───────────────────────────────────────────────────────────────

    This banner carries NO actor name and must never reference the labour
    resource model — no engineer email or phone may reach a client page. This
    file is enumerated in the client-surface privacy guard's CLIENT_FACING_PATHS,
    which scans for that class name as a literal string. That is why the name is
    spelled out nowhere in this file, including in this comment.

    NO new global CSS and no `cav-` tokens: these two pages are NOT the
    cockpit and must not import cockpit CSS. Inline styles only, consistent
    with _engineer-reference-drawer.blade.php.

    Renders nothing at all when $reopened is false. No banner history, no
    "previously sent back" — once the engineer resubmits it goes away whole.
--}}
@if(! empty($reopened))
    <div style="background:#FFF7ED;border:1px solid #FDBA74;border-left:4px solid #C2410C;border-radius:12px;padding:14px 16px;margin-bottom:16px;">
        <div style="display:flex;align-items:center;gap:8px;">
            <span style="font-size:18px;line-height:1;">&#9888;</span>
            <span style="font-weight:700;color:#7C2D12;font-size:15px;">
                The office has asked for more information
            </span>
        </div>

        @if(! empty($at))
            <div style="color:#9A3412;font-size:13px;margin-top:4px;">
                Asked on {{ $at->format('j M Y') }}
            </div>
        @endif

        @if(! empty($reason))
            {{-- ESCAPED echo. Never the unescaped form: PM free text, public page. --}}
            <div style="color:#7C2D12;font-size:14px;margin-top:8px;white-space:pre-wrap;">{{ $reason }}</div>
        @endif

        @if(! empty($rooms))
            {{-- D-05: information, not a gate. An engineer is never blocked
                 from a room because this list is stale. --}}
            <div style="color:#9A3412;font-size:13px;margin-top:8px;">
                <span style="font-weight:600;">Rooms in scope for this visit:</span>
                {{ implode(', ', array_map('strval', $rooms)) }}
            </div>
        @endif
    </div>
@endif
