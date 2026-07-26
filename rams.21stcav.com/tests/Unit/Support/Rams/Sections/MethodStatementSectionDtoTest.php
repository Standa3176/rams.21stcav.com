<?php

namespace Tests\Unit\Support\Rams\Sections;

use App\Support\Rams\Sections\MethodStatementSectionDto;
use PHPUnit\Framework\TestCase;

class MethodStatementSectionDtoTest extends TestCase
{
    public function test_construction_with_typical_method_statement_data(): void
    {
        $dto = new MethodStatementSectionDto(
            team: [
                ['role' => 'Lead Engineer', 'qty' => 1, 'requirements' => '18th Edition'],
            ],
            tools: ['Cordless drill', 'Cable pulling kit'],
            ppe:   ['General' => ['Safety glasses', 'Steel toe boots']],
            steps: [
                [
                    'title'            => 'Set-Up and Pre-Works',
                    'bullets'          => ['Sign in', 'Toolbox talk delivered'],
                    'associated_risks' => ['RA01', 'RA04'],
                ],
            ],
            materialHandling: ['Two-person lift for displays 55" and above.'],
        );

        $this->assertSame('Lead Engineer', $dto->team[0]['role']);
        $this->assertSame(1, $dto->team[0]['qty']);
        $this->assertSame(['Safety glasses', 'Steel toe boots'], $dto->ppe['General']);
        $this->assertSame(['RA01', 'RA04'], $dto->steps[0]['associated_risks']);
    }

    public function test_from_array_normalises_team_qty_to_int_and_steps_shape(): void
    {
        $dto = MethodStatementSectionDto::fromArray([
            'team' => [
                ['role' => 'AV Engineer', 'qty' => '2'],   // qty as string
            ],
            'steps' => [
                ['title' => 'Cable pull'],                 // no bullets, no risks
            ],
            'ppe' => [
                'Cabling' => ['Cut-resistant gloves'],
            ],
        ]);

        $this->assertSame(2, $dto->team[0]['qty']);
        $this->assertSame('', $dto->team[0]['requirements']);
        $this->assertSame('Cable pull', $dto->steps[0]['title']);
        $this->assertSame([], $dto->steps[0]['bullets']);
        $this->assertSame([], $dto->steps[0]['associated_risks']);
        $this->assertSame(['Cut-resistant gloves'], $dto->ppe['Cabling']);
    }

    public function test_from_array_populates_every_bucket_when_supplied(): void
    {
        $dto = MethodStatementSectionDto::fromArray([
            'tools'                  => ['drill'],
            'access_equipment'       => ['step ladder'],
            'access_requirements'    => ['swipe card'],
            'client_responsibilities'=> ['isolate power'],
            'material_handling'      => ['two-person lift'],
            'permits'                => ['permit to drill'],
            'fixings_controls'       => ['approved fixings only'],
            'supervision'            => ['daily briefing'],
            'coordination'           => ['coord with electrician'],
            'it_safety'              => ['no live network changes'],
        ]);

        $this->assertSame(['drill'], $dto->tools);
        $this->assertSame(['step ladder'], $dto->accessEquipment);
        $this->assertSame(['swipe card'], $dto->accessRequirements);
        $this->assertSame(['isolate power'], $dto->clientResponsibilities);
        $this->assertSame(['two-person lift'], $dto->materialHandling);
        $this->assertSame(['permit to drill'], $dto->permits);
        $this->assertSame(['approved fixings only'], $dto->fixingsControls);
        $this->assertSame(['daily briefing'], $dto->supervision);
        $this->assertSame(['coord with electrician'], $dto->coordination);
        $this->assertSame(['no live network changes'], $dto->itSafety);
    }

    public function test_is_empty_on_default_instance(): void
    {
        $this->assertTrue((new MethodStatementSectionDto())->isEmpty());
    }

    public function test_is_empty_false_when_any_bucket_populated(): void
    {
        $this->assertFalse(MethodStatementSectionDto::fromArray(['tools' => ['drill']])->isEmpty());
        $this->assertFalse(MethodStatementSectionDto::fromArray(['steps' => [['title' => 's']]])->isEmpty());
    }
}
