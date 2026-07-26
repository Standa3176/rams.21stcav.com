<?php

namespace App\Support\Rams;

use App\Support\Rams\Sections\AppendixToolboxSectionDto;
use App\Support\Rams\Sections\CompanyInfoSectionDto;
use App\Support\Rams\Sections\CoshhSectionDto;
use App\Support\Rams\Sections\CoverSectionDto;
use App\Support\Rams\Sections\DocControlSectionDto;
use App\Support\Rams\Sections\EmergencySectionDto;
use App\Support\Rams\Sections\EnvironmentalSectionDto;
use App\Support\Rams\Sections\ExclusionsSectionDto;
use App\Support\Rams\Sections\HealthSafetySectionDto;
use App\Support\Rams\Sections\MethodStatementSectionDto;
use App\Support\Rams\Sections\RiskAssessmentSectionDto;
use App\Support\Rams\Sections\RoomOverviewsSectionDto;
use App\Support\Rams\Sections\ScopeSectionDto;
use App\Support\Rams\Sections\SignoffSectionDto;
use App\Support\Rams\Sections\StandardsTableSectionDto;
use App\Support\Rams\Sections\WelfareSectionDto;

/**
 * Typed root DTO for a fully-composed RAMS document.
 *
 * One property per section slug in App\Support\Rams\RamsTheme::sectionOrder().
 * Constructor takes all 16 section DTOs positionally so omitting any
 * section fails with a PHP TypeError — no silent "blank cover page"
 * failure mode. This is the object both renderers consume once the
 * phase 260726-rf3 refactor completes (Plans 3+4).
 *
 * Populated by RamsDocumentComposer (Plan 02) from RamsDocument's
 * generated_data / reviewed_data / form_data. Neither renderer is
 * allowed to read from those raw sources once the composer is wired.
 */
final readonly class RamsDocumentDTO
{
    public function __construct(
        public CoverSectionDto             $cover,
        public DocControlSectionDto        $docControl,
        public CompanyInfoSectionDto       $companyInfo,
        public HealthSafetySectionDto      $healthSafety,
        public StandardsTableSectionDto    $standardsTable,
        public ScopeSectionDto             $scope,
        public RoomOverviewsSectionDto     $roomOverviews,
        public ExclusionsSectionDto        $exclusions,
        public RiskAssessmentSectionDto    $riskAssessment,
        public MethodStatementSectionDto   $methodStatement,
        public EmergencySectionDto         $emergency,
        public CoshhSectionDto             $coshh,
        public EnvironmentalSectionDto     $environmental,
        public WelfareSectionDto           $welfare,
        public SignoffSectionDto           $signoff,
        public AppendixToolboxSectionDto   $appendixToolbox,
    ) {}

    /**
     * Fixture builder — accepts a raw array keyed by section slug and
     * defers to each section DTO's tolerant fromArray() constructor.
     *
     * Fixture map shape:
     *   [ 'cover' => [...], 'doc_control' => [...], ... ]
     *
     * Missing keys default to empty section DTOs so fixture tests can
     * focus on the one section they exercise.
     *
     * Used by Plan 02 composer tests and Plan 05 golden-fixture snapshot
     * regeneration.
     */
    public static function fromRawArray(array $data): self
    {
        return new self(
            cover:           CoverSectionDto::fromArray((array) ($data['cover'] ?? [])),
            docControl:      DocControlSectionDto::fromArray((array) ($data['doc_control'] ?? [])),
            companyInfo:     CompanyInfoSectionDto::fromArray((array) ($data['company_info'] ?? [])),
            healthSafety:    HealthSafetySectionDto::fromArray((array) ($data['health_safety'] ?? [])),
            standardsTable:  StandardsTableSectionDto::fromArray((array) ($data['standards_table'] ?? [])),
            scope:           ScopeSectionDto::fromArray((array) ($data['scope'] ?? [])),
            roomOverviews:   RoomOverviewsSectionDto::fromArray((array) ($data['room_overviews'] ?? [])),
            exclusions:      ExclusionsSectionDto::fromArray((array) ($data['exclusions'] ?? [])),
            riskAssessment:  RiskAssessmentSectionDto::fromArray((array) ($data['risk_assessment'] ?? [])),
            methodStatement: MethodStatementSectionDto::fromArray((array) ($data['method_statement'] ?? [])),
            emergency:       EmergencySectionDto::fromArray((array) ($data['emergency'] ?? [])),
            coshh:           CoshhSectionDto::fromArray((array) ($data['coshh'] ?? [])),
            environmental:   EnvironmentalSectionDto::fromArray((array) ($data['environmental'] ?? [])),
            welfare:         WelfareSectionDto::fromArray((array) ($data['welfare'] ?? [])),
            signoff:         SignoffSectionDto::fromArray((array) ($data['signoff'] ?? [])),
            appendixToolbox: AppendixToolboxSectionDto::fromArray((array) ($data['appendix_toolbox'] ?? [])),
        );
    }

    /**
     * Introspection — expose the whole DTO tree keyed by section slug for
     * debugging + snapshot diffing. Every leaf is cast to array via
     * (array) so downstream code can json_encode() without worrying
     * about readonly-object exposure.
     *
     * @return array<string, array<string, mixed>>
     */
    public function toArray(): array
    {
        return [
            'cover'            => (array) $this->cover,
            'doc_control'      => (array) $this->docControl,
            'company_info'     => (array) $this->companyInfo,
            'health_safety'    => (array) $this->healthSafety,
            'standards_table'  => (array) $this->standardsTable,
            'scope'            => (array) $this->scope,
            'room_overviews'   => (array) $this->roomOverviews,
            'exclusions'       => (array) $this->exclusions,
            'risk_assessment'  => (array) $this->riskAssessment,
            'method_statement' => (array) $this->methodStatement,
            'emergency'        => (array) $this->emergency,
            'coshh'            => (array) $this->coshh,
            'environmental'    => (array) $this->environmental,
            'welfare'          => (array) $this->welfare,
            'signoff'          => (array) $this->signoff,
            'appendix_toolbox' => (array) $this->appendixToolbox,
        ];
    }
}
