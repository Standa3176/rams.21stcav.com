<?php

namespace Tests\Unit\Support\Rams;

use App\Support\Rams\RamsTheme;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Contract tests for the RamsTheme design-token accessor.
 *
 * Verifies that:
 *   - Every category (palette/font/size/spacing) returns the expected
 *     value for known keys and throws InvalidArgumentException for
 *     unknown keys (no silent fallback → typos fail loudly).
 *   - The service-provider singleton binding resolves to the same
 *     instance across two container lookups.
 *
 * Phase 260726-rf3-rams-render-unification / plan-01.
 */
class RamsThemeTest extends TestCase
{
    public function test_palette_returns_hex_for_known_key(): void
    {
        $theme = app(RamsTheme::class);
        $this->assertSame('2E74B5', $theme->palette('brand_blue'));
        $this->assertSame('FFFFFF', $theme->palette('white'));
        $this->assertSame('DEEBF7', $theme->palette('alt_row'));
    }

    public function test_palette_throws_on_unknown_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("unknown palette key 'not_a_real_colour'");

        app(RamsTheme::class)->palette('not_a_real_colour');
    }

    public function test_font_returns_family_for_known_key(): void
    {
        $theme = app(RamsTheme::class);
        $this->assertSame('Poppins', $theme->font('body'));
        $this->assertSame('Consolas', $theme->font('mono'));
    }

    public function test_font_throws_on_unknown_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(RamsTheme::class)->font('comic_sans');
    }

    public function test_size_returns_int_for_known_key(): void
    {
        $theme = app(RamsTheme::class);
        $this->assertSame(10, $theme->size('body'));
        $this->assertSame(22, $theme->size('h1'));
    }

    public function test_size_throws_on_unknown_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(RamsTheme::class)->size('mega');
    }

    public function test_spacing_returns_int_for_known_key(): void
    {
        $theme = app(RamsTheme::class);
        $this->assertSame(1020, $theme->spacing('page_margin_portrait'));
        $this->assertSame(850,  $theme->spacing('page_margin_landscape'));
    }

    public function test_spacing_throws_on_unknown_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(RamsTheme::class)->spacing('warp');
    }

    public function test_section_order_returns_16_canonical_slugs(): void
    {
        $order = app(RamsTheme::class)->sectionOrder();

        $this->assertIsArray($order);
        $this->assertCount(16, $order, 'section_order must have 16 entries (one per section DTO)');
        $this->assertSame('cover',            $order[0]);
        $this->assertSame('appendix_toolbox', $order[15]);
        $this->assertContains('risk_assessment',  $order);
        $this->assertContains('method_statement', $order);
    }

    public function test_singleton_binding_resolves_same_instance(): void
    {
        $a = app(RamsTheme::class);
        $b = app(RamsTheme::class);

        $this->assertSame($a, $b, 'RamsTheme must be a shared singleton');
    }

    public function test_from_config_builds_from_raw_array(): void
    {
        $t = RamsTheme::fromConfig([
            'palette'       => ['brand_blue' => 'ABCDEF'],
            'fonts'         => ['body' => 'Arial'],
            'sizes'         => ['body' => 12],
            'spacing'       => ['gap' => 100],
            'section_order' => ['cover', 'signoff'],
        ]);

        $this->assertSame('ABCDEF', $t->palette('brand_blue'));
        $this->assertSame('Arial',  $t->font('body'));
        $this->assertSame(12,       $t->size('body'));
        $this->assertSame(100,      $t->spacing('gap'));
        $this->assertSame(['cover', 'signoff'], $t->sectionOrder());
    }

    public function test_to_array_exposes_the_full_token_tree(): void
    {
        $arr = app(RamsTheme::class)->toArray();

        $this->assertArrayHasKey('palette',       $arr);
        $this->assertArrayHasKey('fonts',         $arr);
        $this->assertArrayHasKey('sizes',         $arr);
        $this->assertArrayHasKey('spacing',       $arr);
        $this->assertArrayHasKey('section_order', $arr);
        $this->assertCount(16, $arr['section_order']);
    }
}
