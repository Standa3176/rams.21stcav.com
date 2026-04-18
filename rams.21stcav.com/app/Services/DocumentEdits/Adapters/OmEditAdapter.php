<?php

namespace App\Services\DocumentEdits\Adapters;

use App\Models\OmManual;
use App\Services\DocumentEdits\DocumentEditAdapterInterface;

class OmEditAdapter implements DocumentEditAdapterInterface
{
    public function documentType(): string
    {
        return 'om';
    }

    public function loadPayload(int $documentId): ?array
    {
        $om = OmManual::query()->find($documentId);
        if ($om === null) return null;
        return [
            'generated_data' => (array) ($om->generated_data ?? []),
            'extracted_data' => (array) ($om->extracted_data ?? []),
        ];
    }

    public function allowedOperations(): array
    {
        return [];
    }

    public function applyOperation(array $payload, array $op): array
    {
        return [
            'ok'    => false,
            'code'  => 'not_implemented_in_pass_a',
            'error' => "O&M operation '{$op['op']}' is not implemented yet — available from the next pass.",
        ];
    }
}
