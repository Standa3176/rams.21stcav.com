<?php

namespace App\Services\Drawings;

/**
 * Phase 21 Plan 03 — manufacturer-name → logo SVG resolver.
 *
 * Maps a free-text manufacturer string (typically pulled from a DeviceStencil
 * row OR a quote line) to one of the top-20 brand wordmarks living in
 * public/img/manufacturers/. Lookup is case-insensitive substring matching
 * with the needle table evaluated MOST-SPECIFIC-FIRST so collision-prone
 * brand pairs resolve correctly:
 *
 *   - 'q-sys' before 'qsc' — Q-SYS Core 110f must NOT pick up the QSC logo
 *   - 'clickshare' before 'barco' — D-14: Barco ClickShare products MUST
 *     keep using the existing clickshare.svg the spike shipped with; only
 *     non-ClickShare Barco products (F50 projectors, G-series cinema, etc.)
 *     fall through to the new barco.svg.
 *
 * The 'poly' alias maps to polycom.svg (Poly is the Polycom rebrand —
 * legacy product names still ship under both names).
 *
 * The 20 unique slugs (alphabetical):
 *   atlona, barco, biamp, cisco, clickshare, crestron, extron, lightware,
 *   logitech, neat, netgear, polycom, q-sys, qsc, samsung, sennheiser,
 *   shure, sony, yamaha.
 *
 * Asset format (D-06): viewBox="0 0 100 30" + fill="currentColor" so the
 * embedding mxgraph header bar can recolour the glyph to match its theme.
 *
 * Licensing note: these are simplified text-based representations / hand-
 * traced approximations intended for engineering schematic display, NOT
 * brand-asset reproductions. They fall under nominative fair use territory
 * for internal tooling. Phase 24's curation UI ships a per-stencil logo
 * upload path so manufacturer-supplied vectors can replace these in time.
 *
 * @see public/img/manufacturers/ — the 20 SVG assets backing this resolver
 * @see app/Services/Drawings/DrawIoBuilderService.php — primary consumer
 * @see .planning/phases/21-device-port-catalog-stencil-cache/21-CONTEXT.md (D-06, D-14)
 */
class ManufacturerLogoResolver
{
    /**
     * Substring-match needles → slug (filename without extension).
     *
     * Order matters: most-specific FIRST. The first needle that
     * matches the lower-cased input wins. See class docblock for the
     * collision-avoidance rationale.
     *
     * @var array<string, string>
     */
    private const MANUFACTURER_NEEDLES = [
        // ── Collision-prone pairs (most-specific first) ────────────────
        'q-sys' => 'q-sys',     // BEFORE qsc to avoid 'qsc' substring matching 'q-sys'
        'qsc' => 'qsc',
        'clickshare' => 'clickshare', // D-14: BEFORE barco — preserve spike's clickshare.svg
        'barco' => 'barco',
        // ── Remaining manufacturers (no inter-collisions among them) ───
        'crestron' => 'crestron',
        'cisco' => 'cisco',
        'bogen' => 'bogen',
        'polycom' => 'polycom',
        'poly' => 'polycom',   // alias — Polycom legacy / Poly rebrand
        'logitech' => 'logitech',
        'shure' => 'shure',
        'sony' => 'sony',
        'extron' => 'extron',
        'biamp' => 'biamp',
        'yamaha' => 'yamaha',
        'atlona' => 'atlona',
        'lightware' => 'lightware',
        // ── Existing spike assets ──────────────────────────────────────
        'neat' => 'neat',
        'samsung' => 'samsung',
        'netgear' => 'netgear',
        'sennheiser' => 'sennheiser',
    ];

    /**
     * Per-instance memoised file-read cache: slug → SVG markup.
     *
     * @var array<string, string|false>
     */
    private array $svgCache = [];

    /**
     * Resolve a manufacturer name to inline SVG markup.
     *
     * Returns null when:
     *   - $manufacturer is null OR empty (after trim)
     *   - no needle in MANUFACTURER_NEEDLES matches the lower-cased input
     *   - the matched slug's asset file doesn't exist on disk
     */
    public function resolveSvg(?string $manufacturer): ?string
    {
        $slug = $this->resolveSlug($manufacturer);
        if ($slug === null) {
            return null;
        }

        if (! array_key_exists($slug, $this->svgCache)) {
            $path = public_path("img/manufacturers/{$slug}.svg");
            $this->svgCache[$slug] = is_file($path) ? file_get_contents($path) : false;
        }

        $contents = $this->svgCache[$slug];

        return $contents === false ? null : $contents;
    }

    /**
     * Resolve a manufacturer name to its public-web asset path.
     *
     * Useful for Phase 24 curation UI (browser-side <img src="..."/>) and
     * for any context that prefers an external reference over inline SVG.
     */
    public function resolveAssetPath(?string $manufacturer): ?string
    {
        $slug = $this->resolveSlug($manufacturer);
        if ($slug === null) {
            return null;
        }

        return "/img/manufacturers/{$slug}.svg";
    }

    /**
     * The full sorted list of supported manufacturer slugs.
     *
     * @return array<int, string>
     */
    public function knownManufacturers(): array
    {
        $slugs = array_values(array_unique(array_values(self::MANUFACTURER_NEEDLES)));
        sort($slugs);

        return $slugs;
    }

    /**
     * Map a manufacturer string to the slug for one of our SVG assets.
     *
     * Implementation: iterate MANUFACTURER_NEEDLES in declared order
     * (PHP preserves insertion order for associative arrays — this is
     * the same iteration pattern used by DrawIoSpikeBuilderService::
     * STENCIL_ALIASES) and return the first matching slug.
     */
    private function resolveSlug(?string $manufacturer): ?string
    {
        if ($manufacturer === null) {
            return null;
        }

        $haystack = strtolower(trim($manufacturer));
        if ($haystack === '') {
            return null;
        }

        foreach (self::MANUFACTURER_NEEDLES as $needle => $slug) {
            if (str_contains($haystack, $needle)) {
                return $slug;
            }
        }

        return null;
    }
}
