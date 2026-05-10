# Device Stencils Seed Pack

Git-trackable curation manifests, one per device — the source of truth for the
v2.0 Engineering-Grade Drawings stencil cache. Read by
`App\Services\Drawings\DeviceStencilSeedReader` and seeded into the
`device_stencils` + `device_ports` tables by
`Database\Seeders\DeviceStencilSeeder`.

Phase: 21 (`device-port-catalog-stencil-cache`) Plan: 02
Visual contract: XTEN-AV PAGING SYSTEM reference (manufacturer logo top,
name+model below, port rails left/right, connector glyphs outside edge).

---

## File naming

- **Per-stencil manifests:** `{manufacturer-slug}-{model-slug}.json`,
  lowercase + hyphens (e.g. `neat-bar-pro.json`,
  `samsung-qm65c-t.json`).
- **`_INDEX.md`:** reserved (this file). Non-JSON, skipped by the reader.
- **Underscore-prefixed `*.json` files:** bulk manifests with shape
  `{stencils: [...]}`. The reader detects this shape and flat-maps the inner
  list into the result. Currently:
  - `_v1.3-promoted.json` — 53 entries promoted from
    `resources/data/device-port-catalog.json` (D-05 step 2).
  - `_top-50-gap.json` — hand-curated gap-fill devices (top-50
    21CAV quote-volume part_numbers NOT already in spike + v1.3 promotion;
    D-05 step 3).

## Manifest schema (per-file)

```json
{
  "part_number": "NEAT-BAR-PRO",
  "slug": "neat-bar-pro",
  "manufacturer": "Neat",
  "model": "Bar Pro",
  "display_name": "Neat Bar Pro Videobar",
  "default_width": 240,
  "default_height": 160,
  "logo_svg_path": "/img/manufacturers/neat.svg",
  "mxgraph_xml": "<shape name=\"21cav.neat-bar-pro\" h=\"160\" w=\"240\" ...>...</shape>",
  "source": "engineer-curated",
  "metadata": {
    "provenance": "spike-260509-ibx",
    "curated_by": "21CAV engineering"
  },
  "ports": [
    {
      "port_id": "hdmi-in",
      "label": "HDMI IN",
      "side": "left",
      "connector_type": "hdmi",
      "signal_type": "video",
      "direction": "in",
      "sort_order": 1,
      "y_pct": 0.20
    }
  ]
}
```

Required fields: `part_number`, `slug`, `manufacturer`, `model`,
`mxgraph_xml`, `source` (one of `auto-generated` / `engineer-curated` /
`ai-extracted`), `ports` (array — may be empty).

Optional fields: `display_name`, `default_width`, `default_height`,
`logo_svg_path`, `metadata` (object).

Each port requires: `port_id`, `label`, `side` (one of `left` / `right` /
`top` / `bottom`), `connector_type`, `signal_type`, `direction` (one of
`in` / `out` / `io`). Optional: `sort_order` (defaults to 0),
`y_pct` (left/right ports), `x_pct` (top/bottom ports).

## Bulk manifest schema (`_*.json`)

```json
{
  "version": "1.0",
  "generated": "2026-05-10",
  "provenance": "Generated from ... — DO NOT regenerate from seed pack",
  "stencils": [
    { ...same shape as per-file manifest... },
    ...
  ]
}
```

## Provenance breakdown

- **5 from spike `260509-ibx`** (per-file):
  - `neat-bar-pro.json` — Neat Bar Pro Videobar (6 ports)
  - `samsung-qm65c-t.json` — Samsung QM65C-T 65" Display (9 ports)
  - `clickshare-bar-pro.json` — Barco ClickShare Bar Pro BYOD (7 ports)
  - `sennheiser-tcc2.json` — Sennheiser TeamConnect Ceiling 2 (4 ports)
  - `netgear-gs312tp.json` — Netgear GS312TP 12-port PoE+ Switch (14 ports)

- **53 from v1.3 catalog promotion** (in `_v1.3-promoted.json`):
  All entries from `resources/data/device-port-catalog.json` promoted as
  Tier 1.5 stencils per D-05 step 2. Body shell auto-generic; manufacturer
  + model + part_number filled from the JSON. Tagged
  `metadata.needs_phase_24_curation = true` so Phase 24's curation UI can
  filter the queue.

- **Gap-fill from top-50 21CAV quote volume** (in `_top-50-gap.json`):
  Hand-curated entries for top-50 part_numbers NOT already covered by
  spike + v1.3 promotion. Entry count documented in the file's
  `provenance` field; coverage threshold may auto-adjust if the actual
  quote-volume catalogue is smaller than 50 unique parts (per Task 2
  action notes).

## ClickShare slug note (per D-14)

`clickshare-bar-pro.json` keeps the `clickshare` slug for the product line:

- `manufacturer: "Barco"` (true brand)
- `display_name: "ClickShare Bar Pro"` (engineer-friendly product name)
- `logo_svg_path: "/img/manufacturers/clickshare.svg"` (preserves the
  existing spike-shipped clickshare.svg per D-14)

Future generic Barco entries (e.g. F50 projector) would land at
`barco-{model}.json`. Both manufacturer logos coexist at
`public/img/manufacturers/{clickshare,barco}.svg`.

## Idempotency

The seeder upserts via `whereRaw('LOWER(TRIM(part_number)) = ?', [...])` +
`updateOrCreate` (mirrors `DeviceCatalogSeeder` pattern). Re-running the
seeder rewrites the same values without duplicating rows. Hand edits to
ports outside the manifest are wiped on reseed — the manifest is the
source of truth (intentional: git-tracked curation).

## Phase 24 forward-compat

Phase 24 ships the curation UI. It will:

1. Read `metadata.needs_phase_24_curation == true` to populate the queue
   of Tier 1.5 v1.3-promoted entries needing engineer curation.
2. Allow promotion of the auto-generic body shell to a hand-traced device
   card with port rails.
3. Persist edits BACK to the manifest file (via a save-and-commit flow)
   so curation changes survive re-seed.

For now, Phase 21-02 ships the data layer. Phase 24 is pure UI on top.
