<?php

namespace App\Http\Controllers;

use App\Models\ProjectPackage;
use App\Services\RamsReviewDataService;
use App\Services\RamsReviewValidatorService;
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
    ) {}

    public function show(ProjectPackage $package): View
    {
        $this->authorizePackage($package);

        $raw = $package->extracted_data ?? [];
        if (! empty($package->equipment_list)) {
            $raw['equipment'] = $package->equipment_list;
        } elseif (! empty($raw['equipment_list'])) {
            $raw['equipment'] = $raw['equipment_list'];
        }

        $reviewPayload = $this->reviewDataService->normalise($raw);
        $ppeOptions    = self::PPE_OPTIONS;

        return view('project-packages.review', compact('package', 'reviewPayload', 'ppeOptions'));
    }

    public function update(Request $request, ProjectPackage $package): RedirectResponse
    {
        $this->authorizePackage($package);

        $payload = $this->parseReviewPayload($request);

        // Carry over meta from extracted_data if not in payload.
        if (empty($payload['meta']) && ! empty($package->extracted_data['meta'])) {
            $payload['meta'] = $package->extracted_data['meta'];
        }

        $merged = array_merge($package->extracted_data ?? [], $payload);

        $package->update([
            'extracted_data' => $merged,
            'equipment_list' => $payload['equipment'] ?? [],
            'status'         => ProjectPackage::STATUS_REVIEWED,
        ]);

        // Update core project fields so all docs stay in sync
        if ($package->project) {
            $project = $package->project;
            $proj    = $payload['project'] ?? [];

            $project->update([
                'name'              => $proj['project_name'] ?? $project->name,
                'ref'               => $proj['quote_ref'] ?? $project->ref,
                'client_name'       => $proj['client_name'] ?? $project->client_name,
                'site_address'      => $proj['site_address'] ?? $project->site_address,
                'works_description' => $payload['method_statement_notes'] ?? $project->works_description,
            ]);
        }

        return redirect()
            ->route('project-packages.review.show', $package)
            ->with('success', 'Project data saved successfully.');
    }

    public function approve(Request $request, ProjectPackage $package): RedirectResponse
    {
        $this->authorizePackage($package);

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

        $package->update([
            'extracted_data' => $merged,
            'equipment_list' => $payload['equipment'] ?? [],
            'status'         => ProjectPackage::STATUS_REVIEWED,
        ]);

        if ($package->project) {
            $project = $package->project;
            $proj    = $payload['project'] ?? [];
            $project->update([
                'name'              => $proj['project_name'] ?? $project->name,
                'ref'               => $proj['quote_ref'] ?? $project->ref,
                'client_name'       => $proj['client_name'] ?? $project->client_name,
                'site_address'      => $proj['site_address'] ?? $project->site_address,
                'works_description' => $payload['method_statement_notes'] ?? $project->works_description,
            ]);
        }

        return redirect()
            ->route('projects.show', $package->project_id)
            ->with('success', 'Project data approved and ready for document generation.');
    }

    private function authorizePackage(ProjectPackage $package): void
    {
        abort_unless(
            $package->user_id === auth()->id() || auth()->user()?->role === 'admin',
            403
        );
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

        foreach (['install', 'installation', 'commission', 'configuration', 'programming', 'labour', 'support', 'survey', 'management', 'training'] as $kw) {
            if (str_contains($text, $kw)) {
                return 'services';
            }
        }

        return 'hardware';
    }

    private function slugify(string $label): string
    {
        return preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($label)));
    }
}
