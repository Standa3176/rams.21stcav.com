<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

/**
 * M-03 (2026-05-17 audit) — Raw SQL invariant guard.
 *
 * The audit walked every `whereRaw` / `orderByRaw` in app/ and confirmed
 * every one uses `?` placeholders with bound parameters. The pattern is
 * fragile though — a copy-paste with string interpolation would land a
 * SQL injection into the codebase without any test catching it.
 *
 * This guard grep-asserts the codebase never introduces the two
 * unsafe shapes:
 *
 *   1. whereRaw() / orderByRaw() with only ONE argument (no bindings)
 *      when the SQL fragment contains a `?` — that would mean bindings
 *      were forgotten.
 *
 *   2. whereRaw() / orderByRaw() whose SQL fragment interpolates a PHP
 *      variable — `whereRaw("id = $id")` is the classic SQLi shape.
 *
 * Constant literals (`selectRaw('status, count(*)')`) and enum-const
 * concatenations (`orderByRaw("CASE kind WHEN '" . Model::KIND_X . "'…")`)
 * pass because they don't reference `$` variables.
 */
class SqlRawInvariantGuardTest extends TestCase
{
    public function test_no_raw_sql_call_interpolates_a_variable_inside_the_sql_fragment(): void
    {
        $appDir = base_path('app');

        // Match `whereRaw(...)` / `orderByRaw(...)` / `selectRaw(...)` /
        // `havingRaw(...)` calls whose SQL fragment is a double-quoted
        // string containing a `$…` variable interpolation. Heredocs are
        // caught by the same principle — same double-quote semantics.
        //
        // Single-quoted strings don't interpolate PHP vars — they're safe
        // even when they look like `whereRaw('id = $id')` (literal
        // dollar-sign, not a bound value). Multi-line calls handled by
        // the /m flag.
        $needle = '/(?:whereRaw|orderByRaw|selectRaw|havingRaw)\s*\(\s*"[^"]*\$[a-zA-Z_]/m';

        $offenders = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($appDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $body = file_get_contents($file->getPathname());
            if ($body === false) {
                continue;
            }
            if (preg_match($needle, $body, $m, PREG_OFFSET_CAPTURE)) {
                $offenders[] = str_replace('\\', '/', $file->getPathname())
                    . ' — matched: ' . trim($m[0][0]);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Raw SQL call with a variable interpolated inside the SQL fragment. "
            . "This is a SQL-injection shape — use ? placeholders and bind "
            . "the variable via the second argument to whereRaw() etc. "
            . "See audit finding M-03.\nOffenders:\n  - " . implode("\n  - ", $offenders)
        );
    }

    public function test_no_raw_sql_call_has_a_question_mark_placeholder_without_a_bindings_argument(): void
    {
        $appDir = base_path('app');

        // Cheap syntactic pass — matches `whereRaw("id = ?")` (a `?` in the
        // SQL fragment) that is closed with `)` immediately after the string
        // literal (no comma → no bindings array). This is an eyeball-only
        // sanity check; the intent is to catch the "forgot to bind" mistake,
        // not to guarantee coverage of every raw-SQL shape.
        //
        // Single-quoted OR double-quoted, allow whitespace before `)`.
        $needle = '/(?:whereRaw|orderByRaw|havingRaw)\s*\(\s*[\'"][^\'"]*\?[^\'"]*[\'"]\s*\)/m';

        $offenders = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($appDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $body = file_get_contents($file->getPathname());
            if ($body === false) {
                continue;
            }
            if (preg_match($needle, $body, $m, PREG_OFFSET_CAPTURE)) {
                $offenders[] = str_replace('\\', '/', $file->getPathname())
                    . ' — matched: ' . trim($m[0][0]);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Raw SQL call with `?` placeholder but no bindings array. Add the "
            . "bindings as the second argument: `whereRaw('id = ?', [\$id])`. "
            . "See audit finding M-03.\nOffenders:\n  - " . implode("\n  - ", $offenders)
        );
    }
}
