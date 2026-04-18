<?php

namespace App\Services\DocumentEdits\Adapters;

use App\Models\Worksheet;
use App\Services\DocumentEdits\DocumentEditAdapterInterface;

/**
 * Pass-A stub for worksheet edits.
 *
 * Load path is real (returns the Worksheet's generated_data) so the
 * controller's create-thread flow can produce a non-empty base revision.
 *
 * Apply path is deliberately stubbed: returns ok=false with a stable code so
 * the apply endpoint responds 422, never 500. Real operation wiring lands in
 * a later pass.
 */
class WorksheetEditAdapter implements DocumentEditAdapterInterface
{
    public function documentType(): string
    {
        return 'worksheet';
    }

    public function loadPayload(int $documentId): ?array
    {
        /** @var Worksheet|null $worksheet */
        $worksheet = Worksheet::query()->find($documentId);
        if ($worksheet === null) {
            return null;
        }
        // generated_data is the authoritative data payload for worksheets.
        return (array) ($worksheet->generated_data ?? []);
    }

    /**
     * Allow-list of operation names the adapter will (eventually) service.
     * In pass A these are accepted by the validator and then cleanly
     * rejected at apply() with a stable "not implemented" code.
     *
     * @return array<int, string>
     */
    public function allowedOperations(): array
    {
        return [
            'update_room_field',
            'add_blocker',
            'remove_blocker',
            'set_category_override',
        ];
    }

    public function applyOperation(array $payload, array $op): array
    {
        return [
            'ok'      => false,
            'code'    => 'not_implemented_in_pass_a',
            'error'   => "Worksheet operation '{$op['op']}' is not implemented yet — available from the next pass.",
        ];
    }
}
