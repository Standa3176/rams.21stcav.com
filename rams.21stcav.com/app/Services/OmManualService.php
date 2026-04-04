<?php

namespace App\Services;

use App\Services\AI\AIProviderInterface;
use RuntimeException;

/**
 * Orchestrates the two-pass AI generation pipeline for O&M manuals.
 *
 * Pass 1 — extractFromQuote()
 *   Reads the QuoteWerks PDF and returns a structured list of rooms and
 *   installed equipment.  The user reviews and edits this data before
 *   triggering Pass 2.
 *
 * Pass 2 — generateContent()
 *   Takes the reviewed equipment data (no PDF required) and generates the
 *   full O&M manual content: operating procedures, maintenance schedule,
 *   fault-finding guide, network notes, manufacturer support, and warranty
 *   summary.
 */
class OmManualService
{
    public function __construct(
        private readonly PromptBuilderService $promptBuilder,
    ) {}

    // ── Pass 1: extract rooms + equipment from quote PDF ────────────────────

    /**
     * @param  string              $pdfPath   Absolute path to the uploaded PDF.
     * @param  AIProviderInterface $provider
     * @return array  Structured extracted data (project + rooms array).
     * @throws RuntimeException
     */
    public function extractFromQuote(string $pdfPath, AIProviderInterface $provider): array
    {
        $pdfBytes = file_get_contents($pdfPath);

        if ($pdfBytes === false || strlen($pdfBytes) === 0) {
            throw new RuntimeException('Could not read the uploaded PDF file.');
        }

        $pdfBase64 = base64_encode($pdfBytes);
        $prompt    = $this->promptBuilder->buildForOmExtraction();

        $lastError = null;

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $result = $provider->generateFromPdf($pdfBase64, null, $prompt);

                // Validate minimal expected shape
                if (! isset($result['project']) || ! isset($result['rooms']) || ! is_array($result['rooms'])) {
                    throw new RuntimeException(
                        'AI response is missing required "project" or "rooms" keys.'
                    );
                }

                // Sanitise — ensure every room has the expected keys
                $result['rooms'] = array_values(array_map(
                    fn (array $room) => $this->sanitiseRoom($room),
                    $result['rooms']
                ));

                return $result;

            } catch (RuntimeException $e) {
                $lastError = $e;
            }
        }

        throw new RuntimeException(
            'Equipment extraction failed after two attempts. Last error: ' . $lastError->getMessage()
        );
    }

    // ── Pass 2: generate full O&M content from reviewed data ────────────────

    /**
     * @param  array               $extractedData  Reviewed Pass 1 output.
     * @param  AIProviderInterface $provider
     * @return array  Full generated O&M content.
     * @throws RuntimeException
     */
    public function generateContent(array $extractedData, AIProviderInterface $provider): array
    {
        $prompt    = $this->promptBuilder->buildForOmContent($extractedData);
        $lastError = null;

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $result = $provider->generateFromText($prompt);

                if (! isset($result['operation_sections']) || ! is_array($result['operation_sections'])) {
                    throw new RuntimeException(
                        'AI response is missing required "operation_sections" key.'
                    );
                }

                // Merge the original extracted project/rooms data into the generated result
                // so the Docx builder has everything in one place.
                $result['project']       = $extractedData['project']  ?? [];
                $result['rooms_summary'] = $extractedData['rooms']    ?? [];

                // Ensure all expected top-level keys exist with safe defaults
                $result['maintenance_schedule']   ??= [];
                $result['fault_finding']          ??= [];
                $result['network_devices']        ??= [];
                $result['network_security_notes'] ??= [];
                $result['manufacturer_support']   ??= [];
                $result['warranty_summary']       ??= [];

                return $result;

            } catch (RuntimeException $e) {
                $lastError = $e;
            }
        }

        throw new RuntimeException(
            'Content generation failed after two attempts. Last error: ' . $lastError->getMessage()
        );
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function sanitiseRoom(array $room): array
    {
        return [
            'name'        => $room['name']        ?? 'Unknown Room',
            'drawing_ref' => $room['drawing_ref'] ?? '',
            'equipment'   => array_values(array_map(
                fn (array $eq) => [
                    'qty'         => (int) ($eq['qty']         ?? 1),
                    'description' => $eq['description']        ?? '',
                    'model'       => $eq['model']              ?? '',
                    'part_no'     => $eq['part_no']            ?? '',
                ],
                is_array($room['equipment'] ?? null) ? $room['equipment'] : []
            )),
        ];
    }
}
