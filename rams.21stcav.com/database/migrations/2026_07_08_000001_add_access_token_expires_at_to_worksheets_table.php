<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit M-05 (2026-05-17) — worksheet access-token expiry column.
 *
 * Mirrors the site_surveys.expires_at precedent (see
 * 2026_04_05_100000_add_token_fields_to_site_surveys_table). Null = never
 * expires (existing worksheets stay valid). A future PM revoke action
 * regenerates the token AND sets expires_at to now() so any leaked copy
 * of the old URL becomes inert immediately.
 *
 * Existing rows are left with null expires_at deliberately — this is a
 * defence-in-depth column, not a retroactive lockdown.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worksheets', function (Blueprint $table) {
            $table->timestamp('access_token_expires_at')->nullable()->after('access_token');
        });
    }

    public function down(): void
    {
        Schema::table('worksheets', function (Blueprint $table) {
            $table->dropColumn('access_token_expires_at');
        });
    }
};
