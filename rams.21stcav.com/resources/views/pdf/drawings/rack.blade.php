<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $drawing->kindLabel() }} — {{ $drawing->project->ref ?? $drawing->project->quote_reference ?? '' }}</title>
    {{--
        Phase 18 Plan 03 rack-elevation Blade.

        DrawingExportRendererService::renderPdf wires
        PdfRenderService::fromBlade('pdf.drawings.rack', ['drawing' => $drawing]).
        The embedded SVG was server-built by RackElevationRenderService — every
        <text> content went through htmlspecialchars() before SVG emission, so
        {!! ... !!} here is safe (T-18.03-02 — XSS hygiene at the source).

        Landscape A4 keeps a 42U rack + title block + totals footer on one page.
        Browsershot honours @page { size: A4 landscape; } via emulateMedia('print').

        page-break-inside: avoid keeps the rack + title block atomic
        (PITFALLS MIN-04 — same pattern as schematic.blade.php).
    --}}
    <style>
        /*
         * Phase 20 (CRIT-04) — explicit @font-face so chrome-headless-shell
         * finds the font. Production drops the woff2 binaries into
         * public/fonts/ via the deploy runbook (drawings-queue-runbook.md);
         * if missing, the existing font-family fallback chain (Arial →
         * Helvetica → Liberation Sans → DejaVu Sans → sans-serif) below
         * keeps PDFs valid — no breakage. font-display: block makes
         * Browsershot wait for the font load before snapshotting.
         */
        @font-face {
            font-family: 'Liberation Sans';
            font-style: normal;
            font-weight: 400;
            font-display: block;
            src: url('/fonts/liberation-sans-regular.woff2') format('woff2');
        }
        @font-face {
            font-family: 'Liberation Sans';
            font-style: normal;
            font-weight: 700;
            font-display: block;
            src: url('/fonts/liberation-sans-bold.woff2') format('woff2');
        }
        @font-face {
            font-family: 'DejaVu Sans';
            font-style: normal;
            font-weight: 400;
            font-display: block;
            src: url('/fonts/dejavu-sans-regular.woff2') format('woff2');
        }
        @page {
            size: A4 landscape;
            margin: 12mm;
        }
        body {
            font-family: 'Figtree', sans-serif;
            margin: 0;
            padding: 0;
            color: #111;
        }
        .rack-page {
            display: flex;
            flex-direction: column;
            height: 100vh;
            page-break-inside: avoid;
            break-inside: avoid;
        }
        .rack-svg-wrap {
            flex: 1;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            max-height: 80vh;
            overflow: hidden;
            padding: 4mm 4mm 0 4mm;
        }
        .rack-svg-wrap svg {
            max-width: 100%;
            max-height: 100%;
            height: auto;
            width: auto;
        }
        /*
         * Force SVG text to a guaranteed-system font on the production AlmaLinux
         * server (mirrors schematic.blade.php — same Browsershot font-fallback
         * trap; see CRIT-04 / Phase 17 deployment runbook).
         */
        .rack-svg-wrap svg text,
        .rack-svg-wrap svg tspan {
            font-family: Arial, Helvetica, 'Liberation Sans', 'DejaVu Sans', sans-serif !important;
        }
        .rack-empty {
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
    <div class="rack-page">
        <div class="rack-svg-wrap">
            @if (! empty($drawing->generated_svg))
                {!! $drawing->generated_svg !!}
            @else
                <p class="rack-empty">Rack has not been rendered yet — open the editor to build it.</p>
            @endif
        </div>
        <div class="title-block-wrap">
            @include('pdf.drawings._title-block', ['drawing' => $drawing])
        </div>
    </div>
</body>
</html>
