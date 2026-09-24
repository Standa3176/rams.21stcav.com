<?php

namespace App\Http\Requests;

use App\Http\Controllers\ProjectCockpitController;
use App\Support\Cockpit\CockpitDocumentFormPresenter;
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
 */
final class CockpitDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
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
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $map = CockpitDocumentFormPresenter::documentFieldMap();

        $rules = [
            'module' => ['required', 'string', Rule::in(array_keys($map))],
            'format' => ['required', 'string'],
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

        $rules['format'] = ['required', 'string', Rule::in($offered)];

        foreach ($map[$module]['groups'] as $group) {
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
        ];
    }
}
