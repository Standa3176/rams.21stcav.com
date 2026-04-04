<?php

namespace App\Services\AI;

interface AIProviderInterface
{
    /**
     * Generate a RAMS document from the given form data.
     *
     * @param  array  $formData
     * @return array
     */
    public function generateRams(array $formData): array;

    /**
     * Generate a document by reading an uploaded quote PDF and optional drawings.
     *
     * @param  array       $files          Shape:
     *                                     [
     *                                       'quote'    => ['path' => string, 'mime' => string, 'name' => string],
     *                                       'drawings' => [['path' => string, 'mime' => string, 'name' => string], ...],
     *                                     ]
     * @param  string|null $retrySuffix    Appended to the built prompt on retry (ignored when $promptOverride is set).
     * @param  string|null $promptOverride When provided, replaces the internally built prompt entirely.
     * @return array
     */
    public function generateRamsFromFiles(array $files, ?string $retrySuffix = null, ?string $promptOverride = null): array;

    /**
     * Generate RAMS from pre-extracted quote text and equipment list.
     * This is the preferred path for the PDF upload flow — no PDF sent to AI.
     *
     * @param  string      $quoteText   Cleaned text extracted from the quote PDF
     * @param  array       $equipment   Structured equipment list
     * @param  string|null $retrySuffix Appended on retry
     * @return array
     */
    public function generateRamsFromText(string $quoteText, array $equipment, ?string $retrySuffix = null): array;
}
