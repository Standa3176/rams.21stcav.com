<?php

namespace App\Support\Rams\Sections;

/**
 * Cover-page fields for the RAMS front cover.
 *
 * Populated by RamsDocumentComposer (Plan 02) from RamsDocument's
 * reviewed_data / generated_data / form_data merged view.
 * Consumed by both the DOCX cover-page builder and the PDF cover Blade
 * partial (Plans 3+4). No renderer reads $rams->reviewed_data directly
 * once the phase 260726-rf3 refactor lands.
 */
final readonly class CoverSectionDto
{
    /**
     * @param  array<int, string>  $rooms                Room names shown on cover ("ROOMS:" row).
     * @param  array<int, string>  $additionalEngineers  Engineer names beyond the lead engineer.
     * @param  array<int, string>  $vehicles             Vehicle registrations / IDs used on site.
     */
    public function __construct(
        public string $client               = '',
        public string $site                 = '',
        public string $projectRef           = '',
        public array  $rooms                = [],
        public string $date                 = '',
        public string $startDate            = '',
        public string $endDate              = '',
        public string $workingHours         = '',
        public string $preparedBy           = '',
        public string $telephone            = '',
        public string $clientContactName    = '',
        public string $clientContactEmail   = '',
        public string $clientContactPhone   = '',
        public string $revision             = '',
        public string $status               = '',
        public string $projectManager       = '',
        public string $projectManagerPhone  = '',
        public string $leadEngineer         = '',
        public array  $additionalEngineers  = [],
        public string $programmer           = '',
        public array  $vehicles             = [],
    ) {}

    /**
     * Tolerant builder — accepts partial fixture maps and defaults every
     * unknown key to its type's empty value so tests don't need to fully
     * populate the DTO to exercise a single field.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            client:              (string) ($data['client']                ?? ''),
            site:                (string) ($data['site']                  ?? ''),
            projectRef:          (string) ($data['project_ref']           ?? ''),
            rooms:               array_values(array_map('strval', (array) ($data['rooms'] ?? []))),
            date:                (string) ($data['date']                  ?? ''),
            startDate:           (string) ($data['start_date']            ?? ''),
            endDate:             (string) ($data['end_date']              ?? ''),
            workingHours:        (string) ($data['working_hours']         ?? ''),
            preparedBy:          (string) ($data['prepared_by']           ?? ''),
            telephone:           (string) ($data['telephone']             ?? ''),
            clientContactName:   (string) ($data['client_contact_name']   ?? ''),
            clientContactEmail:  (string) ($data['client_contact_email']  ?? ''),
            clientContactPhone:  (string) ($data['client_contact_phone']  ?? ''),
            revision:            (string) ($data['revision']              ?? ''),
            status:              (string) ($data['status']                ?? ''),
            projectManager:      (string) ($data['project_manager']       ?? ''),
            projectManagerPhone: (string) ($data['project_manager_phone'] ?? ''),
            leadEngineer:        (string) ($data['lead_engineer']         ?? ''),
            additionalEngineers: array_values(array_map('strval', (array) ($data['additional_engineers'] ?? []))),
            programmer:          (string) ($data['programmer']            ?? ''),
            vehicles:            array_values(array_map('strval', (array) ($data['vehicles'] ?? []))),
        );
    }

    /**
     * True when nothing has been populated — renderer skips the section.
     */
    public function isEmpty(): bool
    {
        return $this->client === ''
            && $this->site === ''
            && $this->projectRef === ''
            && $this->date === ''
            && $this->startDate === ''
            && $this->endDate === ''
            && $this->workingHours === ''
            && $this->preparedBy === ''
            && $this->telephone === ''
            && $this->clientContactName === ''
            && $this->clientContactEmail === ''
            && $this->clientContactPhone === ''
            && $this->revision === ''
            && $this->status === ''
            && $this->projectManager === ''
            && $this->projectManagerPhone === ''
            && $this->leadEngineer === ''
            && $this->programmer === ''
            && $this->rooms === []
            && $this->additionalEngineers === []
            && $this->vehicles === [];
    }
}
