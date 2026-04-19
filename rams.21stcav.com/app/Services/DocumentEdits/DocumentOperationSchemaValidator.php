<?php

namespace App\Services\DocumentEdits;

/**
 * Strict shape validator for parser output. Enforces the JSON contract that
 * DocumentEditParserService expects from the LLM BEFORE safety or adapter
 * checks run. Keeps rejection reasons stable + machine-readable so retry
 * prompts can feed the errors back to the model.
 *
 * Top-level contract:
 *   {
 *     "operations": [ ... non-empty list ... ],
 *     "summary": "string ≤ 1000"
 *   }
 *
 * Each operation:
 *   {
 *     "op": "non-empty string",
 *     "target": {"room_name": "string|null", "index": "int|null"},
 *     "args": { ... arbitrary args, validated by adapter downstream ... },
 *     "rationale": "string ≤ 1000"
 *   }
 *
 * Unknown keys are rejected at top-level AND per-operation. Max 25 ops.
 */
class DocumentOperationSchemaValidator
{
    public const MAX_OPERATIONS    = 25;
    public const MAX_SUMMARY_LEN   = 1000;
    public const MAX_RATIONALE_LEN = 1000;

    public const TOP_LEVEL_KEYS = ['operations', 'summary'];
    public const OPERATION_KEYS = ['op', 'target', 'args', 'rationale'];
    public const TARGET_KEYS    = ['room_name', 'index'];

    /**
     * Validate and return a flat error list (empty = valid).
     *
     * @param  mixed $payload  Typically array decoded from JSON
     * @return list<array{code: string, message: string}>
     */
    public function validate(mixed $payload): array
    {
        $errors = [];

        if (! is_array($payload)) {
            return [['code' => 'schema_not_object', 'message' => 'root must be a JSON object']];
        }

        // Unknown keys
        foreach (array_keys($payload) as $k) {
            if (! in_array($k, self::TOP_LEVEL_KEYS, true)) {
                $errors[] = ['code' => 'schema_unknown_top_key', 'message' => "unknown top-level key '{$k}'"];
            }
        }

        // operations required + non-empty array
        if (! array_key_exists('operations', $payload)) {
            $errors[] = ['code' => 'schema_missing_operations', 'message' => 'top-level `operations` is required'];
        } elseif (! is_array($payload['operations'])) {
            $errors[] = ['code' => 'schema_operations_not_array', 'message' => '`operations` must be an array'];
        } elseif (count($payload['operations']) === 0) {
            $errors[] = ['code' => 'schema_operations_empty', 'message' => '`operations` must contain at least one operation'];
        } elseif (count($payload['operations']) > self::MAX_OPERATIONS) {
            $errors[] = [
                'code'    => 'schema_operations_too_many',
                'message' => 'max ' . self::MAX_OPERATIONS . ' operations per parse',
            ];
        } else {
            foreach ($payload['operations'] as $idx => $op) {
                $errors = array_merge($errors, $this->validateOperation($idx, $op));
            }
        }

        // summary required
        if (! array_key_exists('summary', $payload)) {
            $errors[] = ['code' => 'schema_missing_summary', 'message' => 'top-level `summary` is required'];
        } elseif (! is_string($payload['summary'])) {
            $errors[] = ['code' => 'schema_summary_not_string', 'message' => '`summary` must be a string'];
        } elseif (strlen($payload['summary']) > self::MAX_SUMMARY_LEN) {
            $errors[] = ['code' => 'schema_summary_too_long', 'message' => '`summary` exceeds ' . self::MAX_SUMMARY_LEN . ' chars'];
        }

        return $errors;
    }

    /** @return list<array{code: string, message: string}> */
    private function validateOperation(int|string $idx, mixed $op): array
    {
        $errors = [];

        if (! is_array($op)) {
            return [['code' => 'schema_operation_not_object', 'message' => "operations[{$idx}] must be an object"]];
        }

        // Unknown keys
        foreach (array_keys($op) as $k) {
            if (! in_array($k, self::OPERATION_KEYS, true)) {
                $errors[] = [
                    'code'    => 'schema_operation_unknown_key',
                    'message' => "operations[{$idx}] contains unknown key '{$k}'",
                ];
            }
        }

        // Required members
        foreach (self::OPERATION_KEYS as $req) {
            if (! array_key_exists($req, $op)) {
                $errors[] = [
                    'code'    => 'schema_operation_missing_field',
                    'message' => "operations[{$idx}] missing required field '{$req}'",
                ];
            }
        }

        if (isset($op['op'])) {
            if (! is_string($op['op']) || trim($op['op']) === '') {
                $errors[] = ['code' => 'schema_op_invalid', 'message' => "operations[{$idx}].op must be a non-empty string"];
            }
        }

        if (array_key_exists('target', $op)) {
            $t = $op['target'];
            if ($t !== null && ! is_array($t)) {
                $errors[] = ['code' => 'schema_target_not_object', 'message' => "operations[{$idx}].target must be an object or null"];
            } elseif (is_array($t)) {
                foreach (array_keys($t) as $k) {
                    if (! in_array($k, self::TARGET_KEYS, true)) {
                        $errors[] = [
                            'code'    => 'schema_target_unknown_key',
                            'message' => "operations[{$idx}].target contains unknown key '{$k}'",
                        ];
                    }
                }
                if (array_key_exists('room_name', $t) && $t['room_name'] !== null && ! is_string($t['room_name'])) {
                    $errors[] = ['code' => 'schema_target_room_name_invalid', 'message' => "operations[{$idx}].target.room_name must be string or null"];
                }
                if (array_key_exists('index', $t) && $t['index'] !== null && ! is_int($t['index'])) {
                    $errors[] = ['code' => 'schema_target_index_invalid', 'message' => "operations[{$idx}].target.index must be integer or null"];
                }
            }
        }

        if (array_key_exists('args', $op)) {
            if (! is_array($op['args'])) {
                $errors[] = ['code' => 'schema_args_not_object', 'message' => "operations[{$idx}].args must be an object"];
            }
        }

        if (array_key_exists('rationale', $op)) {
            if (! is_string($op['rationale'])) {
                $errors[] = ['code' => 'schema_rationale_not_string', 'message' => "operations[{$idx}].rationale must be a string"];
            } elseif (strlen($op['rationale']) > self::MAX_RATIONALE_LEN) {
                $errors[] = ['code' => 'schema_rationale_too_long', 'message' => "operations[{$idx}].rationale exceeds " . self::MAX_RATIONALE_LEN . ' chars'];
            }
        }

        return $errors;
    }

    /**
     * Flatten schema-valid operations back into the shape that
     * DocumentChangeSetValidator + adapters expect: each op as a flat dict
     * with the `op` name and all args merged in.
     *
     * @param  array<string, mixed> $payload  Already schema-validated payload
     * @return array<int, array<string, mixed>>
     */
    public function flattenToAdapterOps(array $payload): array
    {
        $out = [];
        foreach ((array) ($payload['operations'] ?? []) as $op) {
            if (! is_array($op)) continue;
            $flat = ['op' => trim((string) ($op['op'] ?? ''))];
            // Merge args directly onto the op. Adapter handlers already
            // expect keys like `room`, `tool`, `type`, `message`, etc.
            foreach ((array) ($op['args'] ?? []) as $k => $v) {
                if (is_string($k) && $k !== '' && $k !== 'op') {
                    $flat[$k] = $v;
                }
            }
            // Surface target.room_name as `room` when caller didn't set it in args.
            $roomName = $op['target']['room_name'] ?? null;
            if (is_string($roomName) && $roomName !== '' && ! isset($flat['room'])) {
                $flat['room'] = $roomName;
            }
            $targetIdx = $op['target']['index'] ?? null;
            if (is_int($targetIdx) && ! isset($flat['index'])) {
                $flat['index'] = $targetIdx;
            }
            $out[] = $flat;
        }
        return $out;
    }
}
