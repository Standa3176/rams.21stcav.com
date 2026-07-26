<?php

namespace Tests\Unit\Support\Rams\Sections;

use App\Support\Rams\Sections\HealthSafetySectionDto;
use PHPUnit\Framework\TestCase;

class HealthSafetySectionDtoTest extends TestCase
{
    public function test_construction_with_policy_and_intro_text(): void
    {
        $dto = new HealthSafetySectionDto(
            policyText:         '21st Century AV Ltd operates under a full H&S policy...',
            standardsIntroText: 'The following standards apply to this project.',
        );

        $this->assertStringStartsWith('21st Century AV Ltd', $dto->policyText);
        $this->assertNotSame('', $dto->standardsIntroText);
    }

    public function test_from_array_is_tolerant_of_missing_keys(): void
    {
        $dto = HealthSafetySectionDto::fromArray(['policy_text' => 'x']);
        $this->assertSame('x', $dto->policyText);
        $this->assertSame('', $dto->standardsIntroText);
    }

    public function test_is_empty_on_default_instance(): void
    {
        $this->assertTrue((new HealthSafetySectionDto())->isEmpty());
    }

    public function test_is_empty_false_when_any_field_populated(): void
    {
        $this->assertFalse(HealthSafetySectionDto::fromArray(['policy_text' => 'x'])->isEmpty());
        $this->assertFalse(HealthSafetySectionDto::fromArray(['standards_intro_text' => 'y'])->isEmpty());
    }
}
