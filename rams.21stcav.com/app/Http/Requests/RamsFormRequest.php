<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RamsFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Auth enforced at route level via middleware
    }

    public function rules(): array
    {
        return [
            // Project metadata
            'project_ref'         => ['nullable', 'string', 'max:100'],
            'project_name'        => ['required', 'string', 'max:200'],
            'client_name'         => ['required', 'string', 'max:200'],
            'site_address'        => ['required', 'string'],
            'site_contact'        => ['nullable', 'string', 'max:200'],
            'start_date'          => ['nullable', 'string', 'max:100'],
            'expected_duration'   => ['nullable', 'string', 'max:100'],
            'works_description'   => ['required', 'string', 'min:20'],
            'project_manager'     => ['nullable', 'string', 'max:200'],
            'lead_engineer'       => ['nullable', 'string', 'max:200'],
            'additional_engineers'=> ['nullable', 'string', 'max:500'],
            'programmer'          => ['nullable', 'string', 'max:200'],

            // AI
            'ai_provider'         => ['nullable', 'string', 'in:claude,openai'],

            // Hazards (custom hazards are submitted as additional hazards[] entries via JS)
            'hazards'             => ['required', 'array', 'min:1'],
            'hazards.*'           => ['string'],

            // PPE
            'ppe'                 => ['required', 'array', 'min:1'],
            'ppe.*'               => ['string'],

            // Persons at risk
            'persons_at_risk'     => ['required', 'array', 'min:1'],
            'persons_at_risk.*'   => ['string'],

            // Site team
            'team'                => ['nullable', 'array'],
            'team.*.name'         => ['nullable', 'string'],
            'team.*.role'         => ['nullable', 'string'],
            'team.*.mobile'       => ['nullable', 'string'],

            // Emergency & document info
            'emergency_contact'   => ['nullable', 'string'],
            'emergency_tel'       => ['nullable', 'string'],
            'doc_author'          => ['nullable', 'string'],

            // Project linkage (optional — set when creating from project show page)
            'project_id'          => ['nullable', 'integer', 'exists:projects,id'],
        ];
    }
}
