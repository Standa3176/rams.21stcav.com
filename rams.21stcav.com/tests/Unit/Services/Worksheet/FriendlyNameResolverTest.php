<?php

namespace Tests\Unit\Services\Worksheet;

use App\Services\Worksheet\FriendlyNameResolver;
use PHPUnit\Framework\TestCase;

class FriendlyNameResolverTest extends TestCase
{
    private FriendlyNameResolver $r;

    protected function setUp(): void
    {
        parent::setUp();
        $this->r = new FriendlyNameResolver([
            'MXW2X/SM86' => 'Shure Microflex Wireless 2 Handheld (SM86)',
            '16207'      => 'Q-SYS Core 8 Flex',
        ]);
    }

    public function test_resolves_bare_sku_in_name_field(): void
    {
        $this->assertSame(
            'Shure Microflex Wireless 2 Handheld (SM86)',
            $this->r->resolve(['name' => 'MXW2X/SM86']),
        );
    }

    public function test_resolves_by_part_no_when_name_blank(): void
    {
        $this->assertSame(
            'Q-SYS Core 8 Flex',
            $this->r->resolve(['part_no' => '16207']),
        );
    }

    public function test_passes_through_human_readable_name(): void
    {
        $this->assertSame(
            'Samsung QM75B 75" Professional UHD Display',
            $this->r->resolve(['name' => 'Samsung QM75B 75" Professional UHD Display']),
        );
    }

    public function test_falls_back_to_description_when_sku_name_unknown(): void
    {
        $this->assertSame(
            'Generic wall plate assembly',
            $this->r->resolve(['name' => 'WPX-99', 'description' => 'Generic wall plate assembly']),
        );
    }

    public function test_falls_back_to_name_when_description_also_missing(): void
    {
        $this->assertSame('UNKNOWN-1', $this->r->resolve(['name' => 'UNKNOWN-1']));
    }

    public function test_case_insensitive_map_lookup(): void
    {
        $this->assertSame(
            'Shure Microflex Wireless 2 Handheld (SM86)',
            $this->r->resolve(['part_no' => 'mxw2x/sm86']),
        );
    }
}
