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
 *
 * Quick task 260712-euh — length_tiers editor shim.
 * The Alpine.js tier editor POSTs `length_tiers` as a JSON-encoded
 * string (hidden input, sorted ascending on max_m at serialise time).
 * prepareForValidation() decodes + sorts + merges. Empty / invalid
 * JSON collapses to null so the model stores null rather than `[]`.
 * Each tier row is validated element-wise: `max_m > 0`, `cable_type`
 * required + max 200 chars, `cores` / `to_endpoint` / `notes` optional.
 *
 * Quick task 260712-ip3 — negative_keywords textarea shim.
 * Mirrors the keywords_raw pattern: form POSTs `negative_keywords_raw`
 * (one entry per line, optional). prepareForValidation() splits it
 * into a lowercased/trimmed array and merges it as `negative_keywords`.
 * Empty payload collapses to null so the model stores null (not []),
 * which the inference walker treats as "no exclusion". Non-empty
 * arrays are matched element-wise via matchesAny() word-boundary.
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
            // 260712-euh length_tiers
            'length_tiers'              => ['nullable', 'array'],
            'length_tiers.*.max_m'      => ['required_with:length_tiers', 'numeric', 'gt:0'],
            'length_tiers.*.cable_type' => ['required_with:length_tiers', 'string', 'max:200'],
            'length_tiers.*.cores'      => ['nullable', 'string', 'max:50'],
            'length_tiers.*.to_endpoint'=> ['nullable', 'string', 'max:200'],
            'length_tiers.*.notes'      => ['nullable', 'string', 'max:500'],
            // 260712-ip3 negative_keywords
            'negative_keywords_raw' => ['nullable', 'string'],
            'negative_keywords'     => ['nullable', 'array'],
            'negative_keywords.*'   => ['string', 'max:120'],
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

        // 260712-euh: decode + sort length_tiers before validation. Alpine.js
        // editor posts a hidden JSON string; we decode, coerce to array, and
        // sort ascending on max_m so persist-time ordering is guaranteed.
        // Empty / invalid / non-array payloads collapse to null so the model
        // stores null (not []) — inferCableRun treats null and [] identically
        // but null is the canonical "no tier logic" flag.
        $tiers = $this->normaliseLengthTiers($this->input('length_tiers'));

        // 260712-ip3: split negative_keywords_raw the same way as keywords_raw.
        // Empty payload → null (not []) so the model stores the canonical
        // "no exclusion" flag. ruleMatches() short-circuits when the field
        // is null / empty.
        $rawNeg = (string) $this->input('negative_keywords_raw', '');

        $negatives = array_values(array_filter(
            array_map(
                static fn (string $line): string => strtolower(trim($line)),
                preg_split('/\r\n|\r|\n/', $rawNeg) ?: []
            ),
            static fn (string $kw): bool => $kw !== ''
        ));

        $this->merge([
            'keywords'          => $keywords,
            'length_tiers'      => $tiers,
            'negative_keywords' => $negatives === [] ? null : $negatives,
        ]);
    }

    /**
     * Decode the posted `length_tiers` payload (JSON string or array) into a
     * normalised, ascending-sorted array of tier rows. Returns null when the
     * payload is empty, missing, or malformed.
     *
     * @param  mixed  $raw
     * @return array<int, array<string, mixed>>|null
     */
    private function normaliseLengthTiers(mixed $raw): ?array
    {
        if ($raw === null || $raw === '' || $raw === '[]') {
            return null;
        }

        // Editor posts JSON string; some tests may post the array directly.
        $tiers = is_string($raw) ? json_decode($raw, true) : $raw;

        if (! is_array($tiers) || $tiers === []) {
            return null;
        }

        // Drop any non-array entries so usort never crashes on mixed input.
        $tiers = array_values(array_filter($tiers, static fn ($t) => is_array($t)));
        if ($tiers === []) {
            return null;
        }

        // Sort ascending on max_m (numeric compare, null/missing treated as 0).
        usort($tiers, static fn (array $a, array $b) =>
            (float) ($a['max_m'] ?? 0) <=> (float) ($b['max_m'] ?? 0)
        );

        return $tiers;
    }
}
