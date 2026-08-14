<?php

namespace Tests\Feature\Drawings;

use App\Models\DeviceStencil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 24 Plan 07 (DRAW-53), criterion 3 — the full browse -> edit ->
 * upload-logo -> promote loop, exercised through the REAL HTTP routes every
 * curation-UI plan in this phase shipped (24-03 through 24-07), not mocked
 * service calls.
 *
 * @see .planning/phases/24-stencil-curation-ui-quote-import-auto-stub/24-07-PLAN.md
 * @see .planning/phases/24-stencil-curation-ui-quote-import-auto-stub/24-CONTEXT.md (Success Criterion 3)
 */
class DeviceStencilCurationFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);

        Storage::fake('public');
    }

    public function test_full_browse_edit_upload_logo_promote_loop_via_real_routes(): void
    {
        // ── 1. Seed one auto-generated, needs_review stencil with zero ports ──
        $stencil = DeviceStencil::create([
            'part_number'    => 'PN-'.fake()->unique()->numerify('#####'),
            'manufacturer'   => 'Netgear',
            'model'          => 'GS312TP',
            'display_name'   => null,
            'mxgraph_xml'    => '<shape name="21cav.test" h="140" w="220" aspect="variable" strokewidth="inherit"><background/><foreground/></shape>',
            'default_width'  => 220,
            'default_height' => 140,
            'source'         => DeviceStencil::SOURCE_AUTO_GENERATED,
            'needs_review'   => true,
        ]);

        // ── 2. GET the list route filtered to needs_review=1 -> stencil appears ──
        $listFiltered = $this->actingAs($this->admin)
            ->get(route('admin.device-stencils.index', ['source' => DeviceStencil::SOURCE_AUTO_GENERATED, 'needs_review' => 1]));

        $listFiltered->assertOk();
        $listFiltered->assertSee($stencil->part_number);

        // ── 3. GET the edit route -> empty port table, Promote effectively blocked ──
        $editZeroPorts = $this->actingAs($this->admin)
            ->get(route('admin.device-stencils.edit', $stencil));

        $editZeroPorts->assertOk();
        // Zero rows pre-populated into the Alpine ports[] array.
        $editZeroPorts->assertSee('stencilPortEditor([]', false);
        // The client-side promotionBlockingReasons() mirror (UX-only — the
        // real enforcement is the server-side re-check proven elsewhere in
        // this plan's DeviceStencilPromotionTest) carries the exact D-04
        // zero-ports copy.
        $editZeroPorts->assertSee('Blocked: this stencil has zero ports.', false);

        // ── 4. PUT a valid ports array to the update route ───────────────────
        $updateResponse = $this->actingAs($this->admin)
            ->put(route('admin.device-stencils.update', $stencil), [
                'ports' => [
                    ['label' => 'HDMI In', 'side' => 'left', 'connector_type' => 'hdmi', 'signal_type' => 'video', 'direction' => 'in', 'sort_order' => 1, 'port_id' => 'hdmi-1'],
                    ['label' => 'LAN', 'side' => 'right', 'connector_type' => 'rj45', 'signal_type' => 'network', 'direction' => 'io', 'sort_order' => 2, 'port_id' => 'lan-1'],
                ],
            ]);
        $updateResponse->assertRedirect(route('admin.device-stencils.edit', $stencil));
        $updateResponse->assertSessionHas('success');

        $stencil->refresh();
        $this->assertSame(2, $stencil->ports()->count());

        // ── 5. POST a small valid PNG to the upload-logo route ────────────────
        $logoResponse = $this->actingAs($this->admin)
            ->post(route('admin.device-stencils.upload-logo', $stencil), [
                'logo' => UploadedFile::fake()->image('logo.png', 32, 32),
            ]);
        $logoResponse->assertRedirect(route('admin.device-stencils.edit', $stencil));
        $logoResponse->assertSessionHas('success');

        $stencil->refresh();
        $this->assertNotNull($stencil->logo_path);
        Storage::disk('public')->assertExists("device-stencils/{$stencil->id}/logo.png");

        // ── 6. POST to the promote route -> redirect + success flash ─────────
        $promoteResponse = $this->actingAs($this->admin)
            ->post(route('admin.device-stencils.promote', $stencil));

        $promoteResponse->assertRedirect(route('admin.device-stencils.index'));
        $promoteResponse->assertSessionHas('success');

        $stencil->refresh();
        $this->assertSame(DeviceStencil::SOURCE_ENGINEER_CURATED, $stencil->source);
        $this->assertFalse((bool) $stencil->needs_review);

        // ── 7. GET the list route again (no filter) -> engineer-curated badge, no needs-review badge ──
        $listAfter = $this->actingAs($this->admin)->get(route('admin.device-stencils.index'));
        $listAfter->assertOk();

        $content = $listAfter->getContent();
        // Anchor on the table-row markup specifically (not the success flash
        // banner above the table, which ALSO contains the part_number
        // substring — "...promoted to Engineer-Curated. It now renders...
        // part number {part_number}." — and would otherwise be the first,
        // wrong, match).
        $rowMarker = '<span class="stc-partno">'.$stencil->part_number;
        $rowStart = strpos($content, $rowMarker);
        $this->assertNotFalse($rowStart, 'Promoted stencil table row not found in the list view.');

        // Scope assertions to a window around this stencil's row so a
        // "Needs review" badge belonging to some OTHER row in the table
        // can never produce a false pass/fail.
        $rowWindow = substr($content, $rowStart, 1200);

        $this->assertStringContainsString('Engineer-curated', $rowWindow);
        $this->assertStringNotContainsString('Needs review', $rowWindow);
    }
}
