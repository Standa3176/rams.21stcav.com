<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Upgrade site surveys for the enterprise survey module.
 *
 * site_surveys:
 *   - status   ('draft' | 'completed')
 *   - filename  saved PDF filename
 *
 * Note: project_id is added to site_surveys by
 *   2026_03_14_000004_add_project_id_to_module_tables — not repeated here.
 *
 * site_survey_rooms — full infrastructure measurement fields:
 *   Dimensions     : room_width_m, room_depth_m, room_height_m
 *   Ceiling/walls  : ceiling_type, ceiling_height_m, wall_material, floor_type
 *   Services       : power_outlet_count, network_port_count, existing_cabling,
 *                    requires_additional_power
 *   AV             : av_equipment_list
 *   Access         : access_notes
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── site_surveys header fields ────────────────────────────────────────
        Schema::table('site_surveys', function (Blueprint $table) {
            $table->string('status', 30)->default('draft')->after('general_notes');
            $table->string('filename', 255)->nullable()->after('status');
        });

        // ── site_survey_rooms infrastructure columns ──────────────────────────
        Schema::table('site_survey_rooms', function (Blueprint $table) {
            // Room dimensions
            $table->decimal('room_width_m',  5, 2)->nullable()->after('floor');
            $table->decimal('room_depth_m',  5, 2)->nullable()->after('room_width_m');
            $table->decimal('room_height_m', 5, 2)->nullable()->after('room_depth_m');

            // Ceiling & structure
            $table->string('ceiling_type', 50)->nullable()->after('room_height_m');
                // values: concrete | suspended | plasterboard | open | other
            $table->decimal('ceiling_height_m', 5, 2)->nullable()->after('ceiling_type');
            $table->string('wall_material', 50)->nullable()->after('ceiling_height_m');
                // values: brick | plasterboard | glass | concrete | other
            $table->string('floor_type', 50)->nullable()->after('wall_material');
                // values: concrete | carpet | tiles | raised | other

            // Power & network services
            $table->unsignedSmallInteger('power_outlet_count')->default(0)->after('has_network');
            $table->unsignedSmallInteger('network_port_count')->default(0)->after('power_outlet_count');
            $table->text('existing_cabling')->nullable()->after('network_port_count');
            $table->boolean('requires_additional_power')->default(false)->after('existing_cabling');

            // AV & access
            $table->text('av_equipment_list')->nullable()->after('requires_additional_power');
                // existing equipment already present in the room
            $table->text('access_notes')->nullable()->after('av_equipment_list');
                // hazards, restricted access, RAMS-relevant conditions
        });
    }

    public function down(): void
    {
        Schema::table('site_surveys', function (Blueprint $table) {
            $table->dropColumn(['status', 'filename']);
        });

        Schema::table('site_survey_rooms', function (Blueprint $table) {
            $table->dropColumn([
                'room_width_m', 'room_depth_m', 'room_height_m',
                'ceiling_type', 'ceiling_height_m',
                'wall_material', 'floor_type',
                'power_outlet_count', 'network_port_count',
                'existing_cabling', 'requires_additional_power',
                'av_equipment_list', 'access_notes',
            ]);
        });
    }
};
