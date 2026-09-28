{{--
    One produced document on the OVERVIEW tab of a module that holds no visits
    — quick task 260928-dq2.

    WHY THIS IS NOT `x-cockpit.file-row`. That component is the Files tab's
    library row and its copy is "View", never anything else: its own docblock
    says so and `CockpitPanelTest::test_the_files_tab_link_copy_is_view_and_never_download()`
    enforces it. This row answers a different question. The Files tab asks
    "what does the project hold?"; Overview asks "what do I do with it?" — so
    this row offers EDIT and the FINISHED ARTEFACT, and the Files tab is left
    byte-for-byte as it was. Two rows, two jobs, one stylesheet: the classes
    below are `.cav-file`'s existing ones, so `resources/css/cockpit.css` — a
    Vite entry — is untouched and no `npm run build` is needed.

    THIS FILE NAMES NO DOCUMENT. `action`, `route` and `formats` all arrive
    RESOLVED from `CockpitPanelPresenter`, which reads them from its own
    `DOCUMENTS` map and from `CockpitDocumentFormPresenter`'s `formats` key.
    There is no `@if` on a module key here and there must never be one: the
    cockpit's components switch on DATA, and that property has survived six
    reshapes. THAT INCLUDES THE VERB — "Edit" is not written here either, it is
    the map's `action` value, so a module whose route only SHOWS its document
    cannot be given an edit affordance by a copy change in a view.

    EDIT IS THE REVIEW PAGE, AND THAT IS NOT A COMPROMISE. The user asked for
    *"an edit function if a user needs to change things are rerun"*.
    `resources/views/rams/review.blade.php:541-552` is commented
    "Edit & Download form" and posts to `rams.update-and-download` — the review
    page has always BEEN the edit form. The O&M's mapped route is
    `om-manuals.edit` outright. So Edit is one anchor, not a new screen.

    THE COPY WAS CHECKED BEFORE USE. "Edit", "Word" and "PDF" were
    substring-checked against all 21 `CockpitReadOnlyFenceTest::DEFERRED_AFFORDANCES`
    keys and both `FORBIDDEN_MARKUP` entries. None collides. In particular
    "Edit" does NOT contain "Edit details", which is Phase 49's entry and stays
    banned, and the word "Download" — entry 21 — is deliberately NOT used, so
    nothing is lifted from the fence by this row. Fence counts stay 2/21/9/13.

    THE LINKS ARE OPTIONAL AND THEIR ABSENCE IS HONEST, on `file-row`'s own
    ruling: a document whose edit route could not be resolved renders no Edit
    anchor rather than one pointing at `#`, and a module with no PDF path
    (DC-07's worksheet) renders no PDF anchor rather than one that 404s. The
    name, date and status always render, so an unlinked row is still a complete
    record.

    ⚠ NO `cav-qa__control` HERE. `CockpitDocumentFormTest` asserts the closed
    document form carries EXACTLY ONE control, and this row sits outside
    `.cav-qa` precisely so that ruling is kept rather than weakened to fit a
    second anchor.

    Every value is user-authored (filenames come from uploads), so all of it
    goes through the escaping echo. No cockpit view uses unescaped output.

    THE PROP IS `produced`, NOT `produced_at` — Blade camel-cases bound prop
    names, and an underscored one arrives STRINGIFIED, making `->format()` a
    fatal that blanks the page. Measured by `file-row`, inherited here.
--}}
@props(['name', 'produced' => null, 'status' => '', 'route' => null, 'action' => 'View', 'formats' => []])

<div {{ $attributes->merge(['class' => 'cav-file']) }}>
    <span class="cav-file__ident">
        <span class="cav-file__name">{{ $name }}</span>

        <span class="cav-file__meta">{{ $produced?->format('d M Y') ?? 'Date not recorded' }} · {{ $status }}</span>
    </span>

    @if ($route !== null)
        {{-- The aria-label names WHICH document, because a screen reader's link
             list of identical "Edit" entries is unusable. --}}
        <a class="cav-file__view" href="{{ $route }}" aria-label="{{ $action }} {{ $name }}">{{ $action }}</a>
    @endif

    @foreach ($formats as $format)
        {{-- A PDF anchor can lead to a COMPLIANCE FAILURE rather than a file:
             `RamsController::downloadPdf()` raises `RamsGenerationException`
             for GATE-06/07/09. It is already caught there (:875-882) and
             returned as `back()->with('error', ...)`, which lands back on the
             cockpit URL this anchor was clicked from and prints the gate's own
             sentence in the layout's flash banner. So promoting this link
             cannot turn a gate into a 500 — and a test asserts the redirect
             and the sentence rather than trusting this comment. --}}
        <a class="cav-file__view" href="{{ $format['url'] }}" aria-label="{{ $format['label'] }} copy of {{ $name }}">{{ $format['label'] }}</a>
    @endforeach
</div>
