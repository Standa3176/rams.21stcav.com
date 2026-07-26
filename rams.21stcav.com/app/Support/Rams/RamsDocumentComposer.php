<?php

namespace App\Support\Rams;

use App\Models\RamsDocument;
use App\Support\Rams\SectionComposers\AppendixToolboxComposer;
use App\Support\Rams\SectionComposers\CompanyInfoComposer;
use App\Support\Rams\SectionComposers\CoshhComposer;
use App\Support\Rams\SectionComposers\CoverComposer;
use App\Support\Rams\SectionComposers\DocControlComposer;
use App\Support\Rams\SectionComposers\EmergencyComposer;
use App\Support\Rams\SectionComposers\EnvironmentalComposer;
use App\Support\Rams\SectionComposers\ExclusionsComposer;
use App\Support\Rams\SectionComposers\HealthSafetyComposer;
use App\Support\Rams\SectionComposers\MethodStatementComposer;
use App\Support\Rams\SectionComposers\RiskAssessmentComposer;
use App\Support\Rams\SectionComposers\RoomOverviewsComposer;
use App\Support\Rams\SectionComposers\ScopeComposer;
use App\Support\Rams\SectionComposers\SignoffComposer;
use App\Support\Rams\SectionComposers\StandardsTableComposer;
use App\Support\Rams\SectionComposers\WelfareComposer;
use Illuminate\Support\Facades\Log;

/**
 * Root composer — transforms a RamsDocument into a typed RamsDocumentDTO
 * by delegating to per-section sub-composers.
 *
 * ORDER-OF-OPERATIONS INVARIANT
 * ─────────────────────────────
 * This composer MUST run AFTER RamsDisplayPatchService::patch() has been
 * applied to the same $record. The patch service:
 *
 *   1. Overwrites stale generated_data.project.* with live Project record.
 *   2. Resolves the personnel chain (programme → reviewed_data → owner → form_data).
 *   3. Infers client_contact_* from site_logistics / package extracted_data.
 *   4. Rebuilds scope_items from the latest package.
 *   5. Applies the decommission / retained routing (260726-rf2).
 *   6. Seeds reviewed_data defaults (exclusions, scope_traceability, cdm).
 *   7. Auto-carries site_emergency + cdm from prior completed RAMS.
 *
 * The composer relies on every one of those side-effects. If the patch
 * service hasn't run, the composer still produces a DTO (best-effort
 * from raw data) but emits a WARNING to laravel.log so the missed-order
 * bug is detectable in prod without breaking the render pipeline.
 *
 * Detection uses the `_display_patched_at` marker that
 * RamsDisplayPatchService::patch() writes onto generated_data during
 * every invocation. Absence of the marker means the composer ran on
 * un-patched data.
 *
 * Consumers:
 *   - DocxBuilderService (Plan 04, behind RAMS_UNIFIED_COMPOSER kill switch)
 *   - resources/views/pdf/rams.blade.php (Plan 03, behind kill switch)
 *   - Snapshot / parity tests (Plan 05)
 *
 * Never mutates $record; never calls save().
 */
final class RamsDocumentComposer
{
    public function __construct(
        private readonly CoverComposer             $cover,
        private readonly DocControlComposer        $docControl,
        private readonly CompanyInfoComposer       $companyInfo,
        private readonly HealthSafetyComposer      $healthSafety,
        private readonly StandardsTableComposer    $standardsTable,
        private readonly ScopeComposer             $scope,
        private readonly RoomOverviewsComposer     $roomOverviews,
        private readonly ExclusionsComposer        $exclusions,
        private readonly RiskAssessmentComposer    $riskAssessment,
        private readonly MethodStatementComposer   $methodStatement,
        private readonly EmergencyComposer         $emergency,
        private readonly CoshhComposer             $coshh,
        private readonly EnvironmentalComposer     $environmental,
        private readonly WelfareComposer           $welfare,
        private readonly SignoffComposer           $signoff,
        private readonly AppendixToolboxComposer   $appendixToolbox,
    ) {}

    /**
     * Build the fully-populated RamsDocumentDTO for a post-patch record.
     */
    public function compose(RamsDocument $record): RamsDocumentDTO
    {
        $this->assertPatched($record);

        return new RamsDocumentDTO(
            cover:           $this->cover->compose($record),
            docControl:      $this->docControl->compose($record),
            companyInfo:     $this->companyInfo->compose($record),
            healthSafety:    $this->healthSafety->compose($record),
            standardsTable:  $this->standardsTable->compose($record),
            scope:           $this->scope->compose($record),
            roomOverviews:   $this->roomOverviews->compose($record),
            exclusions:      $this->exclusions->compose($record),
            riskAssessment:  $this->riskAssessment->compose($record),
            methodStatement: $this->methodStatement->compose($record),
            emergency:       $this->emergency->compose($record),
            coshh:           $this->coshh->compose($record),
            environmental:   $this->environmental->compose($record),
            welfare:         $this->welfare->compose($record),
            signoff:         $this->signoff->compose($record),
            appendixToolbox: $this->appendixToolbox->compose($record),
        );
    }

    /**
     * Emit a WARNING log if the record was composed without the patch
     * service having run first. Detected via the `_display_patched_at`
     * marker RamsDisplayPatchService::patch() writes on generated_data.
     *
     * Non-fatal — the composer still runs and produces a DTO. The warning
     * exists to make the order-of-operations bug detectable in prod
     * without breaking the render pipeline behind Plan 3/4's kill switch.
     */
    private function assertPatched(RamsDocument $record): void
    {
        $gd = $record->generated_data ?? [];
        if (empty($gd['_display_patched_at'])) {
            Log::warning('RamsDocumentComposer: composing without RamsDisplayPatchService::patch() marker — '
                .'personnel / scope / client-contact resolution may be incomplete.', [
                'rams_id'    => $record->id,
                'project_id' => $record->project_id,
                'status'     => $record->status,
            ]);
        }
    }
}
