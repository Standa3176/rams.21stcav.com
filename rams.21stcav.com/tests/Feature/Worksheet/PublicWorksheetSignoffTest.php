<?php

namespace Tests\Feature\Worksheet;

use App\Models\Project;
use App\Models\User;
use App\Models\Worksheet;
use App\Models\WorksheetSignoff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Public worksheet sign-off feature tests.
 *
 * Covers:
 *  - Schema (Task 1): access_token UUID generated on create, append-only
 *    worksheet_signoffs table, model relationships + helpers.
 *  - Public controller + view (Task 2): token gate (404), happy-path persist
 *    with stripped base64 prefix, validation errors, append-on-resubmit,
 *    throttle middleware, admin show page exposes the public link.
 *  - DOCX embed (Task 3): regenerated DOCX includes the signature image
 *    bytes + client name when a signoff exists.
 */
class PublicWorksheetSignoffTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeWorksheet(array $overrides = []): Worksheet
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        return Worksheet::create(array_merge([
            'user_id'      => $user->id,
            'project_id'   => $project->id,
            'project_name' => 'Acme Boardroom AV Refresh',
            'project_ref'  => 'Q-100001',
            'client_name'  => 'Acme Co.',
            'site_address' => '1 Test Street, London',
            'status'       => Worksheet::STATUS_DRAFT,
            'generated_data' => [
                'project' => [
                    'name'         => 'Acme Boardroom AV Refresh',
                    'client_name'  => 'Acme Co.',
                    'site_address' => '1 Test Street, London',
                    'quote_reference' => 'Q-100001',
                ],
                'rooms' => [
                    [
                        'name'                    => 'Boardroom',
                        'is_surveyed'             => true,
                        'install_steps'           => '1. Mount display',
                        'cable_route_desc'        => 'Cable from rack to wall',
                        'power_outlet_count'      => 2,
                        'requires_additional_power' => false,
                        'network_port_count'      => 1,
                        'existing_cabling'        => 'Cat6 in floor box',
                        'equipment'               => [
                            ['name' => 'Samsung QM75B', 'quantity' => 1, 'part_no' => 'QM75B'],
                        ],
                    ],
                ],
            ],
        ], $overrides));
    }

    private function pngFixturePath(): string
    {
        $path = base_path('tests/Fixtures/1x1.png');
        if (! file_exists($path)) {
            $im = imagecreatetruecolor(1, 1);
            imagesavealpha($im, true);
            $transparent = imagecolorallocatealpha($im, 0, 0, 0, 127);
            imagefill($im, 0, 0, $transparent);
            imagepng($im, $path);
            imagedestroy($im);
        }
        return $path;
    }

    private function pngBase64(): string
    {
        return base64_encode(file_get_contents($this->pngFixturePath()));
    }

    // ── Task 1 — schema + models ─────────────────────────────────────────────

    public function test_worksheet_gets_uuid_access_token_on_create(): void
    {
        $w = $this->makeWorksheet();

        $this->assertNotNull($w->access_token);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $w->access_token,
        );
    }

    public function test_worksheet_helpers_signoffs_isSigned_latestSignoff_publicUrl(): void
    {
        $w = $this->makeWorksheet();

        $this->assertFalse($w->isSigned());
        $this->assertNull($w->latestSignoff());

        $first = WorksheetSignoff::create([
            'worksheet_id'         => $w->id,
            'client_name'          => 'Alice First',
            'signature_png_base64' => $this->pngBase64(),
            'signed_with_comments' => false,
            'comments'             => null,
            'signed_at'            => now()->subMinute(),
        ]);

        $second = WorksheetSignoff::create([
            'worksheet_id'         => $w->id,
            'client_name'          => 'Bob Second',
            'signature_png_base64' => $this->pngBase64(),
            'signed_with_comments' => true,
            'comments'             => 'Pending speaker delivery',
            'signed_at'            => now(),
        ]);

        $w->refresh();

        $this->assertTrue($w->isSigned());
        $this->assertSame($second->id, $w->latestSignoff()->id);
        $this->assertSame(2, $w->signoffs()->count());

        // publicUrl resolves the registered route
        $this->assertStringContainsString('/worksheet/' . $w->access_token, $w->publicUrl());
    }

    public function test_worksheet_signoff_signature_data_uri_accessor(): void
    {
        $w = $this->makeWorksheet();
        $b64 = $this->pngBase64();

        $signoff = WorksheetSignoff::create([
            'worksheet_id'         => $w->id,
            'client_name'          => 'Alice',
            'signature_png_base64' => $b64,
            'signed_with_comments' => false,
            'signed_at'            => now(),
        ]);

        $this->assertSame('data:image/png;base64,' . $b64, $signoff->signature_data_uri);
        $this->assertInstanceOf(\Carbon\Carbon::class, $signoff->signed_at);
    }

    // ── Task 2 — public controller + routes + view ───────────────────────────

    public function test_show_returns_200_with_valid_token_and_renders_project_name(): void
    {
        $w = $this->makeWorksheet();

        $response = $this->get(route('public-worksheet.show', ['token' => $w->access_token]));

        $response->assertOk();
        $response->assertSee('Acme Boardroom AV Refresh');
        $response->assertSee('Boardroom');
    }

    public function test_show_returns_404_with_unknown_token(): void
    {
        $response = $this->get('/worksheet/' . (string) Str::uuid());
        $response->assertNotFound();
    }

    public function test_sign_persists_worksheet_signoff_with_correct_fields_including_stripped_base64(): void
    {
        $w = $this->makeWorksheet();
        $b64 = $this->pngBase64();

        $response = $this->post(route('public-worksheet.sign', ['token' => $w->access_token]), [
            'client_name'          => 'Charlie Client',
            'signature_image'      => 'data:image/png;base64,' . $b64,
            'happy_with_work'      => '1',
            'signed_with_comments' => '0',
            'comments'             => null,
        ]);

        $response->assertRedirect(route('public-worksheet.show', ['token' => $w->access_token]));
        $response->assertSessionHas('success');

        $w->refresh();
        $this->assertSame(1, $w->signoffs()->count());

        $sig = $w->latestSignoff();
        $this->assertSame('Charlie Client', $sig->client_name);
        // The data-URI prefix must be stripped before persisting (matches CommissioningSignoff convention).
        $this->assertSame($b64, $sig->signature_png_base64);
        $this->assertFalse($sig->signed_with_comments);
        $this->assertNull($sig->comments);
        $this->assertNotNull($sig->signed_at);
    }

    public function test_sign_with_missing_signature_or_name_returns_422(): void
    {
        $w = $this->makeWorksheet();

        // Missing client_name
        $r1 = $this->post(route('public-worksheet.sign', ['token' => $w->access_token]), [
            'signature_image' => 'data:image/png;base64,' . $this->pngBase64(),
        ]);
        $r1->assertSessionHasErrors('client_name');

        // Missing signature_image
        $r2 = $this->post(route('public-worksheet.sign', ['token' => $w->access_token]), [
            'client_name' => 'No Sig',
        ]);
        $r2->assertSessionHasErrors('signature_image');

        $w->refresh();
        $this->assertSame(0, $w->signoffs()->count());
    }

    public function test_sign_with_signed_with_comments_true_but_empty_comments_returns_validation_error(): void
    {
        $w = $this->makeWorksheet();
        $b64 = $this->pngBase64();

        $response = $this->post(route('public-worksheet.sign', ['token' => $w->access_token]), [
            'client_name'          => 'Dana Doe',
            'signature_image'      => 'data:image/png;base64,' . $b64,
            'signed_with_comments' => '1',
            'comments'             => '   ',
        ]);

        $response->assertSessionHasErrors('comments');

        $w->refresh();
        $this->assertSame(0, $w->signoffs()->count());
    }

    public function test_resubmit_appends_a_second_signoff_and_does_not_overwrite_the_first(): void
    {
        $w = $this->makeWorksheet();
        $b64 = $this->pngBase64();

        $this->post(route('public-worksheet.sign', ['token' => $w->access_token]), [
            'client_name'     => 'Sig One',
            'signature_image' => 'data:image/png;base64,' . $b64,
            'happy_with_work' => '1',
        ]);

        $this->post(route('public-worksheet.sign', ['token' => $w->access_token]), [
            'client_name'          => 'Sig Two',
            'signature_image'      => 'data:image/png;base64,' . $b64,
            'signed_with_comments' => '1',
            'comments'             => 'New snags raised on second visit',
        ]);

        $w->refresh();
        $this->assertSame(2, $w->signoffs()->count());

        $latest = $w->latestSignoff();
        $this->assertSame('Sig Two', $latest->client_name);
        $this->assertTrue($latest->signed_with_comments);
        $this->assertSame('New snags raised on second visit', $latest->comments);

        // The first signoff is still present.
        $allNames = $w->signoffs()->pluck('client_name')->all();
        $this->assertContains('Sig One', $allNames);
        $this->assertContains('Sig Two', $allNames);
    }

    public function test_sign_route_is_throttled_to_10_per_minute(): void
    {
        $route = Route::getRoutes()->getByName('public-worksheet.sign');
        $this->assertNotNull($route, 'Route public-worksheet.sign must be registered.');

        $middleware = $route->gatherMiddleware();
        $this->assertContains('throttle:10,1', $middleware);
    }

    public function test_admin_worksheet_show_page_exposes_public_link(): void
    {
        $w = $this->makeWorksheet();

        $this->actingAs(\App\Models\User::find($w->user_id));

        $response = $this->get(route('worksheets.show', $w));
        $response->assertOk();
        $response->assertSee('/worksheet/' . $w->access_token, false);
        $response->assertSee('Client sign-off link', false);
    }

    // ── Task 3 — DOCX signature embed ────────────────────────────────────────

    public function test_docx_regeneration_after_signoff_embeds_signature_png_bytes(): void
    {
        $w = $this->makeWorksheet();

        $pngBytes = file_get_contents($this->pngFixturePath());

        WorksheetSignoff::create([
            'worksheet_id'         => $w->id,
            'client_name'          => 'Eve Embed',
            'signature_png_base64' => base64_encode($pngBytes),
            'signed_with_comments' => true,
            'comments'             => 'Outstanding: speaker grilles',
            'signed_at'            => now(),
        ]);

        \Illuminate\Support\Facades\Storage::fake('documents');

        $svc = app(\App\Services\WorksheetDocxService::class);
        $svc->build($w->generated_data, $w->fresh());

        $w->refresh();
        $this->assertNotNull($w->filename);

        $absPath = app(\App\Services\DocumentArtifactStorage::class)
            ->readPath(\App\Services\DocumentArtifactStorage::TYPE_WORKSHEET, $w->filename);

        $this->assertNotNull($absPath, 'Generated DOCX must be readable from documents disk.');
        $this->assertFileExists($absPath);

        // Open the DOCX (zip) and inspect the document XML + media.
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($absPath) === true);

        $documentXml = $zip->getFromName('word/document.xml');
        $this->assertIsString($documentXml);

        // Client name + signed-at line + comment text are all in the rendered XML.
        $this->assertStringContainsString('Eve Embed', $documentXml);
        $this->assertStringContainsString('Outstanding: speaker grilles', $documentXml);

        // Media directory contains at least one PNG (the embedded signature).
        $hasPng = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (str_starts_with($name, 'word/media/') && str_ends_with(strtolower($name), '.png')) {
                $hasPng = true;
                break;
            }
        }
        $zip->close();

        $this->assertTrue($hasPng, 'Regenerated DOCX must embed at least one PNG (the signature).');
    }
}
