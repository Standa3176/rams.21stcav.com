<?php

namespace App\Http\Requests;

use App\Http\Controllers\ProjectCockpitController;
use App\Support\Cockpit\CockpitDocumentFormPresenter;
use App\Support\Cockpit\CockpitWizardPresenter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * CockpitDocumentRequest — the cockpit document form's server-side validation,
 * BUILT FROM `DOCUMENT_FIELD_MAP` (Phase 46.2, Plan 46.2-05).
 *
 * ── ONE SOURCE OF RULES, NOT TWO ───────────────────────────────────────────
 *
 * Every rule below is read out of `CockpitDocumentFormPresenter`'s own map.
 * There is deliberately no hand-written second list: a hand-written list would
 * drift from the map on the first field added, and the drift would be silent —
 * an input on the page with no rule behind it. So the map is the source of the
 * FIELDS, of their TYPES, and of their RULES, and this class is the projection.
 *
 * ── THE MODULE IS RESOLVED BEFORE ANYTHING ELSE (T-46.2-12) ────────────────
 *
 * `module` is validated with `Rule::in(array_keys(documentFieldMap()))` and, if
 * it does not resolve, NO field rules are added at all. Nothing is ever built
 * from the submitted string — no filesystem path, no class name, no view name —
 * so `../../etc/passwd` is a validation message and never a 500 and never a
 * file read. This is the same shape
 * `CockpitCreateVisitTest::test_a_visit_type_the_module_does_not_allow_is_rejected()`
 * already proves for the visit route.
 *
 * ── THE FORMAT SET IS PER DOCUMENT, BECAUSE ONE CELL IS MISSING ────────────
 *
 * `format` is validated against the keys of THAT document's `formats` entry
 * whose route is non-null. The worksheet's `pdf` cell is `null` (DC-07, NOT
 * DELIVERED), so `format=pdf` on the worksheet is a validation error server-side
 * as well as an absent radio on the panel. Saying it in one place only would
 * mean a hand-crafted POST could ask for a document that cannot be rendered.
 *
 * ── A DISPLAY-ONLY FIELD IS `prohibited`, ON PURPOSE ──────────────────────
 *
 * The map gives every field the PROJECT already answers the rule
 * `['prohibited']` (46.2-04, D-46.2-04-04). Submitting one is a failure rather
 * than a silent overwrite of something the app knows. The Blade therefore
 * renders those fields as TEXT and not as inputs — a `readonly` input still
 * submits, and would trip this rule on an ordinary submission.
 *
 * ── THE WIZARD'S TWO NEW INPUTS (Phase 46.5, Plan 46.5-04; GCW-02/GCW-03) ──
 *
 * `intent` is one of `next`, `back`, `create`, and it decides whether this
 * request is a STEP ADVANCE or THE CREATION. An unrecognised value is a
 * validation failure that writes nothing and is never echoed (T-46.5-04-01) —
 * it is deliberately NOT treated as `create`, because "I did not understand
 * what you asked for, so I created the document" is the worst of the three
 * outcomes. An ABSENT value defaults to `create`, which is the same courtesy
 * `tab` already gets and is what keeps every caller written before this plan
 * behaving exactly as it did.
 *
 * `step` is resolved by MEMBERSHIP against `CockpitWizardPresenter::stepsFor()`
 * — the document's own step list — and an unreal value falls back to the first
 * step rather than being rejected. Identical to `?step=` on the GET (Plan
 * 46.5-01), for the identical reason: a stale bookmark or a probe is not an
 * error worth showing a PM, and the resolved value is always a step the
 * document actually has.
 *
 * ── THE RULES NARROW TO THE CURRENT STEP, BUT ONLY ON AN ADVANCE ───────────
 *
 * On `next`/`back` only the CURRENT step's fields carry their rules, so a PM
 * does not reach step 3 to learn step 1 was wrong and is not refused step 1
 * for a step-3 field they have not seen. On `create` EVERY step's rules are
 * added, exactly as before this plan — which is what makes a carried-forward
 * hidden input safe (T-46.5-04-02, T-46.5-04-03). A hidden field is as
 * attacker-controlled as a visible one, so the final submit re-validates all
 * of them and a hand-crafted POST claiming `step=1` cannot skip step 2's rules.
 *
 * `format` is required on `create` only: the format radios live on the LAST
 * step, so an advance from step 1 has none to send.
 */
final class CockpitDocumentRequest extends FormRequest
{
    /**
     * The five things this form can be asking for. A closed set, matched
     * exactly — never a prefix, never case-insensitively.
     *
     * THREE BECAME FIVE IN QUICK TASK 260930-sv2. `spaces-all` and
     * `spaces-none` are the no-JavaScript select-all for the "Spaces being
     * surveyed" step: each re-renders THE SAME step with the tick state
     * rewritten, so an 18-space project is one press away from "none, then the
     * one I want" instead of seventeen manual unticks.
     *
     * THEY REUSE THE `intent` NAME ON PURPOSE. The alternative — a companion
     * checkbox — was considered and REJECTED at 46.5-06 (see the comment above
     * `$spaceTicked` in `components/cockpit/doc-form.blade.php`) precisely
     * because it would put a SECOND control name on the form. Two more submit
     * buttons on the name that already carries `next`/`back` adds none, and the
     * cockpit's ban on `<script` and on the nine handler attributes is
     * untouched: this is a form POST, like every other act on the page.
     *
     * NEITHER WRITES ANYTHING. Both land in `advance()`, which the row-count
     * test `test_a_step_advance_writes_no_row_in_any_of_the_six_tables()`
     * already fences, so the GCW-03 "a half-finished wizard persists nothing"
     * guarantee extends to them for free rather than needing its own proof.
     *
     * @var array<int, string>
     */
    public const INTENTS = ['next', 'back', 'create', 'spaces-all', 'spaces-none', 'regenerate-document'];

    /** The two intents that rewrite the space ticks instead of changing step. */
    public const SPACE_INTENTS = ['spaces-all', 'spaces-none'];

    /** The intent an absent value means, so every pre-46.5 caller is unchanged. */
    public const INTENT_CREATE = 'create';

    /**
     * SITE-SURVEY-ONLY, DOCUMENT-ONLY, NO VISIT AND NO LINK (quick task
     * 260930-qcy). The action a PM was actually reaching for when a
     * pre-existing survey visit made a plain retry collide: update the
     * survey's answered fields and rebuild its Word/PDF without ever
     * attempting `VisitLinkIssuer::issue()`.
     */
    public const INTENT_REGENERATE = 'regenerate-document';

    public function authorize(): bool
    {
        return auth()->check();
    }

    /** The resolved intent — always one of `INTENTS`, or the submitted rubbish. */
    public function intent(): string
    {
        $intent = $this->input('intent');

        return is_string($intent) ? $intent : self::INTENT_CREATE;
    }

    /** The resolved step. Always a step this document has (or 1). */
    public function step(): int
    {
        return app(CockpitWizardPresenter::class)
            ->resolveStep((string) $this->input('module'), $this->input('step'));
    }

    /**
     * An unreal `tab` is DROPPED, never rejected.
     *
     * This follows the ruling `CockpitTabPreservationTest` already records for
     * the four visit acts: "a bad tab is not a reason to refuse a PM's
     * acceptance". Generating a document is the PM's intent; which tab they came
     * from is a courtesy. So a hostile value falls back to the tabless behaviour
     * instead of failing a submission the PM cannot then fix — and because it is
     * dropped here, it can never be reflected anywhere.
     */
    protected function prepareForValidation(): void
    {
        $tab = $this->input('tab');

        if (! is_string($tab) || ! in_array($tab, ProjectCockpitController::TABS, true)) {
            $this->request->remove('tab');
            $this->replace($this->all());
        }

        // AN ABSENT `intent` IS `create`, so every caller and every test written
        // before Plan 46.5-04 submits exactly what it always did. A PRESENT but
        // unrecognised one is left alone on purpose — `rules()` then rejects it,
        // rather than this method quietly turning `intent=rubbish` into a
        // document creation.
        if ($this->input('intent') === null) {
            $this->merge(['intent' => self::INTENT_CREATE]);
        }

        // MEMBERSHIP, NOT VALIDATION. `?step=9`, `?step=abc` and `?step[]=1`
        // all resolve to the document's first step; the submitted value never
        // survives this line, so nothing downstream can echo it or build
        // anything out of it.
        $this->merge(['step' => $this->step()]);

        // AN ABSENT `format` IS THE DOCUMENT'S FIRST OFFERED FORMAT, and only
        // an ABSENT one. The Format radios were removed from the last step
        // (2026-09-27, the user's item 8): the creation already produces the
        // engineer link, the Word document AND the PDF together, so the radio
        // asked a question whose answer changed nothing but a flash word.
        //
        // The default is read from the map's OWN `formats`, never hard-coded,
        // so no format is invented here and the worksheet — which has no PDF
        // (DC-07) — still defaults to the only thing it has. A PRESENT but
        // unoffered `format` is LEFT ALONE and `rules()` rejects it, exactly as
        // `intent` above: a posted `format=pdf` on the worksheet is still a
        // validation error.
        if ($this->input('format') === null) {
            $module = $this->input('module');
            $map    = CockpitDocumentFormPresenter::documentFieldMap();

            if (is_string($module) && array_key_exists($module, $map)) {
                $offered = array_keys(array_filter(
                    $map[$module]['formats'],
                    static fn (?string $routeName): bool => $routeName !== null,
                ));

                if ($offered !== []) {
                    $this->merge(['format' => $offered[0]]);
                }
            }
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $map = CockpitDocumentFormPresenter::documentFieldMap();

        $isCreate = $this->intent() === self::INTENT_CREATE;

        $rules = [
            'module' => ['required', 'string', Rule::in(array_keys($map))],
            // THE WIZARD'S OWN TWO. `intent` is a closed set (T-46.5-04-01) and
            // `step` has already been membership-resolved, so this rule is the
            // belt to that braces rather than the guard itself.
            'intent' => ['required', 'string', Rule::in(self::INTENTS)],
            'step'   => ['nullable', 'integer'],
            // Required on the CREATION only: the format radios render on the
            // last step, so a step advance has none to send.
            'format' => $isCreate ? ['required', 'string'] : ['nullable', 'string'],
            // The tab the generation was initiated FROM, on the same mechanism
            // and against the same constant the four visit acts use
            // (`x-cockpit.tab-field`, Plan 46.1-06). Membership-resolved on the
            // way in as well as on the way out, so a value that is not a real
            // tab never reaches a redirect and is never reflected.
            'tab'    => ['nullable', 'string', Rule::in(ProjectCockpitController::TABS)],
        ];

        $module = $this->input('module');

        // THE GATE. An unresolved module contributes no field rules, so nothing
        // below this line is ever reached with an untrusted key.
        if (! is_string($module) || ! array_key_exists($module, $map)) {
            return $rules;
        }

        $offered = array_keys(array_filter(
            $map[$module]['formats'],
            static fn (?string $routeName): bool => $routeName !== null,
        ));

        $rules['format'] = $isCreate
            ? ['required', 'string', Rule::in($offered)]
            : ['nullable', 'string', Rule::in($offered)];

        // WIDENED, ON PURPOSE, TO MATCH `create` (quick task 260930-qcy).
        // `regenerate-document` can carry edits from ANY earlier step as
        // hidden inputs — the whole point of Test A in the quick task's
        // plan — and must re-validate (and thereby actually RECEIVE) every
        // one of them, not only the submitting step's. Kept as its own
        // local rather than folding into `$isCreate` above: `format` stays
        // `nullable` for this intent exactly as for any non-`create` one.
        $wantsEveryGroup = $isCreate || $this->intent() === self::INTENT_REGENERATE;

        foreach ($this->groupsToValidate($module, $wantsEveryGroup) as $group) {
            foreach ($group['fields'] as $field) {
                $rules[$field['key']] = $field['rules'];

                // A list field's MEMBERS are bounded too. The three
                // `resource-list` fields carry LabourResource names (LR-04), so
                // the member rule is a short string and never an id, an email or
                // a phone.
                if (in_array('array', $field['rules'], true)) {
                    $rules[$field['key'].'.*'] = ['string', 'max:150'];
                }
            }
        }

        return $rules;
    }

    /**
     * WHICH GROUPS' RULES THIS REQUEST CARRIES, AND WHY THE TWO CASES DIFFER.
     *
     * ON `create` — EVERY group, exactly as before Plan 46.5-04. This is the
     * line that makes the wizard's hidden carry-forward inputs safe: they are
     * attacker-controlled, so they are RE-VALIDATED on the final submit rather
     * than trusted because an earlier step validated them. A hand-crafted POST
     * claiming `step=1` therefore cannot skip step 2's rules (T-46.5-04-02),
     * and a forged `readonly` field still trips `prohibited` (T-46.5-04-03).
     *
     * ON `regenerate-document` (quick task 260930-qcy) — the SAME as `create`:
     * every group, never only the submitting step's. This action can carry
     * edits from ANY earlier step as hidden inputs, and narrowing to the
     * current step's rules would mean `FormRequest::validated()` silently
     * drops every earlier step's field — the exact gap that would make the
     * regenerate action fail to save the very edits it exists to save. The
     * caller passes `true` for the SAME reason `create` does; the parameter
     * is still named `$isCreate` for the create case's own history, but a
     * `true` here means "every group", not "this is a create".
     *
     * ON `next`/`back` — the CURRENT step's groups only, so a PM never reaches
     * step 3 to learn step 1 was wrong, and is never refused step 1 for a field
     * they have not been shown. Safe because an advance PERSISTS NOTHING: the
     * narrower rule set guards a request that writes no row.
     *
     * A document with NO steps falls back to every group, because a stepless
     * document has no current step to narrow to and must behave exactly as it
     * did before this plan.
     *
     * @return array<int, array<string, mixed>>
     */
    private function groupsToValidate(string $module, bool $isCreate): array
    {
        $wizard = app(CockpitWizardPresenter::class);
        $all    = CockpitDocumentFormPresenter::documentFieldMap()[$module]['groups'];

        if ($isCreate || $wizard->stepsFor($module) === []) {
            return $all;
        }

        return $wizard->groupsForStep($module, $this->step());
    }

    /**
     * The map's labels, so a message names the field the PM can see rather than
     * its storage key.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $map    = CockpitDocumentFormPresenter::documentFieldMap();
        $module = $this->input('module');

        if (! is_string($module) || ! array_key_exists($module, $map)) {
            return [];
        }

        $labels = [];

        foreach ($map[$module]['groups'] as $group) {
            foreach ($group['fields'] as $field) {
                $labels[$field['key']] = strtolower($field['label']);
            }
        }

        return $labels;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'module.in'       => 'That document is not one this page produces.',
            'module.required' => 'Choose a document to generate.',
            'format.in'       => 'That format is not available for this document.',
            // NAMES NO SUBMITTED VALUE. The message says what is wrong without
            // echoing what was sent (T-46.5-04-06).
            'intent.in'       => 'That is not something this form can do.',
            'intent.required' => 'That is not something this form can do.',
        ];
    }
}
