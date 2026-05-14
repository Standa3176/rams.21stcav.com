<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 23 Plan 01 Task 2 — adds projects.metadata JSON column.
 *
 * Purpose: per CONTEXT D-08 the Phase 23 title block reads `drawing_checked_by`
 * from `Project.metadata.drawing_checked_by`; per D-06 the SheetPaginator's
 * tinker override reads `Project.metadata.force_sheets`. Generic name (no
 * `rams_` prefix) per D-09 carry-forward (SCC merge readiness).
 *
 * Strictly additive — NULL default — existing projects unaffected. Phase 21
 * D-10 invariant: v1.3 D2 generator surfaces never read this column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // JSON nullable. Default NULL — Phase 23 writes happen via tinker
            // only; Phase 24 force-sheet UI is the first writer surface.
            $table->json('metadata')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('metadata');
        });
    }
};
