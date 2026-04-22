<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Validates a review payload before it is approved for generation.
 *
 * Rules enforced:
 *   - project_name       required
 *   - at least one equipment row with a non-empty name
 *   - quantity           integer >= 1
 *   - at least one activity row
 *   - hazards            array (may be empty — not all projects have custom hazards)
 *   - each hazard.hazard required if the hazard row exists
 *   - risk               one of Low / Medium / High
 *   - ppe                array of at least one item (safety equipment is mandatory)
 *
 * @throws \Illuminate\Validation\ValidationException on failure
 */
class RamsReviewValidatorService
{
    /**
     * Validate the given payload.
     *
     * @param  array  $payload  Canonical review schema array
     * @return array            The same $payload, passed through unchanged on success
     *
     * @throws ValidationException
     */
    public function validate(array $payload): array
    {
        $validator = Validator::make($payload, [
            'project'                        => ['required', 'array'],
            'project.project_name'           => ['required', 'string', 'max:255'],
            'project.quote_ref'              => ['nullable', 'string', 'max:100'],
            'project.client_name'            => ['nullable', 'string', 'max:255'],
            'project.site_address'           => ['nullable', 'string', 'max:500'],
            'project.site_contact'           => ['nullable', 'string', 'max:200'],
            'project.prepared_by'            => ['nullable', 'string', 'max:255'],
            'project.project_manager'        => ['nullable', 'string', 'max:200'],
            'project.lead_engineer'          => ['nullable', 'string', 'max:200'],
            'project.additional_engineers'   => ['nullable', 'string', 'max:500'],
            'project.programmer'             => ['nullable', 'string', 'max:200'],

            'equipment'                      => ['required', 'array', 'min:1'],
            'equipment.*.quantity'           => ['required', 'integer', 'min:1'],
            'equipment.*.part_number'        => ['nullable', 'string', 'max:100'],
            'equipment.*.name'               => ['required', 'string', 'min:1', 'max:1000'],
            'equipment.*.area'               => ['nullable', 'string', 'max:150'],
            'equipment.*.category'           => ['nullable', 'string', 'in:hardware,cables,consumables,services,option'],

            'activities'                     => ['required', 'array', 'min:1'],
            'activities.*.key'               => ['required', 'string', 'max:100'],
            'activities.*.label'             => ['required', 'string', 'max:255'],

            'hazards'                        => ['present', 'array'],
            'hazards.*.hazard'               => ['required', 'string', 'max:500'],
            'hazards.*.risk'                 => ['nullable', 'string', 'in:Low,Medium,High'],
            'hazards.*.control_measures'     => ['nullable', 'array'],
            'hazards.*.control_measures.*'   => ['nullable', 'string', 'max:1000'],

            'ppe'                            => ['required', 'array', 'min:1'],
            'ppe.*'                          => ['required', 'string', 'max:255'],

            'access'                         => ['present', 'array'],
            'method_statement_notes'         => ['nullable', 'string', 'max:5000'],
            'room_overviews'                 => ['present', 'array'],
            'room_overviews.*.room'          => ['required', 'string', 'max:255'],
            'room_overviews.*.overview'      => ['nullable', 'string', 'max:10000'],
            'room_overviews.*.summary'       => ['nullable', 'string', 'max:10000'],
            'room_overviews.*.description'   => ['nullable', 'string', 'max:10000'],
            'works_overview'                 => ['nullable', 'string', 'max:2000'],
        ], [
            'project.project_name.required'  => 'Project name is required.',
            'equipment.required'             => 'At least one equipment item is required.',
            'equipment.min'                  => 'At least one equipment item is required.',
            'equipment.*.name.required'      => 'Each equipment row must have a name.',
            'equipment.*.name.min'           => 'Each equipment row must have a non-empty name.',
            'equipment.*.quantity.required'  => 'Each equipment row must have a quantity.',
            'equipment.*.quantity.min'       => 'Quantity must be at least 1.',
            'activities.required'            => 'At least one activity is required.',
            'activities.min'                 => 'At least one activity is required.',
            'ppe.required'                   => 'At least one PPE item is required.',
            'ppe.min'                        => 'At least one PPE item is required.',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $payload;
    }
}
