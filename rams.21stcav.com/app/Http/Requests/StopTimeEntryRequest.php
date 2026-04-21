<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the POST /projects/{project}/time-entries/stop body.
 *
 * Only the optional `note` field is accepted; max 500 chars per D-06.
 */
class StopTimeEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
