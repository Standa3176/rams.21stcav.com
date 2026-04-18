<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add ten new nullable columns for site survey gap fields.
     * All columns are nullable with no defaults so existing rows are unaffected.
     */
    public function up(): void
    {
        Schema::table('site_survey_rooms', function (Blueprint $table) {
            $table->string('cable_route_from', 500)->nullable();
            $table->string('cable_route_to',   500)->nullable();
            $table->boolean('is_rack_room')->nullable()->default(null);
            $table->decimal('projection_throw_m', 5, 2)->nullable();
            $table->decimal('viewing_distance_m', 5, 2)->nullable();
            $table->string('network_ssid',         255)->nullable();
            $table->string('network_vlan',          100)->nullable();
            $table->string('network_switch_port',   100)->nullable();
            $table->boolean('engineer_confirmed')->nullable()->default(null);
            $table->string('engineer_signature_name', 255)->nullable();
        });
    }

    /**
     * Remove all ten columns added in up().
     */
    public function down(): void
    {
        Schema::table('site_survey_rooms', function (Blueprint $table) {
            $table->dropColumn([
                'cable_route_from',
                'cable_route_to',
                'is_rack_room',
                'projection_throw_m',
                'viewing_distance_m',
                'network_ssid',
                'network_vlan',
                'network_switch_port',
                'engineer_confirmed',
                'engineer_signature_name',
            ]);
        });
    }
};
