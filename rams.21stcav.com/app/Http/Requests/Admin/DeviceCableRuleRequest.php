<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Quick task 260711-q7q — CRUD validation for device_cable_rules rows.
 *
 * Textarea shim: the form accepts `keywords_raw` (one keyword per
 * line). prepareForValidation() splits it into an array, lowercases +
 * trims each entry, and merges it as `keywords` for the model. The
 * validator then requires `keywords` to be a non-empty array so an
 * empty textarea fails cleanly with a validation error rather than
 * saving an empty rule that never matches.
 *
 * signal_type is constrained to the 8 keys in
 * config('cables.signal_type_colours') — the drawings + XLSX layers
 * key colour palettes off this enum, so a rogue value would render as
 * uncoloured.
 */
class DeviceCableRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'priority'    => ['required', 'integer', 'min:0', 'max:9999'],
            'keywords_raw' => ['required', 'string'],
            'keywords'    => ['required', 'array', 'min:1'],
            'keywords.*'  => ['string', 'max:120'],
            'cable_type'  => ['required', 'string', 'max:120'],
            'cores'       => ['nullable', 'string', 'max:20'],
            'signal_type' => ['required', 'in:video,audio,network,speaker,control,power,usb,unknown'],
            'to_endpoint' => ['required', 'string', 'max:200'],
            'notes'       => ['nullable', 'string', 'max:500'],
            'is_active'   => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $raw = (string) $this->input('keywords_raw', '');

        $keywords = array_values(array_filter(
            array_map(
                static fn (string $line): string => strtolower(trim($line)),
                preg_split('/\r\n|\r|\n/', $raw) ?: []
            ),
            static fn (string $kw): bool => $kw !== ''
        ));

        $this->merge([
            'keywords' => $keywords,
        ]);
    }
}
