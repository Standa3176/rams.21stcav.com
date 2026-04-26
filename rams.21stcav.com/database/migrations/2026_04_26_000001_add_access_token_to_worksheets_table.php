<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a UUID `access_token` column to `worksheets` so each worksheet can be
 * shared with the client via a no-auth public link — mirrors the precedent set
 * by site_surveys.access_token (see 2026_04_05_100000_add_token_fields_to_site_surveys_table).
 *
 * Token generation lives on the Worksheet model boot() hook so existing rows
 * remain nullable and a backfill is unnecessary; any worksheet created after
 * this migration ships will get a UUID automatically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worksheets', function (Blueprint $table) {
            $table->uuid('access_token')->nullable()->unique()->after('filename');
        });
    }

    public function down(): void
    {
        Schema::table('worksheets', function (Blueprint $table) {
            $table->dropColumn('access_token');
        });
    }
};
