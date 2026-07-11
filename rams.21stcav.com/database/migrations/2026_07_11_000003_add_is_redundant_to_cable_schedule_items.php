<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T3-B — adds is_redundant to the cable_schedule_items table.
 *
 * Populated (true) by the DAG walker when the row was emitted as the '-R'
 * counterpart of a primary edge tied to an is_critical=true processor.
 * Nullable is intentional: pre-migration rows and every primary row read
 * as null → treated as false everywhere.
 *
 * Placement: after signal_type — verified during planning that
 * 2026_07_11_000001_add_signal_type_to_cable_schedule_items.php declares
 * $table->string('signal_type', 20)->nullable()->after('cable_type');
 * so ->after('signal_type') is safe.
 *
 * @see app/Services/CableScheduleGeneratorService.php (T3-B redundant-row emission)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cable_schedule_items', function (Blueprint $table) {
            $table->boolean('is_redundant')->nullable()->after('signal_type');
        });
    }

    public function down(): void
    {
        Schema::table('cable_schedule_items', function (Blueprint $table) {
            $table->dropColumn('is_redundant');
        });
    }
};
