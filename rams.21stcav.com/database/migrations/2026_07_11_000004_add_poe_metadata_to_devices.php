<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T3-C — adds PoE metadata (pse_budget_w + pd_load_w) to the devices table.
 *
 * pse_budget_w — total PoE budget in watts on a Power-Sourcing-Equipment
 *   device (i.e. a switch). Populated on network switches used as
 *   destinations in the cable schedule DAG. Null → checkPoeBudgets skips
 *   the group silently (soft opt-in).
 *
 * pd_load_w — nominal power draw in watts for a Powered-Device (camera,
 *   codec, touch panel, WAP, etc.) that connects to a PoE switch. Null on
 *   any source in a PoE group → whole group skipped (all-or-nothing so
 *   the aggregate can't be under-reported by missing data).
 *
 * Placement: after is_critical — verified that Task 2's 000002 migration
 * added is_critical after signal_role, so ->after('is_critical') is safe.
 * The 000003 timestamp orders this AFTER 000002 during migrate.
 *
 * @see app/Services/CableScheduleGeneratorService.php (T3-C checkPoeBudgets)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->float('pse_budget_w')->nullable()->after('is_critical');
            $table->float('pd_load_w')->nullable()->after('pse_budget_w');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['pse_budget_w', 'pd_load_w']);
        });
    }
};
