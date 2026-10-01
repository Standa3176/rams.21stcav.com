{{--
    THE ENGINEER LINK CARD (Phase 47, Plan 47-01; 47-CONTEXT D-01 remainder /
    D-02).

    The cockpit already ISSUES the engineer link — `CockpitCombinedCreator`
    calls `VisitLinkIssuer` inside one transaction — and until this plan it
    NEVER DISPLAYED the result, except on the narrow collision-refusal flash
    `doc-form.blade.php` renders (quick task 260930-qcy). This card renders
    on every ordinary view: whenever `CockpitLinkPresenter::linkFor()`
    resolves a link for the open module, this card shows it, in plain,
    selectable text.

    NO JAVASCRIPT, NO CLIPBOARD BUTTON. There is no clipboard API available
    to this page; "copyable" means the URL is a visible, selectable `{{ }}`-
    escaped text node inside an `<a>`, exactly the treatment the collision
    flash already gave it.

    `$link` IS NULL FOR RAMS/OM AND FOR A MODULE WITH NO DOCUMENT YET
    (`CockpitLinkPresenter::linkFor()`'s own contract). The early return below
    is the house pattern for "render nothing" used elsewhere on this page.

    THE REVOKE IS WORKSHEET-ONLY. `$link['can_revoke']` is true only when
    `linkFor()` resolved a Worksheet — a survey has no revoke anywhere in this
    codebase (`SiteSurvey` carries no such method, and `VisitLinkIssuer`'s own
    interface comment says so) — so the `@else` branch states the absence in
    one sentence rather than rendering a disabled control. There is no third
    module this card ever renders for, so no further branch is needed.

    THE REVOKE FORM POSTS TO THE ALREADY-REGISTERED
    `worksheets.revoke-token` — NO NEW ROUTE. `$link['revoke_target']` is the
    resolved `Worksheet` model, carried only for the route's own `{worksheet}`
    binding; nothing about its access token is rendered here beyond what
    `publicUrl()` already composed above.

    CHECKED AS A SUBSTRING against all 21
    `CockpitReadOnlyFenceTest::DEFERRED_AFFORDANCES` keys and both
    `FORBIDDEN_MARKUP` entries before use: "Engineer link", "Current link:",
    "Revoke and reissue", "Revoking mints a fresh link and invalidates the one
    shown above.", "There is no way to revoke a survey link. Superseding this
    survey below starts a fresh one instead.", and every state-vocabulary
    sentence `CockpitLinkPresenter` can return. None collides, and the check
    is asserted for real in `CockpitLinkCardTest`, not only claimed here.

    NO NEW CSS. `.cav-panel__card`, `.cav-panel__card-head`, `.cav-qa__note`,
    `.cav-qa__form` and `.cav-qa__control` are all existing classes this page
    already uses elsewhere — `resources/css/cockpit.css` is untouched, so no
    `npm run build` is needed for this plan. Nothing here carries `position`
    or `transform` — the stretched-link trap this page's own house rule
    warns about.
--}}
@props(['link'])

@if ($link === null)
    @php
        return;
    @endphp
@endif

<div class="cav-panel__card">
    <span class="cav-panel__card-head">Engineer link</span>

    {{-- ESCAPED OUTPUT ONLY (T-46.2-17). The URL is the thing being shown on
         purpose — it is already reachable from site-survey/show.blade.php,
         worksheets/show.blade.php and projects/show.blade.php today, behind
         the same auth, so this is a new READER of a value already surfaced
         elsewhere rather than a new exposure. --}}
    <p class="cav-qa__note">Current link: <a href="{{ $link['url'] }}">{{ $link['url'] }}</a></p>

    <p class="cav-qa__note">{{ $link['state'] }}</p>

    @if ($link['can_revoke'])
        {{-- WORKSHEET ONLY. Posts to the EXISTING `worksheets.revoke-token`
             — this plan adds no route. The controller mints nothing; it
             calls the already-tested `Worksheet::regenerateAccessToken()`. --}}
        <form class="cav-qa__form" method="POST" action="{{ route('worksheets.revoke-token', $link['revoke_target']) }}">
            @csrf

            <p class="cav-qa__note">Revoking mints a fresh link and invalidates the one shown above.</p>

            <button class="cav-qa__control" type="submit">Revoke and reissue</button>
        </form>
    @else
        {{-- SITE SURVEY ONLY (the only other module this card ever renders
             for). Stated in words, never a disabled control — no revoke
             exists for a survey link anywhere in this codebase. --}}
        <p class="cav-qa__note">There is no way to revoke a survey link. Superseding this survey below starts a fresh one instead.</p>
    @endif
</div>
