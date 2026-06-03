<?php

namespace App\Services;

/**
 * Parses raw text extracted from a QuoteWerks PDF into a structured data array.
 *
 * Parsing is fully local PHP — no AI, no external services.
 * Designed specifically for QuoteWerks-style PDF output.
 *
 * Output shape (compatible with RamsBuilderService::buildFromQuote):
 * [
 *   'client'       => string,
 *   'site'         => string,
 *   'ref'          => string,
 *   'equipment'    => [['qty' => int, 'description' => string, 'location' => string], ...],
 *   'tasks'        => [string, ...],
 *   'rooms'        => [string, ...],
 *   'project_name' => string,   // always '' from parser; populated by form data later
 *   'works_summary'=> string,   // always '' from parser; populated by form data later
 *   'confidence'   => float,    // 0.0–1.0
 * ]
 *
 * Confidence scoring:
 *   No equipment → 0.0 (forces awaiting_review regardless of other fields).
 *   Equipment present → base 0.3, then:
 *     +0.2  project ref detected (non-default value — treated as project identifier)
 *     +0.2  client name extracted
 *     +0.2  site address extracted
 *     +0.1  three or more distinct equipment items found
 *   Capped at 1.0.
 *
 * Defensive design: all methods return empty strings / empty arrays rather than
 * throwing when content cannot be reliably identified.
 */
class QuoteParserService
{
    // ── QuoteWerks field label patterns ──────────────────────────────────────

    private const CLIENT_PATTERNS = [
        '/(?:bill\s+to|sold\s+to|customer|client|company)\s*[:\-]\s*(.+)/i',
        '/(?:attention|attn)\s*[:\-]\s*(.+)/i',
    ];

    private const SITE_PATTERNS = [
        '/(?:ship\s+to|deliver(?:y)?\s+(?:to|address)|site\s+address|installation\s+address)\s*[:\-]\s*(.+)/i',
        '/(?:location)\s*[:\-]\s*(.+)/i',
    ];

    private const REF_PATTERNS = [
        // Priority 1: labelled patterns — capture whatever follows the label.
        // NOTE: "reference" must appear before "ref" in the alternation so that
        // "quote reference" is consumed in full and does not match "quote ref"
        // and then capture "erence" from the remainder of the word.
        '/(?:quote\s+(?:no|number|reference|ref)|q(?:uote)?\s*[#\-]?\s*no\.?)\s*[:\-]?\s*([A-Z0-9\-\/]{3,30})/i',
        '/(?:order\s+(?:no|number)|po\s+(?:no|number))\s*[:\-]?\s*([A-Z0-9\-\/]{3,30})/i',
        // Priority 2: bare 21CQ reference anywhere in the text.
        // All 21st Century AV quote numbers begin "21CQ" followed by digits and optional
        // hyphen-separated revision / type suffixes (e.g. 21CQ28849-04-OPS).
        '/\b(21CQ[0-9]{2,15}(?:-[A-Z0-9]{1,10})*)\b/i',
    ];

    // ── Installation task verb patterns ──────────────────────────────────────

    private const TASK_VERBS = [
        'supply and install', 'supply & install',
        'install', 'mount', 'fix', 'hang', 'route', 'run', 'pull', 'terminate',
        'connect', 'wire', 'commission', 'configure', 'program', 'test',
        'drill', 'core', 'patch', 'rack', 'assemble', 'set up', 'setup',
        'fit', 'attach', 'secure',
    ];

    // ── AV equipment category keywords ───────────────────────────────────────

    private const EQUIPMENT_KEYWORDS = [
        'display', 'monitor', 'screen', 'projector', 'camera', 'ptz',
        'microphone', 'mic', 'speaker', 'amplifier', 'dsp', 'rack', 'mount',
        'bracket', 'switch', 'extender', 'hdbaset', 'matrix', 'controller',
        'panel', 'cabling', 'hdmi', 'cat6', 'cat5', 'fibre',
        'codec', 'conferencing', 'solstice', 'clickshare', 'mersive',
        'rally bar', 'tap', 'biamp', 'tesira', 'qsc', 'shure', 'sennheiser',
        'logitech', 'yealink', 'poly', 'cisco', 'crestron', 'extron',
        'sony', 'samsung', 'nec', 'chief', 'peerless', 'vogels',
        'blustream', 'atlona', 'kramer', 'avocor', 'clevertouch', 'newline',
        // 'tv', 'cable', 'lg' intentionally omitted — too likely to match noise
    ];

    // ── Room / area keywords (more-specific multi-word phrases first) ─────────

    private const ROOM_KEYWORDS = [
        'boardroom', 'meeting room', 'conference room', 'training room',
        'lecture theatre', 'lecture room', 'break-out room', 'breakout room',
        'server room', 'control room',
        'classroom', 'reception', 'canteen', 'auditorium', 'breakout',
        'studio', 'lobby', 'suite', 'hall', 'office', 'floor', 'room',
    ];

    // ── Generic email provider domains (rejected for client fallback) ─────────

    private const PREPARED_BY_PATTERNS = [
        // "Prepared by: Jordan Phillips" / "Prepared By Jordan Phillips"
        // [\s\r\n]* allows OCR line-breaks between label and name
        '/(?:prepared\s+by|\bauthor\b|consultant)\s*[:\-]?\s*[\r\n]*\s*([A-Za-z][a-zA-Z\'\-]+(?:\s+[A-Za-z][a-zA-Z\'\-]+){0,3})/i',
        // "Sales Person: Jordan Phillips" / "Sales Rep: ..."
        '/(?:sales\s+(?:person|rep(?:resentative)?|exec(?:utive)?))\s*[:\-]\s*[\r\n]*\s*([A-Za-z][a-zA-Z\'\-]+(?:\s+[A-Za-z][a-zA-Z\'\-]+){0,3})/i',
        // "Account Manager: Jordan Phillips"
        '/(?:account\s+manager|account\s+exec(?:utive)?)\s*[:\-]\s*[\r\n]*\s*([A-Za-z][a-zA-Z\'\-]+(?:\s+[A-Za-z][a-zA-Z\'\-]+){0,3})/i',
        // "Contact: Jordan Phillips" as last-resort fallback for prepared-by
        '/(?:your\s+(?:contact|account\s+manager)|contact\s+name)\s*[:\-]\s*[\r\n]*\s*([A-Za-z][a-zA-Z\'\-]+(?:\s+[A-Za-z][a-zA-Z\'\-]+){0,3})/i',
    ];

    private const GENERIC_EMAIL_DOMAINS = [
        'gmail', 'yahoo', 'outlook', 'hotmail', 'icloud',
        'msn', 'live', 'aol', 'googlemail',
    ];

    // ── Subdomain prefixes to skip when deriving company from email ───────────

    private const EMAIL_SUBDOMAIN_PREFIXES = [
        'mail', 'www', 'info', 'support', 'noreply',
        'help', 'contact', 'smtp', 'webmail',
    ];

    // ── Sentinel used to preserve horizontal rule lines through toLines() ─────

    /**
     * QuoteWerks PDFs draw a "red line" separator between the Overview narrative
     * and the pricing table; pdftotext renders it as dashes/underscores/equals.
     * Those lines contain no alpha characters and would normally be dropped by
     * the alpha filter — so we convert them to this sentinel before filtering.
     */
    private const HR_SENTINEL = '__HR_SEPARATOR__';

    // =========================================================================
    // PUBLIC ENTRY POINT
    // =========================================================================

    /**
     * Parse raw extracted PDF text into a structured quote data array.
     */
    public function parse(string $rawText): array
    {
        // PDF text extractors (smalot/pdfparser, pdftotext) occasionally garble
        // the QuoteWerks marker tokens by substituting "V" with "y" — e.g.
        // OVERVIEWTXTEND is extracted as "oyERVIEWTXTEND". Normalise the known
        // garbled forms back to canonical uppercase BEFORE any downstream regex
        // runs, so every strip/match site sees consistent input.
        $rawText = $this->normaliseQuoteWerksMarkers($rawText);

        // Structured RAMS PDF tags are present — use the reliable tag-based parser.
        // Falls back to the heuristic path below for untagged legacy PDFs.
        if ($this->hasStructuredTags($rawText)) {
            return $this->parseTagBased($rawText);
        }

        $lines = $this->toLines($rawText);

        // Merge two-line part-number + description rows into a single line BEFORE
        // any further processing. This handles QuoteWerks PDFs where pdftotext
        // splits a pricing row across two lines:
        //   Line N:   MVC860
        //   Line N+1: Yealink MVC860 Video Conferencing Kit
        // After merging both detectOverviewLineRange() and extractEquipment()
        // see the combined "MVC860 Yealink MVC860 Video Conferencing Kit" and
        // process it exactly like a normal single-line pricing row.
        $lines = $this->mergePartNumberLines($lines);

        // Detect the Overview section FIRST so its lines are excluded from
        // equipment extraction and its text is stored separately.
        $overviewRange = $this->detectOverviewLineRange($lines);
        $overview      = $this->extractOverviewText($lines, $overviewRange);

        $client     = $this->extractClient($rawText, $lines);
        $site       = $this->extractSite($rawText, $lines);
        $ref        = $this->extractRef($rawText);
        $equipment  = $this->extractEquipment($lines, $overviewRange);
        $preparedBy = $this->extractPreparedBy($rawText);

        return [
            'client'        => $client,
            'site_name'     => '',
            'site'          => $site,
            'ref'           => $ref,
            'overview'      => $overview,
            'equipment'     => $equipment,
            'prepared_by'   => $preparedBy,
            'tasks'         => $this->extractTasks($lines),
            'rooms'         => $this->extractRooms($lines),
            'project_name'  => '',
            'works_summary' => '',
            'confidence'    => $this->calculateConfidence($client, $site, $ref, $equipment),
        ];
    }

    // =========================================================================
    // PRIVATE — EXTRACTION
    // =========================================================================

    private function extractClient(string $text, array $lines): string
    {
        // ── Priority 1: labelled patterns (Client:, Sold To:, Attn:, etc.) ──
        foreach (self::CLIENT_PATTERNS as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $candidate = $this->cleanValue($m[1]);
                if ($this->isPlausibleName($candidate)) {
                    return $this->normalise($candidate, 80);
                }
            }
        }

        // ── Priority 2: company name in first 20 lines (suffix heuristic) ──
        // QuoteWerks typically puts the company name in the first ~20 lines.
        foreach (array_slice($lines, 0, 20) as $line) {
            if (preg_match('/\b(ltd|limited|plc|llp|llc|inc|corp|council|university|college|school|hospital|nhs|group)\b/i', $line)) {
                $candidate = $this->cleanValue($line);
                if (strlen($candidate) > 3 && strlen($candidate) < 100) {
                    return $this->normalise($candidate, 80);
                }
            }
        }

        // ── Priority 3: contact name fallback ─────────────────────────────
        // Detects "Contact:", "Attn:", "For the attention of" when no labelled
        // client or company suffix was found.
        $contact = $this->extractContactName($text);
        if ($contact !== null) {
            return $this->normalise($contact, 80);
        }

        // ── Priority 4: email domain fallback ─────────────────────────────
        // Derives a readable company name from the domain part of any email
        // address present in the text; rejects generic providers.
        $domainCompany = $this->extractEmailDomainCompany($text);
        if ($domainCompany !== null) {
            return $this->normalise($domainCompany, 80);
        }

        return '';
    }

    private function extractSite(string $text, array $lines): string
    {
        // ── Priority 1: labelled patterns ────────────────────────────────────
        foreach (self::SITE_PATTERNS as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $candidate = $this->cleanValue($m[1]);

                // Require alphabetic content and minimum length.
                if (strlen($candidate) <= 3 || ! preg_match('/[a-zA-Z]{2,}/', $candidate)) {
                    continue;
                }

                // Reject if the captured value looks like a personal name rather
                // than an address. QuoteWerks "Ship To:" blocks often put the
                // contact name on the first line (e.g. "Ship To: Kane Rason")
                // before the actual address lines. A personal name has:
                //   - Only 2–3 title-case words
                //   - No digits (addresses always have a number or postcode)
                //   - Total length < 35 chars
                if (
                    strlen($candidate) < 35
                    && ! preg_match('/\d/', $candidate)
                    && preg_match('/^[A-Z][a-z]+(?:\s+[A-Z][a-z\'-]+){1,2}$/', $candidate)
                ) {
                    continue;
                }

                return $this->normalise($candidate, 150);
            }
        }

        // ── Priority 2: UK address block detection ────────────────────────────
        // Locates a valid UK postcode and returns the surrounding address lines,
        // stripped of noise (Tel, Email, VAT, document labels).
        $block = $this->extractUkAddressBlock($text);
        if ($block !== null) {
            return $this->normalise($block, 150);
        }

        return '';
    }

    private function extractRef(string $text): string
    {
        foreach (self::REF_PATTERNS as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $ref = trim($m[1]);

                // Sanity-check: ref must be alphanumeric, 3–30 characters
                if (preg_match('/^[A-Z0-9\-\/]{3,30}$/i', $ref)) {
                    return $ref;
                }
            }
        }

        return 'RAMS-001';
    }

    /**
     * Detect the line-index range [startIdx, endIdx] belonging to the Overview
     * section. Returns null when no Overview heading is found.
     *
     * NOTE: this method receives the already-merged $lines array (after
     * mergePartNumberLines()), so two-line part-number + description rows
     * appear as a single merged line and are correctly identified by end-
     * markers 4 and 5 without any additional logic here.
     *
     * The returned array has 2 or 3 elements:
     *   [startIdx, endIdx]               — heading had no inline text
     *   [startIdx, endIdx, inlineText]   — text was on the same line as the heading
     *
     * Recognised heading variants (case-insensitive):
     *   "Overview"
     *   "Overview:"
     *   "Overview: Some text on same line…"
     *   "Overview / Scope Description"
     *   "Scope of Works" / "Scope of Work"
     *   "1. Overview", "Section 1: Overview", etc.
     *
     * End (first match wins):
     *   1. Horizontal rule sentinel (HR_SENTINEL) — the "red line" in QuoteWerks.
     *   2. Pricing table column-header row containing Qty + Description/Part.
     *   3. Standalone table column header on its own line (Qty, Part No, etc.).
     *   4. First pricing row: part number + text + trailing price.
     *   5. First pricing row without price (part number + description, ≥5 lines
     *      past start so contact-line false positives are avoided).
     */
    private function detectOverviewLineRange(array $lines): ?array
    {
        $startIdx   = null;
        $inlineText = '';

        // ── Heading patterns ────────────────────────────────────────────────
        // Core: "overview", "overview / scope description", "scope of works/work"
        $headingCore =
            'overview(?:\s*[\/|\\\\]\s*scope(?:\s+(?:of\s+)?(?:works?|description))?)?'
            . '|scope\s+of\s+works?';

        // Optional numeric/section prefix before the heading word(s)
        $headingFull = '(?:\d+[\.\)]\s*)?(?:section\s+\d+\.?\s*[-:]?\s*)?(?:' . $headingCore . ')';

        // Bare heading (nothing after, optional trailing colon/dash)
        $barePattern   = '/^' . $headingFull . '\s*[:\-]?\s*$/i';

        // Inline heading (colon or dash separates heading from following text)
        $inlinePattern = '/^' . $headingFull . '\s*[:\-]\s*(.+)$/i';

        foreach ($lines as $i => $line) {
            $trimmed = trim($line);

            // ── Detect Overview heading ───────────────────────────────────────
            if ($startIdx === null) {
                if (preg_match($barePattern, $trimmed)) {
                    // Pure heading line — overview body starts on the next line.
                    $startIdx   = $i + 1;
                    $inlineText = '';
                    continue;
                }

                if (preg_match($inlinePattern, $trimmed, $hm)) {
                    // Heading + inline text on the same line (e.g. "Overview: text…").
                    // Capture the text that follows the heading colon/dash.
                    $startIdx   = $i + 1;
                    $inlineText = trim(end($hm));
                    continue;
                }

                // Relaxed fallback: a short line (≤ 80 chars) that contains the
                // standalone word "overview" but does not end with a sentence
                // terminator. Catches headings that pdftotext emits without a
                // trailing colon/dash (e.g. just "Overview" or "Project Overview").
                if (
                    strlen($trimmed) <= 80
                    && preg_match('/\boverview\b/i', $trimmed)
                    && ! preg_match('/[.!?]\s*$/', $trimmed)
                ) {
                    $startIdx   = $i + 1;
                    $inlineText = '';
                    continue;
                }
            }

            // ── Detect end of Overview ────────────────────────────────────────
            if ($startIdx !== null) {

                // 1. Horizontal rule — the "red line" separator QuoteWerks draws
                //    between the Overview narrative and the pricing table.
                //    toLines() converts raw dash/underscore/equals lines to
                //    HR_SENTINEL so they survive the alpha filter and can be
                //    recognised here as an explicit section boundary.
                if ($trimmed === self::HR_SENTINEL) {
                    return $this->buildRange($startIdx, $i, $inlineText);
                }

                // 2. Pricing table column header (Qty + Description/Part on same line)
                if (preg_match(
                    '/\b(?:qty\.?|quantity)\b.{0,60}\b(?:description|unit\s+price|part\s+(?:no\.?|number|code)?|item)\b/i',
                    $trimmed,
                )) {
                    return $this->buildRange($startIdx, $i, $inlineText);
                }

                // 3. Standalone table column headers (one per line in some PDF extracts)
                if (preg_match(
                    '/^(?:qty\.?|quantity|part\s*(?:no\.?|number|code)?|description|unit\s+price|line\s+total)\s*$/i',
                    $trimmed,
                )) {
                    return $this->buildRange($startIdx, $i, $inlineText);
                }

                // 4. First pricing row: part number + description + trailing price.
                //    Accepts both hyphenated (YEA-MIC-S) and digit-containing (MVC860)
                //    part numbers, including mixed-case (YealinkVC840).
                //    Also fires on two-line rows that were pre-merged by
                //    mergePartNumberLines() into a single "PARTNUM Desc" line.
                if (preg_match('/^[A-Za-z0-9][A-Za-z0-9\-\.]{3,29}\s+\S.+\s[\d,]+\.\d{2}/', $trimmed)) {
                    return $this->buildRange($startIdx, $i, $inlineText);
                }

                // 5. First pricing row without inline price — hyphenated part number
                //    followed by a description starting upper-case.
                //    Only trigger after we are ≥5 lines past the heading to avoid
                //    matching header contact lines (e.g. "Tel: 01234-5678").
                if ($i >= $startIdx + 5) {
                    if (preg_match('/^[A-Za-z]{2,}[A-Za-z0-9]{0,}\-[A-Za-z0-9\-\.]{2,28}\s+[A-Z][a-z].{3,}$/', $trimmed)) {
                        return $this->buildRange($startIdx, $i, $inlineText);
                    }
                }

                // 6. Sub-section / option heading inside the overview narrative
                //    (e.g. "Display Options", "Audio Options", "Video Conferencing Options",
                //    bare "Options"). These signal the transition from prose to an
                //    equipment-option list — the overview narrative ends here.
                //    Cap at 60 chars to avoid matching long descriptive sentences
                //    that happen to end with the word "options".
                if (strlen($trimmed) <= 60 && preg_match('/\boptions?\s*$/i', $trimmed)) {
                    return $this->buildRange($startIdx, $i, $inlineText);
                }
            }
        }

        if ($startIdx !== null) {
            return $this->buildRange($startIdx, count($lines), $inlineText);
        }

        // ── Boundary-scan fallback ────────────────────────────────────────────
        // No explicit Overview heading was detected.  Scan forward for the first
        // "table boundary" marker and treat everything before it (capped at 35
        // lines) as the Overview section.  Boundary types checked in order:
        //   a) HR sentinel (red rule QuoteWerks draws above the pricing table)
        //   b) "Options" sub-heading (e.g. "Display Options", "Audio Options")
        //   c) Pricing table column-header row  (Qty / Description / Part)
        //   d) First pricing row with a trailing price figure
        //   e) First bare part-number prefix row (PARTNUM + Description)
        $boundary = null;
        foreach ($lines as $i => $line) {
            $t = trim($line);

            // a) HR sentinel
            if ($t === self::HR_SENTINEL) {
                $boundary = $i;
                break;
            }

            // b) "Options" sub-heading — stop BEFORE this line so it is never
            //    included in the overview text.
            if (strlen($t) <= 60 && preg_match('/\boptions?\s*$/i', $t)) {
                $boundary = $i;
                break;
            }

            // c) Pricing table column header (combined or single-column form)
            if (preg_match(
                '/\b(?:qty\.?|quantity)\b.{0,60}\b(?:description|unit\s+price|part\s+(?:no\.?|number|code)?|item)\b/i',
                $t,
            )) {
                $boundary = $i;
                break;
            }
            if (preg_match(
                '/^(?:qty\.?|quantity|part\s*(?:no\.?|number|code)?|description|unit\s+price|line\s+total)\s*$/i',
                $t,
            )) {
                $boundary = $i;
                break;
            }

            // d) First pricing row with trailing price
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9\-\.]{3,29}\s+\S.+\s[\d,]+\.\d{2}/', $t)) {
                $boundary = $i;
                break;
            }

            // e) Part-number + description row (no price required)
            //    Only checked after at least 5 lines to avoid firing on header
            //    contact/telephone lines (e.g. "Tel: 01234-567890").
            if ($i >= 5 && preg_match(
                '/^[A-Za-z0-9][A-Za-z0-9\-\.]{3,29}\s+[A-Za-z].{3,}$/',
                $t,
            )) {
                $pn = strtok($t, ' ');
                if (
                    $pn !== false
                    && (str_contains($pn, '-') || (preg_match('/\d/', $pn) && preg_match('/[a-zA-Z]{2,}/', $pn)))
                ) {
                    $boundary = $i;
                    break;
                }
            }
        }

        if ($boundary !== null && $boundary > 0) {
            return $this->buildRange(0, min($boundary, 35), '');
        }

        return null;
    }

    /**
     * Build the range array returned by detectOverviewLineRange().
     * Appends inline text as a third element only when present.
     */
    private function buildRange(int $start, int $end, string $inlineText): array
    {
        $range = [$start, $end];
        if ($inlineText !== '') {
            $range[] = $inlineText;
        }
        return $range;
    }

    /**
     * Return the raw text of the Overview section, or '' if none was detected.
     *
     * The range array may contain an optional third element — text that was
     * on the same line as the heading (e.g. "Overview: <inline text>").
     * That inline text is prepended to the body lines.
     */
    private function extractOverviewText(array $lines, ?array $range): string
    {
        if ($range === null) {
            return '';
        }

        [$start, $end] = $range;
        $inlineText = $range[2] ?? '';

        $block = array_slice($lines, $start, $end - $start);

        // Keep only meaningful lines (length ≥ 5); exclude the HR sentinel and
        // "Options" sub-section headings (e.g. "Display Options") that signal
        // the transition to an equipment-choice list rather than prose overview.
        $block = array_values(array_filter(
            $block,
            fn (string $l) => $l !== self::HR_SENTINEL
                && strlen(trim($l)) >= 5
                && ! (strlen(trim($l)) <= 60 && preg_match('/\boptions?\s*$/i', $l)),
        ));

        // Prepend any text that appeared on the same line as the heading
        if ($inlineText !== '' && strlen(trim($inlineText)) >= 5) {
            array_unshift($block, $inlineText);
        }

        return trim(implode("\n", $block));
    }

    private function extractEquipment(array $lines, ?array $overviewRange = null): array
    {
        $equipment = [];
        $seen      = [];

        // Lines within the Overview section must NEVER be treated as equipment.
        $overStart = $overviewRange[0] ?? PHP_INT_MAX;
        $overEnd   = $overviewRange[1] ?? PHP_INT_MAX;

        // Section-level optional tracking.
        // QuoteWerks PDFs group optional add-ons under sub-headings like
        // "Display Options" or "Audio Options".  Every line that follows such
        // a heading — until the next HR sentinel (which marks a real pricing
        // table boundary) — is treated as optional and excluded from equipment.
        $inOptionsSection = false;

        foreach ($lines as $i => $line) {
            // ── Skip sentinel lines injected by toLines() ─────────────────────
            if ($line === self::HR_SENTINEL) {
                // HR always closes an optional section — the pricing table that
                // follows contains real (non-optional) equipment rows.
                $inOptionsSection = false;
                continue;
            }

            // ── Skip Overview section lines ───────────────────────────────────
            if ($i >= $overStart && $i < $overEnd) {
                continue;
            }

            // ── Detect / track "Options" sub-section headings ─────────────────
            // A short line (≤ 60 chars) whose last word is "option" or "options"
            // opens an optional block.  The heading itself is never equipment.
            if (strlen(trim($line)) <= 60 && preg_match('/\boptions?\s*$/i', $line)) {
                $inOptionsSection = true;
                continue;
            }

            // ── Skip all lines while inside an optional section ───────────────
            if ($inOptionsSection) {
                continue;
            }

            $lower = strtolower($line);

            // ── Gate 1: must contain a recognised AV equipment keyword ─────────
            $isEquipment = false;
            foreach (self::EQUIPMENT_KEYWORDS as $kw) {
                if (str_contains($lower, $kw)) {
                    $isEquipment = true;
                    break;
                }
            }

            if (! $isEquipment) {
                // ── Gate 1b: part-number bypass ───────────────────────────────
                // Merged or single-line rows of the form "PARTNUM Description"
                // may lack an equipment keyword if the product name is not in
                // the keyword list (e.g. "CS10 Yealink CS10 USB Hub").
                // A valid part-number prefix is sufficient evidence that this
                // is a real equipment row — accept it even without a keyword.
                $tmpDesc = trim(preg_replace('/\s{2,}/', ' ', $line));
                // Strip leading qty prefix if present
                if (preg_match('/^(\d{1,3})\s*[xX×]?\s+(.+)/', $tmpDesc, $tm)
                    && (int) $tm[1] >= 1 && (int) $tm[1] <= 500) {
                    $tmpDesc = trim($tm[2]);
                }
                // Strip trailing price
                $tmpDesc = trim(preg_replace('/\s+[\d,]+\.\d{2}\s*$/', '', $tmpDesc));

                if (preg_match('/^([A-Za-z0-9][A-Za-z0-9\-\.]{3,29})\s+(.{4,})$/', $tmpDesc, $pm)) {
                    $pn = $pm[1];
                    $pr = trim($pm[2]);
                    $hasH = str_contains($pn, '-');
                    $hasD = (bool) preg_match('/\d/', $pn);
                    $hasA = (bool) preg_match('/[a-zA-Z]{2,}/', $pn);

                    if (
                        ($hasH || ($hasD && $hasA))
                        && preg_match('/[a-zA-Z]{2,}/', $pr)
                        && ! preg_match('/^\d+[\.,]\d{2}/', $pr)
                    ) {
                        $isEquipment = true;
                    }
                }
            }

            if (! $isEquipment) {
                continue;
            }

            // ── Gate 2: skip known noise patterns ────────────────────────────
            if ($this->isNoise($lower)) {
                continue;
            }

            // ── Qty + description extraction ──────────────────────────────────
            // Pattern A: "2x Sony Display", "2 x Sony Display", "2 Sony Display"
            // Limit leading digit capture to 1–3 digits (qty 1–999 maximum) to
            // prevent large PDF object numbers from being misread as quantities.
            $qty  = 1;
            $desc = $line;

            if (preg_match('/^(\d{1,3})\s*[xX×]?\s+(.+)/', $line, $m)) {
                $candidateQty  = (int) $m[1];
                $candidateDesc = trim($m[2]);

                if ($candidateQty >= 1 && $candidateQty <= 500) {
                    $qty  = $candidateQty;
                    $desc = $candidateDesc;
                }
            } elseif (preg_match('/^[xX×]\s*(\d{1,3})\s+(.+)/', $line, $m)) {
                $candidateQty  = (int) $m[1];
                $candidateDesc = trim($m[2]);

                if ($candidateQty >= 1 && $candidateQty <= 500) {
                    $qty  = $candidateQty;
                    $desc = $candidateDesc;
                }
            }

            // ── Cleanup: strip trailing price / currency fragments ─────────────
            $desc = preg_replace('/\s+[£$€][\d,\.]+\s*$/u', '', $desc);
            $desc = preg_replace('/\s+[\d,]+\.\d{2}\s*$/', '', $desc);
            $desc = trim($desc);

            // ── Collapse multi-space runs (OCR tabular alignment artefacts) ────
            $desc = preg_replace('/\s{2,}/', ' ', $desc);
            $desc = trim($desc);

            // ── Part number detection ─────────────────────────────────────────
            // QuoteWerks pricing rows: "PART-NUM Description" or "MVC860 Description"
            //
            // This also handles two-line rows that were pre-merged by
            // mergePartNumberLines() — after merging they arrive here as a
            // normal "PARTNUM Description" single line.
            //
            // Accepted formats:
            //   Traditional:  YEA-MIC-S, QSC-TSC-7T  (contains hyphen)
            //   Alphanumeric: MVC860, CM20, CS10       (has digit AND 2+ alpha chars)
            //   Mixed-case:   YealinkVC840, QscK8      (widened character class)
            //
            // Guards:
            //   - Remainder after part number must contain real text (2+ alpha chars)
            //   - Remainder must not start with a price (digits + decimal)
            //   - Pure alphabetic tokens without hyphens (HDMI, WIFI, etc.) are
            //     NOT treated as part numbers — they lack both a hyphen and a digit
            //   - Placeholder strings like "E.G. YEA-MIC-S" are never set as
            //     part numbers (detected via presence of "e.g." anywhere in $desc)

            $partNumber = '';

            // Global e.g. placeholder guard — if "e.g." (or "e.g") appears
            // anywhere in the current description, this line is example/template
            // text and no part number should be extracted from it.
            $hasEgInDesc = (bool) preg_match('/\be\.?\s*g\.?\b/i', $desc);

            if (! $hasEgInDesc) {
                // Strategy 1 — Prefix: "PARTNUM Description text"
                if (preg_match('/^([A-Za-z0-9][A-Za-z0-9\-\.]{3,29})\s+(.{4,})$/', $desc, $pm)) {
                    $partNum  = $pm[1];
                    $partDesc = trim($pm[2]);

                    $hasHyphen     = str_contains($partNum, '-');
                    $hasDigit      = (bool) preg_match('/\d/', $partNum);
                    $hasAlphaChars = (bool) preg_match('/[a-zA-Z]{2,}/', $partNum);

                    if (
                        ($hasHyphen || ($hasDigit && $hasAlphaChars))
                        && preg_match('/[a-zA-Z]{2,}/', $partDesc)
                        && ! preg_match('/^\d+[\.,]\d{2}/', $partDesc)
                    ) {
                        $partNumber = $partNum;
                        $desc       = $partDesc;
                    }
                }

                // Strategy 2 — Trailing parenthetical: "Description text (PARTNUM)"
                // e.g. "Yealink Ceiling Mic's (CM20)" → part_number=CM20
                if ($partNumber === '') {
                    if (preg_match('/^(.{3,}?)\s*\(([A-Za-z0-9][A-Za-z0-9\-\.]{2,29})\)\s*$/', $desc, $pm)) {
                        $candidate     = $pm[2];
                        $remainingDesc = trim($pm[1]);

                        $hasHyphen = str_contains($candidate, '-');
                        $hasDigit  = (bool) preg_match('/\d/', $candidate);
                        $hasAlpha  = (bool) preg_match('/[a-zA-Z]{2,}/', $candidate);

                        if (
                            ($hasHyphen || ($hasDigit && $hasAlpha))
                            && strlen($remainingDesc) >= 3
                            && preg_match('/[a-zA-Z]{2,}/', $remainingDesc)
                        ) {
                            $partNumber = $candidate;
                            $desc       = $remainingDesc;
                        }
                    }
                }

                // Strategy 3 — Trailing token: "Description text PARTNUM"
                // e.g. "Yealink Ceiling Mic CM20" → part_number=CM20
                // Only fires when strategies 1 & 2 produced nothing.
                if ($partNumber === '') {
                    if (preg_match('/^(.{5,})\s+([A-Za-z0-9][A-Za-z0-9\-\.]{2,29})$/', $desc, $pm)) {
                        $candidate     = $pm[2];
                        $remainingDesc = trim($pm[1]);

                        $hasHyphen = str_contains($candidate, '-');
                        $hasDigit  = (bool) preg_match('/\d/', $candidate);
                        $hasAlpha  = (bool) preg_match('/[a-zA-Z]{2,}/', $candidate);

                        if (
                            ($hasHyphen || ($hasDigit && $hasAlpha))
                            && strlen($remainingDesc) >= 4
                            && preg_match('/[a-zA-Z]{2,}/', $remainingDesc)
                        ) {
                            $partNumber = $candidate;
                            $desc       = $remainingDesc;
                        }
                    }
                }
            } // end !$hasEgInDesc

            // ── Gate 3: length guard ──────────────────────────────────────────
            // 150 chars: long enough for detailed product names, short enough to
            // exclude most narrative sentence fragments from the Overview section.
            if (strlen($desc) < 5 || strlen($desc) > 150) {
                continue;
            }

            // ── Gate 4: require at least 2 consecutive alphabetic characters ──
            // Rejects descriptions that are pure codes, PDF object fragments
            // (e.g. "0 R", "17 obj"), or single-letter operator tokens.
            if (! preg_match('/[a-zA-Z]{2,}/', $desc)) {
                continue;
            }

            // ── Dedup ─────────────────────────────────────────────────────────
            $key = strtolower($desc);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $equipment[] = [
                'qty'         => $qty,
                'part_number' => $partNumber,
                'description' => $desc,
                'area'        => '',   // populated by tag-based parser; empty for heuristic path
                'location'    => $this->detectRoom($desc),
            ];
        }

        return $equipment;
    }

    private function extractTasks(array $lines): array
    {
        $tasks = [];
        $seen  = [];

        foreach ($lines as $line) {
            $lower = strtolower(trim($line));

            // More-specific multi-word verbs are listed first in TASK_VERBS
            // so "supply and install" is matched before the bare "install".
            foreach (self::TASK_VERBS as $verb) {
                if (str_starts_with($lower, $verb) || str_contains($lower, ' ' . $verb . ' ')) {
                    $task = $this->cleanValue($line);
                    if (strlen($task) > 80) {
                        $task = substr($task, 0, 77) . '...';
                    }
                    $key = strtolower($task);
                    if (! isset($seen[$key]) && strlen($task) > 5) {
                        $seen[$key] = true;
                        $tasks[]    = $task;
                    }
                    break;
                }
            }
        }

        return array_values(array_slice($tasks, 0, 20));
    }

    private function extractRooms(array $lines): array
    {
        $rooms = [];
        $seen  = [];

        // ── Priority: explicit "ROOMS:" / "ROOM:" label on a single line ────────
        foreach ($lines as $line) {
            if (! preg_match('/^ROOMS?\s*[:\-]\s*(.+)/i', $line, $labelMatch)) {
                continue;
            }
            $raw     = $labelMatch[1];
            $parts   = preg_split('/ *[&,] *| and /i', $raw);
            $results = [];
            $seen    = [];
            foreach ($parts as $part) {
                $name = trim($part);
                if ($name === '') {
                    continue;
                }
                $key = strtolower($name);
                if (! isset($seen[$key])) {
                    $seen[$key] = true;
                    $results[]  = $name;
                }
            }
            if (! empty($results)) {
                return $results; // return early — explicit label wins
            }
        }
        // ── Fallback: keyword scan (existing logic below unchanged) ─────────────

        foreach ($lines as $line) {
            // Skip very long lines — unlikely to yield a clean room name
            if (strlen($line) > 120) {
                continue;
            }

            $lower = strtolower($line);

            foreach (self::ROOM_KEYWORDS as $kw) {
                if (! str_contains($lower, $kw)) {
                    continue;
                }

                // Capture: up to 50 chars before the keyword + up to 20 chars after.
                // preg_quote handles multi-word keywords (e.g. "meeting room") safely.
                // {0,50} (not {2,50}) so the keyword can appear after non-ASCII
                // punctuation like em-dashes that break an alphabetic prefix run.
                $pattern = '/([A-Za-z0-9\s\-\/]{0,50}' . preg_quote($kw, '/') . '[A-Za-z0-9\s\-\/]{0,20})/i';

                if (preg_match($pattern, $line, $m)) {
                    $room = trim($m[1]);

                    // Hard cap: room names longer than 60 chars are likely full
                    // sentence fragments caught by the pattern, not room names.
                    if (strlen($room) > 60) {
                        break;
                    }

                    // Must have at least 2 consecutive alphabetic characters
                    if (! preg_match('/[a-zA-Z]{2,}/', $room)) {
                        break;
                    }

                    $key = strtolower($room);
                    if (! isset($seen[$key])) {
                        $seen[$key] = true;
                        $rooms[]    = $room;
                    }
                }

                break; // stop on first matching keyword per line
            }
        }

        return array_values(array_unique($rooms));
    }

    // =========================================================================
    // PRIVATE — FALLBACK HELPERS
    // =========================================================================

    /**
     * Attempt to extract a personal contact name from patterns such as:
     *   "Contact: John Smith"
     *   "Attn: Sarah O'Brien"
     *   "For the attention of Robert Blackwell"
     *
     * Returns null when no plausible name is found.
     * Rejects ALL-CAPS strings (headings/labels) and strings containing
     * business/noise words (Ltd, Invoice, Quote, Tel, VAT, etc.).
     */
    private function extractContactName(string $text): ?string
    {
        // Use [ \t]+ (horizontal whitespace only) between words so the pattern
        // cannot cross a line boundary and absorb the next line's content.
        $patterns = [
            '/\bcontact\s*[:\-]\s*([A-Za-z][a-zA-Z\'\-]+(?:[ \t]+[A-Za-z][a-zA-Z\'\-]+){1,3})/i',
            '/\battn(?:ention)?\s*[:\-]\s*([A-Za-z][a-zA-Z\'\-]+(?:[ \t]+[A-Za-z][a-zA-Z\'\-]+){1,3})/i',
            '/\bfor\s+the\s+attention\s+of[ \t]+([A-Za-z][a-zA-Z\'\-]+(?:[ \t]+[A-Za-z][a-zA-Z\'\-]+){1,3})/i',
        ];

        foreach ($patterns as $pattern) {
            if (! preg_match($pattern, $text, $m)) {
                continue;
            }

            $name = trim(preg_replace('/\s+/', ' ', $m[1]));

            // Reject ALL-CAPS strings — these are headings or noise, not names.
            if (preg_match('/[A-Z]/', $name) && $name === strtoupper($name)) {
                continue;
            }

            // Reject if the extracted string contains business or noise words.
            if (preg_match('/\b(ltd|limited|plc|invoice|quote|tel|vat|email|inc|corp)\b/i', $name)) {
                continue;
            }

            // A plausible personal name is 2–4 space-separated words.
            $wordCount = count(array_filter(explode(' ', $name), fn ($w) => $w !== ''));
            if ($wordCount < 2 || $wordCount > 4) {
                continue;
            }

            return $name;
        }

        return null;
    }

    /**
     * Extract the name of the person who prepared / authored this quote.
     *
     * QuoteWerks typically includes a "Prepared by:" or "Sales Person:" field
     * in the quote header. Returns an empty string when nothing plausible is found.
     *
     * Validation:
     *   - Must be 2–3 words, title-case or mixed-case.
     *   - Must contain no digits (not an address or product code).
     *   - Must not contain business or noise words (Ltd, Invoice, Quote, etc.).
     */
    private function extractPreparedBy(string $text): string
    {
        foreach (self::PREPARED_BY_PATTERNS as $pattern) {
            if (! preg_match($pattern, $text, $m)) {
                continue;
            }

            $name = trim(preg_replace('/\s+/', ' ', $m[1]));

            // Must not contain digits.
            if (preg_match('/\d/', $name)) {
                continue;
            }

            // Must not contain business / noise words.
            if (preg_match('/\b(ltd|limited|plc|invoice|quote|tel|vat|email|inc|corp|address)\b/i', $name)) {
                continue;
            }

            // Must be 1–4 space-separated words (allow single-word names from QuoteWerks).
            $words = array_filter(explode(' ', $name), fn ($w) => $w !== '');
            if (count($words) < 1 || count($words) > 4) {
                continue;
            }

            // Reject ALL-CAPS strings (headings, not names).
            if ($name === strtoupper($name) && preg_match('/[A-Z]/', $name)) {
                continue;
            }

            return $name;
        }

        // ── Multi-line fallback: label on one line, name on next (RAMS PDF layout) ─
        if (preg_match(
            '/PREPARED\s+BY\s*[:\-]?\s*[\r\n]+\s*([A-Za-z][a-zA-Z\'\-]+(?:\s+[A-Za-z][a-zA-Z\'\-]+){0,3})/i',
            $text,
            $m
        )) {
            $name  = trim(preg_replace('/\s+/', ' ', $m[1]));
            $words = array_filter(explode(' ', $name), fn ($w) => $w !== '');

            if (
                ! preg_match('/\d/', $name)
                && ! preg_match('/\b(ltd|limited|plc|invoice|quote|tel|vat|email|inc|corp|address)\b/i', $name)
                && count($words) >= 1
                && count($words) <= 4
                && ! ($name === strtoupper($name) && preg_match('/[A-Z]/', $name))
            ) {
                return $name;
            }
        }

        return '';
    }

    /**
     * Derive a readable company name from the domain of the first email address
     * found in the text.
     *
     * Algorithm:
     *   1. Extract domain from email (everything after @).
     *   2. Take the leading hostname segment, skipping known subdomain prefixes
     *      (mail, www, support, etc.) when the domain has 3+ parts.
     *   3. Reject generic consumer providers (gmail, yahoo, outlook, etc.).
     *   4. Format: names ≤5 chars → UPPERCASE; longer → ucwords.
     *
     * Returns null when no usable email is found.
     */
    private function extractEmailDomainCompany(string $text): ?string
    {
        if (! preg_match('/[a-zA-Z0-9._%+\-]+@([a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})/i', $text, $m)) {
            return null;
        }

        $fullDomain = strtolower($m[1]);
        $parts      = explode('.', $fullDomain);

        // Skip known subdomain prefixes (e.g. mail.company.com → company).
        $company = $parts[0];
        if (count($parts) > 2 && in_array($company, self::EMAIL_SUBDOMAIN_PREFIXES, true)) {
            $company = $parts[1] ?? $company;
        }

        // Reject generic consumer email providers.
        if (in_array($company, self::GENERIC_EMAIL_DOMAINS, true)) {
            return null;
        }

        // Must be at least 2 characters to be useful.
        if (strlen($company) < 2) {
            return null;
        }

        // Short names (≤5 chars) → uppercase acronym style; longer → title case.
        if (strlen($company) <= 5) {
            return strtoupper($company);
        }

        return ucwords(str_replace(['-', '_', '.'], ' ', $company));
    }

    /**
     * Locate a UK postcode in the text and return the surrounding address block
     * (up to 3 lines before the postcode + the postcode line itself), with
     * noise lines (Tel, Email, VAT, document labels) stripped out.
     *
     * Returns null when:
     *   - No UK postcode is found.
     *   - The resulting address is shorter than 10 characters.
     *   - The block contains no alphabetic content.
     */
    private function extractUkAddressBlock(string $text): ?string
    {
        // Label prefixes that should not be included in an address block.
        $labelPattern =
            '/^(?:tel|phone|fax|email|e-mail|vat|www\.|https?:|registered|'
            . 'client|quote|ref|order|date|prepared|attention|attn|contact|'
            . 'from|to|sold|bill|ship|deliver)\s*[:\-]/i';

        $rawLines = preg_split('/\r?\n/', $text);
        $lines    = array_values(array_map('trim', array_filter($rawLines, fn ($l) => trim($l) !== '')));

        foreach ($lines as $i => $line) {
            // Require a valid UK postcode on this line.
            if (! preg_match('/\b[A-Z]{1,2}\d{1,2}[A-Z]?\s*\d[A-Z]{2}\b/i', $line)) {
                continue;
            }

            // Gather up to 3 lines before + the postcode line itself (4 total).
            $start = max(0, $i - 3);
            $block = array_slice($lines, $start, $i - $start + 1);

            // Strip noise lines (label prefixes, tel, email, VAT, etc.)
            // and purely numeric lines (they are not valid address components).
            $block = array_values(array_filter(
                $block,
                fn (string $l): bool =>
                    ! preg_match($labelPattern, $l)
                    && strlen(trim($l)) >= 2
                    && (bool) preg_match('/[a-zA-Z]/', $l),
            ));

            if (empty($block)) {
                continue;
            }

            $address = implode(', ', array_map('trim', $block));
            $address = trim(preg_replace('/\s+/', ' ', $address));

            // Reject too-short or non-alphabetic results.
            if (strlen($address) < 10 || ! preg_match('/[a-zA-Z]{2,}/', $address)) {
                continue;
            }

            return $address;
        }

        return null;
    }

    // =========================================================================
    // PRIVATE — HELPERS
    // =========================================================================

    /**
     * Normalise a string value: collapse whitespace, trim, enforce max length.
     */
    private function normalise(string $value, int $maxLen): string
    {
        $value = preg_replace('/\s+/', ' ', $value);
        $value = trim($value);
        return substr($value, 0, $maxLen);
    }

    /**
     * Calculate a confidence score (0.0–1.0) indicating how well the parser
     * was able to extract structured data from the raw text.
     *
     * Equipment is the load-bearing field for RAMS generation — without it,
     * the classifier, risk resolver, and method statement generator all receive
     * empty input, producing a generic document that cannot be trusted.
     * An empty equipment list therefore forces confidence to 0.0 regardless
     * of what other fields were found.
     *
     * Scoring (when equipment is present):
     *   base  0.3  — equipment confirmed non-empty
     *   +0.2  project ref detected (non-default; used as the project identifier)
     *   +0.2  client name found
     *   +0.2  site address found
     *   +0.1  three or more distinct equipment items extracted
     *   cap   1.0
     */
    private function calculateConfidence(string $client, string $site, string $ref, array $equipment): float
    {
        // No equipment → the parse has failed at its most important task.
        if (empty($equipment)) {
            return 0.0;
        }

        $score = 0.3; // base — equipment confirmed non-empty

        // +0.2 for project identification (non-default ref treated as project name)
        if ($ref !== '' && $ref !== 'RAMS-001') {
            $score += 0.2;
        }

        if ($client !== '') {
            $score += 0.2;
        }

        if ($site !== '') {
            $score += 0.2;
        }

        if (count($equipment) >= 3) {
            $score += 0.1;
        }

        return min(1.0, round($score, 2));
    }

    /**
     * Split raw text into clean, usable lines.
     *
     * A line is usable if it:
     *   - Is at least 3 characters after trimming.
     *   - Contains at least one alphabetic character.
     *
     * Special case: horizontal rule lines (dashes / underscores / equals signs,
     * no alpha) are converted to HR_SENTINEL so that detectOverviewLineRange()
     * can use them as an end marker even though they lack alphabetic content.
     *
     * The alpha requirement rejects pure-numeric garbage (standalone prices,
     * PDF xref entries, page counts), binary fragments, and empty separators
     * before they can reach any extraction method.
     */
    private function toLines(string $text): array
    {
        $lines = preg_split('/\r?\n/', $text);

        $result = [];
        foreach ($lines as $l) {
            // Normalise all Unicode space separators (incl. U+00A0 non-breaking
            // space that pdftotext sometimes emits) to plain ASCII spaces so that
            // heading patterns and word-boundary checks work correctly.
            $l = preg_replace('/\p{Zs}/u', ' ', $l);
            $l = trim($l);
            if ($l === '') {
                continue;
            }
            // Convert horizontal rules to a sentinel that survives the alpha filter.
            if (preg_match('/^[\-_=\*]{3,}\s*$/', $l)) {
                $result[] = self::HR_SENTINEL;
                continue;
            }
            if (strlen($l) >= 3 && preg_match('/[a-zA-Z]/', $l)) {
                $result[] = $l;
            }
        }

        return array_values($result);
    }

    /**
     * Merge two-line part-number + description rows into a single line.
     *
     * Some QuoteWerks PDF exports (depending on PDF renderer and column width)
     * split a pricing row across two consecutive lines:
     *
     *   MVC860                               ← bare part number
     *   Yealink MVC860 Video Conferencing Kit ← description
     *
     * After merging this becomes:
     *   MVC860 Yealink MVC860 Video Conferencing Kit
     *
     * which is then processed identically to a standard single-line pricing row
     * by both detectOverviewLineRange() and extractEquipment().
     *
     * Merge conditions (all must be true):
     *   - Current line passes isSolePartNumber() — it is a bare part number with
     *     nothing else on the line.
     *   - Next line is not another bare part number (avoids double-merge).
     *   - Next line is not the HR_SENTINEL.
     *   - Next line contains real text (2+ alpha chars, 4–200 chars long).
     *
     * Lines that do not meet these conditions are passed through unchanged,
     * preserving all existing single-line and non-pricing-table rows.
     */
    private function mergePartNumberLines(array $lines): array
    {
        $result = [];
        $count  = count($lines);
        $i      = 0;

        while ($i < $count) {
            $line = $lines[$i];

            if ($this->isSolePartNumber($line)) {
                $next = $lines[$i + 1] ?? null;

                if (
                    $next !== null
                    && $next !== self::HR_SENTINEL
                    && ! $this->isSolePartNumber($next)
                    && preg_match('/[a-zA-Z]{2,}/', $next)
                    && strlen(trim($next)) >= 4
                    && strlen(trim($next)) <= 200
                    && ! preg_match('/\(optional\)/i', $next)  // never merge into an Optional item
                ) {
                    // Combine: "PARTNUM" + "Description text" → "PARTNUM Description text"
                    $result[] = trim($line) . ' ' . trim($next);
                    $i += 2; // consume both lines
                    continue;
                }
            }

            $result[] = $line;
            $i++;
        }

        return $result;
    }

    /**
     * Return true when $line (before lower-casing) is a bare part number —
     * i.e. the entire line consists of a single part-number token with nothing
     * else on the line.
     *
     * Acceptance criteria mirror extractEquipment()'s part-number detection:
     *   - Matches [A-Za-z0-9][A-Za-z0-9\-\.]{3,29}  (4–30 chars)
     *   - Contains a hyphen (traditional: YEA-MIC-S)
     *     OR contains a digit AND 2+ alpha chars (alphanumeric: MVC860, CM20, CS10)
     *
     * Pure alphabetic tokens without hyphens (HDMI, WIFI, etc.) are rejected
     * because they lack both a hyphen and a digit, preventing common abbreviations
     * from being mistaken for part numbers.
     */
    private function isSolePartNumber(string $line): bool
    {
        $trimmed = trim($line);

        if (! preg_match('/^([A-Za-z0-9][A-Za-z0-9\-\.]{3,29})$/', $trimmed, $m)) {
            return false;
        }

        $token         = $m[1];
        $hasHyphen     = str_contains($token, '-');
        $hasDigit      = (bool) preg_match('/\d/', $token);
        $hasAlphaChars = (bool) preg_match('/[a-zA-Z]{2,}/', $token);

        if (! ($hasHyphen || ($hasDigit && $hasAlphaChars))) {
            return false;
        }

        // Exclude known document-identifier patterns that satisfy the digit +
        // alpha criteria but are never AV equipment part numbers.
        // 21CQ…  — 21st Century AV internal quote reference numbers.
        // Add further patterns here if other ref formats appear.
        if (preg_match('/^21CQ\d+$/i', $token)) {
            return false;
        }

        return true;
    }

    private function cleanValue(string $value): string
    {
        $value = preg_replace('/^[\s\:\-\|]+/', '', $value);
        $value = preg_replace('/\s+/', ' ', $value);
        return trim($value);
    }

    private function isPlausibleName(string $value): bool
    {
        return strlen($value) > 2
            && strlen($value) < 100
            && (bool) preg_match('/[a-zA-Z]/', $value)       // must contain alpha
            && ! preg_match('/^\d+[\.,]?\d*$/', $value);     // not purely numeric
    }

    /**
     * Return true when $lower is a known noise line that should be skipped
     * even if it happens to contain an equipment keyword.
     *
     * Covers the following categories:
     *   A–G. QuoteWerks overview / narrative sentence patterns.
     *   H.   QuoteWerks / invoice header and footer lines.
     *   I.   PDF structural keywords standing alone on a line.
     *   J.   PDF object reference patterns ("17 0 obj", "3 0 R").
     *   K.   PDF cross-reference table entries ("0000000017 00000 n").
     *
     * Note: $lower must already be lower-cased by the caller.
     */
    private function isNoise(string $lower): bool
    {
        // ── QuoteWerks overview / narrative sentences ─────────────────────────
        // The Overview section of a QuoteWerks quote contains human-written
        // descriptive sentences about options and recommendations. These lines
        // contain equipment keywords (display, camera, panel) but are NOT
        // equipment rows — they are explanatory prose.

        // Rule A: article-started lines ending with a full stop are always prose.
        // Catches: "A Yealink MTR System with a Room PC, Touch Control Panel..."
        if (preg_match('/^(?:a|an|the)\s+/i', $lower) && str_ends_with(rtrim($lower), '.')) {
            return true;
        }

        // Rule B: lines that contain a "not included / not provided" phrase.
        // Catches: "Cabling has not been included at this stage..."
        if (preg_match('/\b(?:not\s+(?:been\s+)?included|not\s+(?:been\s+)?provided|'
            . 'subject\s+to\s+change|at\s+this\s+stage)\b/i', $lower)) {
            return true;
        }

        // Rule C: two-condition check (starts narrative AND contains narrative verb).
        // Catches remaining overview sentences not covered by A or B.
        $startsNarrative = (bool) preg_match(
            '/^(?:a |an |the |options? |this |these |note[:\s]|please |we |our |'
            . 'ceiling|cabling\s+|please\s+note)/i',
            $lower,
        );
        $hasNarrativeVerb = (bool) preg_match(
            '/\b(?:is\s+(?:provided|suggested|recommended|required|included|not\s+included)|'
            . 'are\s+(?:provided|suggested|recommended|required|included)|'
            . 'has\s+(?:not\s+)?been\s+(?:suggested|provided|included|noted|installed)|'
            . 'have\s+been|'
            . 'will\s+be\s+(?:installed|provided|required|included)|'
            . 'would\s+(?:go|be|require)|'
            . 'enhances?\s+the|ensures?\s+|'
            . 'are\s+pleased\s+to|pleased\s+to\s+offer)\b/i',
            $lower,
        );

        if ($startsNarrative && $hasNarrativeVerb) {
            return true;
        }

        // Rule D: items marked as optional — green text in QuoteWerks.
        // These are add-ons / alternatives, NOT part of the core solution.
        // Catches:
        //   "(Optional)" with parentheses anywhere in the line.
        //   Bare "optional" as a whole word (e.g. "HDMI - Optional",
        //   "Display - optional upgrade").
        if (preg_match('/\boptional\b/i', $lower)) {
            return true;
        }

        // Rule E: narrative sentences offering alternatives (comma list + "or").
        // Catches: "wired/wireless Desk Mic's, Soundbar style speakers or
        //           wall-mounted Column Speakers."
        // These end with a period, are long enough to be a sentence, and contain
        // the word "or" used as a conjunction between alternatives.
        if (
            strlen($lower) > 40
            && str_ends_with(rtrim($lower), '.')
            && preg_match('/\s+or\s+\w/i', $lower)
        ) {
            return true;
        }

        // Rule F: sub-section / option headings (e.g. "Display Options",
        // "Audio Options", "Video Conferencing Options").
        // Short lines (≤ 35 chars) whose last word is "options" or "option".
        if (strlen($lower) <= 35 && preg_match('/\boptions?\s*$/i', $lower)) {
            return true;
        }

        // Rule G: prose list with possessive/plural apostrophe-s followed by
        // a comma — characteristic of narrative alternative lists in overviews.
        // Catches: "wired/wireless Desk Mic's, Soundbar style speakers or…"
        // Very unlikely to appear in a genuine equipment part-number row.
        if (preg_match("/[a-z]'s\s*,/i", $lower)) {
            return true;
        }

        // ── QuoteWerks / invoice document noise ───────────────────────────────
        if (preg_match(
            '/^(?:total|subtotal|vat|tax|discount|page\s+\d|quote\s+date|terms|conditions|'
            . 'prepared\s+by|issued|revision|ex\s+vat|inc\s+vat|net\s+price|gross|'
            . 'description|qty|unit\s+price|unit\s+cost|line\s+total|'
            . 'authorised|signature|print\s+name|'
            . 'payment\s+terms|bank\s+details|sort\s+code|account\s+number|'
            . 'registered\s+in\s+england|company\s+number|vat\s+reg|'
            . 'email:|tel:|fax:|www\.|https?:)/i',
            $lower,
        )) {
            return true;
        }

        // ── PDF structural keywords (whole-line match) ────────────────────────
        if (preg_match('/^(?:endobj|endstream|xref|startxref|trailer|stream)\s*$/', $lower)) {
            return true;
        }

        // ── PDF object references: "17 0 obj" or "3 0 R" (whole line) ─────────
        if (preg_match('/^\d+\s+\d+\s+(?:obj|r)\s*$/', $lower)) {
            return true;
        }

        // ── PDF xref table entries: "0000000017 00000 n" ─────────────────────
        if (preg_match('/^\d{5,}\s+\d{5}\s+[fn]\s*$/', $lower)) {
            return true;
        }

        return false;
    }

    // =========================================================================
    // PRIVATE — STRUCTURED TAG-BASED PARSING
    // =========================================================================

    /**
     * Return true when the raw PDF text contains the structured RAMS tags that
     * indicate the PDF was produced from the updated QuoteWerks template.
     *
     * Requires all three core equipment tags to be present so we don't trigger
     * on PDFs that happen to contain one stray tag word.
     */
    private function hasStructuredTags(string $rawText): bool
    {
        return stripos($rawText, 'PARTSTART') !== false
            && stripos($rawText, 'PARTDESCSTART') !== false
            && stripos($rawText, 'QTYSTART') !== false;
    }

    /**
     * Identify which QuoteWerks template variant produced this text.
     *
     * The parser supports two QuoteWerks tag flavours emitted by two different
     * proposal templates that the sales team uses interchangeably:
     *
     *   - 'long'  → canonical SITENAMESTART / PARTSTART / OVERVIEWTITLESTART
     *               tokens. The historical default; ~80% of imports. Goes
     *               straight into parseTagBased() unchanged.
     *   - 'short' → compact single-character-prefixed tokens (H1/H1E,
     *               D1S/D1E, P1S/P1E etc.) used by the "priced ram"
     *               proposal template. Routed through
     *               translateShortTagsToLong() so the downstream pipeline
     *               sees canonical long-tag text.
     *
     * Detection rules (deliberately conservative — 'long' wins every tie):
     *
     *   1. If the text contains a long-tag marker (SITENAMESTART or PARTSTART)
     *      → return 'long' immediately. A working long-tag PDF is NEVER
     *      re-routed through the translator, even if it happens to contain
     *      a prose word that looks like a short tag (e.g. a part description
     *      mentioning "H1E" as a model number).
     *   2. Otherwise count short-tag marker matches across three families
     *      (H[1-8]E, D1[SE], P[1-5][SE]?). Return 'short' only when the
     *      summed count is ≥ 2. A single accidental match would not be
     *      enough to flip the variant.
     *   3. Otherwise return 'long' (default-safe — an empty string or a
     *      free-form PDF with no tags goes down the long-tag path, which
     *      then falls back to the heuristic extractor in parse()).
     *
     * Cheap by design — the negative substr_count gate short-circuits on
     * every long-tag PDF without running preg_match_all on the whole text.
     */
    private function detectTagVariant(string $rawText): string
    {
        // Rule 1 — long-tag precedence. Cheap substr_count gate first.
        if (substr_count($rawText, 'SITENAMESTART') > 0
            || substr_count($rawText, 'PARTSTART') > 0
        ) {
            return 'long';
        }

        // Rule 2 — count short-tag markers across the three known families.
        $hCount = preg_match_all('/\bH[1-8]E\b/', $rawText);
        $dCount = preg_match_all('/\bD1[SE]\b/', $rawText);
        $pCount = preg_match_all('/\bP[1-5][SE]?\b/', $rawText);

        $total = (int) $hCount + (int) $dCount + (int) $pCount;

        return $total >= 2 ? 'short' : 'long';
    }

    /**
     * Short-tag → long-tag substitution map for translateShortTagsToLong().
     *
     * Used by a preg_replace_callback after column-split repair + D-pair
     * routing have rewritten the structural shape. The keys are the short
     * tag tokens (H/P prefixed) and the values are the canonical long-tag
     * tokens the downstream parseTagBased() pipeline expects.
     *
     * D1S / D1E are intentionally absent — they're handled by the stateful
     * section-pair routing pass (title vs. text alternation), not by this
     * map.  P4S / P5S are also absent — they're stripped entirely (price +
     * manufacturer side-channel) before this map runs.
     */
    private const SHORT_TO_LONG_TAGS = [
        'H1'  => 'SITENAMESTART',
        'H1E' => 'SITENAMEEND',
        'H2S' => 'PREPAREDBYSTART',
        'H2E' => 'PREPAREDBYEND',
        'H3S' => 'QUOTENUMSTART',
        'H3E' => 'QUOTENUMEND',
        'H4S' => 'SHIPCONTSTART',
        'H4E' => 'SHIPCONTEND',
        'H5S' => 'SHIPPHONESTART',
        'H5E' => 'SHIPPHONEEND',
        'H6S' => 'SHIPEMAILSTART',
        'H6E' => 'SHIPEMAILEND',
        'H7S' => 'SHIPCOMPSTART',
        'H7E' => 'SHIPCOMPEND',
        'H8S' => 'SHIPADDSTART',
        'H8E' => 'SHIPADDEND',
        'P1S' => 'PARTSTART',
        'P1E' => 'PARTEND',
        'P2S' => 'PARTDESCSTART',
        'P2E' => 'PARTDESCEND',
        'P3S' => 'QTYSTART',
        'P3E' => 'QTYEND',
    ];

    /**
     * Translate the QuoteWerks priced "ram" template's short-tag form into
     * the canonical long-tag form so the existing parseTagBased() pipeline
     * runs against it unchanged.
     *
     * The priced template uses a column layout that pdftotext flattens into
     * interleaved single-character-prefixed tokens. Three structural quirks
     * have to be repaired before token-for-token substitution makes sense.
     *
     * ── Quirk 1: H-tag column-split end markers ────────────────────────────
     * The left-column H1 (site name) cell shares a row with the right-column
     * H4/H5/H6/H7/H8 (ship-contact/phone/email) cells. pdftotext serialises
     * left-cell content, then right-cell tuples, then the left-cell's closing
     * marker — split between its first character ("H") and its remainder
     * ("1E").
     *
     *   Input:   H1 Cicor Hartlepool Ltd - Training Room H H4S Jamie Powis H4E 1E
     *   Repair:  H1 Cicor Hartlepool Ltd - Training Room H1E H4S Jamie Powis H4E
     *
     * The sibling H4S/H4E tuple is preserved verbatim AFTER the now-repaired
     * H1…H1E span so the downstream extractor sees a clean SHIPCONT pair.
     *
     * ── Quirk 2: D-tag column-split prefix splits ──────────────────────────
     * The D1S/D1E cell sits in a thin left column; the first letter of the
     * section title overlaps with D1E's right edge in the source PDF.
     * pdftotext serialises:
     *
     *   Input:   D1S F D1E irst Floor Training Room
     *   Repair:  D1S First Floor Training Room D1E
     *
     *   Input:   D1S Suppo D1E rt Services
     *   Repair:  D1S Support Services D1E
     *
     * The glue rule: short alphabetic prefix between D1S and D1E (≤ 8 chars,
     * no whitespace) is concatenated with the line's continuation, and D1E
     * is moved to the end of that line.
     *
     * ── Quirk 3: P4S / P5S pricing-only markers ────────────────────────────
     * The priced template adds two columns the long-tag template never had:
     *
     *   P4S £1,234.56 P4E        — price (proper start/end markers).
     *   P5S Yealink   P5S         — manufacturer (paired START markers — no
     *                               P5E; the second P5S terminates the span).
     *
     * Both spans are stripped entirely. The RAMS / O&M / worksheet pipelines
     * have no use for retail price or manufacturer at the moment; if a
     * future feature needs them, capture them to a side-channel array here
     * before stripping (deliberately not surfaced today to keep the diff
     * surgical).
     *
     * ── D-pair section routing (stateful, the only non-pure pass) ──────────
     * Each section uses D1S/D1E TWICE — first pair is the section title,
     * second pair is the section text. The pair counter resets at every
     * PARTSTART boundary (start-of-equipment marker — anchors end-of-text).
     *
     * For the SECOND D1S of a section, the prose body extends from the
     * paired D1E up to (but not including) the next PARTSTART — far past
     * where D1E lands on its own line.  This pass therefore replaces D1S
     * with OVERVIEWTXTSTART in place, deletes the matched D1E, and inserts
     * OVERVIEWTXTEND just before the next PARTSTART.
     *
     * ── Idempotency ────────────────────────────────────────────────────────
     * Calling this method on already-long-tag text is a no-op — the column-
     * split regexes won't match (no `\bH\b` between H-letter markers), the
     * P4S/P5S strip regexes won't match (no P4S/P5S tokens), the D-pair
     * routing only fires when D1S exists, and the SHORT_TO_LONG_TAGS map
     * only translates short-tag tokens (which long-tag PDFs lack).
     */
    private function translateShortTagsToLong(string $rawText): string
    {
        // ── Pass 1: H-tag column-split end-marker repair ────────────────────
        // Match H<N> at the start of a span, content up to the split marker
        // "H", any number of sibling H<M>S…H<M>E tuples, then the trailing
        // "<N>E". Repair: emit H<N> content H<N>E followed by the sibling
        // tuples verbatim. Size-bounded (.{0,500}) prevents runaway match.
        $rawText = (string) preg_replace_callback(
            '/\bH([1-8])\s+(.{0,500}?)\s+H\s+((?:H[1-8]S\s+.{0,200}?\s+H[1-8]E\s*)+)\1E\b/s',
            function (array $m): string {
                $idx          = $m[1];
                $content      = trim($m[2]);
                $siblingTuple = trim($m[3]);
                return "H{$idx} {$content} H{$idx}E {$siblingTuple}";
            },
            $rawText
        );

        // ── Pass 2: D-tag column-split prefix glue ──────────────────────────
        // Apply line-by-line. Match: D1S <short_alphabetic_prefix> D1E <rest>
        // → D1S <prefix><rest> D1E. The prefix MUST be ≤ 8 alphabetic chars
        // (no spaces) — anything else indicates the prefix is the actual
        // intended D1 content with no column split.
        $rawText = (string) preg_replace_callback(
            '/^(.*?)D1S\s+([A-Za-z]{1,8})\s+D1E\s+(.*)$/m',
            function (array $m): string {
                $leading = $m[1];
                $prefix  = $m[2];
                $rest    = ltrim($m[3]);
                return "{$leading}D1S {$prefix}{$rest} D1E";
            },
            $rawText
        );

        // ── Pass 3a: Strip P4S … P4E (price) spans ──────────────────────────
        // Size-bounded {0,200} prevents catastrophic backtracking on malformed
        // input. Side-channel capture intentionally NOT performed — price is
        // not surfaced anywhere today.
        $rawText = (string) preg_replace('/\bP4S\b.{0,200}?\bP4E\b/s', '', $rawText);

        // ── Pass 3b: Strip P5S … P5S (manufacturer) spans ───────────────────
        // P5 uses paired START markers — no P5E. Second P5S terminates.
        $rawText = (string) preg_replace('/\bP5S\b.{0,100}?\bP5S\b/s', '', $rawText);

        // ── Pass 4: D-pair section routing (title vs. text) ─────────────────
        // Walk the text in order, find every D1S/D1E and every P1S position,
        // alternate title→text per section, reset on P1S boundary.
        $rawText = $this->routeDPairs($rawText);

        // ── Pass 5: Direct short→long token substitution ────────────────────
        // The remaining H/P tokens are translated 1:1. The alternation regex
        // is ordered longest-prefix-first (`H[1-8]E?` matches H1E before H1
        // via \b end-anchor + the `?` greedy quantifier). D1S/D1E are absent
        // — Pass 4 already replaced them with OVERVIEW* tokens.
        $rawText = (string) preg_replace_callback(
            '/\b(H[1-8]E|H[1-8]S|H[1-8]|P[1-3]E|P[1-3]S)\b/',
            fn (array $m): string => self::SHORT_TO_LONG_TAGS[$m[1]] ?? $m[0],
            $rawText
        );

        return $rawText;
    }

    /**
     * Stateful D-pair routing pass — replaces D1S/D1E pairs with
     * OVERVIEWTITLE* (first pair per section) or OVERVIEWTXT* (second
     * pair per section). Section boundaries are anchored by P1S
     * (start-of-equipment marker).
     *
     * For the SECOND pair, OVERVIEWTXTEND is inserted just before the
     * next P1S (not at the D1E location), so the prose body that flows
     * AFTER D1E on subsequent lines is captured into the OVERVIEWTXT
     * span.  D1E itself is deleted.
     *
     * If a section has only ONE D-pair (no second), nothing extra is
     * inserted — the existing parseTagBased pipeline tolerates missing
     * OVERVIEWTXT sections gracefully (overview text just stays empty
     * for that section).
     */
    private function routeDPairs(string $rawText): string
    {
        // Collect every relevant offset in a single pass per token family.
        preg_match_all('/\bD1S\b/', $rawText, $d1sM, PREG_OFFSET_CAPTURE);
        preg_match_all('/\bD1E\b/', $rawText, $d1eM, PREG_OFFSET_CAPTURE);
        preg_match_all('/\bP1S\b/', $rawText, $p1sM, PREG_OFFSET_CAPTURE);

        $d1sOffsets = array_column($d1sM[0], 1);
        $d1eOffsets = array_column($d1eM[0], 1);
        $p1sOffsets = array_column($p1sM[0], 1);

        if (count($d1sOffsets) === 0 || count($d1sOffsets) !== count($d1eOffsets)) {
            // No D-pairs, or mismatched counts — fall back to a simple 1:1
            // substitution by the main pass. We deliberately do NOT throw;
            // the downstream pipeline will surface the data as best it can.
            return $rawText;
        }

        // Build pair (start, end) tuples in document order — D1S[i] pairs
        // with D1E[i] because pdftotext output preserves source order.
        $pairs = [];
        foreach ($d1sOffsets as $i => $startOff) {
            $endOff = $d1eOffsets[$i] ?? null;
            if ($endOff === null || $endOff <= $startOff) {
                return $rawText; // Pair mismatch — abort routing.
            }
            $pairs[] = ['start' => $startOff, 'end' => $endOff];
        }

        // Walk pairs in order; track which "slot" (1=title, 2=text) each
        // pair occupies within its section, then build a list of edits.
        // Monotonic pointers (sectionPtr, nextP1sPtr) keep this pass O(N).
        $edits        = []; // list of [offset, length, replacement]
        $slot         = 1;
        $sectionPtr   = 0;  // index into $p1sOffsets — passed-P1S cursor
        $p1sCount     = count($p1sOffsets);
        $rawTextLen   = strlen($rawText);
        foreach ($pairs as $pair) {
            // Reset slot when this pair sits after the next pending P1S.
            while ($sectionPtr < $p1sCount && $p1sOffsets[$sectionPtr] < $pair['start']) {
                $slot = 1;
                $sectionPtr++;
            }

            if ($slot === 1) {
                // First pair in section → title. Replace D1S/D1E in place.
                $edits[] = [$pair['start'], 3, 'OVERVIEWTITLESTART'];
                $edits[] = [$pair['end'],   3, 'OVERVIEWTITLEEND'];
                $slot = 2;
            } else {
                // Second pair → text. Replace D1S with OVERVIEWTXTSTART;
                // delete the matched D1E; insert OVERVIEWTXTEND just before
                // the next P1S (or end of text if no P1S follows). Use the
                // sectionPtr cursor so this lookup stays amortized O(N).
                $edits[] = [$pair['start'], 3, 'OVERVIEWTXTSTART'];
                $edits[] = [$pair['end'],   3, ''];

                $insertOffset = $sectionPtr < $p1sCount
                    ? $p1sOffsets[$sectionPtr]
                    : $rawTextLen;
                $edits[]      = [$insertOffset, 0, 'OVERVIEWTXTEND '];

                // Stay in slot 2 — further D-pairs in this section (rare)
                // also get the OVERVIEWTXT treatment.
            }
        }

        // Apply edits in a single forward pass to a fresh output buffer.
        // Using substr_replace per edit would be O(N²) on the buffer size —
        // 10k pairs × ~200KB buffer = ~6 GB of byte copies. Building a fresh
        // buffer drops the total work to O(N) and matches the performance
        // contract documented in test_2_10_performance_on_large_synthetic_input.
        usort($edits, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $out    = '';
        $cursor = 0;
        foreach ($edits as [$offset, $length, $replacement]) {
            if ($offset > $cursor) {
                $out .= substr($rawText, $cursor, $offset - $cursor);
                $cursor = $offset;
            }
            $out    .= $replacement;
            $cursor += $length;
        }
        if ($cursor < strlen($rawText)) {
            $out .= substr($rawText, $cursor);
        }

        return $out;
    }

    /**
     * Normalise OCR-garbled QuoteWerks marker tokens back to their canonical form.
     *
     * Some PDF text extractors substitute characters inside QuoteWerks marker
     * tokens due to custom-font glyph subsetting. Two corruption families have
     * been observed on production quotes:
     *
     *   1. OVERVIEW family: "V" → "y" (e.g. OVERVIEWTXTEND → oyERVIEWTXTEND).
     *   2. QUOTENUM  family: leading "Q" → "a" / "q", inner "T" → "r" (e.g.
     *      QUOTENUMSTART → auotenumsTart, QUOTENUMEND → quorenumend) with
     *      sporadic case modulation across the rest of the token.
     *   3. PARTDESC  family: leading "P" dropped AND/OR inner "D" → "O" (e.g.
     *      PARTDESCEND → ARTOESCEND, PARTOESCEND, ARTDESCEND). When the closer
     *      is garbled, the tuple-extraction regex on parseTagBased() non-greedy-
     *      consumes the NEXT canonical PARTDESCEND, merging two adjacent rows
     *      into one mangled row and silently dropping any rows in between.
     *      Observed on the Light Forms 21CQ30451-01-OPS quote (package 124).
     *
     * We rewrite the known garbled forms here ONCE at the entry point so every
     * downstream strip regex sees consistent input. Regexes are conservative:
     * they only match when the suffix is the canonical (START|END) / (TITLE|TXT)
     * pair, so legitimate prose words ("oye", "auto", "quorum") are safe.
     *
     * New corruption variants get added here as they're observed on real PDFs.
     */
    private function normaliseQuoteWerksMarkers(string $rawText): string
    {
        // OVERVIEW family — V↔Y substitution on the second character.
        $rawText = (string) preg_replace(
            '/\bO[yY]ERVIEW(TITLE|TXT)(START|END)\b/i',
            'OVERVIEW$1$2',
            $rawText
        );

        // QUOTENUM family — leading [aAqQ] (instead of Q) and inner [trTR]
        // (instead of T) covering both observed variants. The "\b" boundaries
        // protect any prose word that happens to start with "auo" or "quo".
        $rawText = (string) preg_replace(
            '/\b[aAqQ]uo[trTR]enum(start|end)\b/i',
            'QUOTENUM$1',
            $rawText
        );

        // PARTDESC family — prefix and inner-D corruption. Canonical token is
        // PARTDESC{START,END}. Observed garble: "ARTOESCEND" (leading P dropped
        // AND D→O substitution). Pattern accepts:
        //   - optional leading P (allows P-dropped form)
        //   - literal AR (the next two chars are stable across observed corruption)
        //   - optional T (defensive: covers an unobserved AR(_)DESCEND variant)
        //   - one of [DO] for the canonical D or its O substitution
        //   - literal ESC (stable)
        //   - the canonical (START|END) suffix as the anchor
        // The ESC + (START|END) tail is unique enough that no English prose word
        // matches (no real word ends in "OESCEND" or "DESCSTART") so the rewrite
        // is safe even with the loosened prefix.
        $rawText = (string) preg_replace(
            '/\bP?AR[Tt]?[DOdo]ESC(START|END)\b/i',
            'PARTDESC$1',
            $rawText
        );

        return $rawText;
    }

    /**
     * Full tag-based parse for structured RAMS PDFs.
     *
     * Tag layout (both quote types supported):
     *
     *   SITENAMESTART   … SITENAMEEND            → site name
     *   PREPAREDBYSTART … PREPAREDBYEND           → prepared by
     *   QUOTENUMSTART   … QUOTENUMEND  (or near)  → quote ref
     *
     *   OVERVIEWTITLESTART … OVERVIEWTITLEEND     → section heading
     *   OVERVIEWTXTSTART   … OVERVIEWTXTEND       → section description text
     *   (text may also appear outside the TXT tags due to column-layout OCR)
     *
     *   PARTSTART … PARTEND           → part number (may be empty; look above)
     *   PARTDESCSTART … PARTDESCEND   → description
     *   QTYSTART  … QTYEND            → quantity  (0.00 = optional, skip)
     *
     * Section / Overview rules:
     *   - First OVERVIEWTITLE that contains the word "overview" (case-insensitive)
     *     → its body text becomes the master overview field.
     *   - All other OVERVIEWTITLEs are section headings; the heading text is
     *     stored as the `area` field on every equipment item that follows it.
     *   - If no "overview" title exists (multi-room quotes), the overview field
     *     is built by concatenating every section title + its description.
     *
     * Optional items:
     *   - Any PART tuple where QTYSTART value is 0 (or 0.00) is excluded.
     */
    private function parseTagBased(string $rawText): array
    {
        // ── 1. Header fields ────────────────────────────────────────────────
        // client  → organisation name from SITENAMESTART (trailing punctuation stripped)
        // site    → physical address from SHIPADDSTART / SHIPADDEND (cleaned)
        // Both fall back to heuristic extractors when their tags are empty.

        $siteName = $this->extractTaggedSiteName($rawText);
        $client   = $this->extractTaggedCompanyName($rawText);
        $site = $this->extractTaggedSiteAddress($rawText);

        if ($client === '') {
            $lines  = $this->toLines($rawText);
            $client = $this->extractClient($rawText, $lines);
        }
        if ($site === '') {
            $lines = isset($lines) ? $lines : $this->toLines($rawText);
            $site  = $this->extractSite($rawText, $lines);
        }

        // Extract the prepared-by name from PREPAREDBYSTART…PREPAREDBYEND.
        //
        // In pdftotext column-layout output the block may also contain adjacent-
        // column content (tag tokens, quote ref, phone numbers, email addresses).
        // We process line-by-line and skip those noise lines; the first remaining
        // line that looks like a person's name is the prepared-by value.
        $preparedBy = '';
        if (preg_match('/PREPAREDBYSTART\s*(.*?)\s*PREPAREDBYEND/s', $rawText, $pbm)) {
            $pbContent = trim($pbm[1]);
            if ($pbContent !== '') {
                $pbTagRe   = '/\b(?:QUOTENUMSTART|QUOTENUMEND|SHIPCONTSTART|SHIPCONTEND|'
                    . 'SHIPPHONESTART|SHIPPHONEEND|SHIPEMAILSTART|SHIPEMAILEND|'
                    . 'SHIPCOMPSTART|SHIPCOMPEND|SHIPADDSTART|SHIPADDEND|'
                    . 'SITENAMESTART|SITENAMEEND|OVERVIEWTITLESTART|OVERVIEWTITLEEND)\b/';
                $pbNoiseRe = '/^(?:PART\s*NUMBER|SUPPLIER|QTY|DESCRIPTION|'
                    . 'BUY\s*[£$€]?|SELL\s*[£$€]?|TOTAL|INTERNAL\s+RAMS|\d+\s+of\s+\d+)$/i';
                foreach (preg_split('/\r?\n/', $pbContent) as $pbLine) {
                    $pbLine = trim(preg_replace('/\p{Zs}/u', ' ', $pbLine));
                    if ($pbLine === '') continue;
                    if (preg_match($pbTagRe,   $pbLine)) continue; // skip tag tokens
                    if (preg_match($pbNoiseRe, $pbLine)) continue; // skip column headers
                    if (preg_match('/^[A-Z0-9][A-Z0-9\-\/]{3,}$/i', $pbLine) && preg_match('/\d/', $pbLine)) continue; // skip ref numbers (must contain a digit)
                    if (! preg_match('/[a-zA-Z]{2,}/', $pbLine)) continue; // skip digits/phone
                    if (str_contains($pbLine, '@')) continue;              // skip emails
                    $preparedBy = $this->normalise($pbLine, 80);
                    break;
                }
            }
        }
        // Fallback: heuristic extraction from raw text.
        if ($preparedBy === '') {
            // Layout C: PREPAREDBYSTART PREPAREDBYEND tag is empty; name is on the
            // line immediately before the tag (same pattern as SITENAMESTART).
            $before = $this->extractLineBeforeTag($rawText, 'PREPAREDBYSTART');
            if ($before !== '') {
                $cleaned = trim((string) preg_replace('/\b[A-Z]{3,}(?:START|END)\b/i', '', $before));
                $cleaned = trim((string) preg_replace('/\s{2,}/', ' ', $cleaned));
                // Must look like a person name: 1–3 words, no digits, not all-caps.
                $words = array_filter(explode(' ', $cleaned), fn ($w) => $w !== '');
                if (count($words) >= 1 && count($words) <= 3
                    && ! preg_match('/\d/', $cleaned)
                    && ! preg_match('/\b(?:ltd|plc|limited|inc|corp)\b/i', $cleaned)
                    && $cleaned !== strtoupper($cleaned)
                ) {
                    $preparedBy = $this->normalise($cleaned, 80);
                }
            }
        }
        if ($preparedBy === '') {
            $preparedBy = $this->extractPreparedBy($rawText);
        }
        if ($preparedBy === '') {
            $preparedBy = $this->extractPreparedByFromShipEmail($rawText);
        }
        $ref = $this->extractTaggedRef($rawText);

        // ── 2. Pre-compute all PARTSTART offsets ────────────────────────────
        // Section description text ends at the first PARTSTART within each
        // section — not at the next OVERVIEWTITLESTART — because part tuples
        // and their overflow lines appear after the prose description.
        preg_match_all('/PARTSTART/i', $rawText, $psm, PREG_OFFSET_CAPTURE);
        $allPartStartOffsets = array_column($psm[0], 1);  // already ascending

        // ── 3. Collect overview sections + room titles ──────────────────────
        // QuoteWerks tagged OCR can place title text AFTER OVERVIEWTITLEEND and
        // before OVERVIEWTXTSTART/END. Use a dedicated parser that supports both
        // inline-title and post-end-title layouts.
        $sections = $this->parseTaggedSections($rawText, $allPartStartOffsets);

        // ── 4. Build overview text ───────────────────────────────────────────
        $overviewParts = [];
        foreach ($sections as $section) {
            $title = trim((string) ($section['title'] ?? ''));
            $text  = trim((string) ($section['text'] ?? ''));
            if ($title !== '' && $text !== '') {
                $overviewParts[] = $title . "\n" . $text;
            } elseif ($text !== '') {
                $overviewParts[] = $text;
            }
        }
        $overview = implode("\n\n", $overviewParts);

        // ── 5. Extract equipment from PART tuples ────────────────────────────
        // Regex matches the complete tuple on one logical row:
        //   PARTSTART … PARTEND … PARTDESCSTART … PARTDESCEND … QTYSTART … QTYEND
        preg_match_all(
            '/PARTSTART\s*(.*?)\s*PARTEND[\s~]*PARTDESCSTART\s*(.*?)\s*p.?ARTDESCEND[\s~]*QTYSTART\s*(.*?)\s*(?:QTYEND|QTVEND)/is',
            $rawText,
            $tuples,
            PREG_OFFSET_CAPTURE
        );

        $equipment = [];
        $rooms     = [];
        $seenRooms = [];

        // Single regex that strips every known tag token — used to clean
        // descriptions in case a tag bleeds into the PARTDESCSTART content.
        $allTagsPattern =
            '/\b(?:OVERVIEWTXTSTART|OVERVIEWTXTEND|OVERVIEWTITLESTART|OVERVIEWTITLEEND|'
            . 'PARTSTART|PARTEND|PARTDESCSTART|PARTDESCEND|paARTDESCEND|QTYSTART|QTYEND|QTVEND|'
            . 'SITENAMESTART|SITENAMEEND|PREPAREDBYSTART|PREPAREDBYEND|'
            . 'QUOTENUMSTART|QUOTENUMEND|SHIPCONTSTART|SHIPCONTEND|'
            . 'SHIPPHONESTART|SHIPPHONEEND|SHIPEMAILSTART|SHIPEMAILEND|'
            . 'SHIPCOMPSTART|SHIPCOMPEND|SHIPADDSTART|SHIPADDEND)\b/i';

        foreach ($tuples[0] as $idx => $tupleMatch) {
            $tupleOffset = $tupleMatch[1];
            $rawPartNum  = trim($tuples[1][$idx][0]);

            // Raw PARTDESC block can contain qty + part number in column-layout OCR.
            // Parse that first before line-cleaning (which may intentionally strip
            // numeric-only lines).
            $rawDescBlock = preg_replace($allTagsPattern, '', $tuples[2][$idx][0]);
            [$qtyFromDescBlock, $partFromDescBlock, $descFromDescBlock] = $this->parsePartDescComposite($rawDescBlock);

            // Then clean for normal description processing.
            $rawDesc = $this->cleanPartDescLines((string) $rawDescBlock);
            $rawDesc = trim((string) preg_replace('/\s+/', ' ', $rawDesc));

            $rawQtyBlock = (string) $tuples[3][$idx][0];
            $qty = $this->extractFirstNumericValue($rawQtyBlock);

            // Skip optional items (qty ≤ 0) and empty / nonsense descriptions.
            // ── Part number resolution — three strategies ──────────────────
            // Strategy 1: content between PARTSTART … PARTEND tags.
            // Track whether an explicit token was provided — if so, fallback
            // strategies are skipped: a rejected token means no part number.
            $hadExplicitPartToken = $rawPartNum !== '';
            $partNum = $this->normaliseTaggedPartNumber($rawPartNum);

            if ($qty <= 0.0 && $qtyFromDescBlock > 0.0) {
                $qty = $qtyFromDescBlock;
            }

            // Trailing-qty fallback: when QTYSTART block is empty, try extracting
            // a trailing decimal number from the PARTDESC cleaned text.
            // e.g. "Logitech Rally Conference Camera 1.00" → qty=1, strip "1.00"
            if ($qty <= 0.0 && preg_match('/^(.*?)\s+(\d+(?:\.\d+)?)\s*$/s', $rawDesc, $tqm)) {
                $trailingQty = (float) $tqm[2];
                if ($trailingQty > 0.0) {
                    $qty     = $trailingQty;
                    $rawDesc = trim($tqm[1]);
                    // Also update descFromDescBlock so the cleaned description is used downstream.
                    if ($descFromDescBlock !== '') {
                        if (preg_match('/^(.*?)\s+(\d+(?:\.\d+)?)\s*$/s', $descFromDescBlock, $dfm)) {
                            $descFromDescBlock = trim($dfm[1]);
                        }
                    }
                }
            }

            if ($partNum === '' && ! $hadExplicitPartToken && $partFromDescBlock !== '') {
                $partNum = $partFromDescBlock;
            }

            // Strategy 1.5: some PDF generators (e.g. new QuoteWerks template) place
            // the part number inside the QTYSTART…QTYEND block alongside the qty value,
            // rather than between PARTSTART…PARTEND.  After stripping the leading
            // numeric qty, whatever remains is the part number candidate.
            if ($partNum === '' && ! $hadExplicitPartToken) {
                $qtyBlockRemainder = trim((string) preg_replace('/^\s*\d+(?:\.\d+)?\s*/', '', $rawQtyBlock));
                $qtyBlockRemainder = trim($qtyBlockRemainder, " \t\r\n~");
                if ($qtyBlockRemainder !== '') {
                    $partNum = $this->normaliseTaggedPartNumber($qtyBlockRemainder);
                }
            }

            // Strategy 2: standalone token on the line immediately above PARTSTART.
            if ($partNum === '' && ! $hadExplicitPartToken) {
                $above = $this->findPrecedingPartNumber(substr($rawText, 0, $tupleOffset));
                if ($above !== null) {
                    $partNum = $above;
                }
            }

            // Strategy 3: part number embedded inside the description text
            // (trailing parenthetical or trailing token).
            if ($partNum === '' && ! $hadExplicitPartToken) {
                $partNum = $this->extractPartNumFromDescription($rawDesc);
            }
            if (preg_match('/^\d{1,3}(?:st|nd|rd|th)$/i', $partNum)) {
                $partNum = '';
            }

            $descCandidate = $descFromDescBlock !== '' ? $descFromDescBlock : $rawDesc;
            if ($this->looksLikePartAndQtyOnly($descCandidate)) {
                $descCandidate = '';
            }

            if ($descCandidate === '') {
                $descCandidate = $this->extractDescriptionAfterTuple(
                    $rawText,
                    $tupleOffset + strlen($tupleMatch[0]),
                    $allPartStartOffsets
                );
            }

            $rawDesc = trim($descCandidate, " \t\r\n~");
            $rawDesc = preg_replace('/(?:paARTDESCEND|PARTDESCEND|PARTDESCSTART|QTYSTART|QTYEND|QTVEND|PARTSTART|PARTEND)/i', ' ', $rawDesc);
            $rawDesc = trim((string) preg_replace('/\s+/', ' ', (string) $rawDesc));
            $rawDesc = preg_replace('/\s+[£$€]?\d{1,4}(?:,\d{3})*(?:\.\d{2})\s*$/', '', $rawDesc);
            $rawDesc = trim((string) preg_replace('/\s+/', ' ', (string) $rawDesc));

            if ($qty <= 0.0) {
                continue;
            }
            if (strlen($rawDesc) < 3 || ! preg_match('/[a-zA-Z]{2,}/', $rawDesc)) {
                continue;
            }

            // Clamp only when qty looks like OCR price contamination — a large
            // value (>=9999) that leaked from an adjacent price column into a
            // no-part-number row.  Lower values (e.g. 100 engineering hours,
            // 200 consumable units) are legitimate service quantities and must
            // not be clamped.
            if ($qty >= 9999.0 && ($partNum === '' || preg_match('/^\d{1,3}(?:st|nd|rd|th)$/i', $partNum))) {
                $qty = 1.0;
            }

            // Skip table-header rows that leaked through.
            if (preg_match('/^(?:part\s*(?:no|number)|description|qty\.?|unit\s+price|total)\s*$/i', $rawDesc)) {
                continue;
            }

            // ── Area: most recent OVERVIEWTITLE before this tuple ─────────
            $area = '';
            foreach (array_reverse($sections) as $section) {
                if (! empty($section['is_overview'])) {
                    continue;
                }
                if (trim((string) ($section['title'] ?? '')) === '') {
                    continue;
                }
                // Skip QuoteWerks document-structure headers (Hardware /
                // Services / Summary) — they're not rooms (see
                // isNonRoomSectionTitle for the canonical list).
                // Quick task 260528-h8e — Bug A: 21CQ30485-03-OPS.
                if ($this->isNonRoomSectionTitle((string) $section['title'])) {
                    continue;
                }
                if ($tupleOffset >= $section['start']) {
                    $area = $section['title'];
                    break;
                }
            }
            if ($area === '' && count($sections) === 1 && ! empty($sections[0]['title'])) {
                $area = (string) $sections[0]['title'];
            }
            if ($area === '') {
                $area = $this->detectRoom($rawDesc);
            }

            $equipment[] = [
                'qty'         => max(1, (int) round($qty)),
                'part_number' => $partNum,
                'description' => $rawDesc,
                'area'        => $area,
                'location'    => $this->detectRoom($rawDesc),
            ];

            // Collect unique area names for the rooms list. Symmetric guard:
            // never let a document-structure header pollute the rooms list
            // (see area-picker filter above + isNonRoomSectionTitle).
            // Quick task 260528-h8e — Bug A: 21CQ30485-03-OPS.
            if ($area !== '' && ! $this->isNonRoomSectionTitle($area)) {
                $areaKey = strtolower($area);
                if (! isset($seenRooms[$areaKey])) {
                    $seenRooms[$areaKey] = true;
                    $rooms[]             = $area;
                }
            }
        }

        $equipment = $this->dedupeTaggedEquipment($equipment);

        // ── 6. Tasks ────────────────────────────────────────────────────────
        $tasks = $this->extractTasks($this->toLines($overview));

        // ── 7. Room overviews — map parsed sections to review-form structure ─
        // Each OVERVIEWTITLE section becomes one room_overviews entry so the
        // review form pre-populates with extracted narrative text.
        //
        // EXCEPT: QuoteWerks templates reuse OVERVIEWTITLE blocks for document-
        // structure headers (Summary, Hardware, Services, etc.) that are NOT
        // actual rooms. These are skipped so room_overviews stays clean.
        $roomOverviews = [];
        foreach ($sections as $section) {
            $rTitle = trim((string) ($section['title'] ?? ''));
            $rText  = trim((string) ($section['text'] ?? ''));
            if ($rTitle === '' && $rText === '') {
                continue;
            }
            if ($rTitle !== '' && $this->isNonRoomSectionTitle($rTitle)) {
                continue;
            }
            $roomOverviews[] = [
                'room'             => $rTitle,
                'overview'         => $rText,
                'works_summary'    => '',
                'solution_type_id' => '',
                'summary'          => '',
            ];
        }

        // ── 8. Confidence ────────────────────────────────────────────────────
        $confidence = $this->calculateConfidence($client, $site, $ref, $equipment);

        return [
            'client'         => $client,
            'site_name'      => $siteName,
            'site'           => $site,
            'ref'            => $ref,
            'overview'       => $overview,
            'equipment'      => $equipment,
            'prepared_by'    => $preparedBy,
            'tasks'          => $tasks,
            'rooms'          => $rooms,
            'room_overviews' => $roomOverviews,
            'project_name'   => '',
            'works_summary'  => '',
            // 260602-mlt — surface QuoteWerks SHIPCONT / SHIPPHONE so the engineer
            // worksheet + survey public headers can render a "Site contact: {name} · {tel-link}"
            // line. extractTagContent() returns '' (never null) when tags are absent.
            'ship_contact'   => $this->extractTagContent($rawText, 'SHIPCONTSTART', 'SHIPCONTEND'),
            'ship_phone'     => $this->extractTagContent($rawText, 'SHIPPHONESTART', 'SHIPPHONEEND'),
            'confidence'     => $confidence,
        ];
    }

    /**
     * Extract the first non-empty string between a pair of named tags.
     * Collapses internal whitespace to single spaces.
     * Returns '' when the tags are absent or the content is blank.
     */
    private function extractTagContent(string $rawText, string $openTag, string $closeTag): string
    {
        $pattern = '/' . preg_quote($openTag, '/') . '\s*(.*?)\s*' . preg_quote($closeTag, '/') . '/is';
        if (preg_match($pattern, $rawText, $m)) {
            return trim(preg_replace('/\s+/', ' ', $m[1]));
        }
        return '';
    }

    /**
     * Extract the quote reference from a tagged PDF.
     *
     * Two column-layout variants exist:
     *   Layout A (21CQ30150):  "REF QUOTENUMEND" — ref precedes QUOTENUMEND
     *   Layout B (21CQ28863):  "REF\nQUOTENUMSTART" — ref is on the line above
     *
     * Falls back to the heuristic extractRef() for any other layout.
     */
    private function extractTaggedRef(string $rawText): string
    {
        // Layout A: alphanumeric token immediately before QUOTENUMEND.
        // Must contain at least one digit — pure words like "London" are rejected.
        // Use \b so the token is matched from its actual start (not mid-token), and
        // allow digit-leading refs like "21CQ30069-02-OPS" by using [A-Z0-9] not [A-Z].
        if (preg_match('/\b([A-Z0-9][A-Z0-9\-\/]{4,25})\s*QUOTENUMEND/i', $rawText, $m)) {
            $candidate = trim($m[1]);
            if (preg_match('/\d/', $candidate)
                && preg_match('/^[A-Z0-9][A-Z0-9\-\/]{2,25}$/i', $candidate)
                && stripos($candidate, 'QUOTENUMSTART') === false) {
                return strtoupper($candidate);
            }
        }

        // Layout B: alphanumeric token on the line immediately before QUOTENUMSTART.
        // Must contain at least one digit — tag names like "PREPAREDBYSTART" are rejected.
        // Use /m so ^ anchors to line start — prevents matching a sub-token like "CQ28849"
        // from within "21CQ28849" when the line starts with a digit.
        if (preg_match('/^([A-Z0-9][A-Z0-9\-\/]{4,25})[ \t]*\r?\n[ \t]*QUOTENUMSTART/im', $rawText, $m)) {
            $candidate = trim($m[1]);
            if (preg_match('/\d/', $candidate)
                && preg_match('/^[A-Z0-9][A-Z0-9\-\/]{2,25}$/i', $candidate)) {
                return strtoupper($candidate);
            }
        }

        // Layout C: QUOTENUMSTART QUOTENUMEND is empty; ref appears as the first
        // token on the first line of the SHIPADDSTART block (e.g. "21CQ30058-01-OPS Turbinia Works").
        // Extract that token directly — it is more reliable than the heuristic extractRef()
        // which may match a ref mentioned in body text (e.g. a cross-reference to another quote).
        if (preg_match('/SHIPADDSTART\s*\r?\n?([A-Z0-9][A-Z0-9\-\/]{4,25})\s/i', $rawText, $m)) {
            $candidate = trim($m[1]);
            if (preg_match('/\d/', $candidate)
                && preg_match('/^[A-Z0-9][A-Z0-9\-\/]{4,25}$/i', $candidate)) {
                return strtoupper($candidate);
            }
        }

        // Fallback: existing regex patterns (handles 21CQ… anywhere in text)
        return $this->extractRef($rawText);
    }

    /**
     * Parse OVERVIEWTITLE/OVERVIEWTXT sections with OCR/column-layout tolerance.
     *
     * Returns sections sorted by source offset.
     *
     * @return array<int, array{title:string,text:string,start:int,is_overview:bool}>
     */
    private function parseTaggedSections(string $rawText, array $allPartStartOffsets): array
    {
        preg_match_all('/OVERVIEWTITLESTART\s*(.*?)\s*OVERVIEWTITLEEND/is', $rawText, $titles, PREG_OFFSET_CAPTURE);
        if (empty($titles[0])) {
            return [];
        }

        $sections = [];
        $count    = count($titles[0]);

        for ($i = 0; $i < $count; $i++) {
            $fullMatch   = (string) ($titles[0][$i][0] ?? '');
            $startOffset = (int) ($titles[0][$i][1] ?? 0);
            $afterOffset = $startOffset + strlen($fullMatch);

            $inlineTitle = trim((string) ($titles[1][$i][0] ?? ''));
            $title       = $this->cleanSectionTitle($inlineTitle);

            if ($title === '') {
                // OPSrams / Layout-B PDFs: OVERVIEWTITLESTART OVERVIEWTITLEEND is empty
                // and the room title appears on the line immediately following the tag.
                // Check that line first before falling back to the backwards preceding-text scan
                // (which can pick up address noise like "London" instead of the real title).
                $afterSnippet = substr($rawText, $afterOffset, 200);
                if (preg_match('/\r?\n\s*([^\r\n]{3,80})/', $afterSnippet, $am)) {
                    $candidate = $this->cleanSectionTitle(trim((string) ($am[1] ?? '')));
                    if ($this->isPlausibleSectionTitle($candidate)
                        && ! preg_match(
                            '/\b(?:PARTSTART|PARTEND|PARTDESCSTART|PARTDESCEND|QTYSTART|QTYEND|'
                            . 'OVERVIEWTXTSTART|OVERVIEWTXTEND|SITENAMESTART|SITENAMEEND|'
                            . 'SHIPCONTSTART|SHIPCONTEND|SHIPCOMPSTART|SHIPCOMPEND|'
                            . 'QUOTENUMSTART|QUOTENUMEND|PREPAREDBYSTART|PREPAREDBYEND)\b/i',
                            $am[1]
                        )
                    ) {
                        $title = $candidate;
                    }
                }
            }

            if ($title === '') {
                $title = $this->cleanSectionTitle(
                    $this->extractTitleFromPreceding(substr($rawText, 0, $startOffset))
                );
            }
            if (! $this->isPlausibleSectionTitle($title)) {
                $title = '';
            }

            $nextTitleStart = $i + 1 < $count
                ? (int) ($titles[0][$i + 1][1] ?? strlen($rawText))
                : strlen($rawText);

            $sectionEnd = $nextTitleStart;
            foreach ($allPartStartOffsets as $partOffset) {
                if ($partOffset > $afterOffset) {
                    $sectionEnd = min($sectionEnd, $partOffset);
                    break;
                }
            }

            $sectionRaw = substr($rawText, $afterOffset, max(0, $sectionEnd - $afterOffset));
            if (! is_string($sectionRaw)) {
                $sectionRaw = '';
            }

            $textParts = [];
            if (preg_match_all('/OVERVIEWTXTSTART\s*(.*?)\s*OVERVIEWTXTEND/is', $sectionRaw, $txtMatches)) {
                foreach ((array) ($txtMatches[1] ?? []) as $txt) {
                    $clean = $this->extractSectionText((string) $txt);
                    if ($clean !== '') {
                        $textParts[] = $clean;
                    }
                }
            }

            $fallbackText = $this->extractSectionText($sectionRaw);
            if ($fallbackText !== '') {
                $textParts[] = $fallbackText;
            }

            $text = $this->normaliseSectionText($textParts);
            if ($title === '' && $text === '') {
                continue;
            }

            $sections[] = [
                'title'       => $title,
                'text'        => $text,
                'start'       => $startOffset,
                'is_overview' => $title !== '' && preg_match('/\boverview\b/i', $title) === 1,
            ];
        }

        usort($sections, fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        return $sections;
    }

    /**
     * Extract clean description text from a raw section block.
     *
     * The block is the raw text between one OVERVIEWTITLEEND and the next
     * OVERVIEWTITLESTART. It may contain:
     *   - Actual section description lines  ← keep these
     *   - OVERVIEWTXTSTART / OVERVIEWTXTEND markers  ← strip
     *   - Repeated page header lines (SHIP TO, INTERNAL RAMS, etc.)  ← skip
     *   - Standalone part-number tokens (preceding PARTSTART)  ← skip
     *   - PART* / QTY* tag lines  ← skip
     *
     * Returns cleaned, deduplicated text joined with newlines.
     */
    private function extractSectionText(string $rawSection): string
    {
        $lines  = preg_split('/\r?\n/', $rawSection);
        $result = [];
        $seen   = [];

        // Two-tier token handling:
        //   (1) $stripTokens — section/part markers that may share a line with
        //       legitimate prose in tight-word-wrap PDFs, e.g.:
        //         "OVERVIEWTXTSTART Restaurant will have… OVERVIEWTXTEND"
        //         "for media play back. A USB-A extension. PARTSTART … QTYEND"
        //       We strip the tokens and preserve the surrounding prose rather
        //       than dropping the whole line.
        //   (2) $headerTokens — tags that only ever appear inside the repeated
        //       page header banner. Their content (SHIPCONT names, SITENAME
        //       etc.) is never section prose, so lines mentioning them are
        //       dropped in full.
        static $stripTokens = [
            'OVERVIEWTXTSTART', 'OVERVIEWTXTEND',
            'OVERVIEWTITLESTART', 'OVERVIEWTITLEEND',
            'PARTSTART', 'PARTEND',
            'PARTDESCSTART', 'PARTDESCEND',
            'QTYSTART', 'QTYEND', 'QTVEND',
        ];
        $stripPattern = '/\b(?:' . implode('|', $stripTokens) . ')\b/i';

        // Page-header tokens that only ever appear in repeated page headers.
        // Any line mentioning one of these is guaranteed to be header noise.
        static $headerTokens = [
            'SITENAMESTART', 'SITENAMEEND',
            'PREPAREDBYSTART', 'PREPAREDBYEND',
            'QUOTENUMSTART', 'QUOTENUMEND',
            'SHIPCONTSTART', 'SHIPCONTEND',
            'SHIPPHONESTART', 'SHIPPHONEEND',
            'SHIPEMAILSTART', 'SHIPEMAILEND',
            'SHIPCOMPSTART', 'SHIPCOMPEND',
            'SHIPADDSTART', 'SHIPADDEND',
        ];
        $headerPattern = '/\b(?:' . implode('|', $headerTokens) . ')\b/i';

        foreach ($lines as $line) {
            // Normalise Unicode whitespace.
            $line = preg_replace('/\p{Zs}/u', ' ', $line);
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            // Header-token lines are always noise — drop in full.
            if (preg_match($headerPattern, $line)) {
                continue;
            }

            // Rescue prose that shares a line with section/part tokens.
            // "OVERVIEWTXTSTART Restaurant will have… OVERVIEWTXTEND" → keep the middle.
            // "for media play back. A USB… playback. PARTSTART PARTEND … QTYEND" → keep the prefix.
            // Collapse multi-token spans to a single space so two prose fragments
            // stay recognisable as two sentences, not concatenated.
            if (preg_match($stripPattern, $line)) {
                $line = (string) preg_replace($stripPattern, ' ', $line);
                $line = trim((string) preg_replace('/\s{2,}/', ' ', $line));
                // After token stripping, a trailing part-number-shape token
                // ("2 60005923") may be all that's left on the prose line —
                // the existing standalone-part-number filter below handles it.
                if ($line === '') {
                    continue;
                }
            }

            $clean = $line;

            // Skip recognisable page-header content lines (no tag token, but
            // still clearly header material).
            if (preg_match(
                '/^(?:INTERNAL\s+RAMS|PART\s+NUMBER|QTY\s+BUY|\d+\s+of\s+\d)/i',
                $clean,
            )) {
                continue;
            }

            // Must contain at least two consecutive alphabetic chars.
            if (! preg_match('/[a-zA-Z]{2,}/', $clean)) {
                continue;
            }

            // Skip standalone part-number tokens (the part number line that
            // immediately precedes PARTSTART — not prose description text).
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9\-\.\/]{2,49}$/', $clean)) {
                $hasH = str_contains($clean, '-');
                $hasD = (bool) preg_match('/\d/', $clean);
                $hasA = (bool) preg_match('/[a-zA-Z]{2,}/', $clean);
                if ($hasH || ($hasD && $hasA)) {
                    continue;
                }
            }

            // Minimum length guard — exclude single-word label fragments.
            if (strlen($clean) < 8) {
                continue;
            }

            $key = strtolower($clean);
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $result[]   = $clean;
            }
        }

        return implode("\n", $result);
    }

    /**
     * Look backwards from the position of a PARTSTART tag for a valid standalone
     * part-number token on the immediately preceding non-empty line.
     *
     * This handles the column-layout variant where the part number is extracted
     * to a separate line above the PARTSTART…PARTEND block.
     *
     * Returns null when the preceding line does not look like a part number.
     */
    private function findPrecedingPartNumber(string $textBefore): ?string
    {
        $lines = preg_split('/\r?\n/', $textBefore);

        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim($lines[$i]);
            if ($line === '') {
                continue;
            }

            // Must be a single token (no spaces).
            // Allow digit-leading tokens, slash-separated, ampersand-joined, hash-separated etc.
            if (! preg_match('/^([A-Za-z0-9][A-Za-z0-9\-\.\/&#]{2,49})$/', $line, $m)) {
                break; // Not a part-number line — stop immediately.
            }

            // Delegate all validation to normaliseTaggedPartNumber which correctly
            // handles every valid part-number shape:
            //   - hyphenated codes (BT8431/B, YAM-RM-CR)
            //   - mixed alpha+digit (CS32880, Travel1)
            //   - all-uppercase alpha service codes (DELIVERY, RAMS, FIRSTFIX)
            //   - all-digit SKUs ≥ 4 chars (60005923, 37871)
            //   - ampersand codes (O&M, 0&M)
            $pn = $this->normaliseTaggedPartNumber($m[1]);
            if ($pn !== '') {
                return $pn;
            }

            break;
        }

        return null;
    }

    /**
     * Validate and normalise a raw part number string extracted from a tag.
     *
     * - Strips all internal whitespace (tokens should not contain spaces).
     * - Rejects placeholder indicators ("e.g.", "e.g").
     * - Rejects tokens that don't satisfy the part-number criteria
     *   (hyphen OR digit + 2+ alpha chars).
     * - Returns '' when no valid part number can be produced.
     */
    private function normaliseTaggedPartNumber(string $raw): string
    {
        $raw = trim(preg_replace('/\s+/', '', $raw));
        // OCR commonly prefixes/suffixes part numbers with punctuation noise.
        // Keep only valid token boundaries before shape validation.
        $raw = trim($raw, " \t\n\r\0\x0B~`!@#$%^&*()_+=[]{}|\\:;\"'<>,?");

        // Strip any leading non-ASCII characters introduced by PDF font encoding.
        // QuoteWerks templates using certain fonts can emit a © glyph (0xC2 0xA9 in
        // UTF-8) as a prefix before the first character of a part number, which causes
        // every downstream regex to reject the value.  Strip any run of non-printable-
        // ASCII bytes at the very start before the shape checks below.
        $raw = (string) preg_replace('/^[^\x20-\x7E]+/', '', $raw);
        $raw = trim($raw);

        if ($raw === '') {
            return '';
        }

        // Time/schedule and short unit tokens are never valid part numbers.
        // Case-sensitive: "9am" / "12:30pm" are rejected as time strings,
        // but uppercase codes like "9AM" are kept as QuoteWerks service codes.
        if (preg_match('/^\d{1,2}(?::\d{2})?(?:am|pm)$/', $raw)) {
            return '';
        }
        if (preg_match('/^\d{1,3}[A-Za-z]$/', $raw)) {
            return '';
        }
        if (preg_match('/^(?:mon|tue|wed|thu|fri|sat|sun)(?:day)?$/i', $raw)) {
            return '';
        }
        if (preg_match('/^\d{1,3}(?:st|nd|rd|th)$/i', $raw)) {
            return '';
        }
        if (preg_match('/^(?:am|pm)$/i', $raw)) {
            return '';
        }

        // Reject page-number bleed artifacts that smalot emits when the PDF
        // page-number footer overlaps with the QTYEND tag in the text stream.
        // Examples: "2bt4" = "2 of 4", "3of4" = "3 of 4", "bt4" = "of 4".
        // Pattern: optional leading digits, then "bt" or "of", then trailing digits.
        if (preg_match('/^\d*(?:bt|nof|of)\d+$/i', $raw)) {
            return '';
        }

        // Placeholder guard.
        if (preg_match('/\be\.?\s*g\.?\b/i', $raw)) {
            return '';
        }

        // Reject QuoteWerks structural tag tokens that may appear as the line
        // preceding PARTSTART (e.g. when the address block ends just before the
        // item table, "SHIPADDEND" becomes the last non-empty line and Strategy 2
        // would otherwise promote it to a part number).
        static $tagTokenPattern = '/^(?:PARTSTART|PARTEND|PARTDESCSTART|PARTDESCEND|'
            . 'QTYSTART|QTYEND|QTVEND|QUOTENUMSTART|QUOTENUMEND|'
            . 'SHIPCOMPSTART|SHIPCOMPEND|SHIPADDSTART|SHIPADDEND|'
            . 'SITENAMESTART|SITENAMEEND|PREPAREDBYSTART|PREPAREDBYEND|'
            . 'SHIPCONTSTART|SHIPCONTEND|SHIPPHONESTART|SHIPPHONEEND|'
            . 'SHIPEMAILSTART|SHIPEMAILEND|OVERVIEWTITLESTART|OVERVIEWTITLEEND|'
            . 'OVERVIEWTXTSTART|OVERVIEWTXTEND)$/i';
        if (preg_match($tagTokenPattern, $raw)) {
            return '';
        }

        // Basic shape: alphanumeric + hyphens/dots/slashes/spaces/ampersands/hashes.
        // Spaces and & are valid in QuoteWerks part codes (e.g. "FIRST FIX", "O&M").
        // # is valid mid-token in some supplier part codes (e.g. "BT8431#B").
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9\-\.\/&# ]{1,30}$/', $raw)) {
            return '';
        }

        $hasH = str_contains($raw, '-') || str_contains($raw, '/') || str_contains($raw, '#');
        $hasD = (bool) preg_match('/\d/', $raw);
        $hasA = (bool) preg_match('/[a-zA-Z]/', $raw);

        // Also allow: all-digit codes ≥ 4 chars (e.g. 37871, 45353 — numeric SKUs)
        // and all-uppercase alpha tokens (e.g. DELIVERY, RAMS, FIRST FIX, O&M).
        $allDigit = ctype_digit($raw);
        // Allow ALL-CAPS service codes (e.g. DELIVERY, RAMS, FIRSTFIX, WALLMOUNT.PTC,
        // FIRST FIX, O&M, YAM-RM-CR).  Lowercase letters are explicitly excluded so
        // that normal English words like "Year", "Service", "Contract" — which
        // Strategy B of extractPartFromDescription may extract from a description
        // trailing token — are rejected.  Genuine QuoteWerks service codes are always
        // fully uppercase.
        $allUpperAlpha = (bool) preg_match('/^[A-Z][A-Z0-9\-\.\/&# ]{1,30}$/', $raw);

        // No-digit values must be all-caps to avoid false positives from
        // mixed-case description phrases leaking into part-number extraction.
        if (! $hasD && ! $allUpperAlpha) {
            return '';
        }

        // Dot-separated numeric part numbers (e.g. 910.1995.900) — has dots and
        // digits but no alpha chars. Accept when the value contains at least one
        // dot and at least one digit and is not purely integer (which is covered
        // by $allDigit above).
        $hasDot       = str_contains($raw, '.');
        $dotNumeric   = $hasDot && $hasD && ! $hasA && ! $allDigit;

        return (
            $hasH
            || ($hasD && $hasA)
            || ($allDigit && strlen($raw) >= 4)
            || $allUpperAlpha
            || $dotNumeric
        ) ? $raw : '';
    }

    private function cleanSectionTitle(string $title): string
    {
        $title = trim((string) preg_replace('/\s+/', ' ', $title));
        $title = trim($title, " \t\n\r\0\x0B-–—:;,.()[]{}");

        return $title === '' ? '' : $this->normalise($title, 80);
    }

    private function isPlausibleSectionTitle(string $title): bool
    {
        if ($title === '') {
            return false;
        }
        if (! preg_match('/[a-zA-Z]{2,}/', $title)) {
            return false;
        }
        if (strlen($title) < 3 || strlen($title) > 80) {
            return false;
        }
        if (preg_match('/\b(?:ship\s+to|internal\s+rams|quote|prepared\s+by|part\s*number|'
            . 'description|qty|buy|sell|total|united\s+kingdom|england|scotland|wales)\b/i', $title)) {
            return false;
        }
        if (preg_match('/\+?\d[\d\-\s()]{7,}\d/', $title)) {
            return false;
        }
        if (preg_match('/\b[A-Z]{1,2}\d{1,2}[A-Z]?\s+\d[A-Z]{2}\b/i', $title)) {
            return false;
        }
        if (preg_match('/^[A-Z0-9\-\/]{6,}$/', $title) && preg_match('/\d/', $title)) {
            return false;
        }

        return true;
    }

    /**
     * Return true when the section title is a known QuoteWerks document-structure
     * header rather than an actual room/space.
     *
     * The QuoteWerks template reuses OVERVIEWTITLE blocks for both room narratives
     * AND document-organisational headings (the trailing "Summary" footer, the
     * "Hardware"/"Services" equipment-section labels, etc.). The latter must not
     * pollute room_overviews — they're not rooms, just structural labels.
     *
     * Internal whitespace is collapsed before comparison so column-layout PDF
     * extractions like "Su mmary" still match "summary".
     */
    private function isNonRoomSectionTitle(string $title): bool
    {
        // Whitespace is removed (not just collapsed) before comparison so column-
        // layout PDF extractions like "Su mmary" / "Pro fessional Services" still
        // match. Real multi-word room names ("Service Yard", "Hardware Room") are
        // unaffected because they don't reduce to a filter keyword once spaces
        // are removed.
        static $nonRoomTitles = [
            'summary',
            'hardware',
            'cables',
            'consumables',
            'services',
            'professionalservices',
            'notes',
            'terms',
            'termsandconditions',
            'generalterms',
        ];

        $normalised = strtolower((string) preg_replace('/\s+/', '', trim($title)));
        return in_array($normalised, $nonRoomTitles, true);
    }

    /**
     * Merge and de-duplicate section text chunks while preserving line order.
     */
    private function normaliseSectionText(array $chunks): string
    {
        $lines = [];
        $seen  = [];

        foreach ($chunks as $chunk) {
            foreach (preg_split('/\r?\n/', (string) $chunk) as $line) {
                $line = trim((string) preg_replace('/\s+/', ' ', $line));
                if ($line === '') {
                    continue;
                }
                $key = strtolower($line);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $lines[]    = $line;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Extract the section title from the text immediately preceding an
     * OVERVIEWTITLESTART tag.
     *
     * In column-layout PDF extractions the title text (e.g. "Reception") appears
     * on the line BEFORE the OVERVIEWTITLESTART token, not between the tag pair.
     * We scan backwards from the end of $textBefore, skipping blank lines, known
     * tag tokens, column-header labels, and noise, and return the first meaningful
     * line found.
     *
     * Returns '' when nothing useful precedes the tag.
     */
    private function extractTitleFromPreceding(string $textBefore): string
    {
        static $skipPattern = null;
        if ($skipPattern === null) {
            $tokens = [
                'OVERVIEWTXTSTART', 'OVERVIEWTXTEND', 'OVERVIEWTITLESTART', 'OVERVIEWTITLEEND',
                'PARTSTART', 'PARTEND', 'PARTDESCSTART', 'PARTDESCEND', 'QTYSTART', 'QTYEND', 'QTVEND',
                'SITENAMESTART', 'SITENAMEEND', 'PREPAREDBYSTART', 'PREPAREDBYEND',
                'QUOTENUMSTART', 'QUOTENUMEND', 'SHIPCONTSTART', 'SHIPCONTEND',
                'SHIPPHONESTART', 'SHIPPHONEEND', 'SHIPEMAILSTART', 'SHIPEMAILEND',
                'SHIPCOMPSTART', 'SHIPCOMPEND', 'SHIPADDSTART', 'SHIPADDEND',
            ];
            $skipPattern = '/\b(?:' . implode('|', $tokens) . ')\b/i';
        }

        // Column-header and noise lines that should never be mistaken for a title.
        static $noisePattern = '/^(?:INTERNAL\s+RAMS|SUPPLIER|QTY|DESCRIPTION|PART\s*NUMBER|'
            . 'BUY\s*[£$]?|SELL\s*[£$]?|TOTAL|SHIP\s+TO|PAGE\s+\d|\d+\s+of\s+\d+)$/i';

        $lines = preg_split('/\r?\n/', $textBefore);

        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim(preg_replace('/\p{Zs}/u', ' ', $lines[$i]));
            if ($line === '') {
                continue;
            }
            if (preg_match($skipPattern, $line)) {
                continue;
            }
            if (preg_match($noisePattern, $line)) {
                continue;
            }
            // Must contain at least two consecutive alphabetic characters.
            if (! preg_match('/[a-zA-Z]{2,}/', $line)) {
                continue;
            }
            // Reject obvious address/contact lines that can appear before
            // OVERVIEWTITLESTART in column-layout OCR output.
            if (preg_match('/\b(?:united\s+kingdom|england|scotland|wales)\b/i', $line)) {
                continue;
            }
            if (preg_match('/\b[A-Z]{1,2}\d{1,2}[A-Z]?\s+\d[A-Z]{2}\b/i', $line)) {
                continue;
            }
            if (preg_match('/\+?\d[\d\-\s()]{7,}\d/', $line)) {
                continue;
            }
            if (preg_match('/\b(?:retford|nottinghamshire)\b/i', $line)) {
                continue;
            }
            return $line;
        }

        return '';
    }

    /**
     * Extract and clean the physical installation address from the
     * SHIPADDSTART … SHIPADDEND tag pair.
     *
     * The block may contain several unwanted fragments due to column-layout
     * PDF extraction:
     *   - The quote reference number (e.g. 21CQ28863-05-OPS) — skipped.
     *   - Inline QUOTENUMSTART / QUOTENUMEND tokens — stripped.
     *   - Page numbers or "N of M" lines — skipped.
     *
     * Returns a comma-joined address string, or '' when the tag is absent or
     * the result is too short to be useful.
     */
    private function extractTaggedSiteAddress(string $rawText): string
    {
        // Re-extract in multiline mode to preserve line-break structure.
        if (! preg_match('/SHIPADDSTART\s*(.*?)\s*SHIPADDEND/s', $rawText, $m)) {
            return '';
        }

        $raw   = $m[1];
        $lines = preg_split('/\r?\n/', $raw);
        $parts = [];

        // Any line containing a structural tag token is header noise — skip it.
        static $addrTagPattern = '/\b(?:QUOTENUMSTART|QUOTENUMEND|SITENAMESTART|SITENAMEEND|'
            . 'PREPAREDBYSTART|PREPAREDBYEND|SHIPCONTSTART|SHIPCONTEND|SHIPPHONESTART|SHIPPHONEEND|'
            . 'SHIPEMAILSTART|SHIPEMAILEND|SHIPCOMPSTART|SHIPCOMPEND|SHIPADDSTART|SHIPADDEND|'
            . 'OVERVIEWTITLESTART|OVERVIEWTITLEEND|OVERVIEWTXTSTART|OVERVIEWTXTEND|'
            . 'PARTSTART|PARTEND|PARTDESCSTART|PARTDESCEND|QTYSTART|QTYEND|QTVEND)\b/i';

        // Column-header and price labels that appear in the PDF table header.
        static $addrNoisePattern = '/^(?:INTERNAL\s+RAMS|SUPPLIER|QTY|DESCRIPTION|PART\s*NUMBER|'
            . 'BUY\s*[£$€]?|SELL\s*[£$€]?|TOTAL|SHIP\s+TO|\d+\s+of\s+\d+)$/i';

        foreach ($lines as $line) {
            $line = preg_replace('/\p{Zs}/u', ' ', $line);
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // Skip any line containing a structural tag token.
            if (preg_match($addrTagPattern, $line)) {
                continue;
            }

            // Skip column-header / noise lines.
            if (preg_match($addrNoisePattern, $line)) {
                continue;
            }

            // Skip lines that ARE entirely a quote reference number.
            // Also strip a leading ref prefix when the ref is concatenated with the first
            // address line (Layout B PDFs): "21CQ30036-01-OPS Queens Road" → "Queens Road".
            if (preg_match('/^(?:\d{1,3})?[A-Z]{1,4}\d{3,}[A-Z0-9\-]*$/i', $line)) {
                continue;
            }
            $line = (string) preg_replace('/^(?:\d{1,3})?[A-Z]{1,4}\d{3,}[A-Z0-9\-]*\s+/i', '', $line);
            if ($line === '') {
                continue;
            }

            // Skip page-number lines ("1 of 25", etc.).
            if (preg_match('/^\d+\s+of\s+\d+$/i', $line)) {
                continue;
            }

            // Skip contact lines inside the ship-address block:
            // "Rich -0771 8386409 (Site)" / "Tel: ..." / "Mob: ..."
            if (preg_match('/\(\s*site\s*\)/i', $line)) {
                continue;
            }
            if (preg_match('/\b(?:tel|phone|mobile|mob)\b/i', $line)) {
                continue;
            }
            if (preg_match('/\+?\d[\d\-\s()]{7,}\d/', $line)) {
                continue;
            }

            // Must contain at least two consecutive alphabetic characters.
            if (! preg_match('/[a-zA-Z]{2,}/', $line)) {
                continue;
            }

            $parts[] = $line;

            // Stop collecting once we hit a line that contains a postcode-style
            // pattern (e.g. "SE15 2HP London").  Everything after the postcode
            // line is table-header / section-title noise from adjacent columns.
            if (preg_match('/\b[A-Z]{1,2}\d{1,2}[A-Z]?\s+\d[A-Z]{2}\b/i', $line)) {
                break;
            }
        }

        if (empty($parts)) {
            return '';
        }

        $address = $this->normalise(implode(', ', $parts), 250);
        return strlen($address) >= 5 ? $address : '';
    }

    /**
     * Extract site name from SITENAME tag pair or surrounding lines.
     *
     * Two pdftotext layout variants exist for the same QuoteWerks template:
     *
     *   Layout A (e.g. Volkswagen): tag content = other markers ("SHIPCONTSTART SHIPCONTEND")
     *     → Strip markers from content; if nothing real remains, use SHIPCOMP company tag.
     *
     *   Layout B (e.g. Marubeni): tag content is empty — content appears on the line
     *     BEFORE the tag markers in the pdftotext output.
     *     → Use extractLineBeforeTag('SITENAMESTART'); if that is also empty, fall back
     *       to SHIPCOMP company tag.
     */
    private function extractTaggedSiteName(string $rawText): string
    {
        $value = ltrim($this->extractTagContent($rawText, 'SITENAMESTART', 'SITENAMEEND'), " \t~");

        if ($value !== '') {
            // Layout A: tag had content — strip other markers that bled in from adjacent
            // columns.  If anything real survives use it; otherwise fall through to company.
            $stripped = (string) preg_replace('/\b[A-Z]{3,}(?:START|END)\b/i', '', $value);
            $stripped = trim((string) preg_replace('/\s{2,}/', ' ', $stripped));

            if ($stripped !== '') {
                return rtrim($this->normalise($stripped, 80), ' -–—');
            }

            // Tag had content but it was all markers → try the line before the tag.
            $before = $this->extractLineBeforeTag($rawText, 'SITENAMESTART');
            if ($before !== '') {
                $cleaned = (string) preg_replace('/\b[A-Z]{3,}(?:START|END)\b/i', '', $before);
                $cleaned = trim((string) preg_replace('/\s{2,}/', ' ', $cleaned));
                if (($site = $this->splitSiteNameLine($cleaned)) !== '') {
                    return $site;
                }
            }

            $company = $this->extractTaggedCompanyName($rawText);
            if ($company !== '') {
                return $company;
            }
        } else {
            // Layout B/C: tag was truly empty — content precedes the tag in this PDF.
            $before = $this->extractLineBeforeTag($rawText, 'SITENAMESTART');
            if ($before !== '') {
                $cleaned = (string) preg_replace('/\b[A-Z]{3,}(?:START|END)\b/i', '', $before);
                $cleaned = trim((string) preg_replace('/\s{2,}/', ' ', $cleaned));
                if (($site = $this->splitSiteNameLine($cleaned)) !== '') {
                    return $site;
                }
            }

            $company = $this->extractTaggedCompanyName($rawText);
            if ($company !== '') {
                return $company;
            }
        }

        // Final fallback: line before tag (catches any remaining layout variants).
        $before = $this->extractLineBeforeTag($rawText, 'SITENAMESTART');
        if ($before !== '') {
            return rtrim($this->normalise($before, 80), ' -–—');
        }

        return '';
    }

    /**
     * Given a cleaned line from before SITENAMESTART, return the site name portion.
     *
     * QuoteWerks header lines have two formats:
     *   A) "SiteName - ContactFirstName ContactSurname"
     *      → split on " - "; keep left part (site name).
     *      → Only split when the right part is 1–2 words with no digits and no
     *        location/venue keywords — indicating a person name, not a place suffix.
     *   B) "CompanyName Location" or full site name with no " - "
     *      → return the whole cleaned line.
     *
     * Returns '' when the line is empty or unusable.
     */
    private function splitSiteNameLine(string $cleaned): string
    {
        $cleaned = rtrim($cleaned, ' -–—');
        if ($cleaned === '') {
            return '';
        }

        if (str_contains($cleaned, ' - ')) {
            $left  = rtrim(trim((string) strstr($cleaned, ' - ', true)), ' -–—');
            $right = ltrim(trim((string) substr($cleaned, strpos($cleaned, ' - ') + 3)), ' -–—');

            // Right part looks like a person name when: 1–2 words, no digits,
            // no venue/location keywords.
            $rightWords = array_filter(explode(' ', $right), fn ($w) => $w !== '');
            $rightIsPersonName = count($rightWords) >= 1
                && count($rightWords) <= 2
                && ! preg_match('/\d/', $right)
                && ! preg_match('/\b(?:room|office|floor|building|house|suite|centre|center|meeting|reception|hall|unit|block)\b/i', $right);

            if ($rightIsPersonName && $left !== '') {
                return $this->normalise($left, 80);
            }

            // Right part is not a person name — treat the whole line as the site name.
            return $this->normalise($cleaned, 80);
        }

        return $this->normalise($cleaned, 80);
    }

    /**
     * Extract client/company from SHIPCOMP tag pair or line before SHIPCOMPSTART.
     *
     * Some QuoteWerks fonts garble SHIPCOMPEND to "sypcompEND" (S→s, HI→y, etc.).
     * We try both the exact tag and a fuzzy-end-tag pattern to stay robust.
     */
    private function extractTaggedCompanyName(string $rawText): string
    {
        $value = $this->extractTagContent($rawText, 'SHIPCOMPSTART', 'SHIPCOMPEND');
        // Strip leading tilde — the V1 QuoteWerks template uses "~" as a column
        // separator and it can bleed into the SHIPCOMPSTART content block.
        $value = ltrim($value, " \t~");
        if ($value !== '' && $this->isPlausibleName($value)) {
            return $this->normalise($value, 80);
        }

        // Fuzzy fallback: the SHIPCOMPEND tag can be garbled by font-encoding
        // to variants such as "sypcompEND", "sHIPCOMPEND", etc.
        // Match from SHIPCOMPSTART up to any token that ends in "compEND" (case-insensitive).
        if (preg_match('/SHIPCOMPSTART\s*(.*?)\s*\w*compEND\b/is', $rawText, $fm)) {
            $candidate = ltrim(trim((string) ($fm[1] ?? '')), " \t~");
            if ($candidate !== '' && $this->isPlausibleName($candidate)) {
                return $this->normalise($candidate, 80);
            }
        }

        $before = $this->extractLineBeforeTag($rawText, 'SHIPCOMPSTART');
        if ($before !== '') {
            // Strip any QuoteWerks marker tokens that bled in from the adjacent column
            // (e.g. "PREPAREDBYSTART PREPAREDBYEND Marubeni Europe Plc" → "Marubeni Europe Plc")
            $cleanedBefore = (string) preg_replace('/\b[A-Z]{3,}(?:START|END)\b/i', '', $before);
            $cleanedBefore = trim((string) preg_replace('/\s{2,}/', ' ', $cleanedBefore));
            if ($cleanedBefore !== '' && $this->isPlausibleName($cleanedBefore)) {
                return $this->normalise($cleanedBefore, 80);
            }
        }

        return '';
    }

    /**
     * Use the ship-email local part as prepared-by fallback, e.g. "jane.doe".
     *
     * SAFETY GUARD: SHIPEMAIL is the *client* contact email, not 21CAV. Using
     * its local part as "Prepared By" puts the client's name on the RAMS doc,
     * which is wrong (it should be the 21CAV preparer). Only honour this
     * fallback when the email's domain is unambiguously a 21CAV-owned domain;
     * otherwise return empty and let the upstream code fall back to the
     * project owner / authenticated user.
     */
    private function extractPreparedByFromShipEmail(string $rawText): string
    {
        if (! preg_match('/SHIPEMAILSTART\s*([A-Z0-9._%+\-]+)@([A-Z0-9.\-]+)/i', $rawText, $m)) {
            return '';
        }

        $local  = strtolower(trim((string) ($m[1] ?? '')));
        $domain = strtolower(trim((string) ($m[2] ?? '')));
        if ($local === '' || $domain === '') {
            return '';
        }

        // Hard whitelist — only treat the SHIPEMAIL as the preparer when the
        // domain is one of 21CAV's own. Any third-party domain is the client.
        $cavDomains = ['21stcenturyav.com', '21stcav.com'];
        if (! in_array($domain, $cavDomains, true)) {
            return '';
        }

        $skip = ['info', 'sales', 'support', 'accounts', 'noreply', 'admin'];
        if (in_array($local, $skip, true)) {
            return '';
        }

        $parts = array_values(array_filter(
            preg_split('/[._\-]+/', $local) ?: [],
            fn (string $p): bool => strlen($p) >= 2 && preg_match('/[a-z]/', $p)
        ));

        if (count($parts) === 1) {
            $single = $parts[0];
            $commonFirstNames = [
                'michael', 'stephen', 'andrew', 'daniel', 'thomas', 'martin', 'robert',
                'jordan', 'james', 'david', 'sarah', 'shaun', 'harish', 'cassie',
                'john', 'paul', 'mark', 'rich', 'emma', 'anna', 'alex', 'chris',
            ];

            foreach ($commonFirstNames as $first) {
                if (str_starts_with($single, $first) && strlen($single) > strlen($first) + 2) {
                    $parts = [$first, substr($single, strlen($first))];
                    break;
                }
            }
        }

        if (count($parts) === 0 || count($parts) > 4) {
            return '';
        }

        $name = implode(' ', array_map(
            fn (string $p): string => ucfirst($p),
            $parts
        ));

        return $this->isPlausibleName($name) ? $this->normalise($name, 80) : '';
    }

    /**
     * Return first meaningful line immediately before a tag (e.g. SHIPCOMPSTART).
     */
    private function extractLineBeforeTag(string $rawText, string $tag): string
    {
        $pattern = '/(?:^|\r?\n)\s*([^\r\n]{2,200})\s*\r?\n\s*' . preg_quote($tag, '/') . '\b/i';
        if (! preg_match($pattern, $rawText, $m)) {
            return '';
        }

        $candidate = trim((string) $m[1]);
        if ($candidate === '') {
            return '';
        }

        if (preg_match('/\b(?:PARTSTART|PARTEND|PARTDESCSTART|PARTDESCEND|QTYSTART|QTYEND|OVERVIEW)/i', $candidate)) {
            return '';
        }

        return $candidate;
    }

    private function extractFirstNumericValue(string $raw): float
    {
        if (! preg_match('/(\d+(?:\.\d+)?)/', $raw, $m)) {
            return 0.0;
        }

        $numStr = $m[1];

        // Smalot sometimes drops the decimal point when extracting from certain
        // PDF fonts, turning "1.00" into "100", "26.00" into "2600", etc.
        // If the extracted string has no decimal point and ends in "00", recover
        // the original value by dividing by 100.
        if (! str_contains($numStr, '.') && preg_match('/^(\d+?)00$/', $numStr, $dm) && $dm[1] !== '') {
            return (float) $dm[1];
        }

        return (float) $numStr;
    }

    /**
     * Parse OCR-mixed PARTDESC block that may contain qty + part and little/no description.
     *
     * @return array{0:float,1:string,2:string} [qty, partNumber, description]
     */
    private function parsePartDescComposite(string $raw): array
    {
        if ($raw === '') {
            return [0.0, '', ''];
        }

        $raw = (string) preg_replace(
            '/\b(?:PARTDESCEND|PARTDESCSTART|QTYSTART|QTYEND|QTVEND|PARTSTART|PARTEND)\b/i',
            ' ',
            $raw
        );

        $qty = 0.0;
        $part = '';
        $descParts = [];

        $lines = preg_split('/\r?\n/', (string) $raw);
        if (! is_array($lines)) {
            $lines = [$raw];
        }

        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            if ($qty <= 0.0 && preg_match('/^\d+(?:\.\d+)?$/', $line)) {
                $qty = (float) $line;
                continue;
            }

            // OCR can emit qty + part (+ optional description) on the same line.
            if (preg_match('/^(\d+(?:\.\d+)?)\s+([A-Za-z0-9][A-Za-z0-9\-\.\/]{2,49})(?:\s+(.+))?$/', $line, $m)) {
                if ($qty <= 0.0) {
                    $qty = (float) $m[1];
                }
                if ($part === '') {
                    $pn = $this->normaliseTaggedPartNumber($m[2]);
                    if ($pn !== '') {
                        $part = $pn;
                    }
                }
                $tail = trim((string) ($m[3] ?? ''));
                if ($tail !== '') {
                    $descParts[] = $tail;
                }
                continue;
            }

            // Only test single-token lines as part numbers — multi-word lines
            // (e.g. "O&M Manual", "25m x 25mm sock") are always descriptions.
            // Without this guard, normaliseTaggedPartNumber strips spaces first,
            // turning "O&M Manual" → "O&MManual" which wrongly passes validation.
            if ($part === '' && ! preg_match('/\s/', $line)) {
                $pn = $this->normaliseTaggedPartNumber($line);
                if ($pn !== '') {
                    $part = $pn;
                    continue;
                }
            }

            $descParts[] = $line;
        }

        $desc = trim(preg_replace('/\s+/', ' ', implode(' ', $descParts)));

        return [$qty, $part, $desc];
    }

    private function looksLikePartAndQtyOnly(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return true;
        }

        if (preg_match('/^\d+(?:\.\d+)?\s+[A-Za-z0-9][A-Za-z0-9\-\.\/]{2,49}$/', $value)) {
            return true;
        }

        if ($this->normaliseTaggedPartNumber($value) !== '' && ! preg_match('/\s/', $value)) {
            return true;
        }

        return false;
    }

    /**
     * In some QuoteWerks OCR layouts the description appears after QTYEND.
     */
    private function extractDescriptionAfterTuple(string $rawText, int $fromOffset, array $allPartStartOffsets): string
    {
        $end = strlen($rawText);

        foreach ($allPartStartOffsets as $pos) {
            if ($pos > $fromOffset) {
                $end = min($end, $pos);
                break;
            }
        }

        if (preg_match('/OVERVIEWTITLESTART/i', $rawText, $m, PREG_OFFSET_CAPTURE, $fromOffset)) {
            $end = min($end, $m[0][1]);
        }

        $chunk = substr($rawText, $fromOffset, max(0, $end - $fromOffset));
        if ($chunk === false || trim($chunk) === '') {
            return '';
        }

        // 260602-mlt — EXCISE page-banner blocks instead of truncating at them.
        //
        // QuoteWerks PDFs that span a page boundary mid-description insert a
        // repeating "Page N of M" banner followed by 1-6 lines of repeated
        // tagged header noise (SHIPCONT/SHIPPHONE/SHIPCOMP/SITENAME/QUOTENUM/
        // PREPAREDBY pairs). The next PARTSTART boundary (already computed
        // above) remains the hard terminator for the description chunk.
        //
        // Cap at ONE occurrence per chunk so we never silently swallow legitimate
        // description text that happens to contain digits matching "N of M".
        $chunk = preg_replace(
            '/\r?\n[^\r\n]*\b\d+\s+of\s+\d+\b[^\r\n]*'
                . '(?:\r?\n[^\r\n]*(?:SHIPCONT|SHIPPHONE|SHIPCOMP|SHIPADD|SITENAME|QUOTENUM|PREPAREDBY)(?:START|END)[^\r\n]*){0,6}/i',
            ' ',
            $chunk,
            1
        );

        // Strip tag tokens and header noise.
        $chunk = preg_replace('/\b(?:OVERVIEWTXTSTART|OVERVIEWTXTEND|OVERVIEWTITLESTART|OVERVIEWTITLEEND|'
            . 'PARTSTART|PARTEND|PARTDESCSTART|PARTDESCEND|paARTDESCEND|QTYSTART|QTYEND|QTVEND|'
            . 'SITENAMESTART|SITENAMEEND|PREPAREDBYSTART|PREPAREDBYEND|'
            . 'QUOTENUMSTART|QUOTENUMEND|SHIPCONTSTART|SHIPCONTEND|'
            . 'SHIPPHONESTART|SHIPPHONEEND|SHIPEMAILSTART|SHIPEMAILEND|'
            . 'SHIPCOMPSTART|SHIPCOMPEND|SHIPADDSTART|SHIPADDEND)\b/i', ' ', $chunk);

        $chunk = preg_replace('/\b(?:INTERNAL\s+RAMS|PART\s*NUMBER|SUPPLIER|BUY|SELL|TOTAL|'
            . '\d+\s+of\s+\d+)\b/i', ' ', $chunk);

        $chunk = trim((string) preg_replace('/\s+/', ' ', $chunk));

        return $chunk;
    }

    /**
     * Attempt to extract a part number embedded within an equipment description.
     *
     * Called as Strategy 3 when both the PARTSTART tag content and the
     * preceding-line lookup returned nothing.  Mirrors the heuristic path's
     * parenthetical and trailing-token detection:
     *
     *   Trailing parenthetical: "Ceiling Mic (CM20)"  → part_number = CM20
     *   Trailing token:         "Ceiling Mic CM20"    → part_number = CM20
     *
     * Returns '' when no valid part number can be extracted or when the
     * description contains an "e.g." placeholder indicator.
     */
    private function extractPartNumFromDescription(string $desc): string
    {
        // Placeholder guard — never extract part numbers from example text.
        if (preg_match('/\be\.?\s*g\.?\b/i', $desc)) {
            return '';
        }

        // Strategy A: trailing parenthetical "(PARTNUM)"
        if (preg_match('/^(.{3,}?)\s*\(([A-Za-z0-9][A-Za-z0-9\-\.]{2,29})\)\s*$/', $desc, $m)) {
            return $this->normaliseTaggedPartNumber($m[2]);
        }

        // Strategy B: trailing token "Description text PARTNUM"
        if (preg_match('/^(.{5,})\s+([A-Za-z0-9][A-Za-z0-9\-\.]{2,29})$/', $desc, $m)) {
            return $this->normaliseTaggedPartNumber($m[2]);
        }

        $tokens = preg_split('/[\s,\[\]()]+/', $desc) ?: [];
        for ($i = count($tokens) - 1; $i >= 0; $i--) {
            $token = trim((string) ($tokens[$i] ?? ''), "-–—:;,.!?");
            if ($token === '') {
                continue;
            }
            $pn = $this->normaliseTaggedPartNumber($token);
            if ($pn !== '') {
                return $pn;
            }
        }

        return '';
    }

    /**
     * De-duplicate tagged equipment rows produced by OCR column bleed.
     *
     * Priority:
     * - For same area+part number, keep a single row (lower qty preferred).
     * - For rows without part numbers, de-dupe by area+normalised description.
     */
    private function dedupeTaggedEquipment(array $equipment): array
    {
        $deduped = [];
        $order   = [];

        foreach ($equipment as $item) {
            $area = strtolower(trim((string) ($item['area'] ?? '')));
            $part = strtolower(trim((string) ($item['part_number'] ?? '')));
            $desc = strtolower(trim((string) preg_replace('/\s+/', ' ', (string) ($item['description'] ?? ''))));

            $descKey = preg_replace('/[^a-z0-9]+/', ' ', $desc);
            $descKey = trim((string) preg_replace('/\s+/', ' ', (string) $descKey));

            $key = $part !== ''
                ? "p|{$area}|{$part}"
                : "d|{$area}|{$descKey}";

            if (! isset($deduped[$key])) {
                $deduped[$key] = $item;
                $order[]       = $key;
                continue;
            }

            // Duplicate row in the same area — SUM the quantities. Sales
            // legitimately list the same part on multiple lines (e.g. one
            // per sub-area, per setup pass, or per quote revision), and
            // dropping rows under-counts the install. Description / location
            // merge picks the richer value across the duplicate rows.
            $existingQty = (int) ($deduped[$key]['qty'] ?? 0);
            $newQty      = (int) ($item['qty'] ?? 0);
            if ($newQty > 0) {
                $deduped[$key]['qty'] = $existingQty + $newQty;
            }

            $existingDesc = (string) ($deduped[$key]['description'] ?? '');
            $newDesc      = (string) ($item['description'] ?? '');
            if (strlen($newDesc) > strlen($existingDesc) && ! preg_match('/(?:PARTDESCEND|QTYEND|QTVEND)/i', $newDesc)) {
                $deduped[$key]['description'] = $newDesc;
            }

            if (($deduped[$key]['location'] ?? '') === '' && ($item['location'] ?? '') !== '') {
                $deduped[$key]['location'] = $item['location'];
            }
        }

        $out = [];
        foreach ($order as $key) {
            $out[] = $deduped[$key];
        }
        // Second pass: drop no-part rows that are OCR duplicates of a row with
        // a valid part number in the same area.
        $withPartByArea = [];
        foreach ($out as $row) {
            $areaKey = strtolower(trim((string) ($row['area'] ?? '')));
            $partKey = trim((string) ($row['part_number'] ?? ''));
            if ($partKey === '') {
                continue;
            }
            $descKey = strtolower((string) ($row['description'] ?? ''));
            $descKey = preg_replace('/[^a-z0-9]+/', ' ', $descKey);
            $descKey = trim((string) preg_replace('/\s+/', ' ', (string) $descKey));
            if ($descKey === '') {
                continue;
            }
            $withPartByArea[$areaKey][] = $descKey;
        }

        $filtered = [];
        foreach ($out as $row) {
            $areaKey = strtolower(trim((string) ($row['area'] ?? '')));
            $partKey = trim((string) ($row['part_number'] ?? ''));
            if ($partKey !== '') {
                $filtered[] = $row;
                continue;
            }

            $descKey = strtolower((string) ($row['description'] ?? ''));
            $descKey = preg_replace('/[^a-z0-9]+/', ' ', $descKey);
            $descKey = trim((string) preg_replace('/\s+/', ' ', (string) $descKey));

            $drop = false;
            foreach ((array) ($withPartByArea[$areaKey] ?? []) as $partDescKey) {
                if ($descKey === '' || $partDescKey === '') {
                    continue;
                }
                if (str_contains($descKey, $partDescKey) || str_contains($partDescKey, $descKey)) {
                    $drop = true;
                    break;
                }
            }

            if (! $drop) {
                $filtered[] = $row;
            }
        }

        return $filtered;
    }

    /**
     * Attempt to detect a room or area name within an equipment description.
     * Used to populate the 'location' field on extracted equipment items.
     * Returns an empty string when no room keyword is found.
     */

    /**
     * Remove page-header lines from raw PARTDESC content before whitespace collapse.
     *
     * When a part tuple spans a page break in the column-layout PDF, the page
     * header (INTERNAL RAMS, column labels, site name, page numbers, etc.) is
     * captured inside the PARTDESCSTART…PARTDESCEND block.  Strip those lines
     * so only the actual equipment description remains.
     */
    private function cleanPartDescLines(string $raw): string
    {
        static $skipPattern = '/^(?:INTERNAL\s+RAMS|PART\s*NUMBER|SUPPLIER|QTY|'
            . 'DESCRIPTION|BUY\s*[£$€]?|SELL\s*[£$€]?|TOTAL|\d+\s+of\s+\d+|'
            . 'SHIP\s+TO|PAGE\s+\d+)$/i';

        $lines  = preg_split('/\r?\n/', $raw);
        $result = [];

        foreach ($lines as $line) {
            $line = trim($line, " \t\r\n~");
            if ($line === '') {
                continue;
            }
            // A bare decimal (e.g. "1.00", "2.50") is the price/qty column
            // value that bleeds in when a PARTDESC tuple spans a page break.
            // Stop collecting here — everything after is page-header noise.
            if (preg_match('/^\d+\.\d+$/', $line)) {
                break;
            }
            if (preg_match($skipPattern, $line)) {
                continue;
            }
            $result[] = $line;
        }

        return implode("\n", $result);
    }

    private function detectRoom(string $line): string
    {
        $lower = strtolower($line);

        foreach (self::ROOM_KEYWORDS as $kw) {
            if (! str_contains($lower, $kw)) {
                continue;
            }

            $pattern = '/([A-Za-z0-9\s\-]{2,40}' . preg_quote($kw, '/') . '[A-Za-z0-9\s\-]{0,20})/i';

            if (preg_match($pattern, $line, $m)) {
                $room = trim($m[1]);
                if (strlen($room) <= 60 && preg_match('/[a-zA-Z]{2,}/', $room)) {
                    return $room;
                }
            }
        }

        return '';
    }
}
