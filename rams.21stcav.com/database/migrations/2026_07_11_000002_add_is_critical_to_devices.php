<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T3-B — adds is_critical to the devices table.
 *
 * A processor Device flagged as is_critical causes the cable schedule DAG
 * walker to emit paired primary + '-R' redundant rows so diverse-routing
 * runs are visible in the schedule. Nullable is intentional: pre-migration
 * rows read as null and are treated identically to false at every check
 * site — no redundant rows. Feature is soft opt-in.
 *
 * Placement: after signal_role — verified during planning that
 * 2026_05_01_000002_add_signal_classification_to_devices_table.php declares
 * $table->string('signal_role', 16)->nullable()->after('part_no'); so
 * ->after('signal_role') is safe.
 *
 * @see app/Services/CableScheduleGeneratorService.php (T3-B redundant-row emission)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->boolean('is_critical')->nullable()->after('signal_role');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('is_critical');
        });
    }
};
