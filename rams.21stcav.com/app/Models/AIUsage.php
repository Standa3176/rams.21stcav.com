<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AIUsage extends Model
{
    protected $table = 'ai_usages';

    protected $fillable = [
        'provider',
        'model',
        'prompt',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'cost_usd',
        'meta',
    ];

    protected $casts = [
        'input_tokens'  => 'integer',
        'output_tokens' => 'integer',
        'total_tokens'  => 'integer',
        'cost_usd'      => 'decimal:6',
        'meta'          => 'array',
    ];
}
