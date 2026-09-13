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
        // Phase 30 (UI-SPEC) — the compliance_warnings advisory channel.
        // Written UNCONDITIONALLY here, before any gate runs, and
        // overwritten WHOLESALE on every call (never appended to) —
        // mirroring the resolveSiteEmergency() precedent below (:83-86,
        // unconditional enrichment sitting beside a flag-gated throw). If
        // this were only written inside a flag-gated block, a flag flip
        // from true back to false would leave a stale persisted warnings
        // array on every document — a disarmed gate still showing
        // findings. GATE-04 (30-06) and GATE-14 (30-08) push their
        // findings onto this array later in the pipeline; until those
        // plans land it is always empty.
        $ramsData['compliance_warnings'] = [];

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
        // GATE-06/GATE-07 — independent re-check of every hazard NAME and
        // every surviving hazard control line for FFP2/confined-space
        // violations, plus a raw FFP2 substring check across the PPE
        // surfaces. Config-gated with its OWN flag (D-08 — never reuses
        // RAMS_DISPLAY_LIFT_GATE) so this milestone's live-validation
        // posture can roll it back with a single .env edit
        // (RAMS_PPE_CEILING_ELECTRICAL_GATE) without touching GATE-09. When
        // the flag is false, enforceFfp2AndConfinedSpaceGate() is never
        // called — upgrade() proceeds byte-identical to pre-GATE-06/07
        // behaviour.
        if (config('rams_tier1.ffp2_confined_space_gate_enabled', true)) {
            $ramsData = self::enforceFfp2AndConfinedSpaceGate($ramsData);
        }
        $ramsData = self::crossReferenceMethodStatementRisks($ramsData);
        // Resolves site_emergency into site_emergency_resolved BEFORE the
        // CDM step — unconditional, regardless of the GATE-11/GATE-12 flag
        // below, so the resolved A&E value is always available to render
        // sites (Plan 29-04).
        $ramsData = self::resolveSiteEmergency($ramsData);
        $ramsData = self::addCdmDutyHolders($ramsData);
        // GATE-11/GATE-12 — independent re-check of the CDM duty-holder
        // placeholder (RULE-07) and the resolved site A&E plausibility
        // (RULE-08/D-08), run back-to-back under ONE config check, mirroring
        // GATE-06/GATE-07's shared-flag shape. Config-gated with its OWN
        // flag (D-03 — never reuses RAMS_DISPLAY_LIFT_GATE or
        // RAMS_PPE_CEILING_ELECTRICAL_GATE) so this milestone's
        // live-validation posture can roll it back with a single .env edit
        // (RAMS_CDM_AE_GATE) without touching the other gates. Ships
        // DISARMED (defaults false) per D-03 — armed only after the
        // Plan 29-05 backfill and a live regeneration verify clean. When the
        // flag is false, neither method is ever called — upgrade() proceeds
        // byte-identical to pre-GATE-11/GATE-12 behaviour.
        if (config('rams_tier1.cdm_ae_gate_enabled', false)) {
            $ramsData = self::enforceCdmGate($ramsData);
            $ramsData = self::enforceEmergencyGate($ramsData);
        }
        // GATE-01/GATE-02(/GATE-04, Plan 30-06) — independent re-checks of
        // orphan controls (a method step / hazard control referencing a
        // document, permit or hold point with no supporting hazard row and
        // no supporting client-responsibility entry) and area/method-step
        // coverage. Dispatched under ONE flag (D-04 — see
        // config/rams_tier1.php:138-167 for the full rationale): all three
        // structural gates share the same false-positive failure mode, so
        // they can only ever be usefully rolled back together. A NEW,
        // INDEPENDENT flag — never reuses RAMS_DISPLAY_LIFT_GATE,
        // RAMS_PPE_CEILING_ELECTRICAL_GATE or RAMS_CDM_AE_GATE, so a
        // rollback of one gate generation can never accidentally disarm
        // another's. Ships DISARMED (defaults false) per D-03 — Phase 30's
        // corpus has not been measured clean, unlike GATE-06/07/09. When
        // false, none of the gated methods is ever called — upgrade()
        // proceeds byte-identical to pre-Phase-30 behaviour, no redeploy
        // required. GATE-04's call joined this same block in Plan 30-06.
        if (config('rams_tier1.structural_gates_enabled', false)) {
            $ramsData = self::enforceOrphanControlGate($ramsData);
            $ramsData = self::enforceAreaCoverageGate($ramsData);
            $ramsData = self::enforceResidualScoreGate($ramsData);
        }
        // GATE-13 — independent re-check of the hot-works contradiction
        // (RA18-shaped "no hot works" assertion vs. an unconditional
        // hot-works permit requirement OR solder/flux listed in COSHH).
        // Its OWN flag (D-04) — never reuses RAMS_STRUCTURAL_GATES,
        // RAMS_MISSING_RISK_REF_GATE, or any previously-shipped gate flag —
        // because GATE-13 flips a full PHASE later than the rest (D-02):
        // both halves would false-positive corpus-wide today
        // (config/rams_tier1.php:198-231, RESEARCH.md Finding 5). Ships
        // DISARMED (defaults false); Phase 31 flips RAMS_HOT_WORKS_GATE
        // once RULE-05/GATE-10 make coshh_baseline job-conditional. When
        // false, enforceHotWorksGate() is never called — upgrade()
        // proceeds byte-identical to pre-GATE-13 behaviour, no redeploy
        // required.
        if (config('rams_tier1.hot_works_gate_enabled', false)) {
            $ramsData = self::enforceHotWorksGate($ramsData);
        }
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
                'ppe'  => ['Safety glasses', 'Latex / nitrile gloves', 'Dust mask (FFP3)'],
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
                'ppe'  => ['Hard hat', 'Dust mask (FFP3)', 'Safety glasses', 'Gloves'],
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
                'Assess each load before lifting — its weight, dimensions, shape and carry route decide whether it is a team lift',
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
                    'Team lift where the weight, dimensions, shape or route of the load requires it; mechanical aids used in addition where available',
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
                    'Dust mask (FFP3) worn when accessing ceiling voids. All operatives face-fit tested.',
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
                    'FFP3 dust mask and safety glasses worn during all drilling and cutting. All operatives face-fit tested.',
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
    // 11. CDM 2015 DUTY HOLDERS + SITE EMERGENCY (GATE-11/GATE-12)
    // =========================================================================

    /**
     * Phase 29 Plan 03 (RULE-07) — the restated Principal Designer note.
     * Never the bare `'[To be confirmed]'` placeholder GATE-11 exists to
     * catch. Shared between {@see self::addCdmDutyHolders()} and
     * {@see \App\Services\DocxBuilderService::buildCdmSection()}'s
     * defence-in-depth fallback so both call sites read one literal.
     */
    public const DEFAULT_PRINCIPAL_DESIGNER_NOTE = 'Not formally appointed at this stage — the client will confirm '
        . 'Principal Designer arrangements before works commence if the wider project requires one '
        . '(CDM 2015 Regulation 5).';

    /**
     * Phase 29 Plan 03 (RULE-07) — the restated Principal Contractor note.
     * Conditional wording per `standards-and-legislation.md:32-34`: never
     * asserts 21CAV unequivocally IS the Principal Contractor, and cites
     * Regulation 15 (contractor duties) rather than Regulations 4/5.
     */
    public const DEFAULT_PRINCIPAL_CONTRACTOR_NOTE = 'If the client appoints a Principal Contractor, 21CAV works to '
        . 'their Construction Phase Plan and site arrangements. If 21CAV is confirmed as sole contractor, 21CAV '
        . 'prepares and implements the Construction Phase Plan under CDM 2015 Regulation 15.';

    /**
     * Phase 29 Plan 10 (RULE-07) — verbatim from
     * `standards-and-legislation.md:23-28`, the anticipated-sole-contractor
     * sentence. NEVER an unequivocal assertion that 21CAV IS the sole
     * contractor. Shared between {@see self::addCdmDutyHolders()} and the
     * render-site fallbacks added in Plan 29-11/29-12, plus the
     * `2026_09_12_120000_backfill_cdm_contractor_note` migration, so all
     * three read one literal instead of duplicating it.
     */
    public const DEFAULT_CONTRACTOR_NOTE = '21CAV is currently anticipated to be the sole contractor for the AV '
        . 'installation scope. The client shall confirm whether the overall project involves, or is '
        . 'likely to involve, more than one contractor before works commence.';

    /**
     * Restates the CDM 2015 duty-holder table per RULE-07
     * (`standards-and-legislation.md:17-41`), applied UNCONDITIONALLY on
     * every job. RESEARCH.md Finding 6 / Assumption A2: no deterministic
     * "occupied premises" signal exists anywhere in this codebase to gate
     * this wording on, so the restated position ships on every RAMS rather
     * than being invented behind a signal that does not exist — stated here
     * explicitly rather than buried, per Assumption A2's own instruction.
     *
     * `principal_designer`/`principal_contractor` never emit the bare
     * `'[To be confirmed]'` placeholder GATE-11 exists to catch — see the
     * two class constants above. `project_manager`/`site_supervisor` keep
     * their existing `trim(...) ?: '[To be confirmed]'` fallback
     * unchanged: that placeholder reflects genuinely unknown project data,
     * not the RULE-07 settled position GATE-11 targets.
     */
    private static function addCdmDutyHolders(array $data): array
    {
        $project = (array) ($data['project'] ?? []);

        $data['cdm_duty_holders'] = [
            'client'               => trim((string) ($project['client'] ?? '')) ?: '[Client Name]',
            'principal_designer'   => self::DEFAULT_PRINCIPAL_DESIGNER_NOTE,
            'principal_contractor' => self::DEFAULT_PRINCIPAL_CONTRACTOR_NOTE,
            'contractor'           => '21st Century AV Ltd',
            'subcontractor'        => '21st Century AV Ltd',
            'project_manager'      => trim((string) ($project['project_manager'] ?? '')) ?: '[To be confirmed]',
            'site_supervisor'      => trim((string) ($project['lead_engineer'] ?? '')) ?: '[To be confirmed]',
            'cdm_regulation'       => 'Construction (Design and Management) Regulations 2015',
            'contractor_note'      => self::DEFAULT_CONTRACTOR_NOTE,
            // Never asserts the Principal Contractor must notify HSE — the
            // F10 duty is the Client's (may be submitted on the Client's
            // behalf); notifiability is judged on the whole project, not
            // 21CAV's single-visit scope.
            'notification'         => 'Most single-visit AV installation works fall below the CDM 2015 '
                . 'notifiable-project threshold. Where the wider project is notifiable, F10 notification is the '
                . "Client's duty (it may be submitted on the Client's behalf) — notifiability is judged on the "
                . "whole project, not 21CAV's scope alone.",
        ];

        return $data;
    }

    /**
     * GATE-11 (RULE-07) — independent re-check that the bare
     * `'[To be confirmed]'` placeholder never survives
     * {@see self::addCdmDutyHolders()}. Deliberately scoped to
     * `principal_designer`/`principal_contractor` only —
     * `project_manager`/`site_supervisor` legitimately carry a
     * data-dependent placeholder and are out of GATE-11's scope (RESEARCH.md
     * Integration Points). Mirrors GATE-06/GATE-07's throw-on-first-
     * violation shape exactly.
     */
    private static function enforceCdmGate(array $data): array
    {
        $cdm = (array) ($data['cdm_duty_holders'] ?? []);

        foreach (['principal_designer', 'principal_contractor'] as $field) {
            if (($cdm[$field] ?? null) === '[To be confirmed]') {
                throw new RamsGenerationException(sprintf(
                    'CDM duty-holder field "%s" is still the raw "[To be confirmed]" placeholder '
                    . '(GATE-11/RULE-07). This should never happen — addCdmDutyHolders() no longer emits this '
                    . 'value. Investigate before regenerating, or set RAMS_CDM_AE_GATE=false to disable this '
                    . 'check.',
                    $field,
                ));
            }
        }

        return $data;
    }

    /**
     * GATE-12 (RULE-08/D-08) — independent re-check of the resolved site
     * A&E value via {@see SiteEmergencyResolver::classify()}, the single
     * source of truth for this decision (never re-derived here). Throws on
     * a named-but-implausible A&E; the D-05 hold-point line always passes
     * clean (conservative-by-construction, inherited Phase 28 D-01).
     */
    private static function enforceEmergencyGate(array $data): array
    {
        $siteEmergency = (array) ($data['site_emergency'] ?? []);
        $reason = SiteEmergencyResolver::classify($siteEmergency);

        if ($reason !== null) {
            throw new RamsGenerationException(sprintf(
                'Site emergency A&E arrangement failed plausibility classification: "%s" (GATE-12/RULE-08). '
                . 'Correct the nearest-hospital name/address before regenerating, or set '
                . 'RAMS_CDM_AE_GATE=false to disable this check.',
                $reason,
            ));
        }

        return $data;
    }

    /**
     * Resolves `site_emergency` into the D-05 two-branch value via
     * {@see SiteEmergencyResolver::resolve()} and writes it to
     * `site_emergency_resolved` (`verified`/`text` keys) — the value all
     * five legacy render sites (Plan 29-04) read. Runs unconditionally,
     * regardless of the GATE-11/GATE-12 flag, so the resolved value is
     * always available.
     */
    private static function resolveSiteEmergency(array $data): array
    {
        $siteEmergency = (array) ($data['site_emergency'] ?? []);
        $data['site_emergency_resolved'] = SiteEmergencyResolver::resolve($siteEmergency);

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
     * GATE-06 / GATE-07 — independent re-check of every hazard NAME and
     * every surviving hazard control line for FFP2 / confined-space
     * violations, run immediately after {@see self::enforceDisplayLiftGate()}
     * in `upgrade()`'s pipeline (config-gated by the caller). Completes
     * D-03's "auto-correct-then-throw" pair for RULE-01/RULE-06: Plan 28-01's
     * tier-1 auto-correction inside `RamsBuilderService::reviewedToRisk()`
     * fixes what it can reach; this gate catches everything that survives
     * into a fully-assembled `$data` array regardless of which generation
     * entry point produced it — most importantly the Save Review path
     * (`RamsController::updateAndDownload()`), which never calls
     * `reviewedToRisk()` at all.
     *
     * **This method NEVER re-implements FFP2/confined-space classification.**
     * It calls {@see ControlTextRuleViolations::detect()} /
     * {@see ControlTextRuleViolations::detectAll()} — the single choke
     * point — on whatever text survives into `$data`, exactly as
     * {@see self::enforceDisplayLiftGate()} calls
     * `DisplayLiftPolicy::violatesPolicy()` rather than re-encoding its
     * bands. Duplicating a detector's logic here would let this gate and
     * `ControlTextRuleViolations` silently diverge.
     *
     * **The hazard-NAME check is unconditional per hazard and is NOT nested
     * inside any template-resolution branch** (Revision 1, plan-checker
     * Blocker 1). An unresolved hazard name is precisely the case that
     * reaches generation uncorrected: `RamsBuilderService::reviewedToRisk()`'s
     * `if ($tpl !== null && ($tpl->id ?? null) !== null)` block — and
     * therefore its tier-1 `detectAll()` call — is skipped entirely for an
     * unmatched name, and `LegacyHazardNameFoldMap` only folds the exact
     * plural string `'confined spaces'`. A hazard named "Confined Space"
     * (singular), "Confined Spaces Entry", etc. resolves to nothing upstream
     * and this gate is the ONLY mechanism that ever sees it.
     *
     * **The gate ERRORS on a mislabelled name; it does NOT silently rename
     * it.** An unrecognised hazard name is the "cannot confidently classify,
     * do not guess" case (ROADMAP criterion 4 specifies erroring, not
     * auto-correction) — renaming an engineer's hazard row silently would be
     * a larger, unrequested action than replacing a control line.
     *
     * Scans three surfaces, not just hazard controls (required for GATE-06's
     * literal wording — "errors on any FFP2 occurrence" — to be true rather
     * than true-only-for-the-hazard-controls-subset):
     *   1. `$data['hazards'][*]['hazard']` — the hazard's own NAME, via
     *      `ControlTextRuleViolations::detect()` (confined_space only, in
     *      practice, since `detectFfp2()` matches a bare token names rarely
     *      carry — but the check is not restricted to one key).
     *   2. `$data['hazards'][*]['controls']` — via
     *      `ControlTextRuleViolations::detectAll()` (both `ffp2` and
     *      `confined_space` keys).
     *   3. `$data['ppe']` and `$data['ppe_matrix'][*]['ppe']` — a flat,
     *      case-insensitive substring check for the literal token `FFP2`.
     *      NOT routed through `ControlTextRuleViolations`: PPE is a closed
     *      vocabulary (a fixed pick-list), not free text needing
     *      classification, per Plan 28-03's own reasoning.
     *
     * Throws {@see RamsGenerationException} on the FIRST violation found,
     * naming which rule fired, the offending text, and the exact env flag
     * (`RAMS_PPE_CEILING_ELECTRICAL_GATE=false`) to disable the check.
     */
    private static function enforceFfp2AndConfinedSpaceGate(array $data): array
    {
        $hazards = (array) ($data['hazards'] ?? []);

        foreach ($hazards as $hazard) {
            $hazard = (array) $hazard;
            $name = (string) ($hazard['hazard'] ?? '');

            // Unconditional per hazard — NOT nested inside any
            // template-resolution branch. See method docblock, Revision 1
            // Blocker 1. ControlTextRuleViolations::DETECTORS also carries
            // 'kg_threshold' and 'size_conditional_lift' (Phase 27, RULE-13)
            // — this gate is GATE-06/GATE-07 only, so only the
            // confined_space key (the only one a bare hazard-name label can
            // plausibly trip) is in scope here; any other key is silently
            // ignored, exactly as `fillMissingHazardControls()` and other
            // Phase 28 surfaces leave RULE-13 entirely to its own mechanism.
            $nameViolation = ControlTextRuleViolations::detect($name);

            if ($nameViolation === 'confined_space') {
                throw new RamsGenerationException(sprintf(
                    'Hazard name "%s" is classified as a confined-space house-rule violation (GATE-07/RULE-06). '
                    . 'Rename this hazard before regenerating, or set '
                    . 'RAMS_PPE_CEILING_ELECTRICAL_GATE=false to disable this check.',
                    $name,
                ));
            }

            $controls = array_map('strval', (array) ($hazard['controls'] ?? []));
            $controlViolations = ControlTextRuleViolations::detectAll($controls);

            foreach ($controlViolations as $index => $violationKey) {
                // GATE-06/GATE-07 in scope ONLY — 'kg_threshold' and
                // 'size_conditional_lift' (Phase 27, RULE-13) are also
                // registered on the SAME DETECTORS choke point and can
                // legitimately fire on ordinary manual-handling control
                // text (e.g. "Team lift for items over 20 kg"). RULE-13 has
                // its own dedicated mechanism (tier-1 auto-correction only,
                // no throwing gate) — this gate must not throw on it.
                if ($violationKey !== 'ffp2' && $violationKey !== 'confined_space') {
                    continue;
                }

                throw new RamsGenerationException(sprintf(
                    'Control line "%s" on hazard "%s" is classified as a %s (%s) house-rule violation. '
                    . 'Correct the control text before regenerating, or set '
                    . 'RAMS_PPE_CEILING_ELECTRICAL_GATE=false to disable this check.',
                    $controls[$index] ?? '',
                    $name,
                    self::ffp2ConfinedSpaceRuleLabel($violationKey),
                    self::ffp2ConfinedSpaceGateLabel($violationKey),
                ));
            }
        }

        foreach ((array) ($data['ppe'] ?? []) as $entry) {
            if (stripos((string) $entry, 'FFP2') !== false) {
                throw new RamsGenerationException(sprintf(
                    'PPE entry "%s" contains the banned token FFP2 (GATE-06/RULE-01). Replace it with the '
                    . 'FFP3 equivalent before regenerating, or set RAMS_PPE_CEILING_ELECTRICAL_GATE=false '
                    . 'to disable this check.',
                    (string) $entry,
                ));
            }
        }

        foreach ((array) ($data['ppe_matrix'] ?? []) as $row) {
            foreach ((array) ($row['ppe'] ?? []) as $entry) {
                if (stripos((string) $entry, 'FFP2') !== false) {
                    throw new RamsGenerationException(sprintf(
                        'PPE matrix entry "%s" contains the banned token FFP2 (GATE-06/RULE-01). Replace it '
                        . 'with the FFP3 equivalent before regenerating, or set '
                        . 'RAMS_PPE_CEILING_ELECTRICAL_GATE=false to disable this check.',
                        (string) $entry,
                    ));
                }
            }
        }

        return $data;
    }

    /** Rule label for GATE-06/07 throw messages — 'ffp2' vs 'confined_space'. */
    private static function ffp2ConfinedSpaceRuleLabel(string $violationKey): string
    {
        return $violationKey === 'ffp2' ? 'FFP2' : 'confined-space';
    }

    /** Gate/rule citation for GATE-06/07 throw messages — 'ffp2' vs 'confined_space'. */
    private static function ffp2ConfinedSpaceGateLabel(string $violationKey): string
    {
        return $violationKey === 'ffp2' ? 'GATE-06/RULE-01' : 'GATE-07/RULE-06';
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
        // Single implementation lives on ControlTextRuleViolations (Plan 27-08
        // Task 1) so the gate and the violation detectors can never disagree
        // about what a control line says. This forwarder keeps existing call
        // sites and the private visibility contract intact.
        return ControlTextRuleViolations::parseStatedTeamSize($text);
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
        // Single implementation lives on ControlTextRuleViolations (Plan 27-08
        // Task 1). This forwarder keeps existing call sites and the private
        // visibility contract intact while guaranteeing exactly one parser.
        return ControlTextRuleViolations::parseStatedInches($text);
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
                  . 'Team lifts are required where the weight, dimensions, shape, route or the task-specific manual handling assessment indicates them. '
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
                'sentence'    => 'Single person lift where the task-specific manual handling assessment allows. Check the weight and carry route before lifting.',
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
            'sentence'    => 'Assess the load before lifting — weight, dimensions, shape and carry route decide the team size.',
            'min_persons' => null,
            'inches'      => null,
        ];
    }

    // =========================================================================
    // 13. STRUCTURAL GATES (Phase 30) — GATE-01/02/04/13/14
    // =========================================================================
    //
    // GATE-04 (enforceResidualScoreGate()) landed in Plan 30-06, below
    // enforceAreaCoverageGate(). Placement note for plans 30-07 (GATE-13)
    // and 30-08 (GATE-14), which add their gate methods and join the
    // dispatch block
    // below: GATE-13 reads permit_and_isolation (written by
    // addPermitAndIsolation() at ~:939) and GATE-14 reads associated_risks
    // (written by crossReferenceMethodStatementRisks() at ~:1001-1092), so
    // all new dispatch blocks belong AFTER the
    // crossReferenceMethodStatementRisks()/resolveSiteEmergency()/
    // addCdmDutyHolders()/GATE-11-12 sequence above (upgrade() ~:78-100)
    // and BEFORE cleanTextArtifacts() below. Each new gate follows the
    // shape established by enforceCdmGate() (:1201+): private static
    // function, reads its input defensively via (array)($data['key'] ??
    // []), returns $data unchanged on the clean path, throws
    // RamsGenerationException naming the offending item and its kill
    // switch on the first violation (S2) — except GATE-14 and GATE-04's
    // warn half, which push onto $ramsData['compliance_warnings']
    // (initialised unconditionally at the top of upgrade()) instead of
    // throwing.

    /**
     * GATE-01 (PORTING-NOTES.md:66-68) — independent re-check that every
     * method step or hazard control line referencing a document, permit or
     * hold point (the `structural_gate_triggers` config vocabulary) has BOTH
     * a supporting hazard row and a supporting client-responsibility entry.
     * The canonical failure is a step reading "review the asbestos register"
     * with no Asbestos-Containing Materials hazard row and no matching
     * client-responsibility entry behind it.
     *
     * D-05: fires when EITHER support is missing, not only when both are —
     * this is the correct reading of the PORTING-NOTES source ("must have a
     * matching hazard row *and* a matching clientReqs entry"; missing either
     * conjunct fails the check). ROADMAP criterion 1 states this backwards;
     * Plan 30-05 corrects the doc, this method implements the correct
     * behaviour. The thrown message always names WHICH support is absent —
     * an engineer who has added the hazard but not the client responsibility
     * must be told that, not told both are missing.
     *
     * This gate is independent of the code that produced the text it
     * checks: it never re-derives a hazard or a client-responsibility entry
     * itself, it only asks whether one already exists that supports a
     * trigger phrase already present in the document, via the single shared
     * {@see StructuralGateVocabulary} matcher (D-07/D-08) — never a second,
     * parallel vocabulary.
     *
     * Conservative by construction (pattern S3): an unknown signal, an
     * absent `method_statement`, an absent `hazards` key, or an absent
     * `client_responsibilities`/`client_responsibilities_expanded` — each is
     * a skip or a "no support found", never a throw of its own and never a
     * false positive from a matching failure. Does not call the Phase 26
     * hazard-library resolver's dynamic resolve-from-database method —
     * that method queries Eloquent and would break this class's
     * "Deterministic. No AI. No database." docblock invariant (:18); all
     * signal matching goes through {@see StructuralGateVocabulary}'s
     * const-map-backed helpers instead (D-07).
     */
    private static function enforceOrphanControlGate(array $data): array
    {
        $triggers = (array) config('rams_tier1.structural_gate_triggers', []);

        if (empty($triggers)) {
            return $data;
        }

        $haystack = self::orphanControlHaystack($data);

        if ($haystack === '') {
            return $data;
        }

        $hazards = (array) ($data['hazards'] ?? []);
        $clientReqStrings = StructuralGateVocabulary::flattenClientResponsibilities($data);

        foreach ($triggers as $row) {
            if (! is_array($row)) {
                continue;
            }

            $phrase = mb_strtolower(trim((string) ($row['phrase'] ?? '')));
            $signal = (string) ($row['signal'] ?? '');
            $label = (string) ($row['label'] ?? $phrase);

            if ($phrase === '' || $signal === '') {
                continue;
            }

            if (! str_contains($haystack, $phrase)) {
                continue;
            }

            $hasHazard = StructuralGateVocabulary::signalMatchesHazards($signal, $hazards);
            $hasClientReq = StructuralGateVocabulary::signalMatchesClientReqs($signal, $clientReqStrings);

            if ($hasHazard && $hasClientReq) {
                continue;
            }

            [$missingDescription, $missingAction] = match (true) {
                ! $hasHazard && ! $hasClientReq => [
                    'no supporting hazard row and no client-responsibility entry',
                    'the hazard and the client responsibility',
                ],
                ! $hasHazard => [
                    'no supporting hazard row (a client-responsibility entry is already present)',
                    'the hazard',
                ],
                default => [
                    'no client-responsibility entry (a supporting hazard row is already present)',
                    'the client responsibility',
                ],
            };

            throw new RamsGenerationException(sprintf(
                'Orphan control — "%s" references %s but the RAMS has %s (GATE-01). Add %s, or remove '
                . 'the reference, or set RAMS_STRUCTURAL_GATES=false to disable this check.',
                $phrase,
                $label,
                $missingDescription,
                $missingAction,
            ));
        }

        return $data;
    }

    /**
     * The combined free text GATE-01 scans for a trigger phrase: every
     * method-statement phase title and step, plus every surviving hazard
     * control line (REQUIREMENTS.md:62 — "a method step / hazard control
     * referencing…"). Case-folded once here so every caller in this gate
     * compares like-for-like. Never throws — a missing/malformed
     * `method_statement` or `hazards` key simply contributes nothing.
     */
    private static function orphanControlHaystack(array $data): string
    {
        $parts = [];

        $phases = (array) ($data['method_statement']['phases'] ?? []);
        foreach ($phases as $phase) {
            if (! is_array($phase)) {
                continue;
            }

            $parts[] = (string) ($phase['title'] ?? '');

            foreach ((array) ($phase['steps'] ?? []) as $step) {
                $parts[] = (string) $step;
            }
        }

        $hazards = (array) ($data['hazards'] ?? []);
        foreach ($hazards as $hazard) {
            if (! is_array($hazard)) {
                continue;
            }

            foreach ((array) ($hazard['controls'] ?? []) as $control) {
                $parts[] = (string) $control;
            }
        }

        $parts = array_filter($parts, static fn ($p) => trim((string) $p) !== '');

        return mb_strtolower(implode(' | ', $parts));
    }

    /**
     * GATE-02 (PORTING-NOTES.md:69) — independent re-check that every named
     * area appears in at least one method-statement phase title or step.
     * Method-statement phases carry NO area/room key
     * (RamsDataBuilderService.php:500), so this match is necessarily
     * name-based against phase titles and step text — there is no
     * structured area->phase link to re-check instead.
     *
     * **Passes vacuously on zero areas** — deliberate, not an oversight
     * (RESEARCH Finding 3 point 2): `buildQuoteSummary()` legitimately
     * returns `[]` and `ManualRamsCreationTest` exercises a form-only path
     * with no room list at all. Erroring on an empty area list would reject
     * every manual RAMS, which is legal output.
     *
     * Matching is case-folded and whitespace-trimmed via
     * {@see StructuralGateVocabulary::flattenAreas()}, which reads the
     * gate-private `areas_for_gate` mirror (Plan 30-02) first, falling back
     * to `$data['rooms']`.
     *
     * Known false-positive risk, accepted and documented (not fixed here):
     * a generic room name ("AV Rack", "Room 1") can match unrelated step
     * text and mask a real coverage gap. This is the specific reason D-03
     * ships `RAMS_STRUCTURAL_GATES` disarmed pending the corpus measurement
     * Plan 30-05 documents — conservative by construction, prefer a miss to
     * a false positive.
     *
     * Matching-order property (not a defect, no code change follows from
     * it): `cleanTextArtifacts()` runs LAST in `upgrade()` (:114) and
     * rewrites phase titles and steps, so this gate matches PRE-typo-fix
     * text while the issued document shows POST-fix text. The ordering is
     * forced — GATE-14 must read the `associated_risks` written earlier in
     * the pipeline, so all three new Phase 30 dispatch blocks sit after it —
     * and today's typo map makes divergence very unlikely.
     */
    private static function enforceAreaCoverageGate(array $data): array
    {
        $areas = StructuralGateVocabulary::flattenAreas($data);

        if (empty($areas)) {
            return $data;
        }

        $phases = (array) ($data['method_statement']['phases'] ?? []);

        $stepHaystack = [];
        foreach ($phases as $phase) {
            if (! is_array($phase)) {
                continue;
            }

            $stepHaystack[] = mb_strtolower(trim((string) ($phase['title'] ?? '')));

            foreach ((array) ($phase['steps'] ?? []) as $step) {
                $stepHaystack[] = mb_strtolower(trim((string) $step));
            }
        }

        foreach ($areas as $area) {
            $needle = mb_strtolower(trim($area));

            if ($needle === '') {
                continue;
            }

            $covered = false;
            foreach ($stepHaystack as $text) {
                if ($text !== '' && str_contains($text, $needle)) {
                    $covered = true;
                    break;
                }
            }

            if (! $covered) {
                throw new RamsGenerationException(sprintf(
                    'Area "%s" has no method steps (GATE-02). Add at least one method step for this '
                    . 'area, or remove the area, or set RAMS_STRUCTURAL_GATES=false to disable this '
                    . 'check.',
                    $area,
                ));
            }
        }

        return $data;
    }

    /**
     * GATE-04 (REQUIREMENTS.md:65, ROADMAP criterion 3) — the phase's only
     * TWO-TIER gate. Independently re-checks each hazard row's own
     * pre-control vs post-control scoring:
     *
     *   ERROR tier: throws when `post_likelihood * post_severity >
     *   pre_likelihood * pre_severity` — a residual (post-control) risk
     *   score that exceeds the initial (pre-control) score is a document
     *   that overstates the effect of its own controls. Throws on the
     *   FIRST such row (pattern S2), naming the hazard and the `RA##` label
     *   built from ROW INDEX + 1, zero-padded to two digits — NOT
     *   `$h['id']` (the 260817-r5e correction recorded at :1000-1006 and
     *   mirrored in `DocxBuilderService.php:1221`; the rendered document's
     *   reference label is row position, not any stored identifier).
     *
     *   WARN tier: appends a `compliance_warnings` entry — and NEVER
     *   throws, on any input, ever — when `post_severity < pre_severity`.
     *   T-30-15: controls conventionally reduce LIKELIHOOD, not severity
     *   (removing a hazard's mechanism of harm rather than shrinking the
     *   harm itself is unusual, though not impossible), so this is
     *   "flag for human review", explicitly NOT "silently accept" and
     *   explicitly NOT "reject". Proof this must never error: the
     *   COMMITTED `tests/Fixtures/rams/tilda-21cq29531/record.json` golden
     *   fixture's hazard 0 ("Working at height for display installation
     *   (up to 3m)") is pre 3x4=12, post 1x3=3 — residual severity 3 below
     *   initial severity 4 — and that is CORRECT, INTENDED HAZ-03 output
     *   ({@see \Tests\Feature\Rams\WorkingAtHeightResidualScoreTest}
     *   asserts this exact residual through the live DOCX path). An
     *   erroring `s2 < s1` branch would block the golden fixture and fail
     *   that test. Direct inspection of the fixture shows all three of its
     *   hazard rows trip this branch (severities 3<4, 2<3, 3<5) — this
     *   gate's own test drives the warn-path assertion from the real
     *   committed fixture data rather than a hand-authored count.
     *
     * Collects ALL warn entries in one pass (the warn tier does not stop
     * at the first violation, since the review panel lists every finding),
     * and commits them to `$data['compliance_warnings']` BEFORE checking
     * for an error-tier violation, so a document with both an error and
     * warnings has its warnings already written into `$data` at the moment
     * the throw fires — a deliberate ordering choice, even though
     * {@see \App\Exceptions\RamsGenerationException} itself carries no
     * payload and this call's own local warnings are necessarily discarded
     * along with the rest of its state when it throws (a throw never
     * returns `$data`); a CLEAN hazard set (no error) is the case this
     * ordering actually preserves, and it is proven by
     * `StructuralGatesTest`'s Tilda-fixture and single-warn-row tests,
     * both of which never throw.
     *
     * T-30-14: presence of `pre_likelihood`/`pre_severity` is checked with
     * `array_key_exists()` BEFORE any default is applied. A row missing
     * either key is SKIPPED entirely — neither warned nor errored — rather
     * than being scored against the `?? 1` default the normaliser and the
     * Blade template both use elsewhere
     * (`RamsDataBuilderService.php:440-457`, `pdf/rams.blade.php:1326-1333`).
     * Scoring an incomplete row against that default would manufacture a
     * false "residual exceeds initial" violation out of missing data, not
     * a real one — the conservative-skip precedent is
     * {@see self::enforceDisplayLiftGate()}'s null `continue` (:1322-1329)
     * and `parseStatedTeamSize()`'s null-skips (:1289-1295). Both scores
     * that ARE present are clamped `max(1, min(5, (int) …))`, identical to
     * the normaliser/Blade clamp, so this gate scores rows exactly as the
     * issued document displays them.
     */
    private static function enforceResidualScoreGate(array $data): array
    {
        $hazards = array_values((array) ($data['hazards'] ?? []));

        if (empty($hazards)) {
            return $data;
        }

        $warnings = (array) ($data['compliance_warnings'] ?? []);
        $firstViolationIndex = null;
        $firstViolationHazardName = '';
        $firstViolationPreScore = 0;
        $firstViolationPostScore = 0;

        foreach ($hazards as $index => $hazard) {
            if (! is_array($hazard)) {
                continue;
            }

            // T-30-14 — presence BEFORE default. A row missing either key
            // is skipped, never scored against the `?? 1` default.
            if (! array_key_exists('pre_likelihood', $hazard) || ! array_key_exists('pre_severity', $hazard)) {
                continue;
            }

            $preLikelihood = max(1, min(5, (int) $hazard['pre_likelihood']));
            $preSeverity = max(1, min(5, (int) $hazard['pre_severity']));
            $postLikelihood = max(1, min(5, (int) ($hazard['post_likelihood'] ?? 1)));
            $postSeverity = max(1, min(5, (int) ($hazard['post_severity'] ?? 1)));

            $preScore = $preLikelihood * $preSeverity;
            $postScore = $postLikelihood * $postSeverity;
            $hazardName = (string) ($hazard['hazard'] ?? '');

            if ($postSeverity < $preSeverity) {
                $warnings[] = [
                    'gate' => 'GATE-04',
                    'hazard_index' => $index,
                    'hazard' => $hazardName,
                    'message' => sprintf(
                        'GATE-04 — %s: residual severity %d is lower than initial severity %d. '
                        . 'Controls reduce likelihood, not severity — confirm this is intended.',
                        $hazardName,
                        $postSeverity,
                        $preSeverity,
                    ),
                ];
            }

            if ($firstViolationIndex === null && $postScore > $preScore) {
                $firstViolationIndex = $index;
                $firstViolationHazardName = $hazardName;
                $firstViolationPreScore = $preScore;
                $firstViolationPostScore = $postScore;
            }
        }

        // Commit warnings from every row before checking the error tier —
        // see docblock for what this ordering does and does not guarantee.
        $data['compliance_warnings'] = $warnings;

        if ($firstViolationIndex !== null) {
            $raLabel = 'RA' . str_pad((string) ($firstViolationIndex + 1), 2, '0', STR_PAD_LEFT);

            throw new RamsGenerationException(sprintf(
                'Hazard "%s" (%s) has a residual score of %d against an initial score of %d '
                . '(GATE-04). Residual risk cannot exceed initial risk — correct the post-control '
                . 'scoring, or set RAMS_STRUCTURAL_GATES=false to disable this check.',
                $firstViolationHazardName,
                $raLabel,
                $firstViolationPostScore,
                $firstViolationPreScore,
            ));
        }

        return $data;
    }

    /**
     * GATE-13 (CONTEXT.md D-02, RESEARCH.md Finding 5) — independent
     * cross-reference of the RA18-shaped hot-works contradiction: a
     * document asserting "no hot works" while ALSO carrying an
     * unconditional hot-works permit requirement, or listing a solder/flux
     * substance in COSHH.
     *
     * Ships WHOLE but DISARMED behind its own `RAMS_HOT_WORKS_GATE` flag
     * (D-04 — a new, independent flag; never reuses `RAMS_STRUCTURAL_GATES`
     * or `RAMS_MISSING_RISK_REF_GATE`, because this gate flips a full PHASE
     * later than the rest, per D-02). Both halves would false-positive
     * corpus-wide if armed today:
     *   - the COSHH half: `Tier1RamsDefaultsService::
     *     injectDefaultsIntoRamsData()` sets `$data['coshh_baseline']`
     *     UNCONDITIONALLY (`:81`) from a baseline carrying Tin/Lead Solder
     *     and Rosin Flux (`config/rams_tier1.php` `coshh_products`) — live
     *     on sites 3-6, dormant on sites 1-2 because
     *     `injectDefaultsIntoRamsData()` runs AFTER `upgrade()` on the two
     *     `RamsBuilderService` call sites (RESEARCH.md Finding 1);
     *   - the permit half: {@see self::addPermitAndIsolation()} emits its
     *     hot-works-permit rule line UNCONDITIONALLY, on every document,
     *     inside `upgrade()` itself — live on ALL SIX call sites.
     * Phase 31 (RULE-05/GATE-10) makes `coshh_baseline` job-conditional,
     * the prerequisite for arming `RAMS_HOT_WORKS_GATE=true`. Nothing in
     * this plan arms it.
     *
     * Step order matters (T-30-16, the widest false-positive exposure in
     * the phase): the absence assertion is detected FIRST via
     * {@see ControlTextRuleViolations}'s negation-aware `hot_works_assertion`
     * detector, scanning `exclusions`, every hazard name and control line,
     * and every method-statement step. If no assertion is found anywhere,
     * this method returns `$data` UNCHANGED immediately — this is what
     * keeps the gate silent on the overwhelming majority of documents that
     * never mention hot works at all.
     *
     * Only once an assertion is found does the permit half run: it scans
     * `permit_and_isolation.rules` for a hot-works/solder/heat-shrink
     * permit requirement and classifies it as conditional or unconditional
     * via {@see self::permitRuleIsUnconditionalHotWorksRequirement()}.
     * Conditional wording ("if soldering...", "permit required if...") is
     * treated as NON-contradictory — this is the ONLY thing standing
     * between this gate and a 100% corpus false-positive rate, because
     * `addPermitAndIsolation()` ships that exact conditional line on every
     * document. `HotWorksGateTest`'s regression test builds its input by
     * invoking `addPermitAndIsolation([])` directly and asserts identity —
     * if this gate's own fixture ever had to delete `permit_and_isolation`
     * to get a clean pass, the gate is wrong (RESEARCH.md Pitfall 4).
     *
     * The COSHH half then scans `coshh_baseline` entries' `product` field
     * for "solder"/"flux" (case-insensitive substring — conservative
     * enough not to need GHS-code parsing, since every solder/flux entry
     * in `coshh_products` names the substance in its product string).
     *
     * Known bypass, recorded here for Phase 31's arming task, NOT closed
     * by this plan (out of scope — GATE-13 ships disarmed regardless):
     * `pdf/rams.blade.php:407-409` and `pdf/rams-v2.blade.php:463-465`
     * derive a 'Hot Works Permit' row IN THE BLADE from
     * `preg_match('/(solder|heat shrink|hot work)/', $scopeBlob)`. That
     * derivation is invisible to `upgrade()`, so a document can display a
     * hot-works permit requirement this gate never sees.
     */
    private static function enforceHotWorksGate(array $data): array
    {
        if (! self::documentAssertsNoHotWorks($data)) {
            return $data;
        }

        $permitRules = (array) ($data['permit_and_isolation']['rules'] ?? []);
        foreach ($permitRules as $rule) {
            $ruleText = (string) $rule;

            if (self::permitRuleIsUnconditionalHotWorksRequirement($ruleText)) {
                throw new RamsGenerationException(sprintf(
                    'Hot-works contradiction — this RAMS states no hot works while also requiring '
                    . 'a hot-works permit (GATE-13): "%s". Resolve the contradiction before issuing, '
                    . 'or set RAMS_HOT_WORKS_GATE=false to disable this check.',
                    $ruleText,
                ));
            }
        }

        $coshhBaseline = (array) ($data['coshh_baseline'] ?? []);
        foreach ($coshhBaseline as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $product = (string) ($entry['product'] ?? '');

            if ($product === '') {
                continue;
            }

            if (stripos($product, 'solder') !== false || stripos($product, 'flux') !== false) {
                throw new RamsGenerationException(sprintf(
                    'Hot-works contradiction — this RAMS states no hot works while also listing '
                    . '"%s" in COSHH (GATE-13). Resolve the contradiction before issuing, or set '
                    . 'RAMS_HOT_WORKS_GATE=false to disable this check.',
                    $product,
                ));
            }
        }

        return $data;
    }

    /**
     * GATE-13 — true when a "no hot works" absence assertion is found
     * anywhere in the document's free text: `exclusions`, every hazard's
     * name and control lines, and every method-statement step. Delegates
     * classification entirely to
     * {@see ControlTextRuleViolations::detect()}'s `hot_works_assertion`
     * key (Plan 30-07 Task 1) — never a second, bespoke regex here, per
     * this class's established discipline of routing all free-text
     * rule-detection through that one registry. Conservative by
     * construction: an absent `exclusions`/`hazards`/`method_statement`
     * key contributes nothing and never throws.
     */
    private static function documentAssertsNoHotWorks(array $data): bool
    {
        $lines = [];

        foreach ((array) ($data['exclusions'] ?? []) as $line) {
            $lines[] = (string) $line;
        }

        foreach ((array) ($data['hazards'] ?? []) as $hazard) {
            if (! is_array($hazard)) {
                continue;
            }

            $lines[] = (string) ($hazard['hazard'] ?? '');

            foreach ((array) ($hazard['controls'] ?? []) as $control) {
                $lines[] = (string) $control;
            }
        }

        $phases = (array) ($data['method_statement']['phases'] ?? []);
        foreach ($phases as $phase) {
            if (! is_array($phase)) {
                continue;
            }

            foreach ((array) ($phase['steps'] ?? []) as $step) {
                $lines[] = (string) $step;
            }
        }

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            if (ControlTextRuleViolations::detect($line) === 'hot_works_assertion') {
                return true;
            }
        }

        return false;
    }

    /**
     * GATE-13's permit-half discriminator (T-30-16). A `permit_and_isolation`
     * rule line is treated as an UNCONDITIONAL hot-works permit requirement
     * only when it BOTH mentions hot works (or soldering/heat-shrink) AND
     * requires a permit, AND carries none of the conditional markers below.
     * `addPermitAndIsolation()`'s own shipped line — "Hot works permit
     * required IF soldering or heat-shrink operations are performed on
     * site" — carries `'if '`, so this returns `false` for it; that is the
     * entire point of this method existing rather than a bare "mentions
     * hot works and permit" check.
     */
    private static function permitRuleIsUnconditionalHotWorksRequirement(string $rule): bool
    {
        $lower = strtolower($rule);

        $mentionsHotWorks = str_contains($lower, 'hot work')
            || str_contains($lower, 'solder')
            || str_contains($lower, 'heat-shrink')
            || str_contains($lower, 'heat shrink');

        if (! $mentionsHotWorks || ! str_contains($lower, 'permit')) {
            return false;
        }

        foreach (['if ', 'where ', 'should ', 'when ', 'may be required'] as $conditionalMarker) {
            if (str_contains($lower, $conditionalMarker)) {
                return false;
            }
        }

        return true;
    }

    // =========================================================================
    // 14. TEXT HYGIENE — deterministic cleanup of known artifacts
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
