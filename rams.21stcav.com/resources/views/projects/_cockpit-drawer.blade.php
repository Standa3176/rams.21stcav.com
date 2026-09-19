{{--
    One section drawer and its body. Driven entirely by the array
    CockpitSectionPresenter built — this partial derives nothing.

    THE HINT IS MANDATORY. With every write affordance stripped by the
    read-only fence, drawer bodies get thin, and an open drawer must never be
    empty: one plain sentence saying what the section is and where its data
    comes from.

    Expects: $section
--}}
<x-cockpit.drawer
    :title="$section['title']"
    :pip="$section['pip']"
    :ticked="$section['ticked']"
    :count="$section['count']"
    :status="$section['status']"
    :attention="$section['attention']"
    :not-required="$section['not_required']">

    {{-- Date order, superseded rows included in place (D-04). --}}
    @foreach ($section['visits'] as $visit)
        <x-cockpit.visit-row :visit="$visit" />
    @endforeach

    @if ($section['kind'] === \App\Support\Cockpit\CockpitSectionPresenter::KIND_LIFECYCLE)
        @foreach ($section['rows'] as $row)
            <x-cockpit.lifecycle-row
                :name="$row['name']"
                :value="$row['value']"
                :done="$row['done'] ?? false" />
        @endforeach
    @else
        @foreach ($section['rows'] as $row)
            <x-cockpit.doc-row :name="$row['name']" :value="$row['value']" />
        @endforeach
    @endif

    <x-cockpit.hint>{{ $section['hint'] }}</x-cockpit.hint>

    @if ($section['not_required'])
        <x-cockpit.chip variant="not-required" />

        <x-cockpit.hint>Marked not required at import.</x-cockpit.hint>
    @endif
</x-cockpit.drawer>
