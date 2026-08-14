<?php

namespace Tests\Feature\Drawings;

use App\Models\DeviceStencil;
use App\Models\DeviceStencilAudit;
use App\Models\DevicePort;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 24 Plan 05 (DRAW-51) — stencil edit screen: port table (source of
 * truth) + batched Save + the D-17 curated-artwork guard.
 *
 * Task 1 locks UpdateDeviceStencilPortsRequest + DeviceStencilController::
 * edit()/update() — structural validation, device_ports replace +
 * mxgraph_xml regeneration in the same request (Pitfall 2 parity), and the
 * D-17 guard (tests 5, 6, 7).
 *
 * Task 2 locks the edit.blade.php / _port-table.blade.php pair at the
 * feature-test level this PHPUnit-only phase allows: Alpine `ports`
 * pre-population, the 600ms (not 200ms) debounce constant, a non-empty
 * delete-button aria-label binding, and the sub-900px single-column
 * breakpoint.
 *
 * @see app/Http/Requests/Admin/UpdateDeviceStencilPortsRequest.php
 * @see app/Http/Controllers/Admin/DeviceStencilController.php
 * @see resources/views/admin/device-stencils/edit.blade.php
 * @see resources/views/admin/device-stencils/_port-table.blade.php
 * @see .planning/phases/24-stencil-curation-ui-quote-import-auto-stub/24-05-PLAN.md
 * @see .planning/phases/24-stencil-curation-ui-quote-import-auto-stub/24-CONTEXT.md (D-17)
 */
class DeviceStencilEditTest extends TestCase
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

    private function twoValidPorts(): array
    {
        return [
            ['label' => 'HDMI In', 'side' => 'left', 'connector_type' => 'hdmi', 'signal_type' => 'video', 'direction' => 'in', 'sort_order' => 1, 'port_id' => 'hdmi-in'],
            ['label' => 'LAN', 'side' => 'right', 'connector_type' => 'rj45', 'signal_type' => 'network', 'direction' => 'io', 'sort_order' => 2, 'port_id' => 'lan-1'],
        ];
    }

    // ── Task 1: Test 1 — batched save replaces device_ports + regenerates mxgraph_xml ──

    public function test_update_replaces_device_ports_and_regenerates_mxgraph_xml(): void
    {
        $stencil = $this->makeStencil();
        DevicePort::insert([
            'device_stencil_id' => $stencil->id,
            'label'             => 'Stale Port',
            'side'              => 'left',
            'connector_type'    => 'hdmi',
            'signal_type'       => 'video',
            'direction'         => 'in',
            'sort_order'        => 1,
            'port_id'           => 'stale-1',
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->put(route('admin.device-stencils.update', $stencil), [
                'ports' => $this->twoValidPorts(),
            ]);

        $response->assertRedirect(route('admin.device-stencils.edit', $stencil));
        $response->assertSessionHas('success');

        $stencil->refresh();

        $this->assertSame(0, DevicePort::where('port_id', 'stale-1')->count());
        $this->assertSame(2, $stencil->ports()->count());
        $this->assertNotNull(DevicePort::where('device_stencil_id', $stencil->id)->where('port_id', 'hdmi-in')->first());
        $this->assertNotNull(DevicePort::where('device_stencil_id', $stencil->id)->where('port_id', 'lan-1')->first());

        // mxgraph_xml regenerated in the SAME request — no longer the
        // fixture's bare zero-port placeholder.
        $this->assertStringNotContainsString('21cav.test', $stencil->mxgraph_xml);
        $this->assertStringContainsString('<connections>', $stencil->mxgraph_xml);
    }

    // ── Task 1: Test 2 — duplicate port_id -> 422 distinct-rule error ──

    public function test_update_rejects_duplicate_port_id_within_posted_array(): void
    {
        $stencil = $this->makeStencil();

        $response = $this->actingAs($this->admin)
            ->from(route('admin.device-stencils.edit', $stencil))
            ->put(route('admin.device-stencils.update', $stencil), [
                'ports' => [
                    ['label' => 'A', 'side' => 'left', 'connector_type' => 'hdmi', 'signal_type' => 'video', 'direction' => 'in', 'sort_order' => 1, 'port_id' => 'dupe-1'],
                    ['label' => 'B', 'side' => 'left', 'connector_type' => 'hdmi', 'signal_type' => 'video', 'direction' => 'in', 'sort_order' => 2, 'port_id' => 'dupe-1'],
                ],
            ]);

        $response->assertSessionHasErrors(['ports.0.port_id', 'ports.1.port_id']);
        $this->assertSame(0, $stencil->ports()->count());
    }

    // ── Task 1: Test 3 — invalid side rejected; free-text connector_type accepted ──

    public function test_update_rejects_invalid_side_but_accepts_free_text_connector_type(): void
    {
        $stencil = $this->makeStencil();

        $invalidSide = $this->actingAs($this->admin)
            ->from(route('admin.device-stencils.edit', $stencil))
            ->put(route('admin.device-stencils.update', $stencil), [
                'ports' => [
                    ['label' => 'A', 'side' => 'diagonal', 'connector_type' => 'hdmi', 'signal_type' => 'video', 'direction' => 'in', 'sort_order' => 1, 'port_id' => 'a-1'],
                ],
            ]);

        $invalidSide->assertSessionHasErrors(['ports.0.side']);
        $this->assertSame(0, $stencil->ports()->count());

        // Engineer-extensible connector_type (21 D-02) — a value with no
        // vocabulary precedent is ACCEPTED, not rejected against an `in:` list.
        $freeText = $this->actingAs($this->admin)
            ->put(route('admin.device-stencils.update', $stencil), [
                'ports' => [
                    ['label' => 'A', 'side' => 'left', 'connector_type' => 'proprietary-mystery-connector', 'signal_type' => 'video', 'direction' => 'in', 'sort_order' => 1, 'port_id' => 'a-1'],
                ],
            ]);

        $freeText->assertSessionDoesntHaveErrors();
        $this->assertSame('proprietary-mystery-connector', $stencil->ports()->first()->connector_type);
    }

    // ── Task 1: Test 4 — device_ports.port_id <-> mxgraph_xml <constraint name> parity ──

    public function test_update_saved_ports_have_exact_constraint_parity_in_mxgraph_xml(): void
    {
        $stencil = $this->makeStencil();

        $this->actingAs($this->admin)
            ->put(route('admin.device-stencils.update', $stencil), [
                'ports' => $this->twoValidPorts(),
            ]);

        $stencil->refresh();

        $this->assertNotEmpty($stencil->ports);

        foreach ($stencil->ports as $port) {
            $this->assertMatchesRegularExpression(
                '/<constraint[^>]*name="'.preg_quote($port->port_id, '/').'"\/>/',
                $stencil->mxgraph_xml
            );
        }
    }

    // ── Task 1: Test 5 (D-17 guard) — engineer-curated, no confirm -> persists NOTHING ──

    public function test_update_against_engineer_curated_stencil_without_confirm_persists_nothing(): void
    {
        $originalXml = '<shape name="21cav.curated-original" h="140" w="220" aspect="variable" strokewidth="inherit"><background/><foreground/></shape>';

        $stencil = $this->makeStencil([
            'source'      => DeviceStencil::SOURCE_ENGINEER_CURATED,
            'mxgraph_xml' => $originalXml,
        ]);
        DevicePort::insert([
            'device_stencil_id' => $stencil->id,
            'label'             => 'Existing HDMI',
            'side'              => 'left',
            'connector_type'    => 'hdmi',
            'signal_type'       => 'video',
            'direction'         => 'in',
            'sort_order'        => 1,
            'port_id'           => 'existing-hdmi',
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->put(route('admin.device-stencils.update', $stencil), [
                'ports' => $this->twoValidPorts(),
                // confirm_regenerate deliberately omitted
            ]);

        $response->assertRedirect(route('admin.device-stencils.edit', $stencil));
        $response->assertSessionHas('warning');

        $stencil->refresh();

        $this->assertSame($originalXml, $stencil->mxgraph_xml);
        $this->assertSame(1, $stencil->ports()->count());
        $this->assertNotNull(DevicePort::where('device_stencil_id', $stencil->id)->where('port_id', 'existing-hdmi')->first());
        $this->assertSame(0, DeviceStencilAudit::where('device_stencil_id', $stencil->id)->count());
    }

    // ── Task 1: Test 6 (D-17 guard) — engineer-curated, confirmed -> persists + audits prior XML ──

    public function test_update_against_engineer_curated_stencil_with_confirm_persists_and_audits_prior_xml(): void
    {
        $originalXml = '<shape name="21cav.curated-original" h="140" w="220" aspect="variable" strokewidth="inherit"><background/><foreground/></shape>';

        $stencil = $this->makeStencil([
            'source'      => DeviceStencil::SOURCE_ENGINEER_CURATED,
            'mxgraph_xml' => $originalXml,
        ]);

        $response = $this->actingAs($this->admin)
            ->put(route('admin.device-stencils.update', $stencil), [
                'ports'              => $this->twoValidPorts(),
                'confirm_regenerate' => '1',
            ]);

        $response->assertRedirect(route('admin.device-stencils.edit', $stencil));
        $response->assertSessionHas('success');

        $stencil->refresh();

        $this->assertNotSame($originalXml, $stencil->mxgraph_xml);
        $this->assertSame(2, $stencil->ports()->count());

        $audit = DeviceStencilAudit::where('device_stencil_id', $stencil->id)->first();
        $this->assertNotNull($audit);
        $this->assertSame(DeviceStencilAudit::ACTION_EDIT, $audit->action);
        $this->assertSame($this->admin->id, $audit->user_id);
        $this->assertSame($originalXml, $audit->before_snapshot['mxgraph_xml']);
    }

    // ── Task 1: Test 7 — auto-generated path saves WITHOUT confirm_regenerate ──

    public function test_update_against_auto_generated_stencil_saves_without_confirm_regenerate(): void
    {
        $stencil = $this->makeStencil(['source' => DeviceStencil::SOURCE_AUTO_GENERATED]);

        $response = $this->actingAs($this->admin)
            ->put(route('admin.device-stencils.update', $stencil), [
                'ports' => $this->twoValidPorts(),
                // no confirm_regenerate — the ordinary stub-curation path
                // must stay a zero-friction single-click save.
            ]);

        $response->assertRedirect(route('admin.device-stencils.edit', $stencil));
        $response->assertSessionHas('success');
        $response->assertSessionMissing('warning');

        $stencil->refresh();
        $this->assertSame(2, $stencil->ports()->count());
    }

    // ── Task 2: edit screen renders port table + preview pane ──

    public function test_edit_screen_prepopulates_alpine_ports_from_existing_device_ports(): void
    {
        $stencil = $this->makeStencil();
        DevicePort::insert([
            'device_stencil_id' => $stencil->id,
            'label'             => 'Prepopulated Port',
            'side'              => 'left',
            'connector_type'    => 'hdmi',
            'signal_type'       => 'video',
            'direction'         => 'in',
            'sort_order'        => 1,
            'port_id'           => 'prepopulated-hdmi-1',
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.device-stencils.edit', $stencil));

        $response->assertOk();
        $response->assertSee('prepopulated-hdmi-1', false);
    }

    public function test_edit_screen_debounces_preview_at_600ms_not_200ms(): void
    {
        $stencil = $this->makeStencil();

        $response = $this->actingAs($this->admin)->get(route('admin.device-stencils.edit', $stencil));
        $response->assertOk();

        $content = $response->getContent();

        // Scope to the stencilPortEditor() script block only — the full
        // rendered page includes unrelated layout scripts (⌘K search,
        // lightbox, dropdown) that legitimately use other setTimeout
        // durations elsewhere on the page, so a whole-document regex would
        // false-positive/false-negative on those.
        $start = strpos($content, 'function stencilPortEditor');
        $this->assertNotFalse($start, 'stencilPortEditor() script block not found in rendered edit screen.');
        $end = strpos($content, '</script>', $start);
        $this->assertNotFalse($end);
        $editorScript = substr($content, $start, $end - $start);

        // Literal substring checks, not a paren-crossing regex — the debounce
        // callback is an arrow function ("() => {...}, 600);"), and the
        // empty "()" parameter list's own closing paren would break a
        // "setTimeout\([^)]*,\s*600\)" style pattern before it ever reaches
        // the delay argument.
        $this->assertStringContainsString('}, 600);', $editorScript);
        $this->assertStringNotContainsString('}, 200);', $editorScript);
    }

    public function test_edit_screen_delete_button_has_non_empty_aria_label_binding(): void
    {
        $stencil = $this->makeStencil();

        $response = $this->actingAs($this->admin)->get(route('admin.device-stencils.edit', $stencil));
        $response->assertOk();

        $this->assertStringContainsString(":aria-label=\"'Remove port", $response->getContent());
    }

    public function test_edit_screen_collapses_to_single_column_under_900px(): void
    {
        $source = file_get_contents(resource_path('views/admin/device-stencils/edit.blade.php'));
        $this->assertNotFalse($source);

        $this->assertMatchesRegularExpression('/@media[^{]*max-width:\s*900px/', $source);
    }
}
