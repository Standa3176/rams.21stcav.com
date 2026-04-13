<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds planned_start_date and planned_end_date to install_tasks.
 *
 * These nullable date columns support INST-02c: per-task planned scheduling.
 * They allow engineers to have individual planned dates distinct from the
 * programme-level planned dates on install_programmes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('install_tasks', function (Blueprint $table) {
            $table->date('planned_start_date')->nullable()->after('sign_off_required');
            $table->date('planned_end_date')->nullable()->after('planned_start_date');
        });
    }

    public function down(): void
    {
        Schema::table('install_tasks', function (Blueprint $table) {
            $table->dropColumn(['planned_start_date', 'planned_end_date']);
        });
    }
};
