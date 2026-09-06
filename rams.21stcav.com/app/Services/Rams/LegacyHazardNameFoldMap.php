<?php

namespace App\Services\Rams;

/**
 * Phase 26 Plan 08 (HAZ-02 gap closure, round 2) — the single, shared,
 * machine-readable form of D-02's "fold legacy hazard names into their
 * nearest 21cav-rams skill hazard" mapping.
 *
 * Before this file, D-02's fold mapping existed only as prose in
 * `26-01-PLAN.md` (6 names) plus an implicit consequence of which 18 names
 * `HazardTemplateSeeder` emits (the other 10 names, recoverable only by
 * diffing `HazardTemplateSeeder` and `HazardLibraryService` against their
 * pre-Phase-26 git history). Round 2 live verification
 * (`26-VERIFICATION.md`) found that gap directly: reviewed hazard rows
 * saved under the OLD vocabulary collided with the new library instead of
 * folding onto it, and `Confined Spaces` reached a client-facing document.
 *
 * This class is the SINGLE choke point every caller resolves a legacy name
 * through — consumed by `HazardLibraryService::fuzzyMatch()` only. Do not
 * duplicate this map anywhere else; divergent copies are exactly what
 * caused the gaps this plan closes.
 *
 * ── Provenance of the 16 entries ────────────────────────────────────────
 *
 * Group 1 — D-02's original 6-entry fold mapping, verbatim from
 * `26-01-PLAN.md` (`<decisions>` D-02, "Suggested mapping"):
 *   - "Struck by Falling Objects" -> "Working at height"
 *   - "Hidden Services (Electrical, Plumbing, Gas)" -> "Fixings into walls,
 *     ceilings and pillars"
 *   - "Sharps & Hand / Power Tools" -> "Cable pulling and termination"
 *   - "Display Installation / Wall Mounting" -> "Manual handling"
 *   - "Fixings / Substrate Failure" -> "Fixings into walls, ceilings and
 *     pillars"
 *   - "Interaction with Other Trades" -> "Occupied premises"
 *
 * Group 2 — the retired old-13-hazard-template names not covered by Group
 * 1, traced via `git show 10f26f0^:./database/seeders/HazardTemplateSeeder.php`
 * (the pre-Phase-26-01 seeder):
 *   - "Manual Handling" -> "Manual handling"
 *   - "Slips, Trips & Falls (Same Level)" -> "Slips, trips and falls"
 *   - "Working at Height" -> "Working at height"
 *   - "Electrical Hazards" -> "Electrical"
 *   - "Dust & Debris (Including Drilling)" -> "Dust from drilling and
 *     cutting"
 *   - "Lone Working" -> "Lone and small-team working"
 *   - "Cable Installation in Ceiling Voids" -> "Restricted access and
 *     ceiling voids"
 *
 * Group 3 — the retired always-on hazard-keyword fallback names (removed
 * from HazardLibraryService by Plan 26-04, see
 * HazardInjectionPathsRemovedGuardTest) that had no old-13-template match,
 * traced via
 * `git show 28039e9^:./app/Core/Modules/KnowledgeLibrary/HazardLibraryService.php`
 * (the pre-Phase-26-04 `HazardLibraryService`, its title-cased fallback
 * names):
 *   - "Noise and Vibration" -> "Noise and vibration"
 *   - "Working in Occupied Premises" -> "Occupied premises"
 *   - "Confined Spaces" -> "Restricted access and ceiling voids" — the
 *     exact mislabel `house-rules.md` RULE-06/GATE-07 target (Phase 28),
 *     and the specific gap this plan's round-2 live evidence found
 *     reaching a client-facing document.
 *
 * A future reader extending this map should not need to repeat this git
 * archaeology — this docblock is the record of where every entry came
 * from.
 *
 * ── Phase 28 Plan 02, 2026-09-06 — D-05 rename ──────────────────────────
 *
 * `HazardTemplateSeeder`'s hazard #7 was renamed from "Restricted access
 * and ceiling voids" to "Restricted access and ceiling void working" to
 * match `house-rules.md` §"Ceiling work" and the verbatim wording already
 * in `REQUIREMENTS.md` RULE-06 and `ROADMAP.md`'s Phase 28 success
 * criterion 2 (both predate this rename and needed no edit). Group 2's
 * "Cable Installation in Ceiling Voids" and Group 3's "Confined Spaces"
 * entries above now both resolve to the renamed string.
 *
 * This rename also creates a reachability gap Groups 1-3 do not cover:
 * any document reviewed between Phase 26 (when "Restricted access and
 * ceiling voids" first shipped) and this Phase 28 rename carries that
 * exact string, which is NOT a pre-Phase-26 legacy name — it is the
 * app's own prior canonical output. Without an explicit entry for it,
 * such a document would silently keep the old title forever on
 * regeneration. The new entry immediately below the Group 3 block closes
 * that gap; it is a rename-supersession entry, not a legacy-name fold.
 */
final class LegacyHazardNameFoldMap
{
    /**
     * legacy name (lowercase, no leading/trailing whitespace) => canonical
     * hazard_templates.name (exact string, matching HazardTemplateSeeder).
     *
     * @var array<string, string>
     */
    private const MAP = [
        // ── Group 1 — D-02's original 6-entry mapping (26-01-PLAN.md) ──────
        'struck by falling objects' => 'Working at height',
        'hidden services (electrical, plumbing, gas)' => 'Fixings into walls, ceilings and pillars',
        'sharps & hand / power tools' => 'Cable pulling and termination',
        'display installation / wall mounting' => 'Manual handling',
        'fixings / substrate failure' => 'Fixings into walls, ceilings and pillars',
        'interaction with other trades' => 'Occupied premises',

        // ── Group 2 — retired old-13-hazard-template names ─────────────────
        'manual handling' => 'Manual handling',
        'slips, trips & falls (same level)' => 'Slips, trips and falls',
        'working at height' => 'Working at height',
        'electrical hazards' => 'Electrical',
        'dust & debris (including drilling)' => 'Dust from drilling and cutting',
        'lone working' => 'Lone and small-team working',
        'cable installation in ceiling voids' => 'Restricted access and ceiling void working',

        // ── Group 3 — retired always-on hazard-keyword fallback names ──────
        'noise and vibration' => 'Noise and vibration',
        'working in occupied premises' => 'Occupied premises',
        'confined spaces' => 'Restricted access and ceiling void working',

        // ── Phase 28 Plan 02 (D-05) — rename-supersession entry, NOT a ─────
        // pre-Phase-26 legacy name. This is the exact title Phase 26 shipped
        // ("Restricted access and ceiling voids"); a document reviewed
        // between Phase 26 and this rename carries that string, not any of
        // the Group 1-3 legacy names, and must still fold forward.
        'restricted access and ceiling voids' => 'Restricted access and ceiling void working',
    ];

    /**
     * Resolve a legacy hazard name to its canonical library name.
     *
     * D-04: an unmapped name is never guessed at — a miss returns null,
     * leaving the caller's existing fuzzy-match tiers (or, failing those,
     * the raw name) untouched.
     */
    public static function canonicalName(string $legacyName): ?string
    {
        $key = strtolower(trim($legacyName));

        if ($key === '') {
            return null;
        }

        return self::MAP[$key] ?? null;
    }

    /**
     * The full map, canonical-name values only. For tests — proves the
     * map's OUTPUT side can never re-introduce a banned string (e.g.
     * "Confined Spaces"), and drift-guards the map against the seeder.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return self::MAP;
    }
}
