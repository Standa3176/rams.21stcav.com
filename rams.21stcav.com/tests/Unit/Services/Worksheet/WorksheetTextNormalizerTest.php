<?php

namespace Tests\Unit\Services\Worksheet;

use App\Services\Worksheet\WorksheetTextNormalizer;
use PHPUnit\Framework\TestCase;

class WorksheetTextNormalizerTest extends TestCase
{
    private WorksheetTextNormalizer $n;

    protected function setUp(): void
    {
        parent::setUp();
        $this->n = new WorksheetTextNormalizer();
    }

    public function test_fixes_exisiting_typo_lowercase(): void
    {
        $this->assertSame('utilise existing client screen', $this->n->normalize('utilise exisiting client screen'));
    }

    public function test_fixes_exisiting_typo_preserves_sentence_case(): void
    {
        $this->assertSame('Existing rack reused', $this->n->normalize('Exisiting rack reused'));
    }

    public function test_collapses_whitespace(): void
    {
        $this->assertSame('Meeting Room 1', $this->n->normalize("  Meeting   Room\t1  "));
    }

    public function test_closes_unmatched_paren(): void
    {
        $this->assertSame('Meeting Room (Ground Floor)', $this->n->normalize('Meeting Room (Ground Floor'));
    }

    public function test_matched_parens_untouched(): void
    {
        $this->assertSame('Boardroom (Level 3)', $this->n->normalize('Boardroom (Level 3)'));
    }

    public function test_straightens_curly_apostrophes(): void
    {
        $this->assertSame("Comm's Room", $this->n->normalize("Comm\u{2019}s Room"));
    }

    public function test_empty_input_returns_empty(): void
    {
        $this->assertSame('', $this->n->normalize(''));
        $this->assertSame('', $this->n->normalize(null));
    }

    public function test_normalize_tree_applies_recursively(): void
    {
        $input = [
            'room'    => 'Meeting Room (Main',
            'notes'   => 'Exisiting kit retained',
            'items'   => [
                ['name' => '  Samsung   Display  '],
            ],
        ];
        $out = $this->n->normalizeTree($input);
        $this->assertSame('Meeting Room (Main)',      $out['room']);
        $this->assertSame('Existing kit retained',    $out['notes']);
        $this->assertSame('Samsung Display',          $out['items'][0]['name']);
    }
}
