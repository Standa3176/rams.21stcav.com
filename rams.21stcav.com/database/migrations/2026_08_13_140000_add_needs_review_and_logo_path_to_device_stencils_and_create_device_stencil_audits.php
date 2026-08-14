<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 24 Plan 01 Task 1 — schema foundation for stencil curation
 * (CONTEXT.md D-10 needs_review, D-15 logo_path, D-03 device_stencil_audits).
 *
 *   - device_stencils.needs_review: real INDEXED boolean column (D-10). The
 *     admin curation list view filters `?source=auto-generated&needs_review=1`
 *     (DRAW-50 criterion 3) — MariaDB cannot index a json extract, so a
 *     metadata-based filter would table-scan every stencil on every list
 *     load. metadata keeps its Phase 21 D-02 role (notes / last-edited-by).
 *
 *   - device_stencils.logo_path: nullable file-storage sibling to the
 *     existing inline-SVG-text `logo_svg` column (D-15). DRAW-52 needs
 *     PNG/SVG upload support, which an inline-text column alone can't serve.
 *
 *   - device_stencil_audits: dedicated curation audit trail (D-03). Generic
 *     name per 21 D-09 (no rams_ prefix) so it ports to SCC without rename.
 *     NOT ProjectActivityLog (device_stencils are deliberately project-less —
 *     that's what makes cross-project promotion propagation work) and NOT
 *     metadata alone (that only ever holds the LAST edit, not full history).
 *
 * Backfill (Pitfall 1 — MUST be PHP, never raw SQL JSON functions): this
 * migration runs against MariaDB in production and SQLite :memory: under the
 * test suite (phpunit.xml). Raw `JSON_EXTRACT(...)` / `->>` syntax diverges
 * across those two engines — looping in PHP with json_decode() is portable
 * everywhere. ~96 existing device_stencils rows per the phase audit — no
 * chunking needed.
 *
 * @see app/Models/DeviceStencilAudit.php
 * @see app/Services/Drawings/DeviceStencilCacheService.php — needs_review write-through
 * @see .planning/phases/24-stencil-curation-ui-quote-import-auto-stub/24-CONTEXT.md (D-03, D-10, D-15)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_stencils', function (Blueprint $table) {
            $table->boolean('needs_review')->default(false)->index();
            $table->string('logo_path', 255)->nullable();
        });

        Schema::create('device_stencil_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_stencil_id')
                ->constrained('device_stencils')
                ->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            // action: promote / edit / discard-regenerate (see
            // DeviceStencilAudit::ACTION_* constants).
            $table->string('action', 30);
            $table->json('before_snapshot')->nullable();
            $table->json('after_snapshot')->nullable();
            $table->timestamps();
        });

        // Backfill: carry existing metadata.needs_phase_24_curation = true
        // flags (written by Plan 21-02's seed pack) across into the new real
        // column. PHP-based per Pitfall 1 — do NOT rewrite this as raw SQL.
        DB::table('device_stencils')->get()->each(function ($row) {
            $metadata = json_decode((string) $row->metadata, true) ?: [];
            if (($metadata['needs_phase_24_curation'] ?? false) === true) {
                DB::table('device_stencils')->where('id', $row->id)->update(['needs_review' => true]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_stencil_audits');

        // Drop the index explicitly before dropping its column — SQLite's
        // table-rebuild-based ALTER TABLE otherwise leaves a dangling index
        // definition pointing at the just-dropped column.
        Schema::table('device_stencils', function (Blueprint $table) {
            $table->dropIndex(['needs_review']);
        });

        Schema::table('device_stencils', function (Blueprint $table) {
            $table->dropColumn(['needs_review', 'logo_path']);
        });
    }
};
