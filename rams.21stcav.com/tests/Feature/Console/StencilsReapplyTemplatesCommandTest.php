<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\DevicePort;
use App\Models\DeviceStencil;
use App\Models\DeviceStencilAudit;
use App\Models\User;
use App\Services\Drawings\AutoGenericStencilGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for `stencils:reapply-templates` (Phase 24 Plan 08, D-08).
 *
 * Covers: dry-run safety (no writes), --commit persistence, the D-08
 * eligibility conjunction (source=auto-generated AND zero audit rows),
 * idempotence on repeated --commit runs, and the D-11 zero-port-stub
 * templating case.
 *
 * @see app/Console/Commands/StencilsReapplyTemplatesCommand.php
 * @see .planning/phases/24-stencil-curation-ui-quote-import-auto-stub/24-08-PLAN.md
 */
class StencilsReapplyTemplatesCommandTest extends TestCase
{
    use RefreshDatabase;

    private function generator(): AutoGenericStencilGenerator
    {
        return $this->app->make(AutoGenericStencilGenerator::class);
    }

    /**
     * Build a zero-port auto-generated stencil whose name resolves to the
     * `switch` port template (4 RJ45 ports, config/drawings.php) — mirrors
     * the D-11 shape: a pre-Phase-24 stub with no ports yet, still
     * source=auto-generated, no audit rows.
     */
    private function makeStaleSwitchStub(string $partNumber = 'SW-GS312'): DeviceStencil
    {
        $normalised = DeviceStencil::normalisePartNumber($partNumber);
        $displayName = 'Netgear GS312TP PoE Switch';

        $payload = $this->generator()->build([
            'manufacturer' => 'Netgear',
            'model'        => 'GS312TP',
            'name'         => $displayName,
            'part_number'  => $partNumber,
            'ports'        => [], // stale — built BEFORE any port template existed
        ]);

        return DeviceStencil::create([
            'part_number'    => $normalised,
            'manufacturer'   => 'Netgear',
            'model'          => 'GS312TP',
            'display_name'   => $displayName,
            'mxgraph_xml'    => $payload['mxgraph_xml'],
            'default_width'  => $payload['default_width'],
            'default_height' => $payload['default_height'],
            'source'         => DeviceStencil::SOURCE_AUTO_GENERATED,
            'needs_review'   => true,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Dry-run safety
    // ─────────────────────────────────────────────────────────────────────────

    public function test_dry_run_makes_no_db_writes_even_when_diffs_exist(): void
    {
        $stencil = $this->makeStaleSwitchStub();
        $originalXml = $stencil->mxgraph_xml;

        $this->artisan('stencils:reapply-templates')
            ->expectsOutputToContain('DRY-RUN MODE')
            ->expectsOutputToContain('DRY-RUN — no stencils were changed.')
            ->assertSuccessful();

        $stencil->refresh();
        $this->assertSame($originalXml, $stencil->mxgraph_xml);
        $this->assertSame(0, DevicePort::query()->where('device_stencil_id', $stencil->id)->count());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // --commit persistence + idempotence
    // ─────────────────────────────────────────────────────────────────────────

    public function test_commit_persists_new_template_and_second_run_is_a_no_op(): void
    {
        $stencil = $this->makeStaleSwitchStub();

        $this->artisan('stencils:reapply-templates', ['--commit' => true])
            ->expectsOutputToContain('COMMIT MODE')
            ->assertSuccessful();

        $stencil->refresh();
        $this->assertSame(4, $stencil->ports()->count());
        $this->assertSame('rj45', $stencil->ports()->first()->connector_type);
        // Still auto-generated — this command never promotes a stencil's
        // source, only its content, so it stays eligible for re-runs.
        $this->assertSame(DeviceStencil::SOURCE_AUTO_GENERATED, $stencil->source);

        $xmlAfterFirstRun = $stencil->mxgraph_xml;

        // Idempotence: re-running --commit against unchanged config must
        // produce zero further diffs.
        $this->artisan('stencils:reapply-templates', ['--commit' => true])
            ->expectsOutputToContain('Every eligible stencil already matches the current template vocabulary. Nothing to change.')
            ->assertSuccessful();

        $stencil->refresh();
        $this->assertSame($xmlAfterFirstRun, $stencil->mxgraph_xml);
        $this->assertSame(4, $stencil->ports()->count());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // D-08 safety conjunction — never touches curated or audited stencils
    // ─────────────────────────────────────────────────────────────────────────

    public function test_engineer_curated_stencil_is_never_touched(): void
    {
        $payload = $this->generator()->build([
            'manufacturer' => 'Netgear',
            'model'        => 'GS312TP',
            'name'         => 'Netgear GS312TP PoE Switch',
            'part_number'  => 'SW-CURATED',
            'ports'        => [],
        ]);

        $stencil = DeviceStencil::create([
            'part_number'    => DeviceStencil::normalisePartNumber('SW-CURATED'),
            'manufacturer'   => 'Netgear',
            'model'          => 'GS312TP',
            'display_name'   => 'Netgear GS312TP PoE Switch',
            'mxgraph_xml'    => $payload['mxgraph_xml'],
            'default_width'  => $payload['default_width'],
            'default_height' => $payload['default_height'],
            'source'         => DeviceStencil::SOURCE_ENGINEER_CURATED,
            'needs_review'   => false,
        ]);
        $originalXml = $stencil->mxgraph_xml;

        $this->artisan('stencils:reapply-templates', ['--commit' => true])
            ->assertSuccessful();

        $stencil->refresh();
        $this->assertSame($originalXml, $stencil->mxgraph_xml);
        $this->assertSame(0, $stencil->ports()->count());
    }

    public function test_auto_generated_stencil_with_an_audit_row_is_never_touched(): void
    {
        // Stale + zero-port, matches the eligibility shape EXCEPT it carries
        // an audit row (e.g. a prior discard-regenerate action) — must be
        // excluded regardless of its current source=auto-generated value.
        $stencil = $this->makeStaleSwitchStub('SW-AUDITED');
        $originalXml = $stencil->mxgraph_xml;

        $user = User::factory()->create();
        DeviceStencilAudit::create([
            'device_stencil_id' => $stencil->id,
            'user_id'           => $user->id,
            'action'            => DeviceStencilAudit::ACTION_DISCARD_REGENERATE,
            'before_snapshot'   => null,
            'after_snapshot'    => null,
        ]);

        $this->artisan('stencils:reapply-templates', ['--commit' => true])
            ->expectsOutputToContain('No eligible stencils')
            ->assertSuccessful();

        $stencil->refresh();
        $this->assertSame($originalXml, $stencil->mxgraph_xml);
        $this->assertSame(0, $stencil->ports()->count());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // D-11 — pre-existing zero-port stub templating
    // ─────────────────────────────────────────────────────────────────────────

    public function test_zero_port_stub_gets_templated_in_one_commit_pass(): void
    {
        // Simulates one of the 92 pre-existing zero-port
        // metadata.needs_phase_24_curation stubs (D-11) — resolves to the
        // single-port `display` template.
        $payload = $this->generator()->build([
            'manufacturer' => 'Sony',
            'model'        => 'FW-85BZ40L',
            'name'         => 'Sony FW-85BZ40L 85in Commercial Display',
            'part_number'  => 'FW-85BZ40L',
            'ports'        => [],
        ]);

        $stencil = DeviceStencil::create([
            'part_number'    => DeviceStencil::normalisePartNumber('FW-85BZ40L'),
            'manufacturer'   => 'Sony',
            'model'          => 'FW-85BZ40L',
            'display_name'   => 'Sony FW-85BZ40L 85in Commercial Display',
            'mxgraph_xml'    => $payload['mxgraph_xml'],
            'default_width'  => $payload['default_width'],
            'default_height' => $payload['default_height'],
            'source'         => DeviceStencil::SOURCE_AUTO_GENERATED,
            'metadata'       => ['needs_phase_24_curation' => true],
            'needs_review'   => true,
        ]);

        $this->artisan('stencils:reapply-templates', ['--commit' => true])
            ->assertSuccessful();

        $stencil->refresh();
        $this->assertSame(1, $stencil->ports()->count());
        $this->assertSame('hdmi', $stencil->ports()->first()->connector_type);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Clean state — nothing eligible
    // ─────────────────────────────────────────────────────────────────────────

    public function test_no_eligible_stencils_reports_cleanly(): void
    {
        $this->artisan('stencils:reapply-templates')
            ->expectsOutputToContain('No eligible stencils')
            ->assertSuccessful();
    }
}
