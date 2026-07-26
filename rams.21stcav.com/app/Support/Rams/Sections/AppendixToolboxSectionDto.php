<?php

namespace App\Support\Rams\Sections;

/**
 * Appendix A — Toolbox Talk sign-in.
 *
 * A boilerplate instruction paragraph followed by N blank rows for
 * on-site engineer signatures. row_count controls how many blank rows
 * to render.
 *
 * Populated by RamsDocumentComposer (Plan 02) from
 * config('rams_tier1.toolbox_talk') defaults / reviewed overrides.
 */
final readonly class AppendixToolboxSectionDto
{
    public function __construct(
        public string $instructionText = '',
        public int    $rowCount        = 5,
    ) {}

    public static function fromArray(array $data): self
    {
        // Preserve an explicit 0 if the caller provides one; only fall back
        // to the 5-row default when the key is absent entirely.
        $rowCount = array_key_exists('row_count', $data)
            ? (int) $data['row_count']
            : 5;

        return new self(
            instructionText: (string) ($data['instruction_text'] ?? ''),
            rowCount:        $rowCount,
        );
    }

    /**
     * Empty only when the instruction text is blank AND no rows will be
     * rendered. A default-constructed instance ships 5 rows and no text
     * — that's still "empty" for renderer-skip purposes.
     */
    public function isEmpty(): bool
    {
        return $this->instructionText === '';
    }
}
