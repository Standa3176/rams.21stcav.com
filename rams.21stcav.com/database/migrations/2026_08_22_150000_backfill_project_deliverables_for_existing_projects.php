<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 260822-esf Plan 06 — D-17 back-catalogue retrofit.
 *
 * Every project that existed before this migration ran has ZERO
 * project_deliverables rows. Left alone, Project::deliverableState()
 * (Plan 01) defaults every key to 'not_yet_decided', which combined with
 * D-13's amber-after-grace-period rule would turn the ENTIRE pre-existing
 * project list amber starting from this migration's run date — exactly the
 * "learn to ignore the colour" failure D-17 explicitly rejects.
 *
 * D-17's rule is per-deliverable, not per-project: a project that already
 * has evidence of the work (a related row exists) backfills to 'required'
 * for that key; no evidence backfills to 'not_yet_decided'. `programming`
 * has no model, no table, no relation anywhere in this codebase (D-05 —
 * tracked flag only, no generator), so it ALWAYS backfills to
 * 'not_yet_decided' for every project. There is no signal to check for it,
 * so this is the correct application of the per-deliverable rule, not a
 * violation of the "don't default everything uniformly" rule D-17 states.
 *
 * Portability (mirrors 2026_08_13_140000_..._device_stencil_audits.php's own
 * docblock in spirit): this migration runs against MariaDB in production and
 * SQLite in the test suite (phpunit.xml). All inference here is plain
 * ->count() / ->pluck() against already-indexed project_id /
 * install_programme_id foreign keys — no raw-SQL JSON extraction functions,
 * no engine-specific operators, no raw SQL aggregation of any kind — so
 * there is nothing here that could diverge between the two engines.
 *
 * Idempotency is the concrete mitigation for the open risk carried from
 * RESEARCH.md/VALIDATION.md: local SQLite has exactly 1 project and 0
 * related documents, so the real per-deliverable distribution this
 * migration produces against production data cannot be validated here.
 * insertOrIgnore() against the unique (project_id, deliverable_key) index
 * (Plan 01) means re-running this migration — or a project picking up a row
 * from normal application use between deploy and this migration running —
 * is always safe: existing rows are left completely untouched, never reset
 * or overwritten.
 *
 * Key/state/action literals below are plain strings rather than
 * App\Models\ProjectDeliverable KEY_ / STATE_ constants, matching this
 * codebase's existing migration convention (no migration here imports an
 * App\Models class) — kept in exact lockstep with
 * ProjectDeliverable::ALL_KEYS (site_survey, rams, worksheet, om,
 * cable_schedule, install_programme, drawings, snagging, programming),
 * ProjectDeliverable::STATE_REQUIRED/STATE_NOT_YET_DECIDED
 * ('required'/'not_yet_decided'), and
 * ProjectDeliverableAudit::ACTION_BACKFILL ('backfill').
 *
 * @see app/Models/ProjectDeliverable.php
 * @see app/Models/ProjectDeliverableAudit.php
 * @see database/migrations/2026_08_22_140000_create_project_deliverables_and_audits_tables.php
 * @see .planning/phases/260822-esf-project-deliverables-selection/260822-CONTEXT.md (D-17)
 */
return new class extends Migration
{
    public function up(): void
    {
        // Resolve a single actor id ONCE, before the project loop. Prefer an
        // admin-role user — this codebase has no boolean admin-flag column
        // (see app/Models/User.php:18,45-47 — role is a string
        // 'admin'|'user', read via User::isAdmin()); fall back to any user
        // at all so this migration never SQL-errors against a database with
        // no admin account.
        $actorUserId = DB::table('users')->where('role', 'admin')->value('id')
            ?? DB::table('users')->value('id');

        DB::table('projects')->select('id')->get()->each(function ($row) use ($actorUserId) {
            // Snagging (App\Models\CommissioningSignoff) has no direct
            // project_id column — it hangs off install_programmes, one row
            // per programme (Project::snaggingSignoffs(), Plan 01). Mirror
            // that two-hop shape here in plain PHP loops, never a raw JOIN
            // aggregation.
            $installProgrammeIds = DB::table('install_programmes')
                ->where('project_id', $row->id)
                ->pluck('id');

            $snaggingCount = DB::table('commissioning_signoffs')
                ->whereIn('install_programme_id', $installProgrammeIds)
                ->count();

            // The 8 counted keys — has evidence (count > 0) -> required,
            // none -> not_yet_decided. Table names confirmed against each
            // model/migration, not assumed from the model name (om_manuals
            // is the one deliberate exception to the naive snake-plural
            // pattern; the rest do follow it, verified live, not assumed).
            $counted = [
                'site_survey' => DB::table('site_surveys')->where('project_id', $row->id)->count(),
                'rams' => DB::table('rams_documents')->where('project_id', $row->id)->count(),
                'worksheet' => DB::table('worksheets')->where('project_id', $row->id)->count(),
                'om' => DB::table('om_manuals')->where('project_id', $row->id)->count(),
                'cable_schedule' => DB::table('cable_schedules')->where('project_id', $row->id)->count(),
                'install_programme' => DB::table('install_programmes')->where('project_id', $row->id)->count(),
                'drawings' => DB::table('project_drawings')->where('project_id', $row->id)->count(),
                'snagging' => $snaggingCount,
            ];

            foreach ($counted as $key => $count) {
                $state = $count > 0 ? 'required' : 'not_yet_decided';
                $this->backfillOne($row->id, $key, $state, $actorUserId);
            }

            // programming (D-05): no model, no table, no relation, no
            // signal to check — ALWAYS not_yet_decided, unconditionally,
            // for every existing project.
            $this->backfillOne($row->id, 'programming', 'not_yet_decided', $actorUserId);
        });
    }

    /**
     * Insert one (project_id, deliverable_key) row via insertOrIgnore — the
     * idempotency mechanism. A second run of this migration (or a project
     * that already picked up a row from the live import/auto-flip flow
     * between deploy and this migration running) is silently skipped, never
     * overwritten or reset. The paired audit row is written iff — and only
     * iff — the deliverable row insert actually happened: insertOrIgnore()
     * returns the count of rows genuinely inserted (0 or 1 here), so
     * re-running this migration never produces a duplicate audit row
     * either.
     */
    private function backfillOne(int $projectId, string $key, string $state, ?int $actorUserId): void
    {
        $inserted = DB::table('project_deliverables')->insertOrIgnore([
            'project_id' => $projectId,
            'deliverable_key' => $key,
            'state' => $state,
            // undecided_since MUST stay null for every backfilled row, even
            // when $state is 'not_yet_decided' — written explicitly (not
            // relying on the column default) so a later reader can see this
            // is deliberate: these 89 pre-existing projects predate the
            // deliverable-selection feature entirely and must never nag
            // under D-13's amber-grace-period rule (260823-bcm). Only a real
            // human decision path (ProjectDeliverablesService::setState())
            // is allowed to set this column to a non-null value.
            'undecided_since' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted === 1) {
            $deliverableId = DB::table('project_deliverables')
                ->where('project_id', $projectId)
                ->where('deliverable_key', $key)
                ->value('id');

            DB::table('project_deliverable_audits')->insert([
                'project_deliverable_id' => $deliverableId,
                'user_id' => $actorUserId,
                'action' => 'backfill',
                'reason' => 'Inferred from existing project data during phase 260822-esf retrofit.',
                'before_snapshot' => null,
                'after_snapshot' => json_encode(['state' => $state]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Intentional no-op — see up()'s docblock: rows written by this
        // migration are indistinguishable from rows written by normal
        // application use once created, so a blind bulk-delete on down()
        // would destroy real user data if this migration is rolled back
        // after other writes have occurred.
    }
};
