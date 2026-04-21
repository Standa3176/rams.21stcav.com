<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * time_entry_audits — append-only history log for retro-edits to finished
 * time entries (D-04, D-07).
 *
 * Write path (Plan 15-02): TimeEntryService::editEntry() wraps the entry
 * update + a TimeEntryAudit::create() call in one transaction. No update()
 * or delete() paths ship in Phase 15 — the table is read-only once rows land.
 *
 * FKs:
 *   - time_entry_id     → time_entries (cascadeOnDelete — audits follow their entry)
 *   - edited_by_user_id → users        (restrictOnDelete — history survives user churn)
 *
 * Index (time_entry_id, edited_at) is sized for the common query:
 * "show me the edit history for entry X in chronological order".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_entry_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('time_entry_id')
                  ->constrained('time_entries')
                  ->cascadeOnDelete();
            $table->foreignId('edited_by_user_id')
                  ->constrained('users')
                  ->restrictOnDelete();
            $table->string('field', 32); // 'category' | 'notes' — enforced at service layer
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->timestamp('edited_at');
            $table->timestamps();

            $table->index(
                ['time_entry_id', 'edited_at'],
                'time_entry_audits_entry_edited_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entry_audits');
    }
};
