<?php

namespace App\Services;

use App\Core\Modules\KnowledgeLibrary\HazardLibraryService;
use App\Models\RamsDocument;
use App\Services\ProjectContext\ProjectContextBuilder;
use App\Services\Rams\RamsComplianceUpgradeService;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates RAMS document generation.
 *
 * Entry points:
 *
 *   buildFromForm($formData, $record)
 *     Synchronous build for the manual create form. No PDF involved.
 *     Confidence is always 1.0 (form input is fully structured).
 *
 *   buildFromReview($reviewedData, $formData, $record)   ← Phase B
 *     Used by BuildRamsDocumentJob. Takes reviewed_data as the sole
 *     source-of-truth. No re-parsing. No re-classification. AI is called
 *     only for the method statement.
 *
 * The old buildFromQuote() entry point remains for backwards compatibility
 * with any direct callers (e.g. regenerate in RamsController), but the
 * quote-upload pipeline now dispatches ExtractRamsDraftJob (Phase A) +
 * BuildRamsDocumentJob (Phase B) instead of calling this method directly.
 *
 * Pipeline stages (Phases A and B combined for direct calls):
 *   1. Parse / classify / resolve risks  (local, no AI)
 *   2. Build ProjectContext from SiteSurvey when available (deterministic)
 *   3. Generate method statement         (AI — single AI call)
 *   4. Assemble + normalise data         (RamsDataBuilderService handles all data shaping,
 *                                          including ProjectContext integration)
 *   5. Pre-render guard
 *   6. Persist generated_data
 *   7. Render DOCX
 */
class RamsBuilderService
{
    private const CONFIDENCE_THRESHOLD = 0.5;

    public function __construct(
        private readonly QuoteParserService              $quoteParser,
        private readonly EquipmentClassifierService      $classifier,
        private readonly RiskTemplateResolverService     $riskResolver,
        private readonly MethodStatementGeneratorService $methodStatementGen,
        private readonly RamsDataBuilderService          $dataBuilder,
        private readonly RamsDocumentRendererService     $renderer,
        private readonly HazardLibraryService            $hazardLibrary,
        private readonly RoomOverviewSummaryService      $roomOverviewSummary,
    ) {}

    // =========================================================================
    // PUBLIC ENTRY POINTS
    // =========================================================================

    /**
     * Phase B — Build from reviewed (human-approved) data.
     *
     * This is the correct entry point after the review workflow. It uses
     * reviewed_data exclusively and does NOT touch the raw PDF or re-run
     * the parser/classifier/risk resolver.
     */
    public function buildFromReview(array $reviewedData, array $formData, RamsDocument $record): string
    {
        try {
            return $this->runFromReview($reviewedData, $formData, $record);
        } catch (\Throwable $e) {
            Log::error('RamsBuilderService::buildFromReview failed', [
                'record_id' => $record->id,
                'error'     => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ]);
            $record->update(['status' => RamsDocument::STATUS_FAILED]);
            throw $e;
        }
    }

    /**
     * Build a RAMS DOCX from extracted quote PDF text + optional form overrides.
     *
     * Used by the manual create form and the regenerate action.
     * Form-data overrides are applied exclusively inside
     * RamsDataBuilderService::resolveProjectFields().
     */
    public function buildFromQuote(string $extractedText, array $formData, RamsDocument $record): string
    {
        $parsed = $this->quoteParser->parse($extractedText);

        Log::info('RamsBuilderService: quote parsed', [
            'record_id'       => $record->id,
            'client'          => $parsed['client']    ?: '(not detected)',
            'site'            => $parsed['site']      ?: '(not detected)',
            'ref'             => $parsed['ref'],
            'equipment_count' => count($parsed['equipment'] ?? []),
            'confidence'      => $parsed['confidence'] ?? 0.0,
        ]);

        return $this->pipeline($parsed, $formData, $record);
    }

    /**
     * Build a RAMS DOCX from form data only (no quote PDF).
     */
    public function buildFromForm(array $formData, RamsDocument $record): string
    {
        $parsed = [
            'client'        => $formData['client_name']      ?? '',
            'site'          => $formData['site_address']      ?? '',
            'ref'           => $formData['project_ref']       ?? '',
            'project_name'  => $formData['project_name']      ?? '',
            'works_summary' => $formData['works_description'] ?? '',
            'equipment'     => [],
            'tasks'         => [],
            'rooms'         => [],
            'confidence'    => 1.0,
        ];

        return $this->pipeline($parsed, $formData, $record);
    }

    // =========================================================================
    // PRIVATE — PHASE B (FROM REVIEWED DATA)
    // =========================================================================

    private function runFromReview(array $reviewedData, array $formData, RamsDocument $record): string
    {
        // Convert reviewed_data sections to the formats expected by existing services.
        $parsedQuote = $this->reviewedToParsed($reviewedData);
        $classified  = $this->reviewedToClassified($reviewedData);
        $risk        = $this->reviewedToRisk($reviewedData, $record->user_id);
        $mergedForm  = $this->mergeReviewedIntoFormData($reviewedData, $formData);

        // Build ProjectContext — passed forward to RamsDataBuilderService for all data shaping.
        $projectContext = $this->buildProjectContext($record);

        // Generate fresh AI summaries only when some room overviews lack a saved summary.
        if (! empty($reviewedData['room_overviews'])) {
            // Treat non-array entries as "empty summary" so they trigger summarize().
            $allSummariesPopulated = ! in_array(
                true,
                array_map(
                    fn ($r) => ! is_array($r) || trim((string) ($r['summary'] ?? '')) === '',
                    $reviewedData['room_overviews']
                ),
                true,
            );

            if ($allSummariesPopulated) {
                Log::info('RamsBuilderService::buildFromReview: all room summaries populated, skipping summarize()', [
                    'record_id' => $record->id,
                ]);
            } else {
                Log::info('RamsBuilderService::buildFromReview: regenerating room summaries (some empty)', [
                    'record_id' => $record->id,
                ]);
                $reviewedData['room_overviews'] = $this->roomOverviewSummary->summarize(
                    (array) $reviewedData['room_overviews']
                );
                // Persist updated summaries so the review UI shows the latest output.
                $record->update([
                    'reviewed_data' => array_merge($record->reviewed_data ?? [], [
                        'room_overviews' => $reviewedData['room_overviews'],
                    ]),
                ]);
            }

            $parsedQuote['room_overviews'] = $reviewedData['room_overviews'];
            $parsedQuote['rooms'] = array_values(array_map(
                fn ($r) => is_array($r) ? (string) ($r['room'] ?? '') : '',
                $reviewedData['room_overviews'],
            ));
        }

        Log::info('RamsBuilderService::buildFromReview: inputs prepared', [
            'record_id'       => $record->id,
            'activities'      => $classified['activities'],
            'hazard_count'    => count($risk['hazards']),
            'equipment_count' => count($parsedQuote['equipment']),
            'context_rooms'   => count($projectContext['rooms'] ?? []),
        ]);

        // AI call — method statement only.
        $methodStatement = $this->methodStatementGen->generate(
            $parsedQuote,
            $classified,
            $risk['hazards'] ?? [],
        );

        Log::info('RamsBuilderService::buildFromReview: method statement generated', [
            'record_id'   => $record->id,
            'phase_count' => count($methodStatement['phases'] ?? []),
        ]);

        // Assemble + normalise final data.
        // RamsDataBuilderService handles all ProjectContext integration internally.
        $data = $this->dataBuilder->assemble(
            $parsedQuote,
            $classified,
            $risk,
            $methodStatement,
            $mergedForm,
            $projectContext,
        );

        // Pre-render guard.
        if (empty($data) || ! array_key_exists('method_statement', $data)) {
            throw new \RuntimeException(
                'Pre-render guard failed: generated_data is empty or method_statement key is missing.'
            );
        }

        // Inject scope_of_works and site_logistics from reviewed data into the PDF data bag.
        // Fall back to works_description / a generated summary when scope_of_works was not captured during extraction.
        $scopeOfWorks = trim((string) ($reviewedData['scope_of_works'] ?? ''));
        if ($scopeOfWorks === '') {
            $scopeOfWorks = trim((string) ($reviewedData['works_description'] ?? ''));
        }
        if ($scopeOfWorks === '') {
            $scopeOfWorks = $this->buildScopeFromEquipment($reviewedData);
        }
        $data['scope_of_works']  = $scopeOfWorks;
        $data['site_logistics']  = (array) ($reviewedData['site_logistics'] ?? []);

        // Preserve PM guidance verbatim on generated_data — the compliance upgrade service's
        // scope gates (RamsComplianceUpgradeService) read this to decide whether podium/tower,
        // ceiling-void, rack, and existing-services hazards apply. Without this key set,
        // ground-level-only jobs get boilerplate platform-access content injected by default.
        $msNotes = trim((string) ($reviewedData['method_statement_notes'] ?? ''));
        if ($msNotes !== '') {
            $data['method_statement_notes'] = $msNotes;
        }

        // Inject scope buckets from reviewed data (backward-compat: falls back to whatever
        // dataBuilder assembled from formData when reviewed data does not carry these keys).
        $data['scope_items'] = [
            'decommission' => (array) ($reviewedData['decommission_items'] ?? $data['scope_items']['decommission'] ?? []),
            'retained'     => (array) ($reviewedData['retained_items']     ?? $data['scope_items']['retained']     ?? []),
            'new_install'  => (array) ($reviewedData['new_install_items']  ?? $data['scope_items']['new_install']  ?? []),
        ];

        // ── Tier 1 compliance upgrade (PPE matrix, CDM, risk colour key, etc.) ─
        $data = RamsComplianceUpgradeService::upgrade($data);

        // Persist.
        $record->update([
            'project_ref'    => $data['project']['ref']          ?: $record->project_ref,
            'project_name'   => $data['project']['name']         ?: $record->project_name,
            'client_name'    => $data['project']['client']       ?: $record->client_name,
            'site_address'   => $data['project']['site_address'] ?: $record->site_address,
            'generated_data' => $data,
        ]);

        // Render DOCX.
        $path = $this->renderer->render($data, $record);

        Log::info('RamsBuilderService::buildFromReview: DOCX written', [
            'record_id' => $record->id,
            'path'      => $path,
        ]);

        return $path;
    }

    // ── Conversion helpers ────────────────────────────────────────────────────

    /**
     * Convert reviewed_data into the parsedQuote shape expected by downstream services.
     * Confidence is always 1.0 for reviewed data — the human has approved it.
     */
    private function reviewedToParsed(array $rd): array
    {
        // Only hardware items belong in the equipment schedule and risk assessment.
        // Services, cables, consumables and options are excluded.
        $hardware  = array_values(array_filter(
            $rd['equipment'] ?? [],
            fn ($e) => in_array(
                strtolower(trim((string) ($e['category'] ?? 'hardware'))),
                ['hardware', ''],
                true,
            ),
        ));

        $equipment = array_values(array_map(
            fn ($e) => [
                'qty'         => max(1, (int) ($e['quantity'] ?? 1)),
                'description' => (string) ($e['name']  ?? ''),
                'location'    => (string) ($e['area']  ?? ''),   // preserve room/area
            ],
            $hardware,
        ));

        $notes = trim((string) ($rd['method_statement_notes'] ?? ''));
        $roomOverviews = array_values(array_filter(
            $rd['room_overviews'] ?? [],
            fn ($r) => is_array($r) && trim((string) ($r['room'] ?? '')) !== ''
        ));
        $rooms = array_values(array_map(
            fn ($r) => (string) ($r['room'] ?? ''),
            $roomOverviews,
        ));

        return [
            'client'         => (string) ($rd['project']['client_name']  ?? ''),
            'site'           => (string) ($rd['project']['site_address']  ?? ''),
            'ref'            => (string) ($rd['project']['quote_ref']     ?? ''),
            'project_name'   => (string) ($rd['project']['project_name']  ?? ''),
            'works_summary'  => $notes,
            'equipment'      => $equipment,
            'tasks'          => $notes !== '' ? [$notes] : [],
            'rooms'          => $rooms,
            'room_overviews' => $roomOverviews,
            'confidence'     => 1.0,
            'scope_of_works' => trim((string) ($rd['scope_of_works'] ?? '')),
            'works_overview' => trim((string) ($rd['works_overview']  ?? '')),
        ];
    }

    /**
     * Convert reviewed_data.activities into the classified shape.
     */
    private function reviewedToClassified(array $rd): array
    {
        $activities = array_values(array_filter(
            array_column($rd['activities'] ?? [], 'key'),
            fn ($k) => $k !== '',
        ));

        $labels = array_column($rd['activities'] ?? [], 'label');

        return [
            'activities'        => $activities,
            'categories'        => [],
            'summary'           => implode(', ', array_filter($labels)),
            'heavy_items'       => [],
            'drilling_required' => false,
        ];
    }

    /**
     * Convert reviewed_data hazards + ppe + access into the risk shape
     * expected by RamsDataBuilderService::assemble().
     */
    private function reviewedToRisk(array $rd, ?int $userId = null): array
    {
        $hazards = array_values(array_map(function (array $h, int $i) {
            $preL = null;
            $preS = null;
            $postL = null;
            $postS = null;
            $controls = (array) ($h['control_measures'] ?? []);

            $name = (string) ($h['hazard'] ?? '');

            // Prefer hazard library values when available
            if ($name !== '') {
                $resolved = $this->hazardLibrary->resolveFromSeeds($userId ?? 0, [$name], false);
                $tpl = $resolved->first();
                if ($tpl) {
                    $preL = (int) ($tpl->pre_likelihood  ?? null);
                    $preS = (int) ($tpl->pre_severity    ?? null);
                    $postL = (int) ($tpl->post_likelihood ?? null);
                    $postS = (int) ($tpl->post_severity   ?? null);
                    if (empty($controls)) {
                        $controls = (array) ($tpl->controls ?? []);
                    }
                }
            }

            if ($preL === null || $preS === null) {
                [$preL, $preS] = $this->riskLevelsFromString((string) ($h['risk'] ?? 'Medium'));
            }

            if ($postL === null || $postS === null) {
                $postL = max(1, $preL - 1);
                $postS = max(1, $preS - 1);
            }
            return [
                'id'              => $i + 1,
                'hazard'          => $name,
                'persons_at_risk' => ['21CAV Staff', 'Client Staff', 'Others'],
                'pre_likelihood'  => $preL,
                'pre_severity'    => $preS,
                'controls'        => array_values(array_filter(
                                         array_map('strval', $controls),
                                         fn ($s) => strlen(trim($s)) > 0,
                                     )),
                'post_likelihood' => $postL,
                'post_severity'   => $postS,
            ];
        }, $rd['hazards'] ?? [], array_keys($rd['hazards'] ?? [])));

        // Convert access booleans to access_equipment strings.
        $access = $rd['access'] ?? [];
        $accessEquipment = [];
        if (! empty($access['ladders']))       $accessEquipment[] = 'Podium Steps';
        if (! empty($access['tower']))         $accessEquipment[] = 'Access Tower';
        if (! empty($access['scissor_lift']))  $accessEquipment[] = 'Scissor Lift';
        if (empty($accessEquipment))           $accessEquipment   = ['Kick Stool'];

        return [
            'hazards'          => $hazards,
            'ppe'              => array_values(array_filter(
                                      array_map('strval', (array) ($rd['ppe'] ?? [])),
                                      fn ($s) => strlen(trim($s)) > 0,
                                  )),
            'access_equipment' => $accessEquipment,
        ];
    }

    /**
     * Merge reviewed project/programme fields into formData so downstream services
     * pick up the correct values. Reviewed data takes priority over original form_data.
     *
     * Personnel is now stored in reviewed_data['programme'] (new schema) so we read
     * from there first and fall back to the legacy reviewed_data['project'] fields.
     */
    private function mergeReviewedIntoFormData(array $reviewedData, array $formData): array
    {
        $project   = $reviewedData['project']   ?? [];
        $programme = $reviewedData['programme'] ?? [];

        // ── Personnel — prefer programme section (new schema) ─────────────────
        $pmName  = trim((string) ($programme['project_manager_name'] ?? $project['project_manager']      ?? ''));
        $pmPhone = trim((string) ($programme['project_manager_phone'] ?? ''));
        $pmEmail = trim((string) ($programme['project_manager_email'] ?? ''));
        $leName  = trim((string) ($programme['lead_engineer_name']   ?? $project['lead_engineer']        ?? ''));
        $lePhone = trim((string) ($programme['lead_engineer_phone']  ?? ''));

        $addEngsArr = array_values(array_filter(
            array_map('trim', (array) ($programme['additional_engineers'] ?? [])),
            fn (string $s) => $s !== '',
        ));
        $progsArr = array_values(array_filter(
            array_map('trim', (array) ($programme['programmers'] ?? [])),
            fn (string $s) => $s !== '',
        ));

        // ── Engineering team array for DocxBuilderService::addTeamTable() ─────
        $team = [];
        if ($pmName !== '') {
            $team[] = ['role' => 'Project Manager', 'name' => $pmName, 'mobile' => $pmPhone];
        }
        if ($leName !== '') {
            $team[] = ['role' => 'Lead Engineer', 'name' => $leName, 'mobile' => $lePhone];
        }
        foreach ($addEngsArr as $eng) {
            $team[] = ['role' => 'Engineer', 'name' => $eng, 'mobile' => ''];
        }
        foreach ($progsArr as $prog) {
            $team[] = ['role' => 'Programmer', 'name' => $prog, 'mobile' => ''];
        }

        // ── Auto-generate a scope summary when works_description is blank ─────
        $worksDesc = trim((string) ($reviewedData['method_statement_notes'] ?? ''));
        if ($worksDesc === '') {
            $worksDesc = $this->buildScopeFromEquipment($reviewedData);
        }

        // ── Start date ────────────────────────────────────────────────────────
        $startDate = trim((string) ($programme['planned_start_date'] ?? ''));

        $merged = array_merge($formData, array_filter([
            'project_ref'          => ($project['quote_ref']    ?? '') ?: null,
            'project_name'         => ($project['project_name'] ?? '') ?: null,
            'client_name'          => ($project['client_name']  ?? '') ?: null,
            'site_address'         => ($project['site_address'] ?? '') ?: null,
            'site_contact'         => ($project['site_contact'] ?? '') ?: null,
            'doc_author'           => ($project['prepared_by']  ?? '') ?: null,
            'project_manager'      => $pmName  ?: null,
            'lead_engineer'        => $leName  ?: null,
            'additional_engineers' => $addEngsArr ? implode(', ', $addEngsArr)
                                        : (($project['additional_engineers'] ?? '') ?: null),
            'programmer'           => $progsArr ? implode(', ', $progsArr)
                                        : (($project['programmer'] ?? '') ?: null),
            'emergency_contact'    => $pmName  ?: null,
            'emergency_tel'        => $pmPhone ?: null,
            'start_date'           => $startDate ?: null,
            'works_description'    => $worksDesc ?: null,
        ]));

        // team must always be set (array_filter would strip an empty array)
        $merged['team'] = $team;

        return $merged;
    }

    /**
     * Build a plain-English scope sentence from the equipment list and room names
     * when the user hasn't written a method statement / works description.
     *
     * When all items within an area share the same qty (e.g. 9× per line item for
     * "Small Room - 4 Person"), that qty is treated as the number of identical rooms
     * in that area. This produces "9× Small Room - 4 Person" rather than "1 space".
     */
    private function buildScopeFromEquipment(array $reviewedData): string
    {
        $equipment = array_values(array_filter(
            $reviewedData['equipment'] ?? [],
            fn ($e) => in_array(strtolower(trim((string) ($e['category'] ?? 'hardware'))), ['hardware', ''], true),
        ));

        if (empty($equipment)) {
            return '';
        }

        // Group items by area and infer room count per area.
        // If every line item in an area shares the same qty, that qty = room count.
        $areaQtys = [];
        foreach ($equipment as $item) {
            $area = trim((string) ($item['area'] ?? ''));
            if ($area === '') {
                continue;
            }
            $areaQtys[$area][] = max(1, (int) ($item['quantity'] ?? 1));
        }

        $roomLabels = [];
        $totalRooms = 0;
        foreach ($areaQtys as $area => $qtys) {
            $uniqueQtys = array_unique($qtys);
            $roomCount  = count($uniqueQtys) === 1 ? reset($uniqueQtys) : 1;
            $totalRooms += $roomCount;
            $roomLabels[$area] = $roomCount;
        }

        // Top-3 hardware items by quantity (descending) for the item list.
        usort($equipment, fn ($a, $b) => (int) ($b['quantity'] ?? 1) <=> (int) ($a['quantity'] ?? 1));
        $topItems = array_slice($equipment, 0, 3);
        $itemList = implode(', ', array_map(
            fn ($e) => (($e['quantity'] ?? 1) > 1 ? ($e['quantity'] . '× ') : '') . ($e['name'] ?? ''),
            $topItems,
        ));
        if (count($equipment) > 3) {
            $itemList .= ' and associated AV equipment';
        }

        $siteName = trim((string) ($reviewedData['project']['site_name'] ?? $reviewedData['project']['site_address'] ?? ''));

        $scope = 'Supply and installation of ' . $itemList;

        if (! empty($roomLabels)) {
            // Build room description: "9× Small Room - 4 Person, Medium Room + Boardroom"
            $roomParts = [];
            foreach ($roomLabels as $area => $count) {
                $roomParts[] = ($count > 1 ? $count . '× ' : '') . $area;
            }
            $roomStr = implode(', ', array_slice($roomParts, 0, 4));
            if (count($roomParts) > 4) {
                $roomStr .= ' and others';
            }

            $scope .= ' across ' . $totalRooms . ' ' . ($totalRooms === 1 ? 'space' : 'spaces');
            if ($siteName !== '') {
                $scope .= ' at ' . $siteName;
            }
            $scope .= ' (' . $roomStr . ')';
        } elseif ($siteName !== '') {
            $scope .= ' at ' . $siteName;
        }

        return $scope . '. Works will be carried out by qualified AV engineers during agreed working hours.';
    }

    /**
     * Map a risk string to [likelihood, severity] integers (1–5).
     */
    private function riskLevelsFromString(string $risk): array
    {
        return match (strtolower(trim($risk))) {
            'high'   => [4, 4],
            'low'    => [2, 2],
            default  => [3, 3],
        };
    }

    // =========================================================================
    // PRIVATE — ORIGINAL PIPELINE (for buildFromForm / buildFromQuote)
    // =========================================================================

    private function pipeline(array $parsed, array $formData, RamsDocument $record): string
    {
        try {
            return $this->runPipeline($parsed, $formData, $record);
        } catch (\Throwable $e) {
            Log::error('RamsBuilderService: pipeline failed', [
                'record_id' => $record->id,
                'error'     => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ]);
            $record->update(['status' => RamsDocument::STATUS_FAILED]);
            throw $e;
        }
    }

    private function runPipeline(array $parsed, array $formData, RamsDocument $record): string
    {
        $confidence = (float) ($parsed['confidence'] ?? 1.0);

        if ($confidence < self::CONFIDENCE_THRESHOLD) {
            Log::warning('RamsBuilderService: confidence below threshold — setting awaiting_review, skipping AI and render', [
                'record_id'  => $record->id,
                'confidence' => $confidence,
                'threshold'  => self::CONFIDENCE_THRESHOLD,
            ]);
            $record->update(['status' => RamsDocument::STATUS_AWAITING_REVIEW]);
            return '';
        }

        $classified = $this->classifier->classify($parsed['equipment'] ?? []);

        $risk = $this->riskResolver->resolve(
            $classified['activities'],
            $classified['drilling_required'] ?? false,
            $record->user_id,
            $formData['hazards'] ?? [],
            $formData['persons_at_risk'] ?? [],
        );

        // Build ProjectContext — passed forward to RamsDataBuilderService for all data shaping.
        $projectContext = $this->buildProjectContext($record);

        $methodStatement = $this->methodStatementGen->generate(
            $parsed,
            $classified,
            $risk['hazards'] ?? [],
        );

        // Assemble + normalise final data.
        // RamsDataBuilderService handles all ProjectContext integration internally.
        $data = $this->dataBuilder->assemble(
            $parsed,
            $classified,
            $risk,
            $methodStatement,
            $formData,
            $projectContext,
        );

        if (empty($data) || ! array_key_exists('method_statement', $data)) {
            throw new \RuntimeException(
                'Pre-render guard failed: generated_data is empty or method_statement key is missing.'
            );
        }

        // Populate scope_of_works for the PDF template from works_description when not already set.
        if (empty($data['scope_of_works'])) {
            $data['scope_of_works'] = trim((string) ($formData['works_description'] ?? $parsed['works_summary'] ?? ''));
        }

        // ── Tier 1 compliance upgrade (PPE matrix, CDM, risk colour key, etc.) ─
        $data = RamsComplianceUpgradeService::upgrade($data);

        $record->update([
            'project_ref'    => $data['project']['ref']          ?: $record->project_ref,
            'project_name'   => $data['project']['name']         ?: $record->project_name,
            'client_name'    => $data['project']['client']       ?: $record->client_name,
            'site_address'   => $data['project']['site_address'] ?: $record->site_address,
            'generated_data' => $data,
        ]);

        $path = $this->renderer->render($data, $record);

        Log::info('RamsBuilderService: DOCX written', [
            'record_id' => $record->id,
            'path'      => $path,
        ]);

        return $path;
    }

    // =========================================================================
    // PRIVATE — PROJECT CONTEXT BUILDING
    // =========================================================================

    /**
     * Build a ProjectContext from the RamsDocument's project's site survey.
     *
     * Returns a safe fallback (empty rooms) when no project or no survey exists,
     * ensuring backwards compatibility for projects created without site surveys.
     *
     * @param  RamsDocument  $record
     * @return array  { project_id: int, rooms: array[] }
     */
    private function buildProjectContext(RamsDocument $record): array
    {
        $fallback = [
            'project_id' => 0,
            'rooms'      => [],
        ];

        try {
            $fallback['project_id'] = (int) ($record->project_id ?? 0);
            $project = $record->project;

            if (! $project) {
                return $fallback;
            }

            // Get the latest completed survey, or the most recent one if none completed
            $survey = $project->siteSurveys()
                ->where('status', 'completed')
                ->latest()
                ->first();

            if (! $survey) {
                $survey = $project->siteSurveys()->latest()->first();
            }

            if (! $survey || empty($survey->survey_data)) {
                return $fallback;
            }

            return ProjectContextBuilder::build($survey);
        } catch (\Throwable $e) {
            Log::warning('RamsBuilderService: ProjectContext build failed, using fallback', [
                'record_id' => $record->id,
                'error'     => $e->getMessage(),
            ]);

            return $fallback;
        }
    }
}
