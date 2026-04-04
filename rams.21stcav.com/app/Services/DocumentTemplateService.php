<?php

namespace App\Services;

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

/**
 * Loads a branded .docx template from resources/templates/, replaces
 * {{placeholder}} markers with actual values, and returns either the path
 * of the processed temp file or a fully-loaded PhpWord object that can be
 * extended with programmatic sections.
 *
 * Template files use {{double-brace}} syntax and are created by:
 *   php artisan docx:create-templates
 *
 * Usage:
 *   // Text substitution only → returns temp file path
 *   $path = $templates->process('rams', ['project_name' => 'Foo', ...]);
 *
 *   // For programmatic extension (append more sections)
 *   $phpWord = $templates->load('rams', ['project_name' => 'Foo', ...]);
 *   $phpWord->addSection(...);
 */
class DocumentTemplateService
{
    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Process a named template: replace all {{key}} markers and return the
     * absolute path of the saved temp .docx file.
     *
     * @param  string  $name    Template name without extension (e.g. 'rams').
     * @param  array   $values  ['project_name' => 'value', ...]
     *                          Keys may be bare ('project_name') or braced ('{{project_name}}').
     * @return string           Absolute path of the processed temp .docx.
     *
     * @throws \RuntimeException  If the template file does not exist.
     */
    public function process(string $name, array $values): string
    {
        $templatePath = $this->path($name);

        if (! file_exists($templatePath)) {
            throw new \RuntimeException(
                "Document template not found: templates/{$name}.docx — "
                . 'run `php artisan docx:create-templates` to generate templates.'
            );
        }

        $tmpDir = storage_path('app/tmp');
        if (! is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        $tmpPath = $tmpDir . '/tpl-' . $name . '-' . uniqid() . '.docx';
        copy($templatePath, $tmpPath);

        $this->substituteInZip($tmpPath, $values);

        return $tmpPath;
    }

    /**
     * Process a template and return a mutable PhpWord object.
     * The returned object contains the template's sections and can have
     * additional sections appended before saving.
     *
     * @param  string  $name    Template name without extension.
     * @param  array   $values  Placeholder → value map.
     * @return PhpWord
     *
     * @throws \RuntimeException  If the template file does not exist.
     */
    public function load(string $name, array $values): PhpWord
    {
        $tmpPath = $this->process($name, $values);
        $phpWord = IOFactory::load($tmpPath);
        unlink($tmpPath);

        return $phpWord;
    }

    /**
     * Return true if the named template file exists.
     */
    public function exists(string $name): bool
    {
        return file_exists($this->path($name));
    }

    /**
     * Return the absolute filesystem path of a template file.
     */
    public function path(string $name): string
    {
        return resource_path("templates/{$name}.docx");
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Open the .docx ZIP archive in place, replace all {{key}} occurrences in
     * word/document.xml, and save.
     *
     * A .docx is a ZIP of XML files. We do a direct string-replace on the
     * document XML rather than using PhpWord's TemplateProcessor so that the
     * {{double-brace}} placeholder syntax is honoured regardless of the
     * PhpWord version installed.
     *
     * Note: placeholders that span multiple XML runs (caused by spell-check or
     * grammar markup applied in Word) will NOT be matched. Always create
     * templates programmatically (via the Artisan command) to guarantee each
     * placeholder is a single XML run.
     */
    private function substituteInZip(string $zipPath, array $values): void
    {
        $zip = new \ZipArchive();

        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException("Cannot open template archive: {$zipPath}");
        }

        $xml = $zip->getFromName('word/document.xml');

        foreach ($values as $key => $value) {
            $cleanKey = trim((string) $key, '{}');
            $escaped  = htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $xml      = str_replace('{{' . $cleanKey . '}}', $escaped, $xml);
        }

        $zip->addFromString('word/document.xml', $xml);
        $zip->close();
    }
}
