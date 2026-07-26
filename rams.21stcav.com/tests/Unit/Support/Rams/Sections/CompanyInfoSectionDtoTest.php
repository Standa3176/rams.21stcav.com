<?php

namespace Tests\Unit\Support\Rams\Sections;

use App\Support\Rams\Sections\CompanyInfoSectionDto;
use PHPUnit\Framework\TestCase;

class CompanyInfoSectionDtoTest extends TestCase
{
    public function test_construction_with_typical_company_info(): void
    {
        $dto = new CompanyInfoSectionDto(
            name:    '21st Century AV Ltd',
            address: 'Thames Court, 2 Richfield Avenue, Reading',
            phone:   '01189 977770',
            email:   'info@21stcenturyav.com',
            website: 'www.21stcenturyav.com',
        );

        $this->assertSame('21st Century AV Ltd', $dto->name);
        $this->assertSame('01189 977770', $dto->phone);
    }

    public function test_from_array_is_tolerant_of_missing_keys(): void
    {
        $dto = CompanyInfoSectionDto::fromArray(['name' => 'Acme']);
        $this->assertSame('Acme', $dto->name);
        $this->assertSame('', $dto->address);
        $this->assertSame('', $dto->phone);
        $this->assertSame('', $dto->email);
        $this->assertSame('', $dto->website);
    }

    public function test_is_empty_on_default_instance(): void
    {
        $this->assertTrue((new CompanyInfoSectionDto())->isEmpty());
        $this->assertTrue(CompanyInfoSectionDto::fromArray([])->isEmpty());
    }

    public function test_is_empty_false_when_any_field_populated(): void
    {
        $this->assertFalse(CompanyInfoSectionDto::fromArray(['email' => 'a@b.com'])->isEmpty());
    }
}
