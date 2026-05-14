# Phase 23 Discovery — Open Question 4: Tier 1.5 Stencil Port Presence

**Resolved:** 2026-05-14
**Researcher:** Plan 23-01 Task 1 (per D-07 carry-forward)

## Tinker counts

Query (after running `DeviceStencilSeeder` to populate the local DB):

```
"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" artisan tinker --execute="echo 'total_curated=' . \App\Models\DeviceStencil::where('source', 'engineer-curated')->count() . PHP_EOL; echo 'with_constraints=' . \App\Models\DeviceStencil::where('source', 'engineer-curated')->where('mxgraph_xml', 'like', '%<constraint%')->count() . PHP_EOL; echo 'needs_curation=' . \App\Models\DeviceStencil::where('source', 'engineer-curated')->whereJsonContains('metadata->needs_phase_24_curation', true)->count() . PHP_EOL;"
```

Output verbatim:

```
total_curated=96
with_constraints=5
needs_curation=91
```

Counts:
- total engineer-curated stencils: **96**
- with `<constraint>` elements: **5**
- flagged `needs_phase_24_curation = true`: **91**
- ratio with constraints: **5 / 96 = 5.2%**
- ratio Tier 1.5 (needs_curation = true): **91 / 96 = 94.8%**

The 5 stencils with `<constraint>` elements are the original spike-promoted devices
(Phase 21 Plan 02 Task 2 Step A):

| part_number       | manufacturer | model                         |
|-------------------|--------------|-------------------------------|
| bar-pro           | Barco        | ClickShare Bar Pro            |
| neat-bar-pro      | Neat         | Bar Pro                       |
| gs312tp           | Netgear      | GS312TP                       |
| samsung-qm65c-t   | Samsung      | QM65C-T                       |
| sennheiser-tcc2   | Sennheiser   | TeamConnect Ceiling 2         |

The remaining 91 stencils are Tier 1.5 (auto-generic body shell from
`AutoGenericStencilGenerator` per Plan 21-02 Step B/C). Their `mxgraph_xml` is the
220×140 brand-aligned shape (teal header, manufacturer/model/part_number text,
"Tier 1 placeholder" annotation) with NO `<connections><constraint>` elements.

## Disposition

**Path B selected (Constraints absent in Tier 1.5).**

The numerical evidence is conclusive: only 5 out of 96 engineer-curated stencils (5.2%)
carry port constraints in their `mxgraph_xml`. The other 94.8% are Tier 1.5 auto-generic
placeholders whose stencil shape literally cannot terminate a cable at a named port —
there are no named ports in their XML to terminate at.

This means CableRouter (Plan 03) cannot rely on `exitPortId`/`entryPortId` resolution
for Tier 1.5 stencils. The router MUST fall back to the D-07 device-edge heuristic
(with ⚠ glyph) whenever a cable's source OR dest stencil is Tier 1.5, **regardless of
whether the cable's `source_port_id` / `dest_port_id` FKs are populated**. Even if the
FK exists and the `device_ports` row is real, the stencil shape has no constraint to
attach to — drawing the edge to a named port would produce a draw.io render error or
silently dangle the edge.

**Implication for Plan 03 (CableRouter):** Cable router resolution per source side and
dest side independently:

```php
// Per side (source AND dest evaluated independently):
$stencil = $cable->{side . 'Device'}?->stencil;
$portId  = $cable->{$side . '_port_id'};

if ($stencil === null) {
    // Both FKs null on this side — D-07 leg 1 (device unknown).
    // If BOTH source AND dest end up null after evaluating both sides → SKIP cable
    //   (legacy NULL-FK row, already handled by v1.3 surface).
    $usePortRoute = false;
    $edgeFallback = true;
} elseif ($stencil->isCurated() === false || str_contains($stencil->mxgraph_xml, '<constraint') === false) {
    // Tier 1 placeholder OR Tier 1.5 (no constraints in XML).
    // Even when port FK is set, can't resolve at the stencil XML level.
    $usePortRoute = false;
    $edgeFallback = true;
    $warnGlyph    = true;
} elseif ($portId === null) {
    // Curated stencil with constraints, but cable has no port_id (legacy text-only row).
    // D-07: edge heuristic + ⚠ glyph at this side's junction.
    $usePortRoute = false;
    $edgeFallback = true;
    $warnGlyph    = true;
} else {
    // Happy path — curated stencil + populated port FK.
    $usePortRoute = true; // exitPortId / entryPortId resolution
}
```

Plan 03 Task 2 grep test asserts: when iterating a cable whose `sourceDevice->stencil`
is Tier 1.5 (matches `metadata.needs_phase_24_curation = true` OR `mxgraph_xml` lacks
`<constraint`), the rendered mxCell does NOT contain `exitPortId=` / `entryPortId=` on
that side — it falls through to the edge-style.

The phase-wide acceptance: 91 of the 96 currently-seeded stencils render via the
edge-fallback path in Phase 23. Phase 24's curation UI is what fills the gap — once
engineers curate a Tier 1.5 stencil up to Tier 2 (adding `<constraint>` elements to its
`mxgraph_xml`), every project that referenced its part_number automatically upgrades
to port-to-port routing on next render (the `DeviceStencilCacheService` key is
part_number, so the upgrade propagates cross-project for free).
