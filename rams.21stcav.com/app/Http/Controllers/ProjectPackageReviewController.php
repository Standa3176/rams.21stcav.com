<?php

namespace App\Http\Controllers;

use App\Models\ProjectPackage;
use App\Services\EquipmentClassifierService;
use App\Services\RamsReviewDataService;
use App\Services\RamsReviewValidatorService;
use App\Services\RiskTemplateResolverService;
use App\Services\RoomOverviewSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Review and edit the *project-level* extracted data used by all documents.
 *
 * This reuses the RAMS review UI but persists changes to ProjectPackage
 * so the same data powers RAMS, O&M, surveys, cable schedules, etc.
 */
class ProjectPackageReviewController extends Controller
{
    /** Standard PPE options matching RiskTemplateResolverService output. */
    private const PPE_OPTIONS = [
        'Safety Boots (steel toe cap)',
        'Hi-Visibility Vest',
        'Safety Glasses',
        'Latex / Nitrile Gloves',
        'Hard Hat',
        'Dust Mask (FFP2)',
        'Hearing Protection',
        'Gloves',
        'Overalls',
        'Harness',
        'Face Shield',
    ];

    public function __construct(
        private readonly RamsReviewDataService      $reviewDataService,
        private readonly RamsReviewValidatorService $reviewValidator,
        private readonly EquipmentClassifierService $equipmentClassifier,
        private readonly RiskTemplateResolverService $riskResolver,
        private readonly RoomOverviewSummaryService  $roomSummaryService,
    ) {}

    public function show(ProjectPackage $package): View
    {
        $this->authorizePackage($package);

        $raw = $package->extracted_data ?? [];

        // Map flat extracted_data (QuoteImport) into the review schema.
        if (empty($raw['project']) && ! empty($raw)) {
            $raw['project'] = [
                'project_name' => (string) ($raw['project_name'] ?? ''),
                'quote_ref'    => (string) ($raw['qw_number'] ?? $raw['quote_ref'] ?? ''),
                'client_name'  => (string) ($raw['client_name'] ?? ''),
                'site_name'    => (string) ($raw['site_name'] ?? ''),
                'site_address' => (string) ($raw['site_address'] ?? ''),
                'prepared_by'  => (string) ($raw['prepared_by'] ?? ''),
                'overview'     => (string) ($raw['overview'] ?? ''),
            ];
        }

        // Fill missing project fields from linked project/defaults without
        // clobbering non-empty extracted values.
        $raw['project'] = $this->fillProjectDefaults(
            (array) ($raw['project'] ?? []),
            $package
        );

        $rawEquipment = null;
        // Prefer canonical extracted_data equipment first (usually richer and
        // includes part numbers), then legacy list fields.
        if (! empty($raw['equipment']) && is_array($raw['equipment'])) {
            $rawEquipment = $raw['equipment'];
        } elseif (! empty($raw['equipment_list']) && is_array($raw['equipment_list'])) {
            $rawEquipment = $raw['equipment_list'];
        } elseif (! empty($package->equipment_list) && is_array($package->equipment_list)) {
            $rawEquipment = $package->equipment_list;
        } elseif (! empty($raw['line_items']) && is_array($raw['line_items'])) {
            $rawEquipment = $raw['line_items'];
        } elseif (! empty($raw['items']) && is_array($raw['items'])) {
            $rawEquipment = $raw['items'];
        }

        if (! empty($rawEquipment) && is_array($rawEquipment)) {
            // Handle a single associative equipment object.
            if (isset($rawEquipment['quantity']) || isset($rawEquipment['name']) || isset($rawEquipment['description'])) {
                $rawEquipment = [$rawEquipment];
            }

            $raw['equipment'] = array_values(array_filter(array_map(function ($item) {
                if (! is_array($item)) {
                    return null;
                }

                $name = (string) ($item['name'] ?? $item['description'] ?? $item['title'] ?? '');
                if (trim($name) === '') {
                    return null;
                }

                $partNumber = (string) ($item['part_number'] ?? $item['part_no'] ?? $item['sku'] ?? $item['code'] ?? $item['model'] ?? '');
                $quantity   = (int) ($item['quantity'] ?? $item['qty'] ?? 1);
                $area       = (string) ($item['area'] ?? $item['location'] ?? $item['room'] ?? '');

                return [
                    'quantity'    => max(1, $quantity),
                    'part_number' => $partNumber,
                    'name'        => $name,
                    'area'        => $area,
                    'category'    => $this->normaliseEquipmentCategory([
                        'name'        => $name,
                        'part_number' => $partNumber,
                        'category'    => $item['category'] ?? '',
                    ]),
                ];
            }, $rawEquipment)));
        }

        // Hazards sometimes arrive as a list of strings from quote extraction.
        if (! empty($raw['hazards']) && is_array($raw['hazards']) && isset($raw['hazards'][0]) && is_string($raw['hazards'][0])) {
            $raw['hazards'] = array_map(function (string $hazard) {
                return [
                    'activity_key'     => '',
                    'hazard'           => $hazard,
                    'risk'             => 'Medium',
                    'control_measures' => [],
                ];
            }, $raw['hazards']);
        }

        // Activities can be provided as a flat list of strings.
        if (! empty($raw['activities']) && is_array($raw['activities']) && isset($raw['activities'][0]) && is_string($raw['activities'][0])) {
            $raw['activities'] = array_map(function (string $label) {
                $key = $this->slugify($label);
                return [
                    'key'   => $key,
                    'label' => $label,
                ];
            }, $raw['activities']);
        }

        // ── Room / Space Overviews ────────────────────────────────────────────
        // Only derive rooms from HARDWARE category equipment, and additionally
        // reject any area name that matches known non-room descriptors (section
        // headings, category labels) that QuoteWerks or the AI commonly emit.
        static $excludedAreaWords = [
            'cabling', 'cable', 'cables',
            'professional services', 'professional service',
            'support services', 'support service', 'support',
            'services', 'service',
            'consumables', 'consumable',
            'sundries', 'sundry',
            'delivery', 'deliveries',
            'installation', 'installations',
            'labour', 'labor',
            'general', 'other', 'misc', 'miscellaneous',
            'hardware', 'software',
            'options', 'option',
        ];

        $roomNamesFromEquip = [];
        foreach (($raw['equipment'] ?? []) as $item) {
            $cat  = strtolower(trim((string) ($item['category'] ?? 'hardware')));
            if ($cat !== 'hardware') {
                continue;
            }
            $area      = trim((string) ($item['area'] ?? ''));
            $areaLower = strtolower($area);
            if ($area === '') {
                continue;
            }
            // Reject exact matches and areas whose entire value is one of the
            // excluded keywords (e.g. "Cabling", "Professional Services").
            if (in_array($areaLower, $excludedAreaWords, true)) {
                continue;
            }
            if (! in_array($area, $roomNamesFromEquip, true)) {
                $roomNamesFromEquip[] = $area;
            }
        }

        // ── room_summaries → room_overviews defensive map ─────────────────────
        // The Claude PDF-vision extractor (QuoteExtractorService, invoked by
        // QuoteImportService::import / ::reimport) writes per-room data under
        // `room_summaries` with shape [{room, summary}, ...]. ExtractQuoteJob
        // normalises this into `room_overviews` via mergeParsedQuoteData(), but
        // the QuoteImportService path does not. Without this seed, packages
        // imported via that path render "No spaces detected" on first load
        // even when room data is present in extracted_data.
        //
        // We project to the canonical 4-key shape here so the room_overviews
        // gate at line 193 fires AND the row carries the AI's summary text as
        // the initial overview value (the PM can edit on first save).
        //
        // room_summaries remains intact in extracted_data — it has independent
        // readers (resources/views/pdf/rams.blade.php, DocxBuilderService,
        // resources/views/quote-import/review.blade.php).
        $summaryByRoom = [];
        foreach (($raw['room_summaries'] ?? []) as $rs) {
            if (! is_array($rs)) {
                continue;
            }
            $rsName = trim((string) ($rs['room'] ?? ''));
            if ($rsName === '' || in_array(strtolower($rsName), $excludedAreaWords, true)) {
                continue;
            }
            $summaryByRoom[$rsName] = trim((string) ($rs['summary'] ?? ''));
        }

        // ── Build canonical room list ──────────────────────────────────────────
        // CURATED MODE (room_overviews already saved): use saved list as authority.
        // This prevents rooms the PM explicitly deleted from re-appearing on reload.
        // Equipment areas not yet in the curated list are appended so genuinely new
        // areas (e.g. after a re-extraction) still surface.
        //
        // FIRST-LOAD MODE (room_overviews empty): derive from equipment + parser
        // rooms + Claude PDF-vision room_summaries (see $summaryByRoom above).
        if (! empty($raw['room_overviews'])) {
            // ── Curated mode: room_overviews has been saved at least once ──────
            // The PM's saved list is the SOLE authority.
            // We do NOT append equipment areas here — if a PM deleted a room
            // that still exists in the equipment list, it must stay deleted.
            // They can use "+ Add Space" to re-add rooms manually.
            $allRoomNames = [];
            foreach ($raw['room_overviews'] as $ro) {
                $name = trim((string) ($ro['room'] ?? ''));
                if ($name === '' || in_array(strtolower($name), $excludedAreaWords, true)) {
                    continue;
                }
                if (! in_array($name, $allRoomNames, true)) {
                    $allRoomNames[] = $name;
                }
            }
        } else {
            // ── First load: derive from equipment + parser rooms + AI room_summaries
            $allRoomNames = $roomNamesFromEquip;
            foreach (($raw['rooms'] ?? []) as $room) {
                $name = is_string($room) ? $room : (string) ($room['room'] ?? $room['name'] ?? '');
                $name = trim($name);
                if ($name === '' || in_array(strtolower($name), $excludedAreaWords, true)) {
                    continue;
                }
                if (! in_array($name, $allRoomNames, true)) {
                    $allRoomNames[] = $name;
                }
            }
            // Seed from Claude PDF-vision room_summaries so packages re-extracted
            // via QuoteImportService::reimport surface their AI-detected rooms.
            foreach (array_keys($summaryByRoom) as $name) {
                if (! in_array($name, $allRoomNames, true)) {
                    $allRoomNames[] = $name;
                }
            }
            sort($allRoomNames, SORT_NATURAL | SORT_FLAG_CASE);
        }

        // Index any already-saved overviews by room name so edits survive re-renders.
        $savedOverviewsByRoom = [];
        foreach (($raw['room_overviews'] ?? []) as $ro) {
            $n = trim((string) ($ro['room'] ?? ''));
            if ($n !== '') {
                $savedOverviewsByRoom[$n] = $ro;
            }
        }

        // Phase 22.1 closure (Plan 07): editPayload emits the canonical 4-key
        // per-room shape only. The legacy `summary` and `description` keys are
        // dropped — they were posted by dead form fields that have been removed
        // from review.blade.php this same plan. See 22.1-VERIFICATION.md gaps.
        //
        // Sidebar fix 2026-05-14: when no saved row exists for a room but the
        // Claude PDF-vision extractor returned a summary, use it as the seed
        // `overview` value so the PM sees the AI-detected prose on first load.
        // See .planning/notes/2026-05-14-extracted-data-room-key-mismatch.md.
        $raw['room_overviews'] = array_map(function (string $roomName) use ($savedOverviewsByRoom, $summaryByRoom): array {
            $saved = $savedOverviewsByRoom[$roomName] ?? [];
            $overview = (string) ($saved['overview'] ?? '');
            if ($overview === '' && isset($summaryByRoom[$roomName])) {
                $overview = $summaryByRoom[$roomName];
            }
            return [
                'room'             => $roomName,
                'overview'         => $overview,
                'works_summary'    => (string) ($saved['works_summary']    ?? ''),
                'solution_type_id' => (int)    ($saved['solution_type_id'] ?? 0) ?: null,
            ];
        }, $allRoomNames);

        // ── Backfill Activities / Hazards / PPE ──────────────────────────────
        // When these are absent (new import with no AI seeding), run the local
        // classifier + risk resolver so the review page always starts populated.
        if (empty($raw['activities']) || empty($raw['hazards']) || empty($raw['ppe'])) {
            $classifierItems = array_map(fn ($item) => [
                'qty'         => (int) ($item['quantity'] ?? 1),
                'description' => (string) ($item['name']  ?? ''),
                'location'    => (string) ($item['area']  ?? ''),
            ], $raw['equipment'] ?? []);

            if (! empty($classifierItems)) {
                $classified = $this->equipmentClassifier->classify($classifierItems);

                if (empty($raw['activities'])) {
                    $raw['activities'] = array_map(fn ($key) => [
                        'key'   => $key,
                        'label' => $this->equipmentClassifier->activityLabel($key),
                    ], $classified['activities'] ?? []);
                }

                $resolved = $this->riskResolver->resolve(
                    $classified['activities'] ?? [],
                    $classified['drilling_required'] ?? false,
                );

                if (empty($raw['hazards'])) {
                    $raw['hazards'] = array_values(array_map(function (array $h): array {
                        $score = max(1, (int) ($h['pre_likelihood'] ?? 3))
                               * max(1, (int) ($h['pre_severity']   ?? 3));
                        return [
                            'activity_key'     => '',
                            'hazard'           => (string) ($h['hazard'] ?? ''),
                            'risk'             => $score >= 12 ? 'High' : ($score >= 6 ? 'Medium' : 'Low'),
                            'control_measures' => array_values(array_filter(
                                array_map('strval', (array) ($h['controls'] ?? [])),
                                fn (string $s) => strlen(trim($s)) > 0,
                            )),
                        ];
                    }, $resolved['hazards'] ?? []));
                }

                if (empty($raw['ppe'])) {
                    $raw['ppe'] = $resolved['ppe'] ?? [];
                }
            }
        }

        $reviewPayload = $this->reviewDataService->normalise($raw);
        // Carry through room qty settings (not part of the core normalise schema)
        $reviewPayload['room_qtys'] = (array) ($raw['room_qtys'] ?? []);
        $ppeOptions    = self::PPE_OPTIONS;

        return view('project-packages.review', compact('package', 'reviewPayload', 'ppeOptions'));
    }

    public function update(Request $request, ProjectPackage $package): RedirectResponse
    {
        $this->authorizePackage($package);

        // Phase 23 Plan 06 — DRAW-46 D-03 zone validation.
        // Pitfall 8 XSS mitigation (T-23-06-A1/A2): reject any zone string
        // that doesn't match the Unicode-letter regex allowlist.
        $this->validateEquipmentZones($request);

        $payload = $this->parseReviewPayload($request);

        // Carry over meta from extracted_data if not in payload.
        if (empty($payload['meta']) && ! empty($package->extracted_data['meta'])) {
            $payload['meta'] = $package->extracted_data['meta'];
        }

        $merged = array_merge($package->extracted_data ?? [], $payload);

        // ── Original qty snapshot ─────────────────────────────────────────────
        // On the very first save, record the total qty per part number as the
        // canonical baseline. Subsequent saves compare against this so the PM
        // can verify that splits/edits haven't accidentally changed overall totals.
        if (empty($merged['_original_totals'])) {
            $originalTotals = [];
            foreach (($payload['equipment'] ?? []) as $item) {
                $part = trim((string) ($item['part_number'] ?? ''));
                $qty  = max(1, (int) ($item['quantity'] ?? 1));
                if ($part !== '') {
                    $originalTotals[$part] = ($originalTotals[$part] ?? 0) + $qty;
                }
            }
            if (! empty($originalTotals)) {
                $merged['_original_totals'] = $originalTotals;
            }
        }

        $package->update([
            'extracted_data' => $merged,
            'equipment_list' => $payload['equipment'] ?? [],
            'status'         => ProjectPackage::STATUS_REVIEWED,
        ]);

        // Update core project fields so all docs stay in sync.
        //
        // Phase 22.1 D-03: Project.works_description is no longer auto-written
        // from method_statement_notes. The PM sets works_description explicitly
        // via the project-edit form (Projects::edit); the package-review form
        // persists scope text into reviewed_data.scope_of_works only.
        if ($package->project) {
            $project = $package->project;
            $proj    = $payload['project'] ?? [];

            $project->update([
                'name'              => $proj['project_name'] ?? $project->name,
                'ref'               => $proj['quote_ref'] ?? $project->ref,
                'client_name'       => $proj['client_name'] ?? $project->client_name,
                'site_address'      => $proj['site_address'] ?? $project->site_address,
            ]);
        }

        return redirect()
            ->route('project-packages.review.show', $package)
            ->with('success', 'Project data saved successfully.');
    }

    public function approve(Request $request, ProjectPackage $package): RedirectResponse
    {
        $this->authorizePackage($package);

        // Phase 23 Plan 06 — DRAW-46 D-03 zone validation (mirrors update()).
        $this->validateEquipmentZones($request);

        $payload = $this->parseReviewPayload($request);

        if (empty($payload['meta']) && ! empty($package->extracted_data['meta'])) {
            $payload['meta'] = $package->extracted_data['meta'];
        }
        $payload['meta']['source'] = 'reviewed';

        try {
            $this->reviewValidator->validate($payload);
        } catch (ValidationException $e) {
            return back()
                ->withErrors($e->errors())
                ->withInput()
                ->with('error', 'Please fix the highlighted errors before approving.');
        }

        $merged = array_merge($package->extracted_data ?? [], $payload);

        // Phase 22.1 D-06: compute and persist scope_of_works_bullets at
        // approve-time (not at render-time). Locks the bullets to the
        // approved snapshot so post-approval equipment changes cannot drift
        // the rendered scope. The render-time RamsComplianceUpgradeService::
        // upgradeScopeOfWorks() short-circuits when this field is non-empty
        // (read-through cache).
        $projectContext = [
            'rooms' => $merged['room_overviews'] ?? [],
        ];
        $bullets = \App\Services\Rams\RamsComplianceUpgradeService::computeScopeOfWorksBulletsForApprove(
            $merged,
            $projectContext,
        );
        if (! empty($bullets)) {
            $merged['scope_of_works_bullets'] = $bullets;
        }

        $package->update([
            'extracted_data' => $merged,
            'equipment_list' => $payload['equipment'] ?? [],
            'status'         => ProjectPackage::STATUS_REVIEWED,
        ]);

        // Phase 22.1 D-03: same invariant as save() — Project.works_description
        // is no longer auto-written from method_statement_notes during approve.
        // scope_of_works is the canonical project-wide scope store; the PM
        // edits works_description explicitly through the project-edit form.
        if ($package->project) {
            $project = $package->project;
            $proj    = $payload['project'] ?? [];
            $project->update([
                'name'              => $proj['project_name'] ?? $project->name,
                'ref'               => $proj['quote_ref'] ?? $project->ref,
                'client_name'       => $proj['client_name'] ?? $project->client_name,
                'site_address'      => $proj['site_address'] ?? $project->site_address,
            ]);
        }

        return redirect()
            ->route('projects.show', $package->project_id)
            ->with('success', 'Project data approved and ready for document generation.');
    }

    /**
     * AJAX — generate a Scope of Works paragraph using room overview data.
     * Called by the "✨ Generate" button in the Project Details section.
     */
    public function generateScopeOfWorks(Request $request, ProjectPackage $package): JsonResponse
    {
        $this->authorizePackage($package);

        $extracted = $package->extracted_data ?? [];

        // Build input from current room_overviews (most reliable source)
        $roomOverviews = array_filter(
            (array) ($extracted['room_overviews'] ?? []),
            fn ($r) => is_array($r) && trim((string) ($r['room'] ?? '')) !== ''
        );

        if (empty($roomOverviews)) {
            return response()->json(['error' => 'No room overviews found. Please add room overviews in Section 2 first.'], 422);
        }

        // Build a concise room summary string for the AI
        $solutionTypeIds = array_filter(array_unique(array_map(
            fn ($r) => (int) ($r['solution_type_id'] ?? 0),
            $roomOverviews
        )));
        $solutionTypes = [];
        if (! empty($solutionTypeIds)) {
            foreach (\App\Models\SolutionType::whereIn('id', $solutionTypeIds)->get() as $st) {
                $solutionTypes[$st->id] = $st;
            }
        }

        $roomLines = [];
        foreach ($roomOverviews as $ro) {
            $room    = trim((string) ($ro['room'] ?? ''));
            // Phase 22.1 Plan 07: canonical key is `works_summary`. The legacy
            // `summary` fallback is dead — read-side normaliser projects to the
            // canonical 4-key shape before this point (see RamsReviewDataService
            // ::normaliseRoomOverviews).
            $summary = trim((string) ($ro['works_summary'] ?? ''));
            $overview = trim((string) ($ro['overview'] ?? ''));
            $stId    = (int) ($ro['solution_type_id'] ?? 0);
            $stName  = $stId ? (($solutionTypes[$stId] ?? null)?->name ?? '') : '';

            $desc = $summary ?: $overview;
            if ($room === '' || $desc === '') {
                continue;
            }

            $label = $stName ? "{$room} ({$stName})" : $room;
            $roomLines[] = "- {$label}: {$desc}";
        }

        if (empty($roomLines)) {
            return response()->json(['error' => 'Room overviews have no content. Please write overview text or generate summaries first.'], 422);
        }

        $projectName = $extracted['project']['project_name'] ?? $package->project_name ?? 'this project';
        $clientName  = $extracted['project']['client_name'] ?? '';
        $siteAddress = $extracted['project']['site_address'] ?? '';

        $roomBlock = implode("\n", $roomLines);

        try {
            $prompt = (new \App\Core\AI\Prompts\ScopeOfWorksPrompt())->withContext([
                'project_name' => $projectName,
                'client_name'  => $clientName,
                'site_address' => $siteAddress,
                'room_lines'   => $roomBlock,
            ]);
            $result = \App\Core\AI\AIManager::run($prompt, []);
            $text          = trim((string) ($result['scope_of_works'] ?? ''));
            $worksOverview = trim((string) ($result['works_overview'] ?? ''));
        } catch (\Throwable $e) {
            return response()->json(['error' => 'AI generation failed. Please try again.'], 500);
        }

        if ($text === '') {
            return response()->json(['error' => 'AI returned an empty response. Please try again.'], 500);
        }

        return response()->json([
            'scope_of_works' => $text,
            'works_overview' => $worksOverview,
        ]);
    }

    /**
     * AJAX — generate an AV Works Summary for a single room using AI.
     * Called by the "Generate" button on the Room / Space Overviews section.
     */
    public function generateRoomSummary(Request $request, ProjectPackage $package): JsonResponse
    {
        $this->authorizePackage($package);

        $room           = trim((string) $request->input('room',             ''));
        $overview       = trim((string) $request->input('overview',         ''));
        $solutionTypeId = (int) $request->input('solution_type_id', 0) ?: null;

        if ($overview === '') {
            return response()->json(['error' => 'Please write a phrased overview first, then generate the summary.'], 422);
        }

        // Add solution type context so AI generates a type-specific summary.
        $solutionTypeName    = null;
        $solutionTypeMethod  = null;
        if ($solutionTypeId) {
            $st = \App\Models\SolutionType::find($solutionTypeId);
            if ($st) {
                $solutionTypeName   = $st->name;
                $solutionTypeMethod = $st->install_method;
            }
        }

        try {
            // Phase 22.1 Plan 07: canonical input is the 4-key shape. The
            // service only reads `room` + `overview` — no `summary` input key
            // is consumed. Solution-type fields are extra context, not part
            // of the canonical row.
            $results      = $this->roomSummaryService->summarize([
                [
                    'room'                => $room,
                    'overview'            => $overview,
                    'solution_type'       => $solutionTypeName,
                    'solution_method'     => $solutionTypeMethod,
                ],
            ]);
            // Phase 22.1 closure (Plan 07): consume the canonical `works_summary`
            // key directly. The legacy `summary` shim is gone with Task 2's service
            // rename. The legacy `description` JSON response field is gone with
            // Task 1's blade-JS cleanup (Step 1c) — no remaining consumer.
            $worksSummary = $results[0]['works_summary'] ?? '';
        } catch (\Throwable $e) {
            return response()->json(['error' => 'AI generation failed. Please try again.'], 500);
        }

        return response()->json([
            'works_summary' => $worksSummary,
        ]);
    }

    /**
     * POST /project-packages/{package}/cleanup-lines
     *
     * AJAX — runs every equipment line through EquipmentLineCleanupPrompt
     * to normalise part numbers and rewrite descriptions into the short,
     * document-ready form ("Sony 50inch Bravia display"). Persists the
     * cleaned values back to ProjectPackage->extracted_data and returns
     * the updated rows so the caller can patch the form in place.
     */
    public function cleanupLines(ProjectPackage $package): JsonResponse
    {
        $this->authorizePackage($package);

        $extracted = $package->extracted_data ?? [];
        $equipment = (array) ($extracted['equipment'] ?? []);

        if (empty($equipment)) {
            return response()->json(['error' => 'No equipment lines to clean up.'], 422);
        }

        // Build the prompt input — index becomes the round-trip id.
        $input = [];
        foreach ($equipment as $i => $item) {
            $input[] = [
                'id'          => $i,
                'quantity'    => $item['quantity']    ?? 1,
                'part_number' => $item['part_number'] ?? '',
                'name'        => $item['name']        ?? '',
                'category'    => $item['category']    ?? 'hardware',
            ];
        }

        try {
            $result = app(\App\Core\AI\AIManager::class)->run(
                new \App\Core\AI\Prompts\EquipmentLineCleanupPrompt(),
                ['items' => $input],
                config('ai.default', 'claude'),
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('cleanupLines: AI call failed', [
                'package_id' => $package->id,
                'error'      => $e->getMessage(),
            ]);
            return response()->json(['error' => 'AI cleanup failed. Please try again.'], 500);
        }

        $cleaned = (array) ($result['items'] ?? []);
        if (empty($cleaned)) {
            return response()->json(['error' => 'AI returned no items.'], 500);
        }

        // Index by id so we can pair regardless of returned order.
        $byId = [];
        foreach ($cleaned as $row) {
            if (! is_array($row) || ! isset($row['id'])) {
                continue;
            }
            $byId[(int) $row['id']] = $row;
        }

        $changedRows = [];
        foreach ($equipment as $i => $item) {
            $row = $byId[$i] ?? null;
            if ($row === null) {
                continue;
            }
            $newPart = trim((string) ($row['part_number'] ?? ''));
            $newName = trim((string) ($row['name']        ?? ''));
            if ($newPart !== '' || $newName !== '') {
                $equipment[$i]['part_number'] = $newPart;
                $equipment[$i]['name']        = $newName;
                $changedRows[] = [
                    'id'          => $i,
                    'part_number' => $newPart,
                    'name'        => $newName,
                ];
            }
        }

        $extracted['equipment'] = $equipment;
        $package->update([
            'extracted_data' => $extracted,
            'equipment_list' => $equipment,
        ]);

        return response()->json([
            'updated' => count($changedRows),
            'rows'    => $changedRows,
        ]);
    }

    /**
     * POST /project-packages/{package}/generate-survey-rooms
     *
     * Creates numbered survey rooms in the project's linked site survey.
     * E.g. area="Small Room" qty=9 → creates "Small Room 1" … "Small Room 9"
     * after removing any existing rooms whose names match that area prefix.
     */
    public function generateSurveyRooms(Request $request, ProjectPackage $package): JsonResponse
    {
        $this->authorizePackage($package);

        $data = $request->validate([
            'area'             => ['required', 'string', 'max:150'],
            'qty'              => ['required', 'integer', 'min:1', 'max:99'],
            // Optional comma-separated list of explicit room names. When
            // supplied, the engineers/PM gets actual names (Nutmeg, Project
            // Room, Cardamon) rather than numbered "Area 1 / Area 2 / Area 3".
            // Names override qty: count is derived from the list length so
            // the rest of the pipeline keeps working.
            'names'            => ['nullable', 'string', 'max:1000'],
            // Current form values sent by JS so we don't need a prior save:
            // Phase 22.1 closure (Plan 07): `current_description` dropped — the
            // description textarea is gone from review.blade.php; no posted value.
            'current_overview'      => ['nullable', 'string'],
            'current_works_summary' => ['nullable', 'string'],
            'current_solution_type_id' => ['nullable', 'integer'],
        ]);

        // Parse explicit names list (overrides qty when present).
        $explicitNames = [];
        if (! empty($data['names'])) {
            $explicitNames = array_values(array_filter(
                array_map('trim', preg_split('/[,\n]+/', (string) $data['names'])),
                fn ($n) => $n !== ''
            ));
            // Drop duplicates while preserving order
            $explicitNames = array_values(array_unique($explicitNames));
        }

        $project = $package->project;
        if (! $project) {
            return response()->json(['error' => 'No project linked to this package.'], 422);
        }

        // Find the most recent active (non-submitted) site survey for this project.
        $survey = $project->siteSurveys()
            ->whereNotIn('status', ['completed', 'submitted'])
            ->latest()
            ->first()
            ?? $project->siteSurveys()->latest()->first();

        if (! $survey) {
            return response()->json([
                'error' => 'No site survey found for this project. Please create a survey first.',
            ], 422);
        }

        $area = $data['area'];
        // When explicit names are given, count drives qty; otherwise use the form qty.
        $qty  = ! empty($explicitNames) ? count($explicitNames) : $data['qty'];

        // Resolve the actual room name for the i-th generated room.
        $resolveName = function (int $i) use ($area, $qty, $explicitNames): string {
            if (! empty($explicitNames)) {
                return $explicitNames[$i - 1] ?? "{$area} {$i}";
            }
            return $qty === 1 ? $area : "{$area} {$i}";
        };

        // ── 1. Persist the room qty setting so the review page remembers it ──
        $extractedData = $package->extracted_data ?? [];
        $extractedData['room_qtys'][$area] = $qty;

        // ── 2. Find overview/AV scope text for this area (to copy to new rooms) ──
        // FIRST: use values sent directly from the current form (so we don't need
        //        the PM to save before clicking Generate Rooms).
        // FALLBACK: look up from extracted_data for exact/prefix match.
        $sourceOverview     = trim((string) ($data['current_overview']      ?? ''));
        $sourceWorksSummary = trim((string) ($data['current_works_summary'] ?? ''));
        $sourceSolutionId   = (int) ($data['current_solution_type_id'] ?? 0) ?: null;

        if ($sourceOverview === '' || $sourceSolutionId === null) {
            $areaLowerSrc = strtolower($area);
            foreach (($extractedData['room_overviews'] ?? []) as $ro) {
                $roName = strtolower(trim((string) ($ro['room'] ?? '')));
                if ($roName === $areaLowerSrc || str_starts_with($roName, $areaLowerSrc . ' ')) {
                    if ($sourceOverview === '') {
                        $sourceOverview = trim((string) ($ro['overview'] ?? ''));
                    }
                    if ($sourceWorksSummary === '') {
                        $sourceWorksSummary = trim((string) ($ro['works_summary'] ?? ''));
                    }
                    if ($sourceSolutionId === null) {
                        $sourceSolutionId = (int) ($ro['solution_type_id'] ?? 0) ?: null;
                    }
                    break;
                }
            }
        }

        // If a solution type is assigned, use its survey checklist as the av_requirements pre-fill.
        $surveyChecklist = $sourceOverview; // default: use overview text
        if ($sourceSolutionId) {
            $solutionType = \App\Models\SolutionType::find($sourceSolutionId);
            if ($solutionType && $solutionType->survey_checklist) {
                $surveyChecklist = $solutionType->survey_checklist;
            }
        }

        // ── 3. Expand room_overviews: replace original area with numbered entries ──
        // Remove exact matches AND variants like "Small Room - 4 Person" (starts with area + space/dash).
        $areaLower = strtolower($area);
        $roomOverviews = array_values(array_filter(
            $extractedData['room_overviews'] ?? [],
            function ($ro) use ($areaLower) {
                $name = strtolower(trim((string) ($ro['room'] ?? '')));
                // Exact match
                if ($name === $areaLower) return false;
                // Starts with area followed by a space (e.g. "small room - 4 person", "small room 1")
                if (str_starts_with($name, $areaLower . ' ')) return false;
                return true;
            },
        ));
        // Phase 22.1 closure (Plan 07): expanded survey rooms emit the
        // canonical 4-key shape only — symmetric with the source row that
        // editPayload + parseReviewPayload now produce. See 22.1-VERIFICATION.md.
        for ($i = 1; $i <= $qty; $i++) {
            $roomOverviews[] = [
                'room'             => $resolveName($i),
                'overview'         => $sourceOverview,
                'works_summary'    => $sourceWorksSummary,
                'solution_type_id' => $sourceSolutionId,
            ];
        }
        $extractedData['room_overviews'] = $roomOverviews;

        // ── 4. Expand equipment rows for this area into individual room entries ──
        // Each hardware item is split into $qty copies with whole-number per-room qty.
        $equipment = $extractedData['equipment'] ?? [];
        $expanded  = [];
        foreach ($equipment as $item) {
            if (! is_array($item)) {
                continue;
            }
            $itemArea = trim((string) ($item['area'] ?? ''));
            $itemCat  = strtolower(trim((string) ($item['category'] ?? 'hardware')));

            if ($itemArea === $area && $itemCat === 'hardware') {
                $origQty    = max(1, (int) ($item['quantity'] ?? 1));
                $perRoomQty = max(1, (int) floor($origQty / $qty));
                for ($i = 1; $i <= $qty; $i++) {
                    $copy             = $item;
                    $copy['area']     = $resolveName($i);
                    $copy['quantity'] = $perRoomQty;
                    $expanded[]       = $copy;
                }
            } else {
                $expanded[] = $item;
            }
        }
        $extractedData['equipment'] = $expanded;

        // Save all extracted_data changes back to the package
        $package->update([
            'extracted_data' => $extractedData,
            'equipment_list' => $expanded,
        ]);

        // ── 5. Remove existing survey rooms for this area and recreate ────────
        $survey->rooms()
            ->where(function ($q) use ($area) {
                $q->where('room_name', $area)
                  ->orWhere('room_name', 'like', $area . ' %');
            })
            ->delete();

        $maxSort = (int) ($survey->rooms()->max('sort_order') ?? -1);
        for ($i = 1; $i <= $qty; $i++) {
            $survey->rooms()->create([
                'room_name'       => $qty === 1 ? $area : "{$area} {$i}",
                'sort_order'      => ++$maxSort,
                // If a solution type is set, pre-fill the survey room with the checklist;
                // otherwise fall back to the overview text.
                'av_requirements' => $surveyChecklist ?: null,
                'space_type'      => $sourceSolutionId
                    ? (\App\Models\SolutionType::find($sourceSolutionId)?->slug ?? 'general')
                    : 'general',
            ]);
        }

        return response()->json([
            'created' => $qty,
            'area'    => $area,
        ]);
    }

    private function authorizePackage(ProjectPackage $package): void
    {
        abort_unless(
            $package->user_id === auth()->id() || auth()->user()?->role === 'admin',
            403
        );
    }

    /**
     * Phase 23 Plan 06 — DRAW-46 D-03 zone validation.
     *
     * Enforces the Pitfall 8 XSS regex allowlist on every posted
     * equipment[N][zone] value. Threats T-23-06-A1 (XSS via free-text zone
     * persisted into mxGraph value attribute) and T-23-06-A2 (form bypass
     * posting arbitrary string) are mitigated here at the network boundary.
     *
     * Regex `^[\p{L}\p{N} _\-]+$/u` is Unicode-letter friendly — engineers
     * can label zones in non-ASCII scripts (e.g. "Régie") without tripping
     * validation. Defence-in-depth: Plan 02 XtenAvLayoutEngine::xml() also
     * passes every zone string through htmlspecialchars(ENT_XML1|ENT_QUOTES)
     * before interpolating into the mxGraph XML.
     *
     * Used by update() AND approve() — both pipelines hit parseReviewPayload
     * and both must reject hostile payloads.
     */
    private function validateEquipmentZones(Request $request): void
    {
        $request->validate([
            'equipment.*.zone' => [
                'nullable',
                'string',
                'max:50',
                'regex:/^[\p{L}\p{N} _\-]+$/u',
            ],
        ]);
    }

    /**
     * Parse and sanitise the raw request payload into the canonical review schema.
     */
    private function parseReviewPayload(Request $request): array
    {
        $raw = $request->except(['_token', '_method', '_action']);

        // ── Equipment ─────────────────────────────────────────────────────────
        $equipment = [];
        foreach (array_values($raw['equipment'] ?? []) as $item) {
            if (empty($item['name']) && empty($item['quantity'])) {
                continue;
            }
            $category = $this->normaliseEquipmentCategory($item);
            $entry = [
                'quantity'    => max(1, (int) ($item['quantity']    ?? 1)),
                'part_number' => trim((string) ($item['part_number'] ?? '')),
                'name'        => trim((string) ($item['name']        ?? '')),
                'area'        => trim((string) ($item['area']        ?? '')),
                'category'    => $category,
            ];

            // Phase 23 Plan 06 — zone (DRAW-46 D-02 per-device override;
            // D-04 free-text escape hatch). Empty / whitespace-only zone is
            // OMITTED so it falls through to the D-01 category default in
            // the renderer. Validation already happened in update()/approve()
            // via validateEquipmentZones() — this is the persistence step.
            $zone = trim((string) ($item['zone'] ?? ''));
            if ($zone !== '') {
                $entry['zone'] = $zone;
            }

            $equipment[] = $entry;
        }
        $raw['equipment'] = $equipment;

        // ── Activities ────────────────────────────────────────────────────────
        $activities = [];
        foreach (array_values($raw['activities'] ?? []) as $a) {
            $key   = trim((string) ($a['key']   ?? ''));
            $label = trim((string) ($a['label'] ?? ''));
            if ($key === '' && $label === '') {
                continue;
            }
            $activities[] = [
                'key'   => $key !== '' ? $key : $this->slugify($label),
                'label' => $label !== '' ? $label : $key,
            ];
        }
        $raw['activities'] = $activities;

        // ── Hazards ───────────────────────────────────────────────────────────
        $hazards = [];
        foreach (array_values($raw['hazards'] ?? []) as $h) {
            if (empty($h['hazard'])) {
                continue;
            }
            $cm = $h['control_measures'] ?? '';
            if (is_string($cm)) {
                $cm = array_values(array_filter(
                    array_map('trim', preg_split('/\r?\n/', $cm)),
                    fn (string $s) => strlen($s) > 0,
                ));
            } else {
                $cm = array_values(array_filter(
                    array_map('strval', (array) $cm),
                    fn (string $s) => strlen(trim($s)) > 0,
                ));
            }

            $risk = $h['risk'] ?? 'Medium';
            if (! in_array($risk, ['Low', 'Medium', 'High'], true)) {
                $risk = 'Medium';
            }

            $hazards[] = [
                'activity_key'     => trim((string) ($h['activity_key'] ?? '')),
                'hazard'           => trim((string) ($h['hazard'] ?? '')),
                'risk'             => $risk,
                'control_measures' => $cm,
            ];
        }
        $raw['hazards'] = $hazards;

        // ── PPE ───────────────────────────────────────────────────────────────
        $raw['ppe'] = array_values(array_filter(
            array_map('strval', (array) ($raw['ppe'] ?? [])),
            fn (string $s) => strlen(trim($s)) > 0,
        ));

        // ── Access ───────────────────────────────────────────────────────────
        $posted = $raw['access'] ?? [];
        $raw['access'] = [
            'ladders'          => ! empty($posted['ladders']),
            'tower'            => ! empty($posted['tower']),
            'scissor_lift'     => ! empty($posted['scissor_lift']),
            'out_of_hours'     => ! empty($posted['out_of_hours']),
            'live_environment' => ! empty($posted['live_environment']),
        ];

        // ── Project ───────────────────────────────────────────────────────────
        $p = $raw['project'] ?? [];
        $raw['project'] = [
            'project_name' => trim((string) ($p['project_name'] ?? '')),
            'quote_ref'    => trim((string) ($p['quote_ref']    ?? '')),
            'client_name'  => trim((string) ($p['client_name']  ?? '')),
            'site_name'    => trim((string) ($p['site_name']    ?? '')),
            'site_address' => trim((string) ($p['site_address'] ?? '')),
            'prepared_by'  => trim((string) ($p['prepared_by']  ?? '')),
            'overview'     => trim((string) ($p['overview']     ?? '')),
        ];

        $raw['method_statement_notes'] = trim((string) ($raw['method_statement_notes'] ?? ''));
        $raw['scope_of_works']         = trim((string) ($raw['scope_of_works']         ?? ''));
        $raw['works_overview']         = trim((string) ($raw['works_overview']         ?? ''));
        // Phase 22.1 D-04: project-wide bullets payload key removed by Plan
        // 22.1-04. Survey "Planned AV Works" drawer now derives per-room
        // bullets from room_overviews[*].works_summary instead.

        // ── Programme & Personnel ─────────────────────────────────────────────────
        $prog = $raw['programme'] ?? [];
        $additionalEngineers = array_values(array_filter(
            array_map('strval', (array) ($prog['additional_engineers'] ?? [])),
            fn (string $s) => strlen(trim($s)) > 0,
        ));
        $programmers = array_values(array_filter(
            array_map('strval', (array) ($prog['programmers'] ?? [])),
            fn (string $s) => strlen(trim($s)) > 0,
        ));
        $workingHours = in_array($prog['working_hours'] ?? '', ['in_hours', 'out_of_hours'], true)
            ? $prog['working_hours']
            : 'in_hours';
        // Site vehicles & registrations — array of "REG ABC123 - Crew van" lines.
        $siteVehicles = array_values(array_filter(
            array_map('strval', (array) ($prog['site_vehicles'] ?? [])),
            fn (string $s) => trim($s) !== '',
        ));

        $raw['programme'] = [
            'project_manager_name'  => trim((string) ($prog['project_manager_name']  ?? '')),
            'project_manager_phone' => trim((string) ($prog['project_manager_phone'] ?? '')),
            'project_manager_email' => trim((string) ($prog['project_manager_email'] ?? '')),
            'lead_engineer_name'    => trim((string) ($prog['lead_engineer_name']    ?? '')),
            'lead_engineer_phone'   => trim((string) ($prog['lead_engineer_phone']   ?? '')),
            'additional_engineers'  => $additionalEngineers,
            'programmers'           => $programmers,
            'site_vehicles'         => $siteVehicles,
            'working_hours'         => $workingHours,
            'planned_start_date'    => trim((string) ($prog['planned_start_date'] ?? '')),
            'planned_end_date'      => trim((string) ($prog['planned_end_date']   ?? '')),
            'ongoing'               => ! empty($prog['ongoing']),
            'planned_start_time'    => trim((string) ($prog['planned_start_time'] ?? '')),
        ];

        // ── Site Logistics ────────────────────────────────────────────────────────
        $sl = $raw['site_logistics'] ?? [];
        $validAccessTypes = ['no_special', 'induction', 'reception', 'security', 'other'];
        $raw['site_logistics'] = [
            'contact_name'        => trim((string) ($sl['contact_name']        ?? '')),
            'contact_phone'       => trim((string) ($sl['contact_phone']       ?? '')),
            'contact_email'       => trim((string) ($sl['contact_email']       ?? '')),
            'delivery_area'       => trim((string) ($sl['delivery_area']       ?? '')),
            'restrictions'        => trim((string) ($sl['restrictions']        ?? '')),
            'commissioning_notes' => trim((string) ($sl['commissioning_notes'] ?? '')),
            'parking'             => in_array($sl['parking'] ?? '', ['yes', 'no'], true) ? $sl['parking'] : '',
            'parking_notes'       => trim((string) ($sl['parking_notes']       ?? '')),
            'install_floor'       => trim((string) ($sl['install_floor']       ?? '')),
            'access_type'         => in_array($sl['access_type'] ?? '', $validAccessTypes, true) ? $sl['access_type'] : '',
            'access_notes'        => trim((string) ($sl['access_notes']        ?? '')),
        ];

        // ── Room Overviews ────────────────────────────────────────────────────
        // Phase 22.1 closure (Plan 07): parseReviewPayload persists the
        // canonical 4-key shape only. Any `summary` / `description` keys that
        // hostile or stale clients post are silently dropped. Pair with the
        // editPayload + review.blade.php cleanups landing in the same plan.
        $roomOverviews = [];
        foreach (array_values($raw['room_overviews'] ?? []) as $ro) {
            $room = trim((string) ($ro['room'] ?? ''));
            if ($room === '') {
                continue;
            }
            $solutionTypeId = (int) ($ro['solution_type_id'] ?? 0) ?: null;
            $roomOverviews[] = [
                'room'             => $room,
                'overview'         => trim((string) ($ro['overview']      ?? '')),
                'works_summary'    => trim((string) ($ro['works_summary'] ?? '')),
                'solution_type_id' => $solutionTypeId,
            ];
        }
        $raw['room_overviews'] = $roomOverviews;

        // ── Room Qty overrides (used to pre-generate numbered survey rooms) ───
        $roomQtys = [];
        foreach ((array) ($raw['room_qtys'] ?? []) as $area => $qty) {
            $area = trim((string) $area);
            if ($area === '') {
                continue;
            }
            $roomQtys[$area] = max(1, (int) $qty);
        }
        $raw['room_qtys'] = $roomQtys;

        return $raw;
    }

    private function normaliseEquipmentCategory(array $item): string
    {
        $allowed = ['hardware', 'cables', 'consumables', 'services', 'option'];
        $rawCat = strtolower(trim((string) ($item['category'] ?? '')));
        if (in_array($rawCat, $allowed, true)) {
            return $rawCat;
        }

        $text = strtolower(trim(
            (string) ($item['name'] ?? '') . ' ' . (string) ($item['part_number'] ?? '')
        ));

        foreach (['optional', 'option'] as $kw) {
            if (str_contains($text, $kw)) {
                return 'option';
            }
        }

        foreach (['consumable', 'fixing', 'fastener', 'rawlplug', 'anchor', 'screw', 'bolt', 'tape', 'label', 'cleat', 'tie', 'strap'] as $kw) {
            if (str_contains($text, $kw)) {
                return 'consumables';
            }
        }

        foreach (['cable', 'cat6', 'cat6a', 'cat5', 'hdmi', 'sdi', 'utp', 'ftp', 'stp', 'patch', 'lead', 'usb', 'fibre', 'fiber', 'rg6', 'rg59'] as $kw) {
            if (str_contains($text, $kw)) {
                return 'cables';
            }
        }

        foreach (['install', 'installation', 'commission', 'configuration', 'programming', 'labour', 'support', 'survey', 'management', 'training', 'professional service', 'onsite service', 'on-site service'] as $kw) {
            if (str_contains($text, $kw)) {
                return 'services';
            }
        }

        return 'hardware';
    }

    /**
     * Fill missing project-level fields with sensible fallbacks.
     */
    private function fillProjectDefaults(array $project, ProjectPackage $package): array
    {
        $project = array_merge([
            'project_name' => '',
            'quote_ref'    => '',
            'client_name'  => '',
            'site_name'    => '',
            'site_address' => '',
            'prepared_by'  => '',
            'overview'     => '',
        ], $project);

        $fallback = [
            'project_name' => (string) ($package->project->name ?? ''),
            'quote_ref'    => (string) ($package->project->ref ?? ''),
            'client_name'  => (string) ($package->project->client_name ?? ''),
            'site_name'    => (string) ($package->project->name ?? ''),
            'site_address' => (string) ($package->project->site_address ?? ''),
            'prepared_by'  => '',
        ];

        foreach ($fallback as $key => $value) {
            if (trim((string) ($project[$key] ?? '')) === '' && trim($value) !== '') {
                $project[$key] = $value;
            }
        }

        // Final derived defaults for frequent OCR/AI blanks.
        if (trim((string) $project['project_name']) === '') {
            $ref    = trim((string) $project['quote_ref']);
            $client = trim((string) $project['client_name']);
            $site   = trim((string) $project['site_name']);

            if ($ref !== '' && $client !== '') {
                $project['project_name'] = "{$ref} - {$client}";
            } elseif ($site !== '') {
                $project['project_name'] = $site;
            } elseif ($client !== '') {
                $project['project_name'] = $client;
            } elseif ($ref !== '') {
                $project['project_name'] = $ref;
            }
        }

        if (trim((string) $project['site_name']) === '') {
            $project['site_name'] = trim((string) ($project['project_name'] ?: $project['client_name']));
        }

        return $project;
    }

    private function slugify(string $label): string
    {
        return preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($label)));
    }
}
