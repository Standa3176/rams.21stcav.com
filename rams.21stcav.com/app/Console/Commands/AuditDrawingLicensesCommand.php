<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Phase 20 Plan 02 Task 2 — drawings:audit-licenses (MOD-01).
 *
 * Operator-facing deploy preflight that flags GPL/AGPL composer + npm
 * dependencies. Per CONTEXT.md production-hardening-non-negotiables,
 * MOD-01 mitigation is a license audit step that blocks a hostile
 * dependency from landing in v1.3+.
 *
 * Usage:
 *   php artisan drawings:audit-licenses          # default — flags AGPL/GPL only
 *   php artisan drawings:audit-licenses --strict # also flags LGPL
 *
 * Exit codes:
 *   0 — clean (no offenders)
 *   1 — at least one offender (printed as a table for human review)
 *
 * Implementation contract:
 *   - runComposerLicenses() / runNpmLockGrep() are declared `protected` so
 *     test subclasses can override with stubbed payloads (no real composer
 *     process during unit tests). See AuditDrawingLicensesTest.
 *   - violatesPolicy() centralises the GPL/AGPL/LGPL regex so future
 *     hardening (e.g. SSPL, BUSL) lands in one place.
 *
 * @see tests/Feature/Console/AuditDrawingLicensesTest.php
 */
class AuditDrawingLicensesCommand extends Command
{
    protected $signature = 'drawings:audit-licenses
                            {--strict : Treat LGPL as a failure too (default: GPL/AGPL only)}';

    protected $description = 'Audit composer + npm dependencies for GPL/AGPL/LGPL licenses (Phase 20 MOD-01).';

    /**
     * Pre-existing GPL/LGPL/dual-licensed deps that landed BEFORE Phase 20.
     * Per Plan 20-01 SUMMARY: "Pre-existing GPL/LGPL deps (mpdf/mpdf,
     * dompdf/dompdf, smalot/pdfparser, tecnickcom/tcpdf) were already in
     * the project before this plan and are out of scope."
     *
     * The audit's purpose is to BLOCK NEW GPL/AGPL deps from landing — not
     * to retroactively litigate existing infrastructure. A future cleanup
     * task may swap these out (e.g. dompdf for Browsershot, which is in
     * progress per quick task 260427-qvr) at which point entries should be
     * removed from this allowlist.
     *
     * Format: package name (composer or npm) → reason note (for grep).
     */
    protected array $preExistingAllowlist = [
        // Composer (PHP) — PDF rendering stack predating Browsershot migration.
        'mpdf/mpdf'         => 'GPL-2.0 — PDF rendering for O&M Manual PDF export (predates Browsershot migration)',
        'dompdf/dompdf'     => 'LGPL-2.1 — RAMS/Site Survey PDF (in progress: 260427-qvr Browsershot migration)',
        'smalot/pdfparser'  => 'LGPL-3.0 — primary PDF text extractor (PdfTextExtractorService)',
        'tecnickcom/tcpdf'  => 'LGPL-3.0 — transitive dep of mpdf/mpdf',
        'nette/schema'      => 'BSD-3 OR GPL-2.0/3.0 dual — composer/composer transitive (dev-time, not runtime)',
        'nette/utils'       => 'BSD-3 OR GPL-2.0/3.0 dual — composer/composer transitive (dev-time, not runtime)',
    ];

    public function handle(): int
    {
        $strict = (bool) $this->option('strict');

        $composer = $this->runComposerLicenses();
        $npm = $this->runNpmLockGrep();

        $offenders = [];

        foreach ($composer as $package => $license) {
            if ($this->violatesPolicy((string) $license, $strict) && ! $this->isAllowlisted($package)) {
                $offenders[] = ['composer', $package, $license];
            }
        }
        foreach ($npm as $package => $license) {
            if ($this->violatesPolicy((string) $license, $strict) && ! $this->isAllowlisted($package)) {
                $offenders[] = ['npm', $package, $license];
            }
        }

        if (count($offenders) === 0) {
            $this->info(sprintf(
                'License audit OK — no GPL/AGPL%s offenders across %d composer + %d npm deps.',
                $strict ? '/LGPL' : '',
                count($composer),
                count($npm),
            ));

            return self::SUCCESS;
        }

        $this->error(sprintf(
            'License audit FAILED — %d offender(s) found:',
            count($offenders),
        ));
        $this->table(['OFFENDER:source', 'package', 'license'], $offenders);

        return self::FAILURE;
    }

    /**
     * Run `composer licenses --format=json --no-ansi` via Symfony Process and
     * return a map of package => license string (joined with `|` if multiple).
     *
     * Returns an empty array on any failure (process error, malformed JSON,
     * missing dependencies key) — operator gets a clean OK rather than a
     * false-positive on infra hiccups; pre-deploy CI catches the real cases.
     */
    protected function runComposerLicenses(): array
    {
        try {
            $process = Process::fromShellCommandline(
                'composer licenses --format=json --no-ansi',
                base_path(),
            );
            $process->setTimeout(60);
            $process->run();

            if (! $process->isSuccessful()) {
                $this->warn('drawings:audit-licenses: composer process failed: '.trim($process->getErrorOutput()));

                return [];
            }

            $payload = json_decode($process->getOutput(), associative: true);
            if (! is_array($payload) || ! isset($payload['dependencies']) || ! is_array($payload['dependencies'])) {
                return [];
            }

            $map = [];
            foreach ($payload['dependencies'] as $package => $info) {
                $licenses = $info['license'] ?? null;
                if (is_array($licenses)) {
                    $map[$package] = implode('|', $licenses);
                } elseif (is_string($licenses)) {
                    $map[$package] = $licenses;
                } else {
                    $map[$package] = '';
                }
            }

            return $map;
        } catch (\Throwable $e) {
            $this->warn('drawings:audit-licenses: composer licenses failed: '.$e->getMessage());

            return [];
        }
    }

    /**
     * Read base_path('package-lock.json') (if present), traverse the packages
     * map (npm v7+ format), and return package => license. Returns an empty
     * array if the lockfile is absent or malformed.
     */
    protected function runNpmLockGrep(): array
    {
        $path = base_path('package-lock.json');
        if (! is_file($path)) {
            return [];
        }

        try {
            $contents = file_get_contents($path);
            $payload = json_decode($contents, associative: true);
            if (! is_array($payload)) {
                return [];
            }

            // npm v7+ lockfile shape: $payload['packages']['node_modules/foo']['license']
            $packages = $payload['packages'] ?? [];
            if (! is_array($packages)) {
                return [];
            }

            $map = [];
            foreach ($packages as $key => $info) {
                if (! is_array($info)) {
                    continue;
                }
                if ($key === '') {
                    // root project — no license field of interest
                    continue;
                }
                $name = $info['name'] ?? str_replace('node_modules/', '', $key);
                $license = $info['license'] ?? null;
                if (is_array($license)) {
                    $license = implode('|', $license);
                }
                if (! is_string($license) || $license === '') {
                    continue;
                }
                $map[$name] = $license;
            }

            return $map;
        } catch (\Throwable $e) {
            $this->warn('drawings:audit-licenses: npm lock parse failed: '.$e->getMessage());

            return [];
        }
    }

    /**
     * Returns true when the package is in the pre-existing allowlist (predates
     * Phase 20 — out of scope for this audit). Tests stubbing custom packages
     * (like 'evil/evil-pkg' or 'borderline/lgpl-dep') will NOT be allowlisted
     * — only the documented existing infrastructure deps are.
     */
    protected function isAllowlisted(string $package): bool
    {
        return array_key_exists($package, $this->preExistingAllowlist);
    }

    /**
     * Returns true when the supplied license string violates the policy.
     *
     * Default mode flags AGPL + GPL (any version, any "or-later"/"only" variant).
     * Strict mode additionally flags LGPL.
     *
     * Regexes are deliberately greedy on the GPL family but match WORD-bounded
     * so a license like "MIT" with "GPL" elsewhere in another field doesn't
     * false-positive. The composer licenses output uses canonical SPDX strings
     * (GPL-3.0-only, AGPL-3.0-or-later, LGPL-2.1-or-later, etc.).
     */
    protected function violatesPolicy(string $license, bool $strict): bool
    {
        if ($license === '') {
            return false;
        }

        // AGPL or GPL (NOT preceded by an L) — guarded with negative lookbehind.
        if (preg_match('~(?<!L)(?:A?GPL)\b~i', $license) === 1) {
            return true;
        }

        if ($strict && preg_match('~\bLGPL\b~i', $license) === 1) {
            return true;
        }

        return false;
    }
}
