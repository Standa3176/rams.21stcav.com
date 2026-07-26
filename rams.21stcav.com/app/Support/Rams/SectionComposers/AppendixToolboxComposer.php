<?php

namespace App\Support\Rams\SectionComposers;

use App\Models\RamsDocument;
use App\Support\Rams\Sections\AppendixToolboxSectionDto;
use Illuminate\Contracts\Config\Repository;

/**
 * Composes Appendix A (Toolbox Talk sign-in) from static tier-1
 * defaults + reviewed_data overrides.
 *
 * Default row_count is 5 — matches DocxBuilderService::buildAppendixA().
 * Engineers can override both the instruction paragraph and the row count
 * per project via the review form.
 */
final class AppendixToolboxComposer
{
    private const DEFAULT_INSTRUCTION_TEXT =
        "This document has been read, understood, and accepted by the following personnel prior to "
        ."works commencing. Signature confirms that the risks identified in Section 5 and the method "
        ."described in Section 6 have been briefed to the engineer and that they are competent to "
        ."carry out the works detailed herein.";

    public function __construct(
        private readonly Repository $config,
    ) {}

    public function compose(RamsDocument $record): AppendixToolboxSectionDto
    {
        $rd      = $record->reviewed_data ?? [];
        $toolbox = (array) ($rd['toolbox_talk'] ?? []);

        $instructionText = (string) ($toolbox['instruction_text']
            ?? $this->config->get('rams_tier1.toolbox_talk.instruction_text', self::DEFAULT_INSTRUCTION_TEXT));

        $rowCount = array_key_exists('row_count', $toolbox)
            ? (int) $toolbox['row_count']
            : (int) $this->config->get('rams_tier1.toolbox_talk.row_count', 5);

        return new AppendixToolboxSectionDto(
            instructionText: $instructionText,
            rowCount:        $rowCount,
        );
    }
}
