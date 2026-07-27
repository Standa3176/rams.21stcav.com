<?php

namespace Tests\Unit\Support\Rams\Sections;

use App\Support\Rams\Sections\EmergencySectionDto;
use PHPUnit\Framework\TestCase;

class EmergencySectionDtoTest extends TestCase
{
    public function test_construction_with_typical_emergency_data(): void
    {
        $dto = new EmergencySectionDto(
            nearestHospital:       'Watford General Hospital',
            fireAssemblyPoint:     'Car park by main gate',
            fireWarden:            'Alex Bloggs',
            fireWardenContact:     '07700 900022',
            firstAider:            'Bob Client',
            firstAiderContact:     '07700 900033',
            defibrillator:         'Reception desk',
            isolationSwitch:       'Main plant room',
            fireExtinguisherClass: 'Class A + CO2',
            emergencyContacts: [
                ['role' => 'Site Contact', 'name' => 'Sarah Client', 'phone' => '020 7946 0000'],
            ],
            accidentProcedure: ['Secure the area', 'Call 999', 'Notify PM'],
            fireProcedure:     ['Sound alarm', 'Evacuate via nearest exit'],
            riddorMatrix: [
                ['category' => 'Fatality', 'action' => 'Report within 10 days'],
            ],
        );

        $this->assertSame('Watford General Hospital', $dto->nearestHospital);
        $this->assertSame('07700 900022',              $dto->fireWardenContact);
        $this->assertSame('07700 900033',              $dto->firstAiderContact);
        $this->assertSame('Class A + CO2',             $dto->fireExtinguisherClass);
        $this->assertSame('Site Contact', $dto->emergencyContacts[0]['role']);
        $this->assertCount(3, $dto->accidentProcedure);
        $this->assertSame('Fatality', $dto->riddorMatrix[0]['category']);
    }

    public function test_from_array_normalises_partial_contacts_and_matrix(): void
    {
        $dto = EmergencySectionDto::fromArray([
            'emergency_contacts' => [
                ['name' => 'Only Name'],                                     // missing role/phone
            ],
            'riddor_matrix' => [
                ['action' => 'Report to PM'],                                 // missing category
            ],
            'accident_procedure' => ['Step 1', 42],                           // int coerced
        ]);

        $this->assertSame(
            ['role' => '', 'name' => 'Only Name', 'phone' => ''],
            $dto->emergencyContacts[0]
        );
        $this->assertSame(
            ['category' => '', 'action' => 'Report to PM'],
            $dto->riddorMatrix[0]
        );
        $this->assertSame(['Step 1', '42'], $dto->accidentProcedure);
    }

    public function test_is_empty_on_default_instance(): void
    {
        $this->assertTrue((new EmergencySectionDto())->isEmpty());
    }

    public function test_is_empty_false_when_any_field_populated(): void
    {
        $this->assertFalse(EmergencySectionDto::fromArray(['nearest_hospital' => 'x'])->isEmpty());
        $this->assertFalse(EmergencySectionDto::fromArray(['fire_procedure' => ['x']])->isEmpty());
        $this->assertFalse(EmergencySectionDto::fromArray(['fire_warden_contact' => '07700 000000'])->isEmpty());
        $this->assertFalse(EmergencySectionDto::fromArray(['fire_extinguisher_class' => 'Class A'])->isEmpty());
    }

    /**
     * Regression for phase 260726-rf3 Plan 05a — partial site_emergency
     * data must fully-populate the DTO with '' defaults for every one of
     * the 9 canonical emergency-block keys, so downstream renderers never
     * hit an "Undefined array key" warning under PHP 8.4.
     */
    public function test_from_array_defaults_every_string_field_to_empty_when_absent(): void
    {
        // Only 5 of the 9 site_emergency keys supplied — mirrors the
        // real-world case that triggered the deferred-items bug (empty-scope
        // fixture: nearest_hospital + fire_assembly_point + fire_warden +
        // first_aider + defibrillator set; fire_warden_contact / first_aider_contact
        // / electrical_isolation_switch / fire_extinguisher_class missing).
        $dto = EmergencySectionDto::fromArray([
            'nearest_hospital'    => 'Leeds General Infirmary',
            'fire_assembly_point' => 'North car park',
            'fire_warden'         => 'Alex Bloggs',
            'first_aider'         => 'Sarah Client',
            'defibrillator'       => 'Reception',
        ]);

        // Populated fields survived.
        $this->assertSame('Leeds General Infirmary', $dto->nearestHospital);
        $this->assertSame('North car park',          $dto->fireAssemblyPoint);
        $this->assertSame('Alex Bloggs',             $dto->fireWarden);
        $this->assertSame('Sarah Client',            $dto->firstAider);
        $this->assertSame('Reception',               $dto->defibrillator);

        // Absent fields defaulted to '' — the critical guarantee that
        // stops the blade from hitting an "Undefined array key" warning.
        $this->assertSame('', $dto->fireWardenContact);
        $this->assertSame('', $dto->firstAiderContact);
        $this->assertSame('', $dto->isolationSwitch);
        $this->assertSame('', $dto->fireExtinguisherClass);
    }

    public function test_from_array_accepts_electrical_isolation_switch_and_legacy_alias(): void
    {
        $canonical = EmergencySectionDto::fromArray([
            'electrical_isolation_switch' => 'Sub-panel B, plant room',
        ]);
        $this->assertSame('Sub-panel B, plant room', $canonical->isolationSwitch);

        $legacy = EmergencySectionDto::fromArray([
            'isolation_switch' => 'Riser cupboard, ground floor',
        ]);
        $this->assertSame('Riser cupboard, ground floor', $legacy->isolationSwitch);
    }
}
