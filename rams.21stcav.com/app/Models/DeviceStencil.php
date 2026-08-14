<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 21 Plan 01 — pre-rendered mxGraph stencil per part_number, cached
 * cross-project via DeviceStencilCacheService::firstOrCreate (D-03).
 *
 * Drives Phase 23's renderer. Phase 24's curation UI promotes auto-generated
 * Tier 1 placeholders to engineer-curated Tier 2 stencils in-place — because
 * the cache is keyed on part_number, every project that referenced the same
 * part_number automatically picks up the upgrade on next render.
 *
 * Source semantics (D-04):
 *   - SOURCE_AUTO_GENERATED — Tier 1 placeholder built by
 *     AutoGenericStencilGenerator on first reference of an uncatalogued
 *     part_number. Visually basic (rectangle + manufacturer/model/part_number
 *     text). NO port rails — Phase 24 adds them when an engineer curates.
 *
 *   - SOURCE_ENGINEER_CURATED — promoted by a human via Phase 24's admin UI.
 *     Has port rails, manufacturer logo glyph, brand-aligned visuals matching
 *     the XTEN-AV PAGING SYSTEM reference (visual contract for v2.0).
 *
 *   - SOURCE_AI_EXTRACTED — Phase 25 Tier 3: ports extracted from manufacturer
 *     datasheet PDFs via Claude vision. Reserved enum value, not written by
 *     any code in Phase 21.
 *
 * Naming (D-09): generic — no rams_ / project_ prefix — so the table ports
 * to SCC after the planned RAMS+SCC merge.
 *
 * @see app/Services/Drawings/DeviceStencilCacheService.php
 * @see app/Services/Drawings/AutoGenericStencilGenerator.php
 * @see .planning/phases/21-device-port-catalog-stencil-cache/21-CONTEXT.md
 *
 * @property int $id
 * @property string $part_number normalised (lowercase trim)
 * @property ?string $manufacturer
 * @property ?string $model
 * @property ?string $display_name fallback to manufacturer+model when null
 * @property string $mxgraph_xml full <shape>...</shape> XML
 * @property ?string $logo_svg inline SVG glyph fallback
 * @property int $default_width
 * @property int $default_height
 * @property string $source SOURCE_* constant value
 * @property ?array $metadata reserved for Phase 24 curation extras
 * @property bool $needs_review Phase 24 D-10 — indexed review-queue flag
 * @property ?string $logo_path Phase 24 D-15 — uploaded logo file path (sibling to logo_svg)
 */
class DeviceStencil extends Model
{
    // ── Source enum (D-04) ────────────────────────────────────────────────────
    // Mirrors the Device::ROLE_* pattern — public const for explicit
    // import-free reference at call sites + lint-friendly constant comparison.

    public const SOURCE_AUTO_GENERATED = 'auto-generated';

    public const SOURCE_ENGINEER_CURATED = 'engineer-curated';

    public const SOURCE_AI_EXTRACTED = 'ai-extracted';

    protected $fillable = [
        'part_number',
        'manufacturer',
        'model',
        'display_name',
        'mxgraph_xml',
        'logo_svg',
        'default_width',
        'default_height',
        'source',
        'metadata',
        'needs_review',
        'logo_path',
    ];

    protected $casts = [
        'metadata'       => 'array',
        'default_width'  => 'integer',
        'default_height' => 'integer',
        'needs_review'   => 'boolean',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * Ports owned by this stencil, ordered for stable rendering. Phase 22's
     * cable schedule joins through here for port-level termination metadata.
     */
    public function ports(): HasMany
    {
        return $this->hasMany(DevicePort::class)->orderBy('sort_order');
    }

    /**
     * Phase 24 D-03/D-08 — curation audit trail. `stencils:reapply-templates`
     * uses `whereDoesntHave('audits')` to scope re-templating eligibility: a
     * stencil with ANY audit row (promoted or hand-edited) is never touched.
     */
    public function audits(): HasMany
    {
        return $this->hasMany(DeviceStencilAudit::class);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * True when this stencil has been promoted past the Tier 1 placeholder
     * stage (engineer-curated or AI-extracted). Phase 23's renderer can
     * surface a "needs curation" badge when isCurated() === false.
     */
    public function isCurated(): bool
    {
        return $this->source !== self::SOURCE_AUTO_GENERATED;
    }

    /**
     * Normalise a part_number for cache lookup — lowercase trim. Mirrors the
     * key derivation in DeviceCatalogService::all() so part_numbers match
     * regardless of QuoteWerks export casing / whitespace.
     *
     * Cache writes also flow through this helper so the unique index on
     * device_stencils.part_number stays case-insensitive at the app layer.
     */
    public static function normalisePartNumber(string $partNumber): string
    {
        return strtolower(trim($partNumber));
    }
}
