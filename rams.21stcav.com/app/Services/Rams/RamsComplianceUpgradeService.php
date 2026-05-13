<?php

namespace App\Services\Rams;

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
    // =========================================================================
    // PUBLIC ENTRY POINT
    // =========================================================================

    public static function upgrade(array $ramsData): array
    {
        $ramsData = self::upgradeScopeOfWorks($ramsData);
        $ramsData = self::ensurePerRoomBullets($ramsData);
        $ramsData = self::addPpeMatrix($ramsData);
        $ramsData = self::addAccessEquipmentDetail($ramsData);
        $ramsData = self::fillMissingHazardControls($ramsData);
        $ramsData = self::addProjectSpecificRisks($ramsData);
        $ramsData = self::addRiskColourKey($ramsData);
        $ramsData = self::addPermitAndIsolation($ramsData);
        $ramsData = self::addFixingsControl($ramsData);
        $ramsData = self::addSupervisionAndQA($ramsData);
        $ramsData = self::deriveMaterialHandling($ramsData);
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

        if ($groundLevel || $noAccessKit) {
            // Keep only ladders + kick stool — strip platform-class items and their requirements.
            $items = array_values(array_filter($items, static fn (string $s): bool
                => stripos($s, 'podium') === false
                    && stripos($s, 'tower') === false
                    && stripos($s, 'MEWP')  === false
                    && stripos($s, 'scissor') === false));
            $requirements = array_values(array_filter($requirements, static fn (string $s): bool
                => stripos($s, 'PASMA') === false
                    && stripos($s, 'IPAF') === false
                    && stripos($s, 'tower') === false
                    && stripos($s, 'MEWP') === false
                    && stripos($s, 'harness') === false));
            $items[]        = 'Working height confirmed at ground/floor level — no platform access equipment required.';
        } elseif ($noPodium) {
            $items = array_values(array_filter($items, static fn (string $s): bool
                => stripos($s, 'podium') === false));
            $items[]        = 'Podium steps excluded — working height does not require a working platform.';
        }

        $data['access_equipment_detail'] = [
            'items'        => $items,
            'requirements' => $requirements,
        ];

        return $data;
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

        $hazardIds = [];
        foreach ((array) ($data['hazards'] ?? []) as $h) {
            $id   = (int) ($h['id'] ?? 0);
            $name = strtolower((string) ($h['hazard'] ?? ''));
            if ($id > 0 && $name !== '') {
                $hazardIds[] = ['id' => $id, 'name' => $name];
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
            $title     = strtolower((string) ($phase['title'] ?? ''));
            $stepsText = strtolower(implode(' ', (array) ($phase['steps'] ?? [])));
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
                    $method = self::suggestHandlingMethod((string) ($item['description'] ?? ''), $qty);
                    if ($method === null) break;     // sub-kg control panel, skip
                    $detectedItems[] = [
                        'item'            => $item['description'] ?? '',
                        'qty'             => $qty,
                        'handling_method' => $method,
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
                    $method = self::suggestHandlingMethod((string) ($item['item_name'] ?? ''), (int) ($item['qty'] ?? 1));
                    if ($method === null) break;
                    $detectedItems[] = [
                        'item'            => $item['item_name'] ?? '',
                        'qty'             => (int) ($item['qty'] ?? 1),
                        'handling_method' => $method,
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
                    $method = self::suggestHandlingMethod($type);
                    if ($method === null) continue;
                    $detectedItems[] = [
                        'item'            => ucfirst($type) . ' (' . ($room['name'] ?? 'Room') . ')',
                        'qty'             => 1,
                        'handling_method' => $method,
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
     * Returns null when the description is a small control panel / scheduler
     * that does not warrant a manual-handling row at all; the caller treats
     * null as "skip this row".
     */
    private static function suggestHandlingMethod(string $description, int $qty = 1): ?string
    {
        $desc = strtolower($description);

        // Extract inch size — "98″", "98\"", "98 inch", "98-inch", "10.1″".
        // Returns float (10.1) or null when no inch number found.
        $inches = null;
        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:″|"|\\\\"|\xE2\x80\xB3|inch|in\b|-inch)/u', $desc, $m)) {
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

        // Displays / TVs / large screens — team size scales with inch size.
        if (str_contains($desc, 'display') || str_contains($desc, ' tv ') || str_contains($desc, 'television')
            || (str_contains($desc, 'screen') && $inches !== null && $inches >= 32)) {
            if ($inches !== null && $inches >= 85) {
                return 'Team lift — minimum 4 persons for ' . rtrim(rtrim((string) $inches, '0'), '.') . '″ size class. '
                     . 'Use lifting handles or strap kit. Two persons take the front, two the rear; do not pivot on edges. '
                     . 'Use screen protection during transit. Do not lay face-down.';
            }
            if ($inches !== null && $inches >= 65) {
                return 'Team lift — minimum 3 persons recommended for ' . rtrim(rtrim((string) $inches, '0'), '.') . '″. '
                     . 'Two persons may lift if using a panel-lift trolley. Use screen protection during transit. Do not lay face-down.';
            }
            return 'Team lift (2 persons minimum). Use screen protection during transit. Do not lay face-down.';
        }

        // Wall mounts / brackets — only the heavy XL display brackets warrant
        // a team lift; small panel mounts (e.g. multisurface kit for a 10.1″)
        // are sub-1 kg and need no special handling row.
        if (str_contains($desc, 'mount') || str_contains($desc, 'bracket')) {
            if (str_contains($desc, 'multisurface') || str_contains($desc, 'small panel')
                || (str_contains($desc, 'mount') && str_contains($desc, '10.1'))) {
                return null;  // sub-1 kg, single hand
            }
            if (str_contains($desc, 'x-large') || str_contains($desc, 'xl ') || str_contains($desc, 'fusion')
                || str_contains($desc, 'large')) {
                return 'Team lift (2 persons minimum) — heavy display bracket. Pre-stage at install location to avoid double handling.';
            }
            return 'Single person lift for tilting/fixed wall mount. Check weight before lifting.';
        }

        // Projectors and ceiling-mounted gear.
        if (str_contains($desc, 'projector')) {
            return 'Team lift for ceiling installation. Secure to access equipment (podium / tower) before releasing.';
        }

        // Rack / cabinet hardware.
        if (str_contains($desc, 'rack')) {
            return 'Use equipment trolley for transport. Team lift for rack positioning. Secure to floor or wall before loading equipment.';
        }

        // Audio amps and DSPs — typically 5–15 kg, single person.
        if (str_contains($desc, 'amplifier') || str_contains($desc, ' amp ') || str_contains($desc, 'dsp')) {
            return 'Single person lift acceptable if under 20 kg. Check weight before lifting.';
        }

        // Ceiling speakers — light per unit, but ceiling install needs access
        // equipment, not a team lift.
        if (str_contains($desc, 'ceiling') && str_contains($desc, 'speaker')) {
            return 'Single-hand lift per unit. Use podium / tower for ceiling installation; do not lift from a step ladder above shoulder height.';
        }

        if (str_contains($desc, 'speaker')) {
            return $qty > 2
                ? 'Multiple units — stage near install positions. Team lift only when fitting at high level.'
                : 'Single person lift for wall/shelf mount. Team lift only for ceiling-mounted installs.';
        }

        return 'Assess weight before lifting. Team lift for items over 20 kg.';
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

        // Apply typo corrections (case-insensitive, preserve original casing where possible)
        foreach ($typoMap as $wrong => $right) {
            $text = str_ireplace($wrong, $right, $text);
        }

        // Remove orphan punctuation artifacts
        $text = preg_replace('/^[\s,;:\-–—]+/', '', $text);
        $text = preg_replace('/[\s,;:]+$/', '', $text);

        return trim($text);
    }
}
