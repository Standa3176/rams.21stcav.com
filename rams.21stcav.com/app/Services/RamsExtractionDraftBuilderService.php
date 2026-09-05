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

        // Scope narrative feeds HazardIncludeWhenResolver's tier-2 keyword
        // matching (Phase 26 HAZ-02) — built from already-parsed/validated
        // text, not new raw user input.
        $scopeNarrative = trim(implode(' ', array_filter([
            (string) ($parsed['works_summary'] ?? ''),
            (string) ($formData['works_description'] ?? ''),
            implode(' ', array_column($parsed['equipment'] ?? [], 'description')),
        ])));

        $risk       = $this->riskResolver->resolve(
            $classified['activities'],
            $classified['drilling_required'] ?? false,
            null,
            [],
            [],
            $scopeNarrative,
        );

        return [
            'project'  => $this->buildProject($parsed, $formData),
            'equipment'=> $this->buildEquipment($parsed['equipment'] ?? []),
            'activities'=> $this->buildActivities($classified['activities'] ?? []),
            'hazards'  => $this->buildHazards($risk['hazards'] ?? []),
            'ppe'      => $risk['ppe'] ?? [],
            'access'   => $this->buildAccess($risk['access_equipment'] ?? []),
            'method_statement_notes' => (string) ($formData['works_description'] ?? ''),
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
        $siteName    = ($formData['site_name'] ?? '') ?: ($parsed['site_name'] ?? '');
        if ($siteName === '') {
            $siteName = $projectName !== '' ? $projectName : $client;
        }

        // Phase 22.1 D-09: the `overview` key is dropped from the canonical
        // project array. The raw QuoteWerks overview prose still lives at the
        // top-level `extracted_data['overview']` (written by buildOverview() /
        // QuoteParserService) — it is no longer mirrored inside the `project`
        // sub-array. RamsReviewDataService::normaliseProject() also drops the
        // key on the read side, so the round-trip stays clean.
        return [
            'project_name' => $projectName,
            'quote_ref'    => $ref,
            'client_name'  => $client,
            'site_name'    => $siteName,
            'site_address' => $siteAddress,
            'prepared_by'  => $preparedBy,
        ];
    }

    private function buildEquipment(array $items): array
    {
        return array_values(array_map(
            fn ($item) => [
                'quantity'    => max(1, (int) ($item['qty'] ?? 1)),
                'part_number' => (string) ($item['part_number'] ?? ''),
                'name'        => (string) ($item['description'] ?? ''),
                'area'        => (string) ($item['area'] ?? ''),
            ],
            $items,
        ));
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
            return [
                'activity_key'       => '',
                'hazard'             => (string) ($h['hazard'] ?? ''),
                'pre_likelihood'     => max(1, (int) ($h['pre_likelihood']  ?? 3)),
                'pre_severity'       => max(1, (int) ($h['pre_severity']    ?? 3)),
                'post_likelihood'    => max(1, (int) ($h['post_likelihood'] ?? 1)),
                'post_severity'      => max(1, (int) ($h['post_severity']   ?? 2)),
                'needs_confirmation' => (bool) ($h['needs_confirmation'] ?? false),
                'score_reviewed'     => false,
                // A freshly extracted draft has never been reviewed by anyone.
                'controls_reviewed'  => false,
                'control_measures'   => array_values(array_filter(
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

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

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
