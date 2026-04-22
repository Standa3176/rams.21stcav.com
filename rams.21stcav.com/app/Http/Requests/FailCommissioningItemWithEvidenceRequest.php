<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /commissioning-items/{item}/fail-with-evidence payloads (W-12).
 *
 * Atomic fail path — the endpoint performs status=fail transition + photo
 * upload + note save in a single DB::transaction to eliminate the
 * photo-POST-then-status-PATCH race. MIME + size constraints stay aligned
 * with UploadCommissioningItemPhotoRequest so a client that can upload to
 * the standalone endpoint can also atomically fail with the same file.
 *
 * Both `photo` and `note` are required here — this endpoint is only ever
 * reached when the engineer is explicitly recording a fail; the D-14
 * precondition is that both items of evidence land together.
 */
class FailCommissioningItemWithEvidenceRequest extends FormRequest
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
                'max:20480',
            ],
            'note'  => ['required', 'string', 'min:1', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'note.required'  => 'A fail status requires a note explaining what failed.',
            'photo.required' => 'A fail status requires an evidence photo.',
        ];
    }
}
