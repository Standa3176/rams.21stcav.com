<?php

namespace Tests\Unit\Services\Worksheet;

use App\Services\Worksheet\WorksheetRegressionDiffer;
use PHPUnit\Framework\TestCase;

class WorksheetRegressionDifferTest extends TestCase
{
    private WorksheetRegressionDiffer $d;

    protected function setUp(): void
    {
        parent::setUp();
        $this->d = new WorksheetRegressionDiffer();
    }

    public function test_empty_inputs_produce_empty_diff(): void
    {
        $r = $this->d->diff([], []);
        $this->assertSame([], $r['rooms']);
        $this->assertSame(0, $r['summary']['category_changed']);
        $this->assertSame(0, $r['summary']['added']);
        $this->assertSame(0, $r['summary']['removed']);
    }

    public function test_detects_category_change_for_same_item(): void
    {
        $old = ['rooms' => [[
            'name' => 'Boardroom',
            'subsystems' => ['Other Hardware' => [['name' => 'Chief CH-MTM1U Wall Mount']]],
        ]]];
        $new = ['rooms' => [[
            'name' => 'Boardroom',
            'subsystems' => ['Display' => [['name' => 'Chief CH-MTM1U Wall Mount']]],
        ]]];
        $r = $this->d->diff($old, $new);
        $this->assertCount(1, $r['rooms']);
        $this->assertSame(1, $r['summary']['category_changed']);
        $this->assertSame('Other Hardware', $r['rooms'][0]['category_changes'][0]['old']);
        $this->assertSame('Display',        $r['rooms'][0]['category_changes'][0]['new']);
    }

    public function test_detects_added_and_removed_items(): void
    {
        $old = ['rooms' => [[
            'name' => 'Boardroom',
            'subsystems' => ['Display' => [['name' => 'Samsung QM75B']]],
        ]]];
        $new = ['rooms' => [[
            'name' => 'Boardroom',
            'subsystems' => ['Display' => [['name' => 'Samsung QM85B']]], // QM75B removed, QM85B added
        ]]];
        $r = $this->d->diff($old, $new);
        $this->assertSame(1, $r['summary']['added']);
        $this->assertSame(1, $r['summary']['removed']);
        $this->assertSame('Samsung QM75B', $r['rooms'][0]['removed'][0]['item']);
        $this->assertSame('Samsung QM85B', $r['rooms'][0]['added'][0]['item']);
    }

    public function test_renamed_room_surfaces_in_summary(): void
    {
        $old = ['rooms' => [[
            'name' => 'Meeting Room (Main',
            'subsystems' => ['Display' => [['name' => 'X']]],
        ]]];
        $new = ['rooms' => [[
            'name' => 'Meeting Room (Main)',   // closed paren via normaliser
            'subsystems' => ['Display' => [['name' => 'X']]],
        ]]];
        $r = $this->d->diff($old, $new);
        $this->assertSame(0, $r['summary']['category_changed']);
        $this->assertNotEmpty($r['summary']['rooms_renamed']);
        $this->assertStringContainsString('→', $r['summary']['rooms_renamed'][0]);
    }

    public function test_blocker_diff_adds_and_removes(): void
    {
        $old = ['blockers' => [
            ['type' => 'power', 'room' => 'Boardroom', 'action' => 'Confirm mains'],
        ]];
        $new = ['blockers' => [
            ['type' => 'network', 'room' => 'Boardroom', 'action' => 'Confirm patching'],
        ]];
        $r = $this->d->diff($old, $new);
        $this->assertCount(1, $r['blocker_diff']['added']);
        $this->assertCount(1, $r['blocker_diff']['removed']);
        $this->assertSame('network', $r['blocker_diff']['added'][0]['type']);
        $this->assertSame('power',   $r['blocker_diff']['removed'][0]['type']);
    }

    public function test_identical_input_produces_zero_churn(): void
    {
        $gd = [
            'rooms' => [[
                'name' => 'Boardroom',
                'subsystems' => ['Display' => [['name' => 'Samsung']]],
            ]],
            'blockers' => [['type' => 'power', 'room' => 'Boardroom', 'action' => 'x']],
        ];
        $r = $this->d->diff($gd, $gd);
        $this->assertSame(0, $r['summary']['category_changed']);
        $this->assertSame(0, $r['summary']['added']);
        $this->assertSame(0, $r['summary']['removed']);
        $this->assertSame([], $r['blocker_diff']['added']);
        $this->assertSame([], $r['blocker_diff']['removed']);
    }
}
