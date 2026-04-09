<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Upgrades the site survey module from a meeting-room-only form to a
 * type-aware, multi-discipline survey engine.
 *
 * site_surveys:
 *   survey_type — discriminator: general | pa_system | infrastructure | signage | upgrade | mixed
 *
 * site_survey_rooms — type-specific measurement fields (all nullable, backward compatible):
 *   area_type        — how to classify this space
 *
 *   PA system:
 *     speaker_count    — number of speakers in the zone
 *     speaker_type     — ceiling | pendant | column | column_array | horn | sub | other
 *     speaker_mounting — ceiling_recessed | ceiling_surface | pendant | wall | bracket | other
 *     bg_noise_db      — background noise level in dB(A)
 *
 *   Digital signage:
 *     display_size_in  — screen diagonal in inches
 *     display_orient   — landscape | portrait
 *     display_mounting — wall_flush | wall_tilt | ceiling | floor_stand | other
 *
 *   Infrastructure:
 *     rack_unit_space  — available rack unit space
 *     cable_route_desc — cable route / containment description
 *
 *   Upgrade / strip-out:
 *     existing_condition — condition assessment of existing AV kit
 *     items_to_remove    — equipment to be stripped out
 *     items_to_retain    — equipment to be kept / reused
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_surveys', function (Blueprint $table) {
            // After submitted_at (added in the token migration)
            $table->string('survey_type', 30)->default('general')->after('submitted_at');
        });

        Schema::table('site_survey_rooms', function (Blueprint $table) {
            // Area classification — follows sort_order for readability
            $table->string('area_type', 30)->nullable()->after('sort_order');

            // ── PA system ─────────────────────────────────────────────────
            $table->unsignedSmallInteger('speaker_count')->nullable()->after('area_type');
            $table->string('speaker_type', 50)->nullable()->after('speaker_count');
            $table->string('speaker_mounting', 50)->nullable()->after('speaker_type');
            $table->unsignedSmallInteger('bg_noise_db')->nullable()->after('speaker_mounting');

            // ── Digital signage ───────────────────────────────────────────
            $table->decimal('display_size_in', 5, 1)->nullable()->after('bg_noise_db');
            $table->string('display_orient', 20)->nullable()->after('display_size_in');
            $table->string('display_mounting', 50)->nullable()->after('display_orient');

            // ── Infrastructure ────────────────────────────────────────────
            $table->unsignedSmallInteger('rack_unit_space')->nullable()->after('display_mounting');
            $table->text('cable_route_desc')->nullable()->after('rack_unit_space');

            // ── Upgrade / strip-out ───────────────────────────────────────
            $table->text('existing_condition')->nullable()->after('cable_route_desc');
            $table->text('items_to_remove')->nullable()->after('existing_condition');
            $table->text('items_to_retain')->nullable()->after('items_to_remove');
        });
    }

    public function down(): void
    {
        Schema::table('site_surveys', function (Blueprint $table) {
            $table->dropColumn('survey_type');
        });

        Schema::table('site_survey_rooms', function (Blueprint $table) {
            $table->dropColumn([
                'area_type',
                'speaker_count', 'speaker_type', 'speaker_mounting', 'bg_noise_db',
                'display_size_in', 'display_orient', 'display_mounting',
                'rack_unit_space', 'cable_route_desc',
                'existing_condition', 'items_to_remove', 'items_to_retain',
            ]);
        });
    }
};
