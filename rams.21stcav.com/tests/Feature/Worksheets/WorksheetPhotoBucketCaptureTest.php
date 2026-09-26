<?php

namespace Tests\Feature\Worksheets;

use App\Models\Project;
use App\Models\User;
use App\Models\Worksheet;
use App\Models\WorksheetPhoto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 46.4 Plan 02 Task 1 — the server half of the two trays (D-03) and of
 * the photo label (D-04).
 *
 * TWO NEW UNTRUSTED INPUTS arrive on an endpoint whose only credential is a
 * URL token:
 *
 *   `bucket`  — T-46.4-02-01 (tampering). Constrained by `Rule::in` against
 *               WorksheetPhoto::BUCKETS, the model's OWN constant, so the
 *               vocabulary cannot drift between the model and the controller.
 *               Four rejection shapes are asserted, including the empty string
 *               and a wrong-case value, because "nullable" must mean ABSENT
 *               and not "any falsy thing".
 *
 *   `caption` — T-46.4-02-02 (stored XSS). Already validated `max:200`
 *               server-side before this plan; the front end simply never sent
 *               it. This file proves the round trip now that it does.
 *
 * ⚠️ AN ABSENT `bucket` MUST STILL WORK, and must land in `completion`. Two
 * real populations depend on it: clients running the pre-46.4 page, and photos
 * already sitting in a browser's IndexedDB queue from before this deploy whose
 * `fields` bag has no bucket in it. Those drain days later against the new
 * server. Rejecting them would silently destroy captured site evidence.
 *
 * @see app/Http/Controllers/PublicWorksheetController::uploadPhoto
 * @see .planning/phases/46.4-engineer-link-install-capture/46.4-CONTEXT.md (D-03, D-04)
 */
class WorksheetPhotoBucketCaptureTest extends TestCase
{
    use RefreshDatabase;

    private const ROOM = 'Boardroom';

    // ── Fixtures ─────────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function worksheet(): Worksheet
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        // Worksheet::create (not the factory's mass-assignment of a token) so
        // boot::creating mints `access_token` — it is DELIBERATELY off
        // $fillable and must never be mass-assigned.
        return Worksheet::create([
            'user_id'        => $user->id,
            'project_id'     => $project->id,
            'project_name'   => 'Bucket Capture Fixture',
            'project_ref'    => '21CQ00000-01-OPS',
            'client_name'    => 'Fixture Client',
            'site_address'   => '1 Fixture Way, Reading RG1 1AA',
            'status'         => Worksheet::STATUS_FINAL,
            'generated_data' => ['rooms' => [['name' => self::ROOM]]],
        ]);
    }

    /** @param array<string,mixed> $extra */
    private function upload(Worksheet $worksheet, array $extra = [], ?string $room = null)
    {
        return $this->post(
            route('public-worksheet.photos.upload', ['token' => $worksheet->access_token]),
            array_merge([
                'room_name' => $room ?? self::ROOM,
                'photo'     => UploadedFile::fake()->image('capture.jpg', 40, 40),
            ], $extra),
            ['Accept' => 'application/json'],
        );
    }

    // ── bucket: the happy paths ──────────────────────────────────────────────

    public function test_an_upload_carrying_bucket_start_is_stored_in_the_start_bucket(): void
    {
        $worksheet = $this->worksheet();

        $this->upload($worksheet, ['bucket' => WorksheetPhoto::BUCKET_START])->assertOk();

        $this->assertSame(
            WorksheetPhoto::BUCKET_START,
            $worksheet->photos()->sole()->bucket,
        );
    }

    public function test_an_upload_carrying_bucket_completion_is_stored_in_the_completion_bucket(): void
    {
        $worksheet = $this->worksheet();

        $this->upload($worksheet, ['bucket' => WorksheetPhoto::BUCKET_COMPLETION])->assertOk();

        $this->assertSame(
            WorksheetPhoto::BUCKET_COMPLETION,
            $worksheet->photos()->sole()->bucket,
        );
    }

    /**
     * The compatibility ruling. An old client — or a photo queued offline
     * BEFORE this deploy, whose `fields` bag has no bucket key — still posts
     * with no bucket at all, and it must still be accepted.
     */
    public function test_an_upload_with_no_bucket_key_at_all_still_succeeds_and_lands_in_completion(): void
    {
        $worksheet = $this->worksheet();

        $response = $this->upload($worksheet);

        $response->assertOk();
        $this->assertSame(
            WorksheetPhoto::BUCKET_COMPLETION,
            $worksheet->photos()->sole()->bucket,
        );
    }

    // ── bucket: the rejections (T-46.4-02-01) ────────────────────────────────

    /**
     * @return array<string,array{0:string}>
     */
    public static function forgedBucketProvider(): array
    {
        return [
            'a bucket that does not exist'   => ['kit'],
            'the empty string'               => [''],
            'the right word in the wrong case' => ['START'],
            'an injection attempt'           => ['<script>alert(1)</script>'],
        ];
    }

    /**
     * @dataProvider forgedBucketProvider
     */
    public function test_a_forged_bucket_is_rejected(string $forged): void
    {
        $worksheet = $this->worksheet();

        $this->upload($worksheet, ['bucket' => $forged])
            ->assertStatus(422);

        $this->assertSame(0, $worksheet->photos()->count());
    }

    /**
     * Non-vacuity for the rejection set: the vocabulary the controller
     * validates against is the model's own constant, so a new bucket added to
     * the model is automatically accepted and this file needs no edit.
     */
    public function test_every_bucket_the_model_declares_is_accepted_by_the_endpoint(): void
    {
        $this->assertNotEmpty(WorksheetPhoto::BUCKETS);

        foreach (WorksheetPhoto::BUCKETS as $bucket) {
            $worksheet = $this->worksheet();
            $this->upload($worksheet, ['bucket' => $bucket])->assertOk();
            $this->assertSame($bucket, $worksheet->photos()->sole()->bucket);
        }
    }

    // ── caption (D-04) ───────────────────────────────────────────────────────

    public function test_a_caption_round_trips_and_comes_back_in_the_json_response(): void
    {
        $worksheet = $this->worksheet();

        $response = $this->upload($worksheet, [
            'bucket'  => WorksheetPhoto::BUCKET_START,
            'caption' => 'Rack before works',
        ]);

        $response->assertOk();
        $response->assertJsonPath('caption', 'Rack before works');
        $response->assertJsonPath('bucket', WorksheetPhoto::BUCKET_START);

        $this->assertSame('Rack before works', $worksheet->photos()->sole()->caption);
    }

    public function test_a_caption_of_201_characters_is_rejected(): void
    {
        $worksheet = $this->worksheet();

        $this->upload($worksheet, ['caption' => str_repeat('a', 201)])
            ->assertStatus(422);

        $this->assertSame(0, $worksheet->photos()->count());

        // Non-vacuity: 200 is fine, so the rejection above is the boundary and
        // not a blanket refusal of long-ish strings.
        $this->upload($worksheet, ['caption' => str_repeat('a', 200)])->assertOk();
    }

    // ── sort_order is per room AND per bucket ────────────────────────────────

    /**
     * The two trays number INDEPENDENTLY. Scoped on room only, a start photo
     * captured after three completion photos would sort as #4 in a tray that
     * shows one item — the tray's own ordering would be a lie.
     */
    public function test_sort_order_is_computed_within_the_room_and_the_bucket(): void
    {
        $worksheet = $this->worksheet();

        $this->upload($worksheet, ['bucket' => WorksheetPhoto::BUCKET_COMPLETION])->assertOk();
        $this->upload($worksheet, ['bucket' => WorksheetPhoto::BUCKET_COMPLETION])->assertOk();
        $this->upload($worksheet, ['bucket' => WorksheetPhoto::BUCKET_START])->assertOk();
        $this->upload($worksheet, ['bucket' => WorksheetPhoto::BUCKET_START])->assertOk();

        $sortOrders = fn (string $bucket) => $worksheet->photos()
            ->where('bucket', $bucket)
            ->orderBy('id')
            ->pluck('sort_order')
            ->all();

        $this->assertSame([1, 2], $sortOrders(WorksheetPhoto::BUCKET_COMPLETION));
        $this->assertSame([1, 2], $sortOrders(WorksheetPhoto::BUCKET_START));
    }

    /**
     * …and it is still scoped per ROOM, which the pre-46.4 behaviour already
     * guaranteed. Asserted so the bucket scoping cannot be mistaken for a
     * replacement of the room scoping.
     */
    public function test_sort_order_is_still_scoped_per_room(): void
    {
        $worksheet = $this->worksheet();
        $worksheet->generated_data = ['rooms' => [['name' => self::ROOM], ['name' => 'Comms Room']]];
        $worksheet->save();

        $this->upload($worksheet, ['bucket' => WorksheetPhoto::BUCKET_START])->assertOk();
        $this->upload($worksheet, ['bucket' => WorksheetPhoto::BUCKET_START], 'Comms Room')->assertOk();

        $this->assertSame(
            1,
            (int) $worksheet->photos()->where('room_name', 'Comms Room')->sole()->sort_order,
        );
    }
}
