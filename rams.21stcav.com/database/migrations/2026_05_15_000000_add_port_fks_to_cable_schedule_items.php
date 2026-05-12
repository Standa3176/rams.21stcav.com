<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 22 Plan 01 — additive port-level FK columns on cable_schedule_items.
 *
 * Strictly additive: v1.3 surfaces (SchematicGeneratorService,
 * SchematicD2SourceBuilder, CableScheduleXlsxService,
 * CableScheduleGeneratorService, bound-PDF cable-list section) all continue
 * rendering legacy rows where the new FK columns are NULL. D-10 invariant.
 *
 * Generic column naming per Phase 21 D-09 — `source_*` / `dest_*` so the
 * table ports cleanly to SCC after the planned RAMS+SCC merge. No `rams_`
 * or `project_` prefix.
 *
 * Foreign-key disposition: nullOnDelete (NOT cascadeOnDelete). If a Device
 * is deleted (e.g. equipment list correction mid-project), the cable row
 * survives — text representation (from_location / to_location) preserves
 * the cable description; FK simply clears, putting the row in the same
 * state as a never-FK'd legacy row.
 *
 * connector_override_note: free-text reason populated by the picker modal
 * when an engineer overrides an incompatible connector pair. Persisted via
 * the existing CableScheduleController@update handler once Plan 22-02 lands.
 *
 * cable_schedule_items_port_pair_idx: compound index on
 * (source_port_id, dest_port_id) — Phase 23's renderer reads port pairs to
 * draw port-to-port cable routing.
 *
 * T-22-A1 mitigation: this migration adds NO mass-assignment defaults. The
 * 5 new columns become fillable via CableScheduleItem::$fillable whitelist
 * (Plan 22-01 Task 2). Eloquent drops any other items[N][*] key on update.
 *
 * @see app/Models/CableScheduleItem.php (Task 2 — extends $fillable)
 * @see config/cables.php (Task 3 — compatibility matrix)
 * @see .planning/phases/22-cable-schedule-with-port-level-fks/22-CONTEXT.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cable_schedule_items', function (Blueprint $table) {
            $table->foreignId('source_device_id')->nullable()->after('to_location')
                ->constrained('devices')->nullOnDelete();
            $table->foreignId('source_port_id')->nullable()->after('source_device_id')
                ->constrained('device_ports')->nullOnDelete();
            $table->foreignId('dest_device_id')->nullable()->after('source_port_id')
                ->constrained('devices')->nullOnDelete();
            $table->foreignId('dest_port_id')->nullable()->after('dest_device_id')
                ->constrained('device_ports')->nullOnDelete();
            $table->text('connector_override_note')->nullable()->after('dest_port_id');

            // Index pair for Phase 23's renderer port-lookup queries.
            $table->index(['source_port_id', 'dest_port_id'], 'cable_schedule_items_port_pair_idx');
        });
    }

    public function down(): void
    {
        Schema::table('cable_schedule_items', function (Blueprint $table) {
            $table->dropIndex('cable_schedule_items_port_pair_idx');
            $table->dropConstrainedForeignId('source_device_id');
            $table->dropConstrainedForeignId('source_port_id');
            $table->dropConstrainedForeignId('dest_device_id');
            $table->dropConstrainedForeignId('dest_port_id');
            $table->dropColumn('connector_override_note');
        });
    }
};
