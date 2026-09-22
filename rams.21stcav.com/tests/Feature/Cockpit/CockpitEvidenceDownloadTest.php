<?php

namespace Tests\Feature\Cockpit;

use App\Models\DeviceLabelPhoto;
use App\Models\Project;
use App\Models\SiteSurvey;
use App\Models\SiteSurveyPhoto;
use App\Models\SiteSurveyRoom;
use App\Models\User;
use App\Models\Visit;
use App\Models\Worksheet;
use App\Models\WorksheetPhoto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use ZipArchive;

/**
 * Phase 46.1, Plan 46.1-02 — the photo ZIP and the inline photo route.
 *
 * THIS IS THE SHARPEST SECURITY EDGE IN THE PHASE, because it is the one and
 * only place where a stored path meets the filesystem. Four proofs carry it,
 * and each is written so it cannot pass by accident:
 *
 *  • TRAVERSAL (T-46.1-06) — a `worksheet_photos` row whose filename is
 *    `../../../../.env` is written directly, and a REAL decoy file with a
 *    canary string is placed at exactly the location that path resolves to.
 *    So `realpath()` succeeds and `is_file()` is true: the ONLY thing that can
 *    stop the read is the disk-root containment check. The canary must be
 *    absent from the archive and the response must still be 200.
 *
 *  • ZIP-SLIP (T-46.1-07) — a room named `../../../etc` and a photo whose
 *    original_name is `../../../../etc/passwd`. EVERY entry name in the
 *    produced archive is enumerated with a real `ZipArchive`; an assertion
 *    about the first entry would miss the one that escapes.
 *
 *  • INERTNESS — eleven row counts identical before and after, across three
 *    consecutive calls to each GET. `project_activity_logs` is in that list on
 *    purpose: the no-download-log ruling (T-46.1-09) is what keeps it there.
 *
 *  • NO ENUMERATION (T-46.1-08) — a foreign visit's photo id and a foreign
 *    project's photo id must 404 with IDENTICAL bodies.
 *
 * NON-VACUITY: a positive test asserts a real archive with at least three
 * entries across at least two folders, so the hostile-input tests above cannot
 * be passing merely because the ZIP was empty.
 */
class CockpitEvidenceDownloadTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The eleven write-surface tables. A GET may not move ANY of them.
     *
     * @var array<int, string>
     */
    private const WRITE_TABLES = [
        'visits',
        'install_records',
        'install_programmes',
        'site_surveys',
        'worksheets',
        'snags',
        'project_activity_logs',
        'site_survey_photos',
        'worksheet_photos',
        'worksheet_signoffs',
        'device_label_photos',
    ];

    /** The traversal decoy's contents. Must never reach an archive. */
    private const CANARY = 'APP_KEY=base64:TRAVERSAL-CANARY-46-1-02';

    /** The relative path the hostile row carries. */
    private const TRAVERSAL_PATH = '../../../../.env';

    private ?string $decoyCreatedAt = null;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cockpit.enabled' => true]);

        Storage::fake('local');

        // Plan 46.1-06: device label photos are written to the PUBLIC disk by
        // DeviceLabelPhotoService, and in Laravel 11+ that root is a SIBLING of
        // the local one. Faking only `local` is what let this file pass while
        // every equipment-label photo was being skipped on live.
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        if ($this->decoyCreatedAt !== null && is_file($this->decoyCreatedAt)) {
            @unlink($this->decoyCreatedAt);
        }

        $this->decoyCreatedAt = null;

        parent::tearDown();
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function project(array $overrides = []): Project
    {
        return Project::factory()->create(array_merge([
            'name'   => 'Evidence Download Job',
            'status' => Project::STATUS_INSTALLING,
        ], $overrides));
    }

    private function user(string $name = 'Priya Mistry'): User
    {
        return User::factory()->create(['name' => $name]);
    }

    /** Put a real file on the faked local disk and return its relative path. */
    private function storeFile(string $relative, string $contents = 'JPEGBYTES', string $disk = 'local'): string
    {
        Storage::disk($disk)->put($relative, $contents);

        return $relative;
    }

    /**
     * A survey-sourced visit with two rooms and three photos — the non-vacuity
     * floor: three entries across two folders.
     */
    private function surveyVisit(Project $project): Visit
    {
        $survey = SiteSurvey::create([
            'user_id'      => $this->user('Engineer Eve')->id,
            'project_id'   => $project->id,
            'project_name' => $project->name,
            'status'       => 'completed',
        ]);

        $boardroom = SiteSurveyRoom::create([
            'site_survey_id' => $survey->id,
            'room_name'      => 'Boardroom',
            'sort_order'     => 0,
        ]);

        $comms = SiteSurveyRoom::create([
            'site_survey_id' => $survey->id,
            'room_name'      => 'Comms room',
            'sort_order'     => 1,
        ]);

        SiteSurveyPhoto::create([
            'site_survey_room_id' => $boardroom->id,
            'filename'            => $this->storeFile('survey-photos/board-a.jpg'),
            'original_name'       => 'IMG_4410.jpg',
            'mime_type'           => 'image/jpeg',
            'sort_order'          => 0,
        ]);

        SiteSurveyPhoto::create([
            'site_survey_room_id' => $boardroom->id,
            // Same original name as the photo above: the per-folder sequence
            // is what stops them colliding inside the archive.
            'filename'            => $this->storeFile('survey-photos/board-b.jpg'),
            'original_name'       => 'IMG_4410.jpg',
            'mime_type'           => 'image/jpeg',
            'sort_order'          => 1,
        ]);

        SiteSurveyPhoto::create([
            'site_survey_room_id' => $comms->id,
            'filename'            => $this->storeFile('survey-photos/comms-a.jpg'),
            'original_name'       => 'IMG_4500.jpg',
            'mime_type'           => 'image/jpeg',
            'sort_order'          => 0,
        ]);

        return Visit::factory()
            ->backfilledFromSurvey($survey)
            ->create(['project_id' => $project->id]);
    }

    /** A worksheet-sourced visit: one worksheet photo (after) and one label. */
    private function worksheetVisit(Project $project): array
    {
        $worksheet = Worksheet::factory()->create(['project_id' => $project->id]);

        WorksheetPhoto::create([
            'worksheet_id'  => $worksheet->id,
            'room_name'     => 'Boardroom',
            'filename'      => $this->storeFile('worksheet-photos/install-01.jpg'),
            'original_name' => 'install-01.jpg',
            'mime_type'     => 'image/jpeg',
            'sort_order'    => 0,
        ]);

        DeviceLabelPhoto::create([
            'project_id'   => $project->id,
            'worksheet_id' => $worksheet->id,
            'room_name'    => 'Boardroom',
            // ON THE PUBLIC DISK, because that is where the live capture path
            // writes it (VisitEvidence::DISK_FOR_KIND). Planting it on `local`
            // would test a shape production never produces.
            'photo_path'   => $this->storeFile('device-labels/label-01.jpg', disk: 'public'),
            'confirmed'    => true,
            'captured_at'  => now()->subDay(),
            'captured_by'  => 'ip:203.0.113.9|actor:deadbeef',
        ]);

        $visit = Visit::factory()
            ->backfilledFromWorksheet($worksheet)
            ->create(['project_id' => $project->id]);

        return [$visit, $worksheet];
    }

    // ── Request helpers ───────────────────────────────────────────────────

    private function zipUrl(Project $project, Visit $visit): string
    {
        return route('projects.cockpit.visits.photos-zip', [
            'project' => $project->id,
            'visit'   => $visit->id,
        ]);
    }

    private function photoUrl(Project $project, Visit $visit, string $kind, string|int $photo): string
    {
        return '/projects/'.$project->id.'/cockpit/visits/'.$visit->id
            .'/photo/'.rawurlencode($kind).'/'.rawurlencode((string) $photo);
    }

    private function getZip(Project $project, Visit $visit, ?User $user = null): TestResponse
    {
        return $this->actingAs($user ?? $this->user())->get($this->zipUrl($project, $visit));
    }

    // ── Archive helpers ───────────────────────────────────────────────────

    /** Copy the streamed temp file somewhere stable and return its path. */
    private function archivePath(TestResponse $response): string
    {
        $file = $response->baseResponse->getFile()->getPathname();

        $copy = tempnam(sys_get_temp_dir(), 'zip-assert-').'.zip';
        copy($file, $copy);

        return $copy;
    }

    /**
     * EVERY entry name in the archive, read with a real ZipArchive — never the
     * builder's own return value.
     *
     * @return array<int, string>
     */
    private function entryNames(string $archive): array
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($archive) === true, 'The produced archive did not open.');

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }

        $zip->close();

        return $names;
    }

    /** Every entry's contents, keyed by entry name. */
    private function entryContents(string $archive): array
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($archive) === true);

        $out = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name       = (string) $zip->getNameIndex($i);
            $out[$name] = (string) $zip->getFromIndex($i);
        }

        $zip->close();

        return $out;
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $counts = [];

        foreach (self::WRITE_TABLES as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    // ── Non-vacuity: a real archive ───────────────────────────────────────

    public function test_the_zip_contains_real_entries_across_more_than_one_folder(): void
    {
        $project = $this->project();
        $visit   = $this->surveyVisit($project);

        $response = $this->getZip($project, $visit)->assertOk();

        $names = $this->entryNames($this->archivePath($response));

        $photoEntries = array_values(array_filter($names, fn (string $n): bool => $n !== 'README.txt'));

        $this->assertGreaterThanOrEqual(3, count($photoEntries), 'The non-vacuity floor is three real photo entries.');

        $folders = array_unique(array_map(
            fn (string $n): string => implode('/', array_slice(explode('/', $n), 0, 2)),
            $photoEntries,
        ));

        $this->assertGreaterThanOrEqual(2, count($folders), 'Entries must span at least two folders.');
        $this->assertContains('README.txt', $names);
    }

    public function test_a_survey_visit_groups_into_before_and_never_after_or_label(): void
    {
        $project = $this->project();
        $visit   = $this->surveyVisit($project);

        $names = $this->entryNames($this->archivePath($this->getZip($project, $visit)->assertOk()));

        $this->assertContains('Boardroom/before/001-IMG_4410.jpg', $names);
        $this->assertContains('Boardroom/before/002-IMG_4410.jpg', $names);
        $this->assertContains('Comms room/before/001-IMG_4500.jpg', $names);

        foreach ($names as $name) {
            $this->assertStringNotContainsString('/after/', $name);
            $this->assertStringNotContainsString('/label/', $name);
        }
    }

    public function test_a_worksheet_visit_groups_into_after_and_label_and_never_before(): void
    {
        $project        = $this->project();
        [$visit]        = $this->worksheetVisit($project);

        $names = $this->entryNames($this->archivePath($this->getZip($project, $visit)->assertOk()));

        $this->assertContains('Boardroom/after/001-install-01.jpg', $names);

        $labels = array_filter($names, fn (string $n): bool => str_contains($n, '/label/'));
        $this->assertCount(1, $labels, 'The device label photo must land in label/.');

        foreach ($names as $name) {
            $this->assertStringNotContainsString('/before/', $name);
        }
    }

    public function test_a_visit_with_no_photos_still_returns_a_valid_archive(): void
    {
        $project = $this->project();

        $survey = SiteSurvey::create([
            'user_id'      => $this->user('Engineer Eve')->id,
            'project_id'   => $project->id,
            'project_name' => $project->name,
        ]);

        $visit = Visit::factory()->backfilledFromSurvey($survey)->create(['project_id' => $project->id]);

        $names = $this->entryNames($this->archivePath($this->getZip($project, $visit)->assertOk()));

        $this->assertSame(['README.txt'], $names);
    }

    // ── T-46.1-07: zip-slip ───────────────────────────────────────────────

    public function test_a_hostile_room_name_and_filename_cannot_escape_the_zip_root(): void
    {
        $project     = $this->project();
        [$visit, $ws] = $this->worksheetVisit($project);

        // Written with DB::table so no model mutator can quietly normalise the
        // very values under test.
        DB::table('worksheet_photos')->insert([
            'worksheet_id'  => $ws->id,
            'room_name'     => '../../../etc',
            'filename'      => $this->storeFile('worksheet-photos/hostile.jpg'),
            'original_name' => '../../../../etc/passwd',
            'mime_type'     => 'image/jpeg',
            'sort_order'    => 9,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $names = $this->entryNames($this->archivePath($this->getZip($project, $visit)->assertOk()));

        $this->assertNotEmpty($names);

        // EVERY entry, not the first.
        foreach ($names as $name) {
            $this->assertFalse(str_contains($name, '..'), "Entry escaped with '..': {$name}");
            $this->assertFalse(str_starts_with($name, '/'), "Entry is absolute: {$name}");
            $this->assertFalse(str_starts_with($name, '\\'), "Entry is absolute: {$name}");
            $this->assertFalse(str_contains($name, '\\'), "Entry carries a backslash: {$name}");
            $this->assertFalse((bool) preg_match('#^[A-Za-z]:#', $name), "Entry is drive-qualified: {$name}");
        }

        $hostile = array_values(array_filter($names, fn (string $n): bool => str_contains($n, 'passwd')));
        $this->assertCount(1, $hostile, 'The hostile photo is still included — sanitised, not dropped.');
        $this->assertStringStartsWith('etc/after/', $hostile[0]);
    }

    public function test_a_room_name_that_sanitises_to_nothing_becomes_unnamed(): void
    {
        $project      = $this->project();
        [$visit, $ws] = $this->worksheetVisit($project);

        DB::table('worksheet_photos')->insert([
            'worksheet_id'  => $ws->id,
            'room_name'     => '..',
            'filename'      => $this->storeFile('worksheet-photos/dots.jpg'),
            'original_name' => '.hidden',
            'mime_type'     => 'image/jpeg',
            'sort_order'    => 8,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $names = $this->entryNames($this->archivePath($this->getZip($project, $visit)->assertOk()));

        $unnamed = array_values(array_filter($names, fn (string $n): bool => str_starts_with($n, 'Unnamed/')));

        $this->assertCount(1, $unnamed);
        $this->assertSame('Unnamed/after/001-hidden', $unnamed[0]);
    }

    // ── T-46.1-06: traversal ──────────────────────────────────────────────

    public function test_a_row_whose_path_escapes_the_disk_root_is_skipped_and_its_bytes_never_ship(): void
    {
        $project      = $this->project();
        [$visit, $ws] = $this->worksheetVisit($project);

        $decoy = $this->plantTraversalDecoy();

        // NON-VACUITY: the decoy really is on disk at the resolved location,
        // so realpath() succeeds and is_file() is true. Only the containment
        // check can stop the read.
        $this->assertFileExists($decoy);
        $this->assertStringContainsString(self::CANARY, (string) file_get_contents($decoy));

        DB::table('worksheet_photos')->insert([
            'worksheet_id'  => $ws->id,
            'room_name'     => 'Boardroom',
            'filename'      => self::TRAVERSAL_PATH,
            'original_name' => 'totally-normal.jpg',
            'mime_type'     => 'image/jpeg',
            'sort_order'    => 7,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $response = $this->getZip($project, $visit)->assertOk();
        $archive  = $this->archivePath($response);

        $names    = $this->entryNames($archive);
        $contents = $this->entryContents($archive);

        foreach ($names as $name) {
            $this->assertStringNotContainsString('totally-normal', $name);
            $this->assertStringNotContainsString('.env', $name);
        }

        foreach ($contents as $body) {
            $this->assertStringNotContainsString(self::CANARY, $body);
        }

        $this->assertStringNotContainsString(self::CANARY, (string) file_get_contents($archive));

        // The download still works: a skipped file is not a failed download.
        $this->assertContains('Boardroom/after/001-install-01.jpg', $names);
        $this->assertStringContainsString('Photos skipped', $contents['README.txt']);
    }

    public function test_the_inline_photo_route_refuses_an_escaping_path(): void
    {
        $project      = $this->project();
        [$visit, $ws] = $this->worksheetVisit($project);

        $this->plantTraversalDecoy();

        $id = DB::table('worksheet_photos')->insertGetId([
            'worksheet_id'  => $ws->id,
            'room_name'     => 'Boardroom',
            'filename'      => self::TRAVERSAL_PATH,
            'original_name' => 'totally-normal.jpg',
            'mime_type'     => 'image/jpeg',
            'sort_order'    => 6,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $this->actingAs($this->user())
            ->get($this->photoUrl($project, $visit, 'worksheet', $id))
            ->assertNotFound();
    }

    /**
     * Put a real file at exactly the location `../../../../.env` resolves to
     * off the FAKED local disk root. Never overwrites an existing file.
     */
    private function plantTraversalDecoy(): string
    {
        $target = Storage::disk('local')->path(self::TRAVERSAL_PATH);

        if (! is_file($target)) {
            file_put_contents($target, self::CANARY."\n");
            $this->decoyCreatedAt = $target;
        }

        return $target;
    }

    // ── Inertness: a GET writes nothing ───────────────────────────────────

    public function test_neither_get_writes_a_row_in_any_of_the_eleven_tables(): void
    {
        $project        = $this->project();
        [$visit, $ws]   = $this->worksheetVisit($project);
        $photoId        = (int) WorksheetPhoto::where('worksheet_id', $ws->id)->value('id');
        $user           = $this->user();

        $before = $this->counts();

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($user)->get($this->zipUrl($project, $visit))->assertOk();
        }

        $this->assertSame($before, $this->counts(), 'The ZIP GET moved a row.');

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($user)
                ->get($this->photoUrl($project, $visit, 'worksheet', $photoId))
                ->assertOk();
        }

        $this->assertSame($before, $this->counts(), 'The photo GET moved a row.');

        // T-46.1-09 stated as an assertion, not only as a docblock.
        $this->assertSame(0, DB::table('project_activity_logs')->count());
    }

    public function test_the_visit_row_itself_is_untouched_by_a_download(): void
    {
        $project = $this->project();
        $visit   = $this->surveyVisit($project);

        $before = DB::table('visits')->where('id', $visit->id)->first();

        $this->getZip($project, $visit)->assertOk();

        $this->assertEquals($before, DB::table('visits')->where('id', $visit->id)->first());
    }

    // ── Headers ───────────────────────────────────────────────────────────

    public function test_the_zip_is_an_attachment_with_a_slugged_filename(): void
    {
        $project = $this->project(['name' => 'Acme & Sons / Floor 3']);
        $visit   = $this->surveyVisit($project);

        $response = $this->getZip($project, $visit)->assertOk();

        $disposition = (string) $response->headers->get('Content-Disposition');

        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringNotContainsString('Acme & Sons', $disposition);
        $this->assertStringContainsString('.zip', $disposition);
    }

    public function test_the_inline_photo_carries_nosniff_and_a_sandbox_policy(): void
    {
        $project      = $this->project();
        [$visit, $ws] = $this->worksheetVisit($project);
        $photoId      = (int) WorksheetPhoto::where('worksheet_id', $ws->id)->value('id');

        $response = $this->actingAs($this->user())
            ->get($this->photoUrl($project, $visit, 'worksheet', $photoId))
            ->assertOk();

        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('sandbox', $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('image/jpeg', (string) $response->headers->get('Content-Type'));
    }

    // ── Scope and enumeration (T-46.1-08) ─────────────────────────────────

    public function test_the_zip_route_404s_for_a_visit_belonging_to_another_project(): void
    {
        $project = $this->project();
        $other   = $this->project(['name' => 'Someone Else Job']);

        $visit = $this->surveyVisit($other);

        $this->getZip($project, $visit)->assertNotFound();
    }

    public function test_a_photo_id_from_another_visit_and_from_another_project_are_the_same_404(): void
    {
        $project = $this->project();
        $other   = $this->project(['name' => 'Someone Else Job']);

        [$visit, $ws]      = $this->worksheetVisit($project);
        [, $siblingWs]     = $this->worksheetVisit($project);
        [, $foreignWs]     = $this->worksheetVisit($other);

        $siblingPhotoId = (int) WorksheetPhoto::where('worksheet_id', $siblingWs->id)->value('id');
        $foreignPhotoId = (int) WorksheetPhoto::where('worksheet_id', $foreignWs->id)->value('id');

        $this->assertNotSame($siblingPhotoId, $foreignPhotoId);

        $user = $this->user();

        $sibling = $this->actingAs($user)->get($this->photoUrl($project, $visit, 'worksheet', $siblingPhotoId));
        $foreign = $this->actingAs($user)->get($this->photoUrl($project, $visit, 'worksheet', $foreignPhotoId));
        $absent  = $this->actingAs($user)->get($this->photoUrl($project, $visit, 'worksheet', 999999));

        $sibling->assertNotFound();
        $foreign->assertNotFound();
        $absent->assertNotFound();

        // Indistinguishable: the bodies must be byte-identical, so the route
        // cannot be used to learn which ids exist.
        $this->assertSame($sibling->getContent(), $foreign->getContent());
        $this->assertSame($sibling->getContent(), $absent->getContent());
    }

    public function test_the_photo_id_is_never_used_as_a_global_lookup(): void
    {
        $project = $this->project();
        $visit   = $this->surveyVisit($project);

        // A SURVEY visit asked for a WORKSHEET photo id that really exists on
        // another visit of the same project: a find() would have served it.
        [, $ws]  = $this->worksheetVisit($project);
        $photoId = (int) WorksheetPhoto::where('worksheet_id', $ws->id)->value('id');

        $this->actingAs($this->user())
            ->get($this->photoUrl($project, $visit, 'worksheet', $photoId))
            ->assertNotFound();
    }

    /** @dataProvider hostileKinds */
    public function test_a_hostile_kind_is_a_404_and_is_never_reflected(string $kind): void
    {
        $project      = $this->project();
        [$visit, $ws] = $this->worksheetVisit($project);
        $photoId      = (int) WorksheetPhoto::where('worksheet_id', $ws->id)->value('id');

        $response = $this->actingAs($this->user())
            ->get($this->photoUrl($project, $visit, $kind, $photoId));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringNotContainsString($kind, (string) $response->getContent());
        $this->assertStringNotContainsString('alert(1)', (string) $response->getContent());
    }

    public static function hostileKinds(): array
    {
        return [
            'script'     => ['<script>alert(1)</script>'],
            'long'       => [str_repeat('a', 5000)],
            'encoded-..' => ['..%2f'],
            'dotdot'     => ['..'],
            'empty-ish'  => ['SURVEY'],
        ];
    }

    public function test_a_non_numeric_photo_id_is_a_404(): void
    {
        $project      = $this->project();
        [$visit]      = $this->worksheetVisit($project);

        $this->actingAs($this->user())
            ->get($this->photoUrl($project, $visit, 'worksheet', '1 OR 1=1'))
            ->assertNotFound();
    }

    // ── Auth and flag ─────────────────────────────────────────────────────

    public function test_an_anonymous_caller_reaches_neither_route(): void
    {
        $project      = $this->project();
        [$visit, $ws] = $this->worksheetVisit($project);
        $photoId      = (int) WorksheetPhoto::where('worksheet_id', $ws->id)->value('id');

        $zip   = $this->get($this->zipUrl($project, $visit));
        $photo = $this->get($this->photoUrl($project, $visit, 'worksheet', $photoId));

        $this->assertContains($zip->getStatusCode(), [302, 401, 403]);
        $this->assertContains($photo->getStatusCode(), [302, 401, 403]);
    }

    public function test_both_routes_404_with_the_flag_off_while_route_still_resolves(): void
    {
        $project      = $this->project();
        [$visit, $ws] = $this->worksheetVisit($project);
        $photoId      = (int) WorksheetPhoto::where('worksheet_id', $ws->id)->value('id');

        config(['cockpit.enabled' => false]);

        // route() must keep resolving — the routes are registered
        // unconditionally, exactly as projects.cockpit is.
        $this->assertIsString($this->zipUrl($project, $visit));

        $this->actingAs($this->user())->get($this->zipUrl($project, $visit))->assertNotFound();
        $this->actingAs($this->user())
            ->get($this->photoUrl($project, $visit, 'worksheet', $photoId))
            ->assertNotFound();
    }

    // ── The controller's shape ────────────────────────────────────────────

    public function test_the_evidence_controller_exposes_exactly_two_public_actions(): void
    {
        $methods = array_values(array_filter(
            get_class_methods(\App\Http\Controllers\ProjectCockpitEvidenceController::class),
            fn (string $m): bool => in_array($m, ['zip', 'photo', 'store', 'update', 'destroy', 'create', 'edit'], true),
        ));

        sort($methods);

        $this->assertSame(['photo', 'zip'], $methods);
    }

    public function test_no_write_verb_points_at_the_evidence_controller(): void
    {
        foreach (app('router')->getRoutes() as $route) {
            if (! str_contains((string) $route->getActionName(), 'ProjectCockpitEvidenceController')) {
                continue;
            }

            $this->assertSame(
                ['GET', 'HEAD'],
                array_values($route->methods()),
                'A non-GET verb points at the evidence controller: '.$route->uri(),
            );
        }
    }
}
