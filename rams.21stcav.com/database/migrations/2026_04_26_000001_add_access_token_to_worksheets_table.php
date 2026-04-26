<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Adds a UUID `access_token` column to `worksheets` so each worksheet can be
 * shared with the client via a no-auth public link — mirrors the precedent set
 * by site_surveys.access_token (see 2026_04_05_100000_add_token_fields_to_site_surveys_table).
 *
 * Token generation lives on the Worksheet model boot() hook for new rows.
 * Existing rows are backfilled here so the public-show route + Client Link
 * banner work for legacy worksheets created before this migration shipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worksheets', function (Blueprint $table) {
            $table->uuid('access_token')->nullable()->unique()->after('filename');
        });

        // Backfill — every legacy worksheet gets a UUID so publicUrl() never
        // throws and the Client Link banner renders without guards needing
        // to be sprinkled across views.
        DB::table('worksheets')
            ->whereNull('access_token')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('worksheets')
                        ->where('id', $row->id)
                        ->update(['access_token' => (string) Str::uuid()]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('worksheets', function (Blueprint $table) {
            $table->dropColumn('access_token');
        });
    }
};
