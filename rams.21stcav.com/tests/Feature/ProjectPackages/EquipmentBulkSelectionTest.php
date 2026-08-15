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
     * Task 1 guard — toggleAllInTbody() must set `.checked` on the row
     * checkboxes directly (mirroring clearSelection()), not just mutate
     * the selectedRowIds array and hope Alpine's x-model flush wins the
     * race against the toolbar's reactive :disabled binding.
     *
     * Verified to FAIL if Task 1 is reverted: with the pre-fix body
     * (`if (masterCb.checked) { ... this.selectedRowIds = ... }` and no
     * `cb.checked = checked` line), this assertion does not find the
     * `.checked =` write inside the method and fails as expected.
     */
    public function test_toggle_all_in_tbody_drives_dom_checkboxes(): void
    {
        $body = $this->extractMethodBody(
            $this->reviewBladeSource(),
            'toggleAllInTbody(masterCb, catKey) {',
        );

        $this->assertStringContainsString(
            'cb.checked = checked',
            $body,
            'toggleAllInTbody() must set .checked on each row checkbox directly — '
                . 'relying on x-model alone lets the reactive :disabled binding read '
                . 'zero checked boxes before Alpine flushes the DOM.',
        );

        // Still respects the showDeleted / data-deleted visibility filter —
        // regression guard against a "fix" that selects hidden graveyard rows.
        $this->assertStringContainsString(
            "this.showDeleted || r.dataset.deleted !== '1'",
            $body,
            'toggleAllInTbody() must keep filtering out hidden graveyard rows.',
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
                . 'selection that was made moments earlier by toggleAllInTbody().',
        );

        $this->assertStringContainsString(
            'return Array.from(this.$el.querySelectorAll',
            $body,
            '_selectedRows() must still read the checked checkboxes from the DOM.',
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
