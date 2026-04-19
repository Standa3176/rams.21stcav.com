<?php

namespace App\Services\DocumentEdits\Adapters;

use App\Models\RamsDocument;
use App\Services\DocumentEdits\DocumentEditAdapterInterface;

class RamsEditAdapter implements DocumentEditAdapterInterface
{
    public function documentType(): string
    {
        return 'rams';
    }

    public function loadPayload(int $documentId): ?array
    {
        $rams = RamsDocument::query()->find($documentId);
        if ($rams === null) return null;
        return [
            'generated_data' => (array) ($rams->generated_data ?? []),
            'reviewed_data'  => (array) ($rams->reviewed_data ?? []),
        ];
    }

    public function allowedOperations(): array
    {
        return [];  // Pass A: no ops enrolled — any op is rejected as unknown.
    }

    public function applyOperation(array $payload, array $op): array
    {
        return [
            'ok'    => false,
            'code'  => 'not_implemented_in_pass_a',
            'error' => "RAMS operation '{$op['op']}' is not implemented yet — available from the next pass.",
        ];
    }

    public function summariseDiff(array $before, array $after): array
    {
        return [];
    }

    public function commitChanges(int $documentId, array $payload): ?string
    {
        return null;
    }
}
