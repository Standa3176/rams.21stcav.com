---
quick_id: 260711-n2x
slug: cable-schedule-intelligence-t1-d-t1-e-t2
date: 2026-07-11
status: complete
tasks_planned: 4
tasks_completed: 4
commits:
  - 81bf1c0 -- feat(cable-schedule): T1-D quoted cable products override cable_type by signal_type
  - 18f712b -- feat(cable-schedule): T1-E survey narrative populates length + distance warnings
  - 7cd87f4 -- refactor(cable-schedule): T2-A extract StencilPortResolver + auto-populate row FKs
  - 7b22378 -- feat(cable-schedule): T2-B signal-path DAG traversal with feature-gated flat fallback
files_created:
  - app/Services/Cable/StencilPortResolver.php
  - tests/Unit/Services/Cable/StencilPortResolverTest.php
  - tests/Feature/Cable/CableScheduleDagGenerationTest.php
files_modified:
  - app/Services/CableScheduleGeneratorService.php
  - app/Console/Commands/BackfillCablePortFksCommand.php
  - app/Http/Controllers/CableScheduleController.php
  - tests/Unit/Services/Cable/CableScheduleGeneratorServiceTest.php
  - tests/Feature/Cable/CableScheduleXlsxRegressionTest.php
tests_added: 19        # 5 T1-D + 7 T1-E + 4 StencilPortResolver + 3 T2-A + 4 T2-B unit + 6 T2-B integration
tests_total_green: 90  # full cable + notification suite
deploy: code-only (no migration)
---

# Cable Schedule Intelligence T1-D + T1-E + T2 Bundle Summary

Four atomic commits ship four independent upgrades to the deterministic cable
schedule generator — a signal-aware, survey-aware, port-aware DAG walker with a
per-room feature gate that keeps the flat legacy path alive for rooms without
any classified devices.

## One-liner

`CableScheduleGeneratorService` stops being a flat one-row-per-device inference
engine and becomes a per-room orchestrator that (i) overrides `cable_type` from
quoted cable products, (ii) populates `approx_length_m` + distance warnings
from the engineer's survey narrative, (iii) auto-populates port-level FKs via
a shared `StencilPortResolver`, and (iv) walks a signal-aware DAG that emits
one row per (source, destination) edge along the signal chain.

## Commits

| Hash    | Task | Message                                                                 |
| ------- | ---- | ----------------------------------------------------------------------- |
| 81bf1c0 | T1-D | quoted cable products override cable_type by signal_type                |
| 18f712b | T1-E | survey narrative populates length + distance warnings                   |
| 7cd87f4 | T2-A | extract StencilPortResolver + auto-populate row FKs                     |
| 7b22378 | T2-B | signal-path DAG traversal with feature-gated flat fallback              |

## What Shipped

### T1-D — quoted cable products override `cable_type`

- `QUOTED_CABLE_SIGNAL_KEYWORDS` map: video/network/audio/speaker/usb/power
  buckets. Shure Cat-over-Ethernet special case reclassifies from network to
  audio (Shure Microflex Wireless is Cat-audio, not general networking).
- `buildQuotedCableOverrides(consumables): array` — returns a
  `signal_type → cable_type_display` map, multiple consumables of the same
  signal_type joined with ` / ` in input order.
- `applyQuotedCableOverride(cableInfo, overrides): array` — only touches
  `cable_type` + prepends `Quoted: <name> | ` to notes. `signal_type`, `cores`,
  `to` untouched.
- `buildConsumablesByRoom(project): array` — reloads canonical dataset via
  `ProjectDataService` and buckets each room's classified consumables so
  `generateFromDevices` can apply per-room overrides keyed by device
  `room_name`.
- All three call sites wired: `generate()`, `generateFromDevices()`,
  `buildRowsFromEquipmentLines()`.

### T1-E — survey narrative → `approx_length_m` + distance warnings

- `DISTANCE_WARNING_RULES` matrix — 4 rules covering HDMI passive > 15m,
  Cat6 PoE > 100m, HDBaseT > 100m, 2-core speaker cable > 30m. Walked in
  declaration order; matched warnings joined with ` | ` and appended to notes.
- `parseLengthFromNarrative(text): ?float` — regex LAST-match on
  `\d+(?:\.\d+)?\s*(?:m|metres?|meters?)\b`; handles decimal, plain, m/metre/meter forms case-insensitively.
- `extractRoomNarrative(SiteSurveyRoom): ?string` — priority chain:
  JSON `cable_routes` array (synthetic narrative built from `notes` +
  `length_m` markers) → `engineer_feedback.cable_routes` → legacy
  `cable_route_desc` text.
- `computeDistanceWarnings(cableType, cores, lengthM): array` — walks the
  matrix. `null` length short-circuits to `[]`.
- `buildRoomLengthMap(project): array` — per-room map keyed by
  `strtolower(trim(room_name))` from the LATEST `SiteSurvey`.
- Wired into `generate()` + `generateFromDevices()`. `buildRowsFromEquipmentLines`
  intentionally left unchanged (no survey context available).

### T2-A — `StencilPortResolver` service + port-FK auto-population

- New `App\Services\Cable\StencilPortResolver` with sole public method
  `attachToDevices(Collection): Collection`. Single-`whereIn` batched
  normalised-`part_number` lookup + `setRelation('stencil', ...)` applied to
  every device (including `null` for missing/unknown part_no).
- `BackfillCablePortFksCommand::loadProjectDevicesWithStencils` collapsed
  from 30 lines to a 3-line delegation.
- `CableScheduleController::edit` gains constructor DI for the resolver;
  `edit()` delegates. 25 lines of duplicated setup gone.
- `CableScheduleGeneratorService` gains resolver DI; `generateFromDevices`
  attaches stencils once at the top; new `resolveSourcePortId` /
  `resolveDestPortId` helpers pick the port by `signal_type` +
  `direction='out'|null+side='right'` (source) or `direction='in'|null+side='left'` (dest),
  sorted by `sort_order` ASC.
- Flat path now writes `source_device_id` + `source_port_id` on every emitted
  `CableScheduleItem`. Dest columns stay `null` (flat path has no dest device).

### T2-B — signal-path DAG traversal

- `SIGNAL_PATH_ORDER` const: `[dsp, audio-processor, matrix, switcher, codec, amplifier]`
  drives processor ranking within a signal_type bucket by first-substring match
  on `strtolower(manufacturer . ' ' . model)`. Unmatched processors sort last
  by `Device.id` ASC.
- `generateFromDevices` refactored to a per-room orchestrator: groups devices
  by `room_name`, computes per-room overrides + length + consumables, picks
  DAG vs flat via the `hasUnknownSignalRole()` gate (all-unclassified rooms →
  flat; any classified → DAG). `sortOrder` threaded by-reference so `cable_id`
  stays monotonic across rooms.
- `buildSignalGraph(devicesInRoom): array` buckets devices by signal_type
  (from `inferCableRun`) → signal_role (source/processor/destination). Empty
  buckets dropped.
- `emitDagEdges(...)`  walks source × destination Cartesian; each pair emits
  one row per adjacent hop in `source → processors → destination`. Sources
  without destinations emit a `→ TBC — no destination in room` placeholder
  chain + `Log::warning`. Sinks without sources are skipped (cross-room
  chains are deferred — see plan `deferred_next` T2-B extension).
- Every emitted edge — DAG or flat — receives T1-D + T1-E + T2-A decorations
  identically.

## Behaviour Change (Intentional)

Projects with seeded `Device` rows that have `signal_role` classified
(`source` / `processor` / `destination`) now emit a **DAG-based row set**
instead of the flat row-per-device set. This means the number of emitted rows
per room can be **more or fewer** than pre-bundle depending on the graph
shape:

- 1 source + 1 destination (no processors) → 1 row (down from 2 in the flat
  path)
- 1 source + 1 processor + 1 destination → 2 rows (down from 3 in the flat
  path)
- 2 sources × 3 destinations + 1 processor → 12 rows (up from 6 in the flat
  path)

Rooms with **every** device unclassified fall through to the flat fallback,
preserving byte-for-byte pre-bundle behaviour for those rooms.

Legacy generated `cable_schedule_items` render unchanged until an admin hits
Retry to regenerate — the schema is untouched, so old rows keep their existing
column values including the new `signal_type` from 260711-ml0.

## Deviations from Plan

### Rule 3 — Blocking Issue: v1.3 surface-file guard test

**Found during:** Task T2-A implementation.

**Issue:** `tests/Feature/Cable/CableScheduleXlsxRegressionTest::test_v13_surface_files_have_zero_phase22_column_references`
asserts that `app/Services/CableScheduleGeneratorService.php` does NOT contain
the substrings `source_device_id`, `source_port_id`, `dest_device_id`,
`dest_port_id`, `connector_override_note`. This test was authored in Phase 22
as a canary against the schematic/XLSX read paths accidentally coupling to
the FK columns.

**Blocker:** T2-A's whole point is to opt the generator into writing those
same FK columns so the port picker preselects without a follow-up
`cables:backfill-port-fks` run — the plan's `<behavior>` explicitly requires
`'source_device_id' => $device->id` in the flat generateFromDevices path.

**Fix:** Updated the surface-file guard test to REMOVE
`CableScheduleGeneratorService.php` from the `$surfaceFiles` array with an
inline docblock explaining why (T2-A intentional write-in). The XLSX +
schematic files remain guarded — the read-path invariant is preserved.

**Files modified:** `tests/Feature/Cable/CableScheduleXlsxRegressionTest.php`
(surfaceFiles array + docblock).

**Commit:** 7cd87f4.

### Judgement Call — Test integration strategy

The plan asked for pure-unit tests exercised through PHPUnit describe-style
methods. Where DB integration was needed to prove end-to-end behaviour
(T2-B's DAG emit path against real `CableSchedule` + `Device` + `DevicePort`
+ `DeviceStencil` fixtures), those were split into a new
`tests/Feature/Cable/CableScheduleDagGenerationTest.php` file rather than
force `RefreshDatabase` onto the existing pure-unit test class. Keeps the
unit test class fast + factory-free; keeps the integration tests explicit
about their persistence needs.

### Judgement Call — Processor bucket → signal_type

The plan's canonical example ("Q-Sys core as a video processor") contains an
internal contradiction: `Q-Sys` matches the DSP branch in `inferCableRun` and
returns `signal_type='audio'`, not video. My `buildSignalGraph` implementation
follows the plan literally — bucket processors by `inferCableRun`'s
`signal_type`. In practice this means video-chain processors need names that
`inferCableRun` maps to video (e.g. HDBaseT extender, matrix switcher — see
the DAG integration test fixtures for the working shape). If a room has a
Q-Sys core between a Blu-ray source and a video display, Q-Sys will bucket to
audio and the video chain will emit source → destination directly (skipping
the Q-Sys hop). This is intentional per the plan's per-signal_type
bucketing — cross-signal_type routing is a deferred T3-B redundancy pass
concern.

## Deploy Notes

**NO migration required** — pure code deploy.

```
git pull
php artisan config:cache
php artisan view:clear
```

is enough. Legacy generated rows render unchanged until an admin hits Retry
to regenerate. `CableScheduleItem::$fillable` already whitelists the four FK
columns from Phase 22 — no schema change needed for T2-A/B writes.

Post-deploy sanity check: pick a project that has (a) devices with
`signal_role` set, (b) a `SiteSurvey` with `cable_routes` narratives, (c)
quoted HDMI or Cat6 cable consumables, and (d) stencils with matching ports.
Regenerate the cable schedule. Expected: DAG rows, quoted-cable `cable_type`,
populated `approx_length_m`, distance warnings appearing where thresholds
exceeded, and the port-picker preselecting the FKs.

## Test Counts

| Suite                                         | Tests | Result |
| --------------------------------------------- | ----: | ------ |
| tests/Unit/Services/Cable/                    |    46 | green  |
| tests/Feature/Cable/                          |    42 | green  |
| tests/Feature/Notifications/CableSchedule…    |     2 | green  |
| **Total (touched paths)**                     | **90** | green |

`php -l` clean on every modified/new PHP file.

## Deferred (unchanged from plan)

- T2-B cross-room signal chains (project-wide graph).
- T3-B redundancy pass (collapse duplicate (source, dest, signal_type) edges
  into one row with qty column).
- T3-C PoE budget solver.
- Stencil ports on auto-generated Tier-1 stencils (T2-A currently no-ops
  for these — Tier-1 rows still render via text so this is a fidelity
  improvement, not a correctness fix).

## Self-Check: PASSED

Verified after commits landed:

- `81bf1c0`, `18f712b`, `7cd87f4`, `7b22378` — all four commits exist on
  `feat/worksheet-classifier-universal` (`git log --oneline -6`).
- `app/Services/Cable/StencilPortResolver.php` — created.
- `tests/Unit/Services/Cable/StencilPortResolverTest.php` — created.
- `tests/Feature/Cable/CableScheduleDagGenerationTest.php` — created.
- 90 tests green across full cable + notification suites.
- `php -l` clean on all touched PHP files.
