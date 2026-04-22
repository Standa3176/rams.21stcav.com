<?php

namespace Tests\Feature\Commissioning;

use App\Models\CommissioningItem;
use App\Models\CommissioningSignoff;
use App\Models\InstallProgramme;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Pitfall 7 — double-POST race. Unique index on
 * commissioning_signoffs.install_programme_id means the second attempt
 * must be rejected with 422 (CommissioningSignoffException::alreadySigned).
 *
 * Red until Plan 02 adds the unique index + Plan 04 catches the QueryException.
 */
class SignoffRaceTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_BASE64_PNG_TINY = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNgAAIAAAUAAen63NgAAAAASUVORK5CYII=';

    public function test_second_concurrent_signoff_returns_422(): void
    {
        Storage::fake('documents');

        [$user, $programme] = $this->scaffoldProgramme();

        CommissioningItem::factory()->create([
            'install_programme_id' => $programme->id,
            'status'               => CommissioningItem::STATUS_PASS,
        ]);

        // First signoff goes through (seeded directly — simulates another
        // request having just completed)
        CommissioningSignoff::factory()->create([
            'install_programme_id' => $programme->id,
        ]);

        $response = $this->actingAs($user)
            ->postJson("/install-programmes/{$programme->id}/commissioning/signoff/finalise", [
                'client_name'          => 'Second Client',
                'client_role'          => 'IT',
                'client_company'       => 'Acme',
                'signature_png_base64' => 'data:image/png;base64,' . self::VALID_BASE64_PNG_TINY,
            ]);

        $response->assertStatus(422);
    }

    public function test_unique_index_enforced_at_db_level(): void
    {
        [$programme] = $this->scaffoldProgrammeNoUser();

        CommissioningSignoff::factory()->create([
            'install_programme_id' => $programme->id,
        ]);

        $this->expectException(QueryException::class);

        CommissioningSignoff::factory()->create([
            'install_programme_id' => $programme->id,
        ]);
    }

    /**
     * @return array{0: User, 1: InstallProgramme}
     */
    private function scaffoldProgramme(): array
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

        return [$user, $programme];
    }

    /**
     * @return array{0: InstallProgramme}
     */
    private function scaffoldProgrammeNoUser(): array
    {
        [$_, $p] = $this->scaffoldProgramme();
        return [$p];
    }
}
