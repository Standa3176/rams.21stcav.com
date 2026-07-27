<?php

namespace App\Support\Rams\Sections;

/**
 * Section 7 — Emergency Procedures.
 *
 * Site-specific first-aid + fire + accident info plus the RIDDOR
 * classification grid.
 *
 * The nine canonical site_emergency keys the review form + blades read
 * (see resources/views/pdf/rams.blade.php:1956+ and rams-v2.blade.php
 * mirror). The DTO enforces `''` defaults on every one so no caller ever
 * hits an "Undefined array key" warning under PHP 8.4 error-mode:
 *
 *   1. nearest_hospital
 *   2. fire_assembly_point
 *   3. fire_warden               (aka fire_warden_name on legacy records)
 *   4. fire_warden_contact
 *   5. first_aider               (aka first_aider_name on legacy records)
 *   6. first_aider_contact
 *   7. defibrillator             (aka defibrillator_location on legacy records)
 *   8. electrical_isolation_switch
 *   9. fire_extinguisher_class
 *
 * Plus the list-shaped fields (fed from reviewed_data siblings, not
 * from the site_emergency map):
 *
 * emergency_contacts — [ ['role' => 'First Aider', 'name' => '...',
 *                         'phone' => '...'] ]
 * accident_procedure — ordered numbered-list bullets.
 * fire_procedure     — ordered numbered-list bullets.
 * riddor_matrix      — [ ['category' => 'Fatality', 'action' => '...'] ]
 *
 * Populated by RamsDocumentComposer (Plan 02) from reviewed_data.emergency
 * via EmergencyComposer.
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
        public string $nearestHospital       = '',
        public string $fireAssemblyPoint     = '',
        public string $fireWarden            = '',
        public string $fireWardenContact     = '',
        public string $firstAider            = '',
        public string $firstAiderContact     = '',
        public string $defibrillator         = '',
        public string $isolationSwitch       = '',
        public string $fireExtinguisherClass = '',
        public array  $emergencyContacts     = [],
        public array  $accidentProcedure     = [],
        public array  $fireProcedure         = [],
        public array  $riddorMatrix          = [],
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
            nearestHospital:       (string) ($data['nearest_hospital']    ?? ''),
            fireAssemblyPoint:     (string) ($data['fire_assembly_point'] ?? ''),
            fireWarden:            (string) ($data['fire_warden']         ?? ''),
            fireWardenContact:     (string) ($data['fire_warden_contact'] ?? ''),
            firstAider:            (string) ($data['first_aider']         ?? ''),
            firstAiderContact:     (string) ($data['first_aider_contact'] ?? ''),
            defibrillator:         (string) ($data['defibrillator']       ?? ''),
            // Accept both the canonical `electrical_isolation_switch` key
            // (matches blade + controller) and the legacy short `isolation_switch`
            // alias still used by early Plan 02 composer fixtures.
            isolationSwitch:       (string) ($data['electrical_isolation_switch']
                                              ?? ($data['isolation_switch'] ?? '')),
            fireExtinguisherClass: (string) ($data['fire_extinguisher_class'] ?? ''),
            emergencyContacts:     $contacts,
            accidentProcedure:     $stringList($data['accident_procedure'] ?? []),
            fireProcedure:         $stringList($data['fire_procedure']     ?? []),
            riddorMatrix:          $matrix,
        );
    }

    public function isEmpty(): bool
    {
        return $this->nearestHospital === ''
            && $this->fireAssemblyPoint === ''
            && $this->fireWarden === ''
            && $this->fireWardenContact === ''
            && $this->firstAider === ''
            && $this->firstAiderContact === ''
            && $this->defibrillator === ''
            && $this->isolationSwitch === ''
            && $this->fireExtinguisherClass === ''
            && $this->emergencyContacts === []
            && $this->accidentProcedure === []
            && $this->fireProcedure === []
            && $this->riddorMatrix === [];
    }
}
