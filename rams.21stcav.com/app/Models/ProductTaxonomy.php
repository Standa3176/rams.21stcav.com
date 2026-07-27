<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Phase 260727-wt1 Plan 01 — DB-backed worksheet product taxonomy row.
 *
 * One row per matching rule. The row's category ('display', 'audio', ...)
 * is the verdict returned when its matcher (sku_pattern +/- manufacturer
 * +/- description_pattern) fires against a quote line item.
 *
 * Provenance:
 *   source = 'seed'    — ported from config/worksheet_taxonomy.php by
 *                        ProductTaxonomySeeder. Baseline catalogue.
 *   source = 'learned' — auto-written when a PM classifies a novel SKU on
 *                        the /project-packages/{id}/review page (Plan 04).
 *                        learned_from_package_id + created_by are populated.
 *   source = 'admin'   — manually created / promoted from a learned row via
 *                        the admin resource (Plan 05). promoted_by +
 *                        promoted_at are populated.
 *
 * Plan 01 only lands the shape — Plans 2-5 wire the classifier + writer +
 * admin UI.
 */
class ProductTaxonomy extends Model
{
    use SoftDeletes;

    protected $table = 'product_taxonomy';

    // ── Source provenance sentinels ───────────────────────────────────────────
    // Kept as class constants so calling code (repository, learned-writer,
    // admin resource, tests) references a single symbol and typos fail at
    // compile time rather than silently matching zero rows.
    public const SOURCE_SEED    = 'seed';
    public const SOURCE_LEARNED = 'learned';
    public const SOURCE_ADMIN   = 'admin';

    // ── Category ENUM mirror ──────────────────────────────────────────────────
    // Must match the ENUM in the migration + config('worksheet_taxonomy.categories')
    // keys. 'unclassified' is an internal sentinel — never rendered.
    public const CATEGORY_DISPLAY            = 'display';
    public const CATEGORY_VIDEO_CONFERENCING = 'video_conferencing';
    public const CATEGORY_AUDIO              = 'audio';
    public const CATEGORY_CONTROL            = 'control';
    public const CATEGORY_RACK               = 'rack';
    public const CATEGORY_NETWORK            = 'network';
    public const CATEGORY_UNCLASSIFIED       = 'unclassified';

    /** @var list<string> */
    public const CATEGORIES = [
        self::CATEGORY_DISPLAY,
        self::CATEGORY_VIDEO_CONFERENCING,
        self::CATEGORY_AUDIO,
        self::CATEGORY_CONTROL,
        self::CATEGORY_RACK,
        self::CATEGORY_NETWORK,
        self::CATEGORY_UNCLASSIFIED,
    ];

    /** @var list<string> */
    public const SOURCES = [
        self::SOURCE_SEED,
        self::SOURCE_LEARNED,
        self::SOURCE_ADMIN,
    ];

    protected $fillable = [
        'sku_pattern',
        'manufacturer',
        'description_pattern',
        'product_family',
        'worksheet_category',
        'install_step_hint',
        'source',
        'learned_from_package_id',
        'created_by',
        'promoted_by',
        'promoted_at',
    ];

    protected $casts = [
        'promoted_at' => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function promoter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'promoted_by');
    }

    public function learnedFromPackage(): BelongsTo
    {
        return $this->belongsTo(ProjectPackage::class, 'learned_from_package_id');
    }
}
