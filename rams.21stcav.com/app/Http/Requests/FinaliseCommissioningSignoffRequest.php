<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * FinaliseCommissioningSignoffRequest — validates the POST body for
 * /install-programmes/{programme}/commissioning/signoff/finalise.
 *
 * Ownership is guarded inside the controller via authorise(), not here.
 *
 * Contract:
 *   client_name           required, <=200
 *   client_role           required, <=200
 *   client_company        required, <=200
 *   signature_png_base64  required, >=100 chars, matches
 *                         ^(data:image/png;base64,)?[A-Za-z0-9+/=\s]+$
 *
 * T-16-07 — the regex is intentionally loose on interior whitespace (engineers'
 * Canvas toDataURL outputs may contain newlines) and strict on characters.
 * CommissioningService::sanitiseBase64() strips the prefix + whitespace BEFORE
 * the real PNG-signature check runs.
 */
class FinaliseCommissioningSignoffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_name'    => ['required', 'string', 'max:200'],
            'client_role'    => ['required', 'string', 'max:200'],
            'client_company' => ['required', 'string', 'max:200'],
            // T-16-07 — allow both raw body and data URI prefix; service normalises.
            // WR-03 — cap payload at ~5 MB base64 (≈3.7 MB decoded PNG). The
            // migration docblock records real iPad Retina signatures at
            // 30-60 KB, so this is comfortably above the realistic ceiling
            // while protecting the host from a single-request memory blow-up
            // when the payload is base64_decoded + preg_replaced + stored on
            // every snagging PDF render.
            'signature_png_base64' => [
                'required',
                'string',
                'min:100',
                'max:5242880',
                'regex:#^(data:image/png;base64,)?[A-Za-z0-9+/=\s]+$#',
            ],
        ];
    }
}
