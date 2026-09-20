{{--
    Cockpit glyph — sketch 004.

    WHY THIS FILE EXISTS. `CockpitModulePresenter` deliberately emits an icon
    KEY, never inline SVG and never a hex: "the Blade layer owns the glyph and
    the token layer owns the colour" (its class docblock). Both the module row
    and the side panel render the same module's glyph, so the key-to-path map
    lives here once rather than being copied into two components where the two
    copies would drift the first time a glyph changed.

    An <svg> is not a form control and not a <script>, so the read-only fence
    is untroubled by it. Every glyph is decorative: it always sits beside the
    module's visible title, so it carries aria-hidden and adds nothing to the
    accessibility tree. A glyph that ever becomes the ONLY label must gain a
    role="img" and an aria-label at that call site instead.

    Stroke colour is `currentColor`, so a glyph inherits the --cav-* token
    already resolved on its container. There is no colour value in this file.
--}}
@props(['name'])

@php
    /**
     * Key => list of path `d` strings, drawn on a 24x24 grid.
     *
     * Paths are DATA and are echoed through {{ }} onto the `d` attribute, so
     * nothing here is unescaped output. An unknown key renders nothing rather
     * than a fallback glyph — a wrong icon is a quieter lie than no icon.
     */
    $glyphs = [
        'clipboard' => ['M9 4h6v3H9z', 'M9 5.5H6.5A1.5 1.5 0 0 0 5 7v12a1.5 1.5 0 0 0 1.5 1.5h11A1.5 1.5 0 0 0 19 19V7a1.5 1.5 0 0 0-1.5-1.5H15'],
        'wrench'    => ['M15 3a5 5 0 0 1-6.2 6.2L4 14v6h6l4.8-4.8A5 5 0 0 1 21 9'],
        'calendar'  => ['M7.5 3v3', 'M16.5 3v3', 'M4.5 9h15', 'M5 5.5h14v14H5z'],
        'shield'    => ['M12 3.2l7 2.8v5.6c0 4-3 6.6-7 9.2-4-2.6-7-5.2-7-9.2V6z'],
        'ruler'     => ['M3.2 14.6L14.6 3.2 20.8 9.4 9.4 20.8z', 'M8 10l2 2', 'M11 7l2 2'],
        'book'      => ['M4.5 5.5A2 2 0 0 1 6.5 3.5H11v17H6.5a2 2 0 0 1-2-2z', 'M19.5 5.5a2 2 0 0 0-2-2H13v17h4.5a2 2 0 0 0 2-2z'],
        'cable'     => ['M5 4v6a4 4 0 0 0 4 4h6a4 4 0 0 1 4 4v2', 'M3 4h4', 'M17 20h4'],
        'gear'      => ['M12 9.2a2.8 2.8 0 1 0 0 5.6 2.8 2.8 0 0 0 0-5.6z', 'M12 3.5v2', 'M12 18.5v2', 'M3.5 12h2', 'M18.5 12h2', 'M6.2 6.2l1.4 1.4', 'M16.4 16.4l1.4 1.4', 'M17.8 6.2l-1.4 1.4', 'M7.6 16.4l-1.4 1.4'],
        'flag'      => ['M6 21V4', 'M6 5h11l-2 3 2 3H6'],
        'document'  => ['M14 3.5H7.5A1.5 1.5 0 0 0 6 5v14a1.5 1.5 0 0 0 1.5 1.5h9A1.5 1.5 0 0 0 18 19V7.5z', 'M14 3.5v4h4'],
        'check'     => ['M12 3.2a8.8 8.8 0 1 0 0 17.6 8.8 8.8 0 0 0 0-17.6z', 'M8.2 12l2.6 2.6 5-5.4'],
        'pin'       => ['M12 21s6.8-5.6 6.8-10.8A6.8 6.8 0 1 0 5.2 10.2C5.2 15.4 12 21 12 21z', 'M12 12.3a2.4 2.4 0 1 0 0-4.8 2.4 2.4 0 0 0 0 4.8z'],
        'person'    => ['M12 4.2a3.4 3.4 0 1 0 0 6.8 3.4 3.4 0 0 0 0-6.8z', 'M4.8 20a7.2 7.2 0 0 1 14.4 0'],
        'close'     => ['M6.5 6.5l11 11', 'M17.5 6.5l-11 11'],
        'arrow'     => ['M5 12h13', 'M13 7l5 5-5 5'],
    ];

    $paths = $glyphs[$name] ?? [];
@endphp

@if ($paths !== [])
    <svg {{ $attributes->merge(['class' => 'cav-icon']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
        @foreach ($paths as $path)
            <path d="{{ $path }}" />
        @endforeach
    </svg>
@endif
