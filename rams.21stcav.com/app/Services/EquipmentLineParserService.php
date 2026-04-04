<?php

namespace App\Services;

/**
 * Splits quantity-prefixed (or quantity-suffixed) equipment lines into structured arrays.
 *
 * No AI usage. No external dependencies.
 *
 * Supported formats
 * -----------------
 *   Prefix: "2 Logitech Rally Bar Graphite"   → quantity=2, name="Logitech Rally Bar Graphite"
 *   Suffix: "Sony 85\" 4K Display 1"          → quantity=1, name="Sony 85\" 4K Display"
 *
 * Filtering
 * ---------
 * The raw PDF stream fallback can produce garbage lines such as:
 *   "17 0 obj"  — PDF object headers
 *   "0612"      — numeric-only strings with no letters
 * These are rejected before the qty/name split is attempted.
 */
class EquipmentLineParserService
{
    /**
     * Parse an array of raw equipment line strings into structured objects.
     *
     * Tries quantity-prefix format first, then quantity-suffix format.
     * Lines matching neither are silently skipped.
     * Pre-filters reject PDF object headers and non-alphabetic lines.
     *
     * @param  string[] $lines
     * @return array<int, array{quantity: int, name: string}>
     */
    public function parse(array $lines): array
    {
        $parsed = [];

        foreach ($lines as $line) {
            $line = trim($line);

            // 1. Reject PDF object header tokens: "17 0 obj", "5 0 obj" etc.
            if (preg_match('/^\d+\s+0\s+obj$/i', $line)) {
                continue;
            }

            // 2. Reject lines that contain no alphabetic characters at all.
            if (! preg_match('/[a-zA-Z]/', $line)) {
                continue;
            }

            // 3a. Quantity-prefix format: "2 Logitech Rally Bar"
            if (preg_match('/^\s*(\d+)\s+(.{3,})$/u', $line, $m)) {
                $parsed[] = [
                    'quantity' => (int) $m[1],
                    'name'     => trim($m[2]),
                ];
                continue;
            }

            // 3b. Quantity-suffix format: "Sony 85\" 4K Display 1"
            if (preg_match('/^(.{3,})\s+(\d+)$/u', $line, $m)) {
                $parsed[] = [
                    'quantity' => (int) $m[2],
                    'name'     => trim($m[1]),
                ];
                continue;
            }
        }

        return $parsed;
    }
}
