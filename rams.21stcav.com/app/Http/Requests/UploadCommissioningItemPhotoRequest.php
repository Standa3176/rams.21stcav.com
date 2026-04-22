<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /commissioning-items/{item}/photo payloads (INST-05d).
 *
 * Mirrors Phase 14 InstallTaskPhoto mimetypes verbatim. iOS Safari
 * occasionally reports HEIC uploads as `application/octet-stream`, so
 * that MIME is intentionally on the allow-list; HeicImageConverter then
 * sniffs the real content-type server-side.
 *
 * 20 MB cap matches the Phase 14 precedent — large enough for modern
 * iPhone 48-megapixel HEIC captures without forcing in-app compression.
 */
class UploadCommissioningItemPhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'photo' => [
                'required',
                'file',
                'mimetypes:image/jpeg,image/png,image/webp,image/heic,image/heif,application/octet-stream',
                'max:20480',   // 20 MB in KB
            ],
        ];
    }
}
