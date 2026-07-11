<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation contract for /admin/devices/{device} PUT (quick task 260711-q7q).
 *
 * Gates on isAdmin() — non-admin submitting a PUT gets a 403 before
 * validation runs. All fields are nullable so an admin can clear PoE
 * metadata or unclassify a device by leaving inputs blank.
 *
 * signal_role normalisation: the form submits 'unclassified' when the
 * unclassified pill is selected, but the DB stores null for that state.
 * prepareForValidation() rewrites the input BEFORE the `nullable|in:...`
 * rule fires so the enum stays clean.
 *
 * is_critical normalisation: a plain HTML checkbox posts '1' when
 * checked and nothing when unchecked. Merging `boolean('is_critical')`
 * unconditionally means an unchecked box explicitly writes false to
 * the DB — a hidden `<input value="0">` before the checkbox in the
 * blade view also carries the "0" for the same reason (defence in
 * depth).
 */
class DeviceUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'room_name'    => ['nullable', 'string', 'max:120'],
            'signal_role'  => ['nullable', 'in:source,destination,processor'],
            'is_critical'  => ['nullable', 'boolean'],
            'pse_budget_w' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'pd_load_w'    => ['nullable', 'numeric', 'min:0', 'max:9999'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $signalRole = $this->input('signal_role');
        if ($signalRole === 'unclassified' || $signalRole === '') {
            $signalRole = null;
        }

        // Normalise empty-string numeric fields to null so 'nullable|numeric'
        // treats them as absent instead of failing on the empty-string cast.
        $pse = $this->input('pse_budget_w');
        $pd  = $this->input('pd_load_w');

        $this->merge([
            'signal_role'  => $signalRole,
            'is_critical'  => $this->boolean('is_critical'),
            'pse_budget_w' => ($pse === '' || $pse === null) ? null : $pse,
            'pd_load_w'    => ($pd === '' || $pd === null) ? null : $pd,
        ]);
    }
}
