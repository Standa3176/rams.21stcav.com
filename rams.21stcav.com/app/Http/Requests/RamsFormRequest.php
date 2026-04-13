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

            // Document metadata (new fields — all nullable for backward compatibility)
            'client_contact_name'  => ['nullable', 'string', 'max:200'],
            'client_contact_email' => ['nullable', 'string', 'max:254'],
            'working_hours'        => ['nullable', 'string', 'max:200'],
            'revision'             => ['nullable', 'string', 'max:50'],
            'document_status'      => ['nullable', 'string', 'in:For Issue,For Construction,Approved,Draft'],
            'rooms_text'           => ['nullable', 'string', 'max:500'],

            // Scope item buckets (repeatable rows)
            'decommission_items'               => ['nullable', 'array'],
            'decommission_items.*.item_name'   => ['required_with:decommission_items.*', 'string', 'max:300'],
            'decommission_items.*.qty'         => ['nullable', 'string', 'max:50'],
            'decommission_items.*.notes'       => ['nullable', 'string', 'max:500'],

            'retained_items'                   => ['nullable', 'array'],
            'retained_items.*.item_name'       => ['required_with:retained_items.*', 'string', 'max:300'],
            'retained_items.*.qty'             => ['nullable', 'string', 'max:50'],
            'retained_items.*.notes'           => ['nullable', 'string', 'max:500'],

            'new_install_items'                => ['nullable', 'array'],
            'new_install_items.*.item_name'    => ['required_with:new_install_items.*', 'string', 'max:300'],
            'new_install_items.*.part_number'  => ['nullable', 'string', 'max:100'],
            'new_install_items.*.qty'          => ['nullable', 'string', 'max:50'],
            'new_install_items.*.notes'        => ['nullable', 'string', 'max:500'],
        ];
    }
}
