<?php

namespace App\Support\Rams\SectionComposers;

use App\Models\RamsDocument;
use App\Support\Rams\Sections\CompanyInfoSectionDto;
use Illuminate\Contracts\Config\Repository;

/**
 * Composes Section 2 (Company Information) from `config('rams.company_*')`.
 *
 * $record is unused (the section is pure static config) but the compose()
 * signature is kept uniform across composers so RamsDocumentComposer can
 * call every one the same way.
 *
 * Config Repository is injected so tests can override company identity
 * without touching the global config repository.
 */
final class CompanyInfoComposer
{
    public function __construct(
        private readonly Repository $config,
    ) {}

    public function compose(RamsDocument $record): CompanyInfoSectionDto
    {
        return new CompanyInfoSectionDto(
            name:    (string) $this->config->get('rams.company_name',    ''),
            address: (string) $this->config->get('rams.company_address', ''),
            phone:   (string) $this->config->get('rams.company_phone',   ''),
            email:   (string) $this->config->get('rams.company_email',   ''),
            website: (string) $this->config->get('rams.company_website', ''),
        );
    }
}
