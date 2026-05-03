<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quick task 260503-rgg — Engineer feedback (post-survey debrief 2026-05-03)
 * flagged 17 missing data-capture fields needed for accurate RAMS / install-task /
 * O&M generation. This migration is a pure additive expansion.
 *
 * site_surveys table (7 nullable columns) ─ Site-level logistics for a survey:
 *   - comms_room_access_status / comms_room_access_notes
 *   - parking_restraints
 *   - distance_from_base_miles / distance_from_base_notes
 *   - site_access_notes
 *   - delivery_routes
 *
 * site_survey_rooms table (10 nullable columns: 3 boolean + 7 JSON) ─
 *   - mounting_heights        (JSON: screen/camera/booking_panel/speaker + other:[])
 *   - work_at_height_methods  (JSON array: ladder/podium/tower/mewp/scaffold/na)
 *   - cable_routes            (JSON array of {category,from,to,length_m,notes})
 *   - wall_construction       (JSON array: ply_lined/solid/plasterboard/...)
 *   - wall_needs_reinforcement / wall_needs_chase_out / wall_needs_conduit (booleans)
 *   - table_info              (JSON: has_grommets, grommet_count, grommet_size, notes)
 *   - floor_box_info          (JSON: has_floor_box, power_outlets, data_outlets, ...)
 *   - brackets_required       (JSON array of {equipment,model,pull_out,notes})
 *
 * All columns are nullable so existing surveys remain valid. Downstream services
 * (RamsBuilderService, InstallTaskGeneratorService, ProjectDataService) are NOT
 * touched and will pick up the new data on their next regen cycle.
 *
 * The legacy single-row cable_route_desc / cable_route_from / cable_route_to
 * columns on site_survey_rooms remain untouched — old surveys still display
 * their data; the new JSON `cable_routes` column is the going-forward path.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── site_surveys: 7 site-level columns ────────────────────────────────
        Schema::table('site_surveys', function (Blueprint $table) {
            $table->string('comms_room_access_status', 20)->nullable()
                ->after('general_notes')
                ->comment('Enum: yes | no | outsourced | unknown — does engineer need permission for the comms room?');
            $table->text('comms_room_access_notes')->nullable()
                ->after('comms_room_access_status');
            $table->text('parking_restraints')->nullable()
                ->after('comms_room_access_notes')
                ->comment('Free-text — e.g. no on-street parking, must use NCP');
            $table->decimal('distance_from_base_miles', 6, 1)->nullable()
                ->after('parking_restraints');
            $table->text('distance_from_base_notes')->nullable()
                ->after('distance_from_base_miles')
                ->comment('Route notes — e.g. M25 J7 then 12mi A23');
            $table->text('site_access_notes')->nullable()
                ->after('distance_from_base_notes')
                ->comment('Loading bay, lift size, security pass needed, etc.');
            $table->text('delivery_routes')->nullable()
                ->after('site_access_notes')
                ->comment('Where deliveries can drop, hours, contact');
        });

        // ── site_survey_rooms: 10 room-level columns ──────────────────────────
        Schema::table('site_survey_rooms', function (Blueprint $table) {
            $table->json('mounting_heights')->nullable()
                ->after('display_mounting')
                ->comment('JSON: {screen_h_m, camera_h_m, booking_panel_h_m, speaker_h_m, other:[{label,height_m}]}');
            $table->json('work_at_height_methods')->nullable()
                ->after('mounting_heights')
                ->comment('JSON array: ladder|podium|tower|mewp|scaffold|na — drives RAMS height-risk classification');
            $table->json('cable_routes')->nullable()
                ->after('work_at_height_methods')
                ->comment('JSON array of {category,from,to,length_m,notes} — additive over legacy single-row cable_route_* fields');
            $table->json('wall_construction')->nullable()
                ->after('cable_routes')
                ->comment('JSON array: ply_lined|solid|plasterboard|masonry|metal_stud|concrete');
            $table->boolean('wall_needs_reinforcement')->nullable()
                ->after('wall_construction');
            $table->boolean('wall_needs_chase_out')->nullable()
                ->after('wall_needs_reinforcement');
            $table->boolean('wall_needs_conduit')->nullable()
                ->after('wall_needs_chase_out');
            $table->json('table_info')->nullable()
                ->after('wall_needs_conduit')
                ->comment('JSON: {has_grommets,grommet_count,grommet_size,notes}');
            $table->json('floor_box_info')->nullable()
                ->after('table_info')
                ->comment('JSON: {has_floor_box,power_outlets,data_outlets,cable_space,notes}');
            $table->json('brackets_required')->nullable()
                ->after('floor_box_info')
                ->comment('JSON array of {equipment,model,pull_out,notes}');
        });
    }

    public function down(): void
    {
        Schema::table('site_surveys', function (Blueprint $table) {
            $table->dropColumn([
                'comms_room_access_status',
                'comms_room_access_notes',
                'parking_restraints',
                'distance_from_base_miles',
                'distance_from_base_notes',
                'site_access_notes',
                'delivery_routes',
            ]);
        });

        Schema::table('site_survey_rooms', function (Blueprint $table) {
            $table->dropColumn([
                'mounting_heights',
                'work_at_height_methods',
                'cable_routes',
                'wall_construction',
                'wall_needs_reinforcement',
                'wall_needs_chase_out',
                'wall_needs_conduit',
                'table_info',
                'floor_box_info',
                'brackets_required',
            ]);
        });
    }
};
