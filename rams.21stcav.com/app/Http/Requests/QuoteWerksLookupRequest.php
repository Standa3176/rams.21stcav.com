<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * QuoteWerksLookupRequest — validates QuoteWerks reference or client search inputs.
 *
 * Used by QuoteWerksImportController::lookup() and ::search().
 * Reference format: 21CQ followed by 2–15 digits, optional revision suffix (e.g. 21CQ12345-01).
 */
class QuoteWerksLookupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // auth middleware handles authentication
    }

    public function rules(): array
    {
        return [
            'qw_reference' => ['sometimes', 'string', 'max:50', 'regex:/^21CQ[0-9]{2,15}(-[A-Z0-9]{1,10})*$/i'],
            'client_name'  => ['sometimes', 'string', 'min:2', 'max:100'],
            'date_from'    => ['sometimes', 'nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'qw_reference.regex' => 'Quote reference must start with 21CQ followed by digits (e.g. 21CQ12345 or 21CQ12345-01).',
        ];
    }
}
