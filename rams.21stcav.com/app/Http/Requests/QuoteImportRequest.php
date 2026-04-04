<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class QuoteImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'quote_pdf'     => ['required', 'file', 'mimes:pdf', 'max:20480'],   // 20 MB
            'project_id'    => ['nullable', 'integer', 'exists:projects,id'],
            'create_project'=> ['nullable', 'boolean'],
            'ai_provider'   => ['nullable', 'string', 'in:claude,openai'],
        ];
    }

    public function messages(): array
    {
        return [
            'quote_pdf.required' => 'Please upload a QuoteWerks PDF.',
            'quote_pdf.mimes'    => 'The quote must be a PDF file.',
            'quote_pdf.max'      => 'The quote PDF may not exceed 20 MB.',
        ];
    }
}
