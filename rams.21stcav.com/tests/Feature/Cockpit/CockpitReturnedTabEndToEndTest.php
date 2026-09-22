<?php

namespace Tests\Feature\Cockpit;

use App\Jobs\BuildWorksheetJob;
use App\Models\Project;
use App\Models\ProjectActivityLog;
use App\Models\SiteSurvey;
use App\Models\SiteSurveyRoom;
use App\Models\SiteSurveyRoomQuestion;
use App\Models\User;
use App\Models\Visit;
use App\Models\Worksheet;
use App\Models\WorksheetPhoto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * Phase 46.1, Plan 46.1-06 — THE WHOLE PHASE AS ONE WALK, THROUGH HTTP.
 *
 * Plans 46.1-01..46.1-05 each proved a piece: the resolver, the archive, the
 * tab, the hand-off link, the relocation of the four acts. Nobody had yet
 * driven a job from "the PM creates the visit" to "the PM accepts it" across
 * the real request surface, and the pieces are only worth what they are worth
 * TOGETHER. This file is that walk.
 *
 * IT DOES NOT TOUCH MODELS EXCEPT TO ASSERT. Every step is a `$this->post()`
 * or `$this->get()` against a route this app actually exposes. A walk built on
 * `Model::create()` would prove the resolver and nothing about the product: the
 * whole risk of this phase is that the bytes on the Returned tab were uploaded
 * by an UNAUTHENTICATED stranger on a token URL.
 *
 * ── `actingAs()` PERSISTS FOR A WHOLE TEST ─────────────────────────────────
 * Phase 46's own walk found this the hard way: every "unauthenticated" request
 * made after the first PM request silently carried the PM's session, so the
 * engineer's seat was being proved WHILE LOGGED IN AS A PM. `asEngineer()`
 * drops the guard explicitly and asserts `assertGuest()` before each public
 * request, so an engineer step that only works behind a login fails here.
 *
 * ── THE CLOSING ASSERTION IS THE ONE THAT MATTERS ──────────────────────────
 * D-02: an office action never changes what the engineer said. The walk
 * compares `worksheets.access_token`, `worksheet_signoffs.signature_png_base64`
 * and EVERY `worksheet_photos.filename` through `getRawOriginal()` from the
 * moment the engineer finished to the moment after the PM accepted — across a
 * render, a ZIP download, two photo GETs and a real accept (T-46.1-25).
 *
 * ── AND THE ENGINEER'S LINK SURVIVES THE REVIEW ────────────────────────────
 * `/worksheet/{token}` is fetched unauthenticated AFTER the accept and must
 * still be 200 — VL-03, re-proved with THIS phase's routes in play
 * (T-46.1-26).
 *
 * ── THE HOSTILE SWEEP RIDES ON THE WALK'S OWN FIXTURES ─────────────────────
 * A room named `<img src=x onerror=alert(1)>`, a caption of
 * `"><script>alert(1)</script>` and a client of the same are what the engineer
 * actually submits here — not a synthetic row in a separate file. The page
 * stays 200, nothing is reflected raw, and no ZIP entry name carries a `..`.
 */
class CockpitReturnedTabEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private const HOSTILE_ROOM = '<img src=x onerror=alert(1)>';

    private const HOSTILE_CAPTION = '"><script>alert(1)</script>';

    private const HOSTILE_CLIENT = '"><script>alert(1)</script>';

    /** Symfony strips the path from an uploaded name, so this is planted on the ROW. */
    private const HOSTILE_ORIGINAL_NAME = '../../../../etc/passwd';

    private const SERIAL = 'SN-E2E-0001';

    /** A 1x1 transparent PNG, as the sign-off form sends it. */
    private const SIGNATURE_DATA_URI = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        config(['cockpit.enabled' => true]);

        // Both disks are faked: worksheet and survey photos land on `local`,
        // device label photos land on `public`. The walk is the reason that
        // distinction matters — see Plan 46.1-06's SUMMARY.
        Storage::fake('local');
        Storage::fake('public');

        // The public routes carry per-route throttles and this walk drives
        // several of them more than once. Disabling ONLY the limiter keeps
        // every gate under test genuinely exercised.
        $this->withoutMiddleware(ThrottleRequests::class);

        // The label-photo endpoint asks Claude to read the label. The engineer
        // then confirms the value by hand, which is the step this walk cares
        // about — so no request is allowed to leave the machine.
        Http::fake();
    }

    // ── Seats ────────────────────────────────────────────────────────────

    /**
     * THE ENGINEER'S SEAT: unauthenticated, token only.
     *
     * `actingAs()` persists for the whole test, so this must be called before
     * EVERY public request, not once at the top.
     */
    private function asEngineer(): self
    {
        auth()->logout();
        $this->assertGuest();

        return $this;
    }

    /** The rendered cockpit region on a given tab, entity-decoded. */
    private function region(User $pm, Project $project, string $module, string $tab = 'returned'): string
    {
        $body = $this->actingAs($pm)
            ->get(route('projects.cockpit', ['project' => $project, 'module' => $module, 'tab' => $tab]))
            ->assertOk()
            ->getContent();

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$body);
        libxml_clear_errors();

        $node = (new \DOMXPath($dom))
            ->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' cav-cockpit ')]")
            ->item(0);

        $this->assertNotNull($node, 'The cav-cockpit root was not found — an assertion on it would pass vacuously.');

        return $dom->saveHTML($node);
    }

    /** @return array<int, string> every href in the given markup, decoded */
    private function hrefs(string $html): array
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        $found = [];

        foreach ((new \DOMXPath($dom))->query('//a') as $anchor) {
            $found[] = html_entity_decode((string) $anchor->getAttribute('href'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $found;
    }

    /**
     * How many elements in the markup carry an `on*` handler attribute.
     *
     * Parsed, not grepped: the question is whether the browser would BIND a
     * handler, and only the parser can answer that. A `onerror=` sitting
     * inside a quoted `alt` value is text, not a handler.
     */
    private function handlerAttributeCount(string $html): int
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        return (new \DOMXPath($dom))->query("//@*[starts-with(name(), 'on')]")->length;
    }

    /** @return array<int, string> every entry name in the archive the response carries */
    private function zipEntryNames(\Symfony\Component\HttpFoundation\Response $response): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'e2e-zip-').'.zip';
        file_put_contents($tmp, $response->streamedContent ?? '');

        // A BinaryFileResponse carries a file; a StreamedResponse carries a
        // callback. Both are handled by capturing the sent bytes.
        if (filesize($tmp) === 0) {
            ob_start();
            $response->sendContent();
            file_put_contents($tmp, (string) ob_get_clean());
        }

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($tmp) === true, 'The downloaded archive could not be opened.');

        $names = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }

        $zip->close();
        @unlink($tmp);

        return $names;
    }

    /** Everything the engineer owns on the worksheet, read RAW. */
    private function engineerBytes(Worksheet $worksheet): array
    {
        $worksheet->refresh();

        $photos = WorksheetPhoto::where('worksheet_id', $worksheet->id)
            ->orderBy('id')
            ->get()
            ->map(fn (WorksheetPhoto $p): string => (string) $p->getRawOriginal('filename'))
            ->all();

        $signoff = $worksheet->signoffs()->orderByDesc('id')->first();

        return [
            'access_token' => (string) $worksheet->getRawOriginal('access_token'),
            'signature'    => (string) $signoff?->getRawOriginal('signature_png_base64'),
            'photos'       => $photos,
        ];
    }

    private function pm(): User
    {
        return User::factory()->create(['name' => 'Priya Mistry']);
    }

    private function project(string $name = 'End To End Review Job'): Project
    {
        return Project::factory()->create([
            'name'   => $name,
            'status' => Project::STATUS_INSTALLING,
        ]);
    }

    /**
     * The generated worksheet body the engineer's page needs to render. The
     * build job is faked — this walk is about the review loop, not about
     * document generation, and 46-08's own walk takes the same shortcut.
     *
     * NOTE: a worksheet with NO rooms here would 500 on `/worksheet/{token}`.
     * That is deferred defect D-46-05-01 (`$signOffBlocked` assigned inside the
     * populated-rooms branch and read outside it), still open on live and
     * deliberately NOT fixed by this plan.
     */
    private function generateRooms(Worksheet $worksheet, array $roomNames): void
    {
        $worksheet->forceFill([
            'status'         => Worksheet::STATUS_DRAFT,
            'generated_data' => ['rooms' => array_map(fn (string $name): array => [
                'name'                      => $name,
                'is_surveyed'               => true,
                'install_steps'             => '',
                'cable_route_desc'          => '',
                'power_outlet_count'        => 0,
                'requires_additional_power' => false,
                'network_port_count'        => 0,
                'existing_cabling'          => '',
                'equipment'                 => [],
            ], $roomNames)],
        ])->save();
    }

    // ── THE WALK ─────────────────────────────────────────────────────────

    /**
     * A PM raises an install visit, an engineer on a phone returns it, and the
     * PM reads it, downloads it and accepts it — without one byte of what the
     * engineer captured moving.
     */
    public function test_a_pm_creates_an_install_visit_an_engineer_returns_it_and_the_pm_reviews_downloads_and_accepts(): void
    {
        Bus::fake();

        $pm      = $this->pm();
        $project = $this->project();

        // ── 1. THE PM CREATES THE INSTALL VISIT ─────────────────────────
        $this->actingAs($pm)
            ->post(route('projects.cockpit.visits.store', $project), [
                'module'         => 'worksheet',
                'visit_type'     => Visit::TYPE_INSTALL,
                'scheduled_date' => '2026-11-02',
                'rooms'          => ['Board Room', self::HOSTILE_ROOM],
            ])
            ->assertRedirect(route('projects.cockpit', ['project' => $project, 'module' => 'worksheet']))
            ->assertSessionHas('success');

        $visit     = Visit::where('project_id', $project->id)->sole();
        $worksheet = Worksheet::where('project_id', $project->id)->sole();

        $this->assertSame(Visit::SOURCE_WORKSHEET, $visit->source_type);
        $this->assertSame($worksheet->id, $visit->source_id);
        $this->assertNotEmpty($worksheet->access_token, 'The visit must come with an engineer link.');
        $this->assertSame(Visit::STATE_SENT, $visit->state());
        Bus::assertDispatched(BuildWorksheetJob::class);

        $this->generateRooms($worksheet, ['Board Room', self::HOSTILE_ROOM]);
        $token = $worksheet->access_token;

        // ── 2. THE ENGINEER RETURNS IT — UNAUTHENTICATED, BY TOKEN ───────
        // Two rooms, a label with a serial the engineer confirms by hand, and
        // the client's signature. Every one a real multipart POST.

        $firstUpload = $this->asEngineer()->post(
            route('public-worksheet.photos.upload', ['token' => $token]),
            [
                'room_name' => 'Board Room',
                'photo'     => UploadedFile::fake()->image('install-01.jpg'),
                'caption'   => self::HOSTILE_CAPTION,
            ],
        )->assertOk();

        $this->asEngineer()->post(
            route('public-worksheet.photos.upload', ['token' => $token]),
            [
                'room_name' => self::HOSTILE_ROOM,
                'photo'     => UploadedFile::fake()->image('install-02.jpg'),
            ],
        )->assertOk();

        // Symfony's UploadedFile returns the BASENAME of a client filename, so
        // a traversal cannot arrive through the upload at all — the first of
        // two defences. The second is the ZIP sanitiser, and proving it needs a
        // row that actually carries the hostile value, exactly as the rows on
        // live might. Planted on the ROW, never through the request.
        WorksheetPhoto::where('id', $firstUpload->json('id'))
            ->update(['original_name' => self::HOSTILE_ORIGINAL_NAME]);

        $label = $this->asEngineer()->post(
            route('public-worksheet.label-photo.upload', ['token' => $token]),
            [
                'room_name'        => 'Board Room',
                'item_description' => 'Ceiling microphone array',
                'photo'            => UploadedFile::fake()->image('label-01.jpg'),
            ],
        )->assertOk();

        $this->asEngineer()->post(
            route('public-worksheet.label-photo.confirm', ['token' => $token, 'photo' => $label->json('id')]),
            ['serial_number' => self::SERIAL],
        )->assertOk();

        $this->asEngineer()->post(
            route('public-worksheet.sign', ['token' => $token]),
            [
                'client_name'     => self::HOSTILE_CLIENT,
                'signature_image' => self::SIGNATURE_DATA_URI,
                'happy_with_work' => '1',
            ],
        )->assertRedirect(route('public-worksheet.show', ['token' => $token]));

        // Every step of the engineer's return happened with no session at
        // all. `assertGuest()` takes a GUARD NAME, not a message — a
        // sentence here silently asks for a guard that does not exist.
        $this->assertGuest();

        // What the engineer owns, AS SUBMITTED. Step 7 closes against this.
        $bytesAtReturn = $this->engineerBytes($worksheet);

        $this->assertCount(2, $bytesAtReturn['photos'], 'Both room photos were written by the public endpoint.');
        $this->assertNotSame('', $bytesAtReturn['signature']);
        $this->assertSame(Visit::STATE_RETURNED, $visit->refresh()->state());

        // ── 3. THE PM OPENS THE RETURNED TAB ────────────────────────────
        $region = $this->region($pm, $project, 'worksheet');

        // The evidence is all there, in the PM's own words.
        $this->assertStringContainsString('Download all photos (ZIP)', $region);
        $this->assertStringContainsString(self::SERIAL, $region);
        $this->assertStringContainsString('Client sign-off', $region);
        $this->assertStringContainsString('cav-returned__signature', $region);

        // The engineer's and the client's free text is ESCAPED, always. The
        // caption reaches the page as an `alt`, the client name as text, and
        // the room name as a heading — so the check is on the RAW markup.
        $this->assertStringNotContainsString('<script', $region);
        $this->assertStringNotContainsString('<img src=x', $region);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $region);
        $this->assertStringContainsString('alert(1)', $region, 'Non-vacuity: the hostile strings really did reach the page, escaped.');

        // AND THE PROPERTY UNDERNEATH THE STRING MATCH. `e()` escapes `<`, `>`,
        // `&`, `"` and `'` — it does NOT escape `=`, so the literal text
        // `onerror=` survives INSIDE a quoted attribute value (the room name
        // reaches the contact sheet as an `alt`). That is harmless and a
        // substring check would call it a breach. What must be true is that no
        // ELEMENT carries a handler attribute — the same nine the fence bans.
        $this->assertSame(
            0,
            $this->handlerAttributeCount($region),
            'Engineer free text became a handler attribute on a real element.',
        );

        // The four acts are offered, beneath the evidence (D-02).
        foreach (['Accept', 'Send back', 'Add note', 'Raise a snag'] as $act) {
            $this->assertStringContainsString($act, $region);
        }

        // ── 4. THE ZIP, OPENED FOR REAL ─────────────────────────────────
        $zipHref = null;

        foreach ($this->hrefs($region) as $href) {
            if (str_contains($href, 'photos.zip')) {
                $zipHref = $href;
            }
        }

        $this->assertNotNull($zipHref, 'The hand-off link is the PM\'s only route to the archive.');

        $names = $this->zipEntryNames(
            $this->actingAs($pm)->get($zipHref)->assertOk()->baseResponse,
        );

        $this->assertContains('README.txt', $names);

        // BOTH rooms, and BOTH sub-folders. The install photos land in
        // `after/` and the equipment label in `label/`.
        $this->assertNotEmpty(
            array_filter($names, fn (string $n): bool => str_starts_with($n, 'Board Room/after/')),
            'The Board Room install photo must land in Board Room/after/.',
        );
        $this->assertNotEmpty(
            array_filter($names, fn (string $n): bool => str_starts_with($n, 'Board Room/label/')),
            'The equipment label must land in Board Room/label/.',
        );
        $this->assertNotEmpty(
            array_filter($names, fn (string $n): bool => str_contains($n, '/after/') && ! str_starts_with($n, 'Board Room/')),
            'The second room must have a folder of its own.',
        );

        // Zip-slip, on every entry rather than the first.
        foreach ($names as $name) {
            $this->assertStringNotContainsString('..', $name);
            $this->assertStringNotContainsString('\\', $name);
            $this->assertStringNotContainsString('etc/passwd', $name);
            $this->assertFalse(str_starts_with($name, '/'), "Entry `{$name}` is an absolute path.");
        }

        // ── 5. THE PHOTO LINKS THE PM WOULD ACTUALLY CLICK ──────────────
        $photoHrefs = array_values(array_filter(
            $this->hrefs($region),
            fn (string $href): bool => str_contains($href, '/cockpit/visits/') && str_contains($href, '/photo/'),
        ));

        $this->assertGreaterThanOrEqual(3, count($photoHrefs), 'Two room photos and one label photo are linked.');

        foreach ($photoHrefs as $href) {
            $this->actingAs($pm)->get($href)->assertOk();
        }

        // A photo id belonging to ANOTHER project is 404, not served. The same
        // kind, the same route shape, a foreign row — which is the only case a
        // `find()` would have got wrong.
        $otherProject   = $this->project('Somebody Else\'s Job');
        $otherWorksheet = Worksheet::factory()->create(['project_id' => $otherProject->id]);
        $otherPhoto     = WorksheetPhoto::create([
            'worksheet_id'  => $otherWorksheet->id,
            'room_name'     => 'Board Room',
            'filename'      => 'worksheet-photos/not-yours.jpg',
            'original_name' => 'not-yours.jpg',
            'mime_type'     => 'image/jpeg',
            'sort_order'    => 0,
        ]);

        $this->actingAs($pm)->get(route('projects.cockpit.visits.photo', [
            'project' => $project,
            'visit'   => $visit,
            'kind'    => 'worksheet',
            'photo'   => $otherPhoto->id,
        ]))->assertNotFound();

        // ── 6. THE PM ACCEPTS, FROM THE RETURNED TAB ────────────────────
        $this->actingAs($pm)
            ->post(route('projects.cockpit.visits.accept', ['project' => $project, 'visit' => $visit]), [
                'tab' => 'returned',
            ])
            ->assertRedirect(route('projects.cockpit', [
                'project' => $project,
                'module'  => 'worksheet',
                'tab'     => 'returned',
            ]))
            ->assertSessionHas('success');

        $visit->refresh();
        $this->assertNotNull($visit->accepted_at);
        $this->assertSame($pm->id, $visit->accepted_by_user_id);
        $this->assertTrue($visit->isClosed());

        $this->assertSame(
            1,
            ProjectActivityLog::where('project_id', $project->id)
                ->where('action', ProjectActivityLog::ACTION_VISIT_ACCEPTED)
                ->count(),
            'Accepting a visit writes exactly ONE activity row.',
        );

        // ── 7. NOTHING THE OFFICE DID MOVED ONE BYTE (D-02, T-46.1-25) ──
        $this->assertSame(
            $bytesAtReturn,
            $this->engineerBytes($worksheet),
            'A render, a ZIP download, three photo GETs and an accept changed what the engineer sent back.',
        );

        // ── 8. AND THE ENGINEER'S LINK STILL OPENS (VL-03, T-46.1-26) ───
        $this->asEngineer()
            ->get(route('public-worksheet.show', ['token' => $token]))
            ->assertOk();
    }

    /**
     * THE SURVEY SIDE, shorter: two answered room questions and one photo,
     * rendered per room, archived under `before/` — and the survey's own
     * record byte-identical after the review.
     */
    public function test_a_survey_visit_renders_per_room_answers_and_archives_them_under_before(): void
    {
        $pm      = $this->pm();
        $project = $this->project('Survey Review Job');

        $this->actingAs($pm)
            ->post(route('projects.cockpit.visits.store', $project), [
                'module'     => 'site_survey',
                'visit_type' => Visit::TYPE_SITE_SURVEY,
            ])
            ->assertRedirect(route('projects.cockpit', ['project' => $project, 'module' => 'site_survey']));

        $visit  = Visit::where('project_id', $project->id)->sole();
        $survey = SiteSurvey::where('project_id', $project->id)->sole();
        $token  = $survey->access_token;

        // The wizard creates rooms and their question set as the engineer walks
        // the building. This walk is not about the wizard, so the scaffolding
        // is made directly — but every ANSWER below arrives through the real
        // public endpoint, unauthenticated.
        $room = SiteSurveyRoom::create([
            'site_survey_id' => $survey->id,
            'room_name'      => 'Board Room',
            'space_type'     => 'general',
            'sort_order'     => 0,
        ]);

        $questions = collect(['Is there a spare double socket?', 'Is the ceiling accessible?'])
            ->map(fn (string $text, int $i): SiteSurveyRoomQuestion => SiteSurveyRoomQuestion::create([
                'site_survey_room_id' => $room->id,
                'question'            => $text,
                'sort_order'          => $i,
            ]));

        $this->asEngineer()->post(
            route('survey.question.answer', ['token' => $token, 'room' => $room->id, 'question' => $questions[0]->id]),
            ['answer' => 'yes'],
        )->assertOk();

        $this->asEngineer()->post(
            route('survey.question.answer', ['token' => $token, 'room' => $room->id, 'question' => $questions[1]->id]),
            ['answer' => 'other', 'other_text' => 'Only above the lectern.'],
        )->assertOk();

        $this->asEngineer()->post(
            route('survey.photos.upload', ['token' => $token, 'room' => $room->id]),
            ['photo' => UploadedFile::fake()->image('room-01.jpg'), 'caption' => 'Existing plate'],
        )->assertOk();

        $this->asEngineer()->post(
            route('survey.submit', ['token' => $token]),
            ['surveyor_name' => 'Dan Okafor'],
        )->assertRedirect(route('survey.confirmation', ['token' => $token]));

        $survey->refresh();
        $this->assertNotNull($survey->submitted_at);
        $this->assertSame(Visit::STATE_RETURNED, $visit->refresh()->state());

        $bytesAtReturn = [
            'access_token' => (string) $survey->getRawOriginal('access_token'),
            'submitted_at' => (string) $survey->getRawOriginal('submitted_at'),
            'survey_data'  => (string) $survey->getRawOriginal('survey_data'),
        ];

        // ── THE PM READS IT ─────────────────────────────────────────────
        $region = $this->region($pm, $project, 'site_survey');

        $this->assertStringContainsString('Board Room', $region);
        $this->assertStringContainsString('Is there a spare double socket?', $region);
        $this->assertStringContainsString('Yes', $region);
        // An `other` answer shows the engineer's OWN WORDS, not the token.
        $this->assertStringContainsString('Only above the lectern.', $region);
        $this->assertStringNotContainsString('>Other<', $region);
        // Two integers, not twenty empty rows.
        $this->assertStringContainsString('2 of 2 questions answered', $region);

        // ── AND THE ARCHIVE GROUPS IT UNDER before/ ─────────────────────
        $zipHref = null;

        foreach ($this->hrefs($region) as $href) {
            if (str_contains($href, 'photos.zip')) {
                $zipHref = $href;
            }
        }

        $this->assertNotNull($zipHref);

        $names = $this->zipEntryNames($this->actingAs($pm)->get($zipHref)->assertOk()->baseResponse);

        $this->assertNotEmpty(
            array_filter($names, fn (string $n): bool => str_starts_with($n, 'Board Room/before/')),
            'A survey photo belongs in before/.',
        );

        foreach ($names as $name) {
            $this->assertStringNotContainsString('/after/', $name);
            $this->assertStringNotContainsString('/label/', $name);
        }

        // The review moved nothing.
        $survey->refresh();
        $this->assertSame($bytesAtReturn, [
            'access_token' => (string) $survey->getRawOriginal('access_token'),
            'submitted_at' => (string) $survey->getRawOriginal('submitted_at'),
            'survey_data'  => (string) $survey->getRawOriginal('survey_data'),
        ]);
    }

    /**
     * THE RECONSTRUCTED CASE (D-06 / RV-06): its evidence is worth seeing, and
     * NOBODY PERFORMED THAT REVIEW — so the row offers not one control, while
     * the archive still opens.
     *
     * There are 24 of these on live. A review surface that greeted a PM with
     * two dozen phantom items for work finished years ago would be worse than
     * no surface at all.
     */
    public function test_a_reconstructed_visit_shows_its_evidence_and_offers_nothing(): void
    {
        $pm      = $this->pm();
        $project = $this->project('Reconstructed Job');

        $worksheet = Worksheet::factory()->create(['project_id' => $project->id]);

        $worksheet->signoffs()->create([
            'client_name'          => 'Marta Vieira',
            'signature_png_base64' => 'iVBORw0KGgo=',
            'signed_with_comments' => false,
            'signed_at'            => now()->subYears(2),
        ]);

        WorksheetPhoto::create([
            'worksheet_id'  => $worksheet->id,
            'room_name'     => 'Board Room',
            'filename'      => 'worksheet-photos/archive-01.jpg',
            'original_name' => 'archive-01.jpg',
            'mime_type'     => 'image/jpeg',
            'sort_order'    => 0,
        ]);

        Storage::disk('local')->put('worksheet-photos/archive-01.jpg', 'not-really-a-jpeg');

        $visit = Visit::factory()
            ->backfilledFromWorksheet($worksheet)
            ->create([
                'project_id' => $project->id,
                'type'       => Visit::TYPE_INSTALL,
                'title'      => 'Install visit',
            ]);

        $region = $this->region($pm, $project, 'worksheet');

        // Its evidence IS shown.
        $this->assertStringContainsString('Board Room', $region);
        $this->assertStringContainsString('Marta Vieira', $region);
        $this->assertStringContainsString('Download all photos (ZIP)', $region);

        // And it offers NOTHING.
        foreach (['Accept', 'Send back', 'Add note', 'Raise a snag'] as $act) {
            $this->assertStringNotContainsString($act, $region);
        }

        $this->assertSame(0, substr_count($region, '<button'));

        // The archive still opens — reading an old job is not reviewing it.
        $names = $this->zipEntryNames(
            $this->actingAs($pm)
                ->get(route('projects.cockpit.visits.photos-zip', ['project' => $project, 'visit' => $visit]))
                ->assertOk()
                ->baseResponse,
        );

        $this->assertContains('README.txt', $names);
        $this->assertNotEmpty(array_filter($names, fn (string $n): bool => str_starts_with($n, 'Board Room/after/')));
    }
}
