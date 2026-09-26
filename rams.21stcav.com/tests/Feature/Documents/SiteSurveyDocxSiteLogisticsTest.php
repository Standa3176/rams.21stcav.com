<?php

namespace Tests\Feature\Documents;

use App\Models\Project;
use App\Models\SiteSurvey;
use App\Models\SiteSurveyRoom;
use App\Models\User;
use App\Support\Visits\SurveyCarryForward;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpWord\Settings;
use Tests\TestCase;
use ZipArchive;

/**
 * D-46.2-06-06 — the site-survey .docx silently drops the PM's site logistics.
 *
 * `SiteSurveyDocxService` rendered `general_notes` and nothing else from the
 * survey record. `site_access_notes`, `parking_restraints`, `delivery_routes`
 * and `comms_room_access_notes` are all on `SiteSurvey::$fillable`, all four are
 * on the cockpit document form that plan 46.2-05 shipped, and all four persist —
 * but `grep -c` over the writer returned 2 for `general_notes` and 0 for each of
 * the other four. A PM typed parking and access information into the live
 * cockpit, watched it save, and got a Word document that did not contain it.
 *
 * Severity is safety, not tidiness: these are the same columns
 * `App\Support\Visits\SurveyCarryForward` exists to move onto the installing
 * engineer's link. The carry-forward was never broken — but anyone reading the
 * DOCUMENT rather than the link learned nothing about access or parking.
 *
 * ── Why this reads through SurveyCarryForward rather than a literal list ─────
 * The labels, the render order and the `comms_room_access_status` label map are
 * asserted against `SurveyCarryForward::FIELDS` / `::COMMS_ROOM_LABELS`, which
 * is the single place this project derives survey findings. Hard-coding the
 * strings here would let the test and the writer agree with each other while
 * both drifted away from every other reader — which is exactly how
 * D-46.2-04-02 happened (a fifth, invented spelling of the comms vocabulary).
 *
 * ── Why setOutputEscapingEnabled(false) is called below ─────────────────────
 * Same process-global-static vacuity trap documented at length in
 * SiteSurveyDocxOutputEscapingTest. Forcing it false reproduces a cold request
 * in which the site survey is the only document built, so the double-escaping
 * assertion in this file measures THIS writer rather than a sibling's call.
 */
class SiteSurveyDocxSiteLogisticsTest extends TestCase
{
    use RefreshDatabase;

    /** Distinct, unmistakable text per field so a hit cannot be another field's. */
    private const PARKING  = 'Loading bay bay-7 only, cones out by 0700';
    private const ACCESS   = 'Goods lift 1100x2000, security pass from reception';
    private const DELIVERY = 'Deliveries via Renshaw Street ramp, 0800-1030 only';
    private const COMMS    = 'Comms room key held by Facilities, ask for Dee';
    private const TRAVEL   = 'M4 J11 then ten minutes, avoid the ring road';
    private const NOTES    = 'Surveyor general note, unchanged by this task';

    /** Ampersand in a NEW field — proves it is escaped once, never twice. */
    private const HOSTILE = 'Parking & <access> under review';

    public function test_site_survey_docx_renders_the_pm_typed_site_logistics(): void
    {
        $xml = $this->buildAndReadDocumentXml($this->fullLogistics());

        foreach ([
            'parking_restraints'      => self::PARKING,
            'site_access_notes'       => self::ACCESS,
            'delivery_routes'         => self::DELIVERY,
            'comms_room_access_notes' => self::COMMS,
            'general_notes'           => self::NOTES,
        ] as $column => $text) {
            $this->assertStringContainsString(
                $text,
                $xml,
                "word/document.xml does not contain the survey's {$column}. A PM types it into "
                .'the cockpit document form, it persists, and the generated Word document drops it.'
            );
        }
    }

    /**
     * Labels and render order are the shared ones, not a sixth spelling.
     */
    public function test_site_logistics_labels_and_order_come_from_survey_carry_forward(): void
    {
        $xml = $this->buildAndReadDocumentXml($this->fullLogistics());

        $expected = [
            'parking_restraints',
            'site_access_notes',
            'delivery_routes',
            'comms_room_access_status',
            'distance_from_base_miles',
        ];

        $positions = [];

        foreach ($expected as $key) {
            $label = SurveyCarryForward::FIELDS[$key];
            $at    = strpos($xml, $label);

            $this->assertNotFalse(
                $at,
                "word/document.xml never contains the label '{$label}' for {$key}. The writer must "
                .'use SurveyCarryForward::FIELDS labels, not its own wording.'
            );

            $positions[$label] = $at;
        }

        $sorted = $positions;
        asort($sorted);

        $this->assertSame(
            array_keys($sorted),
            array_keys($positions),
            'Site logistics rows must render in SurveyCarryForward::FIELDS order (arrival-first), '
            .'which is also the order WorksheetDocxService:160 and DocxBuilderService:701 use.'
        );
    }

    /**
     * `comms_room_access_status` stores `yes|no|outsourced|unknown`. The document
     * must print the shared label, never the raw stored token.
     */
    public function test_comms_room_access_status_renders_the_shared_label_not_the_stored_token(): void
    {
        $xml = $this->buildAndReadDocumentXml($this->fullLogistics());

        $label = SurveyCarryForward::COMMS_ROOM_LABELS['yes'];

        $this->assertStringContainsString(
            $label.' — '.self::COMMS,
            $xml,
            "Stored 'yes' must render as '{$label}' joined to the notes by SurveyCarryForward, "
            .'not as a fifth hand-rolled copy of that vocabulary (see D-46.2-04-02).'
        );
    }

    /**
     * A survey with no logistics must omit the heading and the rows entirely —
     * not print a bare "Parking arrangements:" with nothing under it.
     */
    public function test_empty_site_logistics_renders_no_heading_and_no_rows(): void
    {
        $xml = $this->buildAndReadDocumentXml(fn (array $base): array => $base);

        $this->assertStringNotContainsString(
            'Site Logistics',
            $xml,
            'A survey with no site logistics must not emit the Site Logistics heading.'
        );

        foreach ([
            'parking_restraints',
            'site_access_notes',
            'delivery_routes',
            'comms_room_access_status',
            'distance_from_base_miles',
        ] as $key) {
            $this->assertStringNotContainsString(
                SurveyCarryForward::FIELDS[$key],
                $xml,
                "Empty {$key} must render no label at all, not an empty labelled row."
            );
        }

        // Control: the fields that ARE set still render, so this is not
        // asserting an empty document.
        $this->assertStringContainsString(self::NOTES, $xml);
    }

    /**
     * Output escaping landed in quick task 260925-d65 and covers every
     * `addText()`. The new fields must ride on it rather than pre-escaping,
     * which would print a literal `&amp;` to the PM.
     */
    public function test_new_site_logistics_fields_are_escaped_exactly_once(): void
    {
        $xml = $this->buildAndReadDocumentXml(
            fn (array $base): array => $base + [
                'parking_restraints' => self::HOSTILE,
            ]
        );

        $at = strpos($xml, 'Parking &');
        $this->assertNotFalse($at, 'parking_restraints was not rendered at all.');
        $window = substr($xml, max(0, $at - 40), 180);

        $this->assertStringContainsString(
            'Parking &amp; &lt;access&gt; under review',
            $window,
            'New site-logistics text must reach word/document.xml escaped exactly once.'
        );

        $this->assertStringNotContainsString(
            '&amp;amp;',
            $window,
            'Double escaping — the writer must rely on Settings::setOutputEscapingEnabled(true) '
            .'(SiteSurveyDocxService:43) and must NOT escape by hand as well.'
        );
    }

    // -- Helpers --

    /** Every logistics column populated. */
    private function fullLogistics(): \Closure
    {
        return fn (array $base): array => $base + [
            'parking_restraints'       => self::PARKING,
            'site_access_notes'        => self::ACCESS,
            'delivery_routes'          => self::DELIVERY,
            'comms_room_access_status' => 'yes',
            'comms_room_access_notes'  => self::COMMS,
            'distance_from_base_miles' => 42,
            'distance_from_base_notes' => self::TRAVEL,
        ];
    }

    /**
     * Build the .docx through the real route, return word/document.xml, and
     * leave storage/app/site-surveys exactly as it was found.
     */
    private function buildAndReadDocumentXml(\Closure $attributes): string
    {
        // Vacuity defence — see the class docblock.
        Settings::setOutputEscapingEnabled(false);

        [$user, $survey] = $this->makeSurvey($attributes);

        $storageDir = storage_path('app/site-surveys');
        $before     = $this->docxCount($storageDir);

        $this->actingAs($user)->get(route('site-surveys.docx', $survey))->assertOk();

        $filename = $survey->fresh()->filename;
        $this->assertNotNull($filename, 'build() must record the generated filename on the survey.');

        $path = $storageDir.'/'.$filename;

        try {
            $this->assertFileExists($path);

            return $this->documentXml($path);
        } finally {
            // SiteSurveyDocxService writes to storage_path() directly, bypassing
            // DocumentArtifactStorage, so Storage::fake() does not contain it.
            if (is_file($path)) {
                unlink($path);
            }

            $this->assertSame(
                $before,
                $this->docxCount($storageDir),
                'This test must leave storage/app/site-surveys with exactly the file count it started with.'
            );
        }
    }

    private function documentXml(string $path): string
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true, "Generated .docx is not a readable ZIP: {$path}");

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        $this->assertIsString($xml, 'Generated .docx has no word/document.xml.');

        return $xml;
    }

    private function docxCount(string $dir): int
    {
        return is_dir($dir) ? count(glob($dir.'/*.docx') ?: []) : 0;
    }

    /** @return array{0: User, 1: SiteSurvey} */
    private function makeSurvey(\Closure $attributes): array
    {
        $user = User::factory()->create();

        $project = Project::create([
            'user_id'      => $user->id,
            'name'         => 'Logistics Project',
            'ref'          => 'LOG-'.fake()->numerify('###'),
            'client_name'  => 'Acme Ltd',
            'site_address' => '1 Example Way, London',
            'status'       => 'quote_imported',
        ]);

        $survey = SiteSurvey::create($attributes([
            'user_id'       => $user->id,
            'project_id'    => $project->id,
            'project_name'  => 'Logistics Survey',
            'project_ref'   => 'Q-462606',
            'client_name'   => 'Acme Ltd',
            'site_address'  => '1 Example Way, London',
            'status'        => 'draft',
            'general_notes' => self::NOTES,
        ]));

        SiteSurveyRoom::create([
            'site_survey_id' => $survey->id,
            'room_name'      => 'Boardroom',
            'sort_order'     => 0,
        ]);

        return [$user, $survey->refresh()];
    }
}
