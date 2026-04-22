<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the commissioning_items table (INST-05a).
 *
 * One row per AVIXA-category check per equipment instance (D-02 per-instance
 * grain). Populated by CommissioningItemGenerator when the last install_task
 * in a programme flips to status=complete (D-03). Statuses mutate via per-item
 * AJAX endpoints (INST-05c) until CommissioningSignoff lands — thereafter the
 * row is immutable (INST-05i, guarded in the HTTP layer).
 *
 * install_task_id provides D-05 traceability back to the originating task;
 * it is nullable to survive a task force-delete without losing the audit row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commissioning_items', function (Blueprint $table) {
            $table->id();

            // INST-05a primary FK — cascade so dropping a programme wipes its items.
            $table->foreignId('install_programme_id')
                  ->constrained('install_programmes')
                  ->cascadeOnDelete();

            // D-05 traceability link to source install_task (per-instance grain).
            // nullOnDelete: if an install_task is force-deleted the commissioning
            // row survives with a null FK rather than being removed with it.
            $table->foreignId('install_task_id')
                  ->nullable()
                  ->constrained('install_tasks')
                  ->nullOnDelete();

            // INST-05a required columns — denormalised snapshot so the
            // commissioning row remains human-readable even if the source
            // install_task is edited or soft-deleted later.
            $table->string('equipment_name', 300);
            $table->string('room_name', 200);

            // INST-05e — enum stored as varchar per codebase convention
            // (install_tasks.status, install_programmes.status all do the same).
            // Values: power | display | audio | vtc | control | network | cabling
            $table->string('category', 20);

            // INST-05a status enum — pending | pass | fail | na
            $table->string('status', 20)->default('pending');

            // INST-05a — SINGULAR path column (Pitfall 8: not "photo_paths").
            // Stores the DocumentArtifactStorage filename (relative), not an
            // absolute path.
            $table->string('evidence_photo_path', 500)->nullable();

            $table->text('notes')->nullable();

            // INST-05i audit columns — name snapshot (not FK) + UTC timestamp.
            // name is a string not a users.id FK because the engineer who
            // toggled the item pass/fail may have been removed from the system
            // later; we want the audit trail to survive that.
            $table->string('signed_off_by', 255)->nullable();
            $table->timestamp('signed_off_at')->nullable();

            $table->timestamps();
            $table->softDeletes();   // D-04 re-sync preserves audit trail

            $table->index('install_programme_id');
            $table->index(['install_programme_id', 'status']);
            $table->index(['install_programme_id', 'room_name']);
            $table->index('install_task_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commissioning_items');
    }
};
