<?php

namespace App\Http\Controllers;

use App\Models\RamsDocument;
use App\Services\ApproveRamsForGenerationService;
use App\Services\Rams\RamsDiffService;
use App\Services\RamsReviewDataService;
use App\Services\RamsReviewValidatorService;
use App\Services\RoomOverviewSummaryService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Handles the pre-generation review layer for quote-uploaded RAMS documents.
 *
 * Methods:
 *   show($id)    — render the review/edit form
 *   update($id)  — save edits to reviewed_data without triggering generation
 *   approve($id) — validate, persist reviewed_data, and dispatch Phase B generation
 *
 * All methods use the existing RamsDocumentPolicy (view / update) so no
 * new authorization policy is required.
 *
 * After approval, the user is redirected to the Project detail page when the
 * RAMS document has a project_id, otherwise to the RAMS index.
 */
class RamsReviewController extends Controller
{
    use AuthorizesRequests;

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
        private readonly RamsReviewDataService           $reviewDataService,
        private readonly RamsReviewValidatorService      $reviewValidator,
        private readonly ApproveRamsForGenerationService $approver,
        private readonly RoomOverviewSummaryService      $roomOverviewSummarizer,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // show — render the review form
    // ─────────────────────────────────────────────────────────────────────────

    public function show(RamsDocument $rams): View
    {
        $this->authorize('view', $rams);

        $reviewPayload = $this->reviewDataService->load($rams);
        $ppeOptions    = self::PPE_OPTIONS;

        // Diff: extracted vs reviewed for change highlighting (only when reviewed_data exists)
        $hasReviewed = ! empty($rams->reviewed_data);

        $diff = $hasReviewed
            ? RamsDiffService::diff(
                $rams->extracted_data ?? [],
                $rams->reviewed_data ?? [],
            )
            : ['changes' => [], 'summary' => ['total' => 0, 'added' => 0, 'modified' => 0, 'removed' => 0]];

        return view('rams.quote-review', compact('rams', 'reviewPayload', 'ppeOptions', 'diff'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // update — save reviewed_data without generating
    // ─────────────────────────────────────────────────────────────────────────

    public function update(Request $request, RamsDocument $rams): RedirectResponse
    {
        $this->authorize('update', $rams);

        if (in_array($rams->status, [
            RamsDocument::STATUS_APPROVED_FOR_GENERATION,
            RamsDocument::STATUS_GENERATING,
        ], true)) {
            return back()->with('error', 'This document is already queued for generation.');
        }

        $payload = $this->parseReviewPayload($request);

        // Carry over meta from extracted_data if not in payload.
        if (empty($payload['meta']) && ! empty($rams->extracted_data['meta'])) {
            $payload['meta'] = $rams->extracted_data['meta'];
        }

        $updates = ['reviewed_data' => $payload];
        if ($rams->status === RamsDocument::STATUS_COMPLETED) {
            // Mark as awaiting review so the UI shows this needs regeneration.
            $updates['status'] = RamsDocument::STATUS_AWAITING_REVIEW;
        }

        $rams->update($updates);

        return redirect()->route('rams.quote-review.show', $rams)
            ->with('success', 'Review data saved successfully. Review it below and click Approve & Generate when ready.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // approve — validate, approve, dispatch generation
    // ─────────────────────────────────────────────────────────────────────────

    public function approve(Request $request, RamsDocument $rams): RedirectResponse
    {
        $this->authorize('update', $rams);

        if (in_array($rams->status, [
            RamsDocument::STATUS_APPROVED_FOR_GENERATION,
            RamsDocument::STATUS_GENERATING,
        ], true)) {
            return back()->with('error', 'This document is already queued for generation. Please wait.');
        }

        $payload = $this->parseReviewPayload($request);

        // Carry over meta from extracted_data.
        if (empty($payload['meta']) && ! empty($rams->extracted_data['meta'])) {
            $payload['meta'] = $rams->extracted_data['meta'];
        }
        $payload['meta']['source'] = 'reviewed';

        try {
            $this->approver->approve($payload, $rams);
        } catch (ValidationException $e) {
            return back()
                ->withErrors($e->errors())
                ->withInput()
                ->with('error', 'Please fix the highlighted errors before approving.');
        }

        // Redirect to the project page if this RAMS is project-linked
        // and the project still exists (guard against orphaned project_id).
        $successMessage = 'RAMS data approved. Click Generate on the project page when ready.';

        if ($rams->project_id && $rams->project) {
            return redirect()
                ->route('projects.show', $rams->project_id)
                ->with('success', $successMessage);
        }

        return redirect()
            ->route('rams.index')
            ->with('success', $successMessage);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Parse and sanitise the raw request payload into the canonical review schema.
     *
     * - Strips Laravel-internal keys (_token, _method, _action).
     * - Converts control_measures from newline-separated textarea strings to arrays.
     * - Normalises access booleans (checkboxes not posted when unchecked).
     * - Re-indexes all repeater arrays (removes gaps left by removed rows).
     * - Enforces integer quantities.
     */
    private function parseReviewPayload(Request $request): array
    {
        $raw = $request->except(['_token', '_method', '_action']);

        // ── Equipment ─────────────────────────────────────────────────────────
        $equipment = [];
        foreach (array_values($raw['equipment'] ?? []) as $item) {
            if (empty($item['name']) && empty($item['quantity'])) {
                continue; // skip entirely empty rows
            }
            $category = $this->normaliseEquipmentCategory($item);
            $equipment[] = [
                'quantity'    => max(1, (int) ($item['quantity']    ?? 1)),
                'part_number' => trim((string) ($item['part_number'] ?? '')),
                'name'        => trim((string) ($item['name']        ?? '')),
                'area'        => trim((string) ($item['area']        ?? '')),
                'category'    => $category,
            ];
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
        // Phase 26-05 (HAZ-04): score clamping + the legacy risk-string bucket
        // fallback are delegated to RamsReviewDataService::normalise() below —
        // the SAME schema gate the review screen's GET/show() path already
        // uses — so save/approve and load can never drift out of sync on the
        // hazard row shape (pre/post likelihood+severity, score_reviewed,
        // needs_confirmation).
        $hazardsRaw = [];
        foreach (array_values($raw['hazards'] ?? []) as $h) {
            if (empty($h['hazard'])) {
                continue; // skip empty rows
            }

            // control_measures comes as a textarea string (one per line).
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

            $hazardsRaw[] = [
                'activity_key'       => trim((string) ($h['activity_key'] ?? '')),
                'hazard'             => trim((string) ($h['hazard'] ?? '')),
                'risk'               => $h['risk'] ?? null, // legacy fallback only — current form no longer submits this
                'pre_likelihood'     => $h['pre_likelihood']  ?? null,
                'pre_severity'       => $h['pre_severity']    ?? null,
                'post_likelihood'    => $h['post_likelihood'] ?? null,
                'post_severity'      => $h['post_severity']   ?? null,
                'score_reviewed'     => ($h['score_reviewed']     ?? '0') === '1',
                // Plan 27-08 — mirrors score_reviewed. Set by the review form
                // only when the controls textarea actually changed.
                'controls_reviewed'  => ($h['controls_reviewed']  ?? '0') === '1',
                'needs_confirmation' => ($h['needs_confirmation'] ?? '0') === '1',
                'control_measures'   => $cm,
            ];
        }
        $raw['hazards'] = $this->reviewDataService->normalise(['hazards' => $hazardsRaw])['hazards'];

        // ── PPE ───────────────────────────────────────────────────────────────
        $raw['ppe'] = array_values(array_filter(
            array_map('strval', (array) ($raw['ppe'] ?? [])),
            fn (string $s) => strlen(trim($s)) > 0,
        ));

        // ── Access (booleans — checkboxes not posted when unchecked) ──────────
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
            'site_contact' => trim((string) ($p['site_contact'] ?? '')),
            'prepared_by'  => trim((string) ($p['prepared_by']  ?? '')),
            'project_manager'      => trim((string) ($p['project_manager']      ?? '')),
            'lead_engineer'        => trim((string) ($p['lead_engineer']        ?? '')),
            'additional_engineers' => trim((string) ($p['additional_engineers'] ?? '')),
            'programmer'           => trim((string) ($p['programmer']           ?? '')),
            'site_vehicles'        => array_values(array_filter(
                array_map('trim', is_array($p['site_vehicles'] ?? null)
                    ? (array) $p['site_vehicles']
                    : preg_split('/\r?\n/', (string) ($p['site_vehicles'] ?? ''))),
                fn (string $s) => $s !== '',
            )),
            'overview'     => trim((string) ($p['overview']     ?? '')),
        ];

        // ── Method statement notes ────────────────────────────────────────────
        $raw['method_statement_notes'] = trim((string) ($raw['method_statement_notes'] ?? ''));

        // ── Room overviews (per space) ───────────────────────────────────────
        $roomOverviews = [];
        foreach (array_values($raw['room_overviews'] ?? []) as $row) {
            $room = trim((string) ($row['room'] ?? ''));
            if ($room === '') {
                continue;
            }
            $roomOverviews[] = [
                'room'     => $room,
                'overview' => trim((string) ($row['overview'] ?? '')),
                'summary'  => trim((string) ($row['summary']  ?? '')),
            ];
        }
        $raw['room_overviews'] = $roomOverviews;

        return $raw;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // summarize — run AI summaries for room overviews without generating
    // ─────────────────────────────────────────────────────────────────────────

    public function summarize(Request $request, RamsDocument $rams): RedirectResponse
    {
        $this->authorize('update', $rams);

        if (in_array($rams->status, [
            RamsDocument::STATUS_APPROVED_FOR_GENERATION,
            RamsDocument::STATUS_GENERATING,
        ], true)) {
            return back()->with('error', 'This document is already queued for generation. Please wait.');
        }

        $payload = $this->parseReviewPayload($request);

        // Carry over meta from extracted_data if not in payload.
        if (empty($payload['meta']) && ! empty($rams->extracted_data['meta'])) {
            $payload['meta'] = $rams->extracted_data['meta'];
        }

        $payload['room_overviews'] = $this->roomOverviewSummarizer
            ->summarize($payload['room_overviews'] ?? []);

        $updates = ['reviewed_data' => $payload];
        if ($rams->status === RamsDocument::STATUS_COMPLETED) {
            $updates['status'] = RamsDocument::STATUS_AWAITING_REVIEW;
        }

        $rams->update($updates);

        return redirect()->route('rams.quote-review.show', $rams)
            ->with('success', 'AI summaries generated. Review them below and click Approve when ready.');
    }

    /**
     * Normalise equipment category to one of: hardware, cables, consumables, services.
     * Falls back to keyword detection when missing/invalid.
     */
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

        $optionKeywords = ['optional', 'option'];
        foreach ($optionKeywords as $kw) {
            if (str_contains($text, $kw)) {
                return 'option';
            }
        }

        $consumableKeywords = ['consumable', 'fixing', 'fastener', 'rawlplug', 'anchor', 'screw', 'bolt', 'tape', 'label', 'cleat', 'tie', 'strap'];
        foreach ($consumableKeywords as $kw) {
            if (str_contains($text, $kw)) {
                return 'consumables';
            }
        }

        $cableKeywords = ['cable', 'cat6', 'cat6a', 'cat5', 'hdmi', 'sdi', 'utp', 'ftp', 'stp', 'patch', 'lead', 'usb', 'fibre', 'fiber', 'rg6', 'rg59'];
        foreach ($cableKeywords as $kw) {
            if (str_contains($text, $kw)) {
                return 'cables';
            }
        }

        $serviceKeywords = ['install', 'installation', 'commission', 'configuration', 'programming', 'labour', 'support', 'survey', 'management', 'training'];
        foreach ($serviceKeywords as $kw) {
            if (str_contains($text, $kw)) {
                return 'services';
            }
        }

        return 'hardware';
    }

    /**
     * Convert a label string to a slug key (lowercase, underscored).
     * Used when a new activity row has no explicit key.
     */
    private function slugify(string $label): string
    {
        return preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($label)));
    }
}
