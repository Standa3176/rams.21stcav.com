<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add status audit trail to install_tasks (Phase 14 D-07).
 *
 * Every status change (pending → in_progress → complete, blocked, skipped, reopen)
 * now records WHO changed it and WHEN. Data surfaces nowhere in Phase 14 UI
 * (audit-trail UI is deferred per CONTEXT.md Deferred Ideas) but is captured
 * so Phase 16 / compliance views can render it later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('install_tasks', function (Blueprint $table) {
            // Defensive: Phase 12 schema has `blocked_reason`; but if a future rename / baseline
            // change drops it, fall back to `completed_at` (always present per Phase 12). The
            // guard keeps this migration idempotent against minor schema drift.
            $afterColumn = Schema::hasColumn('install_tasks', 'blocked_reason')
                ? 'blocked_reason'
                : 'completed_at';
            $table->timestamp('status_changed_at')->nullable()->after($afterColumn);
            $table->foreignId('status_changed_by')
                  ->nullable()
                  ->after('status_changed_at')
                  ->constrained('users')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('install_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('status_changed_by');
            $table->dropColumn('status_changed_at');
        });
    }
};
