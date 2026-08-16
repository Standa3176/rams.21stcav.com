<?php

namespace Tests\Feature\ProjectPackages;

use Tests\TestCase;

/**
 * Quick task 260815-ohw — equipment multi-select bulk toolbar was
 * permanently disabled.
 *
 * ⚠️ STRUCTURAL GUARD, NOT A BEHAVIOURAL TEST. The failure this fix
 * addresses is entirely client-side Alpine.js state management inside
 * resources/views/project-packages/review.blade.php — a PHPUnit feature
 * test running through Laravel's HTTP kernel cannot execute Alpine or a
 * browser DOM, so it cannot reproduce "tick select-all, watch the button
 * stay disabled." The behavioural proof for this fix is the manual
 * browser reproduction recorded in
 * .planning/quick/20260815-equipment-multiselect-bulk/PLAN.md (live
 * instrumentation showed selectedRowIds populate to 5, DOM checkboxes
 * stay at 0 checked, canBulk('delete') return false, and the read inside
 * canBulk() destroy the array back down to 0).
 *
 * What this test CAN do, and does: assert the Blade source contains the
 * two specific code shapes the fix depends on, so a future edit that
 * silently reverts either half of the fix breaks CI instead of shipping
 * a client-only regression nobody notices until production. See
 * assertFixIsPresentAfterReverting*() below — each assertion has been
 * verified to fail when its corresponding fix is reverted.
 */
class EquipmentBulkSelectionTest extends TestCase
{
    private function reviewBladeSource(): string
    {
        $path = resource_path('views/project-packages/review.blade.php');
        $this->assertFileExists($path, 'review.blade.php must exist for this guard to mean anything.');

        return file_get_contents($path);
    }

    /**
     * Isolate a named method body out of the equipmentSection() Alpine
     * factory so assertions target the right scope instead of matching
     * anywhere in a ~2000-line file.
     */
    private function extractMethodBody(string $source, string $signature): string
    {
        $start = strpos($source, $signature);
        $this->assertNotFalse($start, "Could not find `{$signature}` in review.blade.php — has it been renamed or removed?");

        $bodyStart = strpos($source, '{', $start);
        $this->assertNotFalse($bodyStart);

        // Method bodies in this factory are consistently closed by a
        // line containing only "},\n" at the same 8-space method
        // indent — find the first one after the opening brace.
        $closeAt = strpos($source, "\n        },", $bodyStart);
        $this->assertNotFalse($closeAt, "Could not find the closing `},` for `{$signature}`.");

        return substr($source, $bodyStart, $closeAt - $bodyStart);
    }

    /**
     * Task 1 guard — toggleAllInRoom() must set `.checked` on the row
     * checkboxes directly (mirroring clearSelection()), not just mutate
     * the selectedRowIds array and hope Alpine's x-model flush wins the
     * race against the toolbar's reactive :disabled binding.
     *
     * 260816-prs — repointed from toggleAllInTbody() (category-wide select,
     * removed) to toggleAllInRoom() (per-room select, its replacement).
     *
     * Verified to FAIL if Task 1 is reverted: with the pre-fix body
     * (`if (masterCb.checked) { ... this.selectedRowIds = ... }` and no
     * `cb.checked = checked` line), this assertion does not find the
     * `.checked =` write inside the method and fails as expected.
     */
    public function test_toggle_all_in_room_drives_dom_checkboxes(): void
    {
        $body = $this->extractMethodBody(
            $this->reviewBladeSource(),
            'toggleAllInRoom(masterCb, catKey, roomName) {',
        );

        $this->assertStringContainsString(
            'cb.checked = checked',
            $body,
            'toggleAllInRoom() must set .checked on each row checkbox directly — '
                . 'relying on x-model alone lets the reactive :disabled binding read '
                . 'zero checked boxes before Alpine flushes the DOM.',
        );

        // Still respects the showDeleted / data-deleted visibility filter —
        // regression guard against a "fix" that selects hidden graveyard rows.
        $this->assertStringContainsString(
            "this.showDeleted || r.dataset.deleted !== '1'",
            $body,
            'toggleAllInRoom() must keep filtering out hidden graveyard rows.',
        );

        // 260816-prs Task 4 — must scope the query to this room, not the
        // whole category tbody. Regression guard against reintroducing the
        // category-wide blast radius this task removed.
        $this->assertStringContainsString(
            "tr[data-equip-row][data-room=",
            $body,
            'toggleAllInRoom() must scope its row query by data-room, not select '
                . 'every row in the category tbody.',
        );
    }

    /**
     * 260816-prs Task 2/5 guard — the category-wide master checkbox must
     * not exist in any <thead>. It selected every row in the whole
     * category (63 hardware rows across every room on a real quote),
     * which also made bulkSetCategory() (260815-sup) able to recategorise
     * an entire project in one click. Select-all is now per room only.
     */
    public function test_thead_no_longer_renders_a_select_all_checkbox(): void
    {
        $source = $this->reviewBladeSource();

        $this->assertStringNotContainsString(
            'toggleAllInTbody',
            $source,
            'toggleAllInTbody() must be fully removed — it was replaced by the '
                . 'room-scoped toggleAllInRoom().',
        );

        $theadStart = strpos($source, '<thead>');
        $this->assertNotFalse($theadStart, 'Could not find <thead> in review.blade.php.');
        $theadEnd = strpos($source, '</thead>', $theadStart);
        $this->assertNotFalse($theadEnd, 'Could not find closing </thead> in review.blade.php.');
        $theadBody = substr($source, $theadStart, $theadEnd - $theadStart);

        $this->assertStringNotContainsString(
            '<input type="checkbox"',
            $theadBody,
            'The category <thead> must not render a select-all checkbox — that '
                . 'control now lives per room on the room header row.',
        );
    }

    /**
     * Task 2 guard — _selectedRows() must be a pure DOM read with no
     * write-back to this.selectedRowIds. The destructive bug was this
     * method reconciling (overwriting) selectedRowIds every time
     * canBulk() called it from inside a reactive :disabled expression.
     *
     * Verified to FAIL if Task 2 is reverted: the pre-fix body contains
     * `this.selectedRowIds = domIds;` inside an `if (domIds.length !== ...)`
     * block — this assertion's assertStringNotContainsString would catch
     * that assignment and fail as expected.
     */
    public function test_selected_rows_is_a_pure_read_with_no_state_write(): void
    {
        $body = $this->extractMethodBody(
            $this->reviewBladeSource(),
            '_selectedRows() {',
        );

        $this->assertStringNotContainsString(
            'this.selectedRowIds =',
            $body,
            '_selectedRows() must never assign to this.selectedRowIds — canBulk() '
                . 'calls this from a reactive :disabled binding, so a write here '
                . 're-triggers Alpine reactivity mid-read and can wipe out a '
                . 'selection that was made moments earlier by toggleAllInRoom().',
        );

        $this->assertStringContainsString(
            'return Array.from(this.$root.querySelectorAll',
            $body,
            '_selectedRows() must still read the checked checkboxes from the DOM, '
                . 'scoped to $root NOT $el. Alpine binds $el to the element being '
                . 'evaluated, so when canBulk() is reached from the :disabled '
                . 'binding on a toolbar BUTTON, $el is that button — the query '
                . 'then searches inside the button, finds nothing, and every bulk '
                . 'action stays greyed. $root is always the component root. '
                . '(260815-ohw — verified in-browser; this exact bug survived the '
                . 'first fix attempt because canBulk() returns true when called '
                . 'manually from the root proxy but false from the binding.)',
        );

        $this->assertStringNotContainsString(
            'this.$el',
            $this->reviewBladeSource(),
            'equipmentSection() must not use this.$el anywhere — every helper is '
                . 'reachable from a reactive binding where $el is the bound element '
                . 'rather than the component root. Use this.$root throughout.',
        );
    }

    /**
     * 260725-fx1 regression guard — row-level actions must still keep
     * the checkbox + selectedRowIds in sync via _deselectRow(), since
     * Task 2 removed the reconcile that used to (incidentally) cover
     * this too.
     */
    public function test_deselect_row_still_unchecks_the_checkbox_and_updates_state(): void
    {
        $source = $this->reviewBladeSource();
        $body = $this->extractMethodBody($source, '_deselectRow(row) {');

        $this->assertStringContainsString('cb.checked = false', $body);
        $this->assertStringContainsString('this.selectedRowIds = this.selectedRowIds.filter', $body);
    }
}
