---
name: 260506-qa9 mini O&M context
description: Locked decisions captured before planning the Mini O&M auto-built PDF
type: quick-task-context
date: 2026-05-06
---

# Quick Task 260506-qa9: Mini O&M Auto-Built from Project Data — Context

**Gathered:** 2026-05-06
**Status:** Ready for planning

<domain>
## Task Boundary

Build a single auto-generated, client-facing PDF — the **Mini O&M** — that
packages existing project data (rooms, worksheet photos, device labels,
sign-offs, quoted equipment) into a Tier 1 visual presentation. Pulled
on-demand from a button on the project page; no AI; no new data capture.
Designed to be handed to the client at install close-out as a polished
hardcopy/digital handover artifact.

Distinct from the existing full O&M Manual (which is heavyweight,
AI-assisted, and typically reserved for larger projects). The Mini O&M
is the lightweight option for projects that don't warrant the full pipeline
but still deserve a polished handover document.

</domain>

<decisions>
## Implementation Decisions

### Cover hero photo
- **Auto-pick: first worksheet photo found across any room.**
- Simple deterministic logic — no PM upload step, no decision tree based on
  sign-off status. Falls back gracefully when no worksheet photos exist
  yet (use a brand-only cover for a placeholder mini O&M).
- **Why:** user wants generation to be a single click; the photo only
  needs to look "real and from this site" to feel personal.

### Asset register summary (back-page)
- **Confirmed labels + quote list overlaid.**
- Layout: confirmed device labels first (with serial / MAC / part #
  populated from `device_label_photos` AI extraction), then a second
  block titled "Also installed" listing quoted-but-unconfirmed items.
- **Why:** most complete handover record. Engineers can't always
  photograph every cable / bracket / consumable, so the quote list is
  the catch-all. Confirmed-first ordering signals which items have full
  asset traceability vs. which are quote-only.

### Room inclusion
- **All rooms — no skipping.**
- Rooms without worksheet photos render with a clean "Photos to be
  captured during install" placeholder block. Layout never breaks.
- **Why:** user explicitly chose this — they want visibility of every
  room even before the engineer has finished. The Mini O&M can be
  re-generated as photos come in.

### Visual language
- **Tier 1 client-facing — match existing O&M Manual upgrade pattern**
  (commit `3c9d179` `feat(om-tier1)`).
- Same fonts, brand colour palette, photo treatment, table chrome as
  the existing full O&M's Tier 1 styling. So when a client receives
  both, they feel like one family of documents.

### Generation trigger
- **Manual button on project page**, no auto-generation on sign-off (v1).
- Render fresh on each download — no DB caching v1.
- **Why:** keeps v1 narrow. Auto-on-signoff can be a v2 once tone +
  output is validated by a real client.

### Before/after layout (Claude's discretion — user skipped this question)
- **Auto: include before/after pair only when BOTH exist for a room.**
- When room has both survey photos AND worksheet photos: render a small
  "Before" thumbnail strip + larger "After" hero set. When only one
  exists: render just that set, no awkward gaps.
- **Why:** gracefully degrades. Powerful when complete, never broken.

</decisions>

<specifics>
## Specific Ideas

### Page structure (per project)

1. **Cover** — Tier 1 brand mark + client + project + site address +
   auto-picked hero photo (or brand-only if none captured).
2. **Project summary** — works overview from quote, install dates,
   lead engineer, sign-off status pill.
3. **Per room** (one page each — all rooms, no skipping):
   - Room name + scope sentence (from quote `overview` or
     `works_summary`)
   - Asset list inline: confirmed device labels (manufacturer / model /
     part / serial / MAC) — falls back to quoted equipment for that
     room when no labels captured
   - Photo block: completed worksheet photos prominent (3–6 large),
     "Before" survey thumbnails underneath when both exist
   - Sign-off line: "Installed by X · Accepted by Y · Date" when
     worksheet is signed; "Pending sign-off" otherwise
4. **Asset register** — single-page table aggregating every confirmed
   device + quoted-but-unconfirmed items across all rooms.
5. **Support & warranty** — 21CAV contact + warranty terms +
   "How to raise a service ticket" — copy lives in
   `config/rams.php` extension so it can be edited without touching
   code.

### Tier 1 visual reference
- See existing `feat(om-tier1)` work — commit `3c9d179`. The Mini O&M
  uses the same fonts, colour palette, and photo treatment. The only
  delta is page count (Mini is shorter) and content blocks.

</specifics>

<canonical_refs>
## Canonical References

- Existing O&M Manual Tier 1 upgrade: commit `3c9d179`
  (`feat(om-tier1): upgrade O&M Manual to Tier 1 client-facing standard`)
- PdfRenderService: `app/Services/PdfRenderService.php` — Browsershot
  wrapper, already provisioned on production per Phase 20 runbook
- DocumentArtifactStorage H-07 convention: `CLAUDE.md` — use
  `DocumentArtifactStorage::TYPE_OM` for the Mini O&M's PDF artifact
  (same type as the full O&M; differentiate by filename)
- Existing photo stores (input data):
  - `worksheet_photos` table → `storage/app/worksheet-photos/{wid}/`
  - `site_survey_photos` → `storage/app/projects/{pid}/surveys/{sid}/`
    (or legacy `storage/app/survey-photos/`)
  - `device_label_photos` (with AI extraction) →
    `storage/app/public/projects/{pid}/labels/`

</canonical_refs>
