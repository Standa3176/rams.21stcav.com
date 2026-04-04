<?php

namespace App\Services;

/**
 * Phase A orchestrator — builds the extracted_data blob in the canonical
 * review schema from a raw PDF text and optional form overrides.
 *
 * Called exclusively by ExtractRamsDraftJob. Produces the structured draft
 * that the user reviews and corrects before generation is authorised.
 *
 * No AI calls. No database access. No Eloquent models.
 *
 * Output shape (canonical review schema):
 * [
 *   'project'  => ['project_name','quote_ref','client_name','site_name','site_address','prepared_by'],
 *   'equipment'=> [['quantity'=>int,'name'=>string], ...],
 *   'activities'=> [['key'=>string,'label'=>string], ...],
 *   'hazards'  => [['activity_key'=>'','hazard'=>string,'risk'=>string,'control_measures'=>[]], ...],
 *   'ppe'      => string[],
 *   'access'   => ['ladders'=>bool,'tower'=>bool,'scissor_lift'=>bool,'out_of_hours'=>bool,'live_environment'=>bool],
 *   'method_statement_notes' => string,
 *   'room_overviews' => [['room'=>string,'overview'=>string,'summary'=>string], ...],
 *   'meta'     => ['parser_confidence'=>float|null,'source'=>'extracted'],
 * ]
 */
class RamsExtractionDraftBuilderService
{
    public function __construct(
        private readonly QuoteParserService          $quoteParser,
        private readonly EquipmentClassifierService  $classifier,
        private readonly RiskTemplateResolverService $riskResolver,
    ) {}

    // =========================================================================
    // PUBLIC ENTRY POINT
    // =========================================================================

    /**
     * Build the review-ready extracted_data from raw PDF text + optional form data.
     *
     * @param  string  $extractedText  Raw text from QuoteTextExtractorService
     * @param  array   $formData       Optional form overrides from form_data column
     * @return array                   Canonical review schema
     */
    public function build(string $extractedText, array $formData = []): array
    {
        $parsed     = $this->quoteParser->parse($extractedText);
        $classified = $this->classifier->classify($parsed['equipment'] ?? []);
        $risk       = $this->riskResolver->resolve(
            $classified['activities'],
            $classified['drilling_required'] ?? false,
        );

        return [
            'project'  => $this->buildProject($parsed, $formData),
            'equipment'=> $this->buildEquipment($parsed['equipment'] ?? []),
            'activities'=> $this->buildActivities($classified['activities'] ?? []),
            'hazards'  => $this->buildHazards($risk['hazards'] ?? []),
            'ppe'      => $risk['ppe'] ?? [],
            'access'   => $this->buildAccess($risk['access_equipment'] ?? []),
            'method_statement_notes' => (string) ($formData['works_description'] ?? ''),
            'room_overviews' => $this->buildRoomOverviews($parsed),
            'meta'     => [
                'parser_confidence' => $parsed['confidence'] ?? null,
                'source'            => 'extracted',
            ],
        ];
    }

    // =========================================================================
    // PRIVATE BUILDERS
    // =========================================================================

    private function buildProject(array $parsed, array $formData): array
    {
        $ref    = ($formData['project_ref']  ?? '') ?: ($parsed['ref']    ?? '');
        $client = ($formData['client_name']  ?? '') ?: ($parsed['client'] ?? '');

        // Auto-generate project name as "{ref} – {client}" when no explicit
        // project name was supplied via the upload form. This gives every RAMS
        // document a meaningful name without requiring the user to type one.
        $projectName = ($formData['project_name'] ?? '') ?: ($parsed['project_name'] ?? '');
        if ($projectName === '') {
            if ($ref !== '' && $ref !== 'RAMS-001' && $client !== '') {
                $projectName = $ref . ' – ' . $client;
            } elseif ($ref !== '' && $ref !== 'RAMS-001') {
                $projectName = $ref;
            } elseif ($client !== '') {
                $projectName = $client;
            }
        }

        // Prepared by: prefer the upload form's doc_author override, then fall
        // back to what the parser extracted from the PDF ("Prepared by: ..." etc.)
        $preparedBy = ($formData['doc_author'] ?? '') ?: ($parsed['prepared_by'] ?? '');

        $siteAddress = ($formData['site_address'] ?? '') ?: ($parsed['site'] ?? '');

        // Use the dedicated site_name from the parser (SITENAMESTART tag) when
        // available; fall back to the client name if not present.
        $siteName = ($formData['site_name'] ?? '') ?: ($parsed['site_name'] ?? '') ?: $client;

        return [
            'project_name' => $projectName,
            'quote_ref'    => $ref,
            'client_name'  => $client,
            'site_name'    => $siteName,
            'site_address' => $siteAddress,
            'prepared_by'  => $preparedBy,
            // Overview text extracted from the QuoteWerks Overview section.
            // Stored here so it is visible and editable in the review form,
            // but NEVER used as a source of equipment items.
            'overview'     => ($formData['overview'] ?? '') ?: ($parsed['overview'] ?? ''),
        ];
    }

    private function buildEquipment(array $items): array
    {
        return array_values(array_map(
            function ($item) {
                $description = (string) ($item['description'] ?? '');
                $partNumber  = (string) ($item['part_number'] ?? '');
                return [
                    'quantity'    => max(1, (int) ($item['qty'] ?? 1)),
                    'part_number' => $partNumber,
                    'name'        => $description,
                    'area'        => (string) ($item['area'] ?? ''),
                    'category'    => $this->detectCategory($description, $partNumber),
                ];
            },
            $items,
        ));
    }

    private function detectCategory(string $description, string $partNumber = ''): string
    {
        $text = strtolower($description . ' ' . $partNumber);

        foreach (['optional', 'option'] as $kw) {
            if (str_contains($text, $kw)) return 'option';
        }

        foreach (['consumable', 'fixing', 'fastener', 'rawlplug', 'anchor', 'screw', 'bolt', 'tape', 'label', 'cleat', 'tie', 'strap'] as $kw) {
            if (str_contains($text, $kw)) return 'consumables';
        }

        foreach (['cable', 'cat6', 'cat6a', 'cat5', 'hdmi', 'sdi', 'utp', 'ftp', 'stp', 'patch', 'lead', 'usb', 'fibre', 'fiber', 'rg6', 'rg59'] as $kw) {
            if (str_contains($text, $kw)) return 'cables';
        }

        foreach (['install', 'installation', 'commission', 'configuration', 'programming', 'labour', 'support', 'survey', 'management', 'training'] as $kw) {
            if (str_contains($text, $kw)) return 'services';
        }

        return 'hardware';
    }

    private function buildActivities(array $activityKeys): array
    {
        return array_values(array_map(
            fn ($key) => [
                'key'   => (string) $key,
                'label' => $this->classifier->activityLabel((string) $key),
            ],
            $activityKeys,
        ));
    }

    /**
     * Map full risk-matrix hazards to the simplified review schema.
     *
     * The 'activity_key' is left blank because hazards from the risk template
     * resolver are not activity-specific — the user can populate this field
     * during the review step if needed.
     */
    private function buildHazards(array $hazards): array
    {
        return array_values(array_map(function (array $h) {
            $likelihood = max(1, (int) ($h['pre_likelihood'] ?? 3));
            $severity   = max(1, (int) ($h['pre_severity']   ?? 3));

            return [
                'activity_key'     => '',
                'hazard'           => (string) ($h['hazard'] ?? ''),
                'risk'             => $this->riskLabel($likelihood, $severity),
                'control_measures' => array_values(array_filter(
                    array_map('strval', (array) ($h['controls'] ?? [])),
                    fn (string $s) => strlen(trim($s)) > 0,
                )),
            ];
        }, $hazards));
    }

    /**
     * Map access-equipment strings to the boolean access flags in the review schema.
     * All other flags default to false — the user sets them during review.
     */
    private function buildAccess(array $accessEquipment): array
    {
        return [
            'ladders'          => $this->accessContains($accessEquipment, ['Podium Steps', 'Step Ladder', 'Ladder']),
            'tower'            => $this->accessContains($accessEquipment, ['Tower', 'Access Tower']),
            'scissor_lift'     => false,
            'out_of_hours'     => false,
            'live_environment' => false,
        ];
    }

    /**
     * Build room overview entries from parsed overview sections and equipment areas.
     */
    private function buildRoomOverviews(array $parsed): array
    {
        $sections = (array) ($parsed['overview_sections'] ?? []);
        $map = [];

        foreach ($sections as $section) {
            $title = trim((string) ($section['title'] ?? ''));
            $text  = trim((string) ($section['text']  ?? ''));
            if ($text !== '' && $title !== '') {
                $text = $this->stripLeadingTitle($title, $text);
            }
            if ($title === '') {
                continue;
            }
            $map[mb_strtolower($title)] = [
                'room'     => $title,
                'overview' => $text,
                'summary'  => '',
            ];
        }

        foreach ((array) ($parsed['equipment'] ?? []) as $item) {
            $room = trim((string) ($item['area'] ?? ''));
            if ($room === '') {
                continue;
            }
            $key = mb_strtolower($room);
            if (! isset($map[$key])) {
                $map[$key] = [
                    'room'     => $room,
                    'overview' => '',
                    'summary'  => '',
                ];
            }
        }

        return array_values($map);
    }

    private function stripLeadingTitle(string $title, string $text): string
    {
        $lines = preg_split('/\r?\n/', $text);
        if (! $lines) {
            return $text;
        }

        $first = trim((string) $lines[0]);
        if ($first !== '' && strcasecmp($first, $title) === 0) {
            array_shift($lines);
        }

        return trim(implode("\n", $lines));
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    private function riskLabel(int $likelihood, int $severity): string
    {
        $score = $likelihood * $severity;
        if ($score >= 12) return 'High';
        if ($score >= 6)  return 'Medium';
        return 'Low';
    }

    private function accessContains(array $accessEquipment, array $keywords): bool
    {
        foreach ($accessEquipment as $item) {
            foreach ($keywords as $kw) {
                if (stripos((string) $item, $kw) !== false) {
                    return true;
                }
            }
        }
        return false;
    }
}
