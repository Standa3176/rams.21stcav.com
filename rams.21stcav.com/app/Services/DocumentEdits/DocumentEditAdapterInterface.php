<?php

namespace App\Services\DocumentEdits;

/**
 * Contract implemented by one adapter per document type (rams / survey /
 * worksheet / om / cable). Keeps the generic DocumentChangeSetValidator and
 * DocumentRevisionService free of type-specific knowledge.
 *
 * All methods MUST be pure/data-only. Adapters must never shell out, write
 * files, or touch routes/migrations/config — the safety validator enforces
 * this at the operation level, and adapter implementations must mirror the
 * guarantee in any applyOperation() logic.
 */
interface DocumentEditAdapterInterface
{
    /** Canonical type key this adapter handles: rams|survey|worksheet|om|cable. */
    public function documentType(): string;

    /**
     * Load the mutable payload for the given document id. Returns null when
     * the record doesn't exist or is not accessible to the adapter.
     *
     * @return array<string, mixed>|null
     */
    public function loadPayload(int $documentId): ?array;

    /**
     * Set of operation names this adapter supports. Any op with a name not
     * in this list is rejected by DocumentChangeSetValidator.
     *
     * @return array<int, string>
     */
    public function allowedOperations(): array;

    /**
     * Apply a single validated operation to the given payload and return the
     * resulting payload. Adapters return an array with either:
     *   ['ok' => true,  'payload' => <new payload>]
     *   ['ok' => false, 'error' => '<human reason>', 'code' => '<stable key>']
     *
     * Adapters MUST NOT throw on known invalid ops — return ok=false instead,
     * so the apply endpoint returns 422, not 500.
     *
     * @param  array<string, mixed> $payload  Pre-apply document payload
     * @param  array<string, mixed> $op       Validated operation spec
     * @return array{ok: bool, payload?: array, error?: string, code?: string}
     */
    public function applyOperation(array $payload, array $op): array;

    /**
     * Produce a lightweight preview-diff between two payloads. Used by the
     * change-set GET endpoint to populate the diff block the UI needs without
     * actually mutating data. Adapters that don't yet implement a real diff
     * should return an empty array.
     *
     * @param  array<string, mixed> $before
     * @param  array<string, mixed> $after
     * @return array<string, mixed>
     */
    public function summariseDiff(array $before, array $after): array;

    /**
     * Persist the post-apply payload back to the source model AND regenerate
     * any derived artifact (DOCX / XLSX / PDF). Returns the filename of the
     * newly-written artifact, or null when the adapter doesn't regenerate an
     * artifact (pass A stubs).
     *
     * Adapters MUST NOT throw; return null on failure and log the reason so
     * the apply endpoint can still record the revision cleanly.
     */
    public function commitChanges(int $documentId, array $payload): ?string;
}
