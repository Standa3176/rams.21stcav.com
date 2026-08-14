<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 24 Plan 06 (DRAW-52) — per-stencil manufacturer logo upload.
 *
 * `mimes:svg,png,jpg,jpeg` is deliberate over Laravel's `image` rule — the
 * `image` rule does not reliably admit `.svg` across browsers/PHP fileinfo
 * (see 24-06-PLAN.md interfaces section). `mimes` content-sniffs via PHP's
 * fileinfo extension guesser, so a `.exe` renamed to `.png` is rejected
 * (T-24-16), not extension-trusted. `max:2048` (2MB) mirrors the UI-SPEC
 * helper text verbatim ("PNG or SVG, up to 2MB") and is enforced BEFORE any
 * disk write (T-24-15).
 *
 * Route-level `admin` middleware (routes/web.php) is the primary gate;
 * authorize() here is defence-in-depth, mirroring
 * DeviceCableRuleRequest::authorize().
 *
 * @see .planning/phases/24-stencil-curation-ui-quote-import-auto-stub/24-06-PLAN.md
 * @see .planning/phases/24-stencil-curation-ui-quote-import-auto-stub/24-CONTEXT.md (D-12, D-15)
 */
class UploadDeviceStencilLogoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'logo' => ['required', 'file', 'mimes:svg,png,jpg,jpeg', 'max:2048'],
        ];
    }
}
