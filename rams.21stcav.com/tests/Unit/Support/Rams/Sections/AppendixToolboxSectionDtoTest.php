<?php

namespace Tests\Unit\Support\Rams\Sections;

use App\Support\Rams\Sections\AppendixToolboxSectionDto;
use PHPUnit\Framework\TestCase;

class AppendixToolboxSectionDtoTest extends TestCase
{
    public function test_construction_with_typical_toolbox_data(): void
    {
        $dto = new AppendixToolboxSectionDto(
            instructionText: 'Toolbox talk delivered by Lead Engineer before works commence.',
            rowCount:        8,
        );

        $this->assertStringStartsWith('Toolbox talk', $dto->instructionText);
        $this->assertSame(8, $dto->rowCount);
    }

    public function test_from_array_uses_default_row_count_when_absent(): void
    {
        $dto = AppendixToolboxSectionDto::fromArray(['instruction_text' => 'x']);
        $this->assertSame('x', $dto->instructionText);
        $this->assertSame(5, $dto->rowCount, 'row_count must default to 5 when the key is absent');
    }

    public function test_from_array_preserves_explicit_zero_row_count(): void
    {
        // Guardrail: an explicit 0 must NOT be silently replaced by the
        // 5-row default (?? would do that if we used it naively).
        $dto = AppendixToolboxSectionDto::fromArray(['row_count' => 0]);
        $this->assertSame(0, $dto->rowCount);
    }

    public function test_is_empty_reflects_instruction_text_only(): void
    {
        // A default instance has 5 rows but no text — still counts as
        // empty for renderer-skip purposes (per DTO docblock).
        $this->assertTrue((new AppendixToolboxSectionDto())->isEmpty());
        $this->assertTrue(AppendixToolboxSectionDto::fromArray([])->isEmpty());
    }

    public function test_is_empty_false_when_instruction_text_populated(): void
    {
        $this->assertFalse(
            AppendixToolboxSectionDto::fromArray(['instruction_text' => 'x'])->isEmpty()
        );
    }
}
