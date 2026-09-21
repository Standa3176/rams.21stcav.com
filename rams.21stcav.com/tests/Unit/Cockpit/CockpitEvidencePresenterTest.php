<?php

namespace Tests\Unit\Cockpit;

use App\Models\Device;
use App\Models\DeviceLabelPhoto;
use App\Models\Project;
use App\Models\SiteSurvey;
use App\Models\SiteSurveyPhoto;
use App\Models\SiteSurveyRoom;
use App\Models\SiteSurveyRoomQuestion;
use App\Models\User;
use App\Models\Visit;
use App\Models\Worksheet;
use App\Models\WorksheetPhoto;
use App\Models\WorksheetSignoff;
use App\Support\Cockpit\CockpitEvidencePresenter;
use App\Support\Cockpit\VisitEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 46.1, Plan 46.1-01 — what the engineer sent back.
 *
 * Three proofs carry the plan, and each is written so it cannot pass by
 * accident:
 *
 *  • READ LIVE (RV-02) — edit the engineer's own record between two calls and
 *    the second call shows the edit. A cached or copied value fails this.
 *
 *  • WRITES NOTHING — nine row counts identical across three consecutive
 *    calls, modelled on
 *    `CockpitPanelPresenterTest::test_calling_the_three_methods_writes_nothing()`.
 *
 *  • RV-03 — the capture-audit columns are absent from the SERIALISED output,
 *    asserted with REALISTIC values actually stored on the fixture rows. A
 *    test that passed because the fixture left those columns null would prove
 *    nothing at all.
 */
class CockpitEvidencePresenterTest extends TestCase
{
    use RefreshDatabase;

    /** Every table this plan's code reads. None of them may grow or shrink. */
    private const READ_TABLES = [
        'visits',
        'site_surveys',
        'site_survey_rooms',
        'site_survey_photos',
        'worksheets',
        'worksheet_photos',
        'worksheet_signoffs',
        'device_label_photos',
        'project_activity_logs',
    ];

    /**
     * A realistic value for the audit column that must never be returned:
     * this is the shape new captures actually write.
     */
    private const AUDIT_CAPTURE_VALUE = 'ip:203.0.113.9|actor:deadbeef';

    private const SIGNOFF_ADDRESS = '198.51.100.77';

    private const SIGNOFF_AGENT = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X)';

    private function presenter(): CockpitEvidencePresenter
    {
        return new CockpitEvidencePresenter();
    }

    private function project(array $overrides = []): Project
    {
        return Project::factory()->create(array_merge([
            'name'   => 'Returned Tab Job',
            'status' => Project::STATUS_INSTALLING,
        ], $overrides));
    }

    private function survey(Project $project, array $overrides = []): SiteSurvey
    {
        return SiteSurvey::create(array_merge([
            'user_id'      => User::factory()->create()->id,
            'project_id'   => $project->id,
            'project_name' => $project->name,
        ], $overrides));
    }

    private function room(SiteSurvey $survey, array $overrides = []): SiteSurveyRoom
    {
        return SiteSurveyRoom::create(array_merge([
            'site_survey_id' => $survey->id,
            'room_name'      => 'Boardroom',
            'sort_order'     => 0,
        ], $overrides));
    }

    private function surveyPhoto(SiteSurveyRoom $room, array $overrides = []): SiteSurveyPhoto
    {
        return SiteSurveyPhoto::create(array_merge([
            'site_survey_room_id' => $room->id,
            'filename'            => 'projects/9/surveys/3/' . fake()->uuid() . '.jpg',
            'original_name'       => 'IMG_4410.jpg',
            'mime_type'           => 'image/jpeg',
            'sort_order'          => 0,
        ], $overrides));
    }

    private function question(SiteSurveyRoom $room, array $overrides = []): SiteSurveyRoomQuestion
    {
        return SiteSurveyRoomQuestion::create(array_merge([
            'site_survey_room_id' => $room->id,
            'question'            => 'Is the comms room reachable without a ladder?',
            'sort_order'          => 0,
            'answer'              => null,
        ], $overrides));
    }

    private function worksheet(Project $project, array $overrides = []): Worksheet
    {
        return Worksheet::factory()->create(array_merge([
            'project_id' => $project->id,
        ], $overrides));
    }

    private function worksheetPhoto(Worksheet $worksheet, array $overrides = []): WorksheetPhoto
    {
        return WorksheetPhoto::create(array_merge([
            'worksheet_id'  => $worksheet->id,
            'room_name'     => 'Boardroom',
            'filename'      => 'worksheet-photos/' . fake()->uuid() . '.jpg',
            'original_name' => 'install-01.jpg',
            'mime_type'     => 'image/jpeg',
            'sort_order'    => 0,
        ], $overrides));
    }

    private function labelPhoto(Project $project, Worksheet $worksheet, array $overrides = []): DeviceLabelPhoto
    {
        return DeviceLabelPhoto::create(array_merge([
            'project_id'   => $project->id,
            'worksheet_id' => $worksheet->id,
            'room_name'    => 'Boardroom',
            'photo_path'   => 'device-labels/' . fake()->uuid() . '.jpg',
            'confirmed'    => true,
            'captured_at'  => now()->subDay(),
            // The audit column, populated with a realistic value so the
            // RV-03 assertion below is non-vacuous.
            'captured_by'  => self::AUDIT_CAPTURE_VALUE,
        ], $overrides));
    }

    private function signoff(Worksheet $worksheet, array $overrides = []): WorksheetSignoff
    {
        return WorksheetSignoff::create(array_merge([
            'worksheet_id'         => $worksheet->id,
            'client_name'          => 'Priya Raman',
            'signature_png_base64' => 'iVBORw0KGgo=',
            'signed_with_comments' => false,
            'signed_at'            => now()->subHour(),
            'ip_address'           => self::SIGNOFF_ADDRESS,
            'user_agent'           => self::SIGNOFF_AGENT,
        ], $overrides));
    }

    /** A worksheet-sourced visit with photos, a serial and a real sign-off. */
    private function fullWorksheetVisit(Project $project): array
    {
        $worksheet = $this->worksheet($project);

        $visit = Visit::factory()
            ->backfilledFromWorksheet($worksheet)
            ->create(['project_id' => $project->id]);

        $this->worksheetPhoto($worksheet);

        $device = Device::create([
            'project_id'    => $project->id,
            'room_name'     => 'Boardroom',
            'description'   => 'Ceiling microphone array',
            'serial_number' => 'SN-1122-AA',
        ]);

        $this->labelPhoto($project, $worksheet, ['device_id' => $device->id]);
        $this->signoff($worksheet);

        return [$visit, $worksheet];
    }

    // ── Scope ─────────────────────────────────────────────────────────────

    public function test_a_visit_belonging_to_another_project_returns_null(): void
    {
        $project = $this->project();
        $other   = $this->project(['name' => 'Someone Else Job']);

        [$visit] = $this->fullWorksheetVisit($other);

        $this->assertNull(
            $this->presenter()->evidence($project, $visit),
            'A visit from another project must resolve to nothing — the route-bound project is the only trusted scope (T-46.1-03).'
        );
    }

    public function test_a_visit_with_no_source_record_returns_null(): void
    {
        $project = $this->project();
        $visit   = Visit::factory()->create(['project_id' => $project->id]);

        $this->assertNull($this->presenter()->evidence($project, $visit));
    }

    public function test_a_force_deleted_source_still_resolves_and_reports_itself_missing(): void
    {
        $project   = $this->project();
        $worksheet = $this->worksheet($project);

        $visit = Visit::factory()
            ->backfilledFromWorksheet($worksheet)
            ->create(['project_id' => $project->id]);

        $worksheet->forceDelete();

        $evidence = $this->presenter()->evidence($project, $visit->fresh());

        $this->assertNotNull($evidence, 'The visit still happened — it must render even with no record left behind it.');
        $this->assertTrue($evidence['source_missing']);
        $this->assertFalse($evidence['has_anything']);
        $this->assertSame(0, $evidence['photo_count']);
    }

    // ── Survey-sourced ────────────────────────────────────────────────────

    public function test_a_survey_sourced_visit_returns_its_photos_in_the_before_bucket(): void
    {
        $project = $this->project();
        $survey  = $this->survey($project);
        $room    = $this->room($survey);

        $this->surveyPhoto($room, ['caption' => 'Rear wall']);
        $this->surveyPhoto($room, ['caption' => null, 'category' => 'ceiling']);

        $visit = Visit::factory()
            ->backfilledFromSurvey($survey)
            ->create(['project_id' => $project->id]);

        $evidence = $this->presenter()->evidence($project, $visit);

        $this->assertSame(2, $evidence['photo_count']);
        $this->assertSame(['before'], array_keys($evidence['photos_by_bucket']));

        $first = $evidence['photos_by_bucket']['before'][0];

        $this->assertSame(
            ['kind', 'id', 'room', 'bucket', 'caption', 'original_name', 'mime_type', 'path'],
            array_keys($first),
            'The photo array keys are the contract Plans 46.1-02 and 46.1-03 build against.'
        );
        $this->assertSame(VisitEvidence::KIND_SURVEY, $first['kind']);
        $this->assertSame('Boardroom', $first['room']);
        $this->assertSame('Rear wall', $first['caption']);

        $captions = array_column($evidence['photos_by_bucket']['before'], 'caption');
        $this->assertContains('ceiling', $captions, 'category is the caption fallback when caption is null.');
    }

    public function test_unanswered_questions_are_omitted_but_still_counted(): void
    {
        $project = $this->project();
        $survey  = $this->survey($project);
        $room    = $this->room($survey, ['notes' => 'Ceiling tiles are fragile.']);

        $this->question($room, ['question' => 'Power available?', 'answer' => 'yes', 'sort_order' => 0]);
        $this->question($room, ['question' => 'Anything else?', 'answer' => 'other', 'other_text' => 'Fire door blocks the route', 'sort_order' => 1]);
        $this->question($room, ['question' => 'Never answered', 'answer' => null, 'sort_order' => 2]);

        $visit = Visit::factory()
            ->backfilledFromSurvey($survey)
            ->create(['project_id' => $project->id]);

        $evidence = $this->presenter()->evidence($project, $visit);

        $this->assertCount(1, $evidence['rooms']);

        $returnedRoom = $evidence['rooms'][0];

        $this->assertSame('Boardroom', $returnedRoom['name']);
        $this->assertSame('Ceiling tiles are fragile.', $returnedRoom['notes']);
        $this->assertSame(2, $returnedRoom['answered']);
        $this->assertSame(3, $returnedRoom['total']);
        $this->assertCount(2, $returnedRoom['answers'], 'An unanswered question is noise on a review page — the two integers carry it instead.');
        $this->assertSame('Fire door blocks the route', $returnedRoom['answers'][1]['other_text']);
        $this->assertArrayNotHasKey('other_text', $returnedRoom['answers'][0]);
    }

    public function test_a_room_that_returned_nothing_is_omitted(): void
    {
        $project = $this->project();
        $survey  = $this->survey($project);

        $this->room($survey, ['room_name' => 'Silent Room', 'sort_order' => 0]);

        $spoken = $this->room($survey, ['room_name' => 'Talkative Room', 'sort_order' => 1]);
        $this->surveyPhoto($spoken);

        $visit = Visit::factory()
            ->backfilledFromSurvey($survey)
            ->create(['project_id' => $project->id]);

        $evidence = $this->presenter()->evidence($project, $visit);

        $this->assertSame(['Talkative Room'], array_column($evidence['rooms'], 'name'));
    }

    // ── Worksheet-sourced ─────────────────────────────────────────────────

    public function test_a_worksheet_sourced_visit_returns_photos_serials_and_the_signoff(): void
    {
        $project = $this->project();

        [$visit] = $this->fullWorksheetVisit($project);

        $evidence = $this->presenter()->evidence($project, $visit);

        $this->assertTrue($evidence['has_anything']);
        $this->assertSame(2, $evidence['photo_count']);
        $this->assertSame(['after', 'label'], array_keys($evidence['photos_by_bucket']), 'A worksheet visit yields after/ and label/ and never before/.');

        $this->assertCount(1, $evidence['serials']);

        $serial = $evidence['serials'][0];

        $this->assertSame(
            ['id', 'room', 'device', 'serial', 'confirmed', 'captured_at'],
            array_keys($serial),
            'The serial row carries no audit column — RV-03.'
        );
        $this->assertSame('Ceiling microphone array', $serial['device']);
        $this->assertSame('SN-1122-AA', $serial['serial']);
        $this->assertTrue($serial['confirmed']);

        $this->assertSame(
            ['client_name', 'signed_at', 'signed_with_comments', 'comments', 'signature_data_uri'],
            array_keys($evidence['signoff']),
            'The sign-off surface is the client NAME and SIGNATURE only.'
        );
        $this->assertSame('Priya Raman', $evidence['signoff']['client_name']);
        $this->assertStringStartsWith('data:image/png;base64,', $evidence['signoff']['signature_data_uri']);
    }

    public function test_a_serial_falls_back_to_the_ai_extraction_when_no_device_row_holds_one(): void
    {
        $project   = $this->project();
        $worksheet = $this->worksheet($project);

        $visit = Visit::factory()
            ->backfilledFromWorksheet($worksheet)
            ->create(['project_id' => $project->id]);

        $this->labelPhoto($project, $worksheet, [
            'device_id'    => null,
            'ai_extracted' => ['serial' => 'AI-9087', 'confidence' => 0.91],
            'confirmed'    => false,
        ]);

        $evidence = $this->presenter()->evidence($project, $visit);

        $this->assertSame('AI-9087', $evidence['serials'][0]['serial']);
        $this->assertSame('', $evidence['serials'][0]['device'], 'No device row means an empty string, never a raw model dump.');
    }

    public function test_an_issued_but_untouched_source_has_nothing(): void
    {
        $project   = $this->project();
        $worksheet = $this->worksheet($project);

        $visit = Visit::factory()
            ->backfilledFromWorksheet($worksheet)
            ->create(['project_id' => $project->id]);

        $evidence = $this->presenter()->evidence($project, $visit);

        $this->assertNotNull($evidence);
        $this->assertFalse($evidence['source_missing'], 'The worksheet exists — it is simply untouched.');
        $this->assertFalse($evidence['has_anything']);
        $this->assertSame([], $evidence['photos_by_bucket']);
        $this->assertSame([], $evidence['serials']);
        $this->assertNull($evidence['signoff']);
    }

    // ── T-46.1-02: cross-project label rows ──────────────────────────────

    public function test_a_label_photo_from_another_project_never_reaches_the_serials(): void
    {
        $project = $this->project();
        $other   = $this->project(['name' => 'Neighbouring Job']);

        $worksheet = $this->worksheet($project);

        $visit = Visit::factory()
            ->backfilledFromWorksheet($worksheet)
            ->create(['project_id' => $project->id]);

        $mine = $this->labelPhoto($project, $worksheet, ['room_name' => 'Boardroom']);

        // The row that proves the project_id clause is load-bearing: it
        // carries THIS visit's worksheet_id but ANOTHER project's project_id.
        $foreign = $this->labelPhoto($other, $worksheet, ['room_name' => 'Boardroom']);

        $evidence = $this->presenter()->evidence($project, $visit);

        $ids = array_column($evidence['serials'], 'id');

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($foreign->id, $ids, 'worksheet_id alone is not a scope — project_id must filter too (T-46.1-02).');

        $labelPhotoIds = array_column($evidence['photos_by_bucket']['label'], 'id');
        $this->assertNotContains($foreign->id, $labelPhotoIds);
    }

    // ── RV-02: read live ─────────────────────────────────────────────────

    public function test_it_reads_live_so_a_later_worksheet_capture_shows_on_the_next_call(): void
    {
        $project   = $this->project();
        $worksheet = $this->worksheet($project);

        $visit = Visit::factory()
            ->backfilledFromWorksheet($worksheet)
            ->create(['project_id' => $project->id]);

        $presenter = $this->presenter();

        $before = $presenter->evidence($project, $visit);

        $this->assertSame(0, $before['photo_count']);
        $this->assertNull($before['signoff']);

        $this->worksheetPhoto($worksheet);
        $this->signoff($worksheet, ['client_name' => 'Later Signer']);

        $after = $presenter->evidence($project, $visit);

        $this->assertSame(1, $after['photo_count'], 'A copied or memoised value would still read 0 here — D-04.');
        $this->assertSame('Later Signer', $after['signoff']['client_name']);
        $this->assertNotNull($after['returned_at']);
    }

    public function test_it_reads_live_so_an_edited_room_note_shows_on_the_next_call(): void
    {
        $project = $this->project();
        $survey  = $this->survey($project);
        $room    = $this->room($survey, ['notes' => 'First pass.']);

        $visit = Visit::factory()
            ->backfilledFromSurvey($survey)
            ->create(['project_id' => $project->id]);

        $presenter = $this->presenter();

        $this->assertSame('First pass.', $presenter->evidence($project, $visit)['rooms'][0]['notes']);

        $room->forceFill(['notes' => 'Corrected after a second look.'])->save();

        $this->assertSame(
            'Corrected after a second look.',
            $presenter->evidence($project, $visit)['rooms'][0]['notes'],
            'The engineer record is the only source of truth — a copy would now contradict it.'
        );
    }

    // ── Writes nothing ───────────────────────────────────────────────────

    public function test_calling_evidence_writes_nothing_to_any_read_table(): void
    {
        $project = $this->project();

        [$worksheetVisit] = $this->fullWorksheetVisit($project);

        $survey = $this->survey($project);
        $room   = $this->room($survey, ['notes' => 'Ceiling tiles are fragile.']);
        $this->surveyPhoto($room);
        $this->question($room, ['answer' => 'yes']);

        $surveyVisit = Visit::factory()
            ->backfilledFromSurvey($survey)
            ->create(['project_id' => $project->id]);

        $before = [];
        foreach (self::READ_TABLES as $table) {
            $before[$table] = DB::table($table)->count();
        }

        $presenter = $this->presenter();

        for ($i = 0; $i < 3; $i++) {
            $presenter->evidence($project, $worksheetVisit);
            $presenter->evidence($project, $surveyVisit);
        }

        foreach (self::READ_TABLES as $table) {
            $this->assertSame($before[$table], DB::table($table)->count(), "Reading the evidence changed `{$table}`.");
        }
    }

    // ── RV-03: the capture-audit columns never leave the database ────────

    public function test_the_serialised_output_carries_no_capture_audit_fields(): void
    {
        $project = $this->project();

        [$visit, $worksheet] = $this->fullWorksheetVisit($project);

        // Prove the fixture actually holds the values — otherwise the
        // assertions below would pass because there was nothing to leak.
        $this->assertSame(
            self::AUDIT_CAPTURE_VALUE,
            DB::table('device_label_photos')->where('worksheet_id', $worksheet->id)->value('captured_by'),
            'The fixture must really hold the audit value, or this test proves nothing.'
        );
        $this->assertSame(
            self::SIGNOFF_ADDRESS,
            DB::table('worksheet_signoffs')->where('worksheet_id', $worksheet->id)->value('ip_address')
        );
        $this->assertSame(
            self::SIGNOFF_AGENT,
            DB::table('worksheet_signoffs')->where('worksheet_id', $worksheet->id)->value('user_agent')
        );

        $serialised = json_encode($this->presenter()->evidence($project, $visit));

        $this->assertIsString($serialised);
        $this->assertStringNotContainsString('captured_by', $serialised);
        $this->assertStringNotContainsString(self::AUDIT_CAPTURE_VALUE, $serialised);
        $this->assertStringNotContainsString('ip:', $serialised);
        $this->assertStringNotContainsString('actor:', $serialised);
        $this->assertStringNotContainsString('ip_address', $serialised);
        $this->assertStringNotContainsString(self::SIGNOFF_ADDRESS, $serialised);
        $this->assertStringNotContainsString('user_agent', $serialised);
        $this->assertStringNotContainsString(self::SIGNOFF_AGENT, $serialised);
    }

    // ── Membership + paths ───────────────────────────────────────────────

    public function test_photo_ids_enumerate_every_photo_as_a_kind_and_id_pair(): void
    {
        $project = $this->project();

        [$visit, $worksheet] = $this->fullWorksheetVisit($project);

        $pairs = VisitEvidence::for($project, $visit->fresh())->photoIds();

        $this->assertCount(2, $pairs);

        foreach ($pairs as $pair) {
            $this->assertSame(['kind', 'id'], array_keys($pair));
        }

        $kinds = array_column($pairs, 'kind');

        $this->assertContains(VisitEvidence::KIND_WORKSHEET, $kinds);
        $this->assertContains(VisitEvidence::KIND_LABEL, $kinds);
        $this->assertNotContains(VisitEvidence::KIND_SURVEY, $kinds);
        $this->assertSame($worksheet->id, $visit->source_id);
    }

    public function test_every_photo_path_is_relative_and_no_absolute_path_is_built_here(): void
    {
        $project = $this->project();

        [$visit] = $this->fullWorksheetVisit($project);

        $evidence = $this->presenter()->evidence($project, $visit);

        foreach ($evidence['photos_by_bucket'] as $bucket) {
            foreach ($bucket as $photo) {
                $this->assertStringNotContainsString(storage_path(), $photo['path']);
                $this->assertStringNotContainsString(':\\', $photo['path']);
                $this->assertStringStartsNotWith('/', $photo['path']);
            }
        }

        // The CALL form, so the docblock may still name the method it
        // forbids — a rule you cannot write down is a rule that gets undone.
        $this->assertStringNotContainsString(
            '->absolutePath(',
            file_get_contents(app_path('Support/Cockpit/VisitEvidence.php')),
            'Exactly one place in this phase may turn a stored path into a filesystem path, and it is the ZIP builder in Plan 46.1-02 (T-46.1-05).'
        );
        $this->assertStringNotContainsString(
            'Storage::',
            file_get_contents(app_path('Support/Cockpit/VisitEvidence.php')),
            'No disk facade here either — this class reads rows, not files.'
        );
    }
}
