<?php

namespace App\Services\Rams;

/**
 * Phase 30 Plan 01 (D-06, D-07, D-08) — the single, shared signal-matching
 * helper every Phase 30 structural gate (GATE-01, GATE-02, GATE-04, GATE-13,
 * GATE-14) uses. Judgement-only class: it owns no loop and throws nothing —
 * that stays on each gate method, per the established shipped pattern
 * (the display-lift policy's `violatesPolicy()`, `ControlTextRuleViolations::detect()`,
 * `SiteEmergencyResolver::classify()`).
 *
 * D-07 — hazard-side matching reuses Phase 26's signal vocabulary
 * (`HazardIncludeWhenResolver::TIER2_ACTIVITY_SIGNALS` /
 * `TIER2_KEYWORD_SIGNALS` / `TIER3_KEYWORD_PRECHECK`, widened to public by
 * this plan — a visibility change only). This class NEVER calls
 * {@see HazardIncludeWhenResolver::resolve()} — that method queries
 * `HazardTemplate` Eloquent models and would break
 * `RamsComplianceUpgradeService`'s "Deterministic. No AI. No database."
 * class docblock invariant. Reusing the const maps preserves it and keeps
 * D-07's "one shared vocabulary, never a second parallel one" true by
 * construction.
 *
 * Conservative by construction (pattern S3, 30-PATTERNS.md): an unknown
 * signal, an absent/malformed input, or a miss all resolve to "no match" —
 * never a throw, never a false positive. ROADMAP criterion 4 makes "no
 * false positives against legitimate output" an acceptance criterion for
 * the whole phase, so every method here prefers a miss.
 *
 * D-08 — `clientReqs` does not exist in this app (it is a skill-side JSON
 * key). Its app equivalent is the UNION of `$data['client_responsibilities']`
 * (flat string list) and `$data['client_responsibilities_expanded']` (four
 * fixed buckets — `network_readiness`, `licences`, `access`,
 * `power_validation`, each `['required' => bool, 'notes' => string]` — plus
 * `'additional' => list<['item' => string, 'notes' => string]>`, confirmed
 * at `RamsController.php:520-536`). Both buckets render together under PDF
 * Section 6.3 "Pre-Installation Requirements (Client Responsibilities)".
 *
 * @see app/Services/Rams/HazardIncludeWhenResolver.php
 * @see .planning/phases/30-structural-validation-gates/30-01-PLAN.md
 * @see .planning/phases/30-structural-validation-gates/30-CONTEXT.md
 */
final class StructuralGateVocabulary
{
    /**
     * Every signal key GATE-01's `structural_gate_triggers` and GATE-14's
     * `missing_risk_implications` config rows are permitted to reference
     * (config/rams_tier1.php). Deliberately the union of
     * HazardIncludeWhenResolver's TIER2_ACTIVITY_SIGNALS,
     * TIER2_KEYWORD_SIGNALS and TIER3_KEYWORD_PRECHECK map keys ONLY —
     * TIER2_DRILLING_SIGNALS is a flat boolean-trigger list, not a
     * phrase-bearing map, so it carries no text this class can match
     * against. Kept as an explicit literal (rather than computed at class
     * load) because PHP class-constant initialisers cannot call functions;
     * {@see \Tests\Unit\Services\Rams\StructuralGateVocabularyTest}'s
     * `test_supported_signals_matches_hazard_include_when_resolver_map_keys`
     * is the drift guard that keeps this list honest.
     */
    public const SUPPORTED_SIGNALS = [
        'mounting_above_reach',
        'display_mount_or_rack',
        'ceiling_void_access',
        'first_fix_cabling',
        'mains_connection',
        'strip_out_or_decommission',
        'occupied_premises',
        'asbestos',
        'vehicle_plant',
        'lone_working',
        'road_risk',
    ];

    /**
     * Human labels for the four fixed `client_responsibilities_expanded`
     * buckets (D-08). Deliberately a SEPARATE literal from the PDF blade's
     * `$crExpLabels` (`resources/views/pdf/rams.blade.php:1588-1593`) —
     * this class must not read or depend on a Blade file, and the two
     * copies are free to drift in wording without breaking either surface;
     * only the four bucket KEYS (which are structural, not prose) must
     * stay in sync with `RamsController.php:522-531`.
     */
    private const CLIENT_RESPONSIBILITY_BUCKET_LABELS = [
        'network_readiness' => 'Network / LAN readiness (active drops at device locations)',
        'licences' => 'Software licences / subscriptions (Teams Rooms, Zoom, etc.)',
        'access' => 'Site access and room availability on installation day(s)',
        'power_validation' => 'Mains power validation (sockets live and tested)',
    ];

    /**
     * The literal phrase vocabulary for a signal key, sourced from
     * HazardIncludeWhenResolver's const maps (D-07) — never hand-copied.
     * Unions all three maps' entries for the key (an activity-signal
     * entry contributes its activity slugs alongside any keyword-signal
     * and precheck phrases for the same key). Unknown signal returns an
     * empty array, never throws.
     */
    public static function phrasesForSignal(string $signal): array
    {
        if (! in_array($signal, self::SUPPORTED_SIGNALS, true)) {
            return [];
        }

        return array_values(array_unique(array_merge(
            HazardIncludeWhenResolver::TIER2_ACTIVITY_SIGNALS[$signal] ?? [],
            HazardIncludeWhenResolver::TIER2_KEYWORD_SIGNALS[$signal] ?? [],
            HazardIncludeWhenResolver::TIER3_KEYWORD_PRECHECK[$signal] ?? [],
        )));
    }

    /**
     * Case-insensitive substring containment of ANY of a signal's phrases
     * inside free text. Conservative: an unknown signal or empty haystack
     * never matches.
     */
    public static function signalPresentInText(string $signal, string $haystack): bool
    {
        if (trim($haystack) === '') {
            return false;
        }

        $needleHaystack = mb_strtolower($haystack);

        foreach (self::phrasesForSignal($signal) as $phrase) {
            if ($phrase !== '' && str_contains($needleHaystack, mb_strtolower($phrase))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does a signal resolve into the document's OWN hazard register?
     * `$hazards` is `$data['hazards']` shape — a list of rows, each
     * normally `['hazard' => string, ...]` (free-text names written by
     * AI/engineer, never HazardTemplate rows). Matches the signal's
     * phrases against each row's `hazard` text (or the row itself, if it
     * is a bare string). Empty register, unknown signal, or a malformed
     * row all resolve to false — never a throw.
     */
    public static function signalMatchesHazards(string $signal, array $hazards): bool
    {
        $phrases = self::phrasesForSignal($signal);

        if (empty($phrases)) {
            return false;
        }

        foreach ($hazards as $row) {
            $text = self::hazardRowText($row);

            if ($text === '') {
                continue;
            }

            foreach ($phrases as $phrase) {
                if ($phrase !== '' && str_contains($text, mb_strtolower($phrase))) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function hazardRowText(mixed $row): string
    {
        if (is_string($row)) {
            return mb_strtolower($row);
        }

        if (is_array($row)) {
            return mb_strtolower((string) ($row['hazard'] ?? ''));
        }

        return '';
    }

    /**
     * Does a signal resolve into a flat list of client-responsibility
     * strings (D-08's pooled union — see {@see self::flattenClientResponsibilities()})?
     * Case-insensitive. Non-string entries are skipped, never thrown on.
     */
    public static function signalMatchesClientReqs(string $signal, array $clientReqStrings): bool
    {
        $phrases = self::phrasesForSignal($signal);

        if (empty($phrases)) {
            return false;
        }

        foreach ($clientReqStrings as $entry) {
            if (! is_string($entry) || trim($entry) === '') {
                continue;
            }

            $lower = mb_strtolower($entry);

            foreach ($phrases as $phrase) {
                if ($phrase !== '' && str_contains($lower, mb_strtolower($phrase))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * D-08's union: `$data['client_responsibilities']` (flat string list)
     * pooled with the searchable sub-fields of
     * `$data['client_responsibilities_expanded']` — each fixed bucket's
     * `notes`, every `additional[*].item`/`additional[*].notes`, plus a
     * synthetic label (from {@see self::CLIENT_RESPONSIBILITY_BUCKET_LABELS})
     * for any fixed bucket whose `required` is true, so a bucket that is
     * required but carries no free-text notes is never silently dropped
     * from the matchable pool. Missing or malformed input returns `[]`,
     * never throws.
     */
    public static function flattenClientResponsibilities(array $data): array
    {
        $flat = [];

        $plain = $data['client_responsibilities'] ?? [];
        if (is_array($plain)) {
            foreach ($plain as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $flat[] = $item;
                }
            }
        }

        $expanded = $data['client_responsibilities_expanded'] ?? [];
        if (is_array($expanded)) {
            foreach (self::CLIENT_RESPONSIBILITY_BUCKET_LABELS as $bucketKey => $label) {
                $bucket = $expanded[$bucketKey] ?? null;

                if (! is_array($bucket)) {
                    continue;
                }

                $notes = trim((string) ($bucket['notes'] ?? ''));
                if ($notes !== '') {
                    $flat[] = $notes;
                }

                if (! empty($bucket['required'])) {
                    $flat[] = $label;
                }
            }

            $additional = $expanded['additional'] ?? [];
            if (is_array($additional)) {
                foreach ($additional as $row) {
                    if (! is_array($row)) {
                        continue;
                    }

                    $item = trim((string) ($row['item'] ?? ''));
                    if ($item !== '') {
                        $flat[] = $item;
                    }

                    $notes = trim((string) ($row['notes'] ?? ''));
                    if ($notes !== '') {
                        $flat[] = $notes;
                    }
                }
            }
        }

        return $flat;
    }

    /**
     * Area/room names for GATE-02. Reads the gate-private mirror key
     * `areas_for_gate` FIRST (written by Plan 30-02, immediately before
     * `upgrade()`, mirroring the established S4 pattern), falling back to
     * `$data['rooms']` when the mirror is absent. Deliberately never reads
     * `$data['room_overviews']` — per RESEARCH Finding 3 / Assumption A2,
     * that key is the trigger for the long-dormant `ensurePerRoomBullets()`
     * AI path, and waking it is a behaviour change this phase does not
     * want. Returns trimmed, non-empty, de-duplicated names; `[]` when no
     * source is present.
     */
    public static function flattenAreas(array $data): array
    {
        $source = $data['areas_for_gate'] ?? $data['rooms'] ?? null;

        if (! is_array($source)) {
            return [];
        }

        $names = [];

        foreach ($source as $entry) {
            $name = is_array($entry) ? (string) ($entry['name'] ?? '') : (string) $entry;
            $name = trim($name);

            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }
}
