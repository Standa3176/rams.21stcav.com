<?php

namespace Tests\Unit\Support\Rams\Sections;

use App\Support\Rams\Sections\RiskAssessmentSectionDto;
use PHPUnit\Framework\TestCase;

class RiskAssessmentSectionDtoTest extends TestCase
{
    public function test_construction_with_5x5_matrix_and_hazards(): void
    {
        $matrix = [];
        for ($l = 1; $l <= 5; $l++) {
            for ($s = 1; $s <= 5; $s++) {
                $matrix[$l][$s] = $l * $s;
            }
        }

        $dto = new RiskAssessmentSectionDto(
            matrix:  $matrix,
            hazards: [[
                'ref' => 'RA01',
                'hazard' => 'Working at Height',
                'persons_at_risk' => ['21CAV Engineers'],
                'initial_l' => 4, 'initial_s' => 4, 'initial_r' => 16,
                'controls' => ['Podium steps preferred over ladders.'],
                'residual_l' => 2, 'residual_s' => 3, 'residual_r' => 6,
            ]],
        );

        $this->assertSame(25, $dto->matrix[5][5]);
        $this->assertSame('RA01', $dto->hazards[0]['ref']);
        $this->assertSame(16, $dto->hazards[0]['initial_r']);
    }

    public function test_from_array_normalises_partial_hazards(): void
    {
        $dto = RiskAssessmentSectionDto::fromArray([
            'hazards' => [
                ['ref' => 'RA01'],                                    // only ref set
                ['hazard' => 'Manual Handling', 'controls' => ['x']], // partial
            ],
        ]);

        $this->assertCount(2, $dto->hazards);
        $this->assertSame('RA01', $dto->hazards[0]['ref']);
        $this->assertSame(0,      $dto->hazards[0]['initial_l']);
        $this->assertSame([],     $dto->hazards[0]['persons_at_risk']);
        $this->assertSame(['x'],  $dto->hazards[1]['controls']);
    }

    public function test_from_array_coerces_matrix_cells_to_int(): void
    {
        $dto = RiskAssessmentSectionDto::fromArray([
            'matrix' => [1 => [1 => '1', 2 => '4'], 2 => [1 => 2, 2 => '8']],
        ]);

        $this->assertSame(4, $dto->matrix[1][2]);
        $this->assertSame(8, $dto->matrix[2][2]);
    }

    public function test_is_empty_on_default_instance(): void
    {
        $this->assertTrue((new RiskAssessmentSectionDto())->isEmpty());
    }

    public function test_is_empty_false_when_hazards_or_matrix_populated(): void
    {
        $this->assertFalse(
            RiskAssessmentSectionDto::fromArray(['hazards' => [['ref' => 'RA01']]])->isEmpty()
        );
        $this->assertFalse(
            RiskAssessmentSectionDto::fromArray(['matrix' => [1 => [1 => 1]]])->isEmpty()
        );
    }
}
