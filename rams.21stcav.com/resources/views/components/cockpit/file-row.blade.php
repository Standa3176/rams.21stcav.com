{{--
    One document in the project's library — sketch 004, D-13.

    D-13 is the user's own example of what this panel is for: "under docs a
    user can see all created project docs in one place (ie click and view
    etc)". So the row's job, in order, is: be PRESENT, be identifiable, and
    then be openable.

    THE LINK IS OPTIONAL AND ITS ABSENCE IS HONEST. `route` is null when the
    presenter could not resolve a GET route for that document type (it guards
    every route() call with Route::has, so a renamed route degrades here
    instead of throwing). When it is null this row renders NO anchor at all —
    not "View" pointing at `#`, which reads as a control that is broken rather
    than as information that is complete. The row still carries the document's
    name, the date it was produced and its status, which is what makes it
    useful even unlinked.

    THE COPY IS "View", NEVER "Download". "Download" is Phase 48's word and
    the read-only fence bans it by name. Viewing is reading; downloading a
    produced artefact is a separate surface that phase owns.

    Every value here is user-authored (filenames come from uploads), so all of
    it goes through the escaping echo. No cockpit view uses unescaped output,
    and a test asserts that across the whole component directory.

    THE PROP IS `produced`, NOT `produced_at`. Blade camel-cases prop names
    when it binds them, so an underscored prop name does not arrive as the
    object that was passed — it arrives stringified, and `->format()` on it is
    a fatal that blanks the page. MEASURED here, not assumed: `produced_at`
    threw "Call to a member function format() on string" on the first run.
--}}
@props(['name', 'produced' => null, 'status' => '', 'route' => null])

<div {{ $attributes->merge(['class' => 'cav-file']) }}>
    <span class="cav-file__ident">
        <span class="cav-file__name">{{ $name }}</span>

        {{-- "d M Y" matches the design's "14 Aug 2026". A document with no
             created_at says so rather than printing an epoch date. --}}
        <span class="cav-file__meta">{{ $produced?->format('d M Y') ?? 'Date not recorded' }} · {{ $status }}</span>
    </span>

    @if ($route !== null)
        {{-- The aria-label names WHICH document this opens: a screen reader's
             link list of six identical "View" entries is unusable. --}}
        <a class="cav-file__view" href="{{ $route }}" aria-label="View {{ $name }}">View</a>
    @endif
</div>
