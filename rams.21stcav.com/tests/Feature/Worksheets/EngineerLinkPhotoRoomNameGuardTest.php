<?php

namespace Tests\Feature\Worksheets;

use App\Models\Project;
use App\Models\User;
use App\Models\Worksheet;
use App\Models\WorksheetPhoto;
use App\Support\Worksheets\WorksheetCaptureLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 261010-foa — the missing room-name inclusion guard on
 * `PublicWorksheetController::uploadPhoto`.
 *
 * `uploadPhoto` validated `room_name` as a plain required string and never
 * checked it against the worksheet's own `generated_data['rooms']` list —
 * the one write endpoint on this unauthenticated, token-only controller that
 * never got the guard its siblings (`addAdditionalKit`, `markRoomComplete`,
 * `saveRoomNotes`) already carry. Fixture shapes copied verbatim from
 * `EngineerLinkAdditionalKitTest` (worksheet/sign) and
 * `WorksheetPhotoBucketCaptureTest` (the upload POST).
 *
 * @see app/Http/Controllers/PublicWorksheetController::uploadPhoto
 * @see app/Http/Controllers/PublicWorksheetController::assertRoomNameIsOnTheWorksheet
 */
class EngineerLinkPhotoRoomNameGuardTest extends TestCase
{
    use RefreshDatabase;

    private const ROOMS = ['Boardroom', 'Reception'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function worksheet(): Worksheet
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        return Worksheet::create([
            'user_id'        => $user->id,
            'project_id'     => $project->id,
            'project_name'   => 'Photo Room Guard Fixture',
            'project_ref'    => '21CQ00000-01-OPS',
            'client_name'    => 'Fixture Client',
            'site_address'   => '1 Fixture Way, Reading RG1 1AA',
            'status'         => Worksheet::STATUS_FINAL,
            'generated_data' => [
                'rooms' => array_map(fn (string $name) => ['name' => $name], self::ROOMS),
            ],
        ]);
    }

    /** A worksheet whose generated_data has no rooms at all — reachable live. */
    private function roomlessWorksheet(): Worksheet
    {
        $worksheet = $this->worksheet();
        $worksheet->generated_data = ['rooms' => []];
        $worksheet->save();

        return $worksheet;
    }

    private function sign(Worksheet $worksheet): void
    {
        $worksheet->signoffs()->create([
            'client_name'          => 'A Client',
            'signature_png_base64' => base64_encode('not-a-real-png'),
            'signed_with_comments' => false,
            'signed_at'            => now(),
        ]);
    }

    /** @param array<string,mixed> $extra */
    private function upload(Worksheet $worksheet, array $extra = [], ?string $room = null)
    {
        return $this->postJson(
            route('public-worksheet.photos.upload', ['token' => $worksheet->access_token]),
            array_merge([
                'room_name' => $room ?? self::ROOMS[0],
                'photo'     => UploadedFile::fake()->image('capture.jpg', 40, 40),
            ], $extra),
            ['Accept' => 'application/json'],
        );
    }

    // ── Test 1: non-vacuity — a valid room still succeeds ────────────────────

    public function test_a_room_name_that_is_on_the_worksheet_still_succeeds_and_files_the_photo(): void
    {
        $worksheet = $this->worksheet();

        $response = $this->upload($worksheet, [], self::ROOMS[0]);

        $response->assertOk();
        $this->assertSame(1, WorksheetPhoto::where('worksheet_id', $worksheet->id)->count());
        $this->assertSame(self::ROOMS[0], $worksheet->photos()->sole()->room_name);
    }

    // ── Test 2: the defect itself — a forged room is refused ─────────────────

    public function test_a_room_name_not_on_the_worksheet_is_refused_and_writes_nothing(): void
    {
        $worksheet = $this->worksheet();

        $response = $this->upload($worksheet, [], 'Server Room (Not On This Worksheet)');

        $response->assertStatus(422);
        $this->assertSame(0, WorksheetPhoto::where('worksheet_id', $worksheet->id)->count());
    }

    // ── Test 3: edge case — a roomless worksheet refuses every room, never 500 ──

    public function test_a_worksheet_with_no_rooms_is_refused_and_does_not_500(): void
    {
        $worksheet = $this->roomlessWorksheet();

        $response = $this->upload($worksheet, [], self::ROOMS[0]);

        $response->assertStatus(422);
        $this->assertSame(0, WorksheetPhoto::where('worksheet_id', $worksheet->id)->count());
    }

    // ── Test 4: ordering proof — lock still fires before the room guard ──────

    public function test_a_signed_worksheet_still_refuses_an_upload_with_a_valid_room_name(): void
    {
        $worksheet = $this->worksheet();
        $this->sign($worksheet);

        $response = $this->upload($worksheet, [], self::ROOMS[0]);

        $response->assertStatus(422);
        $response->assertJson(['message' => WorksheetCaptureLock::MESSAGE]);
        $this->assertSame(0, WorksheetPhoto::where('worksheet_id', $worksheet->id)->count());
    }
}
