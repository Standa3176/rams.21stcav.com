<?php

namespace Tests\Unit\Support\Rams\Sections;

use App\Support\Rams\Sections\EmergencySectionDto;
use PHPUnit\Framework\TestCase;

class EmergencySectionDtoTest extends TestCase
{
    public function test_construction_with_typical_emergency_data(): void
    {
        $dto = new EmergencySectionDto(
            nearestHospital:   'Watford General Hospital',
            fireAssemblyPoint: 'Car park by main gate',
            fireWarden:        'Alex Bloggs',
            firstAider:        'Bob Client',
            defibrillator:     'Reception desk',
            isolationSwitch:   'Main plant room',
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
    }
}
