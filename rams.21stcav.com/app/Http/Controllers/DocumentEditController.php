<?php

namespace App\Http\Controllers;

use App\Models\DocumentChangeSet;
use App\Models\DocumentEditMessage;
use App\Models\DocumentEditThread;
use App\Services\DocumentEdits\DocumentChangeSetValidator;
use App\Services\DocumentEdits\DocumentEditAdapterInterface;
use App\Services\DocumentEdits\DocumentEditAdapterRegistry;
use App\Services\DocumentEdits\DocumentRevisionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Endpoints for the shared Document Edit Core.
 *
 * Pass A contract:
 *   - Plumbing works end-to-end: create thread, post message with proposed
 *     operations → change-set is persisted + validated (safety + adapter
 *     allow-list).
 *   - Apply endpoint calls through to the adapter; in pass A every real
 *     apply returns ok=false with code=not_implemented_in_pass_a, so the
 *     endpoint responds 422 (not 500).
 *   - No artifact regeneration. Revisions list reads only.
 *
 * Auth: attached via the `web` + `auth` route group in web.php.
 */
class DocumentEditController extends Controller
{
    public function __construct(
        private readonly DocumentEditAdapterRegistry $registry,
        private readonly DocumentChangeSetValidator  $validator,
        private readonly DocumentRevisionService     $revisions,
    ) {}

    // ─── POST /documents/{type}/{id}/threads ──────────────────────────────────

    public function createThread(Request $request, string $type, int $id): JsonResponse
    {
        $adapter = $this->resolveAdapter($type);
        if ($adapter === null) {
            return $this->jsonError('unknown_document_type', "Unknown document type '{$type}'", 404);
        }

        $payload = $adapter->loadPayload($id);
        if ($payload === null) {
            return $this->jsonError('document_not_found', "{$type} #{$id} not found", 404);
        }

        $userId = $request->user()?->id;
        $base   = $this->revisions->ensureBaseRevision($type, $id, $payload, $userId);

        $thread = DocumentEditThread::create([
            'document_type'    => $type,
            'document_id'      => $id,
            'base_revision_id' => $base->id,
            'status'           => DocumentEditThread::STATUS_OPEN,
            'created_by'       => $userId,
        ]);

        return response()->json([
            'thread'        => $thread->only(['id', 'document_type', 'document_id', 'base_revision_id', 'status', 'created_at']),
            'base_revision' => $base->only(['id', 'document_type', 'document_id', 'source', 'created_at']),
        ], 201);
    }

    // ─── POST /documents/{type}/{id}/threads/{thread}/messages ────────────────

    public function postMessage(Request $request, string $type, int $id, int $thread): JsonResponse
    {
        $adapter = $this->resolveAdapter($type);
        if ($adapter === null) {
            return $this->jsonError('unknown_document_type', "Unknown document type '{$type}'", 404);
        }

        $threadModel = DocumentEditThread::query()
            ->where('document_type', $type)
            ->where('document_id',   $id)
            ->find($thread);
        if ($threadModel === null) {
            return $this->jsonError('thread_not_found', "Thread #{$thread} not found for {$type} #{$id}", 404);
        }

        $data = $request->validate([
            'role'            => 'required|string|in:user,assistant,system',
            'content'         => 'required|string|max:16384',
            'operations_json' => 'nullable|array',
        ]);

        $message = DocumentEditMessage::create([
            'thread_id'       => $threadModel->id,
            'role'            => $data['role'],
            'content'         => $data['content'],
            'operations_json' => $data['operations_json'] ?? null,
        ]);

        // If operations were attached, create a proposed change-set + run validation.
        $changeSet = null;
        if (! empty($data['operations_json'])) {
            $ops        = $data['operations_json'];
            $errors     = $this->validator->validate($ops, $adapter);
            $status     = empty($errors) ? DocumentChangeSet::STATUS_VALIDATED : DocumentChangeSet::STATUS_REJECTED;

            $changeSet = DocumentChangeSet::create([
                'thread_id'         => $threadModel->id,
                'document_type'     => $type,
                'document_id'       => $id,
                'base_revision_id'  => $threadModel->base_revision_id,
                'status'            => $status,
                'operations_json'   => $ops,
                'validation_errors' => empty($errors) ? null : $errors,
                'model_name'        => null,
            ]);
        }

        return response()->json([
            'message'    => $message->only(['id', 'thread_id', 'role', 'created_at']),
            'change_set' => $changeSet?->only(['id', 'status', 'validation_errors', 'created_at']),
        ], 201);
    }

    // ─── GET /documents/{type}/{id}/changes/{changeSet} ───────────────────────

    public function showChangeSet(string $type, int $id, int $changeSet): JsonResponse
    {
        $cs = DocumentChangeSet::query()
            ->where('document_type', $type)
            ->where('document_id',   $id)
            ->find($changeSet);
        if ($cs === null) {
            return $this->jsonError('change_set_not_found', "Change-set #{$changeSet} not found", 404);
        }

        // Preview diff — compute without persisting. Silent no-op when the
        // adapter hasn't implemented a diff (pass A stubs return []).
        $preview = [];
        try {
            $adapter = $this->resolveAdapter($type);
            if ($adapter !== null && $cs->status !== DocumentChangeSet::STATUS_REJECTED) {
                $before = $adapter->loadPayload($id) ?? [];
                $after  = $before;
                foreach ((array) $cs->operations_json as $op) {
                    $res = $adapter->applyOperation($after, (array) $op);
                    if ($res['ok'] ?? false) {
                        $after = (array) ($res['payload'] ?? $after);
                    }
                    // Ignore individual op failures in preview — partial ok.
                }
                $preview = $adapter->summariseDiff($before, $after);
            }
        } catch (\Throwable) {
            $preview = [];
        }

        return response()->json([
            'change_set' => $cs->only([
                'id', 'thread_id', 'document_type', 'document_id',
                'base_revision_id', 'status', 'operations_json',
                'validation_errors', 'model_name', 'created_at',
            ]),
            'preview' => $preview,
        ]);
    }

    // ─── POST /documents/{type}/{id}/changes/{changeSet}/apply ────────────────

    public function applyChangeSet(string $type, int $id, int $changeSet): JsonResponse
    {
        $adapter = $this->resolveAdapter($type);
        if ($adapter === null) {
            return $this->jsonError('unknown_document_type', "Unknown document type '{$type}'", 404);
        }

        $cs = DocumentChangeSet::query()
            ->where('document_type', $type)
            ->where('document_id',   $id)
            ->find($changeSet);
        if ($cs === null) {
            return $this->jsonError('change_set_not_found', "Change-set #{$changeSet} not found", 404);
        }
        if ($cs->status === DocumentChangeSet::STATUS_APPLIED) {
            return $this->jsonError('already_applied', 'Change-set already applied', 409);
        }
        if ($cs->status === DocumentChangeSet::STATUS_REJECTED) {
            return $this->jsonError('change_set_rejected', 'Change-set failed validation', 422, [
                'validation_errors' => $cs->validation_errors,
            ]);
        }

        // Re-validate — defence against ops that slipped through an earlier path.
        $errors = $this->validator->validate($cs->operations_json ?? [], $adapter);
        if (! empty($errors)) {
            $cs->update([
                'status'            => DocumentChangeSet::STATUS_REJECTED,
                'validation_errors' => $errors,
            ]);
            return $this->jsonError('validation_failed', 'Operations failed validation', 422, ['validation_errors' => $errors]);
        }

        // Load payload + walk operations through the adapter.
        $payload = $adapter->loadPayload($id);
        if ($payload === null) {
            return $this->jsonError('document_not_found', "{$type} #{$id} not found", 404);
        }

        $applyErrors = [];
        foreach ((array) $cs->operations_json as $idx => $op) {
            $res = $adapter->applyOperation($payload, (array) $op);
            if (! ($res['ok'] ?? false)) {
                $applyErrors[] = [
                    'code'    => $res['code']  ?? 'apply_failed',
                    'message' => $res['error'] ?? 'Operation apply failed',
                    'index'   => $idx,
                ];
                continue;
            }
            $payload = (array) ($res['payload'] ?? $payload);
        }

        if (! empty($applyErrors)) {
            // Mark rejected — pass A always takes this branch for non-noop ops.
            $cs->update([
                'status'            => DocumentChangeSet::STATUS_REJECTED,
                'validation_errors' => $applyErrors,
            ]);
            return $this->jsonError('adapter_apply_failed', 'Adapter rejected one or more operations', 422, [
                'apply_errors' => $applyErrors,
            ]);
        }

        // Persist payload + regenerate derived artifact via the adapter.
        // Adapters that don't produce an artifact (pass A stubs) return null;
        // adapters that fail artifact regen also return null and log — the
        // apply still records the revision so the payload change isn't lost.
        $artifactFilename = $adapter->commitChanges($id, $payload);

        $baseRev = $cs->baseRevision;
        $newRev  = $baseRev
            ? $this->revisions->recordRevision(
                $baseRev,
                $payload,
                \App\Models\DocumentRevision::SOURCE_AI_CHAT,
                'Applied via chat change-set',
            )
            : null;
        if ($newRev !== null && $artifactFilename !== null) {
            $newRev->update(['artifact_filename' => $artifactFilename]);
        }
        $cs->update(['status' => DocumentChangeSet::STATUS_APPLIED]);

        return response()->json([
            'change_set'        => $cs->only(['id', 'status']),
            'new_revision'      => $newRev?->only(['id', 'parent_revision_id', 'source', 'artifact_filename', 'created_at']),
            'artifact_filename' => $artifactFilename,
        ]);
    }

    // ─── GET /documents/{type}/{id}/revisions ─────────────────────────────────

    public function listRevisions(string $type, int $id): JsonResponse
    {
        if ($this->resolveAdapter($type) === null) {
            return $this->jsonError('unknown_document_type', "Unknown document type '{$type}'", 404);
        }

        $revisions = $this->revisions->listForDocument($type, $id);

        return response()->json([
            'revisions' => $revisions->map(fn ($r) => $r->only([
                'id', 'parent_revision_id', 'source', 'change_summary',
                'artifact_filename', 'created_by', 'created_at',
            ]))->values(),
        ]);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function resolveAdapter(string $type): ?DocumentEditAdapterInterface
    {
        try {
            return $this->registry->for($type);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function jsonError(string $code, string $message, int $status, array $extras = []): JsonResponse
    {
        return response()->json(array_merge([
            'error'   => $code,
            'message' => $message,
        ], $extras), $status);
    }
}
