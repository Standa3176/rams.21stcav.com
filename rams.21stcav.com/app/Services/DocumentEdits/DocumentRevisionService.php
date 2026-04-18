<?php

namespace App\Services\DocumentEdits;

use App\Models\DocumentRevision;

/**
 * Write-side helper for document_revisions. Keeps the controller free of
 * Eloquent plumbing and centralises the "create base revision on first
 * thread" convention.
 */
class DocumentRevisionService
{
    /**
     * Find or create the initial base revision for a document. The base
     * revision is always source=base and has no parent. Subsequent
     * revisions (applied change-sets) hang off this base.
     *
     * @param array<string, mixed> $payload
     */
    public function ensureBaseRevision(string $documentType, int $documentId, array $payload, ?int $createdBy = null): DocumentRevision
    {
        $existing = DocumentRevision::query()
            ->where('document_type', $documentType)
            ->where('document_id',   $documentId)
            ->whereNull('parent_revision_id')
            ->where('source',        DocumentRevision::SOURCE_BASE)
            ->orderBy('id')
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        return DocumentRevision::create([
            'document_type'      => $documentType,
            'document_id'        => $documentId,
            'parent_revision_id' => null,
            'payload_snapshot'   => $payload,
            'artifact_filename'  => null,
            'change_summary'     => 'Initial snapshot',
            'source'             => DocumentRevision::SOURCE_BASE,
            'created_by'         => $createdBy,
        ]);
    }

    /**
     * Persist a new revision as a child of $parent, sourced by an applied
     * change-set or manual edit.
     *
     * @param array<string, mixed> $payload
     */
    public function recordRevision(
        DocumentRevision $parent,
        array $payload,
        string $source,
        ?string $summary = null,
        ?int $createdBy = null,
    ): DocumentRevision {
        return DocumentRevision::create([
            'document_type'      => $parent->document_type,
            'document_id'        => $parent->document_id,
            'parent_revision_id' => $parent->id,
            'payload_snapshot'   => $payload,
            'artifact_filename'  => null,
            'change_summary'     => $summary,
            'source'             => $source,
            'created_by'         => $createdBy,
        ]);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, DocumentRevision>
     */
    public function listForDocument(string $documentType, int $documentId)
    {
        return DocumentRevision::query()
            ->where('document_type', $documentType)
            ->where('document_id',   $documentId)
            ->orderBy('id')
            ->get();
    }
}
