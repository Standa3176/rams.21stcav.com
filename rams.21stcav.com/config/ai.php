<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider
    |--------------------------------------------------------------------------
    |
    | Supported: "claude", "openai"
    |
    */

    'default' => env('AI_DEFAULT_PROVIDER', 'claude'),

    /*
    |--------------------------------------------------------------------------
    | AI Response Cache TTL
    |--------------------------------------------------------------------------
    | Number of days to keep cached AI responses. Set to 0 to disable.
    */

    'cache_ttl_days' => (int) env('AI_CACHE_TTL_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | AI Provider Configurations
    |--------------------------------------------------------------------------
    */

    'providers' => [

        'claude' => [
            'api_key'  => env('CLAUDE_API_KEY'),
            'model'    => env('CLAUDE_MODEL', 'claude-sonnet-4-6'),
            'endpoint' => 'https://api.anthropic.com/v1/messages',
        ],

        'openai' => [
            'api_key'  => env('OPENAI_API_KEY'),
            'model'    => env('OPENAI_MODEL', 'gpt-4o'),
            'endpoint' => env('OPENAI_ENDPOINT', 'https://api.openai.com/v1/chat/completions'),
        ],

    ],

    /*
    |----------------------------------------------------------------------
    | Optional Pricing (USD per 1K tokens)
    |----------------------------------------------------------------------
    | Used for admin AI usage reporting. Leave as 0 to disable cost calc.
    */

    'pricing' => [
        'claude' => [
            'input_per_1k'  => (float) env('CLAUDE_INPUT_COST_PER_1K', 0),
            'output_per_1k' => (float) env('CLAUDE_OUTPUT_COST_PER_1K', 0),
        ],
        'openai' => [
            'input_per_1k'  => (float) env('OPENAI_INPUT_COST_PER_1K', 0),
            'output_per_1k' => (float) env('OPENAI_OUTPUT_COST_PER_1K', 0),
        ],
    ],

];
