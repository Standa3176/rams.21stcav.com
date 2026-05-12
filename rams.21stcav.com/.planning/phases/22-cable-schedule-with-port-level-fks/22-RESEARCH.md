# Phase 22: Cable Schedule with Port-Level FKs — Research

**Researched:** 2026-05-12
**Domain:** Laravel 12 schema migration + modal UI + artisan backfill (Eloquent FKs on existing audit-grade cable schedule pipeline)
**Confidence:** HIGH

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**Carry-forward from Phase 21:**
- **Generic naming (Phase 21 D-09):** FK column names are `source_device_id`, `source_port_id`, `dest_device_id`, `dest_port_id` — no `rams_` / `project_` prefix. RAMS+SCC merge readiness.
- **Don't break v1.3 (Phase 21 D-10):** `SchematicGeneratorService`, `SchematicD2SourceBuilder`, `CableScheduleGeneratorService`, `DrawingDataResolverService::adjacencyForProject`, the cable XLSX export, and the bound-PDF cable-list section MUST continue rendering legacy rows where the new FK columns are NULL. Phase 22 is strictly additive.
- **DevicePort enum constants:** UI filtering/ordering uses `DevicePort::SIDE_*` and `DevicePort::DIRECTION_*` constants, not magic strings.

**Cascading Dropdown UX:**
- **D-01:** Modal picker per row (not inline cascading dropdowns). Legacy text-only rows stay editable as plain text; port FKs only populate when engineer explicitly opens picker.
- **D-02:** Side-by-side modal layout — source-left, destination-right; mirrors "A → B" mental model; collapses to stacked on phone portrait.
- **D-03:** Compact chain-link (🔗) icon between From and To columns in a thin separator column (adds 1 column → 9 total). Faded outline = unset (FK NULL), filled teal = set (FK populated). Teal matches `btn-teal` palette already used on the Save button at `cable-schedule/edit.blade.php` line 47.
- **D-04:** Picker overwrites From/To text inputs with canonical labels on Apply. Format: `"{Manufacturer} {Model} ({Port label})"` (e.g. `"Crestron HD-MD-400 (HDMI 1)"`). Single source of truth — text always reflects FK selection. Engineers wanting freeform text just don't open the picker on that row.

**Claude's Discretion (sensible defaults locked for clarity):**
- **Compatibility matrix:** `config/cables.php` (new file). Exact-match by default + named-exception allowlist for known-interoperable pairs (HDMI↔DisplayPort active adapter, USB-C↔Thunderbolt, RJ45↔SFP+ via SFP module). Phase 23 reads the same config for signal-type colour coding.
- **Override workflow:** Inline yellow warning banner inside the picker modal + REQUIRED text field "Override reason (required)" before Apply accepts. Persists to new `connector_override_note` text column (nullable, max 500). No "block-the-save" option — engineer always overrides.
- **Backfill command:** `php artisan cables:backfill-port-fks {project?}` — idempotent, targets all rows (quote-imported + engineer-added). Default = dry-run (logs per-row decisions, writes nothing). `--apply` flag actually populates FKs. Per-row outcome categories: `matched` (single deterministic match) / `ambiguous` (multiple matches, left NULL) / `no-device-match` (text unresolved, left NULL) / `already-set` (skipped). Auto-fire on quote import DEFERRED.

### Claude's Discretion

(All three discretion areas locked above. No remaining gray areas.)

### Deferred Ideas (OUT OF SCOPE)

- **Auto-fire backfill on quote import** — v2.1 polish; would hook into `CableScheduleGeneratorService` with dry-run-flipped-to-auto-apply. Out of Phase 22 scope to avoid touching v1.0-non-negotiable quote import pipeline.
- **Bulk port re-assignment** ("change all HDMI cables from device X to device Y") — better fit for Phase 24 stencil curation UI.
- **AI assist for ambiguous port mapping** — Phase 25's explicit deliverable.
- **Wholesale text-input removal** — once port FK adoption is high (Phase 24 + Phase 25), a future v2.1+ phase could deprecate `from_location` / `to_location`. NOT a v2.0 conversation.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| DRAW-37 | `cable_schedule_items.source_device_id`, `source_port_id`, `dest_device_id`, `dest_port_id` FK columns (nullable for legacy rows) | §"Migration Shape" — 4 columns + `connector_override_note`, all nullable, FK to `devices` + `device_ports` with `nullOnDelete`. Phase 21 D-02 confirms `device_ports.id` exists (bigint PK, FK cascade from `device_stencils`). |
| DRAW-38 | Cascading dropdown UI: source device → source port → dest device → dest port; client-side signal_type filtering | §"Modal Picker Architecture" — Alpine.js `x-data` component fetches `Project::devicesWithStencils()` JSON (already cached cross-project), x-for over devices, x-for over filtered ports. Form integration via hidden `<input name="items[N][source_port_id]">` fields. |
| DRAW-39 | Connector-compatibility validation at save — warning, not hard block; override with note | §"Compatibility Matrix" — config/cables.php exact-match + allowlist; modal inline yellow banner; `connector_override_note` column. |
| DRAW-40 | Auto-derive port FKs from quote `cable_list` "X to Y" naming where each side has exactly one matching connector | §"Backfill Algorithm" — 4-step resolution: device name match → connector-type filter → deterministic single-port pick → write FK. Quote `cable_list` is AI-populated from `cable_hints` and is a flat array on `ProjectPackage.cable_list` (cast to array). |
| DRAW-41 | One-shot backfill command — populates port FKs where unambiguous, leaves nullable where ambiguous, reports per-row | §"Backfill Algorithm" — `php artisan cables:backfill-port-fks {project?} {--apply}` mirrors `rams:refresh-compliance --dry-run` pattern. Logs categorised per-row decisions. |
</phase_requirements>

## Summary

Phase 22 is a **strictly additive, well-scoped data + UI extension** sitting between Phase 21 (which shipped the `device_stencils` + `device_ports` tables and `Project::devicesWithStencils()` accessor) and Phase 23 (which will render port-to-port routing). Every locked decision in CONTEXT.md is implementable with existing Laravel 12 + Alpine.js patterns already used elsewhere in the codebase — no new dependencies needed.

The core risk is **NOT in the schema migration or backend** — both are trivial. The risk is in two soft areas:
1. **D-04's "picker overwrites text"** semantic — easy to misimplement as a one-way bind that confuses engineers when they edit text after picking ports
2. **D-10 don't-break-v1.3 invariant** — easy to introduce a `with('sourcePort')` eager-load somewhere that breaks legacy NULL-FK rendering paths if Eloquent doesn't gracefully handle null FKs (it does, but tests must prove it)

The backfill command's deterministic algorithm is constrained — `cable_list` is a flat array of cable hints with no canonical schema, so the matcher needs to operate over the same enriched `Project::devicesWithStencils()` line + port shape that the picker uses, NOT against `cable_list` text directly. This is documented in §"Backfill Algorithm".

**Primary recommendation:** Plan as 3 plans across 2 waves: Wave 1 = migration + model + config + service (foundation, parallel-safe); Wave 2 = picker UI + backfill command (both consume Wave 1). Keep TDD RED→GREEN per task per Phase 21 precedent.

## Standard Stack

### Core (already installed — verified via composer.lock)
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| laravel/framework | ^12.0 | MVC + Eloquent + migrations + artisan | Project foundation [VERIFIED: composer.json] |
| Alpine.js | (bundled via Vite) | Reactive picker modal | Already in stack per CLAUDE.md; used in `site-survey/_room-form.blade.php` (lines 120, 579, 637, 847 — x-data + x-for cascading rows) [VERIFIED: grep] |
| PHPUnit | ^11.5.3 | Feature + Unit tests | Phase 21 pattern: tests/Feature/Drawings/ with RefreshDatabase [VERIFIED: composer.json + ProjectDevicesWithStencilsTest.php] |
| Mockery | ^1.6 | Test doubles for service unit tests | Phase 21 pattern: DeviceStencilCacheServiceTest mocks AutoGenericStencilGenerator [VERIFIED: SUMMARY] |

### Supporting (zero new dependencies needed)
| Pattern | Where Used | When to Use |
|---------|------------|-------------|
| `firstOrCreate` cache | DeviceStencilCacheService (Phase 21) | Cross-project port lookup is already cached; the picker reads via `Project::devicesWithStencils()` which warms the cache on first read |
| Anonymous migration class | All Laravel 11+ migrations | Phase 22's migration uses the same `return new class extends Migration` shape as `2026_05_10_120000_create_device_stencils_and_device_ports.php` |
| Artisan signature options | RamsRefreshComplianceCommand | `{project? : ID}` positional + `{--apply : Actually write changes}` flag pattern [VERIFIED: `app/Console/Commands/RamsRefreshComplianceCommand.php` lines 39-41] |
| Hidden form input + form-encoded POST | Existing `cable-schedule/edit.blade.php` | Picker writes into hidden `<input name="items[N][source_port_id]">` fields that flow through the existing PUT handler |
| Inline yellow banner inside modal | Pattern not yet in codebase but trivially Blade+CSS | New — matches existing `.alert-warning` / mini-warning chrome from edit-action-bar pattern |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Modal port picker (D-01 locked) | Inline cascading dropdowns per row | Locked OUT by D-01 — wouldn't fit on tablets, and engineers need this to work on mobile mid-install |
| Alpine.js picker | Vanilla JS (current edit.blade.php style) | Both viable; Alpine recommended for cascading reactivity (x-data + x-for + x-show), vanilla works but doubles the LOC. CONTEXT explicitly approves Alpine here. |
| FK to `devices` table (project-scoped) | FK to `device_stencils` (cross-project) | Both make sense. **Recommend `devices`** — engineers want to point at THIS project's THIS unit (a project may have 3 Samsung QM65 displays, each on a different port). FK to `device_stencils` would identify the model but not the unit. Phase 21's `Project::devicesWithStencils()` already joins `devices` to `device_stencils`. Note: `devices` table is per-project, so `source_device_id` and `dest_device_id` are inherently project-scoped via the device's `project_id`. |

**Installation:** Nothing to install. All dependencies present.

**Version verification:** [VERIFIED 2026-05-12]
- Laravel framework ^12.0 (composer.json:11)
- PHP 8.4.19 active (`php -v` on dev) — production runs PHP 8.2+ minimum per composer.json
- Alpine.js loaded via `@vite(['resources/js/app.js'])` per existing pattern in `resources/views/components/document-edit-drawer.blade.php`

## Architecture Patterns

### Recommended Plan Structure
```
22-01-schema-model-config-PLAN.md       # Wave 1
  - Migration: 4 FK columns + connector_override_note + indexes
  - CableScheduleItem fillable + 4 belongsTo relations + casts
  - config/cables.php with compatibility matrix + connector aliases
  - CableConnectorCompatibilityService (pure function service)
  - Tests: model fillable, migration roundtrip, compat-matrix unit tests

22-02-picker-ui-update-handler-PLAN.md  # Wave 2 (depends on 22-01)
  - resources/views/cable-schedule/_port-picker-modal.blade.php (Alpine component)
  - Edit a single source-of-truth: edit.blade.php gains chain-link icon column + modal include
  - CableScheduleController@update: extend validation rules + persist 5 new fields
  - Tests: picker writes correct FKs, save warns on incompatible, override-note persists, legacy row save unchanged

22-03-backfill-command-PLAN.md          # Wave 2 (parallel-safe with 22-02 per Phase 21 sequential-execution precedent — recommend running 22-02 first)
  - app/Console/Commands/BackfillCablePortFksCommand.php
  - app/Services/Cable/CablePortFkResolverService.php (pure deterministic matcher)
  - Tests: matched / ambiguous / no-device-match / already-set categorisation
```

### Pattern 1: Anonymous Migration with FK Constraints
**What:** Laravel 11+ anonymous-class migration adds nullable FK columns to existing table.
**When to use:** Phase 22 schema extension. Mirrors `2026_05_10_120000_create_device_stencils_and_device_ports.php`.
**Example:**
```php
// Source: Laravel 12 docs + Phase 21 precedent
// File: database/migrations/2026_05_15_000000_add_port_fks_to_cable_schedule_items.php
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cable_schedule_items', function (Blueprint $table) {
            $table->foreignId('source_device_id')->nullable()->after('to_location')
                ->constrained('devices')->nullOnDelete();
            $table->foreignId('source_port_id')->nullable()->after('source_device_id')
                ->constrained('device_ports')->nullOnDelete();
            $table->foreignId('dest_device_id')->nullable()->after('source_port_id')
                ->constrained('devices')->nullOnDelete();
            $table->foreignId('dest_port_id')->nullable()->after('dest_device_id')
                ->constrained('device_ports')->nullOnDelete();
            $table->text('connector_override_note')->nullable()->after('dest_port_id');

            // Index pair for Phase 23's renderer port-lookup queries
            $table->index(['source_port_id', 'dest_port_id'], 'cable_schedule_items_port_pair_idx');
        });
    }

    public function down(): void
    {
        Schema::table('cable_schedule_items', function (Blueprint $table) {
            $table->dropIndex('cable_schedule_items_port_pair_idx');
            $table->dropConstrainedForeignId('source_device_id');
            $table->dropConstrainedForeignId('source_port_id');
            $table->dropConstrainedForeignId('dest_device_id');
            $table->dropConstrainedForeignId('dest_port_id');
            $table->dropColumn('connector_override_note');
        });
    }
};
```

**ON DELETE rationale:** `nullOnDelete()` not `cascadeOnDelete()`. If a Device row is deleted (e.g. engineer corrects equipment list mid-project), the cable schedule shouldn't lose its row — it should fall back to text-only, same as a legacy NULL-FK row. The legacy `from_location` + `to_location` text persists. This is the D-10 "don't break v1.3" invariant in disguise.

**[ASSUMED]** `devices` table has `bigint PK id` matching what `foreignId('source_device_id')` resolves to. **Verification step for planner:** Read `app/Models/Device.php` and confirm; if Device uses a different PK type, switch to explicit `unsignedBigInteger` + `foreign` syntax.

### Pattern 2: Alpine.js Cascading Dropdown
**What:** `x-data` component holds full device list + filters source-side and dest-side ports reactively.
**When to use:** Inside the picker modal Blade partial.
**Example:**
```html
<!-- Source: existing pattern in resources/views/site-survey/_room-form.blade.php lines 637-695 -->
<!-- File: resources/views/cable-schedule/_port-picker-modal.blade.php -->
<div x-data="portPicker({
        devices: @js($devicesWithPorts),
        compatibility: @js(config('cables.compatibility_aliases')),
        initial: { sourceDeviceId: null, sourcePortId: null, destDeviceId: null, destPortId: null }
     })"
     x-show="open"
     @open-port-picker.window="handleOpen($event.detail)"
     class="port-picker-backdrop">

  <div class="port-picker-modal">
    <div class="picker-grid">
      <!-- SOURCE column -->
      <section>
        <h3>SOURCE</h3>
        <label>Device</label>
        <select x-model.number="sourceDeviceId" @change="sourcePortId = null">
          <option :value="null">— pick device —</option>
          <template x-for="d in devices" :key="d.id">
            <option :value="d.id" x-text="d.label"></option>
          </template>
        </select>

        <label>Port</label>
        <select x-model.number="sourcePortId" :disabled="!sourceDeviceId">
          <option :value="null">— pick port —</option>
          <template x-for="p in portsForDevice(sourceDeviceId)" :key="p.id">
            <option :value="p.id" x-text="p.label + ' (' + p.connector_type + ')'"></option>
          </template>
        </select>
      </section>

      <!-- DEST column (mirrors source; ports filtered by compatibility) -->
      <section>
        <h3>DESTINATION</h3>
        <!-- ...same shape; destPort options call portsForDevice(destDeviceId).filter(p => isCompatible(sourcePort, p)) -->
      </section>
    </div>

    <!-- Incompatible-pair warning + required override note -->
    <div x-show="hasIncompatibleSelection()" class="picker-warning-banner">
      <p>⚠ HDMI on source does not normally terminate on RJ45 on destination.</p>
      <label>Override reason (required)</label>
      <textarea x-model="overrideNote" maxlength="500" required></textarea>
    </div>

    <div class="picker-actions">
      <button type="button" @click="open = false">Cancel</button>
      <button type="button" @click="apply()" :disabled="!canApply()">Apply</button>
    </div>
  </div>
</div>
```

**Apply pattern:**
- Picker dispatches `port-picker:applied` event with `{ rowIndex, sourceDeviceId, sourcePortId, destDeviceId, destPortId, overrideNote, sourceLabel, destLabel }`
- Row-level Alpine listener (or vanilla event listener on each row) writes values into `items[N][source_device_id]`, `items[N][from_location]` (the canonical label), `items[N][dest_device_id]`, `items[N][to_location]`, `items[N][connector_override_note]`, and toggles the chain-link icon to its filled state
- D-04 invariant: text inputs (`from_location` / `to_location`) get OVERWRITTEN on Apply, not merged

### Pattern 3: Pure-Function Compatibility Service
**What:** Stateless service over a config-driven matrix. Returns `bool` compatibility + optional `string` warning.
**When to use:** Backend validation in `CableScheduleController@update`; frontend uses the same data via `@js(config(...))` so both layers agree.
**Example:**
```php
<?php
// File: app/Services/Cable/CableConnectorCompatibilityService.php

namespace App\Services\Cable;

class CableConnectorCompatibilityService
{
    /**
     * @param string $sourceConnector connector_type of the source port (e.g. 'hdmi')
     * @param string $destConnector   connector_type of the dest port (e.g. 'rj45')
     * @return array{compatible: bool, reason: ?string}
     */
    public function check(string $sourceConnector, string $destConnector): array
    {
        $src = strtolower(trim($sourceConnector));
        $dst = strtolower(trim($destConnector));

        if ($src === $dst) {
            return ['compatible' => true, 'reason' => null];
        }

        $aliases = (array) config('cables.compatibility_aliases', []);
        foreach ($aliases as $alias) {
            if (($alias['from'] === $src && $alias['to'] === $dst)
                || ($alias['from'] === $dst && $alias['to'] === $src)) {
                return ['compatible' => true, 'reason' => $alias['note'] ?? null];
            }
        }

        return [
            'compatible' => false,
            'reason' => sprintf('Connector mismatch: %s → %s', $src, $dst),
        ];
    }
}
```

### Anti-Patterns to Avoid

- **DON'T eager-load port relations everywhere** — adding `with('sourcePort', 'destPort')` to `CableSchedule::items()` would force every legacy NULL-FK row through a null-relationship resolve. Eloquent handles this fine, but it adds 4 LEFT JOIN queries per page load. Only eager-load on the picker page; leave the existing XLSX export + bound-PDF section untouched. **D-10 invariant.**

- **DON'T cascade-delete cable_schedule_items when devices are deleted** — `nullOnDelete()` not `cascadeOnDelete()`. The cable text representation (from_location / to_location) survives device deletion.

- **DON'T validate compatibility server-side as a hard block** — DRAW-39 explicitly says warning, not hard block. The override-with-note flow REQUIRES the save to succeed even when incompatible. Server-side validation rule for `connector_override_note` is `nullable, string, max:500` — NOT conditionally required (the modal enforces the requirement on the client side; if the engineer somehow bypasses, the row still saves).

- **DON'T expose backfill command via HTTP** — strictly `php artisan` CLI per DRAW-41. Mirrors `rams:refresh-compliance` and `pdf:smoke-test`.

- **DON'T write text inputs back from FK on every form re-render** — the canonical label is set ONCE at Apply time (D-04). Subsequent edits to the row text don't re-derive from FK. If user manually edits `from_location` text AFTER picking ports, the FK stays — both sources of truth co-exist; the renderer (Phase 23) prefers FK. Document this in CableScheduleItem model docblock.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Modal backdrop / focus trap | Custom modal CSS | Existing modal styles + Alpine `x-show` + `x-trap` | `document-edit-drawer.blade.php` already shows the pattern; the spike modal uses similar Alpine + Tailwind backdrop. Roll a thin picker-specific modal **shell** but inherit chrome (backdrop, escape-to-close, animation classes) from existing patterns. |
| Connector compatibility table | Hand-coded if/else ladder | `config/cables.php` | DRAW-39's "warning rather than hard block" + Phase 25's chat-edit + Phase 23's signal-type colour map all need the same data. Single config keeps them consistent. |
| Per-row event coordination | Document-wide global state | Per-row `data-row-index` + dispatched events | Alpine `$dispatch` from row → page-level listener `@port-picker:applied.window` resolves the correct row by index; pattern mirrors existing `open-doc-chat` event in document-edit-drawer. |
| Form encoding | Custom JSON POST endpoint | Existing form-encoded POST + hidden inputs | The existing `cable-schedules.update` PUT route accepts `items[N][...]`; just add 5 new keys to the validation rules. Zero new routes needed. |
| CLI option parsing | Custom argv parsing | Artisan command signature DSL | `{project? : ID} {--apply : Actually write changes}` is the Laravel idiom (verified in `RamsRefreshComplianceCommand.php`). |
| Port lookup query per row | N+1 select-per-row | Eager-load via `Project::devicesWithStencils()` | Phase 21 D-07 already returns enriched lines with stencils + ports. The picker fetches this ONCE on modal open, not per-row. |

**Key insight:** Phase 21 did almost all the heavy lifting. Phase 22 is "wire-up + UI shell" — the data layer (cross-project caching, port catalog, signal_type / connector_type metadata) is fully built. Avoid the temptation to reinvent Phase 21's accessor.

## Runtime State Inventory

> Phase 22 is **strictly additive schema + new code**. There are no renames or refactors. No runtime state to migrate beyond running the migration itself.

| Category | Items Found | Action Required |
|----------|-------------|------------------|
| Stored data | None — the 4 new FK columns + override-note column are net-new. No existing data structure renames. | Migration creates columns nullable; existing `cable_schedule_items` rows get NULL FKs (the "legacy row" state the renderer must handle). Backfill command populates where unambiguous. |
| Live service config | None — no n8n / Datadog / external service references the cable schedule data layer. | Nothing to update. |
| OS-registered state | None — no Task Scheduler / pm2 / systemd state references cable schedules. | Nothing to update. |
| Secrets/env vars | None — no new secrets. `config/cables.php` is non-sensitive (compatibility aliases are public engineering knowledge). | Nothing to update. |
| Build artifacts | None — Phase 22 adds no JS bundle entry points. The picker Blade partial loads via Alpine `@vite(['resources/js/app.js'])` which is already invoked by every authenticated page. | Nothing to rebuild. |

**Nothing found in any category** — confirmed by reviewing CONTEXT.md `<deferred>` (no system-state migration tasks listed) and grepping for `cable_schedule` across `app/` + `database/` + `resources/`.

## Common Pitfalls

### Pitfall 1: D-10 "Don't Break v1.3" Subtle Regression via Eager Loading
**What goes wrong:** A planner adds `protected $with = ['sourcePort', 'destPort']` to the `CableScheduleItem` model for picker-page convenience. The change ripples to every place that queries `CableScheduleItem` — including the XLSX export job, the bound-PDF cable section, and any future read path — adding 4 LEFT JOINs per row even for legacy NULL-FK rows.
**Why it happens:** Eloquent's `$with` is class-level; tempting to add for "every page benefits".
**How to avoid:** NEVER add `$with` for FK relations. Eager-load only at the call site that needs it: `$cableSchedule->load('items.sourcePort', 'items.destPort')` ONLY in `CableScheduleController@edit`. Backend tests must include an explicit "legacy row XLSX export produces byte-identical output" assertion (load Plan 22-02's update test fixture, regenerate XLSX, compare).
**Warning signs:** Test suite slows down measurably between Plan 22-01 and 22-02. Production query log shows JOINs against `device_ports` on the cable_schedule download endpoint.

### Pitfall 2: D-04 Text/FK Drift After Manual Edit
**What goes wrong:** Engineer picks ports → labels populate. Engineer then manually edits `from_location` text to add a custom note. Save. Now FK and text disagree. Phase 23 renderer uses FK and shows the canonical label; engineer thought they were customising the visible label.
**Why it happens:** D-04 says the picker OVERWRITES on Apply but doesn't say what happens on subsequent manual edits.
**How to avoid:** This is BY DESIGN per CONTEXT.md ("text always reflects the FK selection. Engineers who want custom freeform text simply don't open the picker on that row"). Document this clearly in `CableScheduleItem` model docblock + in a small inline help-text under the picker trigger: "Use the picker to keep text + ports in sync. For custom text, leave the picker closed." Phase 23 prefers FK over text per CONTEXT.md `<code_context>`.
**Warning signs:** Engineer field reports of "I edited the text but it didn't show up in the schematic".

### Pitfall 3: Backfill Command Matches Across Devices via Loose `from_location` Contains-Match
**What goes wrong:** Backfill matches `from_location` "Crestron HD-MD-400" against ANY device whose name contains "Crestron" — the first device wins, even if the project has 3 different Crestron devices.
**Why it happens:** Naive `str_contains` matching.
**How to avoid:** Match against `manufacturer + model` AND `part_no`, with case-insensitive normalised comparison (mirroring `DeviceStencil::normalisePartNumber`). If multiple devices match, categorise as `ambiguous` and leave FK NULL. Document the matching algorithm explicitly in the command's class docblock.
**Warning signs:** Backfill assigns FKs that engineers then correct manually — a sign the matcher was too loose.

### Pitfall 4: Connector Validation Treats Empty/Null connector_type as Match
**What goes wrong:** Many Tier 1.5 stencils (91 of 96 per Phase 21 Plan 02 SUMMARY) have empty ports list. Their connector_type is empty. The compatibility check `empty === empty` returns "compatible" silently.
**Why it happens:** PHP loose comparison; empty-string equality.
**How to avoid:** Compatibility service treats empty connector_type as "unknown" and returns `compatible: true, reason: 'connector type not catalogued — assume compatible'`. The picker doesn't show a warning, but the row is flagged for Phase 24 curation queue. This is the correct semantic: Tier 1.5 stencils' missing ports aren't a wrong-port error, they're missing data.
**Warning signs:** Warning banner never fires in production but ports are obviously mismatched in the field.

### Pitfall 5: Modal Triggered Inside a `<form>` — Apply Submits the Form
**What goes wrong:** Picker's "Apply" button is `<button>` inside the existing `<form id="edit-form">`. Default `<button>` type is `submit`. Clicking Apply submits the whole edit form, losing other unsaved rows.
**Why it happens:** Forgotten `type="button"` attribute.
**How to avoid:** ALL picker modal buttons MUST carry explicit `type="button"`. Mirror existing `cable-schedule/edit.blade.php` line 74 — every action button there is `type="button"`. Add a Pint-runnable convention check or a feature test that asserts "clicking Apply does not submit the form".
**Warning signs:** Engineer reports of "my row edits disappear when I pick ports".

## Code Examples

Verified patterns from the codebase:

### Compat Config Style (mirrors config/rams.php)
```php
<?php
// Source: pattern from config/rams.php + config/drawings.php
// File: config/cables.php (NEW)

return [
    /*
    |--------------------------------------------------------------------------
    | Connector Compatibility Matrix (Phase 22 — DRAW-39)
    |--------------------------------------------------------------------------
    | Exact match is the default. Listed aliases extend the allowlist with
    | named exceptions for known-interoperable pairs.
    |
    | The same data drives:
    |  - Picker modal client-side filter (resources/views/cable-schedule/_port-picker-modal.blade.php)
    |  - Server-side validation warning (App\Services\Cable\CableConnectorCompatibilityService)
    |  - Phase 23's signal-type colour coding (forthcoming — uses 'signal_type' map)
    */
    'compatibility_aliases' => [
        ['from' => 'hdmi',   'to' => 'dp',         'note' => 'HDMI ↔ DisplayPort via active adapter'],
        ['from' => 'usb-c',  'to' => 'thunderbolt','note' => 'USB-C ↔ Thunderbolt 3/4 backwards-compatible'],
        ['from' => 'rj45',   'to' => 'sfp-plus',   'note' => 'RJ45 ↔ SFP+ via SFP module'],
        ['from' => 'usb-c',  'to' => 'hdmi',       'note' => 'USB-C → HDMI via DisplayPort Alt Mode adapter'],
        // ... more pairs as engineering documents them
    ],

    /*
    |--------------------------------------------------------------------------
    | Signal-type colour map (mirrors config/drawings.php — Phase 23 reads here too)
    |--------------------------------------------------------------------------
    */
    'signal_type_colours' => [
        'audio'   => '#C0392B',
        'video'   => '#2980B9',
        'control' => '#27AE60',
        'network' => '#8E44AD',
        'usb'     => '#E67E22',
        'speaker' => '#16A085',
        'power'   => '#7F8C8D',
        'unknown' => '#000000',
    ],
];
```

### CableScheduleItem Model With Relations (extension)
```php
<?php
// Source: existing app/Models/CableScheduleItem.php — Phase 22 EXTENDS, not replaces

namespace App\Models;

use App\Models\Device;
use App\Models\DevicePort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CableScheduleItem extends Model
{
    protected $fillable = [
        'cable_schedule_id',
        'cable_id',
        'from_location',
        'to_location',
        'cable_type',
        'cores',
        'approx_length_m',
        'notes',
        'sort_order',
        // ── Phase 22 additions ─────────────────────────────────────────
        'source_device_id',
        'source_port_id',
        'dest_device_id',
        'dest_port_id',
        'connector_override_note',
    ];

    protected $casts = [
        'approx_length_m' => 'float',
    ];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(CableSchedule::class, 'cable_schedule_id');
    }

    // ── Phase 22 port-level relations ─────────────────────────────────
    // D-10 guard: NEVER add these to $with — eager-load only at the
    // call site (the picker page). Legacy NULL-FK rows resolve all four
    // belongsTo() relations as null without firing a DB query, but
    // declaring $with would force 4 LEFT JOINs on every read.

    public function sourceDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'source_device_id');
    }

    public function sourcePort(): BelongsTo
    {
        return $this->belongsTo(DevicePort::class, 'source_port_id');
    }

    public function destDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'dest_device_id');
    }

    public function destPort(): BelongsTo
    {
        return $this->belongsTo(DevicePort::class, 'dest_port_id');
    }
}
```

### CableScheduleController@update Validation Extension
```php
// Source: existing handler at app/Http/Controllers/CableScheduleController.php:117
// Phase 22 ADDS 5 keys to the existing validation array.

$request->validate([
    'status'                 => ['nullable', 'in:draft,final'],
    'items'                  => ['nullable', 'array'],
    'items.*.cable_id'             => ['nullable', 'string', 'max:50'],
    'items.*.from_location'        => ['nullable', 'string', 'max:200'],
    'items.*.to_location'          => ['nullable', 'string', 'max:200'],
    'items.*.cable_type'           => ['nullable', 'string', 'max:100'],
    'items.*.cores'                => ['nullable', 'string', 'max:50'],
    'items.*.approx_length_m'      => ['nullable', 'numeric', 'min:0'],
    'items.*.notes'                => ['nullable', 'string', 'max:500'],
    // ── Phase 22 additions ─────────────────────────────────────────────
    'items.*.source_device_id'      => ['nullable', 'integer', 'exists:devices,id'],
    'items.*.source_port_id'        => ['nullable', 'integer', 'exists:device_ports,id'],
    'items.*.dest_device_id'        => ['nullable', 'integer', 'exists:devices,id'],
    'items.*.dest_port_id'          => ['nullable', 'integer', 'exists:device_ports,id'],
    'items.*.connector_override_note' => ['nullable', 'string', 'max:500'],
]);
```

**Critical:** the existing handler at line 137 does `$cableSchedule->items()->delete()` and re-creates from scratch. The new fields land via `array_merge($item, [...])` at line 140 — they flow through automatically IF they appear in `$fillable`. The form data must arrive with the right keys; the picker writes them via hidden inputs.

### Backfill Command Signature (mirrors RamsRefreshComplianceCommand)
```php
<?php
// Source: pattern from app/Console/Commands/RamsRefreshComplianceCommand.php:39-41
// File: app/Console/Commands/BackfillCablePortFksCommand.php (NEW)

protected $signature = 'cables:backfill-port-fks
                        {project? : Project ID to scope the backfill to (default: all projects)}
                        {--apply : Actually write port FKs (default: dry-run reports only)}';

protected $description = 'Resolve and populate port-level FKs on cable_schedule_items where deterministic. Idempotent and dry-run by default.';
```

## Runtime State Inventory

(See section above — all 5 categories explicitly addressed. Phase 22 has no runtime state migration.)

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| `Schema::table` + raw FK SQL | `foreignId(...)->constrained()->nullOnDelete()` | Laravel 8+ | One-line FK declaration with named constraint; reverse with `dropConstrainedForeignId` |
| Vanilla JS inline `onclick` in edit forms | Alpine `x-data` reactive components | Project-wide (already in stack) | Cleaner state for cascading dropdowns; survives partial DOM updates |
| Class-based migrations (`class MyMigration extends`) | Anonymous-class migrations (`return new class extends`) | Laravel 9+ | Eliminates class-name collisions when timestamps overlap |
| `--force` flag for destructive commands | `--apply` flag for state-changing commands | This project (RamsRefreshComplianceCommand precedent) | Dry-run default protects against accidental writes |

**Deprecated/outdated:** None relevant to Phase 22.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `devices` table PK is `bigint id` (compatible with `foreignId()->constrained('devices')`) | Migration | Planner must confirm via `app/Models/Device.php` read. If wrong, switch to explicit `unsignedBigInteger` + `foreign` syntax. LOW risk — Laravel default is bigint. |
| A2 | `Project::devicesWithStencils()` returns one entry per `equipment_list` line (not deduped by part_number) — so a project with 3 Samsung QM65 displays returns 3 entries the picker can distinguish | Picker UI | If accessor dedupes by part_number, the picker can't distinguish multiple physical units. **Verification needed in Plan 22-02 — read the accessor source or write a quick tinker check.** If deduped, Plan 22-02 must instead query `Device::where('project_id', ...)->with('stencil.ports')` directly. |
| A3 | `cable_list` (on `ProjectPackage`) doesn't have a stable schema — entries are AI-extracted via `cable_hints` and may be strings, arrays of strings, or arrays of objects depending on the prompt that generated them | Backfill algorithm | The backfill MUST NOT rely on parsing `cable_list` "X to Y" text directly. Instead, it should iterate the existing `cable_schedule_items` rows (which `CableScheduleGeneratorService` already wrote) and resolve their `from_location` + `to_location` text against the project's catalogued devices. **MEDIUM risk — re-read CONTEXT.md `<specifics>` if ambiguous.** |
| A4 | Compatibility allowlist entries are bi-directional (HDMI↔DP works the same as DP↔HDMI) | Compat config | If one-way matters (e.g. USB-A can only host upstream from USB-B), the config needs explicit direction fields. LOW risk — current AV practice treats most adapters as bidirectional at the cabling level. |
| A5 | The picker modal is rendered server-side as a Blade partial included once per page, not once per row | Modal architecture | If included per-row, the page DOM weight quadruples on a 50-row schedule. Single modal + event-driven row coordination is the Alpine pattern. ZERO risk — established by D-01. |

**A2 is the only assumption with material planning implications.** The planner should resolve it during Plan 22-02 design.

## Open Questions

1. **What happens when a backfilled FK points at a device row that gets later deleted?**
   - What we know: `nullOnDelete()` ensures the FK clears but the row survives.
   - What's unclear: does anything need to re-derive the text representation if the device that originally provided the canonical label is gone?
   - Recommendation: Document in CableScheduleItem docblock that "FK NULL after device deletion is the same state as a never-FK'd legacy row — text representation is preserved as-is, no auto-rewrite." Test this explicitly in Plan 22-02.

2. **Should the picker support "free text" for legacy-style entries (engineer wants `From: Mains via 13A spur` and no FK)?**
   - What we know: CONTEXT D-04 says "engineers who want custom freeform text simply don't open the picker on that row" — so the answer is: yes, just don't open the picker.
   - What's unclear: should the picker have a "Clear ports" button to UNDO a previous pick?
   - Recommendation: Yes — add a "Clear" button inside the picker that, on Apply, writes NULL to all 4 FK columns + the override-note, and toggles the chain-link icon back to faded. Plan 22-02 should include this as a small but UX-essential feature.

3. **Does the legacy text-only rendering (XLSX export, bound-PDF section) need to surface the port FKs in any way?**
   - What we know: CONTEXT D-10 says "no behaviour change for existing data paths". XLSX export at `CableScheduleXlsxService.php` only reads `from_location` / `to_location` / `cable_type` etc. — never the new columns.
   - What's unclear: nothing — but the planner must include a regression test that proves XLSX byte-output is unchanged for a fixture with FK-populated rows.
   - Recommendation: Plan 22-02 adds `CableScheduleXlsxRegressionTest` asserting byte-equivalent output across FK NULL and FK populated rows.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP 8.2+ | Laravel 12 + Phase 22 code | ✓ | 8.4.19 on dev (production 8.2+ per composer.json) | — |
| MySQL 8.0+ (or compatible) | Migration FK constraints | ✓ | per CLAUDE.md `127.0.0.1:3306` config | — |
| Composer + npm | Build chain | ✓ | per CLAUDE.md | — |
| Alpine.js | Picker reactivity | ✓ | bundled via Vite `resources/js/app.js` | — |
| PHPUnit 11.5+ | Test suite | ✓ | ^11.5.3 per composer.json | — |
| Laravel artisan | Backfill command | ✓ | built-in | — |
| `device_ports` + `device_stencils` tables | FK targets | ✓ | Phase 21 migrations applied 2026-05-10 | — |

**Missing dependencies with no fallback:** None.

**Missing dependencies with fallback:** None — Phase 22 introduces zero new runtime dependencies.

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit ^11.5.3 |
| Config file | `phpunit.xml` |
| Quick run command | `php artisan test --filter=Cable` (Phase 22 tests live in `tests/Feature/Cable/` and `tests/Unit/Services/Cable/` per existing naming — `tests/Feature/Cable/CableScheduleStoreDeterministicTest.php` is the precedent) |
| Full suite command | `php artisan test` |
| TDD pattern | Phase 21 RED→GREEN per task — Wave 0 tests FAIL with explicit "column doesn't exist" or "service class doesn't exist"; Wave 1 makes them GREEN |

### Phase Requirements → Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| DRAW-37 | 4 FK columns + connector_override_note exist with correct nullability + FK constraints | Feature (migration) | `php artisan test --filter=CableScheduleItemMigrationTest` | ❌ Wave 0 |
| DRAW-37 | CableScheduleItem `$fillable` allows port FK keys; belongsTo relations resolve | Unit (model) | `php artisan test --filter=CableScheduleItemRelationsTest` | ❌ Wave 0 |
| DRAW-38 | Picker writes correct items[N][source_port_id] / dest_port_id into hidden inputs and update handler persists them | Feature (controller) | `php artisan test --filter=CableScheduleUpdatePersistsPortFksTest` | ❌ Wave 0 |
| DRAW-38 | Picker filters dest-side ports by signal_type compat against picked source port | Unit (frontend service) | `php artisan test --filter=CableConnectorCompatibilityServiceTest` | ❌ Wave 0 |
| DRAW-39 | Compat service returns compatible/incompatible + reason; allowlist pairs match bidirectionally | Unit | `php artisan test --filter=CableConnectorCompatibilityServiceTest` | ❌ Wave 0 |
| DRAW-39 | Update handler accepts and persists `connector_override_note` when ports are incompatible | Feature | `php artisan test --filter=CableScheduleUpdatePersistsOverrideNoteTest` | ❌ Wave 0 |
| DRAW-40 | Backfill matches single-connector deterministic source/dest, leaves ambiguous as NULL, reports per-row | Feature (artisan) | `php artisan test --filter=BackfillCablePortFksCommandTest` | ❌ Wave 0 |
| DRAW-41 | Dry-run default writes nothing; --apply actually persists FKs; idempotent (re-run does nothing) | Feature (artisan) | `php artisan test --filter=BackfillCablePortFksCommandTest` | ❌ Wave 0 |
| **D-10 regression** | XLSX export byte-output unchanged for FK-NULL legacy rows AND for newly-FK-populated rows | Feature | `php artisan test --filter=CableScheduleXlsxRegressionTest` | ❌ Wave 0 |
| **D-10 regression** | SchematicGeneratorService + SchematicD2SourceBuilder produce byte-identical SVG for a project with NULL-FK cables | Feature | Existing `SchematicGeneratorServiceTest` (extend with explicit "NULL FK is fine" case) | ✅ exists; extend |

### Sampling Rate
- **Per task commit:** `php artisan test --filter=Cable` (~5-10s)
- **Per wave merge:** `php artisan test --testsuite=Feature --filter='Cable|Drawings'` (~30s — catches Phase 21 ↔ 22 cross-impact)
- **Phase gate:** Full `php artisan test` green before `/gsd-verify-work`. Phase 21 baseline was 1633 tests; Phase 22 adds ~25-35 new tests with zero pre-existing regression expected.

### Wave 0 Gaps
- [ ] `tests/Feature/Cable/CableScheduleItemMigrationTest.php` — DRAW-37 schema assertions
- [ ] `tests/Unit/Models/CableScheduleItemRelationsTest.php` — fillable + 4 belongsTo + null-FK resolution
- [ ] `tests/Unit/Services/Cable/CableConnectorCompatibilityServiceTest.php` — compat matrix
- [ ] `tests/Feature/Cable/CableScheduleUpdatePersistsPortFksTest.php` — picker → controller round-trip
- [ ] `tests/Feature/Cable/CableScheduleUpdatePersistsOverrideNoteTest.php` — override-note path
- [ ] `tests/Feature/Cable/BackfillCablePortFksCommandTest.php` — dry-run + apply + categorisation + idempotency
- [ ] `tests/Feature/Cable/CableScheduleXlsxRegressionTest.php` — D-10 invariant: byte-identical XLSX
- [ ] Optionally `tests/Browser/CablePortPickerTest.php` (Dusk) — but D-10 invariant testing via Feature tests is sufficient; Dusk not currently in use in this repo, don't introduce here

**No framework install needed** — PHPUnit ^11.5.3, Mockery ^1.6, FakerPHP ^1.23 all present in composer.json.

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | yes | Existing — `abort_unless($cableSchedule->user_id === auth()->id(), 403)` on every cable-schedule endpoint |
| V3 Session Management | yes | Existing — Laravel session driver = database, 120-min lifetime |
| V4 Access Control | yes | Existing — `abort_unless` ownership gate at `CableScheduleController@edit` line 110 and `@update` line 119 covers Phase 22 changes automatically |
| V5 Input Validation | yes | New rules added: `exists:devices,id`, `exists:device_ports,id`, `integer`, `max:500` for override-note. Pattern matches existing validation rules in `@update`. |
| V6 Cryptography | no | No new cryptographic operations |

### Known Threat Patterns for Laravel + Eloquent + Alpine.js

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Mass assignment via picker hidden fields | Tampering | Whitelist via `$fillable` on `CableScheduleItem`; `exists:devices,id` validation rule prevents pointing at another project's device |
| Cross-project FK injection (engineer in project A picks a device from project B) | Tampering / Information Disclosure | `exists:devices,id` is necessary but NOT sufficient — must also assert `$device->project_id === $cableSchedule->project_id` server-side. **Plan 22-02 MUST add a custom Rule or controller-side check** that walks each `items[N][source_device_id]` and confirms project scope. |
| XSS via override-note text | Tampering | Blade `{{ }}` escaping (default in Blade). Override note never rendered via `{!! !!}`. |
| CSRF on update endpoint | Tampering | Existing `@csrf` directive on the edit form. No new endpoints added. |
| SQL injection via backfill command CLI arg | Tampering | `cables:backfill-port-fks 5` — the `{project? : ID}` arg is cast to int; Eloquent's where bindings parameterise. Mirror `RamsRefreshComplianceCommand::handle` cast pattern: `(int) $this->option('id')`. |
| Backfill writes to wrong tenant's rows | Authorisation | When `{project?}` arg is omitted, backfill iterates ALL projects. The command is admin-only by convention (CLI access = admin). Document this in command docblock; do NOT expose via HTTP. |

**Phase 22 threat is bounded — the cross-project FK injection at A4 is the only new attack vector. Plan 22-02 acceptance criteria MUST include a test asserting "engineer cannot pick a device that belongs to a different project".**

## Sources

### Primary (HIGH confidence)
- `app/Models/CableScheduleItem.php` — current model shape, no FK columns yet
- `app/Http/Controllers/CableScheduleController.php` — existing update handler, validation rules pattern
- `app/Services/CableScheduleGeneratorService.php` — existing import path (line 88-98 CableScheduleItem::create — NOT touched)
- `app/Services/Drawings/SchematicGeneratorService.php` + `SchematicD2SourceBuilder.php` — v1.3 surface; verified no `CableScheduleItem` references (grep)
- `app/Services/Drawings/DrawingDataResolverService.php` — cable adjacency builder; consumes `ProjectDataService::resolve()` (not direct `cable_schedule_items` table)
- `app/Models/DevicePort.php` + `app/Models/DeviceStencil.php` — Phase 21 model API surface; SIDE_/DIRECTION_/SOURCE_ enum constants verified
- `database/migrations/2026_03_09_000002_create_cable_schedules_table.php` — schema to extend
- `database/migrations/2026_05_10_120000_create_device_stencils_and_device_ports.php` — Phase 21 migration precedent (referenced via SUMMARY)
- `resources/views/cable-schedule/edit.blade.php` — current edit UI; confirmed vanilla JS, 8-column table, no Alpine yet
- `resources/views/components/document-edit-drawer.blade.php` — existing edit-drawer Alpine pattern to mirror
- `resources/views/components/modal.blade.php` — existing modal Alpine pattern
- `resources/views/site-survey/_room-form.blade.php` — existing x-data + x-for cascading rows precedent (lines 637-695)
- `app/Console/Commands/RamsRefreshComplianceCommand.php` — `--dry-run` artisan command precedent (lines 39-41)
- `app/Console/Commands/AuditDrawingLicensesCommand.php` — `--strict` flag artisan precedent
- `config/rams.php` + `config/drawings.php` — config file structure precedent
- `app/Services/DocumentArtifactStorage.php` — TYPE_CABLE constant confirmed (line 39)
- `.planning/phases/21-device-port-catalog-stencil-cache/21-CONTEXT.md` — D-09 generic naming, D-10 don't-break invariant
- `.planning/phases/21-device-port-catalog-stencil-cache/21-01-schema-models-cache-service-SUMMARY.md` — DevicePort model API
- `.planning/phases/21-device-port-catalog-stencil-cache/21-02-seed-pack-promote-and-curate-SUMMARY.md` — 96 stencils + 40 ports in seed; 91 still need Phase 24 curation (relevant to compatibility-on-empty handling per Pitfall 4)
- `.planning/REQUIREMENTS.md` — DRAW-37..41 acceptance criteria
- `.planning/ROADMAP.md` — Phase 22 success criteria (5 criteria)

### Secondary (MEDIUM confidence)
- Laravel 12 official docs (foreign key constraints, anonymous migrations) — pattern matches Phase 21 migration verbatim
- Alpine.js v3 docs (x-data, x-for, x-show, $dispatch) — patterns already proven in `site-survey/_room-form.blade.php` and `document-edit-drawer.blade.php`

### Tertiary (LOW confidence)
- None — Phase 22 doesn't depend on external/unverified sources.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — Phase 21 paved the road; all libraries already installed
- Architecture: HIGH — Modal + Alpine + form-encoded POST patterns proven elsewhere in this codebase
- Pitfalls: HIGH — D-10 regression risk is the main one and is mitigable by explicit regression tests
- Backfill algorithm: MEDIUM — exact `cable_list` shape is AI-extracted and varies; recommend backfill operates on existing `cable_schedule_items` text fields rather than `cable_list` directly

**Research date:** 2026-05-12
**Valid until:** 2026-06-12 (Phase 21 stable; Phase 22 spec locked in CONTEXT.md)

---

*Phase: 22-cable-schedule-with-port-level-fks*
*Researched: 2026-05-12*
