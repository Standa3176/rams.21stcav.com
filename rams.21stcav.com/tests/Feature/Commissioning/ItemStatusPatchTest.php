<?php

namespace Tests\Feature\Commissioning;

use App\Models\CommissioningItem;
use App\Models\InstallProgramme;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * INST-05c + INST-05i audit fields + D-14 photo-on-fail guard.
 *
 * PATCH /commissioning-items/{item}/status  body: {status, note?}
 *
 * Red until Plan 03 ships the controller + route.
 */
class ItemStatusPatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_patch_status_pass_returns_counters(): void
    {
        [$user, $item] = $this->scaffoldItem();

        $response = $this->actingAs($user)
            ->patchJson("/commissioning-items/{$item->id}/status", [
                'status' => CommissioningItem::STATUS_PASS,
            ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'id',
            'status',
            'counters' => [
                'programme' => ['complete', 'total', 'unlocked'],
            ],
        ]);
    }

    public function test_patch_status_fail_requires_note(): void
    {
        [$user, $item] = $this->scaffoldItem([
            'evidence_photo_path' => 'commissioning-evidence/already.jpg',
        ]);

        $response = $this->actingAs($user)
            ->patchJson("/commissioning-items/{$item->id}/status", [
                'status' => CommissioningItem::STATUS_FAIL,
                // note missing
            ]);

        $response->assertStatus(422);
    }

    public function test_patch_status_fail_requires_photo(): void
    {
        [$user, $item] = $this->scaffoldItem([
            'evidence_photo_path' => null,
        ]);

        $response = $this->actingAs($user)
            ->patchJson("/commissioning-items/{$item->id}/status", [
                'status' => CommissioningItem::STATUS_FAIL,
                'note'   => 'Audio feedback loop on the ceiling mic',
            ]);

        $response->assertStatus(422);
    }

    public function test_patch_status_auto_fills_signed_off_by(): void
    {
        [$user, $item] = $this->scaffoldItem();

        $this->actingAs($user)
            ->patchJson("/commissioning-items/{$item->id}/status", [
                'status' => CommissioningItem::STATUS_PASS,
            ])
            ->assertOk();

        $fresh = $item->fresh();
        $this->assertSame($user->name, $fresh->signed_off_by);
        $this->assertNotNull($fresh->signed_off_at);
    }

    public function test_patch_status_invalid_enum_returns_422(): void
    {
        [$user, $item] = $this->scaffoldItem();

        $this->actingAs($user)
            ->patchJson("/commissioning-items/{$item->id}/status", [
                'status' => 'frobnicate',
            ])
            ->assertStatus(422);
    }

    public function test_patch_status_allows_pending_reset(): void
    {
        [$user, $item] = $this->scaffoldItem([
            'status'         => CommissioningItem::STATUS_PASS,
            'signed_off_by'  => 'Someone',
            'signed_off_at'  => now(),
        ]);

        $this->actingAs($user)
            ->patchJson("/commissioning-items/{$item->id}/status", [
                'status' => CommissioningItem::STATUS_PENDING,
            ])
            ->assertOk();

        $fresh = $item->fresh();
        $this->assertSame(CommissioningItem::STATUS_PENDING, $fresh->status);
        $this->assertNull($fresh->signed_off_by);
        $this->assertNull($fresh->signed_off_at);
    }

    /**
     * @return array{0: User, 1: CommissioningItem}
     */
    private function scaffoldItem(array $overrides = []): array
    {
        $user = User::factory()->create();
        $project = Project::factory()->create([
            'user_id' => $user->id,
            'status'  => Project::STATUS_INSTALLING,
        ]);
        $programme = InstallProgramme::factory()->create([
            'project_id' => $project->id,
            'status'     => InstallProgramme::STATUS_ACTIVE,
        ]);
        $item = CommissioningItem::factory()->create(array_merge([
            'install_programme_id' => $programme->id,
        ], $overrides));

        return [$user, $item];
    }
}
