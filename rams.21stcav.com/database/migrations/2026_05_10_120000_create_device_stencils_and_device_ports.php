<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 21 Plan 01 — creates the foundation tables for v2.0 engineering-grade
 * drawings. Two new tables, both generic-named so they port to SCC after the
 * planned RAMS+SCC merge (per CONTEXT.md D-09 / memory rams_scc_merge.md).
 *
 *   - device_stencils: pre-rendered mxGraph XML per part_number, cached
 *     cross-project via firstOrCreate. Drives Phase 23's renderer.
 *
 *   - device_ports: per-device port metadata (label, side, connector, signal,
 *     direction). Drives port-to-port cable routing in Phase 22.
 *
 * Column shape per D-02. Tables created in dependency order
 * (device_stencils first, then device_ports with the FK).
 *
 * Idempotency / cache contract (D-03):
 *   - device_stencils.part_number is UNIQUE so DeviceStencilCacheService's
 *     firstOrCreate is race-safe at the DB layer (concurrent first-call on a
 *     fresh part_number raises a UNIQUE-violation on the loser; Eloquent's
 *     firstOrCreate catches and retries as SELECT). Net: exactly one row,
 *     no transaction wrapper needed.
 *
 *   - device_ports has a compound unique index on (device_stencil_id, port_id)
 *     so each stencil cannot define the same port_id twice (port_id is the
 *     mxGraph constraint name used for cable termination in Phase 23).
 *
 * @see app/Models/DeviceStencil.php
 * @see app/Models/DevicePort.php
 * @see app/Services/Drawings/DeviceStencilCacheService.php
 * @see .planning/phases/21-device-port-catalog-stencil-cache/21-CONTEXT.md (D-02 column shape)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_stencils', function (Blueprint $table) {
            $table->id();
            // Case-insensitive uniqueness is enforced at the app layer via
            // DeviceStencil::normalisePartNumber() (lowercase trim). The DB
            // unique index is over the literal stored value — callers MUST
            // pass already-normalised part_numbers when inserting (the cache
            // service does this).
            $table->string('part_number', 100)->unique();
            $table->string('manufacturer', 100)->nullable();
            $table->string('model', 100)->nullable();
            $table->string('display_name', 200)->nullable();
            $table->longText('mxgraph_xml');
            $table->longText('logo_svg')->nullable();
            $table->unsignedSmallInteger('default_width')->default(220);
            $table->unsignedSmallInteger('default_height')->default(140);
            // Source values: auto-generated / engineer-curated / ai-extracted
            // (see DeviceStencil::SOURCE_* constants). Stored as string for
            // forward-compat with future curation states (D-04).
            $table->string('source', 30)->default('auto-generated');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('device_ports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_stencil_id')
                ->constrained('device_stencils')
                ->cascadeOnDelete();
            $table->string('label', 100);
            // side: left / right / top / bottom (DevicePort::SIDE_*)
            $table->string('side', 10);
            // connector_type: hdmi / usb-a / usb-b / usb-c / rj45 / rj45-poe /
            // rs232 / 3.5mm / xlr / phoenix / dp / etc. NOT an enum —
            // engineer-extensible per D-02.
            $table->string('connector_type', 50);
            // signal_type: audio / video / control / network / usb / power /
            // speaker / dante / etc.
            $table->string('signal_type', 30);
            // direction: in / out / io (DevicePort::DIRECTION_*)
            $table->string('direction', 5);
            $table->unsignedSmallInteger('sort_order')->default(0);
            // port_id: engineer-supplied stable identifier used as the mxGraph
            // constraint name for cable termination (e.g. "hdmi-1").
            $table->string('port_id', 50);
            // y_pct / x_pct: 0..1 positional hints for the renderer. y_pct is
            // used by left/right ports; x_pct by top/bottom. Nullable so
            // either dimension can be left for the renderer to compute.
            $table->decimal('y_pct', 5, 4)->nullable();
            $table->decimal('x_pct', 5, 4)->nullable();
            $table->timestamps();

            // Compound unique: a stencil cannot define the same port_id twice.
            $table->unique(['device_stencil_id', 'port_id'], 'device_ports_stencil_port_unique');
        });
    }

    public function down(): void
    {
        // FK order: drop child first.
        Schema::dropIfExists('device_ports');
        Schema::dropIfExists('device_stencils');
    }
};
