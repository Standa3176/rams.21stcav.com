<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds project_id to the project_activity_logs table if it is missing.
 *
 * The 2026_03_21_143732 migration creates the table WITH project_id, but it
 * has a hasTable guard that skips everything when the table already exists
 * (e.g. created on an earlier environment without the column).  This migration
 * picks up that case by checking for the column specifically.
 *
 * Safe to run multiple times — the hasColumn guard is a no-op when the column
 * already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('project_activity_logs')) {
            return; // table doesn't exist yet — let the create migration handle it
        }

        if (Schema::hasColumn('project_activity_logs', 'project_id')) {
            return; // column already present — nothing to do
        }

        Schema::table('project_activity_logs', function (Blueprint $table) {
            $table->foreignId('project_id')
                  ->nullable()
                  ->after('id')
                  ->constrained()
                  ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('project_activity_logs')) {
            return;
        }

        if (! Schema::hasColumn('project_activity_logs', 'project_id')) {
            return;
        }

        Schema::table('project_activity_logs', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropColumn('project_id');
        });
    }
};
