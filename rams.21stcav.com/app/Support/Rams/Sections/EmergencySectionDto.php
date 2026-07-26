<?php

namespace App\Support\Rams\Sections;

/**
 * Section 7 — Emergency Procedures.
 *
 * Site-specific first-aid + fire + accident info plus the RIDDOR
 * classification grid.
 *
 * emergency_contacts — [ ['role' => 'First Aider', 'name' => '...',
 *                         'phone' => '...'] ]
 * accident_procedure — ordered numbered-list bullets.
 * fire_procedure     — ordered numbered-list bullets.
 * riddor_matrix      — [ ['category' => 'Fatality', 'action' => '...'] ]
 *
 * Populated by RamsDocumentComposer (Plan 02) from reviewed_data.emergency.
 */
final readonly class EmergencySectionDto
{
    /**
     * @param  array<int, array<string, string>>  $emergencyContacts
     * @param  array<int, string>                 $accidentProcedure
     * @param  array<int, string>                 $fireProcedure
     * @param  array<int, array<string, string>>  $riddorMatrix
     */
    public function __construct(
        public string $nearestHospital   = '',
        public string $fireAssemblyPoint = '',
        public string $fireWarden        = '',
        public string $firstAider        = '',
        public string $defibrillator     = '',
        public string $isolationSwitch   = '',
        public array  $emergencyContacts = [],
        public array  $accidentProcedure = [],
        public array  $fireProcedure     = [],
        public array  $riddorMatrix      = [],
    ) {}

    public static function fromArray(array $data): self
    {
        $stringList = static fn (mixed $v): array => array_values(array_map('strval', (array) $v));

        $contacts = [];
        foreach ((array) ($data['emergency_contacts'] ?? []) as $c) {
            $c = (array) $c;
            $contacts[] = [
                'role'  => (string) ($c['role']  ?? ''),
                'name'  => (string) ($c['name']  ?? ''),
                'phone' => (string) ($c['phone'] ?? ''),
            ];
        }

        $matrix = [];
        foreach ((array) ($data['riddor_matrix'] ?? []) as $m) {
            $m = (array) $m;
            $matrix[] = [
                'category' => (string) ($m['category'] ?? ''),
                'action'   => (string) ($m['action']   ?? ''),
            ];
        }

        return new self(
            nearestHospital:   (string) ($data['nearest_hospital']    ?? ''),
            fireAssemblyPoint: (string) ($data['fire_assembly_point'] ?? ''),
            fireWarden:        (string) ($data['fire_warden']         ?? ''),
            firstAider:        (string) ($data['first_aider']         ?? ''),
            defibrillator:     (string) ($data['defibrillator']       ?? ''),
            isolationSwitch:   (string) ($data['isolation_switch']    ?? ''),
            emergencyContacts: $contacts,
            accidentProcedure: $stringList($data['accident_procedure'] ?? []),
            fireProcedure:     $stringList($data['fire_procedure']     ?? []),
            riddorMatrix:      $matrix,
        );
    }

    public function isEmpty(): bool
    {
        return $this->nearestHospital === ''
            && $this->fireAssemblyPoint === ''
            && $this->fireWarden === ''
            && $this->firstAider === ''
            && $this->defibrillator === ''
            && $this->isolationSwitch === ''
            && $this->emergencyContacts === []
            && $this->accidentProcedure === []
            && $this->fireProcedure === []
            && $this->riddorMatrix === [];
    }
}
