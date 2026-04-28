<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 4 — Tier 1 appendix item.
 *
 * Drawings (type='drawing') and user guides (type='user_guide') uploaded
 * against a project. The OM template registers these in the Drawings /
 * User Guides sections — file_path is referenced, files are not embedded.
 */
class Appendix extends Model
{
    /**
     * Laravel's default pluraliser maps "Appendix" → "appendixes" (regular
     * -es), but the migration uses the correct English plural "appendices".
     * Pin the table name so Eloquent finds the right schema.
     */
    protected $table = 'appendices';

    public const TYPE_DRAWING    = 'drawing';
    public const TYPE_USER_GUIDE = 'user_guide';
    public const TYPE_OTHER      = 'other';

    protected $fillable = [
        'project_id',
        'type',
        'title',
        'file_path',
        'reference_number',
        'revision',
        'date',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function isDrawing(): bool
    {
        return $this->type === self::TYPE_DRAWING;
    }
}
