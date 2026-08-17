<?php

namespace Tests\Feature\Rams;

use App\Models\Project;
use App\Models\RamsDocument;
use App\Models\User;
use App\Services\DocxBuilderService;
use App\Support\Rams\EquipmentScheduleFallback;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Quick task 260817-r5e Item 2a — the equipment-schedule FALLBACK must not
 * claim more than the data supports.
 *
 * The fallback fires whenever `scope_items` is entirely empty, which happens
 * routinely because RamsFormRequest declares the scope buckets `nullable`.
 * In 21CQ30960-OPS Rev 1.0 that single branch produced three defects at once:
 *
 *   1. Every row banner-headed "NEW INSTALLATION" — including kit being
 *      RECOVERED from the Willen decommission. That instructs an engineer to
 *      install equipment that is being stripped out of another room.
 *   2. The 75" display + mount and the 55" each emitted twice, because the
 *      quote lists the same item under two areas.
 *   3. "Hardware" — a category name — printed in the "Room / Area" column.
 *
 * The fixture below reproduces all three. Assertions cover the normaliser and
 * BOTH PDF templates plus the DOCX, because `pdf.rams` (not `pdf.rams-v2`) is
 * the live renderer while RAMS_UNIFIED_COMPOSER is false.
 *
 * @see app/Support/Rams/EquipmentScheduleFallback.php
 */
class EquipmentScheduleFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * A quote that reproduces the 21CQ30960 shape: a duplicated display, a
     * duplicated mount, a category name where a room should be, and one item
     * that is genuinely in two different rooms.
     */
    private function lineItems(): array
    {
        return [
            ['sku' => '', 'qty' => 1, 'description' => 'Samsung 75" QM75B display', 'room' => 'Hardware'],
            ['sku' => '', 'qty' => 1, 'description' => 'Samsung 75" QM75B display', 'room' => 'Hardware'],
            ['sku' => '', 'qty' => 1, 'description' => 'Vogels display wall mount',  'room' => 'Hardware'],
            ['sku' => '', 'qty' => 1, 'description' => 'Vogels display wall mount',  'room' => 'Hardware'],
            ['sku' => '', 'qty' => 2, 'description' => 'Rally mic pod (graphite)',   'room' => 'GND Meeting Room'],
            ['sku' => '', 'qty' => 3, 'description' => 'Rally mic pod (white)',      'room' => 'CV Room'],
        ];
    }

    // ══════════════════════════════════════════════════════════════════════
    // Normaliser
    // ══════════════════════════════════════════════════════════════════════

    public function test_duplicate_items_are_grouped_with_quantities_summed(): void
    {
        $rows = EquipmentScheduleFallback::rows($this->lineItems());

        $this->assertCount(4, $rows,
            '260817-r5e 2a: 6 raw rows carrying 2 duplicate pairs must collapse to 4.');

        $byItem = [];
        foreach ($rows as $row) {
            $byItem[$row['item']] = $row;
        }

        $this->assertSame('2', $byItem['Samsung 75" QM75B display']['qty'],
            '260817-r5e 2a: duplicate rows must be summed, not dropped — the engineer still needs the true count.');
        $this->assertSame('2', $byItem['Vogels display wall mount']['qty']);
    }

    public function test_the_same_item_in_two_different_rooms_stays_two_rows(): void
    {
        $rows = EquipmentScheduleFallback::rows([
            ['qty' => 1, 'description' => 'Samsung 75" QM75B display', 'room' => 'Boardroom'],
            ['qty' => 1, 'description' => 'Samsung 75" QM75B display', 'room' => 'Reception'],
        ]);

        $this->assertCount(2, $rows,
            '260817-r5e 2a: two rooms is real information — de-duplication must not merge across rooms.');
    }

    public function test_a_category_name_is_not_printed_as_a_room(): void
    {
        $rows = EquipmentScheduleFallback::rows($this->lineItems());

        foreach ($rows as $row) {
            $this->assertNotSame('Hardware', $row['area'],
                '260817-r5e 2a: "Hardware" is a category, not a room — the cell must be blank instead.');
        }

        $areas = array_column($rows, 'area');
        $this->assertContains('GND Meeting Room', $areas,
            'Real room names must survive — otherwise this test would pass by blanking everything.');
    }

    public function test_no_row_claims_an_activity_it_cannot_know(): void
    {
        $rows = EquipmentScheduleFallback::rows($this->lineItems());

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame(EquipmentScheduleFallback::ACTIVITY_LABEL, $row['activity'],
                '260817-r5e 2a: the scope buckets are empty — the generator does not know the activity and must not assert one.');
        }
        $this->assertStringNotContainsStringIgnoringCase('new install', EquipmentScheduleFallback::ACTIVITY_LABEL);
        $this->assertStringNotContainsStringIgnoringCase('new install', EquipmentScheduleFallback::SECTION_LABEL);
    }

    public function test_non_numeric_quantities_are_preserved_rather_than_invented(): void
    {
        $rows = EquipmentScheduleFallback::rows([
            ['qty' => 'Lot', 'description' => 'Containment sundries', 'room' => 'Boardroom'],
            ['qty' => 'Lot', 'description' => 'Containment sundries', 'room' => 'Boardroom'],
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame('Lot', $rows[0]['qty'],
            '260817-r5e 2a: a non-numeric quantity must be passed through, not coerced into a fabricated total.');
    }

    // ══════════════════════════════════════════════════════════════════════
    // Renderers — DOCX + both PDF templates
    // ══════════════════════════════════════════════════════════════════════

    private function fixtureRecord(): RamsDocument
    {
        $user    = User::factory()->create(['name' => 'Sonny Tanda']);
        $project = Project::factory()->create(['user_id' => $user->id, 'name' => 'Fallback Fixture']);

        return RamsDocument::factory()->create([
            'user_id'        => $user->id,
            'project_id'     => $project->id,
            'project_name'   => 'Fallback Fixture',
            'project_ref'    => '21CQ00000-00-OPS',
            'client_name'    => 'Fixture Client Ltd',
            'site_address'   => '1 Test Street, London',
            'form_data'      => [],
            'generated_data' => [
                'project' => [
                    'name'         => 'Fallback Fixture',
                    'ref'          => '21CQ00000-00-OPS',
                    'client'       => 'Fixture Client Ltd',
                    'site_address' => '1 Test Street, London',
                ],
                'team'             => [['role' => 'Project Manager', 'name' => 'Sonny']],
                'hazards'          => [],
                'method_statement' => ['phases' => []],
                // No scope_items at all — this is what triggers the fallback.
                'quote'            => ['line_items' => $this->lineItems()],
            ],
            'reviewed_data'  => null,
            'status'         => RamsDocument::STATUS_COMPLETED,
        ]);
    }

    public function test_docx_fallback_makes_no_new_installation_claim(): void
    {
        $record = $this->fixtureRecord();

        $path = app(DocxBuilderService::class)->build($record->generated_data ?? [], $record->fresh());
        $this->assertFileExists($path);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true, 'Failed to open generated DOCX.');
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        $this->assertIsString($xml);

        // Proves we are actually in the fallback branch, not asserting against
        // a document that never rendered the schedule at all.
        $this->assertStringContainsString('Samsung 75', $xml,
            'Fixture did not reach the equipment schedule — the assertions below would prove nothing.');

        $this->assertStringNotContainsString('NEW INSTALLATION', $xml,
            '260817-r5e 2a: DOCX fallback still banner-heads reused kit "NEW INSTALLATION".');
        $this->assertStringNotContainsString('New Installation', $xml,
            '260817-r5e 2a: DOCX fallback still labels the Activity column "New Installation".');
        $this->assertStringContainsString(EquipmentScheduleFallback::ACTIVITY_LABEL, $xml,
            '260817-r5e 2a: DOCX fallback missing the neutral activity label.');

        // "Hardware" must not appear as the Notes/area cell.
        $this->assertStringNotContainsString('<w:t>Hardware</w:t>', $xml,
            '260817-r5e 2a: the category name "Hardware" is still printed as an area.');

        // Duplicate rows collapsed: the display name appears once.
        $this->assertSame(1, substr_count($xml, 'Samsung 75'),
            '260817-r5e 2a: the duplicated display is still emitted twice.');
    }

    /** @dataProvider pdfTemplateProvider */
    public function test_pdf_fallback_makes_no_new_installation_claim(string $view): void
    {
        $record = $this->fixtureRecord();

        $html = view($view, [
            'rams'  => $record,
            'data'  => $record->generated_data,
            // rams-v2 additionally wants a DTO + theme; compose them lazily so
            // the same test body covers both templates.
            'dto'   => $view === 'pdf.rams-v2'
                ? app(\App\Support\Rams\RamsDocumentComposer::class)->compose($record)
                : null,
            'theme' => $view === 'pdf.rams-v2' ? app(\App\Support\Rams\RamsTheme::class) : null,
        ])->render();

        $this->assertStringContainsString('Samsung 75', $html,
            "{$view}: fixture did not reach the equipment schedule — the assertions below would prove nothing.");

        $this->assertStringNotContainsString('NEW INSTALLATION', $html,
            "260817-r5e 2a: {$view} fallback still banner-heads reused kit \"NEW INSTALLATION\".");
        $this->assertStringContainsString(EquipmentScheduleFallback::ACTIVITY_LABEL, $html,
            "260817-r5e 2a: {$view} fallback missing the neutral activity label.");
        $this->assertStringNotContainsString('<td>Hardware</td>', $html,
            "260817-r5e 2a: {$view} still prints the category name \"Hardware\" in the Room / Area column.");
        $this->assertSame(1, substr_count($html, 'Samsung 75'),
            "260817-r5e 2a: {$view} still emits the duplicated display twice.");
    }

    public static function pdfTemplateProvider(): array
    {
        return [
            'legacy template (live while RAMS_UNIFIED_COMPOSER=false)' => ['pdf.rams'],
            'unified template'                                        => ['pdf.rams-v2'],
        ];
    }
}
