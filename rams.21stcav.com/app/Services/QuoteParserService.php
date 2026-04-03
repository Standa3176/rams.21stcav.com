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
        '/(?:quote\s+(?:no|number|ref|reference)|q(?:uote)?\s*[#\-]?\s*no\.?)\s*[:\-]?\s*([A-Z0-9\-\/]{3,30})/i',
        '/(?:order\s+(?:no|number)|po\s+(?:no|number))\s*[:\-]?\s*([A-Z0-9\-\/]{3,30})/i',
        // Priority 2: bare 21CQ reference anywhere in the text.
        // All 21st Century AV quote numbers begin "21CQ" followed by digits,
        // optionally with alphanumeric dash-separated suffixes (e.g. 21CQ30246-06-OPS).
        '/\b(21CQ[0-9]{2,10}(?:-[A-Z0-9]+)*)\b/i',
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
        '/(?:prepared\s+by|\bauthor\b|consultant)\s*[:\-]?\s*[\r\n]*\s*([A-Za-z][a-zA-Z\'\-]+(?:\s+[A-Za-z][a-zA-Z\'\-]+){1,2})/i',
        // "Sales Person: Jordan Phillips" / "Sales Rep: ..."
        '/(?:sales\s+(?:person|rep(?:resentative)?|exec(?:utive)?))\s*[:\-]\s*[\r\n]*\s*([A-Za-z][a-zA-Z\'\-]+(?:\s+[A-Za-z][a-zA-Z\'\-]+){1,2})/i',
        // "Account Manager: Jordan Phillips"
        '/(?:account\s+manager|account\s+exec(?:utive)?)\s*[:\-]\s*[\r\n]*\s*([A-Za-z][a-zA-Z\'\-]+(?:\s+[A-Za-z][a-zA-Z\'\-]+){1,2})/i',
        // "Contact: Jordan Phillips" as last-resort fallback for prepared-by
        '/(?:your\s+(?:contact|account\s+manager)|contact\s+name)\s*[:\-]\s*[\r\n]*\s*([A-Za-z][a-zA-Z\'\-]+(?:\s+[A-Za-z][a-zA-Z\'\-]+){1,2})/i',
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
        $hasTagsDiag = $this->hasStructuredTags($rawText);
        \Illuminate\Support\Facades\Log::debug('QuoteParserService::parse', [
            'raw_length'       => strlen($rawText),
            'has_structured'   => $hasTagsDiag,
            'has_partstart'    => str_contains($rawText, 'PARTSTART'),
            'has_partdescstart'=> str_contains($rawText, 'PARTDESCSTART'),
            'has_qtystart'     => str_contains($rawText, 'QTYSTART'),
            'first_300'        => substr(preg_replace('/\s+/', ' ', $rawText), 0, 300),
        ]);

        // Structured RAMS PDF tags are present — use the reliable tag-based parser.
        // Falls back to the heuristic path below for untagged legacy PDFs.
        if ($hasTagsDiag) {
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
            'site'          => $site,
            'ref'           => $ref,
            'overview'      => $overview,
            'overview_sections' => [],
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
                if (strlen($candidate) > 3 && strlen($candidate) < 100 && ! $this->hasExcessiveSpecialChars($candidate)) {
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

                // Reject binary / encoded PDF stream fragments — these contain
                // a high proportion of special characters that never appear in
                // real addresses (e.g. `~|$%<>^;_][`).
                if ($this->hasExcessiveSpecialChars($candidate) || $this->isLikelyEncodedBlob($candidate)) {
                    if ($this->isLikelyEncodedBlob($candidate)) {
                        \Illuminate\Support\Facades\Log::warning('QuoteParserService: skipped encoded-looking site address line', [
                            'sample' => substr($candidate, 0, 80),
                        ]);
                    }
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

        return '';
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
                if (preg_match('/^[A-Za-z][A-Za-z0-9\-\.]{3,29}\s+\S.+\s[\d,]+\.\d{2}/', $trimmed)) {
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
            if (preg_match('/^[A-Za-z][A-Za-z0-9\-\.]{3,29}\s+\S.+\s[\d,]+\.\d{2}/', $t)) {
                $boundary = $i;
                break;
            }

            // e) Part-number + description row (no price required)
            //    Only checked after at least 5 lines to avoid firing on header
            //    contact/telephone lines (e.g. "Tel: 01234-567890").
            if ($i >= 5 && preg_match(
                '/^[A-Za-z][A-Za-z0-9\-\.]{3,29}\s+[A-Za-z].{3,}$/',
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

                if (preg_match('/^([A-Za-z][A-Za-z0-9\-\.]{3,29})\s+(.{4,})$/', $tmpDesc, $pm)) {
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
                if (preg_match('/^([A-Za-z][A-Za-z0-9\-\.]{3,29})\s+(.{4,})$/', $desc, $pm)) {
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
                    if (preg_match('/^(.{3,}?)\s*\(([A-Za-z][A-Za-z0-9\-\.]{2,29})\)\s*$/', $desc, $pm)) {
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
                    if (preg_match('/^(.{5,})\s+([A-Za-z][A-Za-z0-9\-\.]{2,29})$/', $desc, $pm)) {
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

            // Must be 2–3 space-separated words.
            $words = array_filter(explode(' ', $name), fn ($w) => $w !== '');
            if (count($words) < 2 || count($words) > 3) {
                continue;
            }

            // Reject ALL-CAPS strings (headings, not names).
            if ($name === strtoupper($name) && preg_match('/[A-Z]/', $name)) {
                continue;
            }

            return $name;
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
     *   - Matches [A-Za-z][A-Za-z0-9\-\.]{3,29}  (4–30 chars)
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

        if (! preg_match('/^([A-Za-z][A-Za-z0-9\-\.]{3,29})$/', $trimmed, $m)) {
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
            && ! preg_match('/^\d+[\.,]?\d*$/', $value)      // not purely numeric
            && ! $this->hasExcessiveSpecialChars($value);    // not binary PDF noise
    }

    /**
     * Returns true when more than 25% of a string's characters are "special"
     * (i.e. not alphanumeric, whitespace, or common punctuation found in normal
     * addresses and company names).
     *
     * This filters binary / encoded PDF stream fragments that slip through
     * smalot/pdfparser and the raw stream extractor. Real addresses and client
     * names rarely exceed ~10% special characters; garbled PDF data routinely
     * exceeds 40%.
     */
    private function hasExcessiveSpecialChars(string $value): bool
    {
        $length = strlen($value);
        if ($length === 0) {
            return false;
        }

        // Characters that are perfectly normal in addresses and company names.
        // Everything else is counted as "special".
        // Threshold of 15%: real names/addresses rarely exceed ~5%; binary PDF
        // stream fragments routinely run 20–45%.
        $specialCount = preg_match_all('/[^a-zA-Z0-9\s\.,\-\'\"\/\(\)\&\:\#\@\+\!\?\;\=]/', $value);

        return ($specialCount / $length) > 0.10;
    }

    /**
     * Return true when a line looks like a base64/base64url blob.
     *
     * These can slip past the special-character filter because they are
     * mostly alphanumeric, but they are never valid address lines.
     */
    private function isLikelyEncodedBlob(string $value): bool
    {
        $trimmed = trim($value);

        // Short tokens are more likely to be real words/labels.
        if (strlen($trimmed) < 40) {
            return false;
        }

        // A genuine address line will almost always contain spaces.
        if (preg_match('/\s/', $trimmed)) {
            return false;
        }

        // Base64 / base64url character sets (allowing '=' padding).
        if (preg_match('/^[A-Za-z0-9+\/=]+$/', $trimmed)) {
            return true;
        }
        if (preg_match('/^[A-Za-z0-9\-_]+$/', $trimmed)) {
            return true;
        }

        return false;
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
        // Require word-boundary tags and a plausible tuple sequence to avoid
        // false positives from binary/base64 streams that happen to contain
        // the tag strings.
        if (! preg_match('/\bPARTSTART\b/', $rawText)) {
            return false;
        }
        if (! preg_match('/\bPARTDESCSTART\b/', $rawText)) {
            return false;
        }
        if (! preg_match('/\bQTYSTART\b/', $rawText)) {
            return false;
        }

        // At least one header tag should exist in real structured output.
        if (! preg_match('/\b(?:SHIPADDSTART|SHIPCOMPSTART|SITENAMESTART)\b/', $rawText)) {
            return false;
        }

        // Ensure the tags appear in a realistic order within a small window.
        return (bool) preg_match(
            '/\bPARTSTART\b[\s\S]{0,2000}\bPARTDESCSTART\b[\s\S]{0,2000}\bQTYSTART\b/',
            $rawText
        );
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

        // SITENAMESTART…SITENAMEEND spans the entire page header in column-layout
        // PDFs, so we take only the FIRST non-empty, non-tag line after the opening
        // tag rather than collapsing all content between the tags.
        //
        // Priority 0: SHIPCOMPSTART — the explicit "Ship To: Company" field.
        //
        // QuoteWerks PDFs embed this differently depending on pdftotext mode:
        //
        //   -layout mode: value is between the tags on the same line:
        //     SHIPCOMPSTART   Western Digital UK Limited   SHIPCOMPEND
        //
        //   -raw mode (used by QuoteTextExtractorService): value may be
        //     (a) on the same line between the tags but mid-line:
        //         PREPAREDBYEND ... SHIPCOMPSTART   Company Ltd   SHIPCOMPEND
        //     (b) on the line immediately after SHIPCOMPEND:
        //         SHIPCOMPSTART SHIPCOMPEND
        //         Western Digital UK Limited
        //
        $client = '';

        // Strategy A: content between tags on the same line (layout mode and some raw).
        // Uses no /s flag so . does not cross line boundaries.
        if (preg_match('/SHIPCOMPSTART\s+(\S[^\r\n]*?\S)\s+SHIPCOMPEND/', $rawText, $scm)) {
            $scLine = trim(preg_replace('/\s+/', ' ', $scm[1]));
            if (
                $scLine !== ''
                && str_word_count($scLine) <= 8
                && ! preg_match('/[A-Z]{4,}(?:START|END)/', $scLine)
                && ! $this->hasExcessiveSpecialChars($scLine)
            ) {
                $client = $this->normalise($scLine, 100);
            }
        }

        // Strategy B: content on line immediately after SHIPCOMPEND (raw mode variant b).
        if ($client === '') {
            if (preg_match('/SHIPCOMPSTART[ \t]*SHIPCOMPEND[ \t]*\r?\n[ \t]*(.+)/m', $rawText, $bcm)) {
                $bcLine = trim(preg_replace('/\s+/', ' ', $bcm[1]));
                if (
                    $bcLine !== ''
                    && str_word_count($bcLine) <= 8
                    && ! preg_match('/[A-Z]{4,}(?:START|END)/', $bcLine)
                    && ! str_contains($bcLine, '@')
                ) {
                    $client = $this->normalise($bcLine, 100);
                }
            }
        }

        // Strategy C: pdftotext -raw mode — the company name appears on the same
        // line as the PREPAREDBYSTART PREPAREDBYEND tag pair, immediately after
        // PREPAREDBYEND.  This happens because in -raw column output QuoteWerks
        // places SHIPCOMP and PREPAREDBY tags on the same horizontal row, and
        // pdftotext -raw collapses them onto one line:
        //
        //   Jordan Phillips
        //   PREPAREDBYSTART PREPAREDBYEND Western Digital UK Limited
        //   SHIPCOMPSTART SHIPCOMPEND
        //
        // SHIPCOMPSTART…SHIPCOMPEND is empty in this format (Strategy A/B yield
        // nothing), so we fall back to extracting the text that follows PREPAREDBYEND.
        if ($client === '') {
            if (preg_match('/PREPAREDBYSTART[ \t]*PREPAREDBYEND[ \t]+(.+)$/m', $rawText, $ccm)) {
                $ccLine = trim(preg_replace('/\s+/', ' ', $ccm[1]));
                if (
                    $ccLine !== ''
                    && str_word_count($ccLine) <= 8
                    && ! preg_match('/[A-Z]{4,}(?:START|END)/', $ccLine)
                    && ! str_contains($ccLine, '@')
                ) {
                    $client = $this->normalise($ccLine, 100);
                }
            }
        }

        // Priority 1: OVERVIEWTXTSTART…SHIPCONTSTART — in QuoteWerks pdftotext
        // column-layout output the company/organisation name often appears here.
        // Only used when SHIPCOMPSTART did not yield a result.
        if ($client === '' && preg_match('/OVERVIEWTXTSTART\s*(.*?)\s*SHIPCONTSTART/s', $rawText, $otm)) {
            $otTagRe = '/\b(?:SHIPCONTSTART|SHIPCONTEND|SHIPPHONESTART|SHIPPHONEEND|'
                . 'SHIPEMAILSTART|SHIPEMAILEND|SHIPCOMPSTART|SHIPCOMPEND|'
                . 'SHIPADDSTART|SHIPADDEND|SITENAMESTART|SITENAMEEND|'
                . 'OVERVIEWTITLESTART|OVERVIEWTITLEEND|OVERVIEWTXTSTART|OVERVIEWTXTEND|'
                . 'PREPAREDBYSTART|PREPAREDBYEND|QUOTENUMSTART|QUOTENUMEND|'
                . 'PARTSTART|PARTEND|PARTDESCSTART|PARTDESCEND|QTYSTART|QTYEND)\b/';
            foreach (preg_split('/\r?\n/', $otm[1]) as $otLine) {
                $otLine = trim(preg_replace('/\s+/', ' ', $otLine));
                if ($otLine === '') continue;
                if (preg_match($otTagRe, $otLine)) continue;
                // Skip prose sentence starters (articles, pronouns, verbs common in overview text).
                if (preg_match('/^(?:A |An |The |Each |This |These |There |All |One |Our |We |It |In |For |With |When |Note)/i', $otLine)) continue;
                // Skip lines ending with a full stop — always a sentence, never a company name.
                if (str_ends_with(rtrim($otLine), '.')) continue;
                // Skip lines that are clearly prose/description (more than 6 words).
                if (str_word_count($otLine) > 6) continue;
                // Skip lines that look like product/part-number SKU codes:
                // all-uppercase alphanumeric (+ hyphens/dots), no spaces, has both
                // letters and digits, length > 7 — e.g. "LH65QETELGCXEN", "CH-MTM1U".
                // Real company names are either short acronyms (< 8 chars) or have spaces.
                if (
                    preg_match('/^[A-Z0-9][A-Z0-9\-\.]{6,}$/i', $otLine)
                    && preg_match('/[0-9]/', $otLine)
                    && preg_match('/[A-Z]/i', $otLine)
                ) {
                    continue;
                }
                // Skip lines containing a forward slash — product spec strings use
                // "/" as a separator (e.g. "UHD/4K", "16:9/4:3") but company names
                // almost never do.
                if (str_contains($otLine, '/')) continue;
                // Skip lines containing a standalone number (pure digits surrounded
                // by spaces or at line edges) — characteristic of product descriptions
                // (sizes, year refs) not company names.
                if (preg_match('/(?:^|\s)\d+(?:\s|$)/', $otLine)) continue;
                // Skip lines containing common AV / electronics spec abbreviations
                // that are very unlikely to appear in a company name.
                if (preg_match('/\b(?:UHD|FHD|QHD|OLED|QLED|LCD|LED|4K|8K|HDR|Hz|kHz|MHz|USB|HDMI|SDI|VGA|DP|DVI|QE\d|NanoCell|QNED)\b/i', $otLine)) continue;
                $client = $this->normalise($otLine, 100);
                break;
            }
        }

        // Fallback: SITENAMESTART tag content.
        if ($client === '') {
            if (preg_match('/SITENAMESTART\s*(.*?)\s*SITENAMEEND/s', $rawText, $snm)) {
                $allTagRe = '/\b(?:PREPAREDBYSTART|PREPAREDBYEND|QUOTENUMSTART|QUOTENUMEND|'
                    . 'SHIPCONTSTART|SHIPCONTEND|SHIPPHONESTART|SHIPPHONEEND|'
                    . 'SHIPEMAILSTART|SHIPEMAILEND|SHIPCOMPSTART|SHIPCOMPEND|'
                    . 'SHIPADDSTART|SHIPADDEND|SITENAMESTART|SITENAMEEND)\b/';
                foreach (preg_split('/\r?\n/', $snm[1]) as $snLine) {
                    $snLine = trim(preg_replace('/\s+/', ' ', $snLine));
                    if ($snLine === '' || preg_match($allTagRe, $snLine)) {
                        continue;
                    }
                    $client = rtrim($this->normalise($snLine, 80), ' -–—');
                    break;
                }
            }
        }

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
                    if (preg_match($pbTagRe,   $pbLine)) continue;
                    if (preg_match($pbNoiseRe, $pbLine)) continue;
                    if (preg_match('/^[A-Z0-9][A-Z0-9\-\/]{3,}$/i', $pbLine)) continue;
                    if (! preg_match('/[a-zA-Z]{2,}/', $pbLine)) continue;
                    if (str_contains($pbLine, '@')) continue;
                    $preparedBy = $this->normalise($pbLine, 80);
                    break;
                }
            }
        }

        // Raw mode fallback: value on the line immediately before "PREPAREDBYSTART PREPAREDBYEND".
        if ($preparedBy === '' && preg_match('/^(.+?)\r?\nPREPAREDBYSTART[ \t]*PREPAREDBYEND/m', $rawText, $pbr)) {
            $pbrLine = trim(preg_replace('/\s+/', ' ', $pbr[1]));
            if (
                $pbrLine !== ''
                && ! preg_match('/[A-Z]{4,}(?:START|END)/', $pbrLine)
                && ! str_contains($pbrLine, '@')
                && preg_match('/[a-zA-Z]{2,}/', $pbrLine)
            ) {
                $preparedBy = $this->normalise($pbrLine, 80);
            }
        }

        $ref = $this->extractTaggedRef($rawText);

        // ── 2. Pre-compute all PARTSTART offsets ────────────────────────────
        // Section description text ends at the first PARTSTART within each
        // section — not at the next OVERVIEWTITLESTART — because part tuples
        // and their overflow lines appear after the prose description.
        preg_match_all('/PARTSTART/', $rawText, $psm, PREG_OFFSET_CAPTURE);
        $allPartStartOffsets = array_column($psm[0], 1);  // already ascending

        // ── 3. Collect section titles with positions ─────────────────────────
        // In column-layout PDFs the section title text (e.g. "Reception") appears
        // on the line BEFORE OVERVIEWTITLESTART, not between the tags.  The content
        // between OVERVIEWTITLESTART…OVERVIEWTITLEEND is page-header garbage from
        // the adjacent column.  We therefore:
        //   a) locate each OVERVIEWTITLESTART/OVERVIEWTITLEEND pair by offset,
        //   b) pull the title from the text immediately preceding OVERVIEWTITLESTART,
        //   c) start the prose-description block at OVERVIEWTITLEEND.

        preg_match_all('/OVERVIEWTITLESTART/', $rawText, $tsm, PREG_OFFSET_CAPTURE);
        preg_match_all('/OVERVIEWTITLEEND/',   $rawText, $tem, PREG_OFFSET_CAPTURE);

        $titleStartOffsets = array_column($tsm[0], 1);
        $titleEndOffsets   = array_column($tem[0], 1);
        $titleCount        = count($titleStartOffsets);
        $sections          = [];

        for ($i = 0; $i < $titleCount; $i++) {
            $tsPos = $titleStartOffsets[$i];
            // Position just after OVERVIEWTITLEEND (start of prose block).
            $tePos = isset($titleEndOffsets[$i])
                ? $titleEndOffsets[$i] + strlen('OVERVIEWTITLEEND')
                : $tsPos + strlen('OVERVIEWTITLESTART');

            // Full section span: this OVERVIEWTITLESTART → next (or end of doc).
            $sectionEnd = strlen($rawText);
            if ($i + 1 < $titleCount) {
                $sectionEnd = $titleStartOffsets[$i + 1];
            }

            // Detect pdftotext -raw mode: in raw output OVERVIEWTITLESTART and
            // OVERVIEWTITLEEND are on the same line with nothing between them.
            // In column-layout mode the tags surround page-header noise text.
            // We check the between-tags content to choose the right strategy.
            $betweenTagsRaw = '';
            if (isset($titleEndOffsets[$i])) {
                $betweenTagsRaw = trim(substr(
                    $rawText,
                    $tsPos + strlen('OVERVIEWTITLESTART'),
                    $titleEndOffsets[$i] - $tsPos - strlen('OVERVIEWTITLESTART')
                ));
            }
            $isRawModeTitle = ($betweenTagsRaw === '');

            if (! $isRawModeTitle) {
                // Column-layout mode: title lives in the text immediately before
                // OVERVIEWTITLESTART (the adjacent-column text from QuoteWerks).
                $title = $this->extractTitleFromPreceding(substr($rawText, 0, $tsPos));
            } else {
                // Raw mode: tags are empty — skip preceding-text lookup entirely.
                // The actual title appears AFTER OVERVIEWTITLEEND (Fallback 2 below).
                $title = '';
            }

            // Fallback 1: title is between OVERVIEWTITLESTART…OVERVIEWTITLEEND.
            // Used when the preceding-text lookup is empty or returns a long address
            // string (> 80 chars) that is clearly not a room/area name.
            // Skipped in raw mode since the between-tags content is always empty.
            if (! $isRawModeTitle && ($title === '' || strlen($title) > 80) && isset($titleEndOffsets[$i])) {
                $betweenClean = trim(preg_replace('/\s+/', ' ', $betweenTagsRaw));
                if ($betweenClean !== '' && strlen($betweenClean) >= 3
                    && strlen($betweenClean) <= 120
                    && preg_match('/[a-zA-Z]{2,}/', $betweenClean)) {
                    $title = $betweenClean;
                }
            }

            // Fallback 2: pdftotext -raw mode — title is the first non-empty line
            // AFTER OVERVIEWTITLEEND and BEFORE OVERVIEWTXTSTART.
            // Also runs when the preceding-text lookup returned a known address-noise
            // term (e.g. "United Kingdom") rather than a real room/area label.
            $titleIsNoise = ($title !== '' && preg_match(
                '/^(?:United Kingdom|England|Scotland|Wales|UK|United States|USA|Canada|Australia)$/i',
                trim($title)
            ));
            if (($title === '' || $titleIsNoise) && isset($titleEndOffsets[$i])) {
                $afterTePos = $titleEndOffsets[$i] + strlen('OVERVIEWTITLEEND');
                $nextOvTxt  = strpos($rawText, 'OVERVIEWTXTSTART', $afterTePos);
                if ($nextOvTxt !== false) {
                    foreach (preg_split('/\r?\n/', substr($rawText, $afterTePos, $nextOvTxt - $afterTePos)) as $tl) {
                        $tl = trim($tl);
                        if ($tl !== '' && strlen($tl) >= 3 && preg_match('/[a-zA-Z]{2,}/', $tl)) {
                            $title = $tl;
                            break;
                        }
                    }
                }
            }

            // Prose description: from OVERVIEWTITLEEND → first PARTSTART in section.
            $textEnd = $sectionEnd;
            foreach ($allPartStartOffsets as $pos) {
                if ($pos > $tePos && $pos < $sectionEnd) {
                    $textEnd = $pos;
                    break;
                }
            }

            $sectionRaw  = substr($rawText, $tePos, $textEnd - $tePos);
            $sectionText = $this->extractSectionText($sectionRaw);

            $sections[] = [
                'title' => $title,
                'text'  => $sectionText,
                'start' => $tsPos,   // used for area-matching: tupleOffset >= start
                'end'   => $sectionEnd,
            ];
        }

        // ── 4. Build overview text ───────────────────────────────────────────
        // ALL section titles + descriptions are concatenated into overview.
        // We never try to find a single "master Overview" heading — the entire
        // section narrative IS the overview for multi-room quotes.
        $overviewParts = [];
        foreach ($sections as $section) {
            if ($section['text'] !== '') {
                $overviewParts[] = $section['title'] . "\n" . $section['text'];
            }
        }
        $overview = implode("\n\n", $overviewParts);

        // ── 5. Extract equipment from PART tuples ────────────────────────────
        // Regex matches the complete tuple on one logical row:
        //   PARTSTART … PARTEND … PARTDESCSTART … PARTDESCEND … QTYSTART … QTYEND
        //
        // Group 3 (QTYSTART content) is optional ([\d.]*) because the new
        // column-layout PDF format leaves QTYSTART/QTYEND empty and embeds the
        // qty (and part number) at the start of PARTDESCSTART instead.
        // Group 4 captures any text on the same line immediately after QTYEND
        // — the new format puts the description there.
        preg_match_all(
            '/PARTSTART\s*(.*?)\s*PARTEND\s*PARTDESCSTART\s*(.*?)\s*PARTDESCEND\s*QTYSTART\s*([\d.]*)\s*QTYEND([ \t]*[^\r\n]*)/s',
            $rawText,
            $tuples,
            PREG_OFFSET_CAPTURE
        );

        \Illuminate\Support\Facades\Log::debug('QuoteParserService::parseTagBased regex', [
            'tuple_count'    => count($tuples[0]),
            'first_tuple_g1' => $tuples[1][0][0] ?? '(none)',
            'first_tuple_g2' => substr($tuples[2][0][0] ?? '(none)', 0, 80),
            'first_tuple_g3' => $tuples[3][0][0] ?? '(none)',
            'first_tuple_g4' => $tuples[4][0][0] ?? '(none)',
            'raw_sample'     => substr(preg_replace('/\s+/', ' ', $rawText), 0, 500),
        ]);

        $equipment = [];
        $seen      = [];
        $rooms     = [];
        $seenRooms = [];

        // Single regex that strips every known tag token — used to clean
        // descriptions in case a tag bleeds into the PARTDESCSTART content.
        $allTagsPattern =
            '/\b(?:OVERVIEWTXTSTART|OVERVIEWTXTEND|OVERVIEWTITLESTART|OVERVIEWTITLEEND|'
            . 'PARTSTART|PARTEND|PARTDESCSTART|PARTDESCEND|QTYSTART|QTYEND|'
            . 'SITENAMESTART|SITENAMEEND|PREPAREDBYSTART|PREPAREDBYEND|'
            . 'QUOTENUMSTART|QUOTENUMEND|SHIPCONTSTART|SHIPCONTEND|'
            . 'SHIPPHONESTART|SHIPPHONEEND|SHIPEMAILSTART|SHIPEMAILEND|'
            . 'SHIPCOMPSTART|SHIPCOMPEND|SHIPADDSTART|SHIPADDEND)\b/';

        foreach ($tuples[0] as $idx => $tupleMatch) {
            $tupleOffset    = $tupleMatch[1];
            $rawPartNum     = trim($tuples[1][$idx][0]);
            $rawDescContent = trim($tuples[2][$idx][0]);
            $qtyStr         = trim($tuples[3][$idx][0]);
            $trailingText   = trim($tuples[4][$idx][0]);

            if ($qtyStr !== '') {
                // ── Old format: QTYSTART has qty, PARTDESCSTART has description ──
                $qty     = (float) $qtyStr;
                $rawDesc = preg_replace($allTagsPattern, '', $rawDescContent);
                $rawDesc = $this->cleanPartDescLines($rawDesc);
                $rawDesc = trim(preg_replace('/\s+/', ' ', $rawDesc));

                // Fallback: description may be on the same line after QTYEND.
                if (strlen($rawDesc) < 3 && $trailingText !== '') {
                    $rawDesc = trim(preg_replace($allTagsPattern, '', $trailingText));
                }
            } else {
                // ── New column-layout format: qty (and optionally part number) are
                //    embedded at the start of PARTDESCSTART content; the description
                //    follows QTYEND on the same line or the next non-empty line.
                //
                // PARTDESCSTART content examples:
                //   "2.00\nLH65QETELGCXEN"  → qty=2, partNum=LH65QETELGCXEN
                //   "2.00 CH-MTM1U"          → qty=2, partNum=CH-MTM1U
                //   "1.00"                   → qty=1, partNum=""
                $descLines = preg_split('/\r?\n/', $rawDescContent);
                $firstLine = trim($descLines[0] ?? '');
                $tokens    = preg_split('/\s+/', $firstLine, 2);
                $qty       = (float) ($tokens[0] ?? 0);

                if ($rawPartNum === '') {
                    if (isset($tokens[1]) && trim($tokens[1]) !== '') {
                        // Qty and part number are on the same first line: "2.00 CH-MTM1U"
                        $rawPartNum = trim($tokens[1]);
                    } elseif (isset($descLines[1])) {
                        // Part number is on the second line: "2.00\nLH65QETELGCXEN"
                        $secondLine = trim($descLines[1]);
                        if ($secondLine !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9\-\.\/=]{1,49}$/', $secondLine)) {
                            $rawPartNum = $secondLine;
                        }
                    }
                }

                // Description: same-line trailing text after QTYEND takes priority,
                // otherwise look at the first non-empty non-tag line after the match.
                if ($trailingText !== '') {
                    $rawDesc = trim(preg_replace($allTagsPattern, '', $trailingText));
                } else {
                    $afterOffset = $tupleMatch[1] + strlen($tupleMatch[0]);
                    $afterText   = substr($rawText, $afterOffset);
                    $rawDesc     = '';
                    foreach (preg_split('/\r?\n/', $afterText) as $line) {
                        $line = trim($line);
                        if ($line === '') {
                            continue;
                        }
                        // Stop at the next structural tag boundary (including page-header
                        // SHIP* tags that repeat on every page in column-layout PDFs).
                        if (preg_match('/^(?:PARTSTART|OVERVIEWTITLE|OVERVIEWTXT|SITENAME|PREPAREDBY|QUOTENUM|SHIPCONT|SHIPPHONE|SHIPEMAIL|SHIPCOMP|SHIPADD)/i', $line)) {
                            break;
                        }
                        $rawDesc = trim(preg_replace($allTagsPattern, '', $line));
                        break;
                    }
                }
            }

            // Skip optional items (qty ≤ 0) and empty / nonsense descriptions.
            if ($qty <= 0.0) {
                continue;
            }
            if (strlen($rawDesc) < 3 || ! preg_match('/[a-zA-Z]{2,}/', $rawDesc)) {
                continue;
            }

            // Skip table-header rows that leaked through.
            if (preg_match('/^(?:part\s*(?:no|number)|description|qty\.?|unit\s+price|total)\s*$/i', $rawDesc)) {
                continue;
            }

            // Skip page-number fragments that appear in pdftotext -raw output:
            // e.g. "1 of 6", "of 6", "Of 6", "Page 2 of 6".
            if (preg_match('/^(?:page\s+)?\d*\s*of\s+\d+\s*$/i', $rawDesc)) {
                continue;
            }
            // Also skip bare "of N" fragments where the leading digit was parsed as qty.
            if (preg_match('/^of\s+\d+\s*$/i', $rawDesc)) {
                continue;
            }

            // ── Part number resolution — three strategies ──────────────────
            // Strategy 1: content between PARTSTART … PARTEND tags.
            $partNum = $this->normaliseTaggedPartNumber($rawPartNum);

            // Strategy 2: standalone token on the line immediately above PARTSTART.
            if ($partNum === '') {
                $above = $this->findPrecedingPartNumber(substr($rawText, 0, $tupleOffset));
                if ($above !== null) {
                    $partNum = $above;
                }
            }

            // Strategy 3: part number embedded inside the description text
            // (trailing parenthetical or trailing token).
            if ($partNum === '') {
                $partNum = $this->extractPartNumFromDescription($rawDesc);
            }

            // Skip address-fragment descriptions that leak through in
            // pdftotext -raw mode.  In -raw output the page-header delivery
            // address (e.g. "255 High Street, Guildford") gets interleaved
            // with PART tags: "255" is parsed as qty and "High Street, …"
            // becomes the description.  Real equipment descriptions never
            // contain a bare UK/common street-type keyword at a word boundary.
            if (preg_match(
                '/\b(?:street|road|avenue|lane|close|way|drive|place|court|'
                . 'terrace|gardens?|green|hill|park|square|row|walk|crescent|'
                . 'grove|mews|boulevard)\b/i',
                $rawDesc
            )) {
                continue;
            }

            // Skip UK postcode fragments (e.g. "GU1 3AH", "SW1A 1AA").
            if (preg_match('/\b[A-Z]{1,2}[0-9][0-9A-Z]?\s+[0-9][A-Z]{2}\b/i', $rawDesc)) {
                continue;
            }

            // Skip lines that are purely a town/city with an optional county or
            // postcode — these are delivery-address continuation lines with no
            // equipment keywords (e.g. "Guildford", "Guildford, Surrey").
            // Heuristic: 1-3 comma-separated tokens, every token is title-case
            // with no digits, and total words ≤ 4.
            if (
                str_word_count($rawDesc) <= 4
                && ! preg_match('/\d/', $rawDesc)
                && preg_match('/^[A-Z][a-z]+(,\s*[A-Z][a-z]+)*$/', $rawDesc)
            ) {
                continue;
            }

            // ── Area: most recent OVERVIEWTITLE before this tuple ─────────
            $area = '';
            foreach (array_reverse($sections) as $section) {
                if ($tupleOffset >= $section['start']) {
                    $area = $section['title'];
                    break;
                }
            }

            // Dedup on description.
            $key = strtolower($rawDesc);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $equipment[] = [
                'qty'         => max(1, (int) round($qty)),
                'part_number' => $partNum,
                'description' => $rawDesc,
                'area'        => $area,
                'location'    => $this->detectRoom($rawDesc),
            ];

            // Collect unique area names for the rooms list.
            if ($area !== '') {
                $areaKey = strtolower($area);
                if (! isset($seenRooms[$areaKey])) {
                    $seenRooms[$areaKey] = true;
                    $rooms[]             = $area;
                }
            }
        }

        // ── 6. Tasks ────────────────────────────────────────────────────────
        $tasks = $this->extractTasks($this->toLines($overview));

        // ── 7. Confidence ────────────────────────────────────────────────────
        $confidence = $this->calculateConfidence($client, $site, $ref, $equipment);

        // Extract SITENAMESTART value to use as the dedicated site name.
        // Layout mode: content between tags. Raw mode: content on line before tag pair.
        $siteName = '';
        if (preg_match('/SITENAMESTART\s*(.*?)\s*SITENAMEEND/s', $rawText, $snRef)) {
            $snVal = trim(preg_replace('/\s+/', ' ', $snRef[1]));
            if ($snVal !== '' && ! preg_match('/\b(?:PARTSTART|PARTEND|QTYSTART|QTYEND)\b/', $snVal)) {
                $siteName = $this->normalise($snVal, 100);
            }
        }
        if ($siteName === '' && preg_match('/^(.+?)\r?\nSITENAMESTART[ \t]*SITENAMEEND/m', $rawText, $snr)) {
            $snrVal = trim(preg_replace('/\s+/', ' ', $snr[1]));
            if ($snrVal !== '' && ! preg_match('/[A-Z]{4,}(?:START|END)/', $snrVal)) {
                $siteName = $this->normalise($snrVal, 100);
            }
        }

        $overviewSections = array_values(array_filter(
            array_map(
                static fn ($s) => [
                    'title' => trim((string) ($s['title'] ?? '')),
                    'text'  => trim((string) ($s['text']  ?? '')),
                ],
                $sections
            ),
            static fn ($s): bool => $s['title'] !== '' || $s['text'] !== '',
        ));

        // Fallback: if tag-based parsing failed to yield section blocks but the
        // raw text clearly contains overview tags, parse them directly.
        if (empty($overviewSections) && str_contains($rawText, 'OVERVIEWTITLESTART')) {
            $overviewSections = $this->parseOverviewSectionsFromTags($rawText);
        }

        return [
            'client'        => $client,
            'site'          => $site,
            'site_name'     => $siteName,
            'ref'           => $ref,
            'overview'      => $overview,
            'overview_sections' => $overviewSections,
            'equipment'     => $equipment,
            'prepared_by'   => $preparedBy,
            'tasks'         => $tasks,
            'rooms'         => $rooms,
            'project_name'  => '',
            'works_summary' => '',
            'confidence'    => $confidence,
        ];
    }

    /**
     * Fallback parser for Overview sections when the structured parser
     * fails to populate $sections (e.g. raw tag layout variants).
     *
     * Extracts blocks between OVERVIEWTITLESTART...OVERVIEWTXTEND and
     * derives a title + text payload for each.
     */
    private function parseOverviewSectionsFromTags(string $rawText): array
    {
        $blocks = preg_split('/OVERVIEWTITLESTART/', $rawText);
        array_shift($blocks); // drop preamble

        $sections = [];

        foreach ($blocks as $block) {
            $block = 'OVERVIEWTITLESTART' . $block;

            $title = '';
            $text  = '';

            // Title between TITLESTART/TITLEEND (if present)
            if (preg_match('/OVERVIEWTITLESTART\s*(.*?)\s*OVERVIEWTITLEEND/s', $block, $tm)) {
                $title = trim(preg_replace('/\s+/', ' ', $tm[1]));
            }

            // If title is empty, try first non-empty line between TITLEEND and TXTSTART
            if ($title === '' && preg_match('/OVERVIEWTITLEEND\s*(.*?)\s*OVERVIEWTXTSTART/s', $block, $bm)) {
                foreach (preg_split('/\r?\n/', $bm[1]) as $line) {
                    $line = trim($line);
                    if ($line !== '' && preg_match('/[a-zA-Z]{2,}/', $line)) {
                        $title = $line;
                        break;
                    }
                }
            }

            // Text between TXTSTART/TXTEND
            if (preg_match('/OVERVIEWTXTSTART\s*(.*?)\s*OVERVIEWTXTEND/s', $block, $xm)) {
                $text = trim($this->cleanOverviewText($xm[1]));
            }

            if ($title !== '' || $text !== '') {
                $sections[] = ['title' => $title, 'text' => $text];
            }
        }

        return $sections;
    }

    private function cleanOverviewText(string $text): string
    {
        $text = preg_replace('/\b(?:OVERVIEWTITLESTART|OVERVIEWTITLEEND|OVERVIEWTXTSTART|OVERVIEWTXTEND)\b/', '', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text ?? '');
    }

    /**
     * Extract the first non-empty string between a pair of named tags.
     * Collapses internal whitespace to single spaces.
     * Returns '' when the tags are absent or the content is blank.
     */
    private function extractTagContent(string $rawText, string $openTag, string $closeTag): string
    {
        $pattern = '/' . preg_quote($openTag, '/') . '\s*(.*?)\s*' . preg_quote($closeTag, '/') . '/s';
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
        // Priority 0: 21CQ reference anywhere in the text always wins.
        // This prevents fallback tokens like "RAMS-001" (which appear before
        // QUOTENUMEND in some PDF layouts) from overriding the real quote ref.
        if (preg_match('/\b(21CQ[0-9]{2,10}(?:-[A-Z0-9]+)*)\b/i', $rawText, $m)) {
            return strtoupper($m[1]);
        }

        // Layout A: alphanumeric token immediately before QUOTENUMEND.
        // Must contain at least one digit — pure words like "London" are rejected.
        if (preg_match('/([A-Z][A-Z0-9\-\/]{4,25})\s*QUOTENUMEND/i', $rawText, $m)) {
            $candidate = trim($m[1]);
            if (preg_match('/\d/', $candidate)
                && preg_match('/^[A-Z0-9][A-Z0-9\-\/]{2,25}$/i', $candidate)) {
                return strtoupper($candidate);
            }
        }

        // Layout B: alphanumeric token on the line immediately before QUOTENUMSTART.
        // Must contain at least one digit — tag names like "PREPAREDBYSTART" are rejected.
        if (preg_match('/([A-Z][A-Z0-9\-\/]{4,25})[ \t]*\r?\n[ \t]*QUOTENUMSTART/i', $rawText, $m)) {
            $candidate = trim($m[1]);
            if (preg_match('/\d/', $candidate)
                && preg_match('/^[A-Z0-9][A-Z0-9\-\/]{2,25}$/i', $candidate)) {
                return strtoupper($candidate);
            }
        }

        // Fallback: existing regex patterns (handles 21CQ… anywhere in text)
        return $this->extractRef($rawText);
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

        // Any line containing one of these tokens is a structural tag line or
        // page-header line and must be skipped entirely.  We test the ORIGINAL
        // (pre-strip) line so tag content on the same line is not accidentally
        // rescued by stripping the token away.
        //
        // This covers:
        //   - Content / structural tags  (OVERVIEW*, PART*, QTY*)
        //   - Page-header tags that repeat on every page  (SHIP*, SITENAME*,
        //     PREPAREDBY*, QUOTENUM*)
        static $skipTokens = [
            'OVERVIEWTXTSTART', 'OVERVIEWTXTEND',
            'OVERVIEWTITLESTART', 'OVERVIEWTITLEEND',
            'PARTSTART', 'PARTEND',
            'PARTDESCSTART', 'PARTDESCEND',
            'QTYSTART', 'QTYEND',
            'SITENAMESTART', 'SITENAMEEND',
            'PREPAREDBYSTART', 'PREPAREDBYEND',
            'QUOTENUMSTART', 'QUOTENUMEND',
            'SHIPCONTSTART', 'SHIPCONTEND',
            'SHIPPHONESTART', 'SHIPPHONEEND',
            'SHIPEMAILSTART', 'SHIPEMAILEND',
            'SHIPCOMPSTART', 'SHIPCOMPEND',
            'SHIPADDSTART', 'SHIPADDEND',
        ];
        $skipPattern = '/\b(?:' . implode('|', $skipTokens) . ')\b/';

        foreach ($lines as $line) {
            // Normalise Unicode whitespace.
            $line = preg_replace('/\p{Zs}/u', ' ', $line);
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            // Skip any line that contains a structural or page-header tag token.
            if (preg_match($skipPattern, $line)) {
                continue;
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
            if (preg_match('/^[A-Za-z][A-Za-z0-9\-\.\/]{2,49}$/', $clean)) {
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

            // Must be a single token with no whitespace.
            // Allow digit-leading tokens (e.g. 991-000389), slash-separated
            // tokens (e.g. XRWALLA/B), and tokens with = suffix (e.g. CS-CAM-RVPTZ-WBKC=)
            // which are valid QuoteWerks part numbers.
            if (! preg_match('/^([A-Za-z0-9][A-Za-z0-9\-\.\/=]{2,49})$/', $line, $m)) {
                break; // Not a part-number line — stop immediately.
            }

            $token = $m[1];
            $hasH  = str_contains($token, '-') || str_contains($token, '/') || str_contains($token, '=');
            $hasD  = (bool) preg_match('/\d/', $token);
            $hasA  = (bool) preg_match('/[a-zA-Z]{2,}/', $token);

            if ($hasH || ($hasD && $hasA)) {
                return $token;
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

        if ($raw === '') {
            return '';
        }

        // Placeholder guard.
        if (preg_match('/\be\.?\s*g\.?\b/i', $raw)) {
            return '';
        }

        // Basic shape: alphanumeric + hyphens/dots/slashes/equals.
        // Allow digit-leading tokens (e.g. 991-000389), slash tokens (e.g. XRWALLA/B),
        // and tokens ending with = (e.g. CS-CAM-RVPTZ-WBKC=, MXWAPXD2UK=-Z11)
        // which are valid QuoteWerks Cisco/Shure part number suffixes.
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9\-\.\/=]{2,49}$/', $raw)) {
            return '';
        }

        $hasH = str_contains($raw, '-') || str_contains($raw, '/') || str_contains($raw, '=');
        $hasD = (bool) preg_match('/\d/', $raw);
        $hasA = (bool) preg_match('/[a-zA-Z]{2,}/', $raw);

        // Also allow: all-digit codes ≥ 4 chars (e.g. 37871, 45353 — numeric SKUs)
        // and all-alpha codes ≥ 5 chars (e.g. "cables" — QuoteWerks category SKUs),
        // but block obvious noise words that would never be product codes.
        $allDigit = ctype_digit($raw);
        $allAlpha = ctype_alpha($raw);
        $isNoise  = $allAlpha && preg_match(
            '/^(?:labour|supply|goods|install|fitting|sundry|provision|misc|included)/i',
            $raw
        );

        return (
            $hasH
            || ($hasD && $hasA)
            || ($allDigit && strlen($raw) >= 4)
            || ($allAlpha && strlen($raw) >= 5 && ! $isNoise)
        ) ? $raw : '';
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
                'PARTSTART', 'PARTEND', 'PARTDESCSTART', 'PARTDESCEND', 'QTYSTART', 'QTYEND',
                'SITENAMESTART', 'SITENAMEEND', 'PREPAREDBYSTART', 'PREPAREDBYEND',
                'QUOTENUMSTART', 'QUOTENUMEND', 'SHIPCONTSTART', 'SHIPCONTEND',
                'SHIPPHONESTART', 'SHIPPHONEEND', 'SHIPEMAILSTART', 'SHIPEMAILEND',
                'SHIPCOMPSTART', 'SHIPCOMPEND', 'SHIPADDSTART', 'SHIPADDEND',
            ];
            $skipPattern = '/\b(?:' . implode('|', $tokens) . ')\b/';
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
            . 'PARTSTART|PARTEND|PARTDESCSTART|PARTDESCEND|QTYSTART|QTYEND)\b/';

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

            // Skip the quote reference number line (e.g. 21CQ29437-11, ABC-001).
            // Matches alphanumeric+hyphen-only strings that contain both letters
            // and digits — characteristic of quote/order refs, not address lines.
            if (
                preg_match('/^[A-Z0-9][A-Z0-9\-\/]{4,29}$/i', $line)
                && preg_match('/[A-Z]/i', $line)
                && preg_match('/\d/', $line)
            ) {
                continue;
            }

            // Strip an inline quote-ref prefix from the start of an address line.
            // Some QuoteWerks PDFs embed the ref on the same line as the site name:
            //   "21CQ30246-06-OPS West Burton Power Station"
            // Remove the ref token and leading whitespace so only the site name remains.
            $line = preg_replace('/^21CQ[0-9]+(?:-[A-Z0-9]+)*\s*/i', '', $line);
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // Skip page-number lines ("1 of 25", etc.).
            if (preg_match('/^\d+\s+of\s+\d+$/i', $line)) {
                continue;
            }

            // Skip lines that are primarily a phone/mobile number — these appear
            // in QuoteWerks SHIPADDSTART blocks as a contact-detail line alongside
            // the physical address (e.g. "Rich -0771 8386409 (Site)").
            // A phone line: contains a 6+ digit run (with optional spaces/hyphens)
            // and has more digit characters than alphabetic characters.
            if (
                preg_match('/\d[\d\s\-]{5,}\d/', $line)
                && preg_match_all('/\d/', $line) > preg_match_all('/[a-zA-Z]/', $line)
            ) {
                continue;
            }

            // Must contain at least two consecutive alphabetic characters.
            if (! preg_match('/[a-zA-Z]{2,}/', $line)) {
                continue;
            }

            // Reject lines that look like binary PDF stream fragments.
            if ($this->hasExcessiveSpecialChars($line) || $this->isLikelyEncodedBlob($line)) {
                if ($this->isLikelyEncodedBlob($line)) {
                    \Illuminate\Support\Facades\Log::warning('QuoteParserService: skipped encoded-looking tagged address line', [
                        'sample' => substr($line, 0, 80),
                    ]);
                }
                continue;
            }

            // Secondary binary/garbled-text guard: check average length of alphabetic
            // runs.  Real address lines are mostly English words (average run ≥ 3.0
            // letters: "Connaught"=9, "House"=5, "Surrey"=6 …).  Garbled binary text
            // has many single/two-character runs separated by digits and symbols,
            // so the average stays well below 3.0.
            preg_match_all('/[a-zA-Z]+/', $line, $alphaRunMatches);
            $runs = $alphaRunMatches[0];
            if (! empty($runs)) {
                $avgRunLen = array_sum(array_map('strlen', $runs)) / count($runs);
                if ($avgRunLen < 3.0) {
                    continue;
                }
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
        if (preg_match('/^(.{3,}?)\s*\(([A-Za-z][A-Za-z0-9\-\.]{2,29})\)\s*$/', $desc, $m)) {
            return $this->normaliseTaggedPartNumber($m[2]);
        }

        // Strategy B: trailing token "Description text PARTNUM"
        if (preg_match('/^(.{5,})\s+([A-Za-z][A-Za-z0-9\-\.]{2,29})$/', $desc, $m)) {
            return $this->normaliseTaggedPartNumber($m[2]);
        }

        return '';
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
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // A bare decimal (e.g. "1.00", "2.50") is the price/qty column
            // value that bleeds in when a PARTDESC tuple spans a page break.
            // Skip it — don't break, as more real content may follow.
            if (preg_match('/^\d+\.\d+$/', $line)) {
                continue;
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
