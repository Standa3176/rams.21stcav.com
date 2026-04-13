<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Assembles and normalises the complete RAMS data structure.
 *
 * Combines all upstream service outputs into the single array that is:
 *   - Persisted to rams_documents.generated_data
 *   - Passed to RamsDocumentRendererService for DOCX generation
 *
 * Responsibilities:
 *   - Resolve final project field values (form data takes priority over parsed)
 *   - Merge base PPE with any form-supplied selections
 *   - Assemble the quote summary block (equipment line items + room summaries)
 *   - Build the persons_at_risk list (always includes '21CAV Staff')
 *   - Normalise every key to its expected PHP type
 *   - Validate that minimum required sections are non-empty
 *
 * Guarantee (Issue 12):
 *   The returned array ALWAYS contains a 'method_statement' key at the top
 *   level, even when the normalised phases array is empty.
 *
 * No AI calls. No database access. No Eloquent models.
 * Pure data transformation.
 */
class RamsDataBuilderService
{
    // =========================================================================
    // PUBLIC ENTRY POINT
    // =========================================================================

    /**
     * Assemble the final RAMS data structure from all upstream service outputs.
     *
     * @param  array  $parsed           Output from QuoteParserService
     * @param  array  $classified       Output from EquipmentClassifierService
     * @param  array  $risk             Output from RiskTemplateResolverService
     *                                  (keys: hazards, ppe, access_equipment)
     * @param  array  $methodStatement  Output from MethodStatementGeneratorService
     *                                  (key: phases)
     * @param  array  $formData         Validated form input (overrides parsed fields)
     * @return array                    Fully assembled, normalised data array
     *
     * @throws \RuntimeException  If required sections are empty after normalisation.
     */
    public function assemble(
        array $parsed,
        array $classified,
        array $risk,
        array $methodStatement,
        array $formData,
    ): array {
        $data = [
            'project'                => $this->resolveProjectFields($parsed, $formData),
            'hazards'                => $risk['hazards']          ?? [],
            'ppe'                    => $this->mergePpe(
                                            $risk['ppe']          ?? [],
                                            $formData['ppe']      ?? [],
                                        ),
            'access_equipment'       => $risk['access_equipment'] ?? [],
            'persons_at_risk'        => $this->buildPersons($formData['persons_at_risk'] ?? []),
            'method_statement'       => $methodStatement,   // always present — guaranteed by normalise()
            'team'                   => $formData['team']   ?? [],
            'quote'                  => $this->buildQuoteSummary($parsed),
            'classified'             => $classified,
            'scope_items'            => $this->buildScopeItems($formData),
            'tools_and_equipment'    => $this->deriveTools($formData),
            'client_responsibilities'=> $this->deriveClientResponsibilities($formData),
        ];

        $data = $this->normalise($data);

        $this->assertMinimum($data);

        return $data;
    }

    // =========================================================================
    // PRIVATE — FIELD RESOLUTION
    // =========================================================================

    /**
     * Resolve final project field values.
     *
     * Priority: form data (explicit user input) > parsed PDF data.
     * A ref is always guaranteed — auto-generated if neither source provides one.
     *
     * This is the ONLY place where form_data overrides are applied (Issue 7).
     */
    private function resolveProjectFields(array $parsed, array $formData): array
    {
        $ref    = ($formData['project_ref']       ?? '') ?: ($parsed['ref']           ?? '');
        $name   = ($formData['project_name']       ?? '') ?: ($parsed['project_name']  ?? '');
        $client = ($formData['client_name']        ?? '') ?: ($parsed['client']        ?? '');
        $site   = ($formData['site_address']        ?? '') ?: ($parsed['site']          ?? '');
        $scope  = ($formData['works_description']  ?? '') ?: ($parsed['works_summary'] ?? '');

        if (empty($ref)) {
            $ref = 'RAMS-' . now()->format('Ymd');
        }

        return [
            'ref'               => $ref,
            'name'              => $name,
            'client'            => $client,
            'site_address'      => $site,
            'site_contact'      => $formData['site_contact'] ?? '',
            'works_description' => $scope,
            'document_status'   => $formData['document_status'] ?? 'For Issue',
            'doc_author'        => $formData['doc_author']       ?? '',
            'date'              => now()->format('F Y'),
            'project_manager'      => $formData['project_manager']      ?? '',
            'lead_engineer'        => $formData['lead_engineer']        ?? '',
            'additional_engineers' => $formData['additional_engineers'] ?? '',
            'programmer'           => $formData['programmer']           ?? '',
            // New document control fields
            'client_contact_name'  => $formData['client_contact_name']  ?? '',
            'client_contact_email' => $formData['client_contact_email'] ?? '',
            'working_hours'        => $formData['working_hours']        ?? 'Monday–Friday, 09:00–17:30',
            'revision'             => $formData['revision']             ?? 'Rev 1.0',
            'rooms_text'           => $formData['rooms_text']           ?? '',
        ];
    }

    private function mergePpe(array $basePpe, array $formPpe): array
    {
        return array_values(array_unique(array_merge($basePpe, $formPpe)));
    }

    /**
     * Ensure '21CAV Staff' is always present, then merge any form-supplied persons.
     */
    private function buildPersons(array $formPersons): array
    {
        return array_values(array_unique(array_merge(['21CAV Staff'], $formPersons)));
    }

    /**
     * Build the quote summary block for the DOCX cover.
     * Returns an empty array when no equipment was parsed (form-only RAMS).
     */
    private function buildQuoteSummary(array $parsed): array
    {
        if (empty($parsed['equipment'])) {
            return [];
        }

        $categorized = $this->categoriseQuoteItems($parsed['equipment']);

        // Hardware only is used for RAMS lists.
        $hardware = $categorized['hardware'] ?? [];

        return [
            'line_items' => array_map(static fn ($item) => [
                'sku'         => '',
                'qty'         => $item['qty']         ?? 1,
                'description' => $item['description'] ?? '',
                'room'        => $item['location']    ?? '',
            ], $hardware),

            // Grouped hardware list for room/area display
            'hardware_by_room' => $this->groupHardwareByRoom($hardware),

            // Full categorised lists for other documents (if needed)
            'cables'       => $categorized['cables']       ?? [],
            'consumables'  => $categorized['consumables']  ?? [],
            'services'     => $categorized['services']     ?? [],
            'options'      => $categorized['option']       ?? [],

            'room_summaries' => array_map(static fn ($room) => [
                'room'    => $room,
                'summary' => '',
            ], $parsed['rooms'] ?? []),
        ];
    }

    /**
     * Categorise quote items into hardware / cables / consumables / services / option.
     */
    private function categoriseQuoteItems(array $items): array
    {
        $cats = [
            'hardware'    => [],
            'cables'      => [],
            'consumables' => [],
            'services'    => [],
            'option'      => [],
        ];

        $optionKw = ['optional', 'option'];
        $serviceKw = [
            'install', 'installation', 'commission', 'programming', 'configuration', 'setup',
            'survey', 'site survey', 'project management', 'pm ', 'engineering', 'labour',
            'training', 'handover', 'design', 'draw', 'tech check', 'testing', 'travel',
            'accommodation', 'logistics', 'delivery cost', 'consumables - service',
            'delivery', 'pallet delivery', 'rams', 'risk assessment', 'method statement',
            'first fix', 'snagging', 'commissioning',
        ];
        $cableKw = [
            'cat5', 'cat5e', 'cat6', 'cat6a', 'cat7', 'cat8', 'u/utp', 's/ftp', 'f/utp',
            'cable', 'patch lead', 'hdmi', 'displayport', 'dp ', 'usb', 'ethernet',
            'network', 'coupler', 'plug', 'connector', 'socket',
        ];
        $consumableKw = [
            'consumable', 'consumables', 'sundry', 'sundries', 'fixing', 'fixings', 'screws',
            'anchors', 'bolt', 'bolts', 'cable tie', 'velcro', 'tape', 'label', 'labels',
            'grommet', 'glue', 'sealant',
        ];

        foreach ($items as $item) {
            $desc = strtolower((string) ($item['description'] ?? ''));

            $category = strtolower((string) ($item['category'] ?? ''));
            if (in_array($category, ['hardware', 'cables', 'consumables', 'services', 'option'], true)) {
                $cats[$category][] = $item;
                continue;
            }

            $isOption     = $this->containsAny($desc, $optionKw);
            $isService    = $this->containsAny($desc, $serviceKw);
            $isCable      = $this->containsAny($desc, $cableKw);
            $isConsumable = $this->containsAny($desc, $consumableKw);

            if ($isOption) {
                $cats['option'][] = $item;
            } elseif ($isService) {
                $cats['services'][] = $item;
            } elseif ($isCable) {
                $cats['cables'][] = $item;
            } elseif ($isConsumable) {
                $cats['consumables'][] = $item;
            } else {
                $cats['hardware'][] = $item;
            }
        }

        return $cats;
    }

    private function groupHardwareByRoom(array $hardware): array
    {
        $groups = [];
        foreach ($hardware as $item) {
            $room = trim((string) ($item['location'] ?? ''));
            if ($room === '') {
                $room = 'General';
            }
            $groups[$room][] = $item;
        }

        ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);

        $result = [];
        foreach ($groups as $room => $items) {
            $result[] = [
                'room'  => $room,
                'items' => $items,
            ];
        }

        return $result;
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $n) {
            if ($n !== '' && str_contains($haystack, $n)) {
                return true;
            }
        }
        return false;
    }

    // =========================================================================
    // PRIVATE — NORMALISATION
    // =========================================================================

    /**
     * Enforce strict types on every section so downstream renderers never
     * receive unexpected nulls or scalars where arrays are expected.
     *
     * Hazard rows with no label are dropped.
     * Method statement phases with no title or no steps are dropped.
     *
     * The 'method_statement' key is ALWAYS present in the output, even when
     * the phases array is empty after normalisation (Issue 12).
     */
    private function normalise(array $data): array
    {
        // ── project ───────────────────────────────────────────────────────────
        $p = is_array($data['project'] ?? null) ? $data['project'] : [];
        $data['project'] = [
            'ref'               => (string) ($p['ref']               ?? ''),
            'name'              => (string) ($p['name']              ?? ''),
            'client'            => (string) ($p['client']            ?? ''),
            'site_address'      => (string) ($p['site_address']      ?? ''),
            'site_contact'      => (string) ($p['site_contact']      ?? ''),
            'works_description' => (string) ($p['works_description'] ?? ''),
            'document_status'   => (string) ($p['document_status']   ?? 'For Issue'),
            'doc_author'        => (string) ($p['doc_author']        ?? ''),
            'date'              => (string) ($p['date']              ?? now()->format('F Y')),
            'project_manager'      => (string) ($p['project_manager']      ?? ''),
            'lead_engineer'        => (string) ($p['lead_engineer']        ?? ''),
            'additional_engineers' => (string) ($p['additional_engineers'] ?? ''),
            'programmer'           => (string) ($p['programmer']           ?? ''),
            // New document control fields
            'client_contact_name'  => (string) ($p['client_contact_name']  ?? ''),
            'client_contact_email' => (string) ($p['client_contact_email'] ?? ''),
            'working_hours'        => (string) ($p['working_hours']        ?? 'Monday–Friday, 09:00–17:30'),
            'revision'             => (string) ($p['revision']             ?? 'Rev 1.0'),
            'rooms_text'           => (string) ($p['rooms_text']           ?? ''),
        ];

        // ── hazards ───────────────────────────────────────────────────────────
        $rawHazards  = is_array($data['hazards'] ?? null) ? $data['hazards'] : [];
        $normHazards = [];

        foreach ($rawHazards as $h) {
            if (! is_array($h)) {
                continue;
            }

            $label = trim((string) ($h['hazard'] ?? ''));
            if ($label === '') {
                continue;
            }

            $controls = array_values(array_filter(
                array_map('strval', (array) ($h['controls'] ?? [])),
                static fn (string $s): bool => strlen(trim($s)) > 0,
            ));

            $persons = array_values(array_filter(
                array_map('strval', (array) ($h['persons_at_risk'] ?? [])),
                static fn (string $s): bool => strlen(trim($s)) > 0,
            ));

            $normHazards[] = [
                'id'              => max(0, (int) ($h['id']              ?? 0)),
                'hazard'          => $label,
                'persons_at_risk' => $persons,
                'pre_likelihood'  => max(1, min(5, (int) ($h['pre_likelihood']  ?? 1))),
                'pre_severity'    => max(1, min(5, (int) ($h['pre_severity']    ?? 1))),
                'controls'        => $controls,
                'post_likelihood' => max(1, min(5, (int) ($h['post_likelihood'] ?? 1))),
                'post_severity'   => max(1, min(5, (int) ($h['post_severity']   ?? 1))),
            ];
        }

        $data['hazards'] = $normHazards;

        // ── ppe ───────────────────────────────────────────────────────────────
        $data['ppe'] = array_values(array_filter(
            array_map('strval', (array) ($data['ppe'] ?? [])),
            static fn (string $s): bool => strlen(trim($s)) > 0,
        ));

        // ── access_equipment ──────────────────────────────────────────────────
        $data['access_equipment'] = array_values(array_filter(
            array_map('strval', (array) ($data['access_equipment'] ?? [])),
            static fn (string $s): bool => strlen(trim($s)) > 0,
        ));

        // ── persons_at_risk ───────────────────────────────────────────────────
        $data['persons_at_risk'] = array_values(array_filter(
            array_map('strval', (array) ($data['persons_at_risk'] ?? [])),
            static fn (string $s): bool => strlen(trim($s)) > 0,
        ));

        // ── method_statement — ALWAYS present at top level (Issue 12) ─────────
        $ms         = is_array($data['method_statement'] ?? null) ? $data['method_statement'] : [];
        $rawPhases  = is_array($ms['phases'] ?? null) ? $ms['phases'] : [];
        $normPhases = [];

        foreach ($rawPhases as $phase) {
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

            $normPhases[] = ['title' => $title, 'steps' => $steps];
        }

        // Always set the key — renderer must never receive a missing key.
        $data['method_statement'] = ['phases' => $normPhases];

        // ── team ──────────────────────────────────────────────────────────────
        $data['team'] = is_array($data['team'] ?? null) ? $data['team'] : [];

        // ── scope_items ───────────────────────────────────────────────────────
        $si = is_array($data['scope_items'] ?? null) ? $data['scope_items'] : [];
        $data['scope_items'] = [
            'decommission' => is_array($si['decommission'] ?? null) ? $si['decommission'] : [],
            'retained'     => is_array($si['retained']     ?? null) ? $si['retained']     : [],
            'new_install'  => is_array($si['new_install']  ?? null) ? $si['new_install']  : [],
        ];

        // ── tools_and_equipment ───────────────────────────────────────────────
        $data['tools_and_equipment'] = array_values(array_filter(
            array_map('strval', (array) ($data['tools_and_equipment'] ?? [])),
            static fn (string $s): bool => strlen(trim($s)) > 0,
        ));

        // ── client_responsibilities ───────────────────────────────────────────
        $data['client_responsibilities'] = array_values(array_filter(
            array_map('strval', (array) ($data['client_responsibilities'] ?? [])),
            static fn (string $s): bool => strlen(trim($s)) > 0,
        ));

        // ── quote ─────────────────────────────────────────────────────────────
        $data['quote'] = is_array($data['quote'] ?? null) ? $data['quote'] : [];

        // ── classified ────────────────────────────────────────────────────────
        $c = is_array($data['classified'] ?? null) ? $data['classified'] : [];
        $data['classified'] = [
            'activities'        => array_values(array_filter(
                                       array_map('strval', (array) ($c['activities'] ?? [])),
                                       static fn (string $s): bool => strlen($s) > 0,
                                   )),
            'categories'        => is_array($c['categories'] ?? null) ? $c['categories'] : [],
            'summary'           => (string) ($c['summary']           ?? ''),
            'heavy_items'       => array_values(array_filter(
                                       array_map('strval', (array) ($c['heavy_items'] ?? [])),
                                       static fn (string $s): bool => strlen($s) > 0,
                                   )),
            'drilling_required' => (bool) ($c['drilling_required']   ?? false),
        ];

        return $data;
    }

    // =========================================================================
    // PRIVATE — SCOPE ITEMS & DERIVED LISTS
    // =========================================================================

    /**
     * Build the three scope item buckets from form data.
     * Filters out rows with empty item_name so blank rows are ignored.
     */
    private function buildScopeItems(array $formData): array
    {
        return [
            'decommission' => array_values(array_filter(
                (array) ($formData['decommission_items'] ?? []),
                static fn ($r): bool => is_array($r) && ! empty($r['item_name']),
            )),
            'retained'     => array_values(array_filter(
                (array) ($formData['retained_items'] ?? []),
                static fn ($r): bool => is_array($r) && ! empty($r['item_name']),
            )),
            'new_install'  => array_values(array_filter(
                (array) ($formData['new_install_items'] ?? []),
                static fn ($r): bool => is_array($r) && ! empty($r['item_name']),
            )),
        ];
    }

    /**
     * Derive the tools & equipment list.
     *
     * Starts with standard AV installation tools, then appends any
     * access equipment selected on the form.
     */
    private function deriveTools(array $formData): array
    {
        $tools = [
            'Drill and drill bits',
            'Multi-tool / oscillating cutter',
            'Cable fish tape',
            'Crimping tools',
            'Cable tester',
            'Multi-meter',
            'Laptop for commissioning',
            'Label printer',
        ];

        $accessMap = [
            'podium_steps'  => 'Podium steps',
            'access_tower'  => 'Access tower',
            'scissor_lift'  => 'Scissor lift',
        ];

        foreach ((array) ($formData['access_equipment'] ?? []) as $key) {
            $label = $accessMap[$key] ?? ucwords(str_replace('_', ' ', (string) $key));
            if ($label !== '' && ! in_array($label, $tools, true)) {
                $tools[] = $label;
            }
        }

        return $tools;
    }

    /**
     * Derive the client responsibilities list.
     *
     * Includes any non-empty notes from new_install_items rows (which may
     * describe client-side pre-requisites), plus standard standing items.
     */
    private function deriveClientResponsibilities(array $formData): array
    {
        $items = [];

        // Gather non-empty notes from new install rows as client-side pre-requisites.
        foreach ((array) ($formData['new_install_items'] ?? []) as $row) {
            $note = trim((string) ($row['notes'] ?? ''));
            if ($note !== '' && ! in_array($note, $items, true)) {
                $items[] = $note;
            }
        }

        // Standard standing items — always appended.
        $standing = [
            'Ensure all software licences are available prior to commencement of works',
            'Provide dedicated power outlets as per the agreed specification',
            'Provide network drops / IT infrastructure as specified',
            'Book out rooms for the agreed installation period',
            'Ensure a site contact is available throughout the installation',
        ];

        foreach ($standing as $s) {
            if (! in_array($s, $items, true)) {
                $items[] = $s;
            }
        }

        return $items;
    }

    // =========================================================================
    // PRIVATE — VALIDATION
    // =========================================================================

    /**
     * Verify minimum required sections are non-empty after normalisation.
     *
     * Throws on hard failures (empty ppe, persons_at_risk, missing ref+name).
     * Warns on soft failures (empty hazards, empty method statement phases — renderer handles gracefully).
     *
     * Hazards are intentionally a soft failure: a reviewer may legitimately remove
     * all hazards (e.g. for a very low-risk scope) and the document must still generate.
     *
     * @throws \RuntimeException
     */
    private function assertMinimum(array $data): void
    {
        foreach (['ppe', 'persons_at_risk'] as $key) {
            if (empty($data[$key])) {
                throw new \RuntimeException(
                    "RAMS data assembly failed: '{$key}' is empty after normalisation."
                );
            }
        }

        if (empty($data['hazards'])) {
            Log::warning('RamsDataBuilderService: hazards array is empty after normalisation. Document will be generated without a hazard register.');
        }

        if (empty($data['project']['ref']) && empty($data['project']['name'])) {
            throw new \RuntimeException(
                'RAMS data assembly failed: project has neither a ref nor a name.'
            );
        }

        if (empty($data['method_statement']['phases'])) {
            Log::warning('RamsDataBuilderService: method statement phases are empty after normalisation.');
        }
    }
}
