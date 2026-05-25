<?php

namespace App\Http\Controllers;

use App\Models\CableSchedule;
use App\Models\DocumentChangeSet;
use App\Models\DocumentEditMessage;
use App\Models\DocumentEditThread;
use App\Models\OmManual;
use App\Models\RamsDocument;
use App\Models\SiteSurvey;
use App\Models\Worksheet;
use App\Services\DocumentEdits\DocumentChangeSetValidator;
use App\Services\DocumentEdits\DocumentEditAdapterInterface;
use App\Services\DocumentEdits\DocumentEditAdapterRegistry;
use App\Services\DocumentEdits\DocumentRevisionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
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
        private readonly \App\Services\DocumentEdits\DocumentEditParserService $parser,
    ) {}

    // ─── POST /documents/{type}/{id}/threads ──────────────────────────────────

    public function createThread(Request $request, string $type, int $id): JsonResponse
    {
        $adapter = $this->resolveAdapter($type);
        if ($adapter === null) {
            return $this->jsonError('unknown_document_type', "Unknown document type '{$type}'", 404);
        }
        if ($denied = $this->authorizeDocument($request, $type, $id)) {
            return $denied;
        }

        $payload = $adapter->loadPayload($id);
        if ($payload === null) {
            return $this->jsonError('document_not_found', "{$type} #{$id} not found", 404);
        }

        $userId = $request->user()?->id;
        $base   = $this->revisions->ensureBaseRevision($type, $id, $payload, $userId);
        $this->audit('thread_created', $type, $id, ['base_revision_id' => $base->id, 'user_id' => $userId]);

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
        if ($denied = $this->authorizeDocument($request, $type, $id)) {
            return $denied;
        }

        // Thread/type/document coherence — refuse cross-document or cross-type threads.
        $threadModel = DocumentEditThread::query()->find($thread);
        if ($threadModel === null) {
            return $this->jsonError('thread_not_found', "Thread #{$thread} not found", 404);
        }
        if ($threadModel->document_type !== $type || (int) $threadModel->document_id !== $id) {
            return $this->jsonError('thread_mismatch',
                "Thread #{$thread} does not belong to {$type} #{$id}", 422);
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
            $this->audit(
                $status === DocumentChangeSet::STATUS_VALIDATED ? 'change_set_validated' : 'change_set_rejected',
                $type, $id,
                ['change_set_id' => $changeSet->id, 'thread_id' => $threadModel->id, 'error_codes' => array_column($errors, 'code')],
            );
        }

        return response()->json([
            'message'    => $message->only(['id', 'thread_id', 'role', 'created_at']),
            'change_set' => $changeSet?->only(['id', 'status', 'validation_errors', 'created_at']),
        ], 201);
    }

    // ─── POST /documents/{type}/{id}/threads/{thread}/parse ───────────────────
    //
    // Parse-only endpoint (Pass D). Runs the conversational AI parser over
    // the user's message, validates the output against schema + safety +
    // adapter allow-list, and persists the result as a validated or
    // rejected change-set. Never calls apply.

    public function parseMessage(Request $request, string $type, int $id, int $thread): JsonResponse
    {
        $adapter = $this->resolveAdapter($type);
        if ($adapter === null) {
            return $this->jsonError('unknown_document_type', "Unknown document type '{$type}'", 404);
        }
        if ($denied = $this->authorizeDocument($request, $type, $id)) {
            return $denied;
        }

        $threadModel = DocumentEditThread::query()->find($thread);
        if ($threadModel === null) {
            return $this->jsonError('thread_not_found', "Thread #{$thread} not found", 404);
        }
        if ($threadModel->document_type !== $type || (int) $threadModel->document_id !== $id) {
            return $this->jsonError('thread_mismatch', "Thread #{$thread} does not belong to {$type} #{$id}", 422);
        }

        $data = $request->validate([
            'message'    => 'required|string|max:8000',
            'model_name' => 'nullable|string|max:128',
        ]);

        // Record the user's message first so even a failed parse has a trace.
        DocumentEditMessage::create([
            'thread_id'       => $threadModel->id,
            'role'            => DocumentEditMessage::ROLE_USER,
            'content'         => $data['message'],
            'operations_json' => null,
        ]);

        // Run the parser. Payload snapshot is the adapter's loadPayload output
        // filtered to the safe subset by DocumentEditParsingPromptFactory.
        $payload = $adapter->loadPayload($id);
        $result  = $this->parser->parse(
            adapter:         $adapter,
            userMessage:     $data['message'],
            documentPayload: $payload,
            modelName:       $data['model_name'] ?? null,
            logContext:      [
                'document_type' => $type,
                'document_id'   => $id,
                'thread_id'     => $threadModel->id,
                'user_id'       => $request->user()?->id,
            ],
        );

        $assistantContent = $result['status'] === \App\Services\DocumentEdits\DocumentEditParserService::STATUS_SUCCESS
            ? ($result['summary'] !== '' ? $result['summary'] : 'Parsed ' . count($result['operations']) . ' operation(s).')
            : ('Parse failed after ' . $result['attempts'] . ' attempt(s).');

        DocumentEditMessage::create([
            'thread_id'       => $threadModel->id,
            'role'            => DocumentEditMessage::ROLE_ASSISTANT,
            'content'         => $assistantContent,
            'operations_json' => $result['operations'] ?: null,
        ]);

        // Create the change-set row. Validated on success, rejected on failure.
        $cs = DocumentChangeSet::create([
            'thread_id'         => $threadModel->id,
            'document_type'     => $type,
            'document_id'       => $id,
            'base_revision_id'  => $threadModel->base_revision_id,
            'status'            => $result['status'] === \App\Services\DocumentEdits\DocumentEditParserService::STATUS_SUCCESS
                                      ? DocumentChangeSet::STATUS_VALIDATED
                                      : DocumentChangeSet::STATUS_REJECTED,
            // operations_json is NOT NULL in schema; use [] for parse_failed
            // so we still record the rejection with its validation_errors.
            'operations_json'   => ! empty($result['operations']) ? $result['operations'] : [],
            'validation_errors' => empty($result['errors']) ? null : $result['errors'],
            'model_name'        => $result['model_name'],
        ]);

        $this->audit($cs->status === DocumentChangeSet::STATUS_VALIDATED ? 'parse_validated' : 'parse_rejected',
            $type, $id, [
                'change_set_id' => $cs->id,
                'thread_id'     => $threadModel->id,
                'attempts'      => $result['attempts'],
                'op_count'      => count($result['operations']),
                'error_codes'   => array_unique(array_column($result['errors'], 'code')),
            ]);

        return response()->json([
            'parse_status'      => $cs->status,
            'change_set_id'     => $cs->id,
            'operations_json'   => $result['operations'],
            'summary'           => $result['summary'],
            'validation_errors' => $result['errors'],
        ], $cs->status === DocumentChangeSet::STATUS_VALIDATED ? 201 : 422);
    }

    // ─── GET /documents/{type}/{id}/changes/{changeSet} ───────────────────────

    public function showChangeSet(Request $request, string $type, int $id, int $changeSet): JsonResponse
    {
        if ($this->resolveAdapter($type) === null) {
            return $this->jsonError('unknown_document_type', "Unknown document type '{$type}'", 404);
        }
        // Ownership / 401 / 404(doc) check — matches every other doc-edit endpoint.
        if ($denied = $this->authorizeDocument($request, $type, $id)) {
            return $denied;
        }

        $cs = DocumentChangeSet::query()
            ->where('document_type', $type)
            ->where('document_id',   $id)
            ->find($changeSet);
        if ($cs === null) {
            return $this->jsonError('change_set_not_found', "Change-set #{$changeSet} not found", 404);
        }

        // Preview diff — compute against the change-set's BASE revision
        // snapshot (the state the change-set was proposed from), not the
        // current live payload. That way a concurrent edit between propose
        // and view doesn't make the preview look wrong. Falls back to the
        // live payload if the base revision snapshot is missing.
        $preview = [];
        try {
            $adapter = $this->resolveAdapter($type);
            if ($adapter !== null && $cs->status !== DocumentChangeSet::STATUS_REJECTED) {
                $baseRev = $cs->baseRevision;
                $before  = is_array($baseRev?->payload_snapshot)
                    ? (array) $baseRev->payload_snapshot
                    : ($adapter->loadPayload($id) ?? []);
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

    public function applyChangeSet(Request $request, string $type, int $id, int $changeSet): JsonResponse
    {
        $adapter = $this->resolveAdapter($type);
        if ($adapter === null) {
            return $this->jsonError('unknown_document_type', "Unknown document type '{$type}'", 404);
        }
        if ($denied = $this->authorizeDocument($request, $type, $id)) {
            return $denied;
        }

        $cs = DocumentChangeSet::query()->find($changeSet);
        if ($cs === null) {
            return $this->jsonError('change_set_not_found', "Change-set #{$changeSet} not found", 404);
        }
        // Type / document coherence — change-set must belong to this URL pair.
        if ($cs->document_type !== $type || (int) $cs->document_id !== $id) {
            return $this->jsonError('change_set_mismatch',
                "Change-set #{$changeSet} does not belong to {$type} #{$id}", 422);
        }

        if ($cs->status === DocumentChangeSet::STATUS_APPLIED) {
            return $this->jsonError('already_applied', 'Change-set already applied', 409);
        }
        if ($cs->status === DocumentChangeSet::STATUS_REJECTED) {
            return $this->jsonError('change_set_rejected', 'Change-set failed validation', 422, [
                'validation_errors' => $cs->validation_errors,
            ]);
        }

        // Optimistic concurrency — reject if a newer revision has been recorded
        // since this change-set was proposed. Prevents applying a stale plan
        // over a newer state.
        //
        // Opt-in recovery: when the client passes ?rebase=1 the server retargets
        // the change-set to the current revision before applying. This preserves
        // the AI-parsed ops so the user does not have to re-describe their intent
        // after a trivial concurrent write. Safe because the subsequent
        // applyOperation walk runs against the fresh payload — if the ops would
        // conflict with the newer state they still return ok=false below and
        // the change-set lands as rejected. The audit trail records the rebase.
        $latest = \App\Models\DocumentRevision::query()
            ->where('document_type', $type)
            ->where('document_id',   $id)
            ->latest('id')
            ->first();
        if ($latest !== null && (int) $cs->base_revision_id !== (int) $latest->id) {
            if ($request->boolean('rebase')) {
                $previousBase = (int) $cs->base_revision_id;
                $cs->update(['base_revision_id' => $latest->id]);
                $this->audit('change_set_rebased', $type, $id, [
                    'change_set_id'        => $cs->id,
                    'from_base_revision'   => $previousBase,
                    'to_base_revision'     => $latest->id,
                ]);
            } else {
                return $this->jsonError('base_revision_stale',
                    "Change-set was based on revision #{$cs->base_revision_id} but revision #{$latest->id} is now current.",
                    409,
                    [
                        'expected_base_revision_id' => $latest->id,
                        'rebase_available'          => true,
                    ],
                );
            }
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
            $this->audit('change_set_apply_rejected', $type, $id, [
                'change_set_id' => $cs->id,
                'error_codes'   => array_column($applyErrors, 'code'),
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

        $this->audit('change_set_applied', $type, $id, [
            'change_set_id'      => $cs->id,
            'new_revision_id'    => $newRev?->id,
            'artifact_filename'  => $artifactFilename,
            'ops_count'          => count((array) $cs->operations_json),
        ]);

        return response()->json([
            'change_set'        => $cs->only(['id', 'status']),
            'new_revision'      => $newRev?->only(['id', 'parent_revision_id', 'source', 'artifact_filename', 'created_at']),
            'artifact_filename' => $artifactFilename,
        ]);
    }

    // ─── GET /documents/{type}/{id}/revisions ─────────────────────────────────

    public function listRevisions(Request $request, string $type, int $id): JsonResponse
    {
        if ($this->resolveAdapter($type) === null) {
            return $this->jsonError('unknown_document_type', "Unknown document type '{$type}'", 404);
        }
        if ($denied = $this->authorizeDocument($request, $type, $id)) {
            return $denied;
        }

        $revisions = $this->revisions->listForDocument($type, $id);

        return response()->json([
            'revisions' => $revisions->map(fn ($r) => $r->only([
                'id', 'parent_revision_id', 'source', 'change_summary',
                'artifact_filename', 'created_by', 'created_at',
            ]))->values(),
        ]);
    }

    // ─── GET /documents/{type}/{id}/revisions-view ────────────────────────────

    public function revisionsView(Request $request, string $type, int $id): \Illuminate\Http\Response|JsonResponse|View
    {
        if ($this->resolveAdapter($type) === null) {
            return $this->jsonError('unknown_document_type', "Unknown document type '{$type}'", 404);
        }
        if ($denied = $this->authorizeDocument($request, $type, $id)) {
            return $denied;
        }
        $revisions = $this->revisions->listForDocument($type, $id);
        return view('document-edits.revisions', [
            'document_type' => $type,
            'document_id'   => $id,
            'revisions'     => $revisions,
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

    /**
     * Accessibility check for a document edit thread. Returns null when the
     * current (authenticated) user may access the row; otherwise returns a
     * 401 (unauthenticated) or 404 (document does not exist) response.
     *
     * Shared team workspace: any authenticated user may open/parse/apply edit
     * threads on any existing document — there is no per-owner 403. ownerIdFor()
     * is still consulted purely to distinguish a missing document (→ 404).
     */
    private function authorizeDocument(Request $request, string $type, int $id): ?JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return $this->jsonError('unauthenticated', 'Authentication required', 401);
        }

        $ownerId = $this->ownerIdFor($type, $id);
        if ($ownerId === null) {
            return $this->jsonError('document_not_found', "{$type} #{$id} not found", 404);
        }

        return null;
    }

    /** Returns owner user_id for the document, or null when not found. */
    private function ownerIdFor(string $type, int $id): ?int
    {
        $class = match ($type) {
            'rams'      => RamsDocument::class,
            'survey'    => SiteSurvey::class,
            'worksheet' => Worksheet::class,
            'om'        => OmManual::class,
            'cable'     => CableSchedule::class,
            default     => null,
        };
        if ($class === null) return null;

        $row = $class::query()->find($id);
        if ($row === null) return null;
        return (int) $row->user_id;
    }

    /**
     * Structured audit log — one line per state transition so operators can
     * trace propose → validate → apply / reject sequences after the fact.
     */
    private function audit(string $event, string $type, int $id, array $context = []): void
    {
        Log::info('DocumentEditController: ' . $event, array_merge([
            'document_type' => $type,
            'document_id'   => $id,
            'user_id'       => auth()->id(),
        ], $context));
    }
}
