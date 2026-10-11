<?php

namespace Tests\Unit\Support;

use App\Support\WorksheetInstallSteps;
use PHPUnit\Framework\TestCase;

/**
 * 261011-d62 Task 1 — the shape normaliser, every shape and every degenerate
 * input.
 *
 * `install_steps` has three live producers writing three different shapes
 * (see the class docblock on App\Support\WorksheetInstallSteps for the
 * citations). Two of the three used to 500 both render paths. This file pins
 * each shape's OUTPUT TEXT, not merely that it did not throw — the point of
 * the fix is that an engineer can read the steps, so the steps are asserted.
 *
 * @see \App\Support\WorksheetInstallSteps
 * @see .planning/quick/261011-d62-worksheet-install-steps-array-render/PLAN.md
 */
class WorksheetInstallStepsTest extends TestCase
{
    // ── Shape (a): newline-delimited string (the AI path) ────────────────────

    public function test_shape_a_string_splits_on_newlines_and_strips_numbering(): void
    {
        $this->assertSame(
            ['Mount the display', 'Terminate the HDMI run'],
            WorksheetInstallSteps::lines("1. Mount the display\n2. Terminate the HDMI run"),
        );
    }

    public function test_shape_a_handles_crlf_bullets_and_blank_lines(): void
    {
        $this->assertSame(
            ['Mount the display', 'Terminate the HDMI run'],
            WorksheetInstallSteps::lines("- Mount the display\r\n\r\n• Terminate the HDMI run\r\n   \r\n"),
        );
    }

    public function test_shape_a_does_not_eat_a_leading_quantity(): void
    {
        // "2x Mount …" must keep its quantity — only list numbering goes.
        $this->assertSame(
            ['2x Mount and secure Sony display'],
            WorksheetInstallSteps::lines('2x Mount and secure Sony display'),
        );
    }

    // ── Shape (b): flat array of step strings (the edit adapter) ─────────────

    public function test_shape_b_flat_array_is_preserved_in_order(): void
    {
        $this->assertSame(
            ['Install bracket', 'Hang display', 'Commission'],
            WorksheetInstallSteps::lines(['Install bracket', 'Hang display', 'Commission']),
        );
    }

    public function test_shape_b_filters_blanks_reindexes_and_splits_embedded_newlines(): void
    {
        $this->assertSame(
            ['Install bracket', 'Hang display', 'Commission'],
            WorksheetInstallSteps::lines([3 => 'Install bracket', 7 => "Hang display\nCommission", 9 => '   ', 11 => '']),
        );
    }

    // ── Shape (c): nested phase list (the generator's AI-empty fallback) ─────

    public function test_shape_c_nested_phases_flatten_carrying_their_titles(): void
    {
        $steps = [
            ['step' => 1, 'title' => 'Pre-Start & Access', 'items' => ['Confirm site access', 'Review RAMS']],
            ['step' => 2, 'title' => 'First Fix', 'items' => ['Install display brackets']],
        ];

        $this->assertSame(
            [
                'PHASE 1 — Pre-Start & Access',
                'Confirm site access',
                'Review RAMS',
                'PHASE 2 — First Fix',
                'Install display brackets',
            ],
            WorksheetInstallSteps::lines($steps),
        );
    }

    public function test_shape_c_blank_title_drops_the_heading_but_keeps_the_items(): void
    {
        $this->assertSame(
            ['Confirm site access'],
            WorksheetInstallSteps::lines([['step' => 1, 'title' => '   ', 'items' => ['Confirm site access']]]),
        );
    }

    public function test_shape_c_missing_title_drops_the_heading_but_keeps_the_items(): void
    {
        $this->assertSame(
            ['Confirm site access'],
            WorksheetInstallSteps::lines([['step' => 1, 'items' => ['Confirm site access']]]),
        );
    }

    public function test_shape_c_phase_with_no_items_contributes_nothing(): void
    {
        // A heading with its actions lost reads to an engineer as an
        // instruction they cannot follow. Drop it.
        $this->assertSame([], WorksheetInstallSteps::lines([['step' => 1, 'title' => 'First Fix', 'items' => []]]));
        $this->assertSame([], WorksheetInstallSteps::lines([['step' => 1, 'title' => 'First Fix']]));
        $this->assertSame([], WorksheetInstallSteps::lines([['step' => 1, 'title' => 'First Fix', 'items' => '  ']]));
    }

    public function test_shape_c_missing_step_number_still_labels_the_phase(): void
    {
        $this->assertSame(
            ['PHASE — First Fix', 'Install display brackets'],
            WorksheetInstallSteps::lines([['title' => 'First Fix', 'items' => ['Install display brackets']]]),
        );
    }

    public function test_shape_c_numeric_string_step_is_accepted(): void
    {
        $this->assertSame(
            ['PHASE 4 — Cabling & Termination', 'Route all signal cables'],
            WorksheetInstallSteps::lines([['step' => '4', 'title' => 'Cabling & Termination', 'items' => ['Route all signal cables']]]),
        );
    }

    public function test_shape_c_items_may_be_a_newline_string(): void
    {
        $this->assertSame(
            ['PHASE 1 — Pre-Start & Access', 'Confirm site access', 'Review RAMS'],
            WorksheetInstallSteps::lines([
                ['step' => 1, 'title' => 'Pre-Start & Access', 'items' => "1. Confirm site access\n2. Review RAMS"],
            ]),
        );
    }

    public function test_mixed_shapes_in_one_list_all_render(): void
    {
        // Defensive: a worksheet edited by the adapter AFTER the generator's
        // phased fallback persists a list carrying both kinds of entry.
        $this->assertSame(
            ['PHASE 1 — First Fix', 'Install bracket', 'Engineer-appended step'],
            WorksheetInstallSteps::lines([
                ['step' => 1, 'title' => 'First Fix', 'items' => ['Install bracket']],
                'Engineer-appended step',
            ]),
        );
    }

    public function test_doubly_wrapped_flat_array_is_flattened(): void
    {
        $this->assertSame(
            ['Install bracket', 'Hang display'],
            WorksheetInstallSteps::lines([['Install bracket', 'Hang display']]),
        );
    }

    // ── Empty / degenerate inputs — the empty state must survive ─────────────

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function emptyInputs(): array
    {
        return [
            'null'            => [null],
            'empty string'    => [''],
            'whitespace'      => ["  \n\t "],
            'empty array'     => [[]],
            'array of blanks' => [['', '   ', "\n"]],
            'false'           => [false],
            'true'            => [true],
            'object'          => [new \stdClass()],
        ];
    }

    /**
     * @dataProvider emptyInputs
     */
    public function test_degenerate_input_yields_no_lines_so_the_empty_state_survives(mixed $input): void
    {
        $this->assertSame([], WorksheetInstallSteps::lines($input));
        $this->assertSame('', WorksheetInstallSteps::text($input));
    }

    public function test_text_joins_lines_with_newlines_for_the_pre_wrap_block(): void
    {
        $this->assertSame(
            "PHASE 1 — First Fix\nInstall bracket",
            WorksheetInstallSteps::text([['step' => 1, 'title' => 'First Fix', 'items' => ['Install bracket']]]),
        );
    }

    public function test_numeric_scalar_steps_are_stringified_not_dropped(): void
    {
        $this->assertSame(['7'], WorksheetInstallSteps::lines(7));
    }

    // ── rows(): the kind tag (261011-f9l) ────────────────────────────────────
    //
    // Every assertion above pins lines()/text() and is deliberately left
    // untouched: rows() is additive and the old API must keep returning
    // byte-identical output. What follows pins the NEW information — which
    // rows are headings and which are actions — because a consumer that
    // cannot tell them apart miscounts the drawer and misnumbers the list.

    public function test_rows_tags_a_phase_title_as_a_heading_and_its_items_as_steps(): void
    {
        $this->assertSame(
            [
                ['kind' => 'heading', 'text' => 'PHASE 1 — Pre-Start & Access'],
                ['kind' => 'step',    'text' => 'Confirm site access'],
                ['kind' => 'step',    'text' => 'Review RAMS'],
                ['kind' => 'heading', 'text' => 'PHASE 2 — Second Fix'],
                ['kind' => 'step',    'text' => 'Mount the display'],
            ],
            WorksheetInstallSteps::rows([
                ['step' => 1, 'title' => 'Pre-Start & Access', 'items' => ['Confirm site access', 'Review RAMS']],
                ['step' => 2, 'title' => 'Second Fix', 'items' => ['Mount the display']],
            ]),
        );
    }

    public function test_rows_for_shape_a_are_all_steps_so_shape_a_still_numbers_one_to_n(): void
    {
        $rows = WorksheetInstallSteps::rows("1. Mount the display\n2. Terminate the HDMI run");

        $this->assertSame(
            [
                ['kind' => 'step', 'text' => 'Mount the display'],
                ['kind' => 'step', 'text' => 'Terminate the HDMI run'],
            ],
            $rows,
        );
        $this->assertCount(2, WorksheetInstallSteps::stepsOnly($rows));
    }

    public function test_rows_for_shape_b_are_all_steps_so_shape_b_still_numbers_one_to_n(): void
    {
        $rows = WorksheetInstallSteps::rows(['Install bracket', 'Hang display', 'Commission']);

        $this->assertSame(['step', 'step', 'step'], array_column($rows, 'kind'));
        $this->assertCount(3, WorksheetInstallSteps::stepsOnly($rows));
    }

    public function test_steps_only_excludes_headings_which_is_the_drawer_count(): void
    {
        // The exact arithmetic of symptom 1: 2 headings + 3 actions is THREE
        // install steps, not five.
        $rows = WorksheetInstallSteps::rows([
            ['step' => 1, 'title' => 'Pre-Start & Access', 'items' => ['One', 'Two']],
            ['step' => 2, 'title' => 'Second Fix', 'items' => ['Three']],
        ]);

        $this->assertCount(5, $rows);
        $this->assertCount(3, WorksheetInstallSteps::stepsOnly($rows));
    }

    public function test_rows_with_a_blank_title_emit_no_heading_row(): void
    {
        $this->assertSame(
            [['kind' => 'step', 'text' => 'Confirm site access']],
            WorksheetInstallSteps::rows([['step' => 1, 'title' => '   ', 'items' => ['Confirm site access']]]),
        );
    }

    public function test_rows_for_an_itemless_phase_are_empty_so_no_orphan_heading_renders(): void
    {
        $this->assertSame([], WorksheetInstallSteps::rows([['step' => 1, 'title' => 'First Fix', 'items' => []]]));
        $this->assertSame([], WorksheetInstallSteps::rows([['step' => 1, 'title' => 'First Fix']]));
    }

    public function test_rows_for_mixed_shapes_tag_only_the_real_phase_title(): void
    {
        $this->assertSame(
            [
                ['kind' => 'heading', 'text' => 'PHASE 1 — First Fix'],
                ['kind' => 'step',    'text' => 'Install bracket'],
                ['kind' => 'step',    'text' => 'Engineer-appended step'],
            ],
            WorksheetInstallSteps::rows([
                ['step' => 1, 'title' => 'First Fix', 'items' => ['Install bracket']],
                'Engineer-appended step',
            ]),
        );
    }

    public function test_rows_for_a_titleless_step_label_heading_still_has_the_heading_kind(): void
    {
        $this->assertSame(
            [
                ['kind' => 'heading', 'text' => 'PHASE — First Fix'],
                ['kind' => 'step',    'text' => 'Install display brackets'],
            ],
            WorksheetInstallSteps::rows([['title' => 'First Fix', 'items' => ['Install display brackets']]]),
        );
    }

    /**
     * @dataProvider emptyInputs
     */
    public function test_degenerate_input_yields_no_rows_either(mixed $input): void
    {
        $this->assertSame([], WorksheetInstallSteps::rows($input));
        $this->assertSame([], WorksheetInstallSteps::stepsOnly(WorksheetInstallSteps::rows($input)));
    }

    /**
     * lines() is rows()' text column, in order — the contract that lets both
     * APIs coexist without drifting.
     */
    public function test_lines_is_exactly_the_text_column_of_rows(): void
    {
        $steps = [
            ['step' => 1, 'title' => 'Pre-Start & Access', 'items' => ['Confirm site access']],
            ['step' => 2, 'title' => 'Second Fix', 'items' => ['Mount the display', 'Commission']],
        ];

        $this->assertSame(
            array_column(WorksheetInstallSteps::rows($steps), 'text'),
            WorksheetInstallSteps::lines($steps),
        );
        $this->assertSame(
            implode("\n", WorksheetInstallSteps::lines($steps)),
            WorksheetInstallSteps::text($steps),
        );
    }
}
