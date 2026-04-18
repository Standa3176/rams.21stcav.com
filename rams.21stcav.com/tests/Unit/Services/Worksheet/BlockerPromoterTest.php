<?php

namespace Tests\Unit\Services\Worksheet;

use App\Services\Worksheet\BlockerPromoter;
use PHPUnit\Framework\TestCase;

class BlockerPromoterTest extends TestCase
{
    private BlockerPromoter $p;

    protected function setUp(): void
    {
        parent::setUp();
        $this->p = new BlockerPromoter();
    }

    public function test_no_blockers_when_all_answers_are_yes(): void
    {
        $result = $this->p->promoteFromAnswers([
            'boardroom' => [
                ['question' => 'Power outlets available at display wall?', 'answer' => 'Yes'],
                ['question' => 'Network ports live at switch?',             'answer' => 'yes'],
            ],
        ]);
        $this->assertSame([], $result);
    }

    public function test_no_answer_on_power_question_produces_power_blocker(): void
    {
        $result = $this->p->promoteFromAnswers([
            'boardroom' => [
                ['question' => 'Power outlets available at display wall?', 'answer' => 'No'],
            ],
        ]);
        $this->assertCount(1, $result);
        $this->assertSame('power', $result[0]['type']);
        $this->assertSame('Boardroom', $result[0]['room']);
        $this->assertStringContainsString('Power provision unconfirmed', $result[0]['message']);
        $this->assertStringStartsWith('pre_install_', $result[0]['source']);
    }

    public function test_unknown_answer_on_network_question_produces_network_blocker(): void
    {
        $result = $this->p->promoteFromAnswers([
            'meeting room 1' => [
                ['question' => 'Ethernet port at rack patched?', 'answer' => 'unknown'],
            ],
        ]);
        $this->assertCount(1, $result);
        $this->assertSame('network', $result[0]['type']);
    }

    public function test_unrecognised_question_still_produces_generic_blocker(): void
    {
        $result = $this->p->promoteFromAnswers([
            'room 1' => [
                ['question' => 'Some novel survey question we have never seen?', 'answer' => 'No'],
            ],
        ]);
        $this->assertCount(1, $result);
        $this->assertSame('pre_install', $result[0]['type']);
    }

    public function test_idempotent_on_identical_input(): void
    {
        $input = [
            'boardroom' => [
                ['question' => 'Power outlets available?',  'answer' => 'No'],
                ['question' => 'Cable route clear?',         'answer' => 'No'],
            ],
        ];
        $this->assertSame(
            $this->p->promoteFromAnswers($input),
            $this->p->promoteFromAnswers($input),
        );
    }

    public function test_flipping_answer_to_yes_removes_blocker(): void
    {
        $base = [
            'boardroom' => [
                ['question' => 'Power outlets available?', 'answer' => 'No'],
            ],
        ];
        $this->assertNotEmpty($this->p->promoteFromAnswers($base));

        $flipped = $base;
        $flipped['boardroom'][0]['answer'] = 'Yes';
        $this->assertSame([], $this->p->promoteFromAnswers($flipped));
    }

    public function test_duplicate_similar_questions_deduped_by_type_and_message(): void
    {
        $result = $this->p->promoteFromAnswers([
            'boardroom' => [
                ['question' => 'Are power outlets available?',    'answer' => 'No'],
                ['question' => 'Is mains power available?',        'answer' => 'No'], // same rule, same room
            ],
        ]);
        $this->assertCount(1, $result);
    }

    public function test_other_answer_with_blank_other_text_raises_blocker(): void
    {
        $result = $this->p->promoteFromAnswers([
            'room 1' => [
                ['question' => 'Permit required?', 'answer' => 'other', 'other_text' => ''],
            ],
        ]);
        $this->assertCount(1, $result);
        $this->assertSame('permit', $result[0]['type']);
    }
}
