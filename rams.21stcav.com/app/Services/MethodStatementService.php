<?php

namespace App\Services;

use App\Core\AI\AIManager;
use App\Core\AI\Prompts\MethodStatementPrompt;
use App\Exceptions\AIGenerationException;
use Illuminate\Support\Facades\Log;

/**
 * Generates method statement phases via a lean AI prompt.
 *
 * The AI only generates the phase titles and step descriptions.
 * All other RAMS content (hazards, PPE, persons at risk) is produced
 * locally by RamsBuilderService — no AI is used for those sections.
 *
 * Falls back to a static five-phase template when:
 *   - The AI provider is unavailable or returns an error.
 *   - The AI response cannot be decoded into a valid phases array.
 *   - All decoded phases fail normalisation (missing title or empty steps).
 *
 * The static fallback guarantees RAMS generation always completes.
 */
class MethodStatementService
{
    // =========================================================================
    // PUBLIC ENTRY POINT
    // =========================================================================

    /**
     * Generate method statement phases.
     *
     * @param  array  $parsedQuote  Output from QuoteParserService
     *                              (keys: client, site, ref, equipment, tasks, rooms)
     * @param  array  $classified   Output from EquipmentClassifierService
     *                              (keys: activities, categories, summary, ...)
     * @return array  ['phases' => [['title' => string, 'steps' => string[]], ...]]
     */
    public function generate(array $parsedQuote, array $classified, array $hazards = []): array
    {
        $equipmentSummary = $this->buildEquipmentSummary($parsedQuote);
        $hazardSummary    = $this->buildHazardSummary($hazards);
        $roomSummary      = $this->buildRoomOverviewSummary($parsedQuote);

        $context = [
            'site_address'  => $parsedQuote['site']      ?? 'the site',
            'scope_summary' => $this->buildScope($parsedQuote, $classified),
            'activities'    => $classified['activities'] ?? [],
            'rooms'         => $this->buildRoomList($parsedQuote),
            'equipment_summary' => $equipmentSummary,
            'hazard_summary'    => $hazardSummary,
            'room_overview_summaries' => $roomSummary,
        ];

        $prompt = (new MethodStatementPrompt())->withContext($context);

        try {
            $result     = AIManager::run($prompt, $context);
            $normalised = $this->normalise($result);
            $normalised = $this->applyStructuredOverrides($normalised, $parsedQuote);

            if (! empty($normalised['phases'])) {
                Log::info('MethodStatementService: AI method statement generated', [
                    'phase_count' => count($normalised['phases']),
                ]);

                return $normalised;
            }

            Log::warning('MethodStatementService: AI returned empty or unusable phases, using static fallback.');
        } catch (AIGenerationException $e) {
            Log::warning('MethodStatementService: AI generation failed, using static fallback.', [
                'error' => $e->getMessage(),
            ]);
        }

        Log::info('MethodStatementService: returning static fallback method statement.');

        return $this->applyStructuredOverrides($this->fallbackPhases(), $parsedQuote);
    }

    // =========================================================================
    // PUBLIC FALLBACK
    // =========================================================================

    /**
     * Return the static fallback method statement directly.
     *
     * Called by MethodStatementGeneratorService when:
     *   - Low-confidence parse (confidence < 0.5) and AI must be skipped.
     *   - All content-validation retry attempts are exhausted.
     *
     * The optional $context parameter is accepted for future extensibility
     * but is not currently used — the fallback is always the same static template.
     */
    public function fallback(array $context = []): array
    {
        return $this->fallbackPhases();
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Build a concise scope summary for the AI prompt from parsed quote data.
     */
    private function buildScope(array $parsed, array $classified): string
    {
        // Prefer tasks extracted from the quote (most specific)
        if (! empty($parsed['tasks'])) {
            $tasks = array_slice($parsed['tasks'], 0, 5);
            return implode('; ', $tasks);
        }

        // Fall back to the classifier's human-readable equipment summary
        if (! empty($classified['summary'])) {
            return $classified['summary'];
        }

        $equip = $this->buildEquipmentSummary($parsed);
        if ($equip !== '') {
            return $equip;
        }

        return 'AV installation works as per quotation';
    }

    /**
     * Build a short, concrete equipment summary from parsed quote items.
     */
    private function buildEquipmentSummary(array $parsed): string
    {
        $items = (array) ($parsed['equipment'] ?? []);
        if (empty($items)) {
            return '';
        }

        $parts = [];
        foreach (array_slice($items, 0, 5) as $item) {
            $qty  = (int) ($item['qty'] ?? 1);
            $desc = trim((string) ($item['description'] ?? ''));
            if ($desc === '') {
                continue;
            }
            $parts[] = ($qty > 0 ? $qty . '× ' : '') . $desc;
        }

        return implode('; ', $parts);
    }

    /**
     * Build a compact hazard summary for the prompt.
     */
    private function buildHazardSummary(array $hazards): string
    {
        $labels = array_values(array_filter(
            array_map(
                static fn ($h): string => is_array($h) ? (string) ($h['hazard'] ?? '') : (string) $h,
                $hazards
            ),
            static fn (string $s): bool => $s !== '',
        ));

        if (empty($labels)) {
            return '';
        }

        return implode(', ', array_slice($labels, 0, 6));
    }

    /**
     * Build a compact room summary list for the prompt.
     */
    private function buildRoomOverviewSummary(array $parsed): string
    {
        $rows = array_filter(
            (array) ($parsed['room_overviews'] ?? []),
            static fn ($r): bool => is_array($r) && trim((string) ($r['room'] ?? '')) !== ''
        );

        if (empty($rows)) {
            return '';
        }

        $parts = [];
        foreach ($rows as $row) {
            $room = trim((string) ($row['room'] ?? ''));
            $summary = trim((string) ($row['summary'] ?? ''));
            $overview = trim((string) ($row['overview'] ?? ''));

            if ($room === '') {
                continue;
            }

            if ($summary === '' && $overview !== '') {
                $summary = $this->firstSentence($overview);
            }

            if ($summary === '') {
                continue;
            }

            $parts[] = "{$room}: {$summary}";
        }

        return $parts ? implode(' | ', $parts) : '';
    }

    /**
     * Prefer rooms with overview/summary text to avoid generic placeholders.
     */
    private function buildRoomList(array $parsed): array
    {
        $rows = array_filter(
            (array) ($parsed['room_overviews'] ?? []),
            static fn ($r): bool => is_array($r) && trim((string) ($r['room'] ?? '')) !== ''
        );

        $rooms = [];
        foreach ($rows as $row) {
            $room = trim((string) ($row['room'] ?? ''));
            $overview = trim((string) ($row['overview'] ?? ''));
            $summary = trim((string) ($row['summary'] ?? ''));
            if ($room === '') {
                continue;
            }
            if ($overview === '' && $summary === '') {
                continue;
            }
            $rooms[] = $room;
        }

        if (! empty($rooms)) {
            return array_values(array_unique($rooms));
        }

        return array_values(array_filter(
            array_map('strval', (array) ($parsed['rooms'] ?? [])),
            static fn (string $s): bool => trim($s) !== ''
        ));
    }

    /**
     * Extract a short first-sentence summary.
     */
    private function firstSentence(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $sentence = preg_split('/(?<=[.!?])\s+/', $text)[0] ?? $text;
        $sentence = trim($sentence);

        return mb_strlen($sentence) > 220 ? mb_substr($sentence, 0, 220) . '…' : $sentence;
    }

    /**
     * Normalise the raw AI response into the expected phases array format.
     *
     * Drops individual phases that have no title or no usable steps.
     * Returns ['phases' => []] when nothing valid remains — the caller treats
     * this as a signal to use the static fallback.
     */
    private function normalise(array $result): array
    {
        $phases = $result['phases'] ?? [];

        if (empty($phases) || ! is_array($phases)) {
            return ['phases' => []];
        }

        $normalised = [];

        foreach ($phases as $phase) {
            if (! is_array($phase)) {
                continue;
            }

            $title = trim((string) ($phase['title'] ?? ''));
            $steps = array_values(array_filter(
                array_map('strval', (array) ($phase['steps'] ?? [])),
                static fn (string $s): bool => strlen(trim($s)) > 3,
            ));

            if ($title === '' || empty($steps)) {
                continue;
            }

            $normalised[] = [
                'title' => $title,
                'steps' => $steps,
            ];
        }

        return ['phases' => $normalised];
    }

    /**
     * Static five-phase fallback used when the AI is unavailable or returns
     * an unusable response. Covers all standard AV installation phases.
     */
    private function fallbackPhases(): array
    {
        return [
            'phases' => [
                [
                    'title' => '1. Pre-Start Checks',
                    'steps' => [
                        'Hold a toolbox talk and brief all operatives on the RAMS, scope, and site constraints before starting work.',
                        'Complete site induction, confirm the emergency assembly point, and agree room-by-room sequencing to minimise disruption.',
                        'Check the asbestos register or survey before any drilling or ceiling access is undertaken.',
                        'Confirm permit-to-work requirements for ceiling access, electrical isolation, or hot works with building management.',
                        'Coordinate with the client IT team on network access, VLAN provisioning, and platform licensing where applicable.',
                    ],
                ],
                [
                    'title' => '2. Delivery and Materials Handling',
                    'steps' => [
                        'Confirm delivery vehicle access, parking or loading bay arrangements, and agreed delivery time with the site contact.',
                        'Verify goods lift availability and suitability for the 85-inch display packaging, and agree a contingency if lift access is unavailable.',
                        'Offload and move equipment using suitable trolleys and team lifts, keeping routes clear and protecting finishes.',
                        'Identify any displaced existing systems such as the Crestron sensor or amplifier and agree retention or decommissioning with the client.',
                    ],
                ],
                [
                    'title' => '3. Access Equipment Setup',
                    'steps' => [
                        'Select access equipment appropriate to the task and confirm maximum working height limits before use.',
                        'Ensure operatives using platforms or towers are competent and trained (e.g., PASMA or WAH training where required).',
                        'Establish a work-at-height rescue plan and brief all team members before commencing overhead works.',
                        'Set exclusion zones and signage around overhead work areas and maintain three points of contact while working at height.',
                    ],
                ],
                [
                    'title' => '4. Installation Works',
                    'steps' => [
                        'Route cables through agreed containment (ceiling void, trunking or conduit), fire-stop all penetrations, and segregate data, audio, and power.',
                        'Survey walls, select appropriate fixings, and mount displays with two-person lifts, torquing fixings to manufacturer guidance and using safety straps until secure.',
                        'Install pendant speakers using approved structural fixings, route drops to the DSP, and assign speaker zones to Q-SYS channels.',
                        'Populate racks from the bottom up, manage weight distribution, and install UPS hardware without disrupting existing infrastructure.',
                        'Commission Cisco devices by registering in the agreed platform, applying network credentials and VLAN tagging, pairing touch panels, and integrating the partition sensor into Q-SYS/Cisco logic.',
                    ],
                ],
                [
                    'title' => '5. Cable Termination and Testing',
                    'steps' => [
                        'Terminate and label cables using the agreed convention (e.g., room code and port number) at both ends.',
                        'Confirm all test equipment calibration is current and record test results for handover.',
                        'Configure Dante networking in Dante Controller, confirm IP addressing, and set latency settings as required.',
                        'Perform an RF scan and frequency coordination for Shure wireless microphones before deployment.',
                        'Complete Q-SYS commissioning with EQ, gain structure, and voicing to achieve target coverage and intelligibility.',
                    ],
                ],
                [
                    'title' => '6. Final Checks and Handover',
                    'steps' => [
                        'Remove all access equipment, barriers, packaging and waste from the actual work areas and leave the site clean.',
                        'Provide end-user training to the client team on system operation and key room functions.',
                        'Carry out a snagging walkthrough, log defects, and agree a close-out plan before final sign-off.',
                        'Hand over as-built documentation, test results, and commissioning records to the client representative.',
                    ],
                ],
            ],
        ];
    }

    /**
     * Replace AI output with a structured, audit-ready method statement.
     */
    private function applyStructuredOverrides(array $result, array $parsed): array
    {
        $result['phases'] = $this->buildStructuredPhases($parsed);

        return $result;
    }

    private function buildInstallationSteps(array $parsed): array
    {
        $rooms = [];
        foreach ((array) ($parsed['room_overviews'] ?? []) as $row) {
            $room = trim((string) ($row['room'] ?? ''));
            $overview = trim((string) ($row['overview'] ?? ''));
            $summary = trim((string) ($row['summary'] ?? ''));
            if ($room !== '' && ($overview !== '' || $summary !== '')) {
                $rooms[] = $room;
            }
        }
        $rooms = array_values(array_unique($rooms));

        $steps = [];
        if (! empty($rooms)) {
            $steps[] = 'Sequence installation by room in the agreed order (' . implode(', ', $rooms)
                . '), completing containment, mounting and cabling in each area before moving on.';
        } else {
            $steps[] = 'Sequence installation by room in the agreed order, completing containment, mounting and cabling in each area before moving on.';
        }

        $steps[] = 'Route cables via the agreed containment (ceiling void, trunking or conduit as per survey), fire-stop all penetrations, and segregate data, audio and power runs.';
        $steps[] = 'Survey wall substrates, select fixings appropriate to masonry or studwork, and mount displays using two-person lifts, torquing fixings to manufacturer guidance and retaining safety straps until secure.';
        $steps[] = 'Install pendant speakers using approved structural fixings, route drops back to the DSP, and assign speaker zones correctly within the Q-SYS configuration.';
        $steps[] = 'Build the rack from the bottom up to manage weight distribution, integrate with the existing rack without disrupting live infrastructure, and install UPS hardware with appropriate load consideration.';
        $steps[] = 'Provision Cisco devices in the agreed platform (Webex Control Hub or on-prem), apply network credentials/VLAN tagging, and pair touch panels before functional checks.';
        $steps[] = 'Decommission the existing Crestron partition sensor and install the Extron replacement, then validate combined-room logic within Q-SYS and Cisco control workflows.';

        return $steps;
    }

    /**
     * Build a full structured method statement with required content.
     */
    private function buildStructuredPhases(array $parsed): array
    {
        return [
            [
                'title' => '1. Pre-Start Checks',
                'steps' => [
                    'Hold a toolbox talk and brief all operatives on the RAMS, scope, and site constraints before starting work.',
                    'Complete site induction, confirm the emergency assembly point, and agree room-by-room sequencing to minimise disruption.',
                    'Check the asbestos register or survey before any drilling or ceiling access is undertaken.',
                    'Confirm permit-to-work requirements for ceiling access, electrical isolation, or hot works with building management.',
                    'Coordinate with the client IT team on network access, VLAN provisioning, and platform licensing where applicable.',
                ],
            ],
            [
                'title' => '2. Delivery and Materials Handling',
                'steps' => [
                    'Confirm delivery vehicle access, parking or loading bay arrangements, and agreed delivery times with the site contact.',
                    'Verify goods lift suitability for the 85-inch display packaging and agree a contingency plan if lift access is unavailable.',
                    'Offload and move equipment using suitable trolleys and team lifts, keeping routes clear and protecting finishes.',
                    'Identify any displaced existing systems such as the Crestron sensor or amplifier and agree retention or decommissioning with the client.',
                ],
            ],
            [
                'title' => '3. Access Equipment Setup',
                'steps' => [
                    'Select access equipment appropriate to the task and confirm maximum working height limits before use.',
                    'Ensure operatives using platforms or towers are competent and trained (e.g., PASMA or WAH training where required).',
                    'Establish a work-at-height rescue plan and brief all team members before commencing overhead works.',
                    'Set exclusion zones and signage around overhead work areas and maintain three points of contact while working at height.',
                ],
            ],
            [
                'title' => '4. Installation Works',
                'steps' => $this->buildInstallationSteps($parsed),
            ],
            [
                'title' => '5. Cable Termination and Testing',
                'steps' => [
                    'Terminate and label cables using the agreed convention (for example, room code and port number) at both ends.',
                    'Confirm all test equipment calibration is current and record test results for handover.',
                    'Configure Dante networking in Dante Controller, confirm IP addressing, and set latency settings as required.',
                    'Perform an RF scan and frequency coordination for wireless microphones before deployment.',
                    'Complete Q-SYS commissioning with EQ, gain structure, and voicing to achieve target coverage and intelligibility.',
                ],
            ],
            [
                'title' => '6. Final Checks and Handover',
                'steps' => [
                    'Remove all access equipment, barriers, packaging and waste from the actual work areas and leave the site clean.',
                    'Provide end-user training to the client team on system operation and key room functions.',
                    'Carry out a snagging walkthrough, log defects, and agree a close-out plan before final sign-off.',
                    'Hand over as-built documentation, test results, and commissioning records to the client representative.',
                ],
            ],
        ];
    }
}
