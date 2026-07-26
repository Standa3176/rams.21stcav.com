<?php

namespace App\Support\Rams\SectionComposers;

use App\Models\RamsDocument;
use App\Support\Rams\Sections\SignoffSectionDto;

/**
 * Composes Section 8 (Sign-Off table) from post-patch RamsDocument.
 *
 * Company side uses the project's lead engineer (or falls back to
 * doc_author / project_manager). Client side ships mostly blank — the
 * client fills those on print / e-sign.
 *
 * Company `date` is the RAMS creation date. Client date is left blank.
 * `sig` is always blank on generation for both sides.
 */
final class SignoffComposer
{
    public function compose(RamsDocument $record): SignoffSectionDto
    {
        $gd      = $record->generated_data ?? [];
        $project = (array) ($gd['project'] ?? []);

        $companyName = (string) (($project['lead_engineer'] ?? '')
            ?: (($project['doc_author'] ?? '') ?: ($project['project_manager'] ?? '')));

        $companyPosition = ($project['lead_engineer'] ?? '') !== ''
            ? 'Lead Engineer'
            : (($project['doc_author'] ?? '') !== '' ? 'Project Manager' : '');

        $docDate = $record->created_at?->format('d/m/Y') ?: now()->format('d/m/Y');

        return new SignoffSectionDto(
            company: [
                'name'     => $companyName,
                'position' => $companyPosition,
                'date'     => $docDate,
                'sig'      => '',
            ],
            client: [
                'name'     => (string) ($project['client_contact_name'] ?? ''),
                'position' => '',
                'date'     => '',
                'sig'      => '',
            ],
        );
    }
}
