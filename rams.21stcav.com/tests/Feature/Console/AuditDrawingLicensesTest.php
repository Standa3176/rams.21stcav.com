<?php

namespace Tests\Feature\Console;

use App\Console\Commands\AuditDrawingLicensesCommand;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Phase 20 Plan 02 Task 2 — `drawings:audit-licenses` command coverage.
 *
 * The command is operator-facing (deploy preflight): runs `composer licenses
 * --format=json` + greps `package-lock.json` for GPL/AGPL strings and exits
 * non-zero if any are found. Tests use a subclass that overrides the two
 * helpers (runComposerLicenses + runNpmLockGrep) with stubbed payloads so
 * no real composer process runs in the test suite.
 *
 * @see app/Console/Commands/AuditDrawingLicensesCommand.php
 */
class AuditDrawingLicensesTest extends TestCase
{
    // ── 1. Clean composer state — exit 0, no GPL/AGPL flagged ──────────────

    public function test_audit_passes_on_current_clean_composer_state(): void
    {
        $exit = Artisan::call('drawings:audit-licenses');
        $output = Artisan::output();

        $this->assertSame(
            0,
            $exit,
            'Audit must exit 0 against current composer state (only MIT / Apache-2.0 / permissive deps): '.$output
        );
        // Output should not flag any package as GPL/AGPL offender.
        $this->assertStringNotContainsString(
            'OFFENDER:',
            $output,
            'No package should be flagged as offender on current clean composer state'
        );
    }

    // ── 2. Simulated GPL dep — exit 1 with offender named ──────────────────

    public function test_audit_fails_when_a_GPL_dep_is_simulated(): void
    {
        $this->bindStub(
            composer: ['evil/evil-pkg' => 'GPL-3.0-only'],
            npm:      [],
        );

        $exit = Artisan::call('drawings:audit-licenses');
        $output = Artisan::output();

        $this->assertSame(1, $exit, 'Audit must exit 1 when a GPL package is present: '.$output);
        $this->assertStringContainsString('evil/evil-pkg', $output, 'Output must name the offending package');
        $this->assertStringContainsString('GPL-3.0-only', $output, 'Output must include the offending license');
    }

    // ── 3. LGPL only fails with --strict ───────────────────────────────────

    public function test_audit_lgpl_only_fails_with_strict_flag(): void
    {
        $this->bindStub(
            composer: ['borderline/lgpl-dep' => 'LGPL-3.0-or-later'],
            npm:      [],
        );

        // Without --strict: LGPL is allowed → exit 0.
        $exitDefault = Artisan::call('drawings:audit-licenses');
        $this->assertSame(
            0,
            $exitDefault,
            'LGPL must NOT fail in default mode: '.Artisan::output()
        );

        // With --strict: LGPL fails → exit 1.
        $exitStrict = Artisan::call('drawings:audit-licenses', ['--strict' => true]);
        $strictOutput = Artisan::output();
        $this->assertSame(
            1,
            $exitStrict,
            'LGPL MUST fail with --strict: '.$strictOutput
        );
        $this->assertStringContainsString('borderline/lgpl-dep', $strictOutput);
        $this->assertStringContainsString('LGPL-3.0-or-later', $strictOutput);
    }

    /**
     * Bind a subclass of AuditDrawingLicensesCommand into the container that
     * returns stubbed composer + npm license maps. Mirrors the test pattern
     * used in BoundPdfDownloadTest::bindRendererFake.
     */
    private function bindStub(array $composer, array $npm): void
    {
        $this->app->bind(AuditDrawingLicensesCommand::class, function () use ($composer, $npm) {
            return new class($composer, $npm) extends AuditDrawingLicensesCommand {
                public function __construct(private array $stubComposer, private array $stubNpm)
                {
                    parent::__construct();
                }

                protected function runComposerLicenses(): array
                {
                    return $this->stubComposer;
                }

                protected function runNpmLockGrep(): array
                {
                    return $this->stubNpm;
                }
            };
        });
    }
}
