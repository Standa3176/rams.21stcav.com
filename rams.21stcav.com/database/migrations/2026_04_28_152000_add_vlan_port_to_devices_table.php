<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wire-up phase — adds the two columns the OM Network table (Section 9)
 * needs that weren't part of the original Phase 4 asset-register schema.
 *
 * Both nullable; existing rows are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('vlan')->nullable()->after('ip_address');
            $table->string('port')->nullable()->after('vlan');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['vlan', 'port']);
        });
    }
};
