<?php

namespace App\Services;

use App\Core\AI\AIManager;
use App\Core\AI\Prompts\WorksheetPrompt;
use App\Core\Modules\Projects\ProjectDataService;
use App\Models\Worksheet;
use App\Services\Worksheet\BlockerPromoter;
use App\Services\Worksheet\FriendlyNameResolver;
use App\Services\Worksheet\SafetyProfileService;
use App\Services\Worksheet\WorksheetClassifier;
use App\Services\Worksheet\WorksheetTextNormalizer;
use Illuminate\Support\Facades\Log;

/**
 * WorksheetGeneratorService — builds per-room worksheet content for a project.
 *
 * Reads exclusively from ProjectDataService (canonical data source).
 * Preprocesses equipment into subsystem groups, detects blockers,
 * builds commissioning checklists, and produces phased install plans.
 *
 * AI is used to enhance install step narrative when available.
 * Deterministic fallback produces strong, non-generic steps when AI fails.
 */
class WorksheetGeneratorService
{
    // ── Item classification ──────────────────────────────────────────────────

    private const LABOUR_KEYWORDS = [
        'install', 'installation', 'commission', 'commissioning', 'programming',
        'configuration', 'project management', 'survey', 'travel', 'labour',
        'training', 'handover', 'design', 'engineering', 'support',
        'delivery', 'carriage', 'logistics', 'first fix', 'second fix',
    ];

    private const CABLE_KEYWORDS = [
        'cable', 'cat5', 'cat6', 'cat6a', 'hdmi', 'usb', 'patch lead',
        'ethernet', 'fibre', 'fiber', 'sdi', 'displayport', 'rg6',
        'connector', 'coupler', 'plug', 'adaptor', 'adapter',
    ];

    private const CONSUMABLE_KEYWORDS = [
        'consumable', 'fixing', 'screw', 'bolt', 'anchor', 'tie',
        'velcro', 'tape', 'label', 'grommet', 'cleat', 'rawlplug',
    ];

    // ── Subsystem classification ─────────────────────────────────────────────

    private const SUBSYSTEM_PATTERNS = [
        'Display'              => ['display', 'screen', 'monitor', 'tv', 'television', 'samsung', 'lg', 'sony', 'uhd', '4k', 'oled', 'qled', 'projector', 'lens'],
        'Video Conferencing' => ['cisco', 'poly', 'logitech', 'teams room', 'zoom room', 'room kit', 'codec', 'navigator', 'quad cam', 'ptz', 'camera', 'webcam', 'conferencing'],
        'Audio'                => ['speaker', 'loudspeaker', 'microphone', 'mic', 'dsp', 'amplifier', 'amp', 'biamp', 'shure', 'sennheiser', 'audio', 'soundbar'],
        'Rack & Infrastructure' => ['rack', '1u', '2u', 'blank', 'fan', 'pdu', 'power distribution', 'shelf', 'drawer'],
        'Control & Automation' => ['control', 'crestron', 'extron', 'amx', 'touch panel', 'sensor', 'partition', 'automation', 'keypad', 'button panel'],
    ];

    public function __construct(
        private readonly ProjectDataService $projectDataService,
    ) {}

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    public function generateContent(Worksheet $worksheet): array
    {
        $project = $worksheet->project ?? $worksheet->load('project')->project;

        if ($project === null) {
            throw new \RuntimeException(
                "WorksheetGeneratorService: worksheet {$worksheet->id} has no linked project."
            );
        }

        $data = $this->projectDataService->resolve($project);

        // ── Content pack context ─────────────────────────────────────────────
        $roomDescriptions = [];
        $worksOverview    = '';
        $package = $project->latestPackage ?? null;
        if ($package !== null) {
            foreach ((array) ($package->reviewed_data['room_overviews'] ?? []) as $ov) {
                if (! is_array($ov)) continue;
                $name = strtolower(trim((string) ($ov['room'] ?? '')));
                $desc = trim((string) ($ov['description'] ?? ''));
                if ($name !== '' && $desc !== '') {
                    $roomDescriptions[$name] = $desc;
                }
            }
            $worksOverview = trim((string) ($package->reviewed_data['works_overview'] ?? ''));
        }

        // ── Pre-install check answers ────────────────────────────────────────
        $preInstallAnswers = [];
        $latestSurvey = $project->siteSurveys()->latest()->first();
        if ($latestSurvey !== null) {
            foreach ($latestSurvey->rooms()->with('questions')->get() as $surveyRoom) {
                $key = strtolower(trim($surveyRoom->room_name));
                $answered = $surveyRoom->questions
                    ->whereNotNull('answer')->values()
                    ->map(fn ($q) => ['question' => $q->question, 'answer' => $q->answer, 'other_text' => $q->other_text])
                    ->toArray();
                if (! empty($answered)) {
                    $preInstallAnswers[$key] = $answered;
                }
            }
        }

        // ── Resolve and distribute rooms ─────────────────────────────────────
        $resolvedRooms = $this->resolveAndDistributeRooms($data, $worksheet->id);

        // ── Build enriched rooms ─────────────────────────────────────────────
        $rooms = $this->buildRooms(
            $resolvedRooms, $data['project'],
            $roomDescriptions, $worksOverview, $preInstallAnswers
        );

        // ── Pass B: blockers are rebuilt from source on every generation so
        //    a flipped answer cleanly invalidates, and regenerating twice
        //    with no input change produces byte-identical output.
        $blockers = $this->promoteBlockers($rooms, $data, $preInstallAnswers);

        // ── Pass A: shadow classifier run. Telemetry-only; does NOT alter
        //    render behaviour. Stored under _classification_telemetry so any
        //    downstream key-iterating renderer ignores it.
        $shadowTelemetry = null;
        try {
            $shadowTelemetry = app(WorksheetClassifier::class)->runShadow($rooms);
            Log::info('WorksheetGeneratorService: shadow classifier run', [
                'worksheet_id'         => $worksheet->id,
                'total_items'          => $shadowTelemetry['total_items'] ?? 0,
                'histogram'            => $shadowTelemetry['histogram'] ?? [],
                'tier_counts'          => $shadowTelemetry['tier_counts'] ?? [],
                'unclassified_count'   => $shadowTelemetry['unclassified_count'] ?? 0,
            ]);
        } catch (\Throwable $e) {
            // Shadow run must never break generation.
            Log::warning('WorksheetGeneratorService: shadow classifier failed', [
                'worksheet_id' => $worksheet->id,
                'error'        => $e->getMessage(),
            ]);
        }

        return [
            'project'                   => $data['project'],
            'rooms'                     => $rooms,
            'blockers'                  => $blockers,
            'generated_at'              => now()->toIso8601String(),
            '_classification_telemetry' => $shadowTelemetry,
        ];
    }

    // =========================================================================
    // ROOM RESOLUTION (unchanged logic, extracted for clarity)
    // =========================================================================

    private function resolveAndDistributeRooms(array $data, int $worksheetId): array
    {
        $nonPhysical = ['licencing', 'licensing', 'cabling', 'cables', 'professional services',
            'support services', 'consumables', 'services', 'options', 'delivery', 'carriage'];

        // ── Strategy 1: Build rooms directly from raw source equipment with area tags ──
        // The raw equipment/equipment_list from the package has 'area' fields that
        // correctly map items to rooms. This is the most reliable room attribution.
        $rawEquipment = $data['_raw_equipment'] ?? $data['equipment'] ?? [];
        $roomsFromAreas = $this->buildRoomsFromAreaTags($rawEquipment, $nonPhysical);

        if (! empty($roomsFromAreas)) {
            Log::info('WorksheetGeneratorService: built rooms from area tags', [
                'worksheet_id' => $worksheetId,
                'room_count'   => count($roomsFromAreas),
                'total_items'  => array_sum(array_map(fn ($r) => count($r['equipment'] ?? []), $roomsFromAreas)),
            ]);
            return $roomsFromAreas;
        }

        // ── Strategy 2: Use resolved rooms + distribute flat equipment ────────
        $resolvedRooms = $data['rooms'];
        $allEquipment  = $this->filterItems($data['equipment'] ?? [], 'install_hardware');

        $resolvedRooms = array_values(array_filter($resolvedRooms, function ($room) use ($nonPhysical) {
            $name = strtolower(trim($room['room_name'] ?? $room['name'] ?? ''));
            return ! in_array($name, $nonPhysical, true);
        }));

        if (empty($resolvedRooms) && ! empty($allEquipment)) {
            $resolvedRooms = $this->recoverRoomsFromEquipment($allEquipment);
        } elseif (! empty($resolvedRooms) && ! empty($allEquipment)) {
            $totalRoomEquipment = 0;
            foreach ($resolvedRooms as $room) {
                $totalRoomEquipment += count($this->filterItems($room['equipment'] ?? [], 'install_hardware'));
            }
            if ($totalRoomEquipment === 0) {
                $roomIndex = [];
                foreach ($resolvedRooms as $i => $room) {
                    $roomIndex[strtolower(trim($room['room_name'] ?? $room['name'] ?? ''))] = $i;
                }
                $unmapped = [];
                foreach ($allEquipment as $eq) {
                    $loc = strtolower(trim($eq['location'] ?? $eq['area'] ?? $eq['room'] ?? ''));
                    $mapped = false;
                    if ($loc !== '') {
                        foreach ($roomIndex as $rName => $rIdx) {
                            if ($loc === $rName || str_contains($rName, $loc) || str_contains($loc, $rName)) {
                                $resolvedRooms[$rIdx]['equipment'][] = $eq;
                                $mapped = true;
                                break;
                            }
                        }
                    }
                    if (! $mapped) $unmapped[] = $eq;
                }
                // Unmapped goes to "General", not first room
                if (! empty($unmapped)) {
                    $resolvedRooms[] = [
                        'room_name' => 'General', 'name' => 'General',
                        'equipment' => $unmapped, 'data_source' => 'unmapped',
                    ];
                }
                $resolvedRooms = array_values(array_filter($resolvedRooms, fn ($r) => ! empty($r['equipment'])));
            }
        }

        return $resolvedRooms;
    }

    /**
     * Build room entries by grouping equipment items using their 'area' field.
     * This preserves the original room attribution from the quote/package source.
     */
    private function buildRoomsFromAreaTags(array $equipment, array $nonPhysical): array
    {
        if (empty($equipment)) return [];

        // Count how many items have an area tag
        $withArea = 0;
        foreach ($equipment as $item) {
            if (! is_array($item)) continue;
            $area = trim((string) ($item['area'] ?? $item['location'] ?? ''));
            if ($area !== '') $withArea++;
        }

        // Only use this strategy if majority of items have area tags
        if ($withArea < count($equipment) * 0.3) {
            return []; // Not enough area data — fall back to other strategy
        }

        $grouped = [];
        foreach ($equipment as $item) {
            if (! is_array($item)) continue;
            $area = trim((string) ($item['area'] ?? $item['location'] ?? ''));
            if ($area === '') $area = 'General';
            $grouped[$area][] = $item;
        }

        // Filter non-physical and build room entries
        $rooms = [];
        foreach ($grouped as $area => $items) {
            if (in_array(strtolower($area), $nonPhysical, true)) continue;
            $rooms[] = [
                'room_name'   => $area,
                'name'        => $area,
                'equipment'   => $items,
                'data_source' => 'area_tag',
                'confidence'  => 0.9,
            ];
        }

        return $rooms;
    }

    // =========================================================================
    // ROOM BUILDER — enriched with subsystems, phases, commissioning, sign-off
    // =========================================================================

    private function buildRooms(
        array  $quoteRooms,
        array  $projectMeta,
        array  $roomDescriptions  = [],
        string $worksOverview     = '',
        array  $preInstallAnswers = [],
    ): array {
        $rooms = [];

        $normalizer = app(WorksheetTextNormalizer::class);
        $friendly   = app(FriendlyNameResolver::class);
        $safetySvc  = app(SafetyProfileService::class);

        foreach ($quoteRooms as $room) {
            $rawRoomName = $room['room_name'] ?? $room['name'] ?? 'Unknown Room';
            $roomName    = $normalizer->normalize((string) $rawRoomName);
            $isSurveyed  = $this->isSurveyed($room);
            $allItems    = $room['equipment'] ?? [];

            // ── A. Classify every line item (labour/cable/existing splits) ───
            $classified = $this->classifyItems($allItems);

            // ── A2. Apply friendly-name resolver so bare SKUs become readable.
            $classified['install_hardware'] = array_map(
                fn (array $i) => $i + ['name' => $friendly->resolve($i)],
                $classified['install_hardware'],
            );

            // ── B. Canonical category grouping (Pass B authoritative path) ───
            $subsystems = $this->groupByCanonicalCategory($classified['install_hardware']);

            // Room summary string derived dynamically from present category keys.
            $categorySummary = $this->buildCategorySummary(array_keys($subsystems));

            // ── C. AI install steps (narrative) ─────────────────────────────
            $installSteps = null;
            try {
                $roomForPrompt = $room;
                $roomForPrompt['equipment'] = $classified['install_hardware'];
                $roomForPrompt['description'] = $roomDescriptions[strtolower(trim($roomName))] ?? '';
                $roomForPrompt['works_overview'] = $worksOverview;
                $prompt  = WorksheetPrompt::forRoom($roomForPrompt, $projectMeta);
                $result  = AIManager::run($prompt, [], config('ai.default', 'claude'));
                $installSteps = $result['install_steps'] ?? null;
            } catch (\Throwable $e) {
                Log::warning('WorksheetGeneratorService: AI call failed for room', [
                    'room' => $roomName, 'error' => $e->getMessage(),
                ]);
            }

            // ── D. Deterministic phased plan (always built) ──────────────────
            $phasedPlan = $this->buildPhasedPlan($classified, $roomName, $room);

            // ── E. Fallback steps if AI empty ────────────────────────────────
            $stepsUsable = is_string($installSteps) && trim($installSteps) !== '';
            if (! $stepsUsable) {
                $stepsUsable = is_array($installSteps) && count($installSteps) > 0;
            }
            if (! $stepsUsable && ! empty($classified['install_hardware'])) {
                $installSteps = $phasedPlan; // Use phased plan as install_steps
            }

            // ── F. Commissioning checklist ────────────────────────────────────
            $commissioning = $this->buildCommissioningChecklist($subsystems);

            // ── G. Sign-off scaffold ─────────────────────────────────────────
            $signoff = [
                'engineer_name'      => '',
                'engineer_signature' => '',
                'date'               => '',
                'client_name'        => '',
                'client_signature'   => '',
                'snags'              => '',
            ];

            // ── H. Safety callouts — per-room, metadata-first (Pass B) ───────
            $safety = $safetySvc->profileRoom($room, $classified['install_hardware']);

            // ── I. Tools required ────────────────────────────────────────────
            $tools = $this->deriveToolsRequired($subsystems);

            // ── J. Room works description ────────────────────────────────────
            $sourceDescription = $roomDescriptions[strtolower(trim($roomName))] ?? '';
            $roomWorksDesc = $this->buildRoomWorksDescription(
                $roomName, $classified['install_hardware'], $subsystems, $isSurveyed, $sourceDescription
            );

            $rooms[] = [
                'name'                      => $roomName,
                'floor'                     => $room['floor'] ?? null,
                'is_surveyed'               => $isSurveyed,
                'room_works_description'    => $normalizer->normalize($roomWorksDesc),
                'equipment'                 => $classified['install_hardware'],
                'subsystems'                => $subsystems,
                'category_summary'          => $categorySummary,
                'cables'                    => $classified['cable_consumable'],
                'existing_reuse'            => $classified['existing_reuse'],
                'install_steps'             => $installSteps,
                'phased_plan'               => $phasedPlan,
                'commissioning'             => $commissioning,
                'signoff'                   => $signoff,
                'safety'                    => $safety,
                'tools'                     => $tools,
                'cable_route_desc'          => $room['cable_route_desc'] ?? null,
                'has_power'                 => $room['has_power'] ?? null,
                'power_outlet_count'        => $room['power_outlet_count'] ?? null,
                'requires_additional_power' => $room['requires_additional_power'] ?? null,
                'network_port_count'        => $room['network_port_count'] ?? null,
                'existing_cabling'          => $room['existing_cabling'] ?? null,
                'pre_install_answers'       => $preInstallAnswers[strtolower(trim($roomName))] ?? [],
            ];
        }

        return $rooms;
    }

    // =========================================================================
    // A. ITEM CLASSIFICATION
    // =========================================================================

    private function classifyItems(array $items): array
    {
        $result = [
            'install_hardware'    => [],
            'cable_consumable'    => [],
            'existing_reuse'      => [],
            'labour_or_document'  => [],
        ];

        foreach ($items as $item) {
            if (! is_array($item)) continue;

            $name     = strtolower(trim($item['name'] ?? $item['description'] ?? ''));
            $category = strtolower(trim($item['category'] ?? ''));
            $status   = strtolower(trim($item['status'] ?? $item['item_type'] ?? ''));

            // Labour / document / service
            if (in_array($category, ['services', 'option'], true) || $status === 'professional_service') {
                $result['labour_or_document'][] = $item;
                continue;
            }
            if ($this->matchesAny($name, self::LABOUR_KEYWORDS)) {
                $result['labour_or_document'][] = $item;
                continue;
            }

            // Cables / consumables
            if (in_array($category, ['cables', 'consumables'], true) || $status === 'consumable') {
                $result['cable_consumable'][] = $item;
                continue;
            }
            if ($this->matchesAny($name, self::CABLE_KEYWORDS) || $this->matchesAny($name, self::CONSUMABLE_KEYWORDS)) {
                $result['cable_consumable'][] = $item;
                continue;
            }

            // Existing / retained
            if (str_contains($status, 'existing') || str_contains($status, 'retain') || str_contains($name, 'existing') || str_contains($name, 'retained')) {
                $result['existing_reuse'][] = $item;
                continue;
            }

            // Default: install hardware
            $result['install_hardware'][] = $item;
        }

        return $result;
    }

    // =========================================================================
    // B2. CANONICAL CATEGORY GROUPING (Pass B authoritative path)
    // =========================================================================

    /**
     * Group install hardware into the 6 canonical categories using the
     * deterministic WorksheetClassifier. Items falling to an internal
     * sentinel (unclassified / mount_accessory / warranty_service /
     * existing_unknown) are kept out of the rendered groups — Pass C adds
     * a soft-warning panel for those. Never emits an "Other Hardware" bucket.
     *
     * @param  array $hardware  install-hardware items (already classified by
     *                          upstream classifyItems() as eligible for render)
     * @return array<string, array<int, array>>  label → items, in taxonomy order
     */
    private function groupByCanonicalCategory(array $hardware): array
    {
        $taxonomy = (array) config('worksheet_taxonomy', []);
        $labels   = (array) ($taxonomy['categories']     ?? []);
        $order    = (array) ($taxonomy['category_order'] ?? array_keys($labels));

        $classifier = app(WorksheetClassifier::class);
        $result     = $classifier->classifyRoom($hardware);

        $bucketed = [];
        foreach ($result['items'] as $item) {
            $cat = $item['_classification']['category'] ?? 'unclassified';
            // Sentinels never render; Pass C surfaces them via warnings panel.
            if (! isset($labels[$cat])) continue;
            $bucketed[$cat][] = $item;
        }

        $ordered = [];
        foreach ($order as $key) {
            if (! empty($bucketed[$key])) {
                $ordered[$labels[$key]] = $bucketed[$key];
            }
        }
        return $ordered;
    }

    /**
     * Build the room-level summary string from the set of category labels
     * present in the room. Always in canonical taxonomy order; uses Oxford-
     * free "A and B" / "A, B and C" phrasing.
     *
     * @param array<int, string> $presentLabels  labels as they appear in the
     *                                            grouped-by-category map
     */
    private function buildCategorySummary(array $presentLabels): string
    {
        if (empty($presentLabels)) return '';

        $n = count($presentLabels);
        if ($n === 1) return $presentLabels[0];
        if ($n === 2) return $presentLabels[0] . ' and ' . $presentLabels[1];
        $last = array_pop($presentLabels);
        return implode(', ', $presentLabels) . ' and ' . $last;
    }

    // =========================================================================
    // B. SUBSYSTEM GROUPING (legacy — kept for backward compat with any stray
    //    caller; new render path uses groupByCanonicalCategory instead)
    // =========================================================================

    private function groupBySubsystem(array $hardware): array
    {
        $groups = [];

        foreach ($hardware as $item) {
            $name = strtolower(trim($item['name'] ?? $item['description'] ?? ''));
            $subsystem = 'Other Hardware';

            foreach (self::SUBSYSTEM_PATTERNS as $label => $keywords) {
                if ($this->matchesAny($name, $keywords)) {
                    $subsystem = $label;
                    break;
                }
            }

            $groups[$subsystem][] = $item;
        }

        // Sort: named subsystems first, Other last
        $ordered = [];
        foreach (array_keys(self::SUBSYSTEM_PATTERNS) as $label) {
            if (isset($groups[$label])) {
                $ordered[$label] = $groups[$label];
            }
        }
        if (isset($groups['Other Hardware'])) {
            $ordered['Other Hardware'] = $groups['Other Hardware'];
        }

        return $ordered;
    }

    // =========================================================================
    // C. PHASED INSTALL PLAN
    // =========================================================================

    private function buildPhasedPlan(array $classified, string $roomName, array $room): array
    {
        $hardware = $classified['install_hardware'];
        $cables   = $classified['cable_consumable'];
        $isSurveyed = $this->isSurveyed($room);

        $phases = [];

        // Phase 1: Pre-Start & Access
        $preStart = [
            'Confirm site access and working area availability for ' . $roomName . '.',
            'Review RAMS and toolbox talk with all engineers.',
            'Verify all equipment delivered and checked against schedule.',
        ];
        if (! $isSurveyed) {
            $preStart[] = 'NOTE: Room not yet surveyed — confirm fixing positions and cable routes on arrival.';
        }
        $phases[] = ['step' => 1, 'title' => 'Pre-Start & Access', 'items' => $preStart];

        // Phase 2: First Fix (brackets, backboxes, containment)
        $firstFix = [];
        $subsystems = $this->groupBySubsystem($hardware);
        if (isset($subsystems['Display'])) {
            $firstFix[] = 'Install display mounting brackets — verify wall substrate and use appropriate fixings.';
        }
        if (isset($subsystems['Audio'])) {
            $firstFix[] = 'Install speaker brackets and ceiling mounts — confirm positions match design.';
        }
        if (isset($subsystems['Video Conferencing'])) {
            $firstFix[] = 'Install camera mounting brackets and codec shelf/mount.';
        }
        if (isset($subsystems['Rack & Infrastructure'])) {
            $firstFix[] = 'Position and secure rack — verify floor/wall fixings.';
        }
        if (empty($firstFix)) {
            $firstFix[] = 'Install all mounting hardware and containment as per design.';
        }
        $phases[] = ['step' => 2, 'title' => 'First Fix', 'items' => $firstFix];

        // Phase 3: Second Fix (mount equipment)
        $secondFix = [];
        foreach ($hardware as $eq) {
            $eqName = trim($eq['name'] ?? $eq['description'] ?? '');
            $qty = (int) ($eq['qty'] ?? $eq['quantity'] ?? 1);
            if ($eqName === '') continue;
            $prefix = $qty > 1 ? $qty . 'x ' : '';
            $secondFix[] = 'Mount and secure ' . $prefix . $eqName . '.';
        }
        if (empty($secondFix)) {
            $secondFix[] = 'Mount all AV equipment onto prepared fixings.';
        }
        $phases[] = ['step' => 3, 'title' => 'Second Fix', 'items' => $secondFix];

        // Phase 4: Cabling & Termination
        $cabling = [
            'Route all signal and power cables as per cable schedule.',
            'Terminate and test all cable ends.',
            'Label all cables at both ends per project labelling standard.',
        ];
        if (! empty($cables)) {
            $cableTypes = [];
            foreach ($cables as $c) {
                $cn = trim($c['name'] ?? $c['description'] ?? '');
                if ($cn !== '' && ! in_array($cn, $cableTypes, true)) $cableTypes[] = $cn;
            }
            if (! empty($cableTypes)) {
                $cabling[] = 'Cable types: ' . implode(', ', array_slice($cableTypes, 0, 5)) . '.';
            }
        }
        $phases[] = ['step' => 4, 'title' => 'Cabling & Termination', 'items' => $cabling];

        // Phase 5: Power-Up & Configuration
        $config = ['Power on all equipment sequentially — verify each unit before proceeding.'];
        if (isset($subsystems['Video Conferencing'])) {
            $config[] = 'Sign in to conferencing platform and verify network connectivity.';
        }
        if (isset($subsystems['Audio'])) {
            $config[] = 'Set DSP levels and verify audio signal path.';
        }
        if (isset($subsystems['Control & Automation'])) {
            $config[] = 'Load control system programming and test all functions.';
        }
        if (isset($subsystems['Display'])) {
            $config[] = 'Configure display input settings and verify signal routing.';
        }
        $phases[] = ['step' => 5, 'title' => 'Power-Up & Configuration', 'items' => $config];

        // Phase 6: Commissioning
        $commItems = ['Run full end-to-end system test.', 'Document commissioning results.'];
        $phases[] = ['step' => 6, 'title' => 'Commissioning', 'items' => $commItems];

        // Phase 7: Handover
        $phases[] = ['step' => 7, 'title' => 'Handover', 'items' => [
            'Clean work area — remove all waste and packaging.',
            'Demonstrate system operation to client representative.',
            'Obtain sign-off on completed works.',
        ]];

        return $phases;
    }

    // =========================================================================
    // D2. BLOCKER PROMOTION (Pass B authoritative path)
    // =========================================================================

    /**
     * Deterministic, idempotent blocker list. Rebuilt from source every call:
     *   1. Survey-level: if no site survey completed, add the survey blocker.
     *   2. Room-level: additional-power + VC-without-network-port checks.
     *   3. Pre-install answers: any failed/unknown answer promotes to a
     *      typed blocker via BlockerPromoter.
     *
     * Flipping an answer from No→Yes between generations must make the blocker
     * disappear. Regenerating with no input change must produce byte-identical
     * output. Tests lock both invariants.
     */
    private function promoteBlockers(array $rooms, array $data, array $preInstallAnswers): array
    {
        $blockers = [];

        if (! ($data['meta']['has_survey'] ?? false)) {
            $blockers[] = [
                'type'    => 'survey',
                'message' => 'Site survey not completed — fixing positions and cable routes unconfirmed.',
                'action'  => 'Complete site survey before installation.',
                'room'    => '(project)',
                'source'  => 'no_survey',
            ];
        }

        foreach ($rooms as $room) {
            $roomName = (string) ($room['name'] ?? 'Unknown');
            if (($room['requires_additional_power'] ?? false) === true) {
                $blockers[] = [
                    'type'    => 'power',
                    'message' => $roomName . ': Additional power outlets required.',
                    'action'  => 'Confirm power provision with the client electrician before install.',
                    'room'    => $roomName,
                    'source'  => 'room_power_' . substr(md5($roomName), 0, 8),
                ];
            }
            if (($room['network_port_count'] ?? 0) === 0
                && ! empty($room['subsystems']['Video Conferencing'] ?? [])) {
                $blockers[] = [
                    'type'    => 'network',
                    'message' => $roomName . ': VC system requires network but no ports recorded.',
                    'action'  => 'Confirm network drop availability with client IT.',
                    'room'    => $roomName,
                    'source'  => 'room_net_' . substr(md5($roomName), 0, 8),
                ];
            }
        }

        // Pre-install answer → blocker promotion (idempotent via BlockerPromoter).
        $promoter = app(BlockerPromoter::class);
        foreach ($promoter->promoteFromAnswers($preInstallAnswers) as $promoted) {
            $blockers[] = $promoted;
        }

        return $blockers;
    }

    // =========================================================================
    // D. BLOCKERS (legacy — retained during the Pass B transition only)
    // =========================================================================

    private function detectBlockers(array $rooms, array $data): array
    {
        $blockers = [];
        $hasSurvey = $data['meta']['has_survey'] ?? false;

        if (! $hasSurvey) {
            $blockers[] = [
                'type'    => 'survey',
                'message' => 'Site survey not completed — fixing positions and cable routes unconfirmed.',
                'action'  => 'Complete site survey before installation.',
            ];
        }

        foreach ($rooms as $room) {
            if (($room['requires_additional_power'] ?? false) === true) {
                $blockers[] = [
                    'type'    => 'power',
                    'message' => $room['name'] . ': Additional power outlets required.',
                    'action'  => 'Confirm power provision with client electrician before install.',
                ];
            }
            if (($room['network_port_count'] ?? 0) === 0 && ! empty($room['subsystems']['Video Conferencing'] ?? [])) {
                $blockers[] = [
                    'type'    => 'network',
                    'message' => $room['name'] . ': VC system requires network but no ports recorded.',
                    'action'  => 'Confirm network drop availability with client IT.',
                ];
            }
        }

        return $blockers;
    }

    // =========================================================================
    // E. COMMISSIONING CHECKLIST
    // =========================================================================

    private function buildCommissioningChecklist(array $subsystems): array
    {
        $checks = [];

        if (isset($subsystems['Display'])) {
            $checks[] = ['system' => 'Display', 'check' => 'Image displayed correctly on all screens', 'result' => '', 'notes' => ''];
            $checks[] = ['system' => 'Display', 'check' => 'Correct input source selected and stable', 'result' => '', 'notes' => ''];
        }
        if (isset($subsystems['Audio'])) {
            $checks[] = ['system' => 'Audio', 'check' => 'Audio output from all speakers at correct level', 'result' => '', 'notes' => ''];
            $checks[] = ['system' => 'Audio', 'check' => 'Microphone pickup clear — no feedback', 'result' => '', 'notes' => ''];
        }
        if (isset($subsystems['Video Conferencing'])) {
            $checks[] = ['system' => 'VC', 'check' => 'Test call completed successfully', 'result' => '', 'notes' => ''];
            $checks[] = ['system' => 'VC', 'check' => 'Camera framing correct — all participants visible', 'result' => '', 'notes' => ''];
            $checks[] = ['system' => 'VC', 'check' => 'Content sharing functional (wired and wireless)', 'result' => '', 'notes' => ''];
        }
        if (isset($subsystems['Control & Automation'])) {
            $checks[] = ['system' => 'Control', 'check' => 'Touch panel responsive — all pages functional', 'result' => '', 'notes' => ''];
            $checks[] = ['system' => 'Control', 'check' => 'All macro functions tested', 'result' => '', 'notes' => ''];
        }
        if (isset($subsystems['Rack & Infrastructure'])) {
            $checks[] = ['system' => 'Rack', 'check' => 'Rack ventilation adequate — fans operational', 'result' => '', 'notes' => ''];
        }

        // Always
        $checks[] = ['system' => 'General', 'check' => 'All cables labelled and dressed', 'result' => '', 'notes' => ''];
        $checks[] = ['system' => 'General', 'check' => 'Work area clean — no waste remaining', 'result' => '', 'notes' => ''];

        return $checks;
    }

    // =========================================================================
    // F. SAFETY CALLOUTS
    // =========================================================================

    private function detectSafetyCallouts(array $hardware): array
    {
        $callouts = [];
        foreach ($hardware as $item) {
            $name = strtolower($item['name'] ?? $item['description'] ?? '');
            if ($this->matchesAny($name, ['display', 'screen', 'monitor', 'tv', '55"', '65"', '75"', '85"', '86"', '98"'])) {
                $callouts['two_person_lift'] = 'Large display — minimum 2-person lift required. Use screen protection during transit.';
            }
            if ($this->matchesAny($name, ['projector', 'ceiling', 'pendant', 'speaker']) && $this->matchesAny($name, ['mount', 'bracket', 'ceiling', 'pendant'])) {
                $callouts['wah'] = 'Ceiling/high-level works — working at height controls apply. Use appropriate access equipment.';
            }
            if ($this->matchesAny($name, ['rack', '42u', '24u', '18u', '12u'])) {
                $callouts['rack_handling'] = 'Rack equipment — team lift required. Secure to floor/wall before loading.';
            }
        }
        return array_values($callouts);
    }

    // =========================================================================
    // G. TOOLS REQUIRED
    // =========================================================================

    private function deriveToolsRequired(array $subsystems): array
    {
        $tools = ['Drill and drill bits', 'Spirit level', 'Cable tester', 'Label printer', 'Laptop for commissioning'];

        if (isset($subsystems['Display'])) {
            $tools[] = 'Display mounting template';
        }
        if (isset($subsystems['Audio'])) {
            $tools[] = 'SPL meter';
        }
        if (isset($subsystems['Rack & Infrastructure'])) {
            $tools[] = 'Cage nut tool';
            $tools[] = 'Torque driver';
        }

        return array_values(array_unique($tools));
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function isSurveyed(array $room): bool
    {
        return isset($room['ceiling_type'])
            || isset($room['cable_route_desc'])
            || isset($room['has_power'])
            || ($room['data_source'] ?? '') === 'survey';
    }

    /**
     * Filter items by classification type.
     * For 'install_hardware', excludes labour/cables/consumables.
     */
    private function filterItems(array $items, string $type): array
    {
        $classified = $this->classifyItems($items);
        return $classified[$type] ?? [];
    }

    private function recoverRoomsFromEquipment(array $equipment): array
    {
        if (empty($equipment)) return [];

        $grouped = [];
        foreach ($equipment as $item) {
            $room = trim((string) ($item['location'] ?? $item['area'] ?? $item['room'] ?? ''));
            if ($room === '') $room = 'General';
            $grouped[$room][] = $item;
        }

        ksort($grouped, SORT_NATURAL | SORT_FLAG_CASE);

        $rooms = [];
        foreach ($grouped as $name => $items) {
            $rooms[] = ['room_name' => $name, 'name' => $name, 'equipment' => $items,
                'data_source' => 'equipment_recovery', 'confidence' => 0.8];
        }
        return $rooms;
    }

    private function matchesAny(string $haystack, array $keywords): bool
    {
        foreach ($keywords as $kw) {
            if ($kw !== '' && str_contains($haystack, $kw)) return true;
        }
        return false;
    }

    // =========================================================================
    // J. ROOM WORKS DESCRIPTION
    // =========================================================================

    /**
     * Build a concise, engineer-readable description of works for a room.
     * Prefers explicit source description when available; synthesises from
     * equipment/subsystem data otherwise. Never blank when equipment exists.
     */
    private function buildRoomWorksDescription(
        string $roomName,
        array  $hardware,
        array  $subsystems,
        bool   $isSurveyed,
        string $sourceDescription,
    ): string {
        $roomName = trim($roomName) !== '' ? trim($roomName) : 'General';
        $hardwareCount = count($hardware);

        if ($hardwareCount === 0 && trim($sourceDescription) === '') {
            return 'No new AV equipment is scheduled in this room.';
        }

        $subsystemNames = array_values(array_keys($subsystems));
        $systemsText = $this->formatList($subsystemNames, 3, 'general AV systems');
        $source = trim($sourceDescription);
        $keyItems = $this->pickKeyEquipmentNames($hardware, 3);

        $variant = abs((int) crc32(strtolower($roomName))) % 4;
        $sentences = [];
        $items = $hardwareCount === 1 ? 'item' : 'items';

        $sentences[] = match ($variant) {
            0 => "{$roomName}: deliver {$hardwareCount} {$items} across {$systemsText}.",
            1 => "{$roomName} room scope: {$hardwareCount} {$items} across {$systemsText}.",
            2 => "{$roomName}: engineer works include {$hardwareCount} {$items} covering {$systemsText}.",
            default => "{$roomName} install scope is {$hardwareCount} {$items} across {$systemsText}.",
        };

        if ($source !== '') {
            $sentences[] = 'Site note: ' . rtrim($source, ". \t\n\r\0\x0B") . '.';
        }

        if (! empty($keyItems)) {
            $sentences[] = 'Key kit: ' . $this->formatList($keyItems, 3, 'listed AV equipment') . '.';
        }

        $actions = [];
        if (isset($subsystems['Display'])) {
            $actions[] = 'mount and align display hardware';
        }
        if (isset($subsystems['Video Conferencing'])) {
            $actions[] = 'integrate VC endpoints with network services';
        }
        if (isset($subsystems['Audio'])) {
            $actions[] = 'wire and commission the audio signal path';
        }
        if (isset($subsystems['Rack & Infrastructure'])) {
            $actions[] = 'build, terminate and dress the rack';
        }
        if (isset($subsystems['Control & Automation'])) {
            $actions[] = 'verify control behaviour and room triggers';
        }
        if (isset($subsystems['Network'])) {
            $actions[] = 'rack, patch and label network switch / AP kit';
        }
        if (! empty($actions)) {
            $sentences[] = 'Work outputs: ' . $this->formatList($actions, 3, 'install listed kit and complete functional checks') . '.';
        } elseif ($hardwareCount > 0) {
            $sentences[] = 'Work outputs: install listed kit, terminate cabling, label terminations, and complete functional checks.';
        }

        if (! $isSurveyed) {
            $sentences[] = 'Survey action: confirm final fixing positions, cable routes, and power/network points before first fix.';
        }

        $sentences = array_values(array_filter(array_map('trim', $sentences), fn ($s) => $s !== ''));
        if (count($sentences) > 4) {
            $sentences = array_slice($sentences, 0, 4);
        }
        if (count($sentences) < 2 && $hardwareCount > 0) {
            $sentences[] = 'Work outputs: install listed kit and complete functional checks.';
        }

        return $this->cleanNarrative(implode(' ', $sentences), 340);
    }

    /**
     * Pick up to N useful key equipment names for room summary text.
     */
    private function pickKeyEquipmentNames(array $hardware, int $max = 3): array
    {
        $skipTerms = [
            'utilise', 'existing', 'exisiting', 'additional', 'drawing', 'document',
            'service', 'labour', 'support', 'warranty', 'contract', 'professional services',
        ];

        $picked = [];
        foreach ($hardware as $item) {
            $name = trim((string) ($item['name'] ?? $item['description'] ?? ''));
            if ($name === '') {
                continue;
            }
            $lower = strtolower($name);
            if ($this->matchesAny($lower, $skipTerms)) {
                continue;
            }
            if (! in_array($name, $picked, true)) {
                $picked[] = $name;
            }
            if (count($picked) >= $max) {
                break;
            }
        }

        if (empty($picked)) {
            foreach ($hardware as $item) {
                $name = trim((string) ($item['name'] ?? $item['description'] ?? ''));
                if ($name === '') {
                    continue;
                }
                if (! in_array($name, $picked, true)) {
                    $picked[] = $name;
                }
                if (count($picked) >= $max) {
                    break;
                }
            }
        }

        return $picked;
    }

    /**
     * Deterministically format a natural-language list.
     */
    private function formatList(array $items, int $max = 3, string $fallback = ''): string
    {
        $items = array_values(array_filter(array_map(fn ($v) => trim((string) $v), $items), fn ($v) => $v !== ''));
        if (empty($items)) {
            return $fallback;
        }

        $items = array_slice($items, 0, $max);
        $count = count($items);

        if ($count === 1) {
            return $items[0];
        }
        if ($count === 2) {
            return $items[0] . ' and ' . $items[1];
        }

        return implode(', ', array_slice($items, 0, -1)) . ' and ' . $items[$count - 1];
    }

    /**
     * Post-build narrative cleaner: collapse whitespace, remove duplicate fragments,
     * enforce max length, ensure sentence ending and non-empty fallback.
     */
    private function cleanNarrative(string $text, int $maxLength = 380): string
    {
        $text = preg_replace('/\b(designated position|as required|where applicable)\b/i', '', $text);
        $text = trim((string) preg_replace('/\s{2,}/', ' ', $text));

        $sentences = preg_split('/(?<=[.!?])\s+/', $text) ?: [];
        $deduped = [];
        $prev = '';
        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if ($sentence === '') {
                continue;
            }
            $normalized = strtolower(preg_replace('/\s+/', ' ', $sentence));
            if ($normalized === $prev) {
                continue;
            }
            $deduped[] = $sentence;
            $prev = $normalized;
        }

        if (count($deduped) > 4) {
            $deduped = array_slice($deduped, 0, 4);
        }
        $text = implode(' ', $deduped);

        if (strlen($text) > $maxLength) {
            $text = substr($text, 0, $maxLength);
            $lastSpace = strrpos($text, ' ');
            if ($lastSpace !== false && $lastSpace > ($maxLength * 0.7)) {
                $text = substr($text, 0, $lastSpace);
            }
            $text = rtrim($text, " ,;:-\t\n\r\0\x0B") . '.';
        }

        if ($text !== '' && ! preg_match('/[.!?]$/', $text)) {
            $text .= '.';
        }

        if (trim($text) === '' || trim($text) === '.') {
            $text = 'Install the listed room kit and complete room functional checks.';
        }

        return $text;
    }
}
