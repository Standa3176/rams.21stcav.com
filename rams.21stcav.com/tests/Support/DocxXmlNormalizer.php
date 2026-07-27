<?php

namespace Tests\Support;

use InvalidArgumentException;
use ZipArchive;

/**
 * Phase 260726-rf3 Plan 04 Commit 3 — DOCX snapshot normaliser.
 *
 * Given a DOCX byte string (or a filesystem path pointing at one), extract
 * `word/document.xml` and return a normalised XML string with every
 * drift-prone value stripped or canonicalised. The result is safe to
 * diff byte-for-byte against a golden file across:
 *
 *  - Successive builds of the same content (PhpWord re-emits attributes
 *    like `w:id`, `w:rsidR`, `r:id` with random / session-scoped values).
 *  - Different machines with different PHP builds / PhpWord versions
 *    (attribute declaration order isn't guaranteed on `<w:document>`).
 *  - Section geometry (`<w:sectPr>`, `<w:pgMar>`, `<w:pgSz>`) where a
 *    theme-driven margin computed from a float can serialise with a
 *    varying number of decimal places.
 *
 * Every transformation is deterministic — normalise(bytes) called N
 * times returns the same string. `DocxSnapshotTest` uses that invariant
 * to guard against silent drift between the legacy DocxBuilderService
 * path (flag=false) and the unified DocxBuilderServiceV2 path (flag=true).
 *
 * NB: This is a TEST-ONLY helper — never load from production code. The
 * regex-based stripping is deliberately narrow (only the noise categories
 * enumerated below); anything else stays untouched so a real content
 * regression can't hide behind a broad wildcard strip.
 */
final class DocxXmlNormalizer
{
    /**
     * Normalise a DOCX to its diff-safe `word/document.xml` string.
     *
     * Order of operations (order matters — sort xmlns first while root
     * element attributes are still all present, then strip the noise):
     *
     *  1. Unzip the DOCX and extract `word/document.xml`.
     *  2. Sort `xmlns:*` attribute declarations alphabetically on the root
     *     `<w:document>` element. PhpWord's declaration order isn't stable
     *     across builds — sorting normalises it. Non-xmlns attributes on
     *     the root are preserved verbatim ahead of the sorted xmlns block.
     *  3. Strip `w:id="..."` — PhpWord assigns these to runs, tables, and
     *     numbering; the values are random per build.
     *  4. Strip `r:id="rId..."` — relationship IDs are position-dependent
     *     inside `word/_rels/document.xml.rels` and drift when render
     *     order changes even by one element.
     *  5. Strip `w:rsidR`, `w:rsidRPr`, `w:rsidRDefault`, `w:rsidP` —
     *     revision-save IDs are session-random.
     *  6. Normalise every numeric attribute inside `<w:sectPr>`,
     *     `<w:pgMar>`, `<w:pgSz>` to 4 decimal places so a computed
     *     margin (e.g. from a theme setting) serialising as `1020.0`
     *     vs `1020` on different PHP builds doesn't invalidate the diff.
     *
     * @param string $docxBytesOrPath ZIP-encoded DOCX bytes, or an absolute
     *                                filesystem path pointing at a DOCX
     *                                file. Path detection is purely on
     *                                is_file() + length; a very short byte
     *                                string that happens to also be a valid
     *                                file path will be treated as the path.
     *
     * @throws InvalidArgumentException When the input is empty, is not a
     *                                  valid ZIP, or has no
     *                                  `word/document.xml` entry.
     */
    public static function normalise(string $docxBytesOrPath): string
    {
        $bytes = self::isFilesystemPath($docxBytesOrPath)
            ? (string) file_get_contents($docxBytesOrPath)
            : $docxBytesOrPath;

        if ($bytes === '') {
            throw new InvalidArgumentException('DocxXmlNormalizer: input bytes are empty.');
        }

        $xml = self::extractDocumentXml($bytes);
        $xml = self::sortRootXmlnsAttrs($xml);
        $xml = self::stripRunIds($xml);
        $xml = self::stripRelationshipIds($xml);
        $xml = self::stripRsidAttrs($xml);
        $xml = self::normaliseSectionNumericAttrs($xml);

        return $xml;
    }

    /**
     * Heuristic — treat the input as a filesystem path only when it looks
     * plausibly like one (short, no ZIP `PK` prefix) and it actually
     * resolves to an existing file. Byte strings from an in-memory build
     * are ZIP bytes and start with `PK\x03\x04`, so they never trip this.
     */
    private static function isFilesystemPath(string $input): bool
    {
        if ($input === '' || strlen($input) > 4096) {
            return false;
        }
        if (str_starts_with($input, "PK\x03\x04")) {
            return false;
        }

        return @is_file($input);
    }

    /**
     * Unzip the DOCX and pull out `word/document.xml`. ZipArchive can only
     * open filesystem paths, so we write to a tempnam then clean up.
     */
    private static function extractDocumentXml(string $bytes): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'docxnorm-');
        if ($tmp === false) {
            throw new InvalidArgumentException('DocxXmlNormalizer: could not create temp file.');
        }

        try {
            file_put_contents($tmp, $bytes);

            $zip = new ZipArchive();
            if ($zip->open($tmp) !== true) {
                throw new InvalidArgumentException('DocxXmlNormalizer: input is not a valid DOCX (ZIP open failed).');
            }

            $xml = $zip->getFromName('word/document.xml');
            $zip->close();

            if (! is_string($xml)) {
                throw new InvalidArgumentException('DocxXmlNormalizer: word/document.xml missing from DOCX.');
            }

            return $xml;
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Rewrite the root `<w:document ...>` open tag with any `xmlns:*`
     * declarations sorted alphabetically. Non-xmlns attributes on the
     * root are preserved in their original relative order and emitted
     * before the sorted xmlns block.
     */
    private static function sortRootXmlnsAttrs(string $xml): string
    {
        $result = preg_replace_callback(
            '/<w:document\b([^>]*)>/',
            static function (array $m): string {
                $attrs = $m[1];

                preg_match_all('/\s+xmlns:[A-Za-z0-9]+="[^"]*"/', $attrs, $matches);
                $xmlnsAttrs = $matches[0];
                sort($xmlnsAttrs, SORT_STRING);

                $nonXmlns = preg_replace('/\s+xmlns:[A-Za-z0-9]+="[^"]*"/', '', $attrs);
                $nonXmlns = rtrim((string) $nonXmlns);

                return '<w:document' . $nonXmlns . implode('', $xmlnsAttrs) . '>';
            },
            $xml,
            1,
        );

        return is_string($result) ? $result : $xml;
    }

    /** Strip random per-build run/table/numbering IDs. */
    private static function stripRunIds(string $xml): string
    {
        $result = preg_replace('/\s+w:id="[^"]*"/', '', $xml);

        return is_string($result) ? $result : $xml;
    }

    /**
     * Strip relationship IDs (`r:id="rIdN"`). Position-dependent — dropping
     * or re-ordering a single element in the render pipeline reshuffles
     * every rId and swamps the real diff.
     */
    private static function stripRelationshipIds(string $xml): string
    {
        $result = preg_replace('/\s+r:id="rId[^"]*"/', '', $xml);

        return is_string($result) ? $result : $xml;
    }

    /** Strip revision-save IDs (session-random, meaningless for diff). */
    private static function stripRsidAttrs(string $xml): string
    {
        $result = preg_replace('/\s+w:rsid(?:R|RPr|RDefault|P)="[^"]*"/', '', $xml);

        return is_string($result) ? $result : $xml;
    }

    /**
     * Normalise every decimal-numeric attribute inside `<w:sectPr>`,
     * `<w:pgMar>`, `<w:pgSz>` to exactly 4 decimal places. Integer-valued
     * attributes are left untouched (a value with no decimal point can't
     * suffer from decimal-place drift). Non-numeric attributes (e.g.
     * `w:orient="portrait"`) are left untouched.
     *
     * We rewrite the open tag itself, so self-closing (`<w:pgMar .../>`)
     * and container (`<w:sectPr ...>`) forms are both handled.
     */
    private static function normaliseSectionNumericAttrs(string $xml): string
    {
        $result = preg_replace_callback(
            '/<(w:sectPr|w:pgMar|w:pgSz)\b([^>]*)>/',
            static function (array $m): string {
                $tag   = $m[1];
                $attrs = $m[2];

                $attrs = preg_replace_callback(
                    '/(\s+[A-Za-z:]+)="(-?\d+\.\d+)"/',
                    static function (array $n): string {
                        return $n[1] . '="' . number_format((float) $n[2], 4, '.', '') . '"';
                    },
                    $attrs,
                );

                return '<' . $tag . (is_string($attrs) ? $attrs : $m[2]) . '>';
            },
            $xml,
        );

        return is_string($result) ? $result : $xml;
    }
}
