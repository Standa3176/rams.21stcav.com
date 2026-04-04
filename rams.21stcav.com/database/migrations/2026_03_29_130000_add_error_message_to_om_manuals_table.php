<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add error_message to om_manuals.
 *
 * Supports the async BuildOmManualJob pattern: when generation fails the job
 * stores a human-readable error here so the UI can show a retry button with
 * context, matching the existing RamsDocument.error_message behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('om_manuals', function (Blueprint $table) {
            $table->text('error_message')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('om_manuals', function (Blueprint $table) {
            $table->dropColumn('error_message');
        });
    }
};
