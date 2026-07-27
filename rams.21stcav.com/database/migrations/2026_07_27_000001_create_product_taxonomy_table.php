<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 260727-wt1 Plan 01 — DB-backed worksheet product taxonomy.
 *
 * Ports config/worksheet_taxonomy.php entries into a DB catalogue so that:
 *  - New SKUs can be classified without a code deploy;
 *  - The QW review page can auto-learn from PM classifications (Plan 04);
 *  - Admin can promote / correct / delete learned rows (Plan 05).
 *
 * PLAN 01 IS PURE ADDITIVE SCAFFOLDING — the classifier still reads from
 * the config file until Plan 02 flips the WORKSHEET_TAXONOMY_DB kill
 * switch. This migration only lands the primitive.
 *
 * Category ENUM values mirror config('worksheet_taxonomy.categories') keys
 * exactly, plus 'unclassified' as an explicit sentinel — never rendered
 * as a real category label.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_taxonomy', function (Blueprint $table) {
            $table->id();

            // ── Matching surface (any / all of these may be NULL) ─────────────
            // sku_pattern       — Tier 1 exact match (or '*' wildcard, Plan 02).
            // manufacturer      — Tier 2 partial match (lowercased at query time).
            // description_pattern — Tier 2/3 substring match against name/desc.
            $table->string('sku_pattern', 120)->nullable();
            $table->string('manufacturer', 60)->nullable();
            $table->string('description_pattern', 255)->nullable();

            // Human-readable family label ("Crestron TSW-1070 series") — admin
            // UI grouping only, never used for matching.
            $table->string('product_family', 120)->nullable();

            // ── Verdict + provenance ──────────────────────────────────────────
            // ENUM values MUST stay in lock-step with config('worksheet_taxonomy.categories')
            // keys + 'unclassified' sentinel. If a 7th category is ever added,
            // the ENUM has to grow first, then the config, then the seeder.
            $table->enum('worksheet_category', [
                'display',
                'video_conferencing',
                'audio',
                'control',
                'rack',
                'network',
                'unclassified',
            ]);

            $table->text('install_step_hint')->nullable();

            $table->enum('source', ['seed', 'learned', 'admin'])->default('seed');

            // ── Learning + promotion audit trail ──────────────────────────────
            // FK columns use nullOnDelete so deleting a package / user does not
            // cascade-nuke catalogue rows — the taxonomy outlives its origin
            // once a PM has classified a novel SKU.
            $table->foreignId('learned_from_package_id')
                ->nullable()
                ->constrained('project_packages')
                ->nullOnDelete();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('promoted_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('promoted_at')->nullable();

            $table->timestamps();

            // Plan 05 admin resource uses soft-delete so a bad promotion can
            // be reverted without losing the learning provenance.
            $table->softDeletes();

            // ── Indexes ───────────────────────────────────────────────────────
            // Single-column index on sku_pattern → Tier 1 fast path.
            // Composite (manufacturer, description_pattern) → Tier 2 fast path.
            // Category index → admin UI + Plan 05 review queue filters.
            $table->index('sku_pattern');
            $table->index(['manufacturer', 'description_pattern']);
            $table->index('worksheet_category');
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_taxonomy');
    }
};
