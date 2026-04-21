<?php

namespace App\Http\Requests;

use App\Models\TimeEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the POST /projects/{project}/time-entries/start body.
 *
 * Authorisation is delegated to TimeEntryController::authoriseProjectAccess()
 * (owner / admin / assigned engineer) — this request only guards the shape of
 * the payload.
 */
class StartTimeEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category' => ['required', 'string', Rule::in(TimeEntry::CATEGORIES)],
        ];
    }

    public function messages(): array
    {
        return [
            'category.in' => 'Category must be one of: installation, commissioning, testing, other.',
        ];
    }
}
