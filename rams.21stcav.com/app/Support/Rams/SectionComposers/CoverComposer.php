<?php

namespace App\Support\Rams\SectionComposers;

use App\Models\RamsDocument;
use App\Support\Rams\Sections\CoverSectionDto;
use Illuminate\Contracts\Config\Repository;

/**
 * Composes the cover-page DTO from a post-patch RamsDocument.
 *
 * Mirrors the exact resolution chain currently duplicated across
 * DocxBuilderService::buildCoverPage() (lines 200-280) and
 * resources/views/pdf/rams.blade.php (lines 355-400).
 *
 * Order-of-operations invariant: this composer MUST run AFTER
 * RamsDisplayPatchService::patch() — the personnel-resolution and
 * client-contact-inference chains it depends on live in the patch
 * service, not here. RamsDocumentComposer enforces the ordering.
 *
 * Never mutates $record; never calls save().
 */
final class CoverComposer
{
    public function __construct(
        private readonly Repository $config,
    ) {}

    public function compose(RamsDocument $record): CoverSectionDto
    {
        $gd       = $record->generated_data ?? [];
        $rd       = $record->reviewed_data  ?? [];
        $formData = $record->form_data      ?? [];
        $project  = (array) ($gd['project'] ?? []);

        // ── Client / site / ref — patch service has already merged live
        //    Project record + model-column fallback into $project.
        $client     = (string) ($project['client']       ?? ($record->client_name  ?? ''));
        $site       = (string) ($project['site_address'] ?? ($record->site_address ?? ''));
        $projectRef = (string) ($project['ref']          ?? ($record->project_ref  ?? ''));

        // ── Rooms — priority chain (matches DocxBuilder::resolveRoomsList).
        $roomsList = $this->resolveRoomsList($record, $project);

        // ── Document date — the actual RAMS creation date (matches
        //    both renderers), never the AI "F Y" placeholder.
        $docDate = $record->created_at?->format('d/m/Y') ?: now()->format('d/m/Y');

        // ── Programme / dates
        $programme       = (array) ($rd['programme'] ?? []);
        $plannedStart    = $this->formatDate((string) ($project['planned_start_date'] ?? ''));
        $plannedEnd      = $this->formatDate((string) ($project['planned_end_date']   ?? ''));
        $plannedStartTime = (string) ($project['planned_start_time'] ?? ($programme['planned_start_time'] ?? ''));
        $plannedEndTime   = (string) ($project['planned_end_time']   ?? ($programme['planned_end_time']   ?? ''));

        $workingHours = (string) (($project['working_hours'] ?? '') ?: ($formData['working_hours'] ?? 'Monday–Friday, 09:00–17:30'));

        // ── Personnel — patch service has already resolved these; we just
        //    pull them off $project. doc_author → preparedBy.
        $preparedBy      = (string) (($project['doc_author']       ?? '') ?: ($project['project_manager'] ?? ''));
        $projectManager  = (string) ($project['project_manager']       ?? '');
        $pmPhone         = (string) (($project['project_manager_phone'] ?? '') ?: $this->config->get('rams.company_phone', ''));

        $leadEngineer    = (string) ($project['lead_engineer'] ?? '');
        $additionalRaw   = $project['additional_engineers'] ?? '';
        $additionalList  = $this->normaliseCsv($additionalRaw);

        $programmer      = (string) ($project['programmer'] ?? '');

        // ── Vehicles — patch service reshapes site_vehicles to newline-joined
        //    string. Fall back to programme array. Empty → '—' handled by renderer.
        $vehSrc = $project['site_vehicles'] ?? ($gd['site_vehicles'] ?? null);
        if ($vehSrc === null || $vehSrc === '') {
            $vehSrc = $programme['site_vehicles'] ?? [];
        }
        $vehicles = $this->normaliseCsv($vehSrc, splitOnComma: false);

        // ── Client contact
        $clientContactName  = (string) ($project['client_contact_name']  ?? '');
        $clientContactEmail = (string) ($project['client_contact_email'] ?? '');
        $clientContactPhone = (string) ($project['client_contact_phone'] ?? '');

        $revision = (string) ($project['revision']        ?? 'Rev 1.0');
        $status   = (string) ($project['document_status'] ?? 'For Issue');

        return new CoverSectionDto(
            client:              $client,
            site:                $site,
            projectRef:          $projectRef,
            rooms:               $roomsList,
            date:                $docDate,
            startDate:           $plannedStart !== '' ? $plannedStart : $plannedStartTime,
            endDate:             $plannedEnd   !== '' ? $plannedEnd   : $plannedEndTime,
            workingHours:        $workingHours,
            preparedBy:          $preparedBy,
            telephone:           (string) $this->config->get('rams.company_phone', ''),
            clientContactName:   $clientContactName,
            clientContactEmail:  $clientContactEmail,
            clientContactPhone:  $clientContactPhone,
            revision:            $revision,
            status:              $status,
            projectManager:      $projectManager,
            projectManagerPhone: $pmPhone,
            leadEngineer:        $leadEngineer,
            additionalEngineers: $additionalList,
            programmer:          $programmer,
            vehicles:            $vehicles,
        );
    }

    /**
     * Rooms resolution chain — mirrors DocxBuilderService::resolveRoomsList
     * and rams.blade.php:324-345. Non-physical-space entries (cabling,
     * services, warranty, etc.) are filtered out.
     *
     * @return array<int, string>
     */
    private function resolveRoomsList(RamsDocument $record, array $project): array
    {
        $excludeRe = '/\b(licen[cs]|cabling|cables?|wiring|network|software|service|warranty|support|delivery|carriage)\b/i';
        $filter    = fn ($r) => is_string($r) && $r !== '' && ! preg_match($excludeRe, $r);
        $rd        = $record->reviewed_data ?? [];
        $list      = [];

        // 1. reviewed_data['rooms']
        foreach ((array) ($rd['rooms'] ?? []) as $r) {
            $name = is_array($r) ? (string) ($r['name'] ?? ($r['room_name'] ?? '')) : (string) $r;
            if ($filter($name)) {
                $list[] = $name;
            }
        }

        // 2. reviewed_data['room_overviews'][n]['room']
        if ($list === []) {
            foreach ((array) ($rd['room_overviews'] ?? []) as $ro) {
                if (! is_array($ro)) {
                    continue;
                }
                $name = (string) ($ro['room'] ?? ($ro['room_name'] ?? ($ro['name'] ?? '')));
                if ($filter($name)) {
                    $list[] = $name;
                }
            }
        }

        // 3. $project['rooms'] (legacy generated_data array)
        if ($list === []) {
            foreach ((array) ($project['rooms'] ?? []) as $r) {
                $name = (string) $r;
                if ($filter($name)) {
                    $list[] = $name;
                }
            }
        }

        // 4. $project['rooms_text'] parser fallback
        if ($list === []) {
            $roomsText = (string) ($project['rooms_text'] ?? '');
            if ($roomsText !== '') {
                foreach (array_map('trim', preg_split('/[,\n]+/', $roomsText) ?: []) as $name) {
                    if ($filter($name)) {
                        $list[] = $name;
                    }
                }
            }
        }

        return array_values(array_unique($list));
    }

    /**
     * Normalise a value that may be string / array / null into an ordered
     * list of trimmed non-empty strings. Splits strings on commas by default;
     * newlines always split.
     *
     * @return array<int, string>
     */
    private function normaliseCsv(mixed $v, bool $splitOnComma = true): array
    {
        if ($v === null) {
            return [];
        }
        if (is_string($v)) {
            $pattern = $splitOnComma ? '/[,\r\n]+/' : '/\r?\n/';
            $v = preg_split($pattern, $v) ?: [];
        }
        $out = [];
        foreach ((array) $v as $item) {
            $s = trim((string) $item);
            if ($s !== '') {
                $out[] = $s;
            }
        }
        return array_values($out);
    }

    private function formatDate(string $d): string
    {
        if ($d === '') {
            return '';
        }
        try {
            return \Carbon\Carbon::parse($d)->format('d/m/Y');
        } catch (\Throwable $e) {
            return $d;
        }
    }
}
