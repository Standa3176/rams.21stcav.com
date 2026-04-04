<?php

namespace App\Core\AI\Providers;

use App\Core\AI\Contracts\AIProviderContract;
use App\Core\AI\Prompts\BasePrompt;
use App\Services\AIUsageService;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * OpenAI provider for the enterprise AI abstraction layer.
 *
 * Sends PDFs as data URIs for vision-capable models (gpt-4o).
 * Config keys: ai.providers.openai.api_key / model / endpoint
 */
class OpenAIProvider implements AIProviderContract
{
    private const DEFAULT_TIMEOUT = 180;

    private string $apiKey;
    private string $model;
    private string $endpoint;

    public function __construct()
    {
        $this->apiKey   = config('ai.providers.openai.api_key', '');
        $this->model    = config('ai.providers.openai.model', 'gpt-4o');
        $this->endpoint = config('ai.providers.openai.endpoint', 'https://api.openai.com/v1/chat/completions');
    }

    // ── AIProviderContract ────────────────────────────────────────────────────

    public function completeJson(string $prompt, array $options = []): array
    {
        $messages = $this->buildMessages($prompt, $options['system'] ?? null);

        return $this->dispatch($messages, $options);
    }

    public function completeWithPdf(string $pdfBase64, string $prompt, array $options = []): array
    {
        $messages = $this->buildMessages(null, $options['system'] ?? null);

        // Append the PDF as a data URI + the prompt text as a vision message
        $messages[] = [
            'role'    => 'user',
            'content' => [
                ['type' => 'text', 'text' => $prompt],
                [
                    'type'      => 'image_url',
                    'image_url' => ['url' => 'data:application/pdf;base64,' . $pdfBase64],
                ],
            ],
        ];

        return $this->dispatch($messages, $options);
    }

    public function execute(BasePrompt $prompt): array
    {
        $text    = $prompt->build();
        $options = [
            'max_tokens' => $prompt->maxTokens(),
            'system'     => $prompt->systemMessage(),
            'prompt_class' => get_class($prompt),
        ];

        if ($prompt->usesPdf()) {
            return $this->completeWithPdf($prompt->getPdfBase64(), $text, $options);
        }

        return $this->completeJson($text, $options);
    }

    public function getProviderKey(): string
    {
        return 'openai';
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Build the OpenAI messages array with an optional system message.
     * When $userContent is null, the user turn is omitted (caller appends it).
     */
    private function buildMessages(?string $userContent, ?string $system): array
    {
        $messages = [];

        if ($system) {
            $messages[] = ['role' => 'system', 'content' => $system];
        } else {
            $messages[] = [
                'role'    => 'system',
                'content' => 'You are a senior AV systems expert. Respond ONLY with valid JSON — no markdown, no commentary.',
            ];
        }

        if ($userContent !== null) {
            $messages[] = ['role' => 'user', 'content' => $userContent];
        }

        return $messages;
    }

    private function dispatch(array $messages, array $options): array
    {
        $payload = [
            'model'           => $this->model,
            'max_tokens'      => $options['max_tokens'] ?? 4096,
            'response_format' => ['type' => 'json_object'],
            'messages'        => $messages,
        ];

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'content-type'  => 'application/json',
        ])
        ->timeout($options['timeout'] ?? self::DEFAULT_TIMEOUT)
        ->post($this->endpoint, $payload);

        if ($response->failed()) {
            throw new RuntimeException(
                "OpenAI API error [{$response->status()}]: " . $response->body()
            );
        }

        $inputTokens  = $response->json('usage.prompt_tokens');
        $outputTokens = $response->json('usage.completion_tokens');
        $totalTokens  = $response->json('usage.total_tokens');
        if ($totalTokens === null && $inputTokens !== null && $outputTokens !== null) {
            $totalTokens = (int) $inputTokens + (int) $outputTokens;
        }

        app(AIUsageService::class)->record([
            'provider'      => $this->getProviderKey(),
            'model'         => $this->model,
            'prompt'        => $options['prompt_class'] ?? null,
            'input_tokens'  => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens'  => $totalTokens,
        ]);

        $raw     = $response->json('choices.0.message.content', '');
        $decoded = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException(
                'OpenAI returned invalid JSON: ' . json_last_error_msg()
                . ' — Raw: ' . substr($raw, 0, 500)
            );
        }

        return $decoded;
    }
}
