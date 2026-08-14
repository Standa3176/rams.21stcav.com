<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 24 Plan 05 (DRAW-51) — batched port-table save for a single
 * device_stencil's device_ports rows.
 *
 * Mirrors app/Http/Requests/Admin/DeviceCableRuleRequest.php's exact shape
 * for a batched nested-array save: authorize() is byte-identical, rules()
 * uses the same `field.*.subfield` wildcard-array pattern.
 *
 * Structural validation ONLY — this is the Save gate (D-01: the port table
 * is always the source of truth, a stencil can be saved with zero ports or
 * with a row missing label/connector_type/signal_type). The separate,
 * stricter D-04 "Promote to Engineer-Curated" hard-gate lives in
 * StencilPromotionValidator (Plan 24-07) and is NOT duplicated here.
 *
 * `connector_type` is deliberately `string, max:50` — NOT an `in:` allowlist
 * — per 21-CONTEXT.md D-02: "device_ports.connector_type is NOT an enum,
 * engineer-extensible". `signal_type` stays a bounded-but-not-DB-enforced
 * set per UI-SPEC Component Inventory point 4.
 *
 * `ports.*.port_id` carries Laravel's `distinct` rule so an intra-array
 * duplicate is caught here (422) rather than hitting the DB's
 * `device_ports_stencil_port_unique` compound index and 500ing (D-04's
 * "catch it in validation, not as a 500", applied at Save time too).
 *
 * `confirm_regenerate` is the D-17 curated-artwork guard's explicit
 * confirm flag — see DeviceStencilController::update().
 *
 * @see .planning/phases/24-stencil-curation-ui-quote-import-auto-stub/24-05-PLAN.md
 * @see .planning/phases/24-stencil-curation-ui-quote-import-auto-stub/24-CONTEXT.md (D-17)
 */
class UpdateDeviceStencilPortsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'ports'                  => ['present', 'array'],
            'ports.*.label'          => ['nullable', 'string', 'max:100'],
            'ports.*.side'           => ['required', 'in:left,right,top,bottom'],
            'ports.*.connector_type' => ['nullable', 'string', 'max:50'],
            'ports.*.signal_type'    => ['nullable', 'in:audio,video,control,network,usb,power,speaker,dante,unclassified'],
            'ports.*.direction'      => ['required', 'in:in,out,io'],
            'ports.*.sort_order'     => ['nullable', 'integer'],
            'ports.*.port_id'        => ['required', 'string', 'max:50', 'distinct'],
            'ports.*.x_pct'          => ['nullable', 'numeric', 'between:0,1'],
            'ports.*.y_pct'          => ['nullable', 'numeric', 'between:0,1'],
            'confirm_regenerate'     => ['nullable', 'boolean'],
        ];
    }
}
