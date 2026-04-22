<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates PATCH /commissioning-items/{item}/status payloads.
 *
 * Enum constraint at the FormRequest layer — unknown statuses return 422
 * before the controller runs. D-14 photo + note guard on `fail` lives in
 * the controller because it requires reading existing item state.
 *
 * Controller-level abort_if handles ownership authorisation, so
 * authorize() returns true unconditionally here.
 */
class UpdateCommissioningItemStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'in:pending,pass,fail,na'],
            'note'   => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.in' => 'Status must be one of pending, pass, fail, or n/a.',
        ];
    }
}
