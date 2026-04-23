<?php

namespace Tests\Unit\Services;

use App\Core\Modules\Projects\ProjectDataService;
use App\Services\WorksheetDocxService;
use App\Services\WorksheetGeneratorService;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Unit tests for the Engineer Work Summary narrative builder.
 *
 * Locks the fix for the mid-sentence truncation bug that produced outputs
 * like "Work outputs: wire and commission the audio signal path, build,
 * terminate and." — sentences must remain syntactically complete, never
 * chopped mid-clause.
 *
 * Also locks the new three-sentence structure (scope / work / survey caveat)
 * and the bare-SKU heuristic used by the DOCX renderer.
 */
class WorksheetWorksDescriptionTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function invokePrivate(object $instance, string $method, array $args = []): mixed
    {
        $ref = new ReflectionMethod($instance, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($instance, $args);
    }

    private function generator(): WorksheetGeneratorService
    {
        return new WorksheetGeneratorService(Mockery::mock(ProjectDataService::class));
    }

    // ── cleanNarrative: no mid-sentence truncation ───────────────────────────

    public function test_clean_narrative_never_leaves_sentence_ending_in_and(): void
    {
        $svc = $this->generator();

        $long =
            'Room A: install 10 items covering display, video conferencing, audio, and infrastructure. '
            . 'Work covers mounting the displays, installing and commissioning the VC codec and camera, '
            . 'wiring and balancing the audio signal path, and racking, terminating, and dressing the infrastructure. '
            . 'Room not surveyed yet — confirm fixing points, cable routes, and power/network drops on arrival.';

        $out = $this->invokePrivate($svc, 'cleanNarrative', [$long, 200]);

        // The bug's signature: dangling connector before the period.
        $this->assertDoesNotMatchRegularExpression(
            '/\b(and|or|with|of|for)\.\s*$/i',
            $out,
            'cleanNarrative must not produce a sentence ending with a dangling connector.'
        );
        // And never a bare one-word sentence ending (was "Survey.", "scope.", etc).
        $this->assertDoesNotMatchRegularExpression(
            '/(?<=\.\s)\p{Lu}[\p{L}]{1,15}\.\s*$/u',
            $out,
            'cleanNarrative must not leave a single-word sentence at the tail.'
        );
        // All kept sentences must end in . ! or ?
        foreach (preg_split('/(?<=[.!?])\s+/', trim($out)) as $sentence) {
            if ($sentence === '') continue;
            $this->assertMatchesRegularExpression(
                '/[.!?]$/',
                $sentence,
                "Every sentence must be complete, got: {$sentence}"
            );
        }
    }

    public function test_clean_narrative_drops_whole_sentences_to_fit_cap(): void
    {
        $svc = $this->generator();

        $joined =
            'First sentence goes here. '
            . 'Second sentence is also important. '
            . 'Third sentence must be kept if room. '
            . 'Fourth sentence is surveyor caveat.';

        $out = $this->invokePrivate($svc, 'cleanNarrative', [$joined, 60]);

        // Fits under cap at a sentence boundary.
        $this->assertLessThanOrEqual(65, strlen($out));
        // Ends at a '.'.
        $this->assertStringEndsWith('.', $out);
        // No truncated-looking tails.
        $this->assertDoesNotMatchRegularExpression('/\b(and|or|the|a)\.\s*$/i', $out);
    }

    public function test_clean_narrative_preserves_short_input(): void
    {
        $svc = $this->generator();

        $short = 'One short sentence.';
        $out = $this->invokePrivate($svc, 'cleanNarrative', [$short, 500]);

        $this->assertSame('One short sentence.', $out);
    }

    // ── buildRoomWorksDescription: new three-part structure ──────────────────

    public function test_summary_uses_install_verb_and_category_list(): void
    {
        $svc = $this->generator();

        $subsystems = [
            'Display'            => [['name' => 'Samsung 65" UHD'], ['name' => 'Wall Mount']],
            'Video Conferencing' => [['name' => 'Cisco Room Kit EQ']],
        ];
        $hardware = array_merge($subsystems['Display'], $subsystems['Video Conferencing']);

        $out = $this->invokePrivate($svc, 'buildRoomWorksDescription', [
            'Board Room', $hardware, $subsystems, true, '',
        ]);

        $this->assertStringContainsString('Install 3 items', $out);
        $this->assertStringContainsString('Display', $out);
        $this->assertStringContainsString('Video Conferencing', $out);
        $this->assertStringNotContainsString('Key kit:', $out,     'Summary must not duplicate the kit table.');
        $this->assertStringNotContainsString('Work outputs:', $out, 'Summary must not use corporate "Work outputs:" jargon.');
    }

    public function test_summary_includes_work_sentence_with_gerunds(): void
    {
        $svc = $this->generator();

        $subsystems = [
            'Display' => [['name' => 'Samsung 65" UHD']],
            'Audio'   => [['name' => 'Q-SYS DSP']],
        ];
        $hardware = array_merge($subsystems['Display'], $subsystems['Audio']);

        $out = $this->invokePrivate($svc, 'buildRoomWorksDescription', [
            'Board Room', $hardware, $subsystems, true, '',
        ]);

        $this->assertStringContainsString('Work covers', $out);
        $this->assertStringContainsString('mounting', $out);
        $this->assertStringContainsString('wiring and balancing the audio signal path', $out);
    }

    public function test_summary_adds_survey_caveat_when_not_surveyed(): void
    {
        $svc = $this->generator();

        $subsystems = ['Display' => [['name' => 'Samsung 65"']]];
        $hardware = $subsystems['Display'];

        $out = $this->invokePrivate($svc, 'buildRoomWorksDescription', [
            'Board Room', $hardware, $subsystems, false, '',
        ]);

        $this->assertStringContainsString('Room not surveyed yet', $out);
        $this->assertStringContainsString('fixing points', $out);
    }

    public function test_summary_omits_survey_caveat_when_surveyed(): void
    {
        $svc = $this->generator();

        $subsystems = ['Display' => [['name' => 'Samsung 65"']]];
        $hardware = $subsystems['Display'];

        $out = $this->invokePrivate($svc, 'buildRoomWorksDescription', [
            'Board Room', $hardware, $subsystems, true, '',
        ]);

        $this->assertStringNotContainsString('not surveyed', strtolower($out));
    }

    public function test_summary_leads_with_source_description_when_present(): void
    {
        $svc = $this->generator();

        $subsystems = ['Display' => [['name' => 'Samsung 65"']]];
        $hardware = $subsystems['Display'];
        $source = 'Boardroom display replacement following water damage.';

        $out = $this->invokePrivate($svc, 'buildRoomWorksDescription', [
            'Board Room', $hardware, $subsystems, true, $source,
        ]);

        $this->assertStringStartsWith('Boardroom display replacement', $out);
        // Scope sentence is suppressed when source description leads.
        $this->assertStringNotContainsString('Install 1 item covering', $out);
    }

    public function test_summary_empty_room_short_message(): void
    {
        $svc = $this->generator();

        $out = $this->invokePrivate($svc, 'buildRoomWorksDescription', [
            'Empty Room', [], [], true, '',
        ]);

        $this->assertSame('No new AV equipment is scheduled in this room.', $out);
    }

    public function test_summary_uses_singular_item_when_one_hardware(): void
    {
        $svc = $this->generator();

        $subsystems = ['Display' => [['name' => 'Samsung 65"']]];
        $out = $this->invokePrivate($svc, 'buildRoomWorksDescription', [
            'Board Room', $subsystems['Display'], $subsystems, true, '',
        ]);

        $this->assertStringContainsString('Install 1 item covering', $out);
        $this->assertStringNotContainsString('Install 1 items', $out);
    }

    // ── joinNaturalPhrases: semicolons when phrases have internal commas ─────

    public function test_join_uses_semicolons_when_any_phrase_has_internal_comma(): void
    {
        $svc = $this->generator();

        $phrases = [
            'mounting the displays',
            'racking, terminating, and dressing the infrastructure',
            'wiring the audio path',
        ];
        $out = $this->invokePrivate($svc, 'joinNaturalPhrases', [$phrases]);

        // Semicolon separators, not bare commas — so phrase boundaries stay
        // visible even when a phrase contains internal commas.
        $this->assertStringContainsString(';', $out);
        $this->assertStringContainsString('; and ', $out);
    }

    public function test_join_uses_commas_for_simple_phrase_list(): void
    {
        $svc = $this->generator();

        $phrases = ['mounting the displays', 'commissioning the VC endpoint', 'testing the room'];
        $out = $this->invokePrivate($svc, 'joinNaturalPhrases', [$phrases]);

        $this->assertStringNotContainsString(';', $out);
        $this->assertStringContainsString(', and ', $out);
    }

    // ── End-to-end: regression lock for the dangling "and." bug ──────────────

    public function test_summary_for_audio_rack_room_has_no_dangling_connector(): void
    {
        $svc = $this->generator();

        // This is the exact subsystem mix that produced the buggy
        // "wire and commission the audio signal path, build, terminate and."
        // output before the fix.
        $subsystems = [
            'Audio'                 => [['name' => 'Q-SYS DSP'], ['name' => 'Amp']],
            'Rack & Infrastructure' => [['name' => 'Patch Panel']],
            'Network'               => [['name' => 'Netgear Switch']],
        ];
        $hardware = array_merge(...array_values($subsystems));

        $out = $this->invokePrivate($svc, 'buildRoomWorksDescription', [
            "Comm's Room", $hardware, $subsystems, false, '',
        ]);

        $this->assertDoesNotMatchRegularExpression(
            '/\b(and|or|with|of|for)\.\s*$/i',
            $out,
            'Summary ended with a dangling connector: ' . $out
        );
    }

    // ── WorksheetDocxService::looksLikeBareSku heuristic ─────────────────────

    public function test_looks_like_bare_sku_detects_symbol_heavy_tokens(): void
    {
        $docx = new WorksheetDocxService();

        $this->assertTrue($this->invokePrivate($docx, 'looksLikeBareSku', ['MXWAPXD2UK=-Z11']));
        $this->assertTrue($this->invokePrivate($docx, 'looksLikeBareSku', ['MXW2X/SM86=-Z11']));
        $this->assertTrue($this->invokePrivate($docx, 'looksLikeBareSku', ['UL4B/C-MTQG-A']));
        $this->assertTrue($this->invokePrivate($docx, 'looksLikeBareSku', ['LH65QETELGCXEN']));
    }

    public function test_looks_like_bare_sku_rejects_readable_names(): void
    {
        $docx = new WorksheetDocxService();

        $this->assertFalse($this->invokePrivate($docx, 'looksLikeBareSku', ['Samsung 65" UHD Display']));
        $this->assertFalse($this->invokePrivate($docx, 'looksLikeBareSku', ['Cisco Room Kit EQ']));
        $this->assertFalse($this->invokePrivate($docx, 'looksLikeBareSku', ['']));
        $this->assertFalse($this->invokePrivate($docx, 'looksLikeBareSku', ['Samsung']));
    }
}
