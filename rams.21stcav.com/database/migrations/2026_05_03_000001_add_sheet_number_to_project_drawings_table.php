<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 20 Plan 01 — adds the AVIXA sheet-number column to project_drawings.
 *
 * Phase 20 introduces auto-derived AV-style sheet numbering on every drawing
 * (DRAW-23):
 *   - Schematics: AV-201, AV-202, ... AV-299
 *   - Racks:      AV-301, AV-302, ... AV-399
 *   - Floor plans (v2.0): AV-101..AV-199 (deferred — no allocator branch yet)
 *
 * Allocation is set ONCE on draft create by SheetNumberAllocator + DrawingService;
 * never re-derived on regenerate (a regenerated AV-201 stays AV-201). The
 * column is nullable so any pre-existing drawings (Phase 17/18 rows) remain
 * valid — the bound-PDF cover sheet renders an em-dash for missing numbers.
 *
 * Library licence audit (T-20-08):
 *   - This plan adds setasign/fpdi (MIT) + setasign/fpdf (permissive,
 *     "no usage restriction") for the bound-PDF concatenation primitive.
 *   - Both verified MIT-equivalent via `composer licenses` — keeps us
 *     OUT of TCPDF's LGPL trap (MOD-01).
 *
 * @see app/Services/Drawings/SheetNumberAllocator.php
 * @see app/Services/Drawings/DrawingService.php::createForProject
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_drawings', function (Blueprint $table) {
            $table->string('sheet_number', 20)->nullable()->after('version');
        });
    }

    public function down(): void
    {
        Schema::table('project_drawings', function (Blueprint $table) {
            $table->dropColumn('sheet_number');
        });
    }
};
