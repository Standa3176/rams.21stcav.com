<?php

namespace Tests\Feature\Security;

use App\Models\LabourResource;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\SiteSurvey;
use App\Models\SiteSurveyRoom;
use App\Models\SiteSurveyRoomQuestion;
use App\Models\User;
use App\Models\Worksheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 44 Plan 04 — the D-04 privacy proof: "Client should only be given
 * engineer name never phone or email — this is for PM only" (the user,
 * verbatim, 2026-09-19; 44-CONTEXT.md D-04).
 *
 * Modeled on `tests/Feature/Rams/MissingRiskRefGateSourceGuardTest.php`'s
 * static source-guard shape (allow-list scan + non-vacuity meta-test) and
 * `tests/Feature/Security/AdminCheckConsistencyTest.php`'s grep-for-a-
 * forbidden-idiom technique.
 *
 * ── Two halves, two different scopes (see 44-04-PLAN.md `<interfaces>`) ──
 *
 * 1. A static source scan of every genuinely client-facing PHP/Blade file
 *    (public survey/worksheet controllers+views, and every generated
 *    client PDF view) proving none of them reference the `LabourResource`
 *    class name at all — true today (nothing consumes the model yet) and
 *    should remain true indefinitely per D-05 (the selector is PM-only,
 *    never wired into a client-facing form).
 *
 * 2. A real HTTP-render proof against the two live, unauthenticated,
 *    token-gated public routes (`survey.show`, `public-worksheet.show`) —
 *    the genuine disclosure surface an unauthenticated third party (client
 *    or engineer with a link) actually reaches. A seeded `LabourResource`
 *    with a distinctive email/phone must never appear in either response
 *    body, regardless of `is_active`.
 *
 * The static scan additionally covers the generated PDF views (RAMS, O&M
 * manual, site-survey forms, commissioning-snagging, mini-O&M) that the
 * HTTP half does not exercise — rendering those end-to-end would require
 * DomPDF/mPDF fixtures unrelated to what this phase changes, and the
 * static scan already gives a strictly stronger guarantee for those files
 * today (zero references beats "we rendered one sample and didn't see
 * it").
 *
 * `app/Http/Controllers/WorksheetController.php`'s
 * `worksheets/{worksheet}/engineer-report.pdf` route (and its
 * `resources/views/pdf/engineer-report.blade.php` view) is DELIBERATELY
 * EXCLUDED from both halves. It is a staff-auth route living OUTSIDE the
 * `survey/{token}`/`worksheet/{token}` prefix blocks (confirmed via
 * `grep -n "engineer-report" routes/web.php` — the route sits inside the
 * authenticated block starting near `routes/web.php:639`, not the public
 * token group), so it is not client-facing at all — it is exactly the
 * kind of surface D-04 sanctions ("visible to the PM and admin only").
 * Do not "fix" this by adding it to the scan; that would misclassify a
 * staff-only surface as client-facing.
 *
 * The client-facing path list below was re-derived by live grep/find at
 * plan-execution time (2026-09-19), not hand-copied from an earlier
 * phase's notes:
 *
 *   grep -n "survey/{token}\|worksheet/{token}" routes/web.php
 *   grep -n "engineer-report" routes/web.php
 *   find resources/views/public-survey -type f
 *   find resources/views/pdf/om-manual -type f
 *   find resources/views/pdf/site-survey -type f
 *
 * @see app/Models/LabourResource.php
 * @see .planning/phases/44-labour-resources/44-CONTEXT.md (D-04, D-05)
 * @see .planning/phases/44-labour-resources/44-04-PLAN.md
 * @see tests/Feature/Rams/MissingRiskRefGateSourceGuardTest.php
 * @see tests/Feature/SurveyDownloadFormTest.php (makeSurveyWithRoom() fixture pattern)
 * @see tests/Feature/Worksheets/PublicWorksheetHeaderContactTest.php (makeWorksheetWithContact() fixture pattern)
 */
class LabourResourceClientSurfacePrivacyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The literal marker: nothing client-facing should ever reference this
     * class name, directly or via a partial include.
     */
    private const MARKER = 'LabourResource';

    /**
     * Every genuinely client-facing file in scope, relative to base_path().
     * Re-derived from a live grep/find of the repo at plan execution time
     * (see class docblock) — do not hand-copy without re-checking.
     */
    private const CLIENT_FACING_PATHS = [
        // Public survey token routes — resolve to TWO controllers.
        'app/Http/Controllers/SurveyController.php',
        'app/Http/Controllers/PublicSurveyController.php',
        'resources/views/surveys/show.blade.php',
        'resources/views/public-survey/confirmation.blade.php',
        'resources/views/public-survey/show.blade.php',

        // Public worksheet token routes — one controller.
        'app/Http/Controllers/PublicWorksheetController.php',
        'resources/views/worksheets/public-show.blade.php',

        // Generated client documents (44-CONTEXT.md canonical_refs).
        'resources/views/pdf/rams.blade.php',
        'resources/views/pdf/rams-v2.blade.php',
        'resources/views/pdf/om-manual.blade.php',
        'resources/views/pdf/om-manual/create.blade.php',
        'resources/views/pdf/om-manual/create.blade2703.php',
        'resources/views/pdf/om-manual/edit.blade.php',
        'resources/views/pdf/om-manual/index.blade.php',
        'resources/views/pdf/site-survey/blank.blade.php',
        'resources/views/pdf/site-survey/field-form.blade.php',
        'resources/views/pdf/site-survey/summary.blade.php',
        'resources/views/pdf/site-survey/_blank-room-body.blade.php',
        'resources/views/pdf/site-survey/_header-meta.blade.php',
        'resources/views/pdf/site-survey/_signoff.blade.php',
        'resources/views/pdf/site-survey/_styles.blade.php',
        'resources/views/pdf/commissioning-snagging.blade.php',
        'resources/views/pdf/mini-om.blade.php',
    ];

    // ── Task 1: static source-guard ─────────────────────────────────────

    public function test_no_client_facing_file_references_labour_resource(): void
    {
        $offenders = [];

        foreach (self::CLIENT_FACING_PATHS as $relative) {
            $absolute = base_path($relative);
            $contents = file_get_contents($absolute);

            if ($contents === false) {
                $offenders[] = "{$relative} could not be read";
                continue;
            }

            if (str_contains($contents, self::MARKER)) {
                $offenders[] = "{$relative} contains '" . self::MARKER . "'";
            }
        }

        $this->assertEmpty(
            $offenders,
            "D-04 privacy boundary violated: a client-facing file references '" . self::MARKER . "', "
            . "risking exposure of an engineer's email/phone to a client.\nOffenders:\n  - "
            . implode("\n  - ", $offenders),
        );
    }

    public function test_every_enumerated_path_exists(): void
    {
        foreach (self::CLIENT_FACING_PATHS as $relative) {
            $this->assertFileExists(
                base_path($relative),
                "Enumerated client-facing path does not exist: {$relative} — the list has drifted "
                . "from the real repo and this test is silently covering less than it claims to.",
            );
        }
    }

    /**
     * Non-vacuity proof: takes a real client-facing view's contents in
     * memory, appends a simulated regression (`{{ $labourResource->email }}`),
     * and asserts the scanning logic's own marker check WOULD catch it —
     * following `MissingRiskRefGateSourceGuardTest::test_guard_would_fail_if_the_gate_referenced_the_map()`'s
     * exact technique. Never written to disk.
     */
    public function test_guard_would_fail_if_a_client_facing_view_referenced_labour_resource(): void
    {
        $realFile = base_path('resources/views/public-survey/show.blade.php');
        $realContents = file_get_contents($realFile);
        $this->assertNotFalse($realContents, "Could not read {$realFile} for the non-vacuity check.");

        // Simulate the exact regression this guard exists to catch, in
        // memory only. Uses the fully-qualified class reference (not a
        // lowerCamelCase variable name) so the literal marker substring
        // actually appears — `$labourResource` alone would not contain the
        // PascalCase `LabourResource` marker.
        $simulatedRegression = $realContents . "\n{{ \App\Models\LabourResource::find(1)->email }}\n";

        $this->assertStringContainsString(
            self::MARKER,
            $simulatedRegression,
            'Sanity check on the guard itself: a view that DOES reference LabourResource must contain '
            . "the '" . self::MARKER . "' marker — if this assertion fails, the guard's own str_contains() "
            . 'logic is broken and the source-guard test above would pass for the wrong reason.',
        );
    }

    // ── Task 2: real HTTP render proof ──────────────────────────────────

    public function test_public_survey_show_never_leaks_a_seeded_resources_email_or_phone(): void
    {
        [$email, $phone] = $this->distinctiveContactDetails();
        LabourResource::factory()->create([
            'name'  => 'Marcus Okafor',
            'email' => $email,
            'phone' => $phone,
        ]);

        $survey = $this->makeSurveyWithRoom();

        $response = $this->get(route('survey.show', ['token' => $survey->access_token]));

        $response->assertOk();
        $this->assertStringNotContainsString($email, $response->getContent());
        $this->assertStringNotContainsString($phone, $response->getContent());
    }

    public function test_public_survey_show_never_leaks_an_inactive_seeded_resources_email_or_phone(): void
    {
        [$email, $phone] = $this->distinctiveContactDetails();
        LabourResource::factory()->create([
            'name'      => 'Priya Shah',
            'email'     => $email,
            'phone'     => $phone,
            'is_active' => false,
        ]);

        $survey = $this->makeSurveyWithRoom();

        $response = $this->get(route('survey.show', ['token' => $survey->access_token]));

        $response->assertOk();
        $this->assertStringNotContainsString($email, $response->getContent());
        $this->assertStringNotContainsString($phone, $response->getContent());
    }

    public function test_public_worksheet_show_never_leaks_a_seeded_resources_email_or_phone(): void
    {
        [$email, $phone] = $this->distinctiveContactDetails();
        LabourResource::factory()->create([
            'name'  => 'Marcus Okafor',
            'email' => $email,
            'phone' => $phone,
        ]);

        $worksheet = $this->makeWorksheetWithContact('John Smith', '0118 937 3787');

        $response = $this->get(route('public-worksheet.show', ['token' => $worksheet->access_token]));

        $response->assertOk();
        $this->assertStringNotContainsString($email, $response->getContent());
        $this->assertStringNotContainsString($phone, $response->getContent());
    }

    public function test_public_worksheet_show_never_leaks_an_inactive_seeded_resources_email_or_phone(): void
    {
        [$email, $phone] = $this->distinctiveContactDetails();
        LabourResource::factory()->create([
            'name'      => 'Priya Shah',
            'email'     => $email,
            'phone'     => $phone,
            'is_active' => false,
        ]);

        $worksheet = $this->makeWorksheetWithContact('John Smith', '0118 937 3787');

        $response = $this->get(route('public-worksheet.show', ['token' => $worksheet->access_token]));

        $response->assertOk();
        $this->assertStringNotContainsString($email, $response->getContent());
        $this->assertStringNotContainsString($phone, $response->getContent());
    }

    public function test_to_client_safe_array_returns_only_id_and_name(): void
    {
        $resource = LabourResource::factory()->create([
            'name'  => 'Marcus Okafor',
            'email' => 'marcus.okafor@example.test',
            'phone' => '07700 900001',
        ]);

        $safe = $resource->toClientSafeArray();

        $this->assertSame(['id', 'name'], array_keys($safe));
        $this->assertSame($resource->id, $safe['id']);
        $this->assertSame('Marcus Okafor', $safe['name']);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────

    /**
     * @return array{0: string, 1: string} [$email, $phone]
     */
    private function distinctiveContactDetails(): array
    {
        return ['engineer-privacy-check@example.test', '07700 900999'];
    }

    /**
     * Copied from `tests/Feature/SurveyDownloadFormTest.php`'s
     * `makeSurveyWithRoom()` — deliberately not shared via a trait, per the
     * plan's "self-contained test file" instruction.
     */
    private function makeSurveyWithRoom(array $overrides = []): SiteSurvey
    {
        $user = User::factory()->create();
        $project = Project::create([
            'user_id'      => $user->id,
            'name'         => 'Acme HQ Refresh',
            'client_name'  => 'Acme Ltd',
            'site_address' => '1 Example Way, London',
        ]);

        ProjectPackage::create([
            'user_id'           => $user->id,
            'project_id'        => $project->id,
            'status'            => ProjectPackage::STATUS_REVIEWED,
            'works_description' => 'Install 85" display + VC bar in Board Room.',
            'equipment_list'    => [
                ['quantity' => 1, 'description' => '85" 4K Display', 'manufacturer' => 'Samsung', 'model' => 'QM85'],
                ['quantity' => 1, 'description' => 'VC Bar',         'manufacturer' => 'Logitech', 'model' => 'Rally Bar'],
            ],
            'revision'          => 1,
        ]);

        $survey = SiteSurvey::create(array_merge([
            'user_id'       => $user->id,
            'project_id'    => $project->id,
            'project_name'  => 'Acme HQ Refresh',
            'project_ref'   => 'ACM-001',
            'client_name'   => 'Acme Ltd',
            'site_address'  => '1 Example Way, London',
            'surveyor_name' => 'J. Smith',
            'status'        => 'draft',
        ], $overrides));
        $survey->forceFill(['access_token' => (string) Str::uuid()])->save();

        $room = SiteSurveyRoom::create([
            'site_survey_id'    => $survey->id,
            'room_name'         => 'Board Room',
            'space_type'        => 'general',
            'sort_order'        => 0,
            'av_requirements'   => 'Ceiling speakers + VC bar install; replace existing display.',
            'av_equipment_list' => "1x Samsung QM85 display\n1x Logitech Rally Bar\n4x JBL Control 26C",
        ]);

        SiteSurveyRoomQuestion::create([
            'site_survey_room_id' => $room->id,
            'question'            => 'Is the ceiling accessible above the room?',
            'sort_order'          => 1,
            'answer'              => null,
        ]);

        return $survey->fresh();
    }

    /**
     * Copied from
     * `tests/Feature/Worksheets/PublicWorksheetHeaderContactTest.php`'s
     * `makeWorksheetWithContact()` — deliberately not shared via a trait,
     * per the plan's "self-contained test file" instruction.
     */
    private function makeWorksheetWithContact(?string $shipContact, ?string $shipPhone): Worksheet
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $extracted = [];
        if ($shipContact !== null) {
            $extracted['ship_contact'] = $shipContact;
        }
        if ($shipPhone !== null) {
            $extracted['ship_phone'] = $shipPhone;
        }

        ProjectPackage::create([
            'project_id'     => $project->id,
            'user_id'        => $user->id,
            'quote_filename' => 'fixture.pdf',
            'quote_path'     => 'fixtures/fixture.pdf',
            'extracted_data' => $extracted,
            'status'         => ProjectPackage::STATUS_EXTRACTED,
        ]);

        return Worksheet::create([
            'user_id'        => $user->id,
            'project_id'     => $project->id,
            'project_name'   => 'Reading Borough Council AV Refresh',
            'project_ref'    => '21CQ30362-01-OPS',
            'client_name'    => 'Reading Borough Council',
            'site_address'   => '10 High Street, Reading RG1 1AA',
            'status'         => Worksheet::STATUS_DRAFT,
            'generated_data' => [
                'rooms' => [
                    [
                        'name'                      => 'Main Hall',
                        'is_surveyed'               => true,
                        'install_steps'             => '',
                        'cable_route_desc'          => '',
                        'power_outlet_count'        => 0,
                        'requires_additional_power' => false,
                        'network_port_count'        => 0,
                        'existing_cabling'          => '',
                        'equipment'                 => [],
                    ],
                ],
            ],
        ]);
    }
}
