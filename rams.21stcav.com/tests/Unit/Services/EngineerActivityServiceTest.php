<?php

namespace Tests\Unit\Services;

use App\Models\DeviceLabelPhoto;
use App\Models\Project;
use App\Models\User;
use App\Models\Worksheet;
use App\Models\WorksheetPhoto;
use App\Models\WorksheetSignoff;
use App\Services\EngineerActivityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Shape contract for EngineerActivityService::buildReportContext (260602-rcd).
 *
 * Locks the dictionary keys + types that BOTH the worksheets.show view and
 * the engineer-report.pdf Blade consume — a drift between them is the bug
 * this service exists to prevent.
 */
class EngineerActivityServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeWorksheet(array $rooms = [['name' => 'Boardroom']]): Worksheet
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        return Worksheet::factory()->create([
            'user_id'        => $user->id,
            'project_id'     => $project->id,
            'status'         => Worksheet::STATUS_DRAFT,
            'generated_data' => [
                'rooms'        => $rooms,
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function test_returns_canonical_top_level_keys(): void
    {
        $worksheet = $this->makeWorksheet();

        $context = app(EngineerActivityService::class)->buildReportContext($worksheet);

        $this->assertSame(
            ['rooms', 'outstanding_items', 'signoffs', 'summary'],
            array_keys($context),
        );
    }

    public function test_summary_keys_lock(): void
    {
        $worksheet = $this->makeWorksheet();

        $context = app(EngineerActivityService::class)->buildReportContext($worksheet);

        $this->assertSame(
            ['photo_count', 'label_count', 'signoff_count', 'has_activity'],
            array_keys($context['summary']),
        );
    }

    public function test_room_dictionary_keys_lock(): void
    {
        $worksheet = $this->makeWorksheet();

        $context = app(EngineerActivityService::class)->buildReportContext($worksheet);

        $this->assertCount(1, $context['rooms']);
        $this->assertSame(
            ['name', 'survey_reviewed_at', 'room_completed_at', 'completed_photos', 'label_photos'],
            array_keys($context['rooms'][0]),
        );
        $this->assertInstanceOf(Collection::class, $context['rooms'][0]['completed_photos']);
        $this->assertInstanceOf(Collection::class, $context['rooms'][0]['label_photos']);
    }

    public function test_photos_are_grouped_by_room_name_case_insensitive(): void
    {
        $worksheet = $this->makeWorksheet([['name' => 'Boardroom'], ['name' => 'Lobby']]);

        // Mixed-case room_name should still group with the Boardroom row.
        WorksheetPhoto::create([
            'worksheet_id'  => $worksheet->id,
            'room_name'     => '  BOARDROOM ',
            'filename'      => 'worksheet-photos/a.jpg',
            'original_name' => 'a.jpg',
            'mime_type'     => 'image/jpeg',
            'sort_order'    => 0,
        ]);
        WorksheetPhoto::create([
            'worksheet_id'  => $worksheet->id,
            'room_name'     => 'Lobby',
            'filename'      => 'worksheet-photos/b.jpg',
            'original_name' => 'b.jpg',
            'mime_type'     => 'image/jpeg',
            'sort_order'    => 0,
        ]);

        $context = app(EngineerActivityService::class)->buildReportContext($worksheet);

        $byName = collect($context['rooms'])->keyBy('name');
        $this->assertCount(1, $byName['Boardroom']['completed_photos']);
        $this->assertCount(1, $byName['Lobby']['completed_photos']);
        $this->assertSame(2, $context['summary']['photo_count']);
    }

    public function test_label_photos_are_grouped_by_room_name(): void
    {
        $worksheet = $this->makeWorksheet([['name' => 'Boardroom']]);

        DeviceLabelPhoto::create([
            'project_id'   => $worksheet->project_id,
            'worksheet_id' => $worksheet->id,
            'room_name'    => 'Boardroom',
            'photo_path'   => 'label-photos/x.jpg',
            'confirmed'    => true,
            'captured_at'  => now(),
        ]);

        $context = app(EngineerActivityService::class)->buildReportContext($worksheet);

        $this->assertCount(1, $context['rooms'][0]['label_photos']);
        $this->assertSame(1, $context['summary']['label_count']);
    }

    public function test_outstanding_items_flatten_signoff_comments_line_by_line(): void
    {
        $worksheet = $this->makeWorksheet();

        WorksheetSignoff::create([
            'worksheet_id'         => $worksheet->id,
            'client_name'          => 'Client A',
            'signature_png_base64' => 'iVBORw0KGgo=',
            'signed_with_comments' => true,
            'comments'             => "Missing cable management\nDisplay tilt loose\n",
            'signed_at'            => now()->subMinute(),
        ]);
        WorksheetSignoff::create([
            'worksheet_id'         => $worksheet->id,
            'client_name'          => 'Client B',
            'signature_png_base64' => 'iVBORw0KGgo=',
            'signed_with_comments' => true,
            'comments'             => 'Replace dead mic',
            'signed_at'            => now(),
        ]);
        // Clean-signoff should NOT add to outstanding items.
        WorksheetSignoff::create([
            'worksheet_id'         => $worksheet->id,
            'client_name'          => 'Client C',
            'signature_png_base64' => 'iVBORw0KGgo=',
            'signed_with_comments' => false,
            'comments'             => null,
            'signed_at'            => now()->subMinutes(2),
        ]);

        $context = app(EngineerActivityService::class)->buildReportContext($worksheet);

        $this->assertCount(3, $context['outstanding_items']);
        $this->assertContains('Missing cable management', $context['outstanding_items']);
        $this->assertContains('Display tilt loose', $context['outstanding_items']);
        $this->assertContains('Replace dead mic', $context['outstanding_items']);
        $this->assertSame(3, $context['summary']['signoff_count']);
    }

    public function test_has_activity_flag_mirrors_worksheet_accessor(): void
    {
        $worksheet = $this->makeWorksheet();

        // Fresh worksheet — no activity.
        $context = app(EngineerActivityService::class)->buildReportContext($worksheet);
        $this->assertFalse($context['summary']['has_activity']);

        // Add a sign-off.
        WorksheetSignoff::create([
            'worksheet_id'         => $worksheet->id,
            'client_name'          => 'Client',
            'signature_png_base64' => 'iVBORw0KGgo=',
            'signed_with_comments' => false,
            'signed_at'            => now(),
        ]);

        $context = app(EngineerActivityService::class)->buildReportContext($worksheet->fresh());
        $this->assertTrue($context['summary']['has_activity']);
    }
}
