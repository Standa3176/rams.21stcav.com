<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Extracts structured QuoteWerks data from a PDF quote using Claude's
 * native document-vision API (PDF content blocks).
 *
 * No third-party PDF parser is needed — the raw PDF bytes are base64-encoded
 * and sent directly to Claude, which reads the document natively.
 *
 * Returns an array with keys ready to drive downstream RAMS-extraction pipelines.
 */
class QuoteExtractorService
{
    private string $apiKey;
    private string $model;
    private string $endpoint;

    public function __construct()
    {
        $this->apiKey   = config('ai.providers.claude.api_key');
        $this->model    = config('ai.providers.claude.model');
        $this->endpoint = config('ai.providers.claude.endpoint');
    }

    /**
     * Extract structured QuoteWerks data from the given PDF file.
     *
     * @param  UploadedFile  $pdf
     * @return array{
     *   qw_number: string,
     *   client_name: string,
     *   site_address: string,
     *   project_name: string,
     *   works_description: string,
     *   line_items: array,
     *   room_summaries: array,
     *   hazards: array,
     *   ppe: array,
     *   persons_at_risk: array
     * }
     *
     * @throws RuntimeException  On API failure or invalid JSON response.
     */
    public function extract(UploadedFile $pdf): array
    {
        // Store immediately via Storage facade to avoid open_basedir blocking /tmp access
        $storedPath = $pdf->store('tmp/rams-uploads', 'local');
        $localPath  = Storage::disk('local')->path($storedPath);

        try {
            return $this->extractFromPath($localPath);
        } finally {
            Storage::disk('local')->delete($storedPath);
        }
    }

    /**
     * Extract structured QuoteWerks data from a PDF at the given absolute file-system path.
     *
     * Useful when the file is already on disk (e.g. retrieved from Storage) and
     * an UploadedFile instance is not available.
     *
     * @param  string  $absolutePath  Absolute path to the PDF file.
     * @return array                  Same shape as extract().
     *
     * @throws RuntimeException  On API failure or invalid JSON response.
     */
    public function extractFromPath(string $absolutePath): array
    {
        $pdfBase64 = base64_encode(file_get_contents($absolutePath));

        return $this->callClaude($pdfBase64);
    }

    /**
     * Send a base64-encoded PDF to the Claude document-vision API and return the decoded JSON.
     *
     * @param  string  $pdfBase64  Base64-encoded PDF bytes.
     * @return array               Decoded JSON from Claude.
     *
     * @throws RuntimeException  On HTTP failure or invalid JSON in the response.
     */
    private function callClaude(string $pdfBase64): array
    {
        // Claude supports PDF documents as 'document' content blocks.
        // The model reads the full document layout, tables, and text natively.
        $response = Http::withHeaders([
            'x-api-key'         => $this->apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])->timeout(120)->post($this->endpoint, [
            'model'      => $this->model,
            'max_tokens' => 4096,
            'messages'   => [
                [
                    'role'    => 'user',
                    'content' => [
                        [
                            'type'   => 'document',
                            'source' => [
                                'type'       => 'base64',
                                'media_type' => 'application/pdf',
                                'data'       => $pdfBase64,
                            ],
                        ],
                        [
                            'type' => 'text',
                            'text' => $this->buildExtractionPrompt(),
                        ],
                    ],
                ],
            ],
        ]);

        if ($response->failed()) {
            throw new RuntimeException(
                'Claude PDF extraction failed (' . $response->status() . '): ' . $response->body()
            );
        }

        $raw = $response->json('content.0.text', '');
        $raw = $this->stripMarkdownFences($raw);
        $raw = self::sanitiseRawJson($raw);

        $decoded = json_decode($raw, true, 512, JSON_INVALID_UTF8_IGNORE);

        if (json_last_error() !== JSON_ERROR_NONE) {
            // Log full response for forensics — user-facing preview is
            // truncated but operators need the offending byte to diagnose
            // novel corruption families.
            Log::error('QuoteExtractor: invalid JSON after sanitisation', [
                'error'      => json_last_error_msg(),
                'raw_length' => strlen($raw),
                'raw_head'   => mb_substr($raw, 0, 1000),
                'raw_tail'   => mb_substr($raw, max(0, mb_strlen($raw) - 500)),
            ]);
            throw new RuntimeException(
                'Quote extraction returned invalid JSON: ' . json_last_error_msg()
                . ' — Raw response: ' . mb_substr($raw, 0, 300)
            );
        }

        return $decoded;
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Three-stage sanitisation of raw Claude response text before json_decode.
     *
     * Public + static for testability — pure function with no I/O dependencies,
     * unit-testable without mocking the Claude HTTP call.
     *
     * Handles three byte-classes that trip JSON_ERROR_CTRL_CHAR:
     *
     * 1. **C0 controls + DEL (0x00-0x1F, 0x7F)** — literal newlines/tabs
     *    inside JSON string values (paragraph breaks in works_description).
     *    Must be escaped as `\n` (two chars) in JSON, not raw byte 0x0A.
     * 2. **High-bit Unicode line/paragraph separators** — U+0085 NEL,
     *    U+2028 LINE SEPARATOR, U+2029 PARAGRAPH SEPARATOR. These pass the
     *    C0 strip but recent PHP json_decode still rejects them inside
     *    strings.
     * 3. **Malformed UTF-8 multi-byte sequences** — Claude occasionally
     *    emits stray bytes that don't form valid UTF-8 codepoints.
     *    mb_convert_encoding('UTF-8','UTF-8',...) normalises them
     *    (invalid byte → U+FFFD replacement which is then strippable).
     *
     * Use JSON_INVALID_UTF8_IGNORE on the json_decode call as belt-and-braces
     * for anything that escapes this sanitisation.
     *
     * Bug trail:
     *   - 2026-05-16: Tilda 21CQ29531-05-OPS package 110 — three different
     *     classes of byte hit in sequence as Claude regenerated different
     *     responses on retry. Triggered the full ladder of fixes.
     *
     * @param  string  $raw  Raw text from Claude response (post-fence-strip).
     * @return string        Sanitised text safe for json_decode.
     */
    public static function sanitiseRawJson(string $raw): string
    {
        $raw = mb_convert_encoding($raw, 'UTF-8', 'UTF-8');
        $raw = preg_replace('/[\x00-\x1F\x7F]/u', '', $raw);
        $raw = preg_replace('/[\x{0085}\x{2028}\x{2029}]/u', '', $raw);

        return $raw;
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function buildExtractionPrompt(): string
    {
        return <<<PROMPT
You are a document analysis assistant specialising in UK AV (Audio-Visual) installation projects.

The PDF attached is a QuoteWerks sales quote for an AV installation project. Extract the information below and return it as a single valid JSON object — no markdown fences, no commentary, no text before or after the JSON.

JSON SCHEMA (return data matching this structure exactly):
{
  "qw_number":         "string — the QuoteWerks quote number (e.g. QW-12345, or the first reference number found)",
  "client_name":       "string — the customer or bill-to company name",
  "site_address":      "string — the ship-to or installation site address (full address including postcode if present)",
  "project_name":      "string — a concise project name derived from the quote content (e.g. 'Boardroom AV Installation — Acme Ltd')",
  "works_description": "string — a clear paragraph (3–5 sentences) describing the full scope of AV works based on the line items and room solutions in the quote",
  "line_items": [
    {
      "sku":         "string — product code or SKU (empty string if not present)",
      "qty":         1,
      "description": "string — full product or line item description"
    }
  ],
  "room_summaries": [
    {
      "room":    "string — room or area name (e.g. 'Boardroom', 'Reception', 'Training Suite')",
      "summary": "string — brief description of the AV solution for this room"
    }
  ],
  "hazards": [
    "string — relevant H&S hazard for this type of AV installation work in the UK"
  ],
  "ppe": [
    "string — PPE item required for this installation"
  ],
  "persons_at_risk": [
    "string — category of person at risk during the installation works"
  ]
}

Rules:
1. For hazards — infer at least 6 realistic hazards based on the scope of works (e.g. working at height, electrical, manual handling, dust, noise, lone working). Each must be a concise phrase, not a full sentence.
2. For ppe — list at least 4 items appropriate for UK AV installation (e.g. 'Safety footwear', 'Hard hat', 'Hi-vis vest', 'Work gloves', 'Eye protection', 'Knee pads').
3. For persons_at_risk — include at minimum: 'AV Installers', 'Client Staff', 'Site Visitors'.
4. For room_summaries — if the quote is not split by room, create one entry for the overall project.
5. For line_items — include ALL line items from the quote including hardware, cabling, and labour lines. If a line item has no SKU, use an empty string "".
6. If a field cannot be found in the document, use an empty string "" or empty array [] — never omit the key.
7. Return ONLY the JSON object. Nothing before or after it.
PROMPT;
    }

    private function stripMarkdownFences(string $text): string
    {
        $text = preg_replace('/^```(?:json)?\s*/i', '', trim($text));
        $text = preg_replace('/\s*```$/i', '', $text);

        return trim($text);
    }
}
