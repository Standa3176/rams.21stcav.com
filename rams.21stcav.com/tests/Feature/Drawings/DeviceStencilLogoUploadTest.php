<?php

namespace Tests\Feature\Drawings;

use App\Models\DeviceStencil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 24 Plan 06 (DRAW-52, D-12/D-15) — per-stencil manufacturer logo
 * upload. `logo_path` is a FILE reference (D-15); the legacy `logo_svg`
 * inline-text column is untouched by this action.
 *
 * The security test (test 2) is the DRAW-52 threat model made concrete
 * (T-24-13/T-24-14): an SVG containing a `<script>` tag and an `on*`
 * handler must never reach disk unsanitised.
 *
 * @see app/Http/Requests/Admin/UploadDeviceStencilLogoRequest.php
 * @see app/Http/Controllers/Admin/DeviceStencilController.php
 * @see app/Services/Drawings/SvgSanitizerService.php
 * @see .planning/phases/24-stencil-curation-ui-quote-import-auto-stub/24-06-PLAN.md
 * @see .planning/phases/24-stencil-curation-ui-quote-import-auto-stub/24-CONTEXT.md (D-12, D-15)
 */
class DeviceStencilLogoUploadTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);

        Storage::fake('public');
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

    // ── Test 1: valid PNG upload sets logo_path + file exists on disk ──

    public function test_uploading_a_valid_png_sets_logo_path_and_stores_the_file(): void
    {
        $stencil = $this->makeStencil();

        $response = $this->actingAs($this->admin)
            ->post(route('admin.device-stencils.upload-logo', $stencil), [
                'logo' => UploadedFile::fake()->image('logo.png', 64, 64),
            ]);

        $response->assertRedirect(route('admin.device-stencils.edit', $stencil));
        $response->assertSessionHas('success');

        $stencil->refresh();

        $this->assertSame("/storage/device-stencils/{$stencil->id}/logo.png", $stencil->logo_path);
        Storage::disk('public')->assertExists("device-stencils/{$stencil->id}/logo.png");
    }

    // ── Test 2: malicious SVG is sanitised BEFORE persist (D-12/T-24-13) ──

    public function test_uploading_svg_with_script_tag_strips_it_before_persist(): void
    {
        $stencil = $this->makeStencil();

        $maliciousSvg = <<<'SVG'
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 30">
            <script>alert(1)</script>
            <rect width="100" height="30" onload="alert(2)" />
        </svg>
        SVG;

        $response = $this->actingAs($this->admin)
            ->post(route('admin.device-stencils.upload-logo', $stencil), [
                'logo' => UploadedFile::fake()->createWithContent('logo.svg', $maliciousSvg),
            ]);

        $response->assertRedirect(route('admin.device-stencils.edit', $stencil));
        $response->assertSessionHas('success');

        $stencil->refresh();

        $this->assertSame("/storage/device-stencils/{$stencil->id}/logo.svg", $stencil->logo_path);

        $stored = Storage::disk('public')->get("device-stencils/{$stencil->id}/logo.svg");
        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringNotContainsString('onload', $stored);
    }

    // ── Test 3: unparseable SVG is rejected as a validation error, never persisted ──

    public function test_uploading_unparseable_svg_is_rejected_and_not_persisted(): void
    {
        $stencil = $this->makeStencil();

        $response = $this->actingAs($this->admin)
            ->from(route('admin.device-stencils.edit', $stencil))
            ->post(route('admin.device-stencils.upload-logo', $stencil), [
                'logo' => UploadedFile::fake()->createWithContent('logo.svg', 'not valid xml <<<'),
            ]);

        $response->assertSessionHasErrors(['logo']);

        $stencil->refresh();
        $this->assertNull($stencil->logo_path);
        Storage::disk('public')->assertMissing("device-stencils/{$stencil->id}/logo.svg");
    }

    // ── Test 4: oversized upload -> 422, never a 500 (T-24-15) ──

    public function test_uploading_a_file_larger_than_2mb_returns_a_validation_error(): void
    {
        $stencil = $this->makeStencil();

        $response = $this->actingAs($this->admin)
            ->from(route('admin.device-stencils.edit', $stencil))
            ->post(route('admin.device-stencils.upload-logo', $stencil), [
                'logo' => UploadedFile::fake()->create('logo.png', 3000), // 3000 KB > 2048 KB max
            ]);

        $response->assertSessionHasErrors(['logo']);

        $stencil->refresh();
        $this->assertNull($stencil->logo_path);
    }

    // ── Test 5: MIME-type spoof (declared type doesn't match extension) rejected (T-24-16) ──

    public function test_mime_type_spoofed_upload_is_rejected_by_mimes_rule(): void
    {
        $stencil = $this->makeStencil();

        $response = $this->actingAs($this->admin)
            ->from(route('admin.device-stencils.edit', $stencil))
            ->post(route('admin.device-stencils.upload-logo', $stencil), [
                'logo' => UploadedFile::fake()->create('malware.png', 10)->mimeType('application/x-msdownload'),
            ]);

        $response->assertSessionHasErrors(['logo']);

        $stencil->refresh();
        $this->assertNull($stencil->logo_path);
    }

    // ── Task 2 — Test 7: edit screen renders the logo widget without breaking the layout ──

    public function test_edit_screen_renders_logo_widget_without_breaking_layout(): void
    {
        $stencil = $this->makeStencil();

        $response = $this->actingAs($this->admin)->get(route('admin.device-stencils.edit', $stencil));

        $response->assertOk();
        $response->assertSee('Manufacturer Logo');
        $response->assertSee('Live Preview'); // Plan 24-05's preview card still renders untouched
        $response->assertSee(
            'PNG or SVG, up to 2MB. SVG uploads are automatically sanitised (scripts and embedded event handlers are stripped).',
        );
    }

    // ── Task 2 — Test 8: fallback-brand hint renders when logo_path is null and manufacturer resolves ──

    public function test_fallback_hint_renders_when_logo_path_null_and_manufacturer_resolves(): void
    {
        $stencil = $this->makeStencil(['manufacturer' => 'Netgear']);

        $response = $this->actingAs($this->admin)->get(route('admin.device-stencils.edit', $stencil));

        $response->assertOk();
        $response->assertSee('Using the built-in Netgear wordmark until a custom logo is uploaded.');
    }

    // ── Task 2 — Test 9: no fallback hint when manufacturer does not resolve to a known brand ──

    public function test_no_fallback_hint_when_manufacturer_unknown(): void
    {
        $stencil = $this->makeStencil(['manufacturer' => 'Totally Unknown Brand Ltd']);

        $response = $this->actingAs($this->admin)->get(route('admin.device-stencils.edit', $stencil));

        $response->assertOk();
        $response->assertDontSee('Using the built-in');
    }

    // ── Test 6: non-admin is blocked by the admin route-group middleware ──

    public function test_non_admin_is_blocked(): void
    {
        $stencil = $this->makeStencil();
        $engineer = User::factory()->create(['role' => 'engineer']);

        $response = $this->actingAs($engineer)
            ->post(route('admin.device-stencils.upload-logo', $stencil), [
                'logo' => UploadedFile::fake()->image('logo.png', 64, 64),
            ]);

        $response->assertForbidden();

        $stencil->refresh();
        $this->assertNull($stencil->logo_path);
    }
}
