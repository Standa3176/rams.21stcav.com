<?php

namespace App\Services;

use App\Models\ProjectPackage;

/**
 * Build a RAMS review payload from a reviewed ProjectPackage.
 *
 * This uses the reviewed quote data (equipment list + project fields)
 * and fills any missing RAMS-specific sections with safe defaults.
 */
class ProjectPackageRamsReviewService
{
    public function __construct(
        private readonly EquipmentClassifierService  $classifier,
        private readonly RiskTemplateResolverService $riskResolver,
    ) {}

    public function build(ProjectPackage $package): array
    {
        $project = $package->project;
        $data    = $package->extracted_data ?? [];

        $equipmentRaw = $package->equipment_list
            ?? ($data['equipment_list'] ?? $data['equipment'] ?? []);

        $equipment = $this->normaliseEquipment($equipmentRaw);
        $equipment = $this->filterHardware($equipment);

        // Activities
        $activities = $this->normaliseActivities($data['activities'] ?? []);
        $classified = $this->classifier->classify($this->toClassifierItems($equipment));

        if (empty($activities)) {
            $activities = $this->activitiesFromClassifier($classified['activities'] ?? []);
        }

        // Hazards / PPE / Access
        $hazards = $this->normaliseHazards($data['hazards'] ?? []);
        $ppe     = $this->normaliseStringArray($data['ppe'] ?? []);
        $access  = is_array($data['access'] ?? null) ? $data['access'] : null;

        if (empty($hazards) || empty($ppe) || $access === null) {
            $risk = $this->riskResolver->resolve(
                $classified['activities'] ?? array_column($activities, 'key'),
                (bool) ($classified['drilling_required'] ?? false),
            );

            if (empty($hazards)) {
                $hazards = $this->hazardsFromRiskMatrix($risk['hazards'] ?? []);
            }
            if (empty($ppe)) {
                $ppe = $this->normaliseStringArray($risk['ppe'] ?? []);
            }
            if ($access === null) {
                $access = $this->accessFromEquipment($risk['access_equipment'] ?? []);
            }
        }

        $projectName = $project?->name ?? ($data['project_name'] ?? 'AV Installation');
        $quoteRef    = $project?->ref ?? ($data['qw_number'] ?? $data['quote_ref'] ?? '');
        $clientName  = $project?->client_name ?? ($data['client_name'] ?? '');
        $siteAddress = $project?->site_address ?? ($data['site_address'] ?? '');

        return [
            'project' => [
                'project_name' => (string) $projectName,
                'quote_ref'    => (string) $quoteRef,
                'client_name'  => (string) $clientName,
                'site_name'    => (string) ($data['site_name'] ?? ''),
                'site_address' => (string) $siteAddress,
                'prepared_by'  => (string) ($data['prepared_by'] ?? config('rams.company_name', '21st Century AV Ltd')),
                'overview'     => (string) ($data['overview'] ?? ''),
            ],
            'equipment'              => $equipment,
            'activities'             => $activities,
            'hazards'                => $hazards,
            'ppe'                    => $ppe,
            'access'                 => $access ?? $this->accessFromEquipment([]),
            'method_statement_notes' => (string) ($package->works_description ?? $data['works_description'] ?? $project?->works_description ?? ''),
            'meta'                   => [
                'parser_confidence' => isset($data['meta']['parser_confidence']) ? (float) $data['meta']['parser_confidence'] : 1.0,
                'source'            => 'reviewed',
            ],
        ];
    }

    private function normaliseEquipment(array $raw): array
    {
        $items = [];
        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }

            $name = (string) ($item['name'] ?? $item['description'] ?? $item['model'] ?? '');
            if (trim($name) === '') {
                continue;
            }

            $category = $this->normaliseCategory($item, $name);

            $items[] = [
                'quantity'    => max(1, (int) ($item['quantity'] ?? $item['qty'] ?? 1)),
                'part_number' => (string) ($item['part_number'] ?? $item['part_no'] ?? $item['sku'] ?? ''),
                'name'        => $name,
                'area'        => (string) ($item['area'] ?? $item['location'] ?? $item['room'] ?? ''),
                'category'    => $category,
            ];
        }

        return $items;
    }

    private function normaliseCategory(array $item, string $name): string
    {
        $category = strtolower(trim((string) ($item['category'] ?? '')));
        if ($category !== '') {
            return $category;
        }

        $desc = strtolower($name . ' ' . (string) ($item['description'] ?? ''));

        if ($this->containsAny($desc, ['optional', '*option', 'option'])) {
            return 'option';
        }
        if ($this->containsAny($desc, ['cable', 'cat5', 'cat6', 'cat6a', 'hdmi', 'usb', 'patch lead', 'ethernet'])) {
            return 'cables';
        }
        if ($this->containsAny($desc, ['consumable', 'fixing', 'screw', 'bolt', 'tie', 'velcro', 'label', 'tape'])) {
            return 'consumables';
        }
        if ($this->containsAny($desc, ['install', 'installation', 'commission', 'programming', 'configuration', 'survey', 'project management', 'travel'])) {
            return 'services';
        }

        return 'hardware';
    }

    private function filterHardware(array $items): array
    {
        return array_values(array_filter($items, function (array $item) {
            $category = strtolower(trim((string) ($item['category'] ?? '')));
            return $category === '' || $category === 'hardware';
        }));
    }

    private function toClassifierItems(array $equipment): array
    {
        return array_map(
            fn ($e) => [
                'qty'         => (int) ($e['quantity'] ?? 1),
                'description' => (string) ($e['name'] ?? ''),
                'location'    => (string) ($e['area'] ?? ''),
            ],
            $equipment
        );
    }

    private function normaliseActivities(array $raw): array
    {
        if (empty($raw)) {
            return [];
        }

        $activities = [];
        foreach ($raw as $item) {
            if (is_array($item)) {
                $key   = (string) ($item['key'] ?? '');
                $label = (string) ($item['label'] ?? '');
                if ($key !== '' || $label !== '') {
                    $activities[] = [
                        'key'   => $key !== '' ? $key : $this->slugify($label),
                        'label' => $label !== '' ? $label : $this->labelFromKey($key),
                    ];
                }
            } elseif (is_string($item)) {
                $key = trim($item);
                if ($key !== '') {
                    $activities[] = [
                        'key'   => $key,
                        'label' => $this->labelFromKey($key),
                    ];
                }
            }
        }

        return $activities;
    }

    private function activitiesFromClassifier(array $keys): array
    {
        $activities = [];
        foreach ($keys as $key) {
            $key = (string) $key;
            if ($key === '') {
                continue;
            }
            $activities[] = [
                'key'   => $key,
                'label' => $this->labelFromKey($key),
            ];
        }

        if (empty($activities)) {
            $activities[] = [
                'key'   => 'installation',
                'label' => 'Installation Works',
            ];
        }

        return $activities;
    }

    private function labelFromKey(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            return '';
        }

        return $this->classifier->activityLabel($key);
    }

    private function normaliseHazards(array $raw): array
    {
        if (empty($raw)) {
            return [];
        }

        $hazards = [];
        foreach ($raw as $h) {
            if (! is_array($h)) {
                continue;
            }

            if (! empty($h['hazard']) && ! empty($h['control_measures'])) {
                $hazards[] = [
                    'activity_key'     => (string) ($h['activity_key'] ?? ''),
                    'hazard'           => (string) $h['hazard'],
                    'risk'             => (string) ($h['risk'] ?? 'Medium'),
                    'control_measures' => $this->normaliseStringArray($h['control_measures'] ?? []),
                ];
                continue;
            }

            if (! empty($h['hazard']) && ! empty($h['controls'])) {
                $score = ((int) ($h['pre_likelihood'] ?? 3)) * ((int) ($h['pre_severity'] ?? 3));
                $hazards[] = [
                    'activity_key'     => '',
                    'hazard'           => (string) $h['hazard'],
                    'risk'             => $this->riskLabelFromScore($score),
                    'control_measures' => $this->normaliseStringArray($h['controls'] ?? []),
                ];
            }
        }

        return $hazards;
    }

    private function hazardsFromRiskMatrix(array $hazards): array
    {
        $out = [];
        foreach ($hazards as $h) {
            if (! is_array($h) || empty($h['hazard'])) {
                continue;
            }
            $score = ((int) ($h['pre_likelihood'] ?? 3)) * ((int) ($h['pre_severity'] ?? 3));
            $out[] = [
                'activity_key'     => '',
                'hazard'           => (string) $h['hazard'],
                'risk'             => $this->riskLabelFromScore($score),
                'control_measures' => $this->normaliseStringArray($h['controls'] ?? []),
            ];
        }

        return $out;
    }

    private function riskLabelFromScore(int $score): string
    {
        if ($score <= 3) {
            return 'Low';
        }
        if ($score <= 6) {
            return 'Medium';
        }
        return 'High';
    }

    private function accessFromEquipment(array $accessEquipment): array
    {
        $accessEquipment = array_map('strtolower', array_map('strval', $accessEquipment));

        return [
            'ladders'          => $this->containsAny(implode(' ', $accessEquipment), ['podium', 'kick stool', 'ladder']),
            'tower'            => $this->containsAny(implode(' ', $accessEquipment), ['tower']),
            'scissor_lift'     => $this->containsAny(implode(' ', $accessEquipment), ['scissor']),
            'out_of_hours'     => false,
            'live_environment' => false,
        ];
    }

    private function normaliseStringArray(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter(
            array_map('strval', $raw),
            fn (string $s) => strlen(trim($s)) > 0,
        ));
    }

    private function slugify(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]+/i', '_', $text);
        return trim($text ?? '', '_');
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
}

