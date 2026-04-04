<?php

namespace App\Services;

use App\Models\RamsDocument;

/**
 * Renders the final RAMS DOCX from the fully assembled data array.
 *
 * This is the last stage of the RAMS generation pipeline.
 * It takes the normalised data produced by RamsDataBuilderService and
 * writes a branded .docx file to storage/app/rams/.
 *
 * Delegates to DocxBuilderService which:
 *   - Uses a branded PhpWord template when available (resources/templates/rams.docx)
 *   - Falls back to a fully programmatic PhpWord document
 *   - Writes the landscape risk matrix, PPE table, method statement sections, etc.
 *   - Persists the final filename to the RamsDocument record
 *
 * No AI calls. No data assembly. No business logic.
 * Pure document rendering.
 */
class RamsDocumentRendererService
{
    public function __construct(
        private readonly DocxBuilderService $docxBuilder,
    ) {}

    /**
     * Render the RAMS DOCX and return the absolute path to the written file.
     *
     * @param  array        $data    Fully assembled data from RamsDataBuilderService
     * @param  RamsDocument $record  Persisted record (used for filename + ID)
     * @return string                Absolute path to the written .docx file
     */
    public function render(array $data, RamsDocument $record): string
    {
        return $this->docxBuilder->build($data, $record);
    }
}
