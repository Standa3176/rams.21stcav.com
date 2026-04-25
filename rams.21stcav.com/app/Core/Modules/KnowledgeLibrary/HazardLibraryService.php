<?php

namespace App\Core\Modules\KnowledgeLibrary;

use App\Models\HazardTemplate;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * HazardLibraryService — the bridge between the Hazard Library and RAMS generation.
 *
 * Responsibilities:
 *   1. Resolve a RAMS hazard list from a mix of library template IDs and AI-seed strings.
 *   2. Guarantee mandatory baseline hazards always appear in every RAMS.
 *   3. Provide formatted data for RamsPrompt::build() context.
 *   4. Offer the full library for JSON API / modal use.
 *
 * Usage inside RamsController / RamsGeneratorService:
 *   $service = app(HazardLibraryService::class);
 *
 *   // From explicit IDs (RAMS create form):
 *   $hazards = $service->resolveByIds(auth()->id(), $request->input('hazard_ids', []));
 *
 *   // From AI-extracted seeds (QuoteImport → auto-RAMS flow):
 *   $hazards = $service->resolveFromSeeds(auth()->id(), $extracted['hazards']);
 *
 *   // Pass to RamsPrompt:
 *   $context['hazards'] = $service->toPromptData($hazards);
 */
class HazardLibraryService
{
    /**
     * Hazard names that must appear in EVERY RAMS regardless of scope.
     * These map to standard library entries by keyword.
     */
    private const MANDATORY_KEYWORDS = [
        'working at height',
        'manual handling',
        'electrical',
        'slips, trips and falls',
        'noise and vibration',
        'working in occupied premises',
        'confined spaces',
    ];

    // ── Resolution methods ────────────────────────────────────────────────────

    /**
     * Resolve templates by explicit IDs (used when engineer selects from the RAMS form).
     *
     * @param  int[]  $ids
     * @return Collection<HazardTemplate>
     */
    public function resolveByIds(int $userId, array $ids, bool $includeMandatory = true): Collection
    {
        if (empty($ids)) {
            return $includeMandatory ? $this->mandatoryBaseline($userId) : collect();
        }

        $selected = HazardTemplate::visibleTo($userId)
            ->whereIn('id', $ids)
            ->get();

        return $includeMandatory ? $this->mergeWithMandatory($userId, $selected) : $selected;
    }

    /**
     * Resolve templates from AI-extracted hazard seed strings.
     *
     * Each seed string is fuzzy-matched against the library.
     * Any seed that has no match is returned as a plain-string fallback item.
     * Mandatory baseline hazards are always included.
     *
     * @param  string[]  $seeds  Strings from QuoteExtractionPrompt hazards array.
     * @return array<int, HazardTemplate|string>  Mix of HazardTemplate models and fallback strings.
     */
    public function resolveFromSeeds(int $userId, array $seeds, bool $includeMandatory = true): Collection
    {
        $library = HazardTemplate::visibleTo($userId)->get();
        $matched = collect();
        $unmatched = [];

        foreach ($seeds as $seed) {
            $template = $this->fuzzyMatch($seed, $library);
            if ($template !== null) {
                if (! $matched->contains('id', $template->id)) {
                    $matched->push($template);
                }
            } else {
                $unmatched[] = $seed;
            }
        }

        // Merge mandatory, then append unmatched seeds as plain strings
        $resolved = $includeMandatory ? $this->mergeWithMandatory($userId, $matched) : $matched;

        // Wrap unmatched strings as pseudo-objects for uniform handling
        foreach ($unmatched as $text) {
            $resolved->push((object) [
                'id'              => null,
                'name'            => $text,
                'description'     => null,
                'controls'        => [],
                'pre_likelihood'  => 3,
                'pre_severity'    => 3,
                'post_likelihood' => 1,
                'post_severity'   => 2,
                'is_global'       => false,
            ]);
        }

        return $resolved;
    }

    // ── RAMS prompt formatting ────────────────────────────────────────────────

    /**
     * Convert a resolved collection into the array format expected by RamsPrompt::build().
     *
     * Each item becomes: ['name' => '...', 'description' => '...', 'controls' => [...]]
     *
     * @param  Collection  $hazards  Result of resolveByIds() or resolveFromSeeds().
     * @return array
     */
    public function toPromptData(Collection $hazards): array
    {
        return $hazards->map(function ($h) {
            // Works with both HazardTemplate models and stdClass pseudo-objects
            return [
                'name'            => $h->name,
                'description'     => $h->description ?? null,
                'controls'        => $h->controls ?? [],
                'pre_likelihood'  => $h->pre_likelihood  ?? 3,
                'pre_severity'    => $h->pre_severity    ?? 3,
                'post_likelihood' => $h->post_likelihood ?? 1,
                'post_severity'   => $h->post_severity   ?? 2,
            ];
        })->values()->all();
    }

    // ── Library access ────────────────────────────────────────────────────────

    /**
     * Return all templates visible to a user, split into global and personal.
     *
     * @return array{global: Collection, personal: Collection}
     */
    public function forUser(int $userId): array
    {
        $all = HazardTemplate::visibleTo($userId)
            ->orderBy('is_global', 'desc')
            ->orderBy('name')
            ->get();

        return [
            'global'   => $all->where('is_global', true)->values(),
            'personal' => $all->where('is_global', false)->values(),
        ];
    }

    /**
     * Return all visible templates as a JSON-serialisable array (for API endpoints).
     *
     * @return array
     */
    public function forUserJson(int $userId): array
    {
        $all = HazardTemplate::visibleTo($userId)
            ->orderBy('is_global', 'desc')
            ->orderBy('name')
            ->get();

        return $all->map(fn($t) => [
            'id'              => $t->id,
            'name'            => $t->name,
            'description'     => $t->description,
            'is_global'       => $t->is_global,
            'pre_likelihood'  => $t->pre_likelihood,
            'pre_severity'    => $t->pre_severity,
            'pre_risk'        => $t->pre_likelihood * $t->pre_severity,
            'post_likelihood' => $t->post_likelihood,
            'post_severity'   => $t->post_severity,
            'post_risk'       => $t->post_likelihood * $t->post_severity,
            'controls'        => $t->controls ?? [],
            'controls_count'  => count($t->controls ?? []),
        ])->values()->all();
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Return the mandatory baseline hazards from the library.
     * Falls back to plain-string pseudo-objects if no matching template is found.
     */
    private function mandatoryBaseline(int $userId): Collection
    {
        $library  = HazardTemplate::visibleTo($userId)->get();
        $baseline = collect();
        $fallbackControls = [
            'noise and vibration' => [
                'Use hearing protection when exposed to sustained drilling or power tool noise.',
                'Limit noisy works to agreed times and inform occupants in advance.',
                'Use low-vibration tools where practicable and take regular breaks.',
            ],
            'working in occupied premises' => [
                'Maintain clean, segregated work areas with clear signage and barriers.',
                'Coordinate work windows to minimise disruption to occupants.',
                'Protect client property and ensure confidentiality of visible data.',
            ],
            'confined spaces' => [
                'Confirm ventilation and safe access before entering comms rooms or enclosures.',
                'Do not obstruct escape routes; maintain clear access at all times.',
                'Ensure a second person is aware of entry and available for assistance.',
            ],
        ];

        foreach (self::MANDATORY_KEYWORDS as $keyword) {
            $match = $this->fuzzyMatch($keyword, $library);

            if ($match !== null) {
                $baseline->push($match);
            } else {
                $controls = $fallbackControls[$keyword] ?? [];
                // Title-case but keep connector words ("and", "in", "on", "of",
                // "the") in lower case — Laravel's Str::title() capitalises
                // every word, producing "Noise And Vibration" / "Working In
                // Occupied Premises" which doesn't match the reference style.
                $titled = Str::title($keyword);
                $titled = preg_replace_callback(
                    '/\s(And|In|On|Of|The|For|To|At)\b/',
                    fn ($m) => ' ' . strtolower($m[1]),
                    $titled
                );
                $baseline->push((object) [
                    'id'              => null,
                    'name'            => $titled,
                    'description'     => null,
                    'controls'        => $controls,
                    'pre_likelihood'  => 3,
                    'pre_severity'    => 3,
                    'post_likelihood' => 1,
                    'post_severity'   => 2,
                    'is_global'       => false,
                ]);
            }
        }

        return $baseline;
    }

    /**
     * Merge a resolved collection with mandatory hazards, deduplicating by ID.
     */
    private function mergeWithMandatory(int $userId, Collection $resolved): Collection
    {
        $mandatory = $this->mandatoryBaseline($userId);

        foreach ($mandatory as $m) {
            if ($m->id === null) {
                // Pseudo-object — add only if no template with same name already present
                $nameExists = $resolved->contains(
                    fn($r) => Str::lower($r->name) === Str::lower($m->name)
                );
                if (! $nameExists) {
                    $resolved->push($m);
                }
            } elseif (! $resolved->contains('id', $m->id)) {
                $resolved->push($m);
            }
        }

        return $resolved;
    }

    /**
     * Fuzzy-match a seed string against a library collection.
     *
     * Match strategy (in order):
     *   1. Exact case-insensitive name match.
     *   2. Library name contains the seed (or seed contains library name).
     *   3. Shared word count ≥ 2 between seed and library name.
     *
     * Returns the first match or null.
     */
    private function fuzzyMatch(string $seed, Collection $library): ?HazardTemplate
    {
        $seedLower = Str::lower(trim($seed));

        // 1. Exact
        $exact = $library->first(fn($t) => Str::lower($t->name) === $seedLower);
        if ($exact !== null) return $exact;

        // 2. Substring
        $sub = $library->first(function ($t) use ($seedLower) {
            $name = Str::lower($t->name);
            return str_contains($name, $seedLower) || str_contains($seedLower, $name);
        });
        if ($sub !== null) return $sub;

        // 3. Shared significant words (ignore stop words)
        $stopWords  = ['and', 'or', 'of', 'the', 'a', 'an', 'in', 'at', 'to', 'for', 'from', 'by'];
        $seedWords  = array_diff(explode(' ', $seedLower), $stopWords);

        $best      = null;
        $bestScore = 1; // Require at least 2 shared words

        foreach ($library as $template) {
            $nameWords = array_diff(explode(' ', Str::lower($template->name)), $stopWords);
            $shared    = count(array_intersect($seedWords, $nameWords));

            if ($shared > $bestScore) {
                $bestScore = $shared;
                $best      = $template;
            }
        }

        return $best;
    }
}
