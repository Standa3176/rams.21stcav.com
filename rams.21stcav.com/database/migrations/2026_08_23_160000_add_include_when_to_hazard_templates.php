<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 26 (Hazard Library Structural Inversion) — HAZ-01 / HAZ-02.
 *
 * Adds `include_when` to `hazard_templates`: the tiered condition string that
 * drives whether a hazard is auto-included in a generated RAMS register.
 * Convention (locked by 26-CONTEXT.md D-05/D-06, established by this plan):
 *   - 'always'          — tier 1, unconditional (4 hazards).
 *   - 'signal:<key>'    — tier 2, deterministic keyword/tag match (9 hazards).
 *   - 'confirm:<key>'   — tier 3, always surfaced requiring human confirmation
 *                          (5 hazards). NOT an AI-evaluated condition — no AI
 *                          call decides hazard inclusion anywhere in this
 *                          phase, per CLAUDE.md's AI-usage constraint ("AI is
 *                          ONLY allowed for formatting and method statement
 *                          structuring — never for inventing scope").
 *   - null              — manual-only (D-04): every is_global=false
 *                          (user-created) row keeps include_when null and is
 *                          never auto-populated; no seeder row is ever null.
 *
 * Nullable text column, guarded with Schema::hasColumn so re-running this
 * migration (e.g. duplicate-apply in SQLite test runs) is a no-op rather than
 * an error. Reversible via down() — dropping the column does not affect the
 * `name`/`controls`/score columns the rest of the row depends on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hazard_templates', function (Blueprint $table) {
            if (! Schema::hasColumn('hazard_templates', 'include_when')) {
                $table->text('include_when')->nullable()->after('controls');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hazard_templates', function (Blueprint $table) {
            $table->dropColumn('include_when');
        });
    }
};
