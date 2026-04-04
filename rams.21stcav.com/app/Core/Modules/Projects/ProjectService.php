<?php

namespace App\Core\Modules\Projects;

use App\Models\Project;
use App\Models\ProjectActivityLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * ProjectService — project lifecycle management and activity logging.
 *
 * All status transitions MUST go through this service.
 * Direct model updates that bypass this service will not produce activity log entries.
 *
 * Usage:
 *   $service = app(ProjectService::class);
 *   $project = $service->create($user, $data);
 *   $service->transition($project, Project::STATUS_SURVEY_PENDING, $user);
 *   $service->reopen($project, $user, 'Customer requested changes');
 */
class ProjectService
{
    // ── Create ────────────────────────────────────────────────────────────────

    /**
     * Create a new project and log the creation event.
     */
    public function create(User $user, array $data): Project
    {
        return DB::transaction(function () use ($user, $data) {
            $project = Project::create([
                'user_id'           => $user->id,
                'name'              => $data['name'],
                'ref'               => $data['ref']               ?? null,
                'client_name'       => $data['client_name'],
                'site_address'      => $data['site_address'],
                'works_description' => $data['works_description'] ?? null,
                'status'            => Project::STATUS_QUOTE_IMPORTED,
                'notes'             => $data['notes']             ?? null,
            ]);

            $this->log(
                project:     $project,
                user:        $user,
                action:      ProjectActivityLog::ACTION_CREATED,
                description: "Project \"{$project->name}\" created by {$user->name}.",
                metadata:    ['ref' => $project->ref],
            );

            return $project;
        });
    }

    // ── Lifecycle transitions ─────────────────────────────────────────────────

    /**
     * Transition a project to a new lifecycle status.
     *
     * @throws InvalidArgumentException  If the transition is not permitted.
     */
    public function transition(Project $project, string $toStatus, User $user, ?string $note = null): Project
    {
        if (! $project->canTransitionTo($toStatus)) {
            throw new InvalidArgumentException(
                "Cannot transition project #{$project->id} from '{$project->status}' to '{$toStatus}'."
            );
        }

        return DB::transaction(function () use ($project, $toStatus, $user, $note) {
            $fromStatus = $project->status;
            $timestamp  = $this->milestoneColumn($toStatus);

            $updates = ['status' => $toStatus];

            if ($timestamp) {
                $updates[$timestamp] = now();
            }

            $project->update($updates);

            $fromLabel = Project::STATUS_LABELS[$fromStatus] ?? $fromStatus;
            $toLabel   = Project::STATUS_LABELS[$toStatus]   ?? $toStatus;

            $this->log(
                project:     $project,
                user:        $user,
                action:      ProjectActivityLog::ACTION_STATUS_CHANGED,
                description: "{$user->name} advanced project from {$fromLabel} to {$toLabel}."
                           . ($note ? " Note: {$note}" : ''),
                fromStatus:  $fromStatus,
                toStatus:    $toStatus,
                metadata:    $note ? ['note' => $note] : null,
            );

            return $project->fresh();
        });
    }

    // ── Archive ───────────────────────────────────────────────────────────────

    /**
     * Archive a project from any non-archived status.
     */
    public function archive(Project $project, User $user, ?string $reason = null): Project
    {
        if ($project->isArchived()) {
            throw new InvalidArgumentException("Project #{$project->id} is already archived.");
        }

        return DB::transaction(function () use ($project, $user, $reason) {
            $fromStatus = $project->status;

            $project->update([
                'previous_status' => $fromStatus,
                'status'          => Project::STATUS_ARCHIVED,
                'archived_at'     => now(),
            ]);

            $this->log(
                project:     $project,
                user:        $user,
                action:      ProjectActivityLog::ACTION_STATUS_CHANGED,
                description: "{$user->name} archived the project."
                           . ($reason ? " Reason: {$reason}" : ''),
                fromStatus:  $fromStatus,
                toStatus:    Project::STATUS_ARCHIVED,
                metadata:    $reason ? ['reason' => $reason] : null,
            );

            return $project->fresh();
        });
    }

    // ── Reopen ────────────────────────────────────────────────────────────────

    /**
     * Reopen an archived project, restoring it to its previous status.
     *
     * @throws InvalidArgumentException  If project is not archived or has no previous_status.
     */
    public function reopen(Project $project, User $user, string $reason): Project
    {
        if (! $project->canReopen()) {
            throw new InvalidArgumentException(
                "Project #{$project->id} cannot be reopened (not archived or no previous status)."
            );
        }

        return DB::transaction(function () use ($project, $user, $reason) {
            $restoredStatus = $project->previous_status;

            $project->update([
                'status'          => $restoredStatus,
                'previous_status' => null,
                'archived_at'     => null,
                'reopened_at'     => now(),
                'reopened_by'     => $user->id,
                'reopen_reason'   => $reason,
            ]);

            $restoredLabel = Project::STATUS_LABELS[$restoredStatus] ?? $restoredStatus;

            $this->log(
                project:     $project,
                user:        $user,
                action:      ProjectActivityLog::ACTION_REOPENED,
                description: "{$user->name} reopened the project. Restored to: {$restoredLabel}. Reason: {$reason}",
                fromStatus:  Project::STATUS_ARCHIVED,
                toStatus:    $restoredStatus,
                metadata:    ['reason' => $reason],
            );

            return $project->fresh();
        });
    }

    // ── Update ────────────────────────────────────────────────────────────────

    /**
     * Update project metadata (name, client, address, notes, etc.).
     * Does NOT change lifecycle status.
     */
    public function update(Project $project, User $user, array $data): Project
    {
        $changed = array_filter($data, fn($v, $k) => $project->$k !== $v, ARRAY_FILTER_USE_BOTH);

        $project->update(array_intersect_key($data, array_flip([
            'name', 'ref', 'client_name', 'site_address', 'works_description', 'notes',
        ])));

        if (! empty($changed)) {
            $this->log(
                project:     $project,
                user:        $user,
                action:      ProjectActivityLog::ACTION_NOTE_ADDED,
                description: "{$user->name} updated project details.",
                metadata:    ['fields_changed' => array_keys($changed)],
            );
        }

        return $project->fresh();
    }

    // ── Activity logging ──────────────────────────────────────────────────────

    /**
     * Append an entry to the project activity log.
     */
    public function log(
        Project  $project,
        User     $user,
        string   $action,
        string   $description,
        ?string  $fromStatus = null,
        ?string  $toStatus   = null,
        ?array   $metadata   = null,
    ): ProjectActivityLog {
        return ProjectActivityLog::create([
            'project_id'  => $project->id,
            'user_id'     => $user->id,
            'action'      => $action,
            'from_status' => $fromStatus,
            'to_status'   => $toStatus,
            'description' => $description,
            'metadata'    => $metadata,
        ]);
    }

    /**
     * Log a module document event (RAMS added, O&M generated, etc.).
     */
    public function logDocument(
        Project $project,
        User    $user,
        string  $documentType,
        int     $documentId,
        string  $verb = 'added',
    ): ProjectActivityLog {
        return $this->log(
            project:     $project,
            user:        $user,
            action:      $verb === 'added'
                            ? ProjectActivityLog::ACTION_DOCUMENT_ADDED
                            : ProjectActivityLog::ACTION_DOCUMENT_UPDATED,
            description: "{$user->name} {$verb} a {$documentType} document.",
            metadata:    ['document_type' => $documentType, 'document_id' => $documentId],
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Map a lifecycle status to its milestone timestamp column.
     */
    private function milestoneColumn(string $status): ?string
    {
        return match ($status) {
            Project::STATUS_SURVEY_PENDING  => 'survey_started_at',
            Project::STATUS_ENGINEERING     => 'engineering_started_at',
            Project::STATUS_INSTALLING      => 'installation_started_at',
            Project::STATUS_COMMISSIONING   => 'commissioning_started_at',
            Project::STATUS_HANDOVER        => 'handover_started_at',
            Project::STATUS_COMPLETED       => 'completed_at',
            Project::STATUS_ARCHIVED        => 'archived_at',
            default                         => null,
        };
    }
}
