<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates PATCH /commissioning-items/{item}/notes payloads.
 *
 * `notes` is nullable — clearing notes to null is a legitimate operation
 * (INST-05c notes are free-text engineer memory aids, not audit-bound copy).
 * The 2000-char cap matches the `TEXT`-compatible cap used elsewhere in
 * commissioning data entry and prevents log spam from rogue clients.
 *
 * Controller-level abort_if handles ownership authorisation.
 */
class UpdateCommissioningItemNotesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
