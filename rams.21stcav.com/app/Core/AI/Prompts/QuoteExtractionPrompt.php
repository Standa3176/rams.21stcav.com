<?php

namespace App\Core\AI\Prompts;

/**
 * Standardizes pre-parsed equipment names via AI.
 *
 * Input:  array of ["quantity" => int, "name" => string]
 * Output: {"equipment":[{"quantity":0,"name":""}]}
 */
class QuoteExtractionPrompt extends BasePrompt
{
    /** @param array<int, array{quantity: int, name: string}> $items */
    public function __construct(private readonly array $items) {}

    public function systemMessage(): string
    {
        return 'You are a data formatter. Respond ONLY with valid JSON — no markdown, no commentary.';
    }

    public function maxTokens(): int
    {
        return 4096;
    }

    public function build(array $context = []): string
    {
        $isRetry     = (bool) ($context['is_retry'] ?? false);
        $retrySuffix = $isRetry ? $this->retrySuffix() : '';
        $json        = json_encode($this->items, JSON_THROW_ON_ERROR);

        return <<<PROMPT
Standardize these product names. Fix spelling, spacing, capitalization only.

{$json}

Return ONLY: {"equipment":[{"quantity":0,"name":""}]}{$retrySuffix}
PROMPT;
    }
}
