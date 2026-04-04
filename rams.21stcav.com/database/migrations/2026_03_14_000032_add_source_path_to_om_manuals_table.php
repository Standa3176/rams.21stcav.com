<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add source_path to om_manuals.
 *
 * OmManualGeneratorService stores a persistent copy of the uploaded source PDF
 * under storage/app/om-sources/ and records its path here so the document can be
 * re-processed (Pass 1 re-extraction) without requiring the user to re-upload.
 *
 * Also adds project_id via the shared 2026_03_14_000004 migration — this
 * migration only handles the column that was missing from the original schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('om_manuals', function (Blueprint $table) {
            // Path within the local storage disk, e.g. om-sources/uuid.pdf
            $table->string('source_path', 500)->nullable()->after('source_filename');
        });
    }

    public function down(): void
    {
        Schema::table('om_manuals', function (Blueprint $table) {
            $table->dropColumn('source_path');
        });
    }
};
