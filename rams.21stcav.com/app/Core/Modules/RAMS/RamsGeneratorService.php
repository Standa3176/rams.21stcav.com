<?php

namespace App\Core\Modules\RAMS;

use App\Core\AI\AIManager;
use App\Core\AI\Prompts\RamsPrompt;
use App\Exceptions\AIGenerationException;

/**
 * Enterprise RAMS generator.
 *
 * Thin orchestration layer: passes form context to AIManager via RamsPrompt
 * and ensures all required top-level keys are present in the response.
 *
 * Usage:
 *   $data = app(RamsGeneratorService::class)->generate($formData, 'claude');
 */
class RamsGeneratorService
{
    private const REQUIRED_KEYS = [
        'project',
        'hazards',
        'ppe',
        'persons_at_risk',
        'regulations',
        'method_statement',
    ];

    /**
     * Generate a validated RAMS array via AIManager.
     *
     * AIManager handles retry logic and throws AIGenerationException on exhaustion.
     * This method additionally auto-fills any missing required keys with empty arrays
     * so a valid-but-incomplete AI response does not cause a downstream hard failure.
     *
     * @param  array        $context   Form data — keys must match RamsPrompt::build() expectations.
     * @param  string|null  $provider  'claude' | 'openai' | null (uses config default).
     * @return array
     *
     * @throws AIGenerationException  Propagated from AIManager after all retries are exhausted.
     */
    public function generate(array $context, ?string $provider = null): array
    {
        $result = AIManager::run(new RamsPrompt(), $context, $provider);

        foreach (self::REQUIRED_KEYS as $key) {
            if (! array_key_exists($key, $result)) {
                $result[$key] = [];
            }
        }

        return $result;
    }
}
