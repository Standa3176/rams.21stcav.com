<?php

namespace Tests\Feature\Drawings;

use App\Models\DeviceStencil;
use App\Models\DeviceStencilAudit;
use App\Models\DevicePort;
use App\Models\User;
use App\Services\Drawings\DeviceStencilCacheService;
use App\Services\Drawings\StencilPromotionValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 24 Plan 07 (DRAW-53) — StencilPromotionValidator (D-04's two-tier
 * hard-block/soft-warn gate) + DeviceStencilController::promote()/discard()
 * (D-03 audit trail, T-24-17 promote-bypass mitigation, criterion 4
 * cross-project propagation).
 *
 * Task 1 locks StencilPromotionValidator::evaluate() — 5 behaviour tests
 * using the EXACT UI-SPEC Copywriting Contract copy, byte-for-byte.
 *
 * Task 2 locks promote()/discard() — the server-side re-validation bypass
 * test (T-24-17), the successful-promote audit trail, criterion 4's
 * cross-project propagation via the existing DeviceStencilCacheService
 * lookup, and discard's unconditional success.
 *
 * @see app/Services/Drawings/StencilPromotionValidator.php
 * @see app/Http/Controllers/Admin/DeviceStencilController.php
 * @see .planning/phases/24-stencil-curation-ui-quote-import-auto-stub/24-07-PLAN.md
 * @see .planning/phases/24-stencil-curation-ui-quote-import-auto-stub/24-CONTEXT.md (D-03, D-04)
 * @see .planning/phases/24-stencil-curation-ui-quote-import-auto-stub/24-UI-SPEC.md (Copywriting Contract)
 */
class DeviceStencilPromotionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function makeStencil(array $overrides = []): DeviceStencil
    {
        return DeviceStencil::create(array_merge([
            'part_number'    => 'PN-'.fake()->unique()->numerify('#####'),
            'manufacturer'   => 'Netgear',
            'model'          => 'GS312TP',
            'display_name'   => null,
            'mxgraph_xml'    => '<shape name="21cav.test" h="140" w="220" aspect="variable" strokewidth="inherit"><background/><foreground/></shape>',
            'default_width'  => 220,
            'default_height' => 140,
            'source'         => DeviceStencil::SOURCE_AUTO_GENERATED,
            'needs_review'   => true,
        ], $overrides));
    }

    private function insertPort(DeviceStencil $stencil, array $overrides = []): void
    {
        DevicePort::insert(array_merge([
            'device_stencil_id' => $stencil->id,
            'label'              => 'HDMI In',
            'side'               => 'left',
            'connector_type'     => 'hdmi',
            'signal_type'        => 'video',
            'direction'          => 'in',
            'sort_order'         => 1,
            'port_id'            => 'hdmi-1',
            'created_at'         => now(),
            'updated_at'         => now(),
        ], $overrides));
    }

    // ── Task 1: Test 1 — zero ports -> single blocking reason ───────────────

    public function test_validator_blocks_zero_port_stencil(): void
    {
        $stencil = $this->makeStencil();

        $result = app(StencilPromotionValidator::class)->evaluate($stencil);

        $this->assertSame(['Blocked: this stencil has zero ports.'], $result['blocking']);
        $this->assertSame([], $result['warnings']);
    }

    // ── Task 1: Test 2 — 2 ports both missing signal_type -> ONE grouped line ──

    public function test_validator_groups_missing_signal_type_across_ports_into_a_single_reason(): void
    {
        $stencil = $this->makeStencil();
        $this->insertPort($stencil, ['port_id' => 'hdmi-1', 'signal_type' => '']);
        $this->insertPort($stencil, ['port_id' => 'lan-1', 'side' => 'right', 'connector_type' => 'rj45', 'signal_type' => '']);

        $result = app(StencilPromotionValidator::class)->evaluate($stencil->fresh());

        $this->assertContains('Blocked: 2 ports are missing a signal type.', $result['blocking']);
        $this->assertSame(
            1,
            count(array_filter($result['blocking'], static fn (string $r) => str_contains($r, 'signal type'))),
            'Missing signal_type across 2 ports must produce exactly ONE grouped line, not two.'
        );
    }

    // ── Task 1: Test 3 — duplicate port_id -> exact interpolated string ─────
    //
    // Two persisted rows sharing (device_stencil_id, port_id) can never exist
    // in real data — the device_ports_stencil_port_unique compound index (D-04's
    // own rationale for this check existing) rejects the INSERT before it
    // ever reaches this validator. This test therefore builds two UNSAVED
    // DevicePort instances directly and injects them via setRelation(), so
    // evaluate()'s in-memory duplicate-detection logic is exercised without
    // touching that index — proving the defence-in-depth check itself works,
    // independent of whether the DB constraint would also have caught it.

    public function test_validator_blocks_duplicate_port_id_with_offending_id_interpolated(): void
    {
        $stencil = $this->makeStencil();

        $portA = new DevicePort(['label' => 'A', 'side' => 'left', 'connector_type' => 'hdmi', 'signal_type' => 'video', 'direction' => 'in', 'sort_order' => 1, 'port_id' => 'dupe-1']);
        $portB = new DevicePort(['label' => 'B', 'side' => 'right', 'connector_type' => 'hdmi', 'signal_type' => 'video', 'direction' => 'in', 'sort_order' => 2, 'port_id' => 'dupe-1']);
        $stencil->setRelation('ports', collect([$portA, $portB]));

        $result = app(StencilPromotionValidator::class)->evaluate($stencil);

        $this->assertContains('Blocked: duplicate port ID "dupe-1".', $result['blocking']);
    }

    // ── Task 1: Test 4 — valid fields, no logo, one unclassified -> promotable with warnings ──

    public function test_validator_allows_promotion_with_warnings_for_no_logo_and_unclassified_signal_type(): void
    {
        $stencil = $this->makeStencil(['logo_svg' => null, 'logo_path' => null]);
        $this->insertPort($stencil, ['port_id' => 'hdmi-1', 'signal_type' => 'unclassified']);

        $result = app(StencilPromotionValidator::class)->evaluate($stencil->fresh());

        $this->assertSame([], $result['blocking']);
        $this->assertContains('This stencil has no manufacturer logo — promotion will proceed without one.', $result['warnings']);
        $this->assertContains('1 port has an unclassified signal type.', $result['warnings']);
    }

    // ── Task 1: Test 5 — fully valid, fully classified, logo-present -> zero blocking/warnings ──

    public function test_validator_returns_clean_result_for_fully_valid_stencil(): void
    {
        $stencil = $this->makeStencil(['logo_path' => '/storage/device-stencils/1/logo.svg']);
        $this->insertPort($stencil, ['port_id' => 'hdmi-1', 'signal_type' => 'video', 'x_pct' => null, 'y_pct' => 0.5]);

        $result = app(StencilPromotionValidator::class)->evaluate($stencil->fresh());

        $this->assertSame([], $result['blocking']);
        $this->assertSame([], $result['warnings']);
    }

    private function fullyValidTwoPorts(): array
    {
        return [
            ['label' => 'HDMI In', 'side' => 'left', 'connector_type' => 'hdmi', 'signal_type' => 'video', 'direction' => 'in', 'sort_order' => 1, 'port_id' => 'hdmi-1'],
            ['label' => 'LAN', 'side' => 'right', 'connector_type' => 'rj45', 'signal_type' => 'network', 'direction' => 'io', 'sort_order' => 2, 'port_id' => 'lan-1'],
        ];
    }

    // ── Task 2: T-24-17 — direct POST to promote on a zero-port stencil is refused ──

    public function test_promote_route_refuses_a_zero_port_stencil_via_direct_post(): void
    {
        $stencil = $this->makeStencil();

        $response = $this->actingAs($this->admin)
            ->post(route('admin.device-stencils.promote', $stencil));

        $response->assertRedirect(route('admin.device-stencils.edit', $stencil));
        $response->assertSessionHasErrors(['promote']);

        $stencil->refresh();

        $this->assertSame(DeviceStencil::SOURCE_AUTO_GENERATED, $stencil->source);
        $this->assertTrue((bool) $stencil->needs_review);
        $this->assertSame(0, DeviceStencilAudit::where('device_stencil_id', $stencil->id)->count());
    }

    // ── Task 2: successful promote flips source, clears needs_review, audits ──

    public function test_promote_flips_source_clears_needs_review_and_writes_audit_row(): void
    {
        $stencil = $this->makeStencil();
        foreach ($this->fullyValidTwoPorts() as $port) {
            $this->insertPort($stencil, $port);
        }

        $response = $this->actingAs($this->admin)
            ->post(route('admin.device-stencils.promote', $stencil));

        $response->assertRedirect(route('admin.device-stencils.index'));
        $response->assertSessionHas('success');

        $stencil->refresh();

        $this->assertSame(DeviceStencil::SOURCE_ENGINEER_CURATED, $stencil->source);
        $this->assertFalse((bool) $stencil->needs_review);

        $this->assertSame(1, DeviceStencilAudit::where('device_stencil_id', $stencil->id)->count());
        $audit = DeviceStencilAudit::where('device_stencil_id', $stencil->id)->first();
        $this->assertSame(DeviceStencilAudit::ACTION_PROMOTE, $audit->action);
        $this->assertSame($this->admin->id, $audit->user_id);
        $this->assertNotEmpty($audit->before_snapshot);
        $this->assertNotEmpty($audit->after_snapshot);
        $this->assertSame(DeviceStencil::SOURCE_AUTO_GENERATED, $audit->before_snapshot['source']);
        $this->assertSame(DeviceStencil::SOURCE_ENGINEER_CURATED, $audit->after_snapshot['source']);
    }

    // ── Task 2: criterion 4 — promotion propagates cross-project via the existing cache lookup ──

    public function test_promote_propagates_to_every_project_via_the_existing_cache_lookup(): void
    {
        $partNumber = 'PN-'.fake()->unique()->numerify('#####');
        $stencil = $this->makeStencil(['part_number' => DeviceStencil::normalisePartNumber($partNumber)]);
        foreach ($this->fullyValidTwoPorts() as $port) {
            $this->insertPort($stencil, $port);
        }

        $cache = app(DeviceStencilCacheService::class);

        // "Project A" resolving the SAME part_number BEFORE promotion — the
        // stub, zero-friction lookup Phase 21 D-03 already provides.
        $before = $cache->resolveForPartNumber($partNumber);
        $this->assertSame($stencil->id, $before->id);
        $this->assertSame(DeviceStencil::SOURCE_AUTO_GENERATED, $before->source);

        $this->actingAs($this->admin)->post(route('admin.device-stencils.promote', $stencil));

        // Same cache lookup, same part_number, AFTER promotion — zero extra
        // code, the SAME resolveForPartNumber call now returns the
        // engineer-curated row with its full port set.
        $after = $cache->resolveForPartNumber($partNumber);
        $this->assertSame($stencil->id, $after->id);
        $this->assertSame(DeviceStencil::SOURCE_ENGINEER_CURATED, $after->source);
        $this->assertSame(2, $after->ports()->count());
    }

    // ── Task 2: discard always succeeds, even when the current ports already fail the hard gate ──

    public function test_discard_regenerates_even_when_current_ports_fail_the_promote_hard_gate(): void
    {
        $stencil = $this->makeStencil(['display_name' => 'Generic Display']);
        // Deliberately invalid — blank signal_type, would hard-block Promote.
        $this->insertPort($stencil, ['port_id' => 'bad-1', 'signal_type' => '', 'label' => '']);

        $originalXml = $stencil->mxgraph_xml;

        $response = $this->actingAs($this->admin)
            ->post(route('admin.device-stencils.discard', $stencil));

        $response->assertRedirect(route('admin.device-stencils.edit', $stencil));
        $response->assertSessionHas('success');

        $stencil->refresh();

        $this->assertNotSame($originalXml, $stencil->mxgraph_xml);
        $this->assertSame(1, DeviceStencilAudit::where('device_stencil_id', $stencil->id)->count());
        $audit = DeviceStencilAudit::where('device_stencil_id', $stencil->id)->first();
        $this->assertSame(DeviceStencilAudit::ACTION_DISCARD_REGENERATE, $audit->action);
        $this->assertSame($originalXml, $audit->before_snapshot['mxgraph_xml']);

        // Discard does NOT flip source/needs_review — it is a reset, not a promotion.
        $this->assertSame(DeviceStencil::SOURCE_AUTO_GENERATED, $stencil->source);
    }
}
