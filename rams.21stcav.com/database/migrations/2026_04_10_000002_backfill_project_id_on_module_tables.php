<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill project_id on module tables (rams_documents, om_manuals, cable_schedules, site_surveys).
 *
 * Context:
 * - project_packages.project_id is already non-nullable and constrained — all packages have a project.
 * - Module tables (rams_documents, om_manuals, cable_schedules, site_surveys) have nullable project_id
 *   columns added by 2026_03_14_000020_add_project_id_to_module_tables.php.
 * - These module rows may have been created before the project linkage migration ran, leaving project_id NULL.
 * - Module tables link to project_packages via indirect join through user_id + project context.
 *   Since module tables don't have a project_package_id FK, we can attempt to link via user_id
 *   by matching to the project that owns the package the user worked on.
 * - For safety, this migration only backfills where a SINGLE unambiguous project exists for a user.
 *   If a user has multiple projects, rows with NULL project_id remain null (cannot safely auto-assign).
 *
 * Note on orphaned packages:
 * The project_packages table has project_id as a required non-nullable FK (constrained()),
 * so there are no orphaned packages — all packages already have a project_id.
 *
 * D-03 auto-create behaviour applies only during initial data migration of legacy orphaned data,
 * which does not exist in this schema since project_packages always required project_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Module tables that have a nullable project_id column.
        // Confirmed in migration 2026_03_14_000020_add_project_id_to_module_tables.php.
        $moduleTables = [
            'rams_documents',
            'om_manuals',
            'cable_schedules',
            'site_surveys',
        ];

        $totalBackfilled = 0;
        $tableResults    = [];

        foreach ($moduleTables as $table) {
            // Guard: only process tables that actually have a project_id column.
            if (! Schema::hasColumn($table, 'project_id')) {
                Log::info("BackfillProjectId: table {$table} has no project_id column — skipped.");
                continue;
            }

            // For rows with NULL project_id, attempt to backfill using user_id:
            // If the user has exactly one project, assign it. This is safe and unambiguous.
            $backfilled = DB::table($table)
                ->whereNull('project_id')
                ->whereExists(function ($sub) use ($table) {
                    $sub->select(DB::raw(1))
                        ->from('projects')
                        ->whereColumn('projects.user_id', $table . '.user_id')
                        ->whereNull('projects.deleted_at')
                        ->groupBy('projects.user_id')
                        ->havingRaw('COUNT(*) = 1');
                })
                ->get()
                ->each(function ($row) use ($table, &$backfilled) {
                    $project = DB::table('projects')
                        ->where('user_id', $row->user_id)
                        ->whereNull('deleted_at')
                        ->first();

                    if ($project) {
                        DB::table($table)
                            ->where('id', $row->id)
                            ->update(['project_id' => $project->id]);
                    }
                })
                ->count();

            $tableResults[$table] = $backfilled;
            $totalBackfilled     += $backfilled;
        }

        Log::info('BackfillProjectId: module table backfill complete', [
            'total_backfilled' => $totalBackfilled,
            'by_table'         => $tableResults,
        ]);
    }

    public function down(): void
    {
        // down() is intentionally a no-op.
        // Backfilled project_id values cannot be safely cleared without risking data loss.
        // To roll back, restore from a pre-migration database snapshot.
    }
};
