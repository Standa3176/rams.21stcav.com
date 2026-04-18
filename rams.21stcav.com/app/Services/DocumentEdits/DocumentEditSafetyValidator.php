<?php

namespace App\Services\DocumentEdits;

/**
 * First line of defence for chat-driven document edits.
 *
 * Scans the full operations JSON tree (keys AND string values) for tokens
 * that would allow a chat caller to target app code, routes, migrations,
 * config, or shell execution paths. If any are present, the change-set is
 * rejected with stable error codes before reaching the adapter.
 *
 * The denied token list is deliberately conservative and matches whole-word
 * boundaries inside keys OR anywhere inside string values (defence in depth:
 * an adapter operation that legitimately needs the word "file" in its text
 * isn't blocked — only the word "file" appearing as a key IS).
 */
class DocumentEditSafetyValidator
{
    /**
     * Tokens that must not appear as a JSON key anywhere in the operations
     * tree. Case-insensitive exact-match.
     */
    private const DENIED_KEYS = [
        'path', 'file', 'filepath', 'class', 'method', 'route', 'controller',
        'migration', 'blade', 'php', 'sql', 'shell', 'command',
    ];

    /**
     * Tokens that must not appear as substrings inside a string VALUE.
     * Restricted to clearly-dangerous tokens to avoid false positives.
     */
    private const DENIED_VALUE_SUBSTRINGS = [
        '<?php', '<?=',           // raw PHP tags
        'system(', 'exec(',        // PHP shell calls
        'passthru(', 'proc_open(', // PHP shell calls
        'eval(',                   // dynamic code
        '__halt_compiler',         // stop-compilation marker
    ];

    /** Max serialized operations_json size (bytes). */
    public const MAX_OPERATIONS_BYTES = 65_536;

    /** Max nested operation-tree depth. */
    public const MAX_DEPTH = 8;

    /**
     * Validate the operations JSON. Returns an array of stable error codes
     * paired with a human message. Empty array = safe.
     *
     * @param  mixed $operations  Typically array|null (decoded JSON payload)
     * @return list<array{code: string, message: string}>
     */
    public function validate(mixed $operations): array
    {
        $errors = [];

        if ($operations === null || $operations === []) {
            $errors[] = ['code' => 'empty_operations', 'message' => 'operations_json must contain at least one operation'];
            return $errors;
        }

        if (! is_array($operations)) {
            $errors[] = ['code' => 'operations_not_array', 'message' => 'operations_json must be an array'];
            return $errors;
        }

        // Size guard — encode once, compare against cap.
        $serialised = json_encode($operations);
        if ($serialised === false) {
            $errors[] = ['code' => 'operations_not_encodable', 'message' => 'operations cannot be JSON-encoded'];
            return $errors;
        }
        if (strlen($serialised) > self::MAX_OPERATIONS_BYTES) {
            $errors[] = [
                'code'    => 'operations_too_large',
                'message' => 'operations_json exceeds ' . self::MAX_OPERATIONS_BYTES . ' bytes',
            ];
        }

        // Depth + denied-token scan.
        $this->scan($operations, 0, $errors);

        // Dedupe by code+message so a repeated bad key doesn't spam the user.
        $seen = [];
        $unique = [];
        foreach ($errors as $e) {
            $fp = $e['code'] . '|' . $e['message'];
            if (isset($seen[$fp])) continue;
            $seen[$fp] = true;
            $unique[] = $e;
        }
        return $unique;
    }

    /**
     * Recursively walk the operations tree, appending errors.
     * @param list<array{code: string, message: string}> $errors (by-ref)
     */
    private function scan(mixed $node, int $depth, array &$errors): void
    {
        if ($depth > self::MAX_DEPTH) {
            $errors[] = ['code' => 'operations_nesting_too_deep', 'message' => 'operations nesting exceeds ' . self::MAX_DEPTH];
            return;
        }

        if (is_array($node)) {
            foreach ($node as $k => $v) {
                if (is_string($k)) {
                    $keyLower = strtolower(trim($k));
                    if (in_array($keyLower, self::DENIED_KEYS, true)) {
                        $errors[] = [
                            'code'    => 'denied_key',
                            'message' => "operation key '{$k}' is not permitted (code/file/route-like keys are blocked)",
                        ];
                    }
                }
                $this->scan($v, $depth + 1, $errors);
            }
            return;
        }

        if (is_string($node)) {
            foreach (self::DENIED_VALUE_SUBSTRINGS as $sub) {
                if (stripos($node, $sub) !== false) {
                    $errors[] = [
                        'code'    => 'denied_value_substring',
                        'message' => "operation value contains disallowed token '{$sub}'",
                    ];
                }
            }
        }
    }
}
