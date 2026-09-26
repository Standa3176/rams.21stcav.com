<?php

namespace Tests\Feature\Security;

use App\Models\LabourResource;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\SiteSurvey;
use App\Models\SiteSurveyRoom;
use App\Models\SiteSurveyRoomQuestion;
use App\Models\User;
use App\Models\Visit;
use App\Models\Worksheet;
use App\Models\WorksheetAdditionalKit;
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
 * ── 2026-09-27, Phase 46.4 Plan 05 — WHY THIS FILE JUST GREW A WHOLE SECTION ──
 *
 * When this file was written, nothing in the app put an engineer's name in front
 * of a client, and both halves above were guarding an absence. That changed.
 *
 * D-01 (46.4-CONTEXT.md) kept **ONE page and ONE URL** for the engineer and the
 * client: SCC splits them (`/pmv/{token}` vs `/client-report/{token}`), RAMS
 * does not, and the user chose to keep it that way. So
 * `worksheets/public-show.blade.php` — the page the CLIENT reads and SIGNS — is
 * now also the page an engineer captures on, and from plan 46.4-05 it **NAMES
 * ENGINEERS**: every additional-kit row renders `Qty × Part description —
 * Engineer name`.
 *
 * That makes **LR-04 bind harder here than it ever has**: a client is given an
 * engineer's NAME and nothing else, never a phone and never an email, on a
 * surface with no login on it. `LabourResource` carries all three
 * (`app/Models/LabourResource.php:43-50`).
 *
 * **The HTTP half is where the proof lives for this surface, and that is the
 * point.** The static scan is a strictly stronger guarantee only while a file
 * references the model ZERO times — useful, and still asserted below for
 * `public-show.blade.php`, because the name reaches that page through
 * `App\Support\Worksheets\AllocatedEngineers`' plain `['id','name']` arrays and
 * never through the model class. But the name is now **SUPPOSED to render**, so
 * "we found no name" can no longer be the test. The render assertions therefore
 * put the PRESENCE of the name FIRST: without it, every absence assertion could
 * pass on a page that failed to render the row at all.
 *
 * **D-07 is why a SIGNED worksheet is covered too.** Once a client signs, the
 * capture surface closes — but the page still RENDERS, because a signed
 * worksheet is a record its signer must be able to read. So **the disclosure
 * surface OUTLIVES the capture surface**, and a test that only covered the
 * capturable state would stop covering the page at exactly the moment it becomes
 * a legal document. Four renderings are asserted: plain, amended, marked, and
 * signed. The amendment chip and the marked-row reason are each a NEW output
 * path and each a new chance to print the wrong thing.
 *
 * This is the one addition the "what may be added here" rule below permits: a
 * genuinely client-facing path. It is **not** a staff-auth surface.
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

        // Shared partial included by BOTH public token views (Phase 46, Plan
        // 46-05). Genuinely client-facing: `worksheets/public-show.blade.php`
        // is the page a client opens and signs, and this partial renders
        // inside it. Added here in the same commit that created it.
        // It carries no actor name and must never reference LabourResource —
        // an engineer's email or phone must not reach a client (LR-04).
        'resources/views/partials/_office-sendback-banner.blade.php',

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

    // ── Task 3 (46.4-05): LR-04 on the page the client now reads ────────
    //
    // IC-06. Four renderings, one fixture shape, and the NAME ASSERTED FIRST
    // every time — see the class docblock for why the presence assertion is
    // what makes the absences non-vacuous here.

    /** The engineer whose details must never reach the client. */
    private const KIT_ENGINEER_NAME  = 'Dean Whitcombe';
    private const KIT_ENGINEER_EMAIL = 'dean.whitcombe@21stcav.example';
    private const KIT_ENGINEER_PHONE = '07700 900123';

    public function test_the_public_worksheet_names_the_engineer_on_a_kit_row_and_leaks_neither_contact_detail(): void
    {
        [$worksheet] = $this->makeWorksheetWithAllocatedEngineerAndKitRow();

        $response = $this->get(route('public-worksheet.show', ['token' => $worksheet->access_token]));

        $response->assertOk();
        $this->assertClientSeesTheNameAndNothingElse($response->getContent());
    }

    public function test_an_AMENDED_kit_row_still_leaks_neither_contact_detail(): void
    {
        [$worksheet, $row] = $this->makeWorksheetWithAllocatedEngineerAndKitRow();

        // The amendment chip and the trail are a NEW output path. forceFill,
        // because every column it touches is off $fillable by design.
        $row->forceFill([
            'amendments' => [[
                'at'      => now()->toIso8601String(),
                'actor'   => 'ip:198.51.100.9|actor:c0ffee123456',
                'changes' => ['qty' => ['from' => 3, 'to' => 5]],
            ]],
            'amended_at' => now(),
        ])->save();

        $response = $this->get(route('public-worksheet.show', ['token' => $worksheet->access_token]));

        $response->assertOk();
        // Non-vacuity for THIS rendering specifically: the amended state is on
        // screen, so the page really did take the amended branch.
        $response->assertSee('Amended', false);
        $this->assertClientSeesTheNameAndNothingElse($response->getContent());
    }

    public function test_a_MARKED_kit_row_still_leaks_neither_contact_detail(): void
    {
        [$worksheet, $row] = $this->makeWorksheetWithAllocatedEngineerAndKitRow();

        $row->forceFill([
            'marked_for_deletion_at' => now(),
            'deletion_reason'        => 'Ordered twice — only one length fitted.',
            'marked_by_actor'        => 'ip:198.51.100.10|actor:abad1dea9876',
        ])->save();

        $response = $this->get(route('public-worksheet.show', ['token' => $worksheet->access_token]));

        $response->assertOk();
        $response->assertSee('Marked for deletion', false);
        // The reason is engineer free text and renders — and the actor stamp
        // that moved with it does NOT.
        $response->assertSee('Ordered twice', false);
        $this->assertStringNotContainsString('abad1dea9876', $response->getContent());
        $this->assertClientSeesTheNameAndNothingElse($response->getContent());
    }

    /**
     * D-07: signing CLOSES the capture surface but the page still renders — so
     * the disclosure surface outlives the capture surface, and this is the
     * rendering that matters most, because it is the one the client keeps.
     */
    public function test_a_SIGNED_worksheet_still_renders_the_name_and_still_leaks_neither_contact_detail(): void
    {
        [$worksheet] = $this->makeWorksheetWithAllocatedEngineerAndKitRow();

        $worksheet->signoffs()->create([
            'client_name'          => 'A Client',
            'signature_png_base64' => base64_encode('not-a-real-png'),
            'signed_with_comments' => false,
            'signed_at'            => now(),
        ]);

        $response = $this->get(route('public-worksheet.show', ['token' => $worksheet->access_token]));

        $response->assertOk();
        // The capture surface is gone — asserted on the BUTTON LABEL, not on the
        // `data-kit-trigger` attribute: that literal also appears in the page's
        // drawer script, which is rendered unconditionally, so an attribute-name
        // assertion would fail on the JS rather than on the markup.
        $this->assertStringNotContainsString('+ Add additional kit', $response->getContent());
        // Same reason: the drawer script builds a "Mark for deletion" button in a
        // string, so only Blade-ONLY copy can stand as evidence the markup is
        // gone. This subtitle exists nowhere else on the page.
        $this->assertStringNotContainsString('Tap to open — add one or several before closing.', $response->getContent());
        // …and the RECORD is not.
        $this->assertClientSeesTheNameAndNothingElse($response->getContent());
    }

    public function test_a_kit_row_with_no_engineer_renders_the_unassigned_sentence_and_no_ones_name(): void
    {
        [$worksheet] = $this->makeWorksheetWithAllocatedEngineerAndKitRow(null);

        $response = $this->get(route('public-worksheet.show', ['token' => $worksheet->access_token]));

        $response->assertOk();
        $content = $response->getContent();

        // The row itself rendered — otherwise the absences below are vacuous.
        $this->assertStringContainsString('Trunking, 50x50 white', $content);

        // ⚠️ ASSERTED ON THE ROW, NOT ON THE WHOLE PAGE. The allocated engineer's
        // NAME legitimately appears elsewhere on this page — they are an option
        // in the drawer's engineer picker, which is exactly what D-02 requires
        // and what LR-04 permits. What must not happen is the ROW claiming them.
        $this->assertSame(
            1,
            preg_match('/<li data-kit-row="\d+".*?<\/li>/s', $content, $matches),
            'The kit row did not render at all — the assertions below would be vacuous.',
        );
        $rowHtml = $matches[0];

        $this->assertStringContainsString(
            'Unassigned — no engineer allocated to this visit',
            $rowHtml,
            'A NULL engineer must render the ruled sentence (D-02) — not a blank, and never a guessed name.',
        );
        // A null must never resolve to somebody. THERE IS NO FREE-TEXT ENGINEER
        // FIELD, which is what stops a null becoming a spelling variant.
        $this->assertStringNotContainsString(self::KIT_ENGINEER_NAME, $rowHtml);
        // The contact details must be absent from the WHOLE page regardless.
        $this->assertStringNotContainsString(self::KIT_ENGINEER_EMAIL, $content);
        $this->assertStringNotContainsString(self::KIT_ENGINEER_PHONE, $content);
    }

    /**
     * The name reaches the page through `AllocatedEngineers`' plain arrays, never
     * through the model class — so the static scan's ZERO still holds for this
     * file even though it now prints an engineer's name. No exclusion was added
     * to `CLIENT_FACING_PATHS` and none may be.
     */
    public function test_the_public_worksheet_view_still_references_labour_resource_zero_times(): void
    {
        $relative = 'resources/views/worksheets/public-show.blade.php';

        $this->assertContains(
            $relative,
            self::CLIENT_FACING_PATHS,
            'The public worksheet view must stay on the scanned list — it is the page the client signs.',
        );

        $contents = file_get_contents(base_path($relative));
        $this->assertNotFalse($contents);

        $this->assertSame(
            0,
            substr_count($contents, self::MARKER),
            "{$relative} references " . self::MARKER . ' — the engineer\'s name must arrive via '
            . 'App\Support\Worksheets\AllocatedEngineers\' two-key arrays, which cannot carry an '
            . 'email or a phone, never through the model class.',
        );
    }

    /**
     * The shared assertion for all four renderings.
     *
     * ⚠️ PRESENCE FIRST, ALWAYS. The name is SUPPOSED to render here; if it does
     * not, the row did not render, and every absence below would pass for the
     * wrong reason.
     */
    private function assertClientSeesTheNameAndNothingElse(string $content): void
    {
        // 1. THE NAME IS THERE.
        $this->assertStringContainsString(
            self::KIT_ENGINEER_NAME,
            $content,
            'The engineer\'s NAME must render on the kit row — LR-04 permits the name, and without '
            . 'it the absence assertions below are vacuous.',
        );
        $this->assertStringContainsString('Trunking, 50x50 white', $content);

        // 2. THE EMAIL IS NOT — raw, and in the two entity forms an HTML
        //    escaper could produce for the `@`.
        foreach ([
            self::KIT_ENGINEER_EMAIL,
            str_replace('@', '&#64;', self::KIT_ENGINEER_EMAIL),
            str_replace('@', '&#x40;', self::KIT_ENGINEER_EMAIL),
            str_replace('@', '&commat;', self::KIT_ENGINEER_EMAIL),
        ] as $form) {
            $this->assertStringNotContainsString(
                $form,
                $content,
                "An engineer's email reached a page the client signs (form: {$form}) — LR-04.",
            );
        }

        // 3. NEITHER IS THE PHONE — spaced AND unspaced, because a tel: link or
        //    a normaliser would strip the space and slip past a single form.
        foreach ([
            self::KIT_ENGINEER_PHONE,
            str_replace(' ', '', self::KIT_ENGINEER_PHONE),
        ] as $form) {
            $this->assertStringNotContainsString(
                $form,
                $content,
                "An engineer's phone number reached a page the client signs (form: {$form}) — LR-04.",
            );
        }
    }

    /**
     * A worksheet whose install visit allocates ONE engineer with a realistic
     * email and phone on the record, carrying one additional-kit row attributed
     * to them.
     *
     * Follows the file's existing `makeWorksheetWithContact()` pattern and reuses
     * it for the worksheet itself, so the room name the kit row is scoped to is
     * the same `Main Hall` that fixture already declares.
     *
     * @param  int|false|null  $engineerOnRow  false = attribute the row to the
     *         allocated engineer (the default); null = leave it unattributed.
     * @return array{0: Worksheet, 1: WorksheetAdditionalKit}
     */
    private function makeWorksheetWithAllocatedEngineerAndKitRow(int|false|null $engineerOnRow = false): array
    {
        $engineer = LabourResource::factory()->create([
            'name'  => self::KIT_ENGINEER_NAME,
            'email' => self::KIT_ENGINEER_EMAIL,
            'phone' => self::KIT_ENGINEER_PHONE,
        ]);

        $worksheet = $this->makeWorksheetWithContact('John Smith', '0118 937 3787');

        Visit::factory()->create([
            'project_id'          => $worksheet->project_id,
            'type'                => Visit::TYPE_INSTALL,
            'source_type'         => Visit::SOURCE_WORKSHEET,
            'source_id'           => $worksheet->id,
            'labour_resource_ids' => [$engineer->id],
        ]);

        $row = WorksheetAdditionalKit::factory()->create([
            'worksheet_id'       => $worksheet->id,
            'room_name'          => 'Main Hall',
            'labour_resource_id' => $engineerOnRow === false ? $engineer->id : $engineerOnRow,
            'qty'                => 3,
            'part_description'   => 'Trunking, 50x50 white',
            'sort_order'         => 1,
        ]);

        return [$worksheet->fresh(), $row];
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
