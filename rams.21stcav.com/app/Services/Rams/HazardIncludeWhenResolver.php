<?php

namespace App\Services\Rams;

use App\Models\HazardTemplate;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Phase 26 (HAZ-02) — tiered include-when evaluator.
 *
 * Given the full visible hazard library (`hazard_templates`, `include_when`
 * seeded by Plan 26-01) and a job's captured signals, returns the subset
 * that should auto-populate a fresh RAMS register, each tagged with how
 * confidently it matched. Pure logic, no DB writes, no dependency on any
 * specific seeded content — the tier rule is what's under test, not the
 * 18-row library itself.
 *
 * Tier semantics (D-05, D-06, and the 2026-08-23 tier-3 correction — see
 * `.planning/phases/26-hazard-library-structural-inversion/26-CONTEXT.md`):
 *
 *   - `include_when = null`         -> never returned (D-04, manual-only).
 *   - `include_when = 'always'`     -> always returned, needs_confirmation=false.
 *   - `include_when = 'signal:<k>'` -> resolves in-or-out. A match returns it
 *     with needs_confirmation=false; a miss drops it entirely (absent from
 *     the collection, NOT returned-and-flagged).
 *   - `include_when = 'confirm:<k>'` -> ALWAYS returned, ALWAYS
 *     needs_confirmation=true, on every job, no exceptions. A keyword hit
 *     only sets the transient pre_ticked flag for UI ordering — it never
 *     auto-confirms and never excludes. No AI call is made anywhere in this
 *     evaluation: CLAUDE.md's AI-usage constraint ("never for inventing
 *     scope") rules out a model deciding which hazards belong on a safety
 *     document, so tier 3 hands that judgement to a human on every job.
 *
 * Matching is restricted to the fixed, reviewed keyword arrays below — no
 * open-ended regex over arbitrary narrative text (mirrors the
 * PPE_ACTIVITY_MAP / ACCESS_EQUIPMENT_MAP discipline in
 * RiskTemplateResolverService).
 *
 * Not wired into the RAMS pipeline by this plan — Plan 26-04 owns that.
 */
class HazardIncludeWhenResolver
{
    /**
     * Tier-2 signal keys satisfied by an EquipmentClassifierService activity
     * key being present in the job's captured `activities` signal.
     */
    private const TIER2_ACTIVITY_SIGNALS = [
        'mounting_above_reach' => ['ceiling_works'],
        'display_mount_or_rack' => ['display_installation', 'av_rack', 'ceiling_works'],
        'ceiling_void_access' => ['ceiling_works'],
        'first_fix_cabling' => ['structured_cabling', 'cable_management'],
    ];

    /**
     * Tier-2 signal keys satisfied by a fixed-vocabulary keyword match
     * against the (lowercased) scope narrative.
     */
    private const TIER2_KEYWORD_SIGNALS = [
        'mounting_above_reach' => [
            'above standing reach',
            'overhead mount',
            'high level mount',
            'pillar mount',
        ],
        'ceiling_void_access' => [
            'ceiling void',
            'void access',
            'riser',
            'above the ceiling grid',
            'ceiling tiles',
            'comms room',
        ],
        'mains_connection' => [
            'mains connection',
            'mains disconnection',
            'isolate the',
            'new circuit',
            'electrical connection',
            'mains isolation',
            'disconnect the existing supply',
        ],
        'strip_out_or_decommission' => [
            'strip-out',
            'strip out',
            'decommission',
            'removal of existing',
            'de-install',
        ],
    ];

    /**
     * Tier-2 signal keys satisfied purely by drilling_required === true.
     */
    private const TIER2_DRILLING_SIGNALS = [
        'drilling_or_percussive',
        'any_penetration',
        'any_drilling',
    ];

    /**
     * Tier-3 pre-tick ordering hint ONLY. A hit here never decides
     * inclusion or confirmation state — both are unconditional for tier 3
     * (see class docblock). This exists solely so Plan 26-05's review-screen
     * UI can pre-tick/order the confirmation candidates sensibly.
     */
    private const TIER3_KEYWORD_PRECHECK = [
        'occupied_premises' => [
            'occupied',
            'live building',
            'staff present',
            'out of hours',
            'during business hours',
        ],
        'asbestos' => [
            'asbestos',
            'pre-2000',
            'pre 2000',
            'age unknown',
            'built before 2000',
        ],
        'vehicle_plant' => [
            'warehouse',
            'workshop',
            'yard',
            'loading bay',
        ],
        'lone_working' => [
            'lone work',
            'single operative',
            'one engineer',
            'split areas',
            'split location',
        ],
        'road_risk' => [
            'travel day',
            'overnight stay',
            'significant distance',
            'outside normal travel radius',
        ],
    ];

    /**
     * @param  Collection<int, HazardTemplate>  $library  Visible hazard rows to evaluate.
     * @param  array{activities?: string[], drilling_required?: bool, scope_narrative?: string}  $signals
     * @return Collection<int, HazardTemplate> Matched rows, each carrying transient
     *   ->needs_confirmation (bool), ->match_tier ('always'|'deterministic'|'confirm'),
     *   ->pre_ticked (bool) attributes. Never persisted (->save() is never called).
     */
    public function resolve(Collection $library, array $signals): Collection
    {
        $activities = $signals['activities'] ?? [];
        $drillingRequired = (bool) ($signals['drilling_required'] ?? false);
        $narrative = Str::lower((string) ($signals['scope_narrative'] ?? ''));

        return $library
            ->map(fn ($template) => $this->evaluate($template, $activities, $drillingRequired, $narrative))
            ->filter()
            ->values();
    }

    /**
     * Evaluate a single hazard row. Returns the decorated row when it should
     * be included, or null when it should be dropped from the register.
     */
    private function evaluate(HazardTemplate $template, array $activities, bool $drillingRequired, string $narrative): ?HazardTemplate
    {
        $includeWhen = $template->include_when;

        if ($includeWhen === null) {
            // D-04: user-created hazards with a null condition are manual-pick-only.
            return null;
        }

        if ($includeWhen === 'always') {
            return $this->tag($template, needsConfirmation: false, matchTier: 'always', preTicked: false);
        }

        if (Str::startsWith($includeWhen, 'signal:')) {
            $key = Str::after($includeWhen, 'signal:');

            if (! $this->tier2Matches($key, $activities, $drillingRequired, $narrative)) {
                // Tier 2 resolves in-or-out: a miss drops the hazard entirely,
                // it is never returned-and-flagged (that is tier 3's job).
                return null;
            }

            return $this->tag($template, needsConfirmation: false, matchTier: 'deterministic', preTicked: false);
        }

        if (Str::startsWith($includeWhen, 'confirm:')) {
            $key = Str::after($includeWhen, 'confirm:');
            $preTicked = $this->tier3PreTickMatches($key, $narrative);

            // Tier 3 always resolves to "ask a human" — a keyword hit only
            // pre-ticks the candidate, it never auto-confirms or excludes.
            return $this->tag($template, needsConfirmation: true, matchTier: 'confirm', preTicked: $preTicked);
        }

        // Unrecognised include_when value — fail closed (manual-only), never
        // silently auto-populate an unknown condition string.
        return null;
    }

    private function tier2Matches(string $key, array $activities, bool $drillingRequired, string $narrative): bool
    {
        if ($drillingRequired && in_array($key, self::TIER2_DRILLING_SIGNALS, true)) {
            return true;
        }

        $activitySignals = self::TIER2_ACTIVITY_SIGNALS[$key] ?? [];
        if (! empty(array_intersect($activitySignals, $activities))) {
            return true;
        }

        foreach (self::TIER2_KEYWORD_SIGNALS[$key] ?? [] as $keyword) {
            if (Str::contains($narrative, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function tier3PreTickMatches(string $key, string $narrative): bool
    {
        foreach (self::TIER3_KEYWORD_PRECHECK[$key] ?? [] as $keyword) {
            if (Str::contains($narrative, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function tag(HazardTemplate $template, bool $needsConfirmation, string $matchTier, bool $preTicked): HazardTemplate
    {
        $template->needs_confirmation = $needsConfirmation;
        $template->match_tier = $matchTier;
        $template->pre_ticked = $preTicked;

        return $template;
    }
}
