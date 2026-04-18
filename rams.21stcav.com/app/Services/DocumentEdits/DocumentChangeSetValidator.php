<?php

namespace App\Services\DocumentEdits;

/**
 * Validates a DocumentChangeSet against:
 *   1. DocumentEditSafetyValidator (code/route/file-token scan)
 *   2. Adapter's allowedOperations() allow-list (per-type vocabulary)
 *   3. Basic operation-structure shape (each op must be an array with 'op' key)
 *
 * Returns a flat error list; callers persist to change_set.validation_errors.
 */
class DocumentChangeSetValidator
{
    public function __construct(
        private readonly DocumentEditSafetyValidator $safety,
    ) {}

    /**
     * @param  array<string, mixed>|null $operations
     * @return list<array{code: string, message: string}>
     */
    public function validate(?array $operations, DocumentEditAdapterInterface $adapter): array
    {
        $errors = $this->safety->validate($operations);
        if (! empty($errors)) {
            // Safety failures dominate — do not run further adapter validation.
            return $errors;
        }

        // Operations must be an array of op objects, each with an 'op' key.
        $allowed = array_map('strtolower', $adapter->allowedOperations());
        foreach ((array) $operations as $idx => $op) {
            if (! is_array($op)) {
                $errors[] = ['code' => 'operation_not_array', 'message' => "operation [{$idx}] must be an object"];
                continue;
            }
            $opName = isset($op['op']) ? strtolower(trim((string) $op['op'])) : '';
            if ($opName === '') {
                $errors[] = ['code' => 'operation_missing_name', 'message' => "operation [{$idx}] must include an 'op' key"];
                continue;
            }
            if (! in_array($opName, $allowed, true)) {
                $errors[] = [
                    'code'    => 'unknown_operation',
                    'message' => "operation '{$opName}' at [{$idx}] is not in the adapter's allow-list",
                ];
            }
        }

        return $errors;
    }
}
