<?php

namespace Tests\Unit\Support\Rams\Sections;

use App\Support\Rams\Sections\DocControlSectionDto;
use PHPUnit\Framework\TestCase;

class DocControlSectionDtoTest extends TestCase
{
    public function test_construction_with_typical_revision_rows(): void
    {
        $dto = new DocControlSectionDto(revisions: [
            ['rev' => 'Rev 0.1', 'date' => '2026-07-01', 'author' => 'AB', 'description' => 'Initial draft', 'status' => 'DRAFT'],
            ['rev' => 'Rev 1.0', 'date' => '2026-07-26', 'author' => 'AB', 'description' => 'Issued for review', 'status' => 'FOR REVIEW'],
        ]);

        $this->assertCount(2, $dto->revisions);
        $this->assertSame('Rev 1.0', $dto->revisions[1]['rev']);
    }

    public function test_from_array_normalises_partial_rows(): void
    {
        $dto = DocControlSectionDto::fromArray([
            'revisions' => [
                ['rev' => 'Rev 0.1'],                                 // missing every other key
                ['author' => 'AB', 'description' => 'draft'],         // missing rev/date/status
            ],
        ]);

        $this->assertCount(2, $dto->revisions);
        $this->assertSame(
            ['rev' => 'Rev 0.1', 'date' => '', 'author' => '', 'description' => '', 'status' => ''],
            $dto->revisions[0]
        );
        $this->assertSame('AB',    $dto->revisions[1]['author']);
        $this->assertSame('draft', $dto->revisions[1]['description']);
    }

    public function test_is_empty_on_default_instance(): void
    {
        $this->assertTrue((new DocControlSectionDto())->isEmpty());
        $this->assertTrue(DocControlSectionDto::fromArray([])->isEmpty());
    }

    public function test_is_empty_false_when_revisions_populated(): void
    {
        $this->assertFalse(
            DocControlSectionDto::fromArray(['revisions' => [['rev' => 'x']]])->isEmpty()
        );
    }
}
