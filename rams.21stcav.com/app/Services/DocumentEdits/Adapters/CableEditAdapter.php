<?php

namespace App\Services\DocumentEdits\Adapters;

use App\Models\CableSchedule;
use App\Services\DocumentEdits\DocumentEditAdapterInterface;

class CableEditAdapter implements DocumentEditAdapterInterface
{
    public function documentType(): string
    {
        return 'cable';
    }

    public function loadPayload(int $documentId): ?array
    {
        $cable = CableSchedule::query()->with('items')->find($documentId);
        if ($cable === null) return null;
        return [
            'project_ref' => $cable->project_ref,
            'items'       => $cable->items->map(fn ($i) => $i->only([
                'id', 'cable_id', 'from_location', 'to_location',
                'cable_type', 'cores', 'approx_length_m', 'notes',
            ]))->all(),
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
            'error' => "Cable operation '{$op['op']}' is not implemented yet — available from the next pass.",
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
