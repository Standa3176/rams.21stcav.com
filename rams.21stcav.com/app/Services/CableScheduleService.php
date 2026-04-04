<?php

namespace App\Services;

use App\Services\AI\AIProviderInterface;
use RuntimeException;

class CableScheduleService
{
    public function __construct(
        private readonly PromptBuilderService $promptBuilder,
    ) {}

    /**
     * Generate cable schedule items from an uploaded quote PDF.
     *
     * The PDF is passed as base64 directly to the AI provider — no server-side
     * parser required (same approach as QuoteExtractorService).
     *
     * @param  string              $pdfPath   Absolute path to the uploaded PDF.
     * @param  AIProviderInterface $provider
     * @return array               Array of cable item arrays.
     */
    public function generateFromQuote(string $pdfPath, AIProviderInterface $provider): array
    {
        $pdfBytes = file_get_contents($pdfPath);

        if ($pdfBytes === false || empty($pdfBytes)) {
            throw new RuntimeException('Could not read the PDF file.');
        }

        $pdfBase64   = base64_encode($pdfBytes);
        $basePrompt  = $this->promptBuilder->buildForCableSchedule();
        $retryPrompt = $this->promptBuilder->buildForCableSchedule(
            'IMPORTANT: Return ONLY a JSON object with a "cables" array key. No other keys, no markdown.'
        );

        $lastError = null;

        foreach ([1, 2] as $attempt) {
            try {
                $result = $provider->generateFromPdf(
                    $pdfBase64,
                    null,
                    $attempt === 2 ? $retryPrompt : $basePrompt
                );

                if (! isset($result['cables']) || ! is_array($result['cables'])) {
                    throw new RuntimeException('AI response missing "cables" array key.');
                }

                return $result['cables'];
            } catch (RuntimeException $e) {
                $lastError = $e;
            }
        }

        throw new RuntimeException(
            'Cable schedule generation failed after two attempts. Last error: ' . $lastError->getMessage()
        );
    }
}
