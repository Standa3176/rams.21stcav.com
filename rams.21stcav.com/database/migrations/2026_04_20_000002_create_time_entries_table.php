<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Minimal time_entries schema for Phase 14 clock in/out (INST-04g partial).
 *
 * Phase 15 (INST-04a/b/c/d/e) will extend this schema with:
 *   - category (installation / commissioning / testing / other)
 *   - notes (per-entry notes)
 *   - scheduled-job-driven close-stale-sessions behaviour
 *
 * last_heartbeat_at is included from day one per REQUIREMENTS.md
 * Technical Constraints table ("not retrofittable after time_entries contains data").
 *
 * One-open-entry-per-user-per-project guard (INST-04g) is enforced at the
 * service layer via DB::transaction + lockForUpdate, NOT a partial unique index
 * (SQLite/MySQL differ in partial-index support — enforced in TimeEntryService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')
                  ->constrained('projects')
                  ->cascadeOnDelete();
            $table->foreignId('user_id')
                  ->constrained('users')
                  ->cascadeOnDelete();
            $table->timestamp('clocked_in_at');
            $table->timestamp('clocked_out_at')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'user_id']);
            $table->index(['user_id', 'clocked_out_at']); // speeds up open-entry guard queries
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entries');
    }
};
