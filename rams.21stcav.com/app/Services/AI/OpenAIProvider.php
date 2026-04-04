<?php

namespace App\Services\AI;

use App\Services\PromptBuilderService;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAIProvider implements AIProviderInterface
{
    private string $apiKey;
    private string $model;
    private string $endpoint;

    public function __construct(private readonly PromptBuilderService $promptBuilder)
    {
        $this->apiKey   = config('ai.providers.openai.api_key');
        $this->model    = config('ai.providers.openai.model');
        $this->endpoint = config('ai.providers.openai.endpoint');
    }


    /**
     * Generate RAMS from pre-extracted text + equipment list (text-only path).
     * Mirrors ClaudeProvider::generateRamsFromText for provider consistency.
     */
    public function generateRamsFromText(string $quoteText, array $equipment, ?string $retrySuffix = null): array
    {
        $prompt = $this->promptBuilder->buildFromText($quoteText, $equipment, $retrySuffix);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'content-type'  => 'application/json',
        ])
        ->timeout(300)
        ->post($this->endpoint, [
            'model'           => $this->model,
            'max_tokens'      => 8192,
            'response_format' => ['type' => 'json_object'],
            'messages'        => [
                [
                    'role'    => 'system',
                    'content' => 'You are a UK Health & Safety expert. Respond only with valid JSON, no markdown.',
                ],
                [
                    'role'    => 'user',
                    'content' => $prompt,
                ],
            ],
        ]);

        if ($response->failed()) {
            throw new RuntimeException(
                'OpenAI API (text) request failed: ' . $response->status() . ' ' . $response->body()
            );
        }

        $raw     = $this->sanitise($response->json('choices.0.message.content', ''));
        $decoded = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException(
                'OpenAI (text) returned invalid JSON: ' . json_last_error_msg() . ' — Raw: ' . substr($raw, 0, 500)
            );
        }

        return $decoded;
    }

    public function generateRamsFromFiles(array $files, ?string $retrySuffix = null): array
    {
        // Use the text pipeline — extract PDF text locally, then call generateRamsFromText
        $quoteText = $this->extractPdfText($files['quote']['path']);
        return $this->generateRamsFromText($quoteText, [], $retrySuffix);
    }

    /** @deprecated Use generateRamsFromText instead */
    private function generateRamsFromFilesLegacy(array $files, ?string $retrySuffix = null): array
    {
        $hasDrawings = ! empty($files['drawings']);
        $prompt      = $this->promptBuilder->buildFromFiles($hasDrawings, $retrySuffix);

        // Extract quote PDF text (OpenAI does not accept native PDF documents)
        $quoteText = $this->extractPdfText($files['quote']['path']);

        $content = [];

        // Quote as extracted text
        $content[] = [
            'type' => 'text',
            'text' => "QUOTEWERKS QUOTE (text extracted from PDF):\n\n" . $quoteText,
        ];

        // Drawings: raster images via vision, PDFs via text extraction
        foreach ($files['drawings'] ?? [] as $drawing) {
            if ($drawing['mime'] === 'application/pdf') {
                $drawingText = $this->extractPdfText($drawing['path']);
                $content[]   = [
                    'type' => 'text',
                    'text' => "SITE DRAWING (text extracted from PDF):\n\n" . $drawingText,
                ];
            } else {
                $imageData = base64_encode(file_get_contents($drawing['path']));
                $content[] = [
                    'type'      => 'image_url',
                    'image_url' => [
                        'url'    => 'data:' . $drawing['mime'] . ';base64,' . $imageData,
                        'detail' => 'high',
                    ],
                ];
            }
        }

        // Prompt at the end
        $content[] = ['type' => 'text', 'text' => $prompt];

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'content-type'  => 'application/json',
        ])->post($this->endpoint, [
            'model'           => $this->model,
            'max_tokens'      => 8192,
            'response_format' => ['type' => 'json_object'],
            'messages'        => [
                [
                    'role'    => 'system',
                    'content' => 'You are a UK Health & Safety expert. Respond only with valid JSON, no markdown.',
                ],
                [
                    'role'    => 'user',
                    'content' => $content,
                ],
            ],
        ]);

        if ($response->failed()) {
            throw new RuntimeException(
                'OpenAI API (files) request failed: ' . $response->status() . ' ' . $response->body()
            );
        }

        $raw     = $response->json('choices.0.message.content', '');
        $decoded = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException(
                'OpenAI (files) returned invalid JSON: ' . json_last_error_msg() . ' — Raw: ' . $raw
            );
        }

        return $decoded;
    }

    /**
     * Extract readable text from a PDF using smalot/pdfparser.
     * Falls back gracefully if extraction fails or yields no text.
     */

    private function sanitise(string $text): string
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/i', '', $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', ' ', $text);
        $text = trim($text);

        if (! str_starts_with($text, '{')) {
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

    private function repairTruncatedJson(string $json): string
    {
        $len = strlen($json); $stack = []; $inString = false; $escape = false;
        for ($i = 0; $i < $len; $i++) {
            $ch = $json[$i];
            if ($escape) { $escape = false; continue; }
            if ($ch === '\\' && $inString) { $escape = true; continue; }
            if ($ch === '"') { $inString = ! $inString; continue; }
            if ($inString) continue;
            if ($ch === '{' || $ch === '[') $stack[] = $ch;
            elseif ($ch === '}' || $ch === ']') array_pop($stack);
        }
        $suffix = '';
        if ($inString) $suffix .= '"';
        $trimmed = rtrim($json);
        if (str_ends_with($trimmed, ',')) { $json = rtrim($trimmed, ','); $suffix = ''; }
        foreach (array_reverse($stack) as $open) $suffix .= ($open === '{') ? '}' : ']';
        return $json . $suffix;
    }

    private function extractPdfText(string $absolutePath): string
    {
        try {
            $config = new \Smalot\PdfParser\Config();
            $config->setIgnoreEncryption(true);
            $parser = new \Smalot\PdfParser\Parser([], $config);
            $pdf    = $parser->parseFile($absolutePath);
            $text   = $pdf->getText();

            if (empty(trim($text))) {
                return '[PDF text extraction yielded no readable content — '
                    . 'the file may be image-based. Hazard assessment will rely on context only.]';
            }

            return $text;
        } catch (\Throwable $e) {
            return '[PDF text extraction failed: ' . $e->getMessage() . ']';
        }
    }

    public function generateRams(array $formData): array
    {
        $prompt = $this->promptBuilder->build($formData);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'content-type'  => 'application/json',
        ])->post($this->endpoint, [
            'model'           => $this->model,
            'max_tokens'      => 4096,
            'response_format' => ['type' => 'json_object'],
            'messages'        => [
                [
                    'role'    => 'system',
                    'content' => 'You are a UK Health & Safety expert. Respond only with valid JSON, no markdown.',
                ],
                [
                    'role'    => 'user',
                    'content' => $prompt,
                ],
            ],
        ]);

        if ($response->failed()) {
            throw new RuntimeException(
                'OpenAI API request failed: ' . $response->status() . ' ' . $response->body()
            );
        }

        $raw = $response->json('choices.0.message.content', '');

        $decoded = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException(
                'OpenAI returned invalid JSON: ' . json_last_error_msg() . ' — Raw: ' . $raw
            );
        }

        return $decoded;
    }
}
