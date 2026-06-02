<?php

namespace Tests\Unit\Models;

use App\Models\DeviceLabelPhoto;
use App\Models\Project;
use App\Models\User;
use App\Models\Worksheet;
use App\Models\WorksheetPhoto;
use App\Models\WorksheetSignoff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Truth table for Worksheet::hasEngineerActivity (quick task 260602-rcd).
 *
 * Returns true when ANY of: completed-work photos, equipment label photos,
 * sign-offs, or any survey_review.reviewed_at tick. Returns false on a fresh
 * worksheet with none of those signals.
 */
class WorksheetHasEngineerActivityTest extends TestCase
{
    use RefreshDatabase;

    private function makeWorksheet(): Worksheet
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        return Worksheet::factory()->create([
            'user_id'        => $user->id,
            'project_id'     => $project->id,
            'status'         => Worksheet::STATUS_DRAFT,
            'generated_data' => [
                'rooms'        => [['name' => 'Boardroom', 'is_surveyed' => true]],
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function test_returns_false_when_no_engineer_activity_exists(): void
    {
        $worksheet = $this->makeWorksheet();

        $this->assertFalse($worksheet->hasEngineerActivity());
    }

    public function test_returns_true_when_completed_work_photo_exists(): void
    {
        $worksheet = $this->makeWorksheet();

        WorksheetPhoto::create([
            'worksheet_id'  => $worksheet->id,
            'room_name'     => 'Boardroom',
            'filename'      => 'worksheet-photos/' . uniqid() . '.jpg',
            'original_name' => 'install.jpg',
            'mime_type'     => 'image/jpeg',
            'caption'       => 'Installed display',
            'sort_order'    => 0,
        ]);

        $this->assertTrue($worksheet->fresh()->hasEngineerActivity());
    }

    public function test_returns_true_when_signoff_exists(): void
    {
        $worksheet = $this->makeWorksheet();

        WorksheetSignoff::create([
            'worksheet_id'         => $worksheet->id,
            'client_name'          => 'Test Client',
            'signature_png_base64' => 'iVBORw0KGgo=', // trivial fake
            'signed_with_comments' => false,
            'signed_at'            => now(),
        ]);

        $this->assertTrue($worksheet->fresh()->hasEngineerActivity());
    }

    public function test_returns_true_when_survey_reviewed_tick_exists(): void
    {
        $worksheet = $this->makeWorksheet();
        $worksheet->update([
            'pre_install_confirmations' => [
                'survey_review' => [
                    'Boardroom' => [
                        'reviewed_at' => now()->toIso8601String(),
                        'reviewed_by' => 'abc12345',
                    ],
                ],
            ],
        ]);

        $this->assertTrue($worksheet->fresh()->hasEngineerActivity());
    }

    public function test_returns_true_when_device_label_photo_exists(): void
    {
        $worksheet = $this->makeWorksheet();

        DeviceLabelPhoto::create([
            'project_id'   => $worksheet->project_id,
            'worksheet_id' => $worksheet->id,
            'room_name'    => 'Boardroom',
            'photo_path'   => 'label-photos/' . uniqid() . '.jpg',
            'confirmed'    => true,
            'captured_at'  => now(),
        ]);

        $this->assertTrue($worksheet->fresh()->hasEngineerActivity());
    }
}
