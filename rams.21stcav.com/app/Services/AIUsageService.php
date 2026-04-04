<?php

namespace App\Services;

use App\Models\AIUsage;

class AIUsageService
{
    /**
     * Record a single AI usage event.
     */
    public function record(array $payload): void
    {
        $provider = (string) ($payload['provider'] ?? '');
        $model    = (string) ($payload['model'] ?? '');
        $prompt   = (string) ($payload['prompt'] ?? '');

        $input  = $payload['input_tokens']  ?? null;
        $output = $payload['output_tokens'] ?? null;
        $total  = $payload['total_tokens']  ?? null;

        $cost = $this->estimateCost($provider, $input, $output);

        AIUsage::create([
            'provider'      => $provider ?: 'unknown',
            'model'         => $model ?: null,
            'prompt'        => $prompt ?: null,
            'input_tokens'  => $input !== null ? (int) $input : null,
            'output_tokens' => $output !== null ? (int) $output : null,
            'total_tokens'  => $total !== null ? (int) $total : null,
            'cost_usd'      => $cost,
            'meta'          => $payload['meta'] ?? null,
        ]);
    }

    private function estimateCost(string $provider, ?int $input, ?int $output): ?float
    {
        if ($input === null && $output === null) {
            return null;
        }

        $pricing = config("ai.pricing.{$provider}", []);

        $inRate  = (float) ($pricing['input_per_1k']  ?? 0);
        $outRate = (float) ($pricing['output_per_1k'] ?? 0);

        if ($inRate <= 0 && $outRate <= 0) {
            return null;
        }

        $inCost  = $input  !== null ? ($input / 1000)  * $inRate  : 0.0;
        $outCost = $output !== null ? ($output / 1000) * $outRate : 0.0;

        return round($inCost + $outCost, 6);
    }
}
