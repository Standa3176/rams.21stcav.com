<?php

namespace Tests\Unit\Support\Rams\Sections;

use App\Support\Rams\Sections\StandardsTableSectionDto;
use PHPUnit\Framework\TestCase;

class StandardsTableSectionDtoTest extends TestCase
{
    public function test_construction_with_typical_rows(): void
    {
        $dto = new StandardsTableSectionDto(rows: [
            ['ref' => 'BS 7671:2018+A2:2022', 'title' => 'IET Wiring Regulations', 'applies_to' => 'All power terminations'],
            ['ref' => 'AVIXA F502.01',        'title' => 'AV Systems Performance Verification', 'applies_to' => 'Commissioning'],
        ]);

        $this->assertCount(2, $dto->rows);
        $this->assertSame('AVIXA F502.01', $dto->rows[1]['ref']);
    }

    public function test_from_array_normalises_partial_rows(): void
    {
        $dto = StandardsTableSectionDto::fromArray([
            'rows' => [
                ['ref' => 'PUWER 1998'],                             // missing title/applies_to
                ['title' => 'Standalone Title'],                     // missing ref/applies_to
            ],
        ]);

        $this->assertCount(2, $dto->rows);
        $this->assertSame(['ref' => 'PUWER 1998', 'title' => '', 'applies_to' => ''], $dto->rows[0]);
        $this->assertSame('Standalone Title', $dto->rows[1]['title']);
    }

    public function test_is_empty_on_default_instance(): void
    {
        $this->assertTrue((new StandardsTableSectionDto())->isEmpty());
    }

    public function test_is_empty_false_when_rows_populated(): void
    {
        $this->assertFalse(StandardsTableSectionDto::fromArray(['rows' => [['ref' => 'BS 7671']]])->isEmpty());
    }
}
