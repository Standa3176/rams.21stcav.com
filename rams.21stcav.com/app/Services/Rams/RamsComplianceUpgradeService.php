<?php

namespace App\Services\Rams;

use App\Exceptions\RamsGenerationException;

/**
 * RamsComplianceUpgradeService
 *
 * Upgrades a RAMS generated_data structure to Tier 1 UK contractor standard
 * (ISG / Mace / Kier level compliance).
 *
 * This service EXTENDS — it does not rewrite. Existing sections, project
 * details, equipment, hazards, and method statements are preserved. New
 * sections are added and existing ones are enhanced with project-specific
 * AV installation detail.
 *
 * Deterministic. No AI. No database.
 */
class RamsComplianceUpgradeService
{
    /**
     * The inch-size extraction regex. Originally inline inside
     * `suggestHandlingMethod()`; extracted to a shared constant by Plan 27-06
     * (Task 1) so `parseStatedInches()` (the engineer-typed-row GATE-09
     * extension) reuses the EXACT same pattern rather than declaring a
     * second, potentially-divergent one. Matches "98″", "98\"", "98 inch",
     * "98-inch", "10.1″", etc. Capture group 1 is the numeric value.
     */
    private const INCH_REGEX = '/(\d+(?:\.\d+)?)\s*(?:″|"|\\\\"|\xE2\x80\xB3|inch|in\b|-inch)/u';

    // =========================================================================
    // PUBLIC ENTRY POINT
    // =========================================================================

    public static function upgrade(array $ramsData): array
    {
        $ramsData = self::upgradeScopeOfWorks($ramsData);
        $ramsData = self::ensurePerRoomBullets($ramsData);
        $ramsData = self::addPpeMatrix($ramsData);
        $ramsData = self::fillMissingHazardControls($ramsData);
        $ramsData = self::addProjectSpecificRisks($ramsData);
        // 260817-r5e Item 3 — MUST run after the two hazard steps above.
        // addAccessEquipmentDetail now reconciles against the document's own
        // hazard controls, and fillMissingHazardControls is what injects the
        // "podium steps, tower, or MEWP" work-at-height control it has to see.
        // Nothing between reads access_equipment_detail, so the move is inert
        // apart from giving the reconciliation the complete risk assessment.
        $ramsData = self::addAccessEquipmentDetail($ramsData);
        $ramsData = self::addRiskColourKey($ramsData);
        $ramsData = self::addPermitAndIsolation($ramsData);
        $ramsData = self::addFixingsControl($ramsData);
        $ramsData = self::addSupervisionAndQA($ramsData);
        $ramsData = self::deriveMaterialHandling($ramsData);
        // GATE-09 — independent re-check of every display item's stated team
        // size against DisplayLiftPolicy::violatesPolicy(). Config-gated so
        // this milestone's live-validation posture can roll it back with a
        // single .env edit (RAMS_DISPLAY_LIFT_GATE), mirroring
        // RAMS_HAZARD_LIBRARY_TIERING's established shape exactly. When the
        // flag is false, enforceDisplayLiftGate() is never called — upgrade()
        // proceeds byte-identical to pre-GATE-09 behaviour.
        if (config('rams_tier1.display_lift_gate_enabled', true)) {
            $ramsData = self::enforceDisplayLiftGate($ramsData);
        }
        $ramsData = self::crossReferenceMethodStatementRisks($ramsData);
        $ramsData = self::addCdmDutyHolders($ramsData);
        $ramsData = self::cleanTextArtifacts($ramsData);

        return $ramsData;
    }

    /**
     * Phase 22.1 D-06 — approve-time bullet computation.
     *
     * Invoked by ProjectPackageReviewController::approve() to compute and
     * persist scope_of_works_bullets into reviewed_data BEFORE the package
     * is saved. After persistence the render-time upgradeScopeOfWorks()
     * sees the populated array and short-circuits its heuristic (read-through
     * cache pattern) — locking the bullets to the approved snapshot so
     * post-approval edits to equipment_list cannot drift the rendered scope.
     *
     * Returns the bullet array (possibly empty). The caller is responsible
     * for writing the result into $reviewedData['scope_of_works_bullets']
     * and persisting via $package->update(['extracted_data' => $merged]).
     *
     * The synthetic $data payload deliberately omits scope_of_works_bullets
     * so the heuristic ALWAYS runs (cache miss path); the result is then
     * extracted and returned to the caller. This keeps the heuristic logic
     * in one location — the render-time and approve-time invocations share
     * the same implementation.
     *
     * @param  array  $reviewedData    The PM-approved review payload (may contain
     *                                 cable_requirements + equipment).
     * @param  array  $projectContext  Project-context hints, e.g. rooms list
     *                                 derived from $reviewedData['room_overviews'].
     * @return array                   Array of bullet strings (may be empty).
     */
    public static function computeScopeOfWorksBulletsForApprove(array $reviewedData, array $projectContext): array
    {
        // Build a synthetic heuristic-input payload. Intentionally NO
        // scope_of_works_bullets key so the cache hit guard does not
        // short-circuit and the heuristic body runs.
        $synthetic = [
            'rooms'              => $projectContext['rooms']             ?? [],
            'cable_requirements' => $reviewedData['cable_requirements']  ?? [],
            'quote'              => [
                'line_items' => $reviewedData['equipment'] ?? [],
            ],
        ];

        $upgraded = self::upgradeScopeOfWorks($synthetic);

        return (array) ($upgraded['scope_of_works_bullets'] ?? []);
    }

    /**
     * Ensure every per-room overview has an install-action bullet list.
     *
     * §4 Scope of Works renders works_summary (bullets) when present and
     * falls back to overview (raw quote prose) when not. Manual conversion
     * was a Convert-to-bullets click on each project review screen. This
     * step does the same job at RAMS generation time so the operator never
     * has to think about it — sales-style hedging ("other larger sizes are
     * available") and first-person prose ("I have also added the…") gets
     * normalised before it lands in a compliance document.
     *
     * AI cache is SHA-256 keyed on prompt content, so re-renders of the
     * same room cost zero tokens. A room is considered already converted
     * when works_summary contains "- " bullet markers.
     */
    private static function ensurePerRoomBullets(array $data): array
    {
        $rooms = $data['room_overviews'] ?? [];
        if (! is_array($rooms) || empty($rooms)) {
            return $data;
        }

        $needsConversion = [];
        foreach ($rooms as $i => $room) {
            if (! is_array($room)) continue;
            $existing = trim((string) ($room['works_summary'] ?? ''));
            // Phase 22.1 D-01: dropped $room['description'] and $room['scope']
            // from the fallback chain. After Plan 22.1-03 the canonical
            // room_overviews schema is exactly 4 keys (room / overview /
            // works_summary / solution_type_id). Reading the dead `description`
            // / `scope` keys would pollute the bullet heuristic with legacy
            // AI prose that nothing else in the pipeline still consumes.
            $overview = trim((string) ($room['overview'] ?? ''));
            // Skip rooms that already have bullet output.
            if ($existing !== '' && (str_starts_with($existing, '- ') || str_contains($existing, "\n- "))) {
                continue;
            }
            // Skip rooms with no source prose to convert.
            if ($overview === '' || strlen($overview) < 40) {
                continue;
            }
            $needsConversion[$i] = [
                'room'     => (string) ($room['room'] ?? $room['room_name'] ?? $room['name'] ?? ''),
                'overview' => $overview,
                'summary'  => $existing,
            ];
        }

        if (empty($needsConversion)) {
            return $data;
        }

        // Resolve the summariser via the container so tests can swap it for
        // a fixture without touching the AI provider.
        try {
            $summariser = app(\App\Services\RoomOverviewSummaryService::class);
            $results    = $summariser->summarize(array_values($needsConversion));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('RamsComplianceUpgrade: per-room bullet conversion failed', [
                'error' => $e->getMessage(),
            ]);
            return $data;  // never block PDF generation
        }

        // Pair results back to rooms by name (order is not guaranteed by
        // the AI response). Fall back to positional pairing if name match
        // fails for any reason.
        $byName = [];
        foreach ((array) $results as $r) {
            if (! is_array($r)) continue;
            $name = strtolower(trim((string) ($r['room'] ?? '')));
            if ($name !== '') $byName[$name] = $r;
        }

        $idx = 0;
        foreach ($needsConversion as $roomIdx => $row) {
            $name   = strtolower($row['room']);
            $result = $byName[$name] ?? array_values($results)[$idx] ?? null;
            $idx++;
            if (! is_array($result)) continue;
            // Phase 22.1 Plan 07: RoomOverviewSummaryService now writes
            // `works_summary` (not `summary`) into each returned row. See
            // RoomOverviewSummaryService::summarize() docblock.
            $bullets = trim((string) ($result['works_summary'] ?? ''));
            if ($bullets === '') continue;
            $rooms[$roomIdx]['works_summary'] = $bullets;
        }

        $data['room_overviews'] = $rooms;
        return $data;
    }

    // =========================================================================
    // 1. SCOPE OF WORKS — engineer-focused bullet points
    // =========================================================================

    private static function upgradeScopeOfWorks(array $data): array
    {
        // Phase 22.1 D-06: read-through cache. When scope_of_works_bullets has
        // been persisted at approve-time (see ProjectPackageReviewController::
        // approve() and ::computeScopeOfWorksBulletsForApprove() below), the
        // heuristic short-circuits. This locks the approved bullets to the
        // snapshot taken at approve — the equipment_list can change after
        // approval without drifting the rendered scope.
        //
        // Backward compatibility: records without persisted bullets (any
        // record approved before this plan ships) still run the heuristic at
        // render time, preserving the Wave-1 byte-equivalence canary.
        $persisted = $data['scope_of_works_bullets'] ?? null;
        if (is_array($persisted) && ! empty($persisted)) {
            return $data;
        }

        $rooms             = (array) ($data['rooms']              ?? []);
        $cableRequirements = (array) ($data['cable_requirements'] ?? []);
        $quoteLineItems    = (array) ($data['quote']['line_items'] ?? []);

        $bullets = [];

        // ── Source A: ProjectContext rooms (equipment + activities) ───────────
        $allActivities = [];
        $allEquipment  = [];

        foreach ($rooms as $room) {
            foreach ((array) ($room['activities'] ?? []) as $activity) {
                $allActivities[$activity] = true;
            }
            foreach ((array) ($room['equipment'] ?? []) as $item) {
                $type = strtolower(trim((string) ($item['type'] ?? '')));
                if ($type !== '') {
                    $allEquipment[$type] = true;
                }
            }
        }

        // Equipment-driven scope bullets
        $scopeMap = [
            'display'   => 'Installation and alignment of display screens',
            'projector' => 'Installation of projector and ceiling mount assembly',
            'camera'    => 'Installation and framing of PTZ / USB cameras',
            'mic'       => 'Installation of microphone system',
            'dsp'       => 'Installation and configuration of DSP / audio processor',
            'speaker'   => 'Installation of speaker system including bracket fixing',
            'vc'        => 'Installation and commissioning of video conferencing codec',
            'control'   => 'Installation of control system and touch panel',
            'switcher'  => 'Installation and configuration of AV switcher / matrix',
        ];

        foreach ($scopeMap as $type => $bullet) {
            if (isset($allEquipment[$type])) {
                $bullets[] = $bullet;
            }
        }

        // ── Source B: Quote line items (fallback when no ProjectContext) ──────
        if (empty($bullets) && ! empty($quoteLineItems)) {
            $seenCategories = [];
            foreach ($quoteLineItems as $item) {
                $desc = strtolower(trim((string) ($item['description'] ?? '')));
                if ($desc === '') {
                    continue;
                }
                // Detect equipment categories from description
                if (! isset($seenCategories['display']) && preg_match('/\b(display|screen|monitor|tv)\b/', $desc)) {
                    $bullets[] = 'Installation and alignment of display screens';
                    $seenCategories['display'] = true;
                }
                if (! isset($seenCategories['projector']) && preg_match('/\bprojector\b/', $desc)) {
                    $bullets[] = 'Installation of projector and ceiling mount assembly';
                    $seenCategories['projector'] = true;
                }
                if (! isset($seenCategories['audio']) && preg_match('/\b(speaker|dsp|amplifier|microphone|mic|audio|transmitter|receiver)\b/', $desc)) {
                    $bullets[] = 'Installation of audio system components';
                    $seenCategories['audio'] = true;
                }
                if (! isset($seenCategories['camera']) && preg_match('/\b(camera|ptz|webcam)\b/', $desc)) {
                    $bullets[] = 'Installation and framing of camera system';
                    $seenCategories['camera'] = true;
                }
                if (! isset($seenCategories['vc']) && preg_match('/\b(codec|video conferenc|teams room|zoom room)\b/', $desc)) {
                    $bullets[] = 'Installation and commissioning of video conferencing system';
                    $seenCategories['vc'] = true;
                }
                if (! isset($seenCategories['control']) && preg_match('/\b(control|touch panel|crestron|extron|amx)\b/', $desc)) {
                    $bullets[] = 'Installation of control system and touch panel';
                    $seenCategories['control'] = true;
                }
                if (! isset($seenCategories['rack']) && preg_match('/\b(rack|1u|2u|blank)\b/', $desc)) {
                    $bullets[] = 'Rack installation and equipment mounting';
                    $seenCategories['rack'] = true;
                }
            }
        }

        // ── Common install bullets (always applicable) ───────────────────────
        if (! empty($bullets)) {
            // Cabling
            if (isset($allActivities['cable_installation']) && ! empty($cableRequirements)) {
                $cableTypes = array_values(array_unique(array_column($cableRequirements, 'cable_type')));
                $bullets[] = ! empty($cableTypes)
                    ? 'Installation of ' . implode(', ', $cableTypes) . ' cabling as per cable schedule'
                    : 'Installation of AV cabling as per cable schedule';
            } elseif (! empty($cableRequirements)) {
                $cableTypes = array_values(array_unique(array_column($cableRequirements, 'cable_type')));
                $bullets[] = ! empty($cableTypes)
                    ? 'Installation of ' . implode(', ', $cableTypes) . ' cabling'
                    : 'Installation of AV cabling';
            }

            $bullets[] = 'Mounting and fixing of AV equipment to walls, ceilings, and furniture';
            $bullets[] = 'Termination and labelling of all cables at both ends';
            $bullets[] = 'Testing and commissioning of all AV systems';
            $bullets[] = 'Client handover and system demonstration';

            $data['scope_of_works_bullets'] = array_values(array_unique($bullets));
        }

        return $data;
    }

    // =========================================================================
    // 2. PPE MATRIX — task-specific table
    // =========================================================================

    private static function addPpeMatrix(array $data): array
    {
        $data['ppe_matrix'] = [
            [
                'task' => 'General AV installation works',
                'ppe'  => ['Safety footwear (steel toe cap)', 'Hi-vis vest'],
            ],
            [
                'task' => 'Drilling / cutting / fixing',
                'ppe'  => ['Safety glasses', 'Latex / nitrile gloves', 'Dust mask (FFP2)'],
            ],
            [
                'task' => 'Working at height',
                'ppe'  => ['Hard hat', 'Appropriate access equipment as specified'],
            ],
            [
                'task' => 'Cable installation and termination',
                'ppe'  => ['Gloves', 'Eye protection'],
            ],
            [
                'task' => 'Working in ceiling voids',
                'ppe'  => ['Hard hat', 'Dust mask (FFP2)', 'Safety glasses', 'Gloves'],
            ],
            [
                'task' => 'Manual handling of heavy equipment',
                'ppe'  => ['Safety footwear', 'Gloves', 'Back support belt (where applicable)'],
            ],
        ];

        // Merge PPE items from matrix into the base PPE list (deduplicated)
        $existingPpe = (array) ($data['ppe'] ?? []);
        $matrixPpe   = [];
        foreach ($data['ppe_matrix'] as $row) {
            foreach ($row['ppe'] as $item) {
                $matrixPpe[] = $item;
            }
        }
        $data['ppe'] = array_values(array_unique(array_merge($existingPpe, $matrixPpe)));

        return $data;
    }

    // =========================================================================
    // 3. ACCESS EQUIPMENT — EN131 / PASMA / IPAF detail
    // =========================================================================

    private static function addAccessEquipmentDetail(array $data): array
    {
        $items = [
            'Step ladders (EN131 compliant, inspected before each use)',
            'Podium steps (where working platform required)',
            'Mobile access tower (where above 2 m, PASMA assembled)',
            'MEWP / scissor lift (where above 3.5 m, IPAF certified operator)',
            'Kick stool (for low-level access only)',
        ];
        $requirements = [
            'All access equipment to be inspected before use and defective items removed from service',
            'PASMA certification required for tower assembly and use',
            'IPAF certification required for MEWP / powered access operation',
            'Harness and lanyard to be used with MEWP where required by site rules',
            'Access equipment not to be positioned near open edges or on uneven surfaces',
            'No improvised access (chairs, desks, stacked items) permitted',
        ];

        // Honour explicit "ground level / no podium / no access equipment" PM instructions.
        // Scope of works or method-statement notes may carry these signals when the engineer
        // has declared the installation does not require platform access.
        $hints = strtolower(implode(' ', array_filter([
            (string) ($data['method_statement_notes'] ?? ''),
            (string) ($data['works_description']      ?? ''),
            (string) ($data['scope_of_works']         ?? ''),
            (string) ($data['works_summary']          ?? ''),
        ])));

        $noPodium    = self::containsPhrase($hints, ['no podium', 'without podium', 'not podium', 'no platform', 'without platform']);
        $groundLevel = self::containsPhrase($hints, ['ground level', 'floor level', 'at ground', 'reachable from the floor', 'reachable from floor']);
        $noAccessKit = self::containsPhrase($hints, ['no access equipment', 'without access equipment']);

        // 260817-r5e Item 3 — reconcile against the document's own contents.
        //
        // 21CQ30960-OPS Rev 1.0 stated in §6.4 "Podium steps excluded —
        // working height does not require a working platform" while RA01's
        // controls listed podium steps and Step 8 told operatives to remove
        // them. A RAMS that contradicts itself on a work-at-height control is
        // worse than one that says nothing, and "working height does not
        // require a working platform" is a safety judgement the generator is
        // not entitled to make from a prose hint.
        //
        // So: drop an access-equipment type ONLY when nothing else in the
        // document references it, and never write an exclusion claim. The PM's
        // "ground level" instruction still takes effect — silently, which is
        // all the data supports.
        $referenced = self::accessEquipmentReferencedElsewhere($data);

        /** @var list<string> $dropTypes */
        $dropTypes = [];
        if ($groundLevel || $noAccessKit) {
            $dropTypes = ['podium', 'tower', 'mewp'];
        } elseif ($noPodium) {
            $dropTypes = ['podium'];
        }

        // Keyword → the item / requirement lines that belong to each type.
        $typeKeywords = [
            'podium' => ['items' => ['podium'],                     'requirements' => []],
            'tower'  => ['items' => ['tower'],                      'requirements' => ['PASMA', 'tower']],
            'mewp'   => ['items' => ['MEWP', 'scissor'],            'requirements' => ['IPAF', 'MEWP', 'harness']],
        ];

        foreach ($dropTypes as $type) {
            if ($referenced[$type] ?? false) {
                continue; // referenced in a control or a method step — leave it
            }

            foreach ($typeKeywords[$type]['items'] as $needle) {
                $items = array_values(array_filter(
                    $items,
                    static fn (string $s): bool => stripos($s, $needle) === false,
                ));
            }
            foreach ($typeKeywords[$type]['requirements'] as $needle) {
                $requirements = array_values(array_filter(
                    $requirements,
                    static fn (string $s): bool => stripos($s, $needle) === false,
                ));
            }
        }

        $data['access_equipment_detail'] = [
            'items'        => $items,
            'requirements' => $requirements,
        ];

        return $data;
    }

    /**
     * 260817-r5e Item 3 — which access-equipment types does the rest of this
     * document already rely on?
     *
     * Scans the two places an engineer reads a work-at-height instruction:
     * the risk assessment's hazard names + control measures, and the method
     * statement's phase titles + steps. If podium steps appear in RA01's
     * controls or in "remove access equipment" at Step 8, the §6.4 access list
     * must not pretend they are out of scope.
     *
     * @return array{podium:bool,tower:bool,mewp:bool}
     */
    private static function accessEquipmentReferencedElsewhere(array $data): array
    {
        $corpus = [];

        foreach ((array) ($data['hazards'] ?? []) as $h) {
            if (! is_array($h)) {
                continue;
            }
            $corpus[] = (string) ($h['hazard'] ?? '');
            foreach ((array) ($h['controls'] ?? []) as $control) {
                $corpus[] = (string) $control;
            }
        }

        foreach ((array) ($data['method_statement']['phases'] ?? []) as $phase) {
            if (! is_array($phase)) {
                continue;
            }
            $corpus[] = (string) ($phase['title'] ?? '');
            foreach ((array) ($phase['steps'] ?? []) as $step) {
                $corpus[] = (string) $step;
            }
        }

        $blob = strtolower(implode(' ', $corpus));

        return [
            'podium' => str_contains($blob, 'podium'),
            'tower'  => str_contains($blob, 'access tower') || str_contains($blob, 'mobile tower'),
            'mewp'   => str_contains($blob, 'mewp') || str_contains($blob, 'scissor lift'),
        ];
    }

    /**
     * Case-insensitive multi-phrase contains check.
     * Returns true if any of the given phrases appears in the haystack.
     */
    private static function containsPhrase(string $haystack, array $phrases): bool
    {
        foreach ($phrases as $phrase) {
            if ($phrase !== '' && str_contains($haystack, strtolower($phrase))) {
                return true;
            }
        }
        return false;
    }

    // =========================================================================
    // 4. FILL MISSING HAZARD CONTROLS (RA01–RA07 gap closure)
    // =========================================================================

    /**
     * Ensure every existing hazard has control measures.
     * If a hazard row has an empty controls array, inject standard AV-specific
     * controls based on keyword matching against the hazard name.
     * Never overwrites existing controls — only fills gaps.
     */
    private static function fillMissingHazardControls(array $data): array
    {
        $hazards = (array) ($data['hazards'] ?? []);

        $controlDefaults = [
            'height' => [
                'Use appropriate access equipment (podium steps, tower, or MEWP) — no improvised access',
                'Ensure three-point contact when ascending and descending access equipment',
                'Inspect all access equipment before use; remove defective items from service',
                'Secure tools and materials to prevent items falling from height',
                'Barrier or cordon area below when working above occupied spaces',
            ],
            'manual handling' => [
                'Assess load weight before lifting — team lift for items over 20 kg',
                'Use mechanical aids (trolley, lifter) where available',
                'Adopt correct lifting technique: bend knees, keep back straight, lift with legs',
                'Plan route before carrying — ensure path is clear and level',
                'Do not carry loads that obstruct vision or exceed comfortable grip',
            ],
            'electrical' => [
                'All mains electrical connections by qualified electrician only — no live working by AV engineers',
                'Visually inspect cables and connectors before use; do not use damaged equipment',
                'Use PAT-tested power supplies, extension leads, and adaptors only',
                'Isolate power before connecting or disconnecting AV equipment',
                'Confirm equipment earthing before power-on',
            ],
            'slip' => [
                'Maintain clear, tidy work area at all times — no trailing cables across walkways',
                'Use cable covers or warning signage where temporary cables cross pedestrian routes',
                'Clean up off-cuts, packaging, and debris immediately',
                'Wear safety footwear with non-slip soles',
                'Report wet or contaminated surfaces to site management immediately',
            ],
            'trip' => [
                'Maintain clear, tidy work area at all times — no trailing cables across walkways',
                'Use cable covers or warning signage where temporary cables cross pedestrian routes',
                'Clean up off-cuts, packaging, and debris immediately',
                'Wear safety footwear with non-slip soles',
            ],
            'noise' => [
                'Use hearing protection when exposed to sustained drilling or power tool noise',
                'Limit noisy works to agreed times and inform occupants in advance',
                'Use low-vibration tools where practicable and take regular breaks',
            ],
            'occupied' => [
                'Maintain clean, segregated work areas with clear signage and barriers',
                'Coordinate work windows to minimise disruption to occupants',
                'Protect client property and ensure confidentiality of visible data',
            ],
            'confined' => [
                'Confirm ventilation and safe access before entering comms rooms or enclosures',
                'Do not obstruct escape routes; maintain clear access at all times',
                'Ensure a second person is aware of entry and available for assistance',
            ],
        ];

        foreach ($hazards as &$hazard) {
            if (! is_array($hazard)) {
                continue;
            }

            $controls = (array) ($hazard['controls'] ?? []);
            // Filter truly empty entries
            $controls = array_values(array_filter($controls, fn ($c) => trim((string) $c) !== ''));

            if (! empty($controls)) {
                continue; // Already has controls — do not overwrite
            }

            $name = strtolower((string) ($hazard['hazard'] ?? ''));

            foreach ($controlDefaults as $keyword => $defaults) {
                if (str_contains($name, $keyword)) {
                    $hazard['controls'] = $defaults;
                    break;
                }
            }
        }

        $data['hazards'] = $hazards;

        return $data;
    }

    // =========================================================================
    // 5. PROJECT-SPECIFIC RISKS (RA08+)
    // =========================================================================

    private static function addProjectSpecificRisks(array $data): array
    {
        // Phase 26 Plan 07 (HAZ-02 gap closure): this method's function is
        // fully superseded by the declarative 18-hazard tiered library on
        // BOTH RamsBuilderService::runFromReview() and ::runPipeline(). It
        // is the sixth, previously-undocumented hazard-injection path
        // (26-07-PLAN.md <investigation>) — the traced-and-resolved cause
        // of the unexplained 7→11 delta in 26-VERIFICATION.md. Every one of
        // its 7 candidates now has a direct or D-02-mapped equivalent in
        // hazard_templates: Cable Pulling & Termination -> "Cable pulling
        // and termination" (signal:first_fix_cabling); Low Voltage AV
        // Connections -> "Low voltage AV connections" (always); Fixings
        // into Walls & Ceilings -> "Fixings into walls, ceilings and
        // pillars" (signal:any_penetration); Rack Installation -> folded
        // into "Manual handling" (signal:display_mount_or_rack) +
        // "Fixings into walls, ceilings and pillars" per D-02; Working in
        // Ceiling Voids -> "Restricted access and ceiling voids"
        // (signal:ceiling_void_access); Dust from Drilling & Cutting ->
        // "Dust from drilling and cutting" (signal:any_drilling); Working
        // Near Existing Services -> folded into "Fixings into walls,
        // ceilings and pillars" per D-02 (hidden-services check).
        //
        // The guard below is the ENTIRE change. Everything after it is
        // otherwise byte-identical to pre-Plan-07 behaviour — do not touch,
        // rename, or "improve" any of it, since it must remain exactly what
        // fires when an operator sets RAMS_HAZARD_LIBRARY_TIERING=false to
        // roll back.
        if (config('rams_tier1.hazard_tiering_enabled', true)) {
            return $data;
        }

        $existing = (array) ($data['hazards'] ?? []);

        // Track existing hazard names to avoid duplicates — keep in sync as we append.
        $existingNames = array_map(
            fn ($h) => strtolower(trim((string) ($h['hazard'] ?? ''))),
            $existing
        );

        $maxId = 0;
        foreach ($existing as $h) {
            $id = (int) ($h['id'] ?? 0);
            if ($id > $maxId) {
                $maxId = $id;
            }
        }

        // Build a single lowercased hint string from scope/notes once, for keyword gating.
        $hints = strtolower(implode(' ', array_filter([
            (string) ($data['method_statement_notes'] ?? ''),
            (string) ($data['works_description']      ?? ''),
            (string) ($data['scope_of_works']         ?? ''),
            (string) ($data['works_summary']          ?? ''),
        ])));

        // Equipment descriptions — used to derive whether rack work, cable pulling, etc. apply.
        $equipmentBlob = strtolower(implode(' | ', array_map(
            static fn ($e) => is_array($e)
                ? trim((string) ($e['description'] ?? '') . ' ' . (string) ($e['part_number'] ?? ''))
                : (string) $e,
            (array) ($data['equipment'] ?? []))));

        $scopeBlob = $hints . ' ' . $equipmentBlob;

        // Explicit PM opt-outs
        $noCeiling = self::containsPhrase($scopeBlob, ['no ceiling', 'without ceiling', 'not working in ceiling', 'no ceiling access']);
        $noRack    = self::containsPhrase($scopeBlob, ['no rack', 'without rack', 'no rack work']);

        // Each candidate risk is now tagged with an `applies` closure that inspects scope
        // and equipment text. A risk is only appended when its scope condition evaluates true.
        $avRisks = [
            [
                'applies' => static fn (): bool
                    => ! $noRack
                    && self::containsPhrase($scopeBlob, ['rack', '19-inch', '19"', 'equipment cabinet', 'comms cabinet', 'server rack', 'av rack']),
                'hazard'          => 'Rack Installation',
                'persons_at_risk' => ['21CAV Staff', 'Client Staff'],
                'pre_likelihood'  => 3,
                'pre_severity'    => 3,
                'controls'        => [
                    'Team lift for items over 20 kg; mechanical aids used where available',
                    'Rack secured to floor or wall before loading equipment',
                    'Cable management applied as equipment is installed — no trailing cables',
                    'Power isolated until all rack equipment is physically secured',
                ],
                'post_likelihood' => 1,
                'post_severity'   => 2,
            ],
            [
                'applies' => static fn (): bool => true, // cable work is universal on AV installs
                'hazard'          => 'Cable Pulling & Termination',
                'persons_at_risk' => ['21CAV Staff'],
                'pre_likelihood'  => 3,
                'pre_severity'    => 2,
                'controls'        => [
                    'Cable pulling carried out in pairs for runs over 15 m',
                    'Cable lubricant used on long conduit pulls to reduce force',
                    'Eye protection worn during cable termination and crimping',
                    'Sharp cable ends covered immediately after cutting',
                    'Work area kept clear of cable coils to prevent trip hazard',
                ],
                'post_likelihood' => 1,
                'post_severity'   => 1,
            ],
            [
                'applies' => static fn (): bool
                    => ! $noCeiling
                    && self::containsPhrase($scopeBlob, ['ceiling void', 'ceiling tile', 'above ceiling', 'cable tray', 'basket tray', 'ceiling cavity', 'plenum', 'ceiling access', 'overhead cable', 'drop rod', 'containment']),
                'hazard'          => 'Working in Ceiling Voids',
                'persons_at_risk' => ['21CAV Staff', 'Client Staff', 'Building Occupants'],
                'pre_likelihood'  => 3,
                'pre_severity'    => 3,
                'controls'        => [
                    'Hard hat worn at all times when ceiling tiles are removed',
                    'Dust mask (FFP2) worn when accessing ceiling voids',
                    'Ceiling tiles removed and replaced one at a time — never left open unattended',
                    'Dust sheets laid below work area to protect furniture and equipment',
                    'Area beneath cordoned off when overhead work is in progress',
                    'Existing services (fire, HVAC, sprinklers) identified before work commences',
                ],
                'post_likelihood' => 1,
                'post_severity'   => 2,
            ],
            [
                'applies' => static fn (): bool => true, // AV installs always involve LV connections
                'hazard'          => 'Low Voltage AV Connections',
                'persons_at_risk' => ['21CAV Staff'],
                'pre_likelihood'  => 2,
                'pre_severity'    => 3,
                'controls'        => [
                    'All AV equipment powered down before connecting or disconnecting cables',
                    'Visual inspection of cables and connectors before each use',
                    'No work on mains-voltage circuits — all mains connections by qualified electrician',
                    'PAT-tested power supplies and extension leads only',
                    'Equipment earthing verified before power-on',
                ],
                'post_likelihood' => 1,
                'post_severity'   => 2,
            ],
            [
                'applies' => static fn (): bool => true, // wall fixings are universal — mount, bracket, anchor
                'hazard'          => 'Fixings into Walls & Ceilings',
                'persons_at_risk' => ['21CAV Staff', 'Client Staff', 'Building Occupants'],
                'pre_likelihood'  => 3,
                'pre_severity'    => 3,
                'controls'        => [
                    'Verify substrate type (plasterboard, masonry, steel) before drilling',
                    'Use correct anchor type and size for the substrate and load',
                    'Do not fix into unknown surfaces — confirm with site/building management',
                    'Check for hidden services (pipes, cables, reinforcement) before any penetration',
                    'Pull-test fixings to confirm load capacity before mounting equipment',
                ],
                'post_likelihood' => 1,
                'post_severity'   => 2,
            ],
            [
                'applies' => static fn (): bool
                    => self::containsPhrase($scopeBlob, ['drill', 'fix', 'mount', 'bracket', 'anchor', 'masonry', 'plasterboard', 'wall mount']),
                'hazard'          => 'Dust from Drilling & Cutting',
                'persons_at_risk' => ['21CAV Staff', 'Client Staff', 'Building Occupants'],
                'pre_likelihood'  => 3,
                'pre_severity'    => 2,
                'controls'        => [
                    'FFP2 dust mask and safety glasses worn during all drilling and cutting',
                    'Use dust extraction attachment on drill where practicable',
                    'Lay dust sheets below work area to contain debris',
                    'Vacuum work area immediately after drilling — do not leave dust accumulation',
                    'Inform building occupants of dust-generating works in advance',
                ],
                'post_likelihood' => 1,
                'post_severity'   => 1,
            ],
            [
                'applies' => static fn (): bool
                    => ! $noCeiling
                    && self::containsPhrase($scopeBlob, ['ceiling void', 'ceiling tile', 'above ceiling', 'riser', 'plenum', 'cable tray', 'basket tray', 'containment', 'comms room', 'ceiling access', 'overhead']),
                'hazard'          => 'Working Near Existing Services',
                'persons_at_risk' => ['21CAV Staff', 'Client Staff', 'Building Occupants'],
                'pre_likelihood'  => 2,
                'pre_severity'    => 4,
                'controls'        => [
                    'Identify and mark all existing services before commencing work in ceiling voids or risers',
                    'Review asbestos register with responsible person before any penetrations',
                    'Maintain minimum clearance distances from sprinkler heads, fire dampers, and HVAC ductwork',
                    'Do not disturb or relocate fire alarm devices, sprinkler pipework, or smoke detectors',
                    'Report any accidental contact with existing services to site management immediately',
                ],
                'post_likelihood' => 1,
                'post_severity'   => 2,
            ],
        ];

        foreach ($avRisks as $risk) {
            // Scope gate — skip risks that don't apply to this project.
            $applies = $risk['applies'] ?? static fn (): bool => true;
            if (! $applies()) {
                continue;
            }
            unset($risk['applies']);

            // Exact-match and significant-overlap dedup. Critical: short first words (Rack, Dust,
            // Low) are not exempt — the previous strlen > 4 gate caused the RA08/RA15, RA11/RA16,
            // RA13/RA17 duplicates. `$existingNames` must be updated inside the loop so hazards
            // appended earlier in this pass are also considered when testing later candidates.
            $riskName = strtolower(trim($risk['hazard']));
            $riskWords = array_values(array_filter(
                explode(' ', preg_replace('/[^a-z ]/', ' ', $riskName) ?? ''),
                static fn (string $w): bool => strlen($w) > 3,
            ));

            $isDuplicate = false;
            foreach ($existingNames as $existingName) {
                if ($existingName === $riskName) {
                    $isDuplicate = true;
                    break;
                }
                $matchCount = 0;
                foreach ($riskWords as $w) {
                    if (str_contains($existingName, $w)) {
                        $matchCount++;
                    }
                }
                // Require a majority of significant words to match — prevents false positives
                // between "Dust generation from drilling" and "Dust mask provision" style names.
                if (count($riskWords) > 0 && $matchCount >= max(2, (int) ceil(count($riskWords) * 0.5))) {
                    $isDuplicate = true;
                    break;
                }
            }

            if ($isDuplicate) {
                continue;
            }

            $maxId++;
            $risk['id']      = $maxId;
            $existing[]      = $risk;
            $existingNames[] = $riskName; // keep in-loop dedup consistent
        }

        $data['hazards'] = $existing;

        return $data;
    }

    // =========================================================================
    // 6. RISK COLOUR KEY
    // =========================================================================

    private static function addRiskColourKey(array $data): array
    {
        $data['risk_colour_key'] = [
            ['level' => 'LOW',    'range' => '1–4',  'description' => 'Acceptable — proceed with standard controls',       'action' => 'Monitor and maintain existing controls'],
            ['level' => 'MEDIUM', 'range' => '5–9',  'description' => 'Reduce risk — additional controls required',         'action' => 'Implement additional controls before work proceeds'],
            ['level' => 'HIGH',   'range' => '10–25', 'description' => 'Unacceptable — stop work immediately',              'action' => 'Do not proceed. Review method, apply further controls, escalate to PM'],
        ];

        return $data;
    }

    // =========================================================================
    // 7. PERMIT & ISOLATION REQUIREMENTS
    // =========================================================================

    private static function addPermitAndIsolation(array $data): array
    {
        $data['permit_and_isolation'] = [
            'rules' => [
                'No live working on mains-voltage circuits — all mains connections by qualified electrician',
                'Obtain permit to work before accessing ceiling voids, risers, or restricted areas',
                'Electrical isolation required before removing or replacing rack-mounted equipment',
                'Client or building management approval required before any fixings into fire-rated structures',
                'Hot works permit required if soldering or heat-shrink operations are performed on site',
                'All isolation points to be locked off and tagged during the isolation period',
            ],
        ];

        return $data;
    }

    // =========================================================================
    // 8. FIXINGS & INSTALLATION CONTROL
    // =========================================================================

    private static function addFixingsControl(array $data): array
    {
        $data['fixings_control'] = [
            'rules' => [
                'Verify substrate type (plasterboard, masonry, concrete, steel) before selecting fixings',
                'Use manufacturer-approved anchors rated for the equipment weight and substrate type',
                'Do not fix into unknown or unverified surfaces — confirm with site/building management',
                'Check for hidden services (pipes, cables, steel reinforcement) using a detector before drilling',
                'Pull-test all structural fixings to confirm load capacity before mounting AV equipment',
                'Document fixing positions and types in the as-installed record',
            ],
        ];

        return $data;
    }

    // =========================================================================
    // 9. SUPERVISION & QA
    // =========================================================================

    private static function addSupervisionAndQA(array $data): array
    {
        $data['supervision_and_qa'] = [
            'responsibilities' => [
                'Lead engineer is responsible for all on-site H&S decisions and work quality',
                'All installation work to be visually inspected before commissioning begins',
                'Cable terminations to be tested and verified before system power-on',
                'Snagging list to be completed and agreed with client before sign-off',
                'As-installed documentation (cable schedule, photos, test results) compiled before leaving site',
                'Any deviation from the method statement to be reported to the Project Manager immediately',
            ],
        ];

        return $data;
    }

    // =========================================================================
    // 10. METHOD STATEMENT ↔ RISK CROSS-REFERENCES
    // =========================================================================

    private static function crossReferenceMethodStatementRisks(array $data): array
    {
        $ms = $data['method_statement'] ?? [];
        $phases = (array) ($ms['phases'] ?? []);

        if (empty($phases)) {
            return $data;
        }

        // 260817-r5e — the RA reference is the hazard's ROW POSITION in the
        // rendered risk register, NOT $h['id']. Both renderers label the Ref
        // column 'RA' . str_pad(index + 1) (DocxBuilderService:1221,
        // rams-v2.blade.php:1393), so keying off $h['id'] emitted dangling
        // references the moment ids stopped being 1..N in order — which they
        // do whenever RamsDataBuilderService::normalise drops an unlabelled
        // hazard row but keeps the surviving rows' original ids.
        $hazardIds = [];
        foreach (array_values((array) ($data['hazards'] ?? [])) as $idx => $h) {
            $name = strtolower((string) ($h['hazard'] ?? ''));
            if ($name !== '') {
                $hazardIds[] = ['id' => $idx + 1, 'name' => $name];
            }
        }

        $keywordRiskMap = [
            'display'      => ['mount', 'screen', 'display', 'manual handling', 'height'],
            'cable'        => ['cable', 'termina', 'pull', 'trip'],
            'rack'         => ['rack', 'heavy', 'manual handling'],
            'ceiling'      => ['ceiling', 'void', 'overhead', 'debris'],
            'electrical'   => ['power', 'voltage', 'electri', 'connection', 'connect', 'isolat'],
            'height'       => ['height', 'ladder', 'access tower', 'podium', 'mewp'],
            'commissioning'=> ['commission', 'test', 'power on', 'power up'],
            'fixing'       => ['fix', 'drill', 'mount', 'bracket', 'anchor', 'substrate'],
            'dust'         => ['dust', 'drill', 'cut'],
            'services'     => ['service', 'sprinkler', 'hvac', 'fire', 'asbestos'],
            'induction'    => ['induction', 'toolbox', 'ppe', 'sign in', 'arrive', 'arrival'],
        ];

        $upgradedPhases = [];

        foreach ($phases as $phase) {
            $phase = (array) $phase;

            // 260817-r5e — strip any model-authored "Associated Risks: …"
            // bullet BEFORE deriving our own. Pre-fix, the AI prompt asked
            // for one and this method added a second, so every phase rendered
            // two lines carrying different RA-IDs (21CQ30960-OPS Rev 1.0).
            // The prompt no longer asks — but models ignore negative
            // instructions often enough that stripping here is the actual
            // guarantee, and it also cleans phases already persisted in
            // generated_data (upgrade() runs on every render path).
            $phase['steps'] = array_values(array_filter(
                (array) ($phase['steps'] ?? []),
                static fn ($step): bool => ! self::isAssociatedRisksLine((string) $step),
            ));

            $title     = strtolower((string) ($phase['title'] ?? ''));
            $stepsText = strtolower(implode(' ', $phase['steps']));
            $combined  = $title . ' ' . $stepsText;

            $matchedIds = [];

            foreach ($hazardIds as $entry) {
                $hazardName = $entry['name'];

                foreach ($keywordRiskMap as $keywords) {
                    foreach ($keywords as $keyword) {
                        if (str_contains($combined, $keyword) && str_contains($hazardName, $keyword)) {
                            $matchedIds[] = $entry['id'];
                            break 2;
                        }
                    }
                }
            }

            // Fallback: scan for any hazard name fragments
            if (empty($matchedIds)) {
                foreach ($hazardIds as $entry) {
                    $words = explode(' ', $entry['name']);
                    foreach ($words as $word) {
                        if (strlen($word) > 4 && str_contains($combined, $word)) {
                            $matchedIds[] = $entry['id'];
                            break;
                        }
                    }
                }
            }

            $matchedIds = array_values(array_unique($matchedIds));
            sort($matchedIds);

            $phase['associated_risks'] = $matchedIds;
            $phase['associated_risks_label'] = ! empty($matchedIds)
                ? 'Associated Risks: ' . implode(', ', array_map(fn ($id) => 'RA' . str_pad((string) $id, 2, '0', STR_PAD_LEFT), $matchedIds))
                : '';

            $upgradedPhases[] = $phase;
        }

        $data['method_statement']['phases'] = $upgradedPhases;

        return $data;
    }

    /**
     * 260817-r5e — is this method-statement bullet a risk cross-reference
     * line rather than a work instruction?
     *
     * Matches the shapes a model actually produces: "Associated Risks: RA01,
     * RA02", "- Associated risks — RA01", "• Associated Risk: RA03".
     * Deliberately anchored to the start of the bullet so a genuine
     * instruction that merely mentions risks ("Brief the team on the
     * associated risks before starting") is left alone.
     */
    private static function isAssociatedRisksLine(string $step): bool
    {
        return preg_match('/^\s*[-•*\x{2022}\s]*associated\s+risks?\s*[:\-–—]/iu', $step) === 1;
    }

    // =========================================================================
    // 11. CDM 2015 DUTY HOLDERS
    // =========================================================================

    private static function addCdmDutyHolders(array $data): array
    {
        $project = (array) ($data['project'] ?? []);

        $data['cdm_duty_holders'] = [
            'client'               => trim((string) ($project['client'] ?? '')) ?: '[Client Name]',
            'principal_designer'   => '[To be confirmed]',
            'principal_contractor' => '[To be confirmed]',
            'contractor'           => '21st Century AV Ltd',
            'subcontractor'        => '21st Century AV Ltd',
            'project_manager'      => trim((string) ($project['project_manager'] ?? '')) ?: '[To be confirmed]',
            'site_supervisor'      => trim((string) ($project['lead_engineer'] ?? '')) ?: '[To be confirmed]',
            'cdm_regulation'       => 'Construction (Design and Management) Regulations 2015',
            'notification'         => 'F10 notification submitted by Principal Contractor where applicable',
        ];

        return $data;
    }

    // =========================================================================
    // 12. MATERIAL HANDLING — derive from equipment data
    // =========================================================================

    /**
     * GATE-09 — an independent re-check of every display-lift item
     * `deriveMaterialHandling()` just derived, run immediately after it in
     * `upgrade()`'s pipeline (config-gated by the caller). Also validates
     * engineer-typed `material_handling.large_items[]` rows — see below.
     *
     * This method NEVER re-derives a team size and NEVER calls
     * {@see DisplayLiftPolicy::forSize()} — it only re-checks the numbers
     * `deriveMaterialHandling()` already stored, via the independent
     * {@see DisplayLiftPolicy::violatesPolicy()} re-check. This is the "gate
     * never trusts the same call path that produced the text" anti-pattern
     * guard from 27-RESEARCH.md: a violation check that merely re-derived
     * `forSize()`'s own output and compared it would not be a true
     * independent check.
     *
     * Plan 27-06 (2026-08-26 user decision) — `material_handling.large_items`
     * IS in scope, reversing Plan 27-03's original position. Plan 27-03
     * declared it OUT of scope on the grounds of the existing "engineer
     * values always win, never re-validated" convention (HAZ-04's
     * `score_reviewed` precedent). That produced a gate that could never
     * fire in production: `material_handling_derived.items` (the only array
     * this method originally checked) is generated by
     * `DisplayLiftPolicy::forSize()` one line earlier in the same pipeline,
     * so it is conformant by construction and can never trip
     * `violatesPolicy()` — proving the gate fired at all required a Mockery
     * `alias:` mock. Meanwhile `large_items` is free-text typed by an
     * engineer on the RAMS review screen
     * (`resources/views/rams/review.blade.php`) that renders straight into
     * the live DOCX/PDF unchecked — the exact defect class the 21CQ30960
     * professional review raised. Shown this gap, the user chose to extend
     * GATE-09 to engineer-typed rows: a stated lift team size is treated as
     * a safety claim, not a preference, and is a DELIBERATE, recorded
     * exception to the "engineer values always win" convention — not a
     * reversal of that convention elsewhere. See
     * `.planning/phases/27-manual-handling-display-lift-house-rules/27-06-PLAN.md`
     * and `27-06-SUMMARY.md`.
     *
     * The engineer-row pass parses a team size from free text via
     * {@see self::parseStatedTeamSize()}. Parsing is deliberately
     * conservative (T-27-06-01): an unrecognised or ambiguous (2+
     * conflicting) count returns null and the row is SKIPPED — a parsing
     * miss must never block a real job. A resolvable team size with an
     * unresolvable display size is still checked
     * (`violatesPolicy($stated, null)` still fires for 4+ operatives, per
     * D-05's asymmetry — only an unparseable TEAM SIZE skips a row, never an
     * unparseable size).
     *
     * Throws {@see RamsGenerationException} on the FIRST violating item
     * found (derived or engineer-typed), naming the item, its stated
     * `min_persons`, and its resolved `inches` (or "unresolved" when null)
     * so the `error_message` surfaced on `rams/index.blade.php` (via
     * `BuildRamsDocumentJob::handle()`'s `catch (\Throwable $e)` ->
     * `RamsDocument.status = STATUS_FAILED` -> `error_message`) is
     * actionable, not generic.
     */
    private static function enforceDisplayLiftGate(array $data): array
    {
        $items = (array) ($data['material_handling_derived']['items'] ?? []);

        foreach ($items as $item) {
            $minPersons = $item['min_persons'] ?? null;
            if ($minPersons === null) {
                // Non-display item (mount/bracket/projector/rack/amp/speaker/
                // catch-all) — DisplayLiftPolicy's bands do not govern these,
                // per deriveMaterialHandling()'s own null convention.
                continue;
            }

            $inches = $item['inches'] ?? null;

            if (DisplayLiftPolicy::violatesPolicy((int) $minPersons, $inches === null ? null : (float) $inches)) {
                $inchesLabel = $inches === null ? 'unresolved' : ((string) $inches . '"');

                throw new RamsGenerationException(sprintf(
                    'Manual handling team size for "%s" (%s operative%s, %s) does not meet the display-lift '
                    . 'house rules (RULE-02/GATE-09): 4+ operatives are never required, 2 operatives are '
                    . 'insufficient above 90", and 1 operative is insufficient at 55" or larger. Correct the '
                    . 'stated team size before regenerating, or set RAMS_DISPLAY_LIFT_GATE=false to disable '
                    . 'this check.',
                    (string) ($item['item'] ?? 'unnamed item'),
                    (string) $minPersons,
                    ((int) $minPersons === 1 ? '' : 's'),
                    $inchesLabel,
                ));
            }
        }

        // Plan 27-06 — engineer-typed rows. An unparseable team size is
        // never a violation (T-27-06-01: guessing is worse than not
        // checking) — the row is simply skipped.
        $largeItems = (array) ($data['material_handling']['large_items'] ?? []);

        foreach ($largeItems as $row) {
            $handlingMethod = (string) ($row['handling_method'] ?? '');
            $stated = self::parseStatedTeamSize($handlingMethod);
            if ($stated === null) {
                continue;
            }

            $inches = self::parseStatedInches((string) ($row['item'] ?? ''))
                ?? self::parseStatedInches($handlingMethod);

            if (DisplayLiftPolicy::violatesPolicy($stated, $inches)) {
                $inchesLabel = $inches === null ? 'unresolved' : ((string) $inches . '"');

                throw new RamsGenerationException(sprintf(
                    'Engineer-entered manual handling team size for "%s" (%s operative%s, %s) does not meet '
                    . 'the display-lift house rules (RULE-02/GATE-09): 4+ operatives are never required, 2 '
                    . 'operatives are insufficient above 90", and 1 operative is insufficient at 55" or '
                    . 'larger. This row was entered by an engineer on the RAMS review screen — correct the '
                    . 'stated team size there before regenerating, or set RAMS_DISPLAY_LIFT_GATE=false to '
                    . 'disable this check.',
                    (string) ($row['item'] ?? 'unnamed item'),
                    (string) $stated,
                    ($stated === 1 ? '' : 's'),
                    $inchesLabel,
                ));
            }
        }

        return $data;
    }

    /**
     * Plan 27-06 Task 1 — conservative free-text team-size parser for
     * engineer-typed `material_handling.large_items[].handling_method`
     * strings. Extracts an operative count; NEVER decides conformance
     * (that is `DisplayLiftPolicy::violatesPolicy()`'s job alone, wired in
     * by Task 2) and NEVER calls `DisplayLiftPolicy`.
     *
     * Recognises, case-insensitively: bare digits and the number-words
     * one-four directly adjacent to "person(s)"/"operative(s)" (e.g.
     * "2 persons", "two persons", "minimum 3 persons", "3-person lift",
     * "team lift (2 persons minimum)", "minimum 4 operatives",
     * "two-operative team lift"), plus "single"/"single-hand" mapped to 1
     * ("single person lift", "single-hand lift").
     *
     * T-27-06-01 (HIGH): a parsing miss must never block a real job.
     * Ambiguity ALWAYS returns null, never a guess:
     *   - no recognisable count anywhere in the text -> null.
     *   - two or more DIFFERENT counts found (e.g. "2 persons normally, 3
     *     for the 98 inch") -> null, even though one of them looks like a
     *     confident match — a genuinely conflicting statement is exactly
     *     the case a conservative parser must decline to resolve.
     *
     * Implementation: normalises the recognised phrasings to bare digits,
     * masks out inch/size phrases (including "NN to MM inches" ranges, so a
     * display's diagonal is never mistaken for a team-size count — this is
     * what keeps every sentence `DisplayLiftPolicy::forSize()` emits,
     * including "...55 to 90 inches..." and "...above 90 inches...",
     * round-tripping to exactly one number), then requires EXACTLY one
     * distinct number to remain in what is left.
     */
    private static function parseStatedTeamSize(string $text): ?int
    {
        $normalised = strtolower($text);

        // "single person"/"single-person"/"single hand"/"single-hand" -> 1.
        $normalised = preg_replace('/\bsingle[\s-]+(?:person|hand)\b/u', '1 person', $normalised)
            ?? $normalised;

        // Number-words one-four, ONLY when directly adjacent to a
        // person/operative keyword — an unrelated "two" elsewhere in the
        // text (there is none in this app's vocabulary today, but the rule
        // is deliberately conservative) is never treated as a team-size
        // mention.
        $wordMap = ['one' => '1', 'two' => '2', 'three' => '3', 'four' => '4'];
        $normalised = preg_replace_callback(
            '/\b(one|two|three|four)\b(?=[\s-]*(?:persons?|operatives?)\b)/u',
            static fn (array $m): string => $wordMap[$m[1]],
            $normalised,
        ) ?? $normalised;

        // Mask out inch/size phrases — including "NN to MM inches" ranges —
        // so a display's stated diagonal is never mistaken for a team-size
        // count. Deliberately broader than self::INCH_REGEX (adds the
        // plural "inches" and an optional leading "NN to "/"NN-" range
        // prefix); this masking pattern is an internal detail of THIS
        // parser only — parseStatedInches() below reuses self::INCH_REGEX
        // verbatim, unrelated to this mask.
        $masked = preg_replace(
            '/(?:\d+(?:\.\d+)?\s*(?:to|-)\s*)?\d+(?:\.\d+)?\s*(?:″|"|\\\\"|\xE2\x80\xB3|inch(?:es)?|in\b|-inch)/u',
            ' ',
            $normalised,
        ) ?? $normalised;

        if (! preg_match_all('/\d+(?:\.\d+)?/u', $masked, $matches) || empty($matches[0])) {
            return null; // no recognisable count present
        }

        $distinct = array_unique(array_map(
            static fn (string $n): int => (int) round((float) $n),
            $matches[0],
        ));

        if (count($distinct) !== 1) {
            return null; // ambiguous — two or more different counts stated
        }

        return (int) reset($distinct);
    }

    /**
     * Plan 27-06 Task 1 — reuses self::INCH_REGEX (suggestHandlingMethod()'s
     * existing inch-extraction pattern) VERBATIM, applied to `$text` only.
     * The gate's caller (Task 2) applies this to the row's `item` field
     * first, then its `handling_method` field, using the first match found.
     * No match returns null (D-05's silent-fallback precedent, extended to
     * engineer rows — an unresolvable size is never a gate error on its
     * own, per {@see \App\Services\Rams\DisplayLiftPolicy::violatesPolicy()}).
     */
    private static function parseStatedInches(string $text): ?float
    {
        if (preg_match(self::INCH_REGEX, strtolower($text), $m)) {
            return (float) $m[1];
        }

        return null;
    }

    /**
     * Detect heavy/bulky equipment from all available data sources and set
     * a deterministic material_handling_derived key so the PDF template can
     * render accurate text instead of the contradictory "no heavy items" fallback.
     */
    private static function deriveMaterialHandling(array $data): array
    {
        // Keywords indicating heavy/bulky items
        $heavyKeywords = [
            'display', 'screen', 'monitor', 'tv', 'television',
            'projector', 'rack', 'amplifier', 'amp', 'speaker',
            'wall mount', 'ceiling mount', 'bracket',
            'dsp', 'switcher', 'matrix', 'codec',
        ];

        $detectedItems = [];

        // Categories that never represent a physical item to lift / move.
        // Warranty upgrades, service contracts, options, and customer-supplied
        // lines pollute the table when they happen to contain a hardware
        // keyword (e.g. "Sony 98″ display — 2-year warranty upgrade").
        $nonPhysicalCategories = [
            'warranty', 'option', 'options', 'service', 'services',
            'service_contract', 'service_contracts', 'customer_supplied',
            'carriage', 'delivery', 'training', 'project_management', 'pm',
            'consumables', 'rams', 'method_statement', 'travel',
        ];

        // Phrase markers that always indicate a non-physical line even when
        // the category field is missing (older records with no classifier).
        $nonPhysicalPhrases = [
            'warranty upgrade', 'warranty extension', 'extended warranty',
            'service contract', 'support contract', 'maintenance contract',
            'project management', 'commissioning', 'programming day',
            'delivery & carriage', 'carriage', 'delivery only',
            'training day', 'on-site training',
        ];

        $isNonPhysical = function (?string $category, string $description) use ($nonPhysicalCategories, $nonPhysicalPhrases): bool {
            $cat = strtolower(trim((string) $category));
            if ($cat !== '' && in_array(str_replace(['-', ' '], '_', $cat), $nonPhysicalCategories, true)) {
                return true;
            }
            $desc = strtolower($description);
            foreach ($nonPhysicalPhrases as $phrase) {
                if (str_contains($desc, $phrase)) {
                    return true;
                }
            }
            return false;
        };

        // Scan quote line items
        foreach ((array) ($data['quote']['line_items'] ?? []) as $item) {
            $desc = strtolower(trim((string) ($item['description'] ?? '')));
            $qty  = (int) ($item['qty'] ?? 1);
            if ($isNonPhysical($item['category'] ?? null, $desc)) {
                continue;
            }
            foreach ($heavyKeywords as $kw) {
                if (str_contains($desc, $kw)) {
                    $resolved = self::suggestHandlingMethod((string) ($item['description'] ?? ''), $qty);
                    if ($resolved === null) break;     // sub-kg control panel, skip
                    $detectedItems[] = [
                        'item'            => $item['description'] ?? '',
                        'qty'             => $qty,
                        'handling_method' => $resolved['sentence'],
                        'min_persons'     => $resolved['min_persons'],
                        'inches'          => $resolved['inches'],
                    ];
                    break;
                }
            }
        }

        // Scan scope items (new_install)
        foreach ((array) ($data['scope_items']['new_install'] ?? []) as $item) {
            $name = strtolower(trim((string) ($item['item_name'] ?? '')));
            if ($isNonPhysical($item['category'] ?? null, $name)) {
                continue;
            }
            foreach ($heavyKeywords as $kw) {
                if (str_contains($name, $kw)) {
                    $resolved = self::suggestHandlingMethod((string) ($item['item_name'] ?? ''), (int) ($item['qty'] ?? 1));
                    if ($resolved === null) break;
                    $detectedItems[] = [
                        'item'            => $item['item_name'] ?? '',
                        'qty'             => (int) ($item['qty'] ?? 1),
                        'handling_method' => $resolved['sentence'],
                        'min_persons'     => $resolved['min_persons'],
                        'inches'          => $resolved['inches'],
                    ];
                    break;
                }
            }
        }

        // Scan scope items (decommission) — Phase 27 Plan 02 (RULE-03): this
        // bucket was never scanned before, so a display being stripped out
        // produced zero §6.7 rows regardless of RULE-03. Display items found
        // here additionally get the wall-mount-removal statement appended,
        // since that sequence is the highest-risk lift on a strip-out
        // (house-rules.md:13-16) — not just a generic team-lift line.
        // Non-display decommission items (e.g. a rack being stripped out)
        // are scanned the same way as before, with no statement appended —
        // RULE-03 is display-specific.
        $equipmentClassifier = new \App\Services\EquipmentClassifierService();
        foreach ((array) ($data['scope_items']['decommission'] ?? []) as $item) {
            $name = strtolower(trim((string) ($item['item_name'] ?? '')));
            if ($isNonPhysical($item['category'] ?? null, $name)) {
                continue;
            }
            foreach ($heavyKeywords as $kw) {
                if (str_contains($name, $kw)) {
                    $resolved = self::suggestHandlingMethod((string) ($item['item_name'] ?? ''), (int) ($item['qty'] ?? 1));
                    if ($resolved === null) break;

                    $sentence = $resolved['sentence'];
                    if ($equipmentClassifier->textIndicatesDisplay((string) ($item['item_name'] ?? ''))) {
                        $sentence .= ' ' . DisplayLiftPolicy::wallMountRemovalStatement();
                    }

                    $detectedItems[] = [
                        'item'            => ($item['item_name'] ?? '') . ' (decommission)',
                        'qty'             => (int) ($item['qty'] ?? 1),
                        'handling_method' => $sentence,
                        'min_persons'     => $resolved['min_persons'],
                        'inches'          => $resolved['inches'],
                        'phase'           => 'decommission',
                    ];
                    break;
                }
            }
        }

        // Scan ProjectContext rooms equipment
        foreach ((array) ($data['rooms'] ?? []) as $room) {
            foreach ((array) ($room['equipment'] ?? []) as $eq) {
                $type = strtolower(trim((string) ($eq['type'] ?? '')));
                if (in_array($type, ['display', 'projector', 'speaker', 'dsp', 'switcher'], true)) {
                    $resolved = self::suggestHandlingMethod($type);
                    if ($resolved === null) continue;
                    $detectedItems[] = [
                        'item'            => ucfirst($type) . ' (' . ($room['name'] ?? 'Room') . ')',
                        'qty'             => 1,
                        'handling_method' => $resolved['sentence'],
                        'min_persons'     => $resolved['min_persons'],
                        'inches'          => $resolved['inches'],
                    ];
                }
            }
        }

        // Deduplicate by item name
        $seen = [];
        $unique = [];
        foreach ($detectedItems as $di) {
            $key = strtolower(trim($di['item']));
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $di;
            }
        }

        $hasHeavy = ! empty($unique);

        $data['material_handling_derived'] = [
            'has_heavy_items' => $hasHeavy,
            'items'           => $unique,
            'statement'       => $hasHeavy
                ? 'This installation includes heavy or bulky AV equipment requiring manual handling controls. '
                  . 'Team lifts (minimum 2 persons) are required for items over 20 kg. '
                  . 'Mechanical aids (trolley, lifter) must be used where available. '
                  . 'Correct lifting technique must be adopted at all times.'
                : 'No significant heavy or bulky items have been identified for this installation. '
                  . 'Standard manual handling precautions apply to all works.',
        ];

        return $data;
    }

    /**
     * Suggest a handling method based on the item's full description.
     *
     * Keyword-only matching produced "98″ display" and "10.1″ room scheduling
     * touch screen" with the same 2-person team lift instruction — wrong on
     * both ends. The description gives us the inch size and the device class
     * (display vs control panel vs speaker), so we can size the team and
     * select the right control.
     *
     * Phase 27 Plan 02 (RULE-02, RULE-12): the display/tv/screen band no
     * longer hardcodes its own team-size ladder — it delegates to
     * DisplayLiftPolicy::forSize(), the single shared source D-03 requires.
     * The mount/bracket branch is now checked BEFORE the display branch (was
     * after — RULE-12's root cause): a description containing both "mount"
     * and "display" (e.g. "double-arm wall mount for 65 inch display") must
     * resolve as a mount, never inherit the display band's text.
     *
     * Returns null when the description is a small control panel / scheduler,
     * or a small panel mount, that does not warrant a manual-handling row at
     * all; the caller treats null as "skip this row". Every other case
     * returns ['sentence' => string, 'min_persons' => ?int, 'inches' => ?float]
     * — min_persons/inches are non-null only for the display band (the only
     * branch DisplayLiftPolicy governs); every other branch (mount/bracket,
     * projector, rack, amp/dsp, speaker, catch-all) reports null/null since
     * D-01's bands do not apply to non-display items (house-rules.md:18-19).
     */
    private static function suggestHandlingMethod(string $description, int $qty = 1): ?array
    {
        $desc = strtolower($description);

        // Extract inch size — "98″", "98\"", "98 inch", "98-inch", "10.1″".
        // Returns float (10.1) or null when no inch number found.
        $inches = null;
        if (preg_match(self::INCH_REGEX, $desc, $m)) {
            $inches = (float) $m[1];
        }

        // Small touch / scheduling / control panels are NOT a manual-handling
        // concern — they are sub-2 kg single-hand items. Skip them entirely
        // even though the description contains "screen".
        $isSmallPanel = $inches !== null && $inches <= 14
            && (str_contains($desc, 'scheduling') || str_contains($desc, 'touch panel')
                || str_contains($desc, 'booking panel') || str_contains($desc, 'control panel'));
        if ($isSmallPanel) {
            return null;
        }

        // Wall mounts / brackets — checked BEFORE the display/tv/screen band
        // (RULE-12 fix, moved from below unmodified). Only the heavy XL
        // display brackets warrant a team lift; small panel mounts (e.g.
        // multisurface kit for a 10.1″) are sub-1 kg and need no special
        // handling row. Non-display items are NOT governed by D-01's bands
        // (house-rules.md:18-19: "wall mounts and rack rails are usually
        // two-person, small brackets and video bar mounts single-person").
        if (str_contains($desc, 'mount') || str_contains($desc, 'bracket')) {
            if (str_contains($desc, 'multisurface') || str_contains($desc, 'small panel')
                || (str_contains($desc, 'mount') && str_contains($desc, '10.1'))) {
                return null;  // sub-1 kg, single hand
            }
            if (str_contains($desc, 'x-large') || str_contains($desc, 'xl ') || str_contains($desc, 'fusion')
                || str_contains($desc, 'large')) {
                return [
                    'sentence'    => 'Team lift (2 persons minimum) — heavy display bracket. Pre-stage at install location to avoid double handling.',
                    'min_persons' => null,
                    'inches'      => null,
                ];
            }

            return [
                'sentence'    => 'Single person lift for tilting/fixed wall mount. Check weight before lifting.',
                'min_persons' => null,
                'inches'      => null,
            ];
        }

        // Displays / TVs / large screens — team size resolved through the
        // single shared DisplayLiftPolicy band table (RULE-02). $isSmallPanel
        // is always false by the time execution reaches here (handled by the
        // early return above), so DisplayLiftPolicy is never asked to
        // resolve a scheduling/touch panel.
        if (str_contains($desc, 'display') || str_contains($desc, ' tv ') || str_contains($desc, 'television')
            || (str_contains($desc, 'screen') && $inches !== null && $inches >= 32)) {
            $band = DisplayLiftPolicy::forSize($inches, $isSmallPanel);
            if ($band === null) {
                return null;
            }

            return [
                'sentence'    => $band['sentence'],
                'min_persons' => $band['min_persons'],
                'inches'      => $inches,
            ];
        }

        // Projectors and ceiling-mounted gear.
        if (str_contains($desc, 'projector')) {
            return [
                'sentence'    => 'Team lift for ceiling installation. Secure to access equipment (podium / tower) before releasing.',
                'min_persons' => null,
                'inches'      => null,
            ];
        }

        // Rack / cabinet hardware.
        if (str_contains($desc, 'rack')) {
            return [
                'sentence'    => 'Use equipment trolley for transport. Team lift for rack positioning. Secure to floor or wall before loading equipment.',
                'min_persons' => null,
                'inches'      => null,
            ];
        }

        // Audio amps and DSPs — typically 5–15 kg, single person.
        if (str_contains($desc, 'amplifier') || str_contains($desc, ' amp ') || str_contains($desc, 'dsp')) {
            return [
                'sentence'    => 'Single person lift acceptable if under 20 kg. Check weight before lifting.',
                'min_persons' => null,
                'inches'      => null,
            ];
        }

        // Ceiling speakers — light per unit, but ceiling install needs access
        // equipment, not a team lift.
        if (str_contains($desc, 'ceiling') && str_contains($desc, 'speaker')) {
            return [
                'sentence'    => 'Single-hand lift per unit. Use podium / tower for ceiling installation; do not lift from a step ladder above shoulder height.',
                'min_persons' => null,
                'inches'      => null,
            ];
        }

        if (str_contains($desc, 'speaker')) {
            return [
                'sentence'    => $qty > 2
                    ? 'Multiple units — stage near install positions. Team lift only when fitting at high level.'
                    : 'Single person lift for wall/shelf mount. Team lift only for ceiling-mounted installs.',
                'min_persons' => null,
                'inches'      => null,
            ];
        }

        return [
            'sentence'    => 'Assess weight before lifting. Team lift for items over 20 kg.',
            'min_persons' => null,
            'inches'      => null,
        ];
    }

    // =========================================================================
    // 13. TEXT HYGIENE — deterministic cleanup of known artifacts
    // =========================================================================

    /**
     * Clean known typos, whitespace artifacts, and orphan fragments from
     * text fields in the generated data. Never invents content — only fixes
     * known patterns that reduce document quality.
     */
    private static function cleanTextArtifacts(array $data): array
    {
        // Common typo corrections (case-insensitive)
        $typoMap = [
            'exisiting'   => 'existing',
            'reoved'      => 'removed',
            'handhelp'    => 'handheld',
            'equipemnt'   => 'equipment',
            'installaton' => 'installation',
            'commissoning' => 'commissioning',
            'maintanance' => 'maintenance',
            'recieve'     => 'receive',
            'reciever'    => 'receiver',
            'seperately'  => 'separately',
            'occured'     => 'occurred',
            'neccessary'  => 'necessary',
            'acomodation' => 'accommodation',
            'whioch'      => 'which',
            'Assitive'    => 'Assistive',
        ];

        // Clean scope_of_works text
        if (! empty($data['scope_of_works']) && is_string($data['scope_of_works'])) {
            $data['scope_of_works'] = self::applyTypoFixes($data['scope_of_works'], $typoMap);
        }

        // Clean scope bullets
        if (! empty($data['scope_of_works_bullets']) && is_array($data['scope_of_works_bullets'])) {
            $data['scope_of_works_bullets'] = array_values(array_filter(
                array_map(fn ($b) => self::applyTypoFixes(trim((string) $b), $typoMap), $data['scope_of_works_bullets']),
                fn ($b) => strlen($b) > 5 // Remove orphan fragments
            ));
        }

        // Clean method statement phase titles and steps
        if (! empty($data['method_statement']['phases'])) {
            foreach ($data['method_statement']['phases'] as &$phase) {
                if (isset($phase['title'])) {
                    $phase['title'] = self::applyTypoFixes($phase['title'], $typoMap);
                }
                if (! empty($phase['steps']) && is_array($phase['steps'])) {
                    $phase['steps'] = array_values(array_filter(
                        array_map(fn ($s) => self::applyTypoFixes(trim((string) $s), $typoMap), $phase['steps']),
                        fn ($s) => strlen($s) > 5
                    ));
                }
            }
            unset($phase);
        }

        // Clean hazard names and controls
        if (! empty($data['hazards']) && is_array($data['hazards'])) {
            foreach ($data['hazards'] as &$hazard) {
                if (isset($hazard['hazard'])) {
                    $hazard['hazard'] = self::applyTypoFixes($hazard['hazard'], $typoMap);
                }
                if (! empty($hazard['controls']) && is_array($hazard['controls'])) {
                    $hazard['controls'] = array_map(
                        fn ($c) => self::applyTypoFixes((string) $c, $typoMap),
                        $hazard['controls']
                    );
                }
            }
            unset($hazard);
        }

        return $data;
    }

    /**
     * Apply typo corrections and whitespace normalization to a string.
     */
    private static function applyTypoFixes(string $text, array $typoMap): string
    {
        // Fix double spaces / leading/trailing whitespace
        $text = trim(preg_replace('/\s{2,}/', ' ', $text));

        // Apply typo corrections case-insensitively BUT preserve original casing
        // of the matched word — "Exisiting" → "Existing", "EXISITING" → "EXISTING",
        // "exisiting" → "existing". Plain str_ireplace flattened everything to the
        // replacement's lowercase form, losing sentence-start capitalisation.
        foreach ($typoMap as $wrong => $right) {
            $text = preg_replace_callback(
                '/\b' . preg_quote($wrong, '/') . '\b/i',
                static function (array $m) use ($right): string {
                    $matched = $m[0];
                    if (preg_match('/^[A-Z]+$/', $matched)) {
                        return strtoupper($right);          // FULL CAPS
                    }
                    if (preg_match('/^[A-Z]/', $matched)) {
                        return ucfirst($right);             // Title / sentence start
                    }
                    return $right;                          // lowercase
                },
                $text
            );
        }

        // Remove orphan punctuation artifacts
        $text = preg_replace('/^[\s,;:\-–—]+/', '', $text);
        $text = preg_replace('/[\s,;:]+$/', '', $text);

        return trim($text);
    }
}
