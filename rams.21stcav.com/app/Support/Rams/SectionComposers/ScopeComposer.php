<?php

namespace App\Support\Rams\SectionComposers;

use App\Models\RamsDocument;
use App\Support\Rams\Sections\ScopeSectionDto;

/**
 * Composes Section 4 (Scope of Works) from post-patch RamsDocument.
 *
 * RamsDisplayPatchService::patch() has already:
 *   - Filtered $rams->generated_data.scope_items.new_install to hardware.
 *   - Routed "Existing X — deinstall" rows into `decommission`.
 *   - Routed "to be retained" rows into `retained`.
 *   - Extracted parenthesised room suffixes into `room`.
 *
 * This composer just reads the three buckets and pulls plain-text
 * activities from reviewed_data.
 *
 * Never mutates $record.
 */
final class ScopeComposer
{
    public function compose(RamsDocument $record): ScopeSectionDto
    {
        $gd         = $record->generated_data ?? [];
        $rd         = $record->reviewed_data  ?? [];
        $scopeItems = (array) ($gd['scope_items'] ?? []);

        $activities = [];
        foreach ((array) ($rd['scope_activities'] ?? []) as $a) {
            $s = trim((string) $a);
            if ($s !== '') {
                $activities[] = $s;
            }
        }

        // Split cross-project vs per-room activities. Both renderers today
        // read them as a flat list; we surface the split so the DTO can
        // grow into a per-room block later without a schema break.
        $perRoom = [];
        foreach ((array) ($rd['per_room_scope'] ?? []) as $roomName => $items) {
            $bullets = [];
            foreach ((array) $items as $i) {
                $s = trim((string) $i);
                if ($s !== '') {
                    $bullets[] = $s;
                }
            }
            if ($bullets !== []) {
                $perRoom[(string) $roomName] = $bullets;
            }
        }

        return ScopeSectionDto::fromArray([
            'activities'     => $activities,
            'per_room_scope' => $perRoom,
            'new_install'    => (array) ($scopeItems['new_install']   ?? []),
            'decommission'   => (array) ($scopeItems['decommission'] ?? []),
            'retained'       => (array) ($scopeItems['retained']     ?? []),
        ]);
    }
}
