<?php

/*
|--------------------------------------------------------------------------
| RAMS Theme — Shared Design Tokens
|--------------------------------------------------------------------------
|
| Single source of truth for every hex colour, font name, type size,
| spacing constant and section-render ordering shared by the two RAMS
| renderers (`DocxBuilderService` — PhpWord/DOCX, and
| `resources/views/pdf/rams.blade.php` — DomPDF/HTML).
|
| Read via App\Support\Rams\RamsTheme (typed accessor). Neither renderer
| should read from this file directly — always resolve the RamsTheme
| singleton and call its typed getters so unknown keys fail loudly.
|
| Introduced by phase 260726-rf3-rams-render-unification / plan-01.
| Plans 3+4 refactor the renderers to consume these values; plans 1+2
| are additive (nothing hits the render pipeline yet).
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Palette (6-digit hex, uppercase, no leading '#')
    |--------------------------------------------------------------------------
    |
    | PhpWord expects colours as 6-digit uppercase hex without a '#' prefix
    | (`'2E74B5'`). DomPDF/CSS expects them with a '#' prefix. The typed
    | accessor RamsTheme::palette($key) returns the bare hex — Blade sites
    | that need CSS syntax prepend '#' themselves so both renderers can
    | share one source of truth.
    |
    | Values captured 2026-07-26 from the current DocxBuilderService
    | constants (post-260725-rd1 brand-blue palette shift). Any change
    | here reflows both renderers in Plans 3+4.
    |
    */
    'palette' => [
        'brand_blue'      => '2E74B5',   // H1/H2 headings + accents (was TEAL)
        'brand_blue_dark' => '1F4D78',   // H3 sub-headings
        'brand_blue_tint' => 'DEEBF7',   // Alt-row shading (very light blue)
        'alt_row'         => 'DEEBF7',   // Explicit alias for zebra-row context
        'white'           => 'FFFFFF',
        'dark_text'       => '333333',   // DocxBuilderService DARK_GREY
        'text_muted'      => '666666',   // DocxBuilderService MID_GREY
        'border'          => 'CCCCCC',   // Table border grey (tableStyle)
        'warning_amber'   => 'FFF3CD',   // Risk MED band
        'error_red'       => 'FFDEDE',   // Risk HIGH band (DOCX)
        'risk_green'      => 'D4EDDA',   // Risk LOW band
        'risk_amber'      => 'FFF3CD',   // Risk MED band (alias)
        'risk_orange'     => 'FFD0A0',   // Risk high-mid interim
        'risk_red'        => 'FFDEDE',   // Risk HIGH band (alias)
    ],

    /*
    |--------------------------------------------------------------------------
    | Fonts
    |--------------------------------------------------------------------------
    |
    | body — primary running text font (PhpWord setDefaultFontName +
    |        CSS body font-family). Poppins per 260725-rd1 design brief.
    |        Word substitutes if not installed on the reader's machine;
    |        no font file is bundled.
    | mono — monospace font used for GHS codes, cable refs, ports.
    | fallback — CSS fallback stack appended after body font.
    |
    */
    'fonts' => [
        'body'     => 'Poppins',
        'mono'     => 'Consolas',
        'fallback' => 'Arial, "DejaVu Sans", sans-serif',
    ],

    /*
    |--------------------------------------------------------------------------
    | Sizes (points)
    |--------------------------------------------------------------------------
    |
    | Point sizes for both renderers. PhpWord accepts integers; CSS accepts
    | decimals — callers convert as needed. Values captured from the
    | current blade CSS and DocxBuilderService font() default sizes.
    |
    */
    'sizes' => [
        'h1'       => 22,   // Cover title (blade .cover-title)
        'h2'       => 16,   // Section heading (blade .sec-heading)
        'h3'       => 11,   // Sub-heading (blade .cover-sub / DOCX H3)
        'body'     => 10,   // Default body / PhpWord setDefaultFontSize
        'small'    => 9,    // Compact table body text
        'caption'  => 8,    // Footnotes, muted captions
        'micro'    => 7,    // Risk-cell numeric badges
    ],

    /*
    |--------------------------------------------------------------------------
    | Spacing (twips unless suffixed)
    |--------------------------------------------------------------------------
    |
    | Twips = 1/1440 inch = 1/567 cm. PhpWord uses twips throughout; the
    | blade renders in pt/px so spacing values under `_pt` are exposed
    | separately for CSS callers.
    |
    | Page margins reflect current DocxBuilderService M_PORT / M_LAND
    | (portrait 1.8cm, landscape 1.5cm). Cell paddings mirror tableStyle().
    |
    */
    'spacing' => [
        'section_break_twips'      => 240,   // ~12pt spacing between sections
        'table_row_min_height'     => 300,   // twips (DocxBuilderService default)
        'cover_cell_padding_twips' => 80,    // matches tableStyle cellMarginLeft/Right
        'cell_margin_top_twips'    => 60,
        'cell_margin_bottom_twips' => 60,
        'page_margin_portrait'     => 1020,  // twips ≈ 1.8cm
        'page_margin_landscape'    => 850,   // twips ≈ 1.5cm
        'header_height_twips'      => 500,
        'footer_height_twips'      => 400,
        'table_border_size'        => 4,     // eighths of a point (PhpWord borderSize)
    ],

    /*
    |--------------------------------------------------------------------------
    | Canonical section render order
    |--------------------------------------------------------------------------
    |
    | Slug list matching the 16 section DTOs under app/Support/Rams/Sections/.
    | RamsDocumentComposer (Plan 02) walks this list to populate the DTO
    | tree; both renderers (Plan 03 PDF, Plan 04 DOCX) walk it to render
    | sections in this exact order. Adding a new section = add slug here +
    | add DTO class + wire composer + wire renderers.
    |
    | NOTE: `standards_table` sits between health_safety and scope per the
    | current blade Section 3 render position (Standards & Guidance table
    | is presented immediately after the Health & Safety Policy statement).
    |
    */
    'section_order' => [
        'cover',
        'doc_control',
        'company_info',
        'health_safety',
        'standards_table',
        'scope',
        'room_overviews',
        'exclusions',
        'risk_assessment',
        'method_statement',
        'emergency',
        'coshh',
        'environmental',
        'welfare',
        'signoff',
        'appendix_toolbox',
    ],

];
