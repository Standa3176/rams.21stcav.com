<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * QuoteWerksLookupRequest — validates QuoteWerks reference or client search inputs.
 *
 * Used by QuoteWerksImportController::lookup() and ::search().
 * Reference format is validated loosely — the QW instance emits 21CQ###### natively
 * but revision suffixes vary (21CQ29531-05-OPS is a valid live example).
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
            'reference' => ['sometimes', 'string', 'max:64'],
            'client'    => ['sometimes', 'string', 'min:2', 'max:100'],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'force'     => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'reference.max' => 'Quote reference is too long (max 64 characters).',
            'client.min'    => 'Enter at least 2 characters to search.',
        ];
    }
}
