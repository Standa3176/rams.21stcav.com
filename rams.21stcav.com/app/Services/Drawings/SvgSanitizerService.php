<?php

namespace App\Services\Drawings;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Audit WR-04 — SVG sanitiser for client-uploaded SVG payloads.
 *
 * ── Threat model ────────────────────────────────────────────────────────
 *
 * `DrawIoSpikeController::exportSvg` accepts a raw SVG string from the
 * draw.io embed, persists it to disk, and later serves it back for the
 * drawing view. Any of the following in the client payload becomes
 * stored XSS:
 *
 *   - `<script>` elements
 *   - `on*` inline event handlers (onload, onclick, onmouseover, …)
 *   - `href` / `xlink:href` with `javascript:` schemes
 *   - `<foreignObject>` embedding HTML that itself contains scripts
 *
 * Admin middleware doesn't help — any authenticated user with access to
 * the drawing view page renders the persisted SVG, so a hostile admin
 * (or an admin whose account is compromised) can plant a payload that
 * fires on every viewer.
 *
 * ── Approach ────────────────────────────────────────────────────────────
 *
 * Parse with DOMDocument (LIBXML_NOENT | LIBXML_NONET to block XXE and
 * external entity fetches), walk the tree, strip:
 *   1. every element in `BLOCKED_ELEMENTS`
 *   2. every attribute matching `on*` (case-insensitive)
 *   3. any `href` / `xlink:href` starting with `javascript:` /
 *      `data:text/html` / `vbscript:` / `data:image/svg+xml` (nested
 *      SVGs are a re-entry vector for further XSS)
 *
 * Returns the sanitised SVG as a UTF-8 string.
 *
 * ── Callers ─────────────────────────────────────────────────────────────
 *
 * @see \App\Http\Controllers\Admin\DrawIoSpikeController::exportSvg
 */
class SvgSanitizerService
{
    /**
     * Elements stripped outright — their tag opens a script execution
     * surface regardless of attribute state.
     */
    private const BLOCKED_ELEMENTS = [
        'script', 'foreignobject', 'iframe', 'embed', 'object',
        'style', // may contain @import + CSS expressions that fire JS on IE-family renderers
        'meta',
    ];

    /**
     * URI schemes rejected in `href`/`xlink:href` values.
     */
    private const BLOCKED_URI_SCHEMES = [
        'javascript:',
        'vbscript:',
        'data:text/html',
        'data:image/svg+xml', // nested SVG re-entry vector
    ];

    /**
     * Sanitise a client-provided SVG string.
     *
     * Returns an empty string if the input can't be parsed as XML — the
     * caller can treat that as a validation failure. On success returns
     * a UTF-8-encoded, script-free SVG.
     */
    public function sanitize(string $svg): string
    {
        if (trim($svg) === '') {
            return '';
        }

        $dom = new DOMDocument();

        // LIBXML_NOENT: substitute predefined entities (safe with NONET).
        // LIBXML_NONET: forbid network access — no external entity fetches.
        // LIBXML_NOWARNING + LIBXML_NOERROR: suppress libxml notices about
        // unknown namespaces; we handle the return value ourselves.
        $prevInternalErrors = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML(
            $svg,
            LIBXML_NONET | LIBXML_NOENT | LIBXML_NOWARNING | LIBXML_NOERROR,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prevInternalErrors);

        if (! $loaded) {
            return '';
        }

        $this->stripBlockedElements($dom);
        $this->stripBlockedAttributes($dom);

        return (string) $dom->saveXML();
    }

    private function stripBlockedElements(DOMDocument $dom): void
    {
        $xpath = new DOMXPath($dom);

        // Build case-insensitive predicates for every blocked element.
        // XPath 1.0 doesn't have lower-case(), so we use translate().
        foreach (self::BLOCKED_ELEMENTS as $tag) {
            $expr = sprintf(
                '//*[translate(local-name(), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="%s"]',
                $tag,
            );
            $nodes = $xpath->query($expr);
            if ($nodes === false) {
                continue;
            }

            // Iterate in reverse so removeChild() calls don't shift the
            // remaining index positions.
            for ($i = $nodes->length - 1; $i >= 0; $i--) {
                $node = $nodes->item($i);
                if ($node !== null && $node->parentNode !== null) {
                    $node->parentNode->removeChild($node);
                }
            }
        }
    }

    private function stripBlockedAttributes(DOMDocument $dom): void
    {
        $xpath = new DOMXPath($dom);
        $elements = $xpath->query('//*');
        if ($elements === false) {
            return;
        }

        foreach ($elements as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }

            // Snapshot attribute names first — DOMElement::removeAttribute()
            // mutates the collection, so iterating live would skip entries.
            $attrNames = [];
            foreach ($element->attributes ?? [] as $attr) {
                $attrNames[] = $attr->nodeName;
            }

            foreach ($attrNames as $name) {
                $lower = strtolower($name);

                // 1. `on*` event handlers.
                if (str_starts_with($lower, 'on')) {
                    $element->removeAttribute($name);
                    continue;
                }

                // 2. href / xlink:href with a blocked URI scheme.
                if ($lower === 'href' || $lower === 'xlink:href') {
                    $value = strtolower(trim((string) $element->getAttribute($name)));
                    foreach (self::BLOCKED_URI_SCHEMES as $scheme) {
                        if (str_starts_with($value, $scheme)) {
                            $element->removeAttribute($name);
                            continue 2;
                        }
                    }
                }
            }
        }
    }
}
