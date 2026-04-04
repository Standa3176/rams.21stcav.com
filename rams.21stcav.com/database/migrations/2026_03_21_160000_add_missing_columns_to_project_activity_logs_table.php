<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds every column that the full project_activity_logs schema requires,
 * guarded with hasColumn so the migration is safe to run multiple times
 * and on environments where the table was already created correctly.
 *
 * The 2026_03_21_143732 create migration has a hasTable guard that makes
 * it a no-op when the table already exists.  Combined with the 150000
 * migration (which adds project_id), this migration completes the schema
 * for any server where the table pre-existed without the full column set.
 *
 * Columns added (all nullable / with defaults so existing rows are safe):
 *   user_id      FK → users (nullable — null means system action)
 *   action       string(60), default '' (required for SQLite ALTER TABLE compatibility)
 *   from_status  string(30), nullable
 *   to_status    string(30), nullable
 *   description  string(500)
 *   metadata     JSON, nullable
 *   created_at   timestamp, default CURRENT_TIMESTAMP
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('project_activity_logs')) {
            return;
        }

        Schema::table('project_activity_logs', function (Blueprint $table) {

            if (! Schema::hasColumn('project_activity_logs', 'user_id')) {
                $table->foreignId('user_id')
                      ->nullable()
                      ->after('project_id')
                      ->constrained()
                      ->nullOnDelete();
            }

            if (! Schema::hasColumn('project_activity_logs', 'action')) {
                $table->string('action', 60)->after('user_id')->default('')->index();
            }

            if (! Schema::hasColumn('project_activity_logs', 'from_status')) {
                $table->string('from_status', 30)->nullable()->after('action');
            }

            if (! Schema::hasColumn('project_activity_logs', 'to_status')) {
                $table->string('to_status', 30)->nullable()->after('from_status');
            }

            if (! Schema::hasColumn('project_activity_logs', 'description')) {
                $table->string('description', 500)->after('to_status')->default('');
            }

            if (! Schema::hasColumn('project_activity_logs', 'metadata')) {
                $table->json('metadata')->nullable()->after('description');
            }

            if (! Schema::hasColumn('project_activity_logs', 'created_at')) {
                $table->timestamp('created_at')->useCurrent()->after('metadata');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('project_activity_logs')) {
            return;
        }

        Schema::table('project_activity_logs', function (Blueprint $table) {
            $cols = ['user_id', 'action', 'from_status', 'to_status', 'description', 'metadata', 'created_at'];

            foreach ($cols as $col) {
                if (Schema::hasColumn('project_activity_logs', $col)) {
                    if ($col === 'user_id') {
                        $table->dropForeign(['user_id']);
                    }
                    $table->dropColumn($col);
                }
            }
        });
    }
};
