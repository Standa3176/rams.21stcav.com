<?php

namespace App\Http\Requests;

use App\Models\TimeEntry;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the PATCH /time-entries/{entry} body for retro-edits (Plan 15-02).
 *
 * field whitelist mitigates T-15-02-03 (mass-assignment via PATCH) — only
 * 'category' and 'notes' are retro-editable. When field === 'category' the
 * value is additionally checked against TimeEntry::CATEGORIES.
 */
class UpdateTimeEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'field' => ['required', 'string', Rule::in(['category', 'notes'])],
            'value' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($this->input('field') === 'category') {
                $value = $this->input('value');
                if (! in_array($value, TimeEntry::CATEGORIES, true)) {
                    $v->errors()->add(
                        'value',
                        'Category must be one of: installation, commissioning, testing, other.',
                    );
                }
            }
        });
    }
}
