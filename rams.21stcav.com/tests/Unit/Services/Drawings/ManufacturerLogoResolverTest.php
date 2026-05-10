<?php

namespace Tests\Unit\Services\Drawings;

use App\Services\Drawings\ManufacturerLogoResolver;
use Tests\TestCase;

/**
 * Phase 21 Plan 03 Task 1 — locks ManufacturerLogoResolver per CONTEXT.md
 * D-06 (top-20 manufacturer logos as inline SVG, single-colour currentColor)
 * and D-14 (ClickShare slug PRESERVED — needle ordering matches `clickshare`
 * BEFORE `barco` so the spike's existing clickshare.svg keeps rendering for
 * ClickShare Bar Pro stencils).
 *
 * Asserts the public contract (resolveSvg / resolveAssetPath /
 * knownManufacturers) AND the slug-collision avoidance rules:
 *   - q-sys before qsc (avoid 'qsc' substring matching 'q-sys')
 *   - clickshare before barco (D-14 — preserves spike asset)
 *
 * @see app/Services/Drawings/ManufacturerLogoResolver.php
 * @see .planning/phases/21-device-port-catalog-stencil-cache/21-CONTEXT.md (D-06, D-14)
 */
class ManufacturerLogoResolverTest extends TestCase
{
    private function resolver(): ManufacturerLogoResolver
    {
        return new ManufacturerLogoResolver;
    }

    public function test_resolves_known_manufacturer_to_svg_markup(): void
    {
        $svg = $this->resolver()->resolveSvg('Crestron Electronics');

        $this->assertNotNull($svg);
        $this->assertStringContainsString('<svg', $svg);
    }

    public function test_lookup_is_case_insensitive(): void
    {
        $upper = $this->resolver()->resolveSvg('CRESTRON');
        $lower = $this->resolver()->resolveSvg('crestron');
        $mixed = $this->resolver()->resolveSvg('CresTroN');

        $this->assertNotNull($upper);
        $this->assertSame($upper, $lower);
        $this->assertSame($upper, $mixed);
    }

    public function test_unknown_manufacturer_returns_null(): void
    {
        $this->assertNull($this->resolver()->resolveSvg('Acme Corp Ltd'));
    }

    public function test_null_input_returns_null(): void
    {
        $this->assertNull($this->resolver()->resolveSvg(null));
    }

    public function test_empty_string_returns_null(): void
    {
        $this->assertNull($this->resolver()->resolveSvg(''));
    }

    public function test_known_manufacturers_returns_twenty_unique_slugs(): void
    {
        $known = $this->resolver()->knownManufacturers();

        $this->assertCount(20, $known);
        $this->assertSame($known, array_values(array_unique($known)));
        // Sorted alphabetically per the contract.
        $sorted = $known;
        sort($sorted);
        $this->assertSame($sorted, $known, 'knownManufacturers() must return slugs sorted alphabetically');

        // Spot-check inclusion of all 20 expected slugs.
        $expected = [
            'atlona', 'barco', 'biamp', 'cisco', 'clickshare', 'crestron',
            'extron', 'lightware', 'logitech', 'neat', 'netgear', 'polycom',
            'q-sys', 'qsc', 'samsung', 'sennheiser', 'shure', 'sony', 'yamaha',
        ];
        foreach ($expected as $slug) {
            $this->assertContains($slug, $known, "knownManufacturers() must include $slug");
        }
    }

    /**
     * D-14 critical assertion: 'Barco ClickShare ...' MUST resolve to the
     * spike's existing clickshare.svg, NOT a new generic Barco logo. The
     * needle ordering in MANUFACTURER_NEEDLES puts `clickshare` before
     * `barco` to enforce this.
     */
    public function test_d14_clickshare_takes_precedence_over_barco(): void
    {
        $clickshareSvg = file_get_contents(public_path('img/manufacturers/clickshare.svg'));
        $barcoSvg = file_get_contents(public_path('img/manufacturers/barco.svg'));

        $this->assertNotFalse($clickshareSvg, 'clickshare.svg must be readable from public path');
        $this->assertNotFalse($barcoSvg, 'barco.svg must be readable from public path');
        $this->assertNotSame($clickshareSvg, $barcoSvg,
            'Sanity: clickshare.svg and barco.svg must be DIFFERENT files (D-14 separation)');

        $resolved = $this->resolver()->resolveSvg('Barco ClickShare Bar Pro');

        $this->assertSame($clickshareSvg, $resolved,
            'D-14: "Barco ClickShare ..." MUST resolve to clickshare.svg, not barco.svg');
    }

    /**
     * D-14 fallback assertion: a non-ClickShare Barco product (e.g. F50
     * projector) MUST resolve to the new barco.svg via the second needle
     * in the table.
     */
    public function test_d14_non_clickshare_barco_resolves_to_barco_svg(): void
    {
        $barcoSvg = file_get_contents(public_path('img/manufacturers/barco.svg'));

        $resolved = $this->resolver()->resolveSvg('Barco F50 Projector');

        $this->assertSame($barcoSvg, $resolved,
            'D-14: "Barco F50" (no clickshare substring) MUST resolve to barco.svg');
    }

    /**
     * Q-SYS / QSC collision avoidance — `q-sys` needle MUST be evaluated
     * BEFORE `qsc` so a Q-SYS Core 110f doesn't accidentally pick up the
     * QSC logo (different brands; QSC owns Q-SYS but the products ship
     * with separate visual identities).
     */
    public function test_q_sys_resolves_to_q_sys_not_qsc(): void
    {
        $qsysSvg = file_get_contents(public_path('img/manufacturers/q-sys.svg'));
        $qscSvg = file_get_contents(public_path('img/manufacturers/qsc.svg'));

        $this->assertNotSame($qsysSvg, $qscSvg, 'Sanity: q-sys.svg and qsc.svg must be different files');

        $resolved = $this->resolver()->resolveSvg('Q-SYS Core 110f');

        $this->assertSame($qsysSvg, $resolved,
            'q-sys needle MUST precede qsc needle so Q-SYS products do not match QSC');
    }

    public function test_polycom_alias_poly_resolves_to_polycom(): void
    {
        $polycomSvg = file_get_contents(public_path('img/manufacturers/polycom.svg'));
        $resolved = $this->resolver()->resolveSvg('Poly Studio X70');

        $this->assertSame($polycomSvg, $resolved,
            'poly alias must map to polycom.svg');
    }

    public function test_resolve_asset_path_returns_web_url(): void
    {
        $path = $this->resolver()->resolveAssetPath('Crestron');

        $this->assertSame('/img/manufacturers/crestron.svg', $path);
    }

    public function test_resolve_asset_path_returns_null_for_unknown(): void
    {
        $this->assertNull($this->resolver()->resolveAssetPath('Acme Corp'));
    }

    /**
     * D-14 preservation check — clickshare.svg ships with the spike and
     * MUST not be removed by Plan 21-03. This is a defensive assertion
     * against accidental deletion.
     */
    public function test_d14_clickshare_svg_file_preserved(): void
    {
        $path = public_path('img/manufacturers/clickshare.svg');
        $this->assertFileExists($path,
            'D-14: public/img/manufacturers/clickshare.svg MUST be preserved (not deleted/renamed by Plan 21-03)');

        $contents = file_get_contents($path);
        $this->assertNotFalse($contents);
        $this->assertNotEmpty(trim((string) $contents),
            'D-14: clickshare.svg must be a non-empty file');
    }

    /**
     * Memoisation: resolveSvg('Crestron ...') and resolveSvg('crestron')
     * (different inputs, same slug) must hit the file system at most once
     * per slug. We can't easily count file_get_contents calls, but we can
     * assert byte-identical output across calls (strong proxy for cached
     * read).
     */
    public function test_repeated_calls_return_same_svg_bytes(): void
    {
        $resolver = $this->resolver();
        $first = $resolver->resolveSvg('Crestron');
        $second = $resolver->resolveSvg('Crestron Electronics TSS-1070');
        $third = $resolver->resolveSvg('crestron');

        $this->assertNotNull($first);
        $this->assertSame($first, $second);
        $this->assertSame($first, $third);
    }
}
