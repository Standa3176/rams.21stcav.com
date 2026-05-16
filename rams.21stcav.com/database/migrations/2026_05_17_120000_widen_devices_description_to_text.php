<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen devices.description from VARCHAR(255) to TEXT.
 *
 * Real AV product descriptions exceed 255 chars regularly. Hit on Tilda
 * 21CQ29531-05-OPS (project 65, OmManual #16) during O&M generation —
 * OE Electrics Phase power module description is ~400 chars, blowing
 * MySQL's strict-mode VARCHAR(255) check:
 *
 *   "SQLSTATE[22001]: String data, right truncated:
 *    1406 Data too long for column 'description' at row 1"
 *
 * TEXT (64KB) is the natural fit — AV product blurbs are bounded by
 * vendor marketing copy, not user input, so 64KB is overkill but
 * future-proof. No index on description, so no perf hit from the
 * widening.
 *
 * Reversible: down() narrows back to VARCHAR(255), but any rows whose
 * description exceeds 255 chars will be truncated on rollback. Document
 * this risk in the migration body so a future operator doesn't roll
 * back blindly during a hot incident.
 *
 * @see app/Core/Modules/OMManual/OmManualGeneratorService.php
 *      (buildDeterministicSections inserts devices rows here)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->text('description')->change();
        });
    }

    public function down(): void
    {
        // Narrowing is destructive — any rows with description > 255
        // chars will be truncated. Confirm zero offending rows before
        // running this in production:
        //   SELECT id FROM devices WHERE CHAR_LENGTH(description) > 255;
        Schema::table('devices', function (Blueprint $table): void {
            $table->string('description', 255)->change();
        });
    }
};
