<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

/**
 * H-03 (2026-07-02) — Admin-check consistency invariant.
 *
 * The canonical admin check across the app is `auth()->user()?->isAdmin()`,
 * which delegates to User::isAdmin() (the ONE place the actual role string
 * comparison lives). Historically some controllers inline-compared
 * `->role === 'admin'`, which is brittle:
 *   - If the role string changes ('admin' → 'administrator', roles table,
 *     spatie/permission, etc.) inline checks silently pass the wrong users.
 *   - It scatters a security-sensitive check across many files, so any one
 *     of them getting the null-safe operator wrong (skipping `?->`) causes
 *     a TypeError-on-guest and a hard 500 that hides the intended 403.
 *
 * This test grep-asserts the raw `role === 'admin'` idiom only appears in
 * app/Models/User.php (the isAdmin() definition itself) and app/Policies/
 * ProjectPolicy.php (a documentation comment). Any new occurrence is a
 * regression — replace it with `->isAdmin()`.
 *
 * @see .planning/audits/security-audit-2026-05-17.md — finding H-03
 */
class AdminCheckConsistencyTest extends TestCase
{
    public function test_raw_admin_role_string_compare_is_confined_to_the_canonical_definition(): void
    {
        $appDir = base_path('app');

        // Case-sensitive substring scan — we want to catch `->role === 'admin'`,
        // `->role==='admin'`, `->role  === 'admin'`, and the double-quoted
        // variant. Using a permissive regex here so the guard survives minor
        // formatting drift.
        $needle = '/role\s*===\s*[\'"]admin[\'"]/';

        $offenders = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($appDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();

            // Normalise to forward slashes so the allow-list works on Windows
            // and *nix identically.
            $normalised = str_replace('\\', '/', $path);

            $body = file_get_contents($path);
            if ($body === false || ! preg_match($needle, $body)) {
                continue;
            }

            // Allow-list the canonical definition sites.
            $isCanonical = str_ends_with($normalised, '/Models/User.php');
            $isDocComment = str_ends_with($normalised, '/Policies/ProjectPolicy.php');

            if ($isCanonical || $isDocComment) {
                continue;
            }

            $offenders[] = $normalised;
        }

        $this->assertSame(
            [],
            $offenders,
            "Found raw `role === 'admin'` comparison(s) outside the canonical "
            . 'sites (User::isAdmin() / ProjectPolicy doc comment). Replace with '
            . '`auth()->user()?->isAdmin()`. See audit finding H-03. Offenders: '
            . "\n  - " . implode("\n  - ", $offenders)
        );
    }
}
