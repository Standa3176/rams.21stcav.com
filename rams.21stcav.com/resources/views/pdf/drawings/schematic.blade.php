<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $drawing->kindLabel() }} — {{ $drawing->project->ref ?? $drawing->project->quote_reference ?? '' }}</title>
    {{--
        Phase 17 schematic Blade.

        Plan 03 wires PdfRenderService::fromBlade('pdf.drawings.schematic',
        ['drawing' => $drawing]) — Plan 02 (this file) creates the template
        the renderer will pick up. Browsershot composes the embedded SVG
        plus an HTML title block; the SVG comes from the deterministic D2
        CLI server-side, so {!! ... !!} is safe (T-17.02-04 trust source).

        No SVG foreign-object containers anywhere — both the SVG output (D2)
        and the Blade composition stay on the safe rendering path
        (PITFALLS MIN-03, CRIT-01).

        page-break-inside: avoid keeps the schematic + title block on a
        single landscape page (PITFALLS MIN-04).
    --}}
    <style>
        @page {
            size: A4 landscape;
            margin: 10mm;
        }
        body {
            font-family: 'Figtree', sans-serif;
            margin: 0;
            padding: 0;
            color: #111;
        }
        .schematic-page {
            display: flex;
            flex-direction: column;
            height: 100vh;
            page-break-inside: avoid;
            break-inside: avoid;
        }
        .schematic-svg-wrap {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            max-height: 80vh;
            overflow: hidden;
            padding: 4mm 4mm 0 4mm;
        }
        .schematic-svg-wrap svg {
            max-width: 100%;
            max-height: 100%;
            height: auto;
            width: auto;
        }
        /*
         * Force SVG text to a guaranteed-system font on the production AlmaLinux
         * server. D2's SVG output references specific fonts (e.g. Source Sans
         * Pro) that aren't installed on most servers, so chrome-headless-shell
         * falls back and miscalculates glyph widths — visible as letters spaced
         * apart ("S ig na lFlow"). Overriding font-family on every text/tspan
         * forces Chrome to use a font it definitely has metrics for.
         */
        .schematic-svg-wrap svg text,
        .schematic-svg-wrap svg tspan {
            font-family: Arial, Helvetica, 'Liberation Sans', 'DejaVu Sans', sans-serif !important;
        }
        .schematic-empty {
            font-size: 12pt;
            color: #6b7280;
            font-style: italic;
            text-align: center;
        }
        .title-block-wrap {
            display: flex;
            justify-content: flex-end;
            padding: 6mm 10mm 4mm 10mm;
        }
    </style>
</head>
<body>
    <div class="schematic-page">
        <div class="schematic-svg-wrap">
            @if (! empty($drawing->generated_svg))
                {!! $drawing->generated_svg !!}
            @else
                <p class="schematic-empty">Schematic SVG not available — regenerate this drawing.</p>
            @endif
        </div>
        <div class="title-block-wrap">
            @include('pdf.drawings._title-block', ['drawing' => $drawing])
        </div>
    </div>
</body>
</html>
