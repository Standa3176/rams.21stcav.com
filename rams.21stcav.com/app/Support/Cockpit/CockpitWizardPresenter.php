<?php

namespace App\Support\Cockpit;

/**
 * CockpitWizardPresenter — the cockpit document form's STEP SPINE, sliced out
 * of the field map that already exists (Phase 46.5, Plan 46.5-01; 46.5-CONTEXT
 * D-02).
 *
 * PURE READING. Nothing here writes, and nothing here loads a model — this
 * class touches only `CockpitDocumentFormPresenter::documentFieldMap()`, a
 * const array. Plan 46.5-01 persists nothing at all; the creation itself is
 * Plan 46.5-06.
 *
 * ── A SIXTH CHANGE TO THIS PAGE IS A ROW EDIT ──────────────────────────
 *
 * This page's CONTENTS have now changed five times in six weeks — nine rows to
 * four, a review tab added and then unsurfaced, a field map, and now a wizard.
 * Every one of those was cheap for the same two reasons: the field set is DATA,
 * and the Blade switches on field TYPE with no document name in it. So A STEP
 * IS A ROW ON THE MAP — one `step` key per group, and one `step_titles` row per
 * document — and NOT a branch in a template or a match arm in here.
 *
 * Adding a step is therefore ONE INTEGER in
 * `CockpitDocumentFormPresenter::DOCUMENT_FIELD_MAP`. Moving a group between
 * steps is one integer changed. Taking a group off the wizard is `null`, WITH
 * THE REASON WRITTEN BESIDE IT — as the comms-room group's `step => null`
 * carries D-03's safety reason. There is no `@if` on a document key anywhere in
 * this class, and `CockpitWizardPresenterTest` asserts that by reading this
 * file's own source, so the next author cannot quietly add one.
 *
 * ── A DOCUMENT WITH NO STEPS IS NOT A DOCUMENT WITH A BROKEN WIZARD ────
 *
 * `stepsFor()` returns `[]` for a document whose groups all carry `step =>
 * null`, and everything downstream must render that document exactly as it
 * renders today. That is the contract the O&M relies on permanently, and the
 * one RAMS relies on until Plan 46.5-05 mints its step set.
 *
 * ── THIS PRESENTER NEVER INVENTS A MODULE ─────────────────────────────
 *
 * An unknown document key returns `[]` / `0` / `null` / `1` and NEVER throws —
 * the same contract `CockpitModulePresenter::progress()` and
 * `CockpitDocumentFormPresenter::fieldsFor()` already keep. Matching is exact
 * and case-sensitive: a key is never helpfully corrected.
 */
class CockpitWizardPresenter
{
    /**
     * The step a document shows when nothing usable was submitted, and the step
     * a document with no wizard always reports.
     *
     * NOT ZERO: the URL speaks in the numbers a human reads off the page, so the
     * first step is 1 and `?step=0` is as unreal as `?step=99`.
     */
    private const FIRST_STEP = 1;

    /**
     * One document's steps, ascending and distinct.
     *
     * Derived from the groups themselves rather than from a second hand-written
     * list, so a group whose step changes cannot leave a stale step behind it.
     *
     * @return array<int, int>
     */
    public function stepsFor(string $documentKey): array
    {
        $steps = [];

        foreach ($this->groupsOf($documentKey) as $group) {
            $step = $group['step'] ?? null;

            if (is_int($step) && ! in_array($step, $steps, true)) {
                $steps[] = $step;
            }
        }

        sort($steps);

        return $steps;
    }

    /**
     * The title of one step, or null.
     *
     * Read off the map's `step_titles` row, so the wizard's heading and the
     * step it heads can never come from two different authors.
     */
    public function stepTitle(string $documentKey, int $step): ?string
    {
        $titles = CockpitDocumentFormPresenter::documentFieldMap()[$documentKey]['step_titles'] ?? [];

        return $titles[$step] ?? null;
    }

    /**
     * The groups on one step, IN MAP ORDER.
     *
     * Map order is the reading order on the page, so the slice preserves it
     * rather than re-sorting by anything of its own. A group with `step =>
     * null` is returned by NO step, for ANY step number — a hand-typed number
     * is not a way back to a group a decision deliberately removed.
     *
     * @return array<int, array<string, mixed>>
     */
    public function groupsForStep(string $documentKey, int $step): array
    {
        $groups = [];

        foreach ($this->groupsOf($documentKey) as $group) {
            if (($group['step'] ?? null) === $step) {
                $groups[] = $group;
            }
        }

        return $groups;
    }

    public function stepCount(string $documentKey): int
    {
        return count($this->stepsFor($documentKey));
    }

    /**
     * The step `?step=` asked for — RESOLVED BY MEMBERSHIP, never validated.
     *
     * The identical treatment `?module=`, `?tab=` and `?action=` have had since
     * 45-11: an unrecognised value is a stale bookmark or a probe, not an error
     * worth showing a PM. It is DROPPED, never rejected — the caller discloses
     * step 1, the submitted value is never echoed, and the page is still 200.
     * `validate()` would redirect with an error bag, which is a write-shaped
     * behaviour on a read-only page.
     *
     * Matching is EXACT: `'02'` is not helpfully corrected to 2, an array is
     * not flattened, and the returned value is always a step this document
     * actually has — so nothing downstream ever builds a path, a class name or
     * a view name out of something a browser sent.
     *
     * @param  mixed  $submitted  whatever the query string held, of any type
     */
    public function resolveStep(string $documentKey, mixed $submitted): int
    {
        $steps = $this->stepsFor($documentKey);
        $first = $steps[0] ?? self::FIRST_STEP;

        if (! is_string($submitted) && ! is_int($submitted)) {
            return $first;
        }

        foreach ($steps as $step) {
            if ((string) $step === (string) $submitted) {
                return $step;
            }
        }

        return $first;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function groupsOf(string $documentKey): array
    {
        return CockpitDocumentFormPresenter::documentFieldMap()[$documentKey]['groups'] ?? [];
    }
}
