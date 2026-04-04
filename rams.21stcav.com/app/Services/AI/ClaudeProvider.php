<?php

namespace App\Services\AI;

use App\Services\PromptBuilderService;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ClaudeProvider implements AIProviderInterface
{
    private string $apiKey;
    private string $model;
    private string $endpoint;

    public function __construct(private readonly PromptBuilderService $promptBuilder)
    {
        $this->apiKey   = config('ai.providers.claude.api_key');
        $this->model    = config('ai.providers.claude.model');
        $this->endpoint = config('ai.providers.claude.endpoint');
    }

    // =========================================================================
    // Manual form path
    // =========================================================================

    public function generateRams(array $formData): array
    {
        $prompt = $this->promptBuilder->build($formData);

        $response = $this->sendTextRequest($prompt, 4096);

        $raw     = $this->sanitise($response->json('content.0.text', ''));
        $decoded = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException(
                'Claude returned invalid JSON: ' . json_last_error_msg() .
                ' — Raw: ' . substr($raw, 0, 500)
            );
        }

        return $decoded;
    }

    // =========================================================================
    // PDF TEXT PATH (primary production path)
    // =========================================================================

    public function generateRamsFromText(
        string $quoteText,
        array $equipment,
        ?string $retrySuffix = null
    ): array {

        $prompt = $this->promptBuilder->buildFromText($quoteText, $equipment, $retrySuffix);

        $response = $this->sendTextRequest($prompt, 8192);

        $raw     = $this->sanitise($response->json('content.0.text', ''));
        $decoded = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException(
                'Claude (text) returned invalid JSON: ' . json_last_error_msg() .
                ' — Raw: ' . substr($raw, 0, 500)
            );
        }

        return $decoded;
    }

    // =========================================================================
    // FILE PATH (compatibility with interface)
    // =========================================================================

    public function generateRamsFromFiles(
        array $files,
        ?string $retrySuffix = null,
        ?string $promptOverride = null
    ): array {

        $quoteText = $this->extractTextFromFile($files['quote']['path']);

        $equipment = [];

        if ($promptOverride !== null) {
            $response = $this->sendTextRequest($promptOverride, 4096);

            $raw     = $this->sanitise($response->json('content.0.text', ''));
            $decoded = json_decode($raw, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException(
                    'Claude returned invalid JSON: ' . json_last_error_msg() .
                    ' — Raw: ' . substr($raw, 0, 500)
                );
            }

            return $decoded;
        }

        return $this->generateRamsFromText($quoteText, $equipment, $retrySuffix);
    }

    // =========================================================================
    // Claude API Request
    // =========================================================================

    private function sendTextRequest(string $prompt, int $maxTokens): \Illuminate\Http\Client\Response
    {
        $response = Http::withHeaders([
            'x-api-key'         => $this->apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])
        ->timeout(300)
        ->post($this->endpoint, [
            'model'      => $this->model,
            'max_tokens' => $maxTokens,
            'messages'   => [
                [
                    'role'    => 'user',
                    'content' => $prompt,
                ],
            ],
        ]);

        if ($response->failed()) {
            throw new RuntimeException(
                'Claude API request failed: ' . $response->status() . ' ' . $response->body()
            );
        }

        return $response;
    }

    // =========================================================================
    // PDF TEXT EXTRACTION
    // =========================================================================

    private function extractTextFromFile(string $path): string
    {
        if (class_exists(\Spatie\PdfToText\Pdf::class)) {
            try {
                $text = \Spatie\PdfToText\Pdf::getText($path);
                if (!empty(trim($text))) {
                    return $this->cleanText($text);
                }
            } catch (\Throwable) {}
        }

        if (class_exists(\Smalot\PdfParser\Parser::class)) {
            try {
                $config = new \Smalot\PdfParser\Config();
                $config->setIgnoreEncryption(true);
                $parser = new \Smalot\PdfParser\Parser([], $config);
                $pdf    = $parser->parseFile($path);
                $text   = $pdf->getText();
                if (!empty(trim($text))) {
                    return $this->cleanText($text);
                }
            } catch (\Throwable) {}
        }

        return '[Text extraction failed — no readable content could be extracted from the PDF]';
    }

    private function cleanText(string $text): string
    {
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\r\n|\r/', "\n", $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    // =========================================================================
    // RESPONSE SANITISATION
    // =========================================================================

    private function sanitise(string $text): string
    {
        $text = trim($text);

        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/i', '', $text);

        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', ' ', $text);

        if (!str_starts_with($text, '{')) {
            if (preg_match('/\{.*\}/s', $text, $m)) {
                $text = $m[0];
            } elseif (preg_match('/\{/', $text, $m, PREG_OFFSET_CAPTURE)) {
                $text = substr($text, $m[0][1]);
            }
        }

        if (json_decode($text, true) === null) {
            $text = $this->repairTruncatedJson($text);
        }

        return $text;
    }

    // =========================================================================
    // TRUNCATED JSON REPAIR
    // =========================================================================

    private function repairTruncatedJson(string $json): string
    {
        $len      = strlen($json);
        $stack    = [];
        $inString = false;
        $escape   = false;

        for ($i = 0; $i < $len; $i++) {
            $ch = $json[$i];

            if ($escape) {
                $escape = false;
                continue;
            }

            if ($ch === '\\' && $inString) {
                $escape = true;
                continue;
            }

            if ($ch === '"') {
                $inString = !$inString;
                continue;
            }

            if ($inString) {
                continue;
            }

            if ($ch === '{' || $ch === '[') {
                $stack[] = $ch;
            } elseif ($ch === '}' || $ch === ']') {
                array_pop($stack);
            }
        }

        $suffix = '';

        if ($inString) {
            $suffix .= '"';
        }

        $trimmed = rtrim($json);

        if (str_ends_with($trimmed, ',')) {
            $json = rtrim($trimmed, ',');
        }

        foreach (array_reverse($stack) as $open) {
            $suffix .= ($open === '{') ? '}' : ']';
        }

        return $json . $suffix;
    }
}
