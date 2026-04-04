<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add nullable project_id foreign key to all module tables so documents
 * can be linked to a project without breaking existing standalone records.
 *
 * Modules affected: rams_documents, om_manuals, cable_schedules, site_surveys
 */
return new class extends Migration
{
    public function up(): void
    {
        $tables = ['rams_documents', 'om_manuals', 'cable_schedules', 'site_surveys'];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreignId('project_id')
                          ->nullable()
                          ->after('user_id')
                          ->constrained('projects')
                          ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        $tables = ['rams_documents', 'om_manuals', 'cable_schedules', 'site_surveys'];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropForeign(["{$table}_project_id_foreign"]);
                $blueprint->dropColumn('project_id');
            });
        }
    }
};
