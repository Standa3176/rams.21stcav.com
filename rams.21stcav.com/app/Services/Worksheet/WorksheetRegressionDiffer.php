<?php

namespace App\Services\Worksheet;

/**
 * Deterministic regression diff utility for worksheet generation.
 *
 * Compares two `generated_data` arrays (old vs new) and returns a structured
 * diff showing per-item category changes, added/removed items per room,
 * and blocker churn. Used as a manual sanity tool after classifier or
 * taxonomy changes so reviewers can see "which items changed category?"
 * across historical worksheets before a cutover.
 *
 * Stateless; pure function of its inputs. No Laravel container, no DB.
 */
class WorksheetRegressionDiffer
{
    /**
     * Compare old vs new generated_data and return a structured diff.
     *
     * Output shape:
     *   {
     *     rooms: [
     *       { room: 'Boardroom',
     *         category_changes: [
     *           { item: 'Chief CH-MTM1U', old: 'Other Hardware', new: 'Display' }
     *         ],
     *         added:   [ {item:'…', new:'audio'} ],
     *         removed: [ {item:'…', old:'display'} ],
     *       }
     *     ],
     *     summary: {
     *       total_items:        12,
     *       category_changed:   3,
     *       added:              1,
     *       removed:            0,
     *       rooms_renamed:      ['Comm's Room' → 'Comm\'s Room'],
     *     },
     *     blocker_diff: {
     *       added:   [ ... ],
     *       removed: [ ... ],
     *     },
     *   }
     */
    public function diff(array $oldGenerated, array $newGenerated): array
    {
        $oldRooms = $this->indexRooms($oldGenerated['rooms'] ?? []);
        $newRooms = $this->indexRooms($newGenerated['rooms'] ?? []);

        $roomDiffs = [];
        $roomsRenamed = [];

        $allRoomKeys = array_unique(array_merge(array_keys($oldRooms), array_keys($newRooms)));
        sort($allRoomKeys);

        $totalItems = 0;
        $catChanged = 0;
        $added      = 0;
        $removed    = 0;

        foreach ($allRoomKeys as $roomKey) {
            $oldRoom = $oldRooms[$roomKey] ?? null;
            $newRoom = $newRooms[$roomKey] ?? null;

            $oldItems = $oldRoom ? $this->indexItemsByName($oldRoom) : [];
            $newItems = $newRoom ? $this->indexItemsByName($newRoom) : [];

            $categoryChanges = [];
            $roomAdded       = [];
            $roomRemoved     = [];

            $itemNames = array_unique(array_merge(array_keys($oldItems), array_keys($newItems)));
            sort($itemNames);
            foreach ($itemNames as $itemName) {
                $oldCat = $oldItems[$itemName] ?? null;
                $newCat = $newItems[$itemName] ?? null;

                if ($oldCat !== null && $newCat !== null && $oldCat !== $newCat) {
                    $categoryChanges[] = ['item' => $itemName, 'old' => $oldCat, 'new' => $newCat];
                    $catChanged++;
                } elseif ($oldCat === null && $newCat !== null) {
                    $roomAdded[] = ['item' => $itemName, 'new' => $newCat];
                    $added++;
                } elseif ($oldCat !== null && $newCat === null) {
                    $roomRemoved[] = ['item' => $itemName, 'old' => $oldCat];
                    $removed++;
                }
                if ($newCat !== null) $totalItems++;
            }

            if (! empty($categoryChanges) || ! empty($roomAdded) || ! empty($roomRemoved)) {
                $roomDiffs[] = [
                    'room'             => $newRoom['name'] ?? $oldRoom['name'] ?? $roomKey,
                    'category_changes' => $categoryChanges,
                    'added'            => $roomAdded,
                    'removed'          => $roomRemoved,
                ];
            }
        }

        // Room-name normalisation surfaces (e.g. Pass B normaliser closing unmatched parens).
        foreach ($newRoomsRaw = ($newGenerated['rooms'] ?? []) as $new) {
            $nn = $new['name'] ?? '';
            $origKey = $this->roomKey($nn);
            $oldMatch = null;
            foreach ($oldGenerated['rooms'] ?? [] as $old) {
                if ($this->roomKey($old['name'] ?? '') === $origKey && ($old['name'] ?? '') !== $nn) {
                    $oldMatch = $old['name'];
                    break;
                }
            }
            if ($oldMatch !== null) {
                $roomsRenamed[] = $oldMatch . ' → ' . $nn;
            }
        }

        return [
            'rooms'   => $roomDiffs,
            'summary' => [
                'total_items'      => $totalItems,
                'category_changed' => $catChanged,
                'added'            => $added,
                'removed'          => $removed,
                'rooms_renamed'    => $roomsRenamed,
            ],
            'blocker_diff' => $this->diffBlockers(
                $oldGenerated['blockers'] ?? [],
                $newGenerated['blockers'] ?? [],
            ),
        ];
    }

    /** @return array<string, array> keyed by normalised room name */
    private function indexRooms(array $rooms): array
    {
        $out = [];
        foreach ($rooms as $r) {
            if (! is_array($r)) continue;
            $key = $this->roomKey($r['name'] ?? '');
            if ($key === '') continue;
            $out[$key] = $r;
        }
        return $out;
    }

    /** @return array<string, string> item-name → category-label */
    private function indexItemsByName(array $room): array
    {
        $out = [];
        // Prefer the canonical subsystems path (post-Pass B); fall back to
        // flat equipment list for legacy generated_data.
        if (! empty($room['subsystems']) && is_array($room['subsystems'])) {
            foreach ($room['subsystems'] as $categoryLabel => $items) {
                foreach ((array) $items as $i) {
                    if (! is_array($i)) continue;
                    $name = $this->itemKey($i);
                    if ($name !== '') $out[$name] = (string) $categoryLabel;
                }
            }
            return $out;
        }
        foreach ($room['equipment'] ?? [] as $i) {
            if (! is_array($i)) continue;
            $name = $this->itemKey($i);
            if ($name !== '') $out[$name] = '(ungrouped)';
        }
        return $out;
    }

    private function itemKey(array $item): string
    {
        $name = trim((string) ($item['name'] ?? $item['description'] ?? ''));
        // Collapse whitespace so "foo  bar" and "foo bar" merge.
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
        return $name;
    }

    private function roomKey(string $name): string
    {
        $n = strtolower(trim($name));
        // Strip trailing close-paren artefact from Pass B normaliser.
        $n = rtrim($n, ' )');
        return $n;
    }

    /**
     * Blocker diff by (type, room, action) fingerprint so wording tweaks
     * on the issue string don't produce false churn.
     *
     * @param list<array> $oldBlockers
     * @param list<array> $newBlockers
     * @return array{added: list<array>, removed: list<array>}
     */
    private function diffBlockers(array $oldBlockers, array $newBlockers): array
    {
        $fingerprint = function (array $b): string {
            return ($b['type'] ?? '?') . '|' . ($b['room'] ?? '?') . '|' . ($b['action'] ?? '?');
        };
        $oldFp = [];
        foreach ($oldBlockers as $b) {
            if (is_array($b)) $oldFp[$fingerprint($b)] = $b;
        }
        $newFp = [];
        foreach ($newBlockers as $b) {
            if (is_array($b)) $newFp[$fingerprint($b)] = $b;
        }
        $added = array_values(array_diff_key($newFp, $oldFp));
        $removed = array_values(array_diff_key($oldFp, $newFp));
        return ['added' => $added, 'removed' => $removed];
    }
}
