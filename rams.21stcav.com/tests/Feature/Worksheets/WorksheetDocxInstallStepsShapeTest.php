<?php

namespace Tests\Feature\Worksheets;

use App\Models\Project;
use App\Models\User;
use App\Models\Worksheet;
use App\Services\WorksheetDocxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 261011-d62 (follow-up) — the DOCX builder survives ALL THREE `install_steps`
 * shapes, exactly as the two web surfaces now do.
 *
 * ── WHY THIS FILE EXISTS ─────────────────────────────────────────────────────
 *
 * The first pass of 261011-d62 hardened the office view and the engineer link
 * but left WorksheetDocxService.php:295 holding the identical defect:
 *
 *     $installStepsRaw = trim((string) ($room['install_steps'] ?? ''));
 *
 * On shapes (b) and (c) that raises "Array to string conversion", which
 * HandleExceptions promotes to an ErrorException (error_reporting(-1) plus a
 * throwing handler), so the DOCX build threw.
 *
 * ── WHY IT IS A LIVE PATH, NOT A THEORETICAL ONE ─────────────────────────────
 *
 * WorksheetEditAdapter is the producer of shape (b) — `append_install_step`
 * and `replace_install_step` write `array_values($steps)` at :256 / :285 — and
 * the SAME adapter rebuilds the DOCX at :373. So an engineer appending one
 * install step through the document-edit drawer wrote the array and then
 * immediately triggered the build that could not read it.
 *
 * It also breaks the premise of WorksheetShowViewParityTest, which exists to
 * stop the web view and the DOCX diverging on one payload: after the web fix
 * they diverged in the opposite direction until this was closed.
 *
 * ── WHAT IS ASSERTED ─────────────────────────────────────────────────────────
 *
 * Not "no exception" alone — that would pass on a builder that silently
 * dropped every step. Each case asserts the step text (and, for shape (c), the
 * phase titles) is present in the written .docx, and the empty case asserts no
 * stray step text leaks into it.
 *
 * @see \App\Support\WorksheetInstallSteps
 * @see .planning/quick/261011-d62-worksheet-install-steps-array-render/PLAN.md
 */
class WorksheetDocxInstallStepsShapeTest extends TestCase
{
    use RefreshDatabase;

    private const SHAPE_A = "1. Mount the Sony display centred at 1200mm AFFL\n2. Terminate the HDMI run at the table plate";

    /** @var list<string> */
    private const SHAPE_B = [
        'Install the display mounting bracket',
        'Hang and level the display',
    ];

    /** @var list<array{step:int,title:string,items:list<string>}> */
    private const SHAPE_C = [
        ['step' => 1, 'title' => 'Pre-Start & Access', 'items' => [
            'Confirm site access and working area availability for Boardroom.',
        ]],
        ['step' => 2, 'title' => 'Second Fix', 'items' => [
            'Terminate and test all structured cabling.',
        ]],
    ];

    /**
     * Worksheet::create (not ->update) so boot::creating mints access_token by
     * direct assignment — access_token is deliberately absent from $fillable
     * and must never be mass-assigned.
     */
    private function generatedData(mixed $installSteps): array
    {
        return [
            'project' => [
                'name'   => 'Docx Install Steps Fixture',
                'ref'    => '21CQ00000-01-OPS',
                'client' => 'Fixture Client',
            ],
            'rooms' => [
                [
                    'name'                   => 'Boardroom',
                    'is_surveyed'            => true,
                    'equipment'              => [],
                    'works_summary_bullets'  => [],
                    'room_works_description' => '',
                    'install_steps'          => $installSteps,
                ],
            ],
        ];
    }

    private function worksheet(): Worksheet
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        return Worksheet::create([
            'user_id'      => $user->id,
            'project_id'   => $project->id,
            'project_name' => 'Docx Install Steps Fixture',
            'project_ref'  => '21CQ00000-01-OPS',
            'client_name'  => 'Fixture Client',
            'site_address' => '1 Fixture Way, Reading RG1 1AA',
            'status'       => Worksheet::STATUS_FINAL,
        ]);
    }

    /**
     * Build the DOCX and return its document.xml as text.
     *
     * A .docx is a zip; the body text lives in word/document.xml. Reading it
     * back is what makes these assertions about CONTENT rather than about the
     * absence of an exception.
     */
    private function buildAndReadText(mixed $installSteps): string
    {
        $worksheet = $this->worksheet();
        $data      = $this->generatedData($installSteps);

        app(WorksheetDocxService::class)->build($data, $worksheet);

        $worksheet->refresh();
        $this->assertNotEmpty(
            $worksheet->filename,
            'the build must record a filename — if it did not, nothing was written and the assertions below would be vacuous'
        );

        $path = app(\App\Services\DocumentArtifactStorage::class)
            ->writePath(\App\Services\DocumentArtifactStorage::TYPE_WORKSHEET, $worksheet->filename);

        $this->assertFileExists($path);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true, 'the written .docx must be a readable zip');
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();

        $this->assertNotSame('', $xml, 'word/document.xml must not be empty');

        return $xml;
    }

    public function test_shape_a_string_reaches_the_docx(): void
    {
        $xml = $this->buildAndReadText(self::SHAPE_A);

        $this->assertStringContainsString('Mount the Sony display centred at 1200mm AFFL', $xml);
        $this->assertStringContainsString('Terminate the HDMI run at the table plate', $xml);
    }

    public function test_shape_b_flat_array_reaches_the_docx(): void
    {
        $xml = $this->buildAndReadText(self::SHAPE_B);

        $this->assertStringContainsString('Install the display mounting bracket', $xml);
        $this->assertStringContainsString('Hang and level the display', $xml);
    }

    public function test_shape_c_nested_phases_reach_the_docx_with_their_titles(): void
    {
        $xml = $this->buildAndReadText(self::SHAPE_C);

        // The items.
        $this->assertStringContainsString('Confirm site access and working area availability for Boardroom.', $xml);
        $this->assertStringContainsString('Terminate and test all structured cabling.', $xml);

        // And the titles — an engineer needs to tell one phase from another.
        // PhpWord escapes the ampersand, so match the distinctive half.
        $this->assertStringContainsString('Pre-Start', $xml);
        $this->assertStringContainsString('Second Fix', $xml);
    }

    public function test_an_empty_shape_leaks_no_step_text_into_the_docx(): void
    {
        $xml = $this->buildAndReadText('');

        $this->assertStringNotContainsString('Mount the Sony display', $xml);
        $this->assertStringNotContainsString('Pre-Start', $xml);
    }
}
