<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RamsUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Auth enforced at route level via middleware
    }

    public function rules(): array
    {
        return [
            // Use 'extensions' not 'mimes' — the mimes rule calls getMimeType() which
            // instantiates Symfony's File object and checks /tmp exists. On shared hosting
            // /tmp is swept aggressively, causing FileNotFoundException before the
            // controller even runs. 'extensions' validates only the filename string.
            'quote_pdf'   => ['required', 'file', 'extensions:pdf', 'max:10240'],     // 10 MB
            'drawings'    => ['nullable', 'array', 'max:10'],                          // up to 10 drawings
            'drawings.*'  => ['file', 'extensions:pdf,jpg,jpeg,png', 'max:5120'],      // 5 MB each
            'ai_provider' => ['nullable', 'string', 'in:claude,openai'],
        ];
    }

    public function messages(): array
    {
        return [
            'quote_pdf.required' => 'A QuoteWerks PDF quote is required.',
            'quote_pdf.extensions' => 'The quote must be a PDF file.',
            'quote_pdf.max'      => 'The quote PDF must not exceed 10 MB.',
            'drawings.max'       => 'You may upload a maximum of 10 drawings.',
            'drawings.*.extensions' => 'Each drawing must be a PDF, JPG, or PNG file.',
            'drawings.*.max'     => 'Each drawing must not exceed 5 MB.',
        ];
    }
}
