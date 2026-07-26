<?php

namespace Tests\Unit\Support\Rams;

use App\Support\Rams\RamsDocumentDTO;
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
use ArgumentCountError;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for the RAMS root DTO.
 *
 * Focus:
 *   - Direct construction with all 16 section DTOs succeeds and exposes
 *     the correct property types.
 *   - fromRawArray() builds every section from a fixture map (partial
 *     sections default to empty DTOs).
 *   - Omitting a section from the positional constructor throws
 *     ArgumentCountError — this is the "compile-time" guardrail that
 *     prevents a silent blank cover page in a fresh renderer path.
 *   - toArray() round-trips the tree shape keyed by section slug.
 *
 * Phase 260726-rf3-rams-render-unification / plan-01.
 *
 * Uses PHPUnit\Framework\TestCase directly (no Laravel container needed
 * — DTOs are pure PHP with no service dependencies).
 */
class RamsDocumentDtoTest extends TestCase
{
    private function fullyPopulatedDto(): RamsDocumentDTO
    {
        return new RamsDocumentDTO(
            cover:           new CoverSectionDto(client: 'Tilda'),
            docControl:      new DocControlSectionDto(),
            companyInfo:     new CompanyInfoSectionDto(name: '21CAV'),
            healthSafety:    new HealthSafetySectionDto(),
            standardsTable:  new StandardsTableSectionDto(),
            scope:           new ScopeSectionDto(),
            roomOverviews:   new RoomOverviewsSectionDto(),
            exclusions:      new ExclusionsSectionDto(),
            riskAssessment:  new RiskAssessmentSectionDto(),
            methodStatement: new MethodStatementSectionDto(),
            emergency:       new EmergencySectionDto(),
            coshh:           new CoshhSectionDto(),
            environmental:   new EnvironmentalSectionDto(),
            welfare:         new WelfareSectionDto(),
            signoff:         new SignoffSectionDto(),
            appendixToolbox: new AppendixToolboxSectionDto(instructionText: 'Sign in below'),
        );
    }

    public function test_construction_with_all_16_sections_succeeds(): void
    {
        $dto = $this->fullyPopulatedDto();

        $this->assertInstanceOf(CoverSectionDto::class,             $dto->cover);
        $this->assertInstanceOf(DocControlSectionDto::class,        $dto->docControl);
        $this->assertInstanceOf(CompanyInfoSectionDto::class,       $dto->companyInfo);
        $this->assertInstanceOf(HealthSafetySectionDto::class,      $dto->healthSafety);
        $this->assertInstanceOf(StandardsTableSectionDto::class,    $dto->standardsTable);
        $this->assertInstanceOf(ScopeSectionDto::class,             $dto->scope);
        $this->assertInstanceOf(RoomOverviewsSectionDto::class,     $dto->roomOverviews);
        $this->assertInstanceOf(ExclusionsSectionDto::class,        $dto->exclusions);
        $this->assertInstanceOf(RiskAssessmentSectionDto::class,    $dto->riskAssessment);
        $this->assertInstanceOf(MethodStatementSectionDto::class,   $dto->methodStatement);
        $this->assertInstanceOf(EmergencySectionDto::class,         $dto->emergency);
        $this->assertInstanceOf(CoshhSectionDto::class,             $dto->coshh);
        $this->assertInstanceOf(EnvironmentalSectionDto::class,     $dto->environmental);
        $this->assertInstanceOf(WelfareSectionDto::class,           $dto->welfare);
        $this->assertInstanceOf(SignoffSectionDto::class,           $dto->signoff);
        $this->assertInstanceOf(AppendixToolboxSectionDto::class,   $dto->appendixToolbox);
    }

    public function test_missing_section_at_construction_throws_argument_count_error(): void
    {
        $this->expectException(ArgumentCountError::class);

        // Only the cover — the other 15 positional args are missing. This is
        // the guardrail that prevents a renderer from silently shipping a
        // blank appendix if a section is forgotten.
        new RamsDocumentDTO(
            cover: new CoverSectionDto(),
        );
    }

    public function test_to_array_round_trips_shape(): void
    {
        $arr = $this->fullyPopulatedDto()->toArray();

        $this->assertSame(
            [
                'cover',
                'doc_control',
                'company_info',
                'health_safety',
                'standards_table',
                'scope',
                'room_overviews',
                'exclusions',
                'risk_assessment',
                'method_statement',
                'emergency',
                'coshh',
                'environmental',
                'welfare',
                'signoff',
                'appendix_toolbox',
            ],
            array_keys($arr),
            'toArray() must key every section by its canonical slug'
        );
        // Leaf sanity — the populated cover client survives the round trip.
        $this->assertSame('Tilda', $arr['cover']['client']);
        $this->assertSame('Sign in below', $arr['appendix_toolbox']['instructionText']);
    }

    public function test_from_raw_array_builds_from_partial_fixture_map(): void
    {
        $dto = RamsDocumentDTO::fromRawArray([
            'cover' => [
                'client'      => 'Tilda Ltd',
                'project_ref' => '21CQ29531',
                'rooms'       => ['Board Room', 'Bistro'],
            ],
            'company_info' => [
                'name'  => '21st Century AV Ltd',
                'email' => 'info@21stcenturyav.com',
            ],
            'appendix_toolbox' => [
                'row_count'        => 8,
                'instruction_text' => 'Toolbox talk delivered before works commence.',
            ],
        ]);

        // Populated sections have values.
        $this->assertSame('Tilda Ltd', $dto->cover->client);
        $this->assertSame('21CQ29531', $dto->cover->projectRef);
        $this->assertSame(['Board Room', 'Bistro'], $dto->cover->rooms);
        $this->assertSame('21st Century AV Ltd', $dto->companyInfo->name);
        $this->assertSame(8, $dto->appendixToolbox->rowCount);

        // Un-supplied sections default to their empty DTO — no TypeError,
        // no missing property, no silent leak from a stale run.
        $this->assertTrue($dto->docControl->isEmpty());
        $this->assertTrue($dto->healthSafety->isEmpty());
        $this->assertTrue($dto->riskAssessment->isEmpty());
        $this->assertTrue($dto->emergency->isEmpty());
    }

    public function test_from_raw_array_with_empty_input_yields_all_empty_sections(): void
    {
        $dto = RamsDocumentDTO::fromRawArray([]);

        $this->assertTrue($dto->cover->isEmpty());
        $this->assertTrue($dto->docControl->isEmpty());
        $this->assertTrue($dto->companyInfo->isEmpty());
        $this->assertTrue($dto->healthSafety->isEmpty());
        $this->assertTrue($dto->standardsTable->isEmpty());
        $this->assertTrue($dto->scope->isEmpty());
        $this->assertTrue($dto->roomOverviews->isEmpty());
        $this->assertTrue($dto->exclusions->isEmpty());
        $this->assertTrue($dto->riskAssessment->isEmpty());
        $this->assertTrue($dto->methodStatement->isEmpty());
        $this->assertTrue($dto->emergency->isEmpty());
        $this->assertTrue($dto->coshh->isEmpty());
        $this->assertTrue($dto->environmental->isEmpty());
        $this->assertTrue($dto->welfare->isEmpty());
        $this->assertTrue($dto->signoff->isEmpty());
        $this->assertTrue($dto->appendixToolbox->isEmpty());
    }
}
