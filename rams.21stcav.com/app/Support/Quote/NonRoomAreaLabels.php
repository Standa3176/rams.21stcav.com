<?php

declare(strict_types=1);

namespace App\Support\Quote;

/**
 * Canonical list of QuoteWerks section headers that are NOT physical rooms.
 *
 * QW section headers (LineType 32/256) serve two purposes:
 *   - Rooms      — Oregano, Cinnamon, Board Room, Reception
 *   - Groupings  — Professional Services, Hardware, Summary, Delivery
 *
 * The fetcher can't tell them apart: it threads every section header into
 * `area` on the products beneath it and appends them all to `rooms[]`.
 * Knowing "what is a real room" belongs here, in RAMS-specific land.
 *
 * Extracted to a shared class by 260817-r5e. Before that the list was
 * copy-pasted between QuoteWerksImportService and
 * PackagesReclassifyEquipmentCommand with a "keep in sync" comment, and the
 * RAMS renderers had no access to it at all — so a category name that got
 * past import (e.g. "Hardware") printed in the document's "Room / Area"
 * column as though it were a room.
 *
 * Map format: regex → forced_category (null = clear the area but leave the
 * classifier's category alone).
 *
 * Anchoring matters. `/^\s*hardware\s*$/i` clears a section header that is
 * exactly "Hardware" while leaving a genuine room called "Hardware Store"
 * untouched. Only add unanchored patterns for phrases that cannot be part
 * of a real room name.
 */
final class NonRoomAreaLabels
{
    /** @var array<string,string|null> */
    public const PATTERNS = [
        // ── Grouping headers (260725-qw3) ────────────────────────────────
        '/professional\s+services?/i'          => 'services',
        '/^\s*services?\s*$/i'                 => 'services',
        '/^\s*labour\s*$/i'                    => 'services',
        '/^\s*delivery\s*$/i'                  => 'services',
        '/^\s*consumables?\s*$/i'              => 'consumables',
        '/^\s*summary\s*$/i'                   => null,
        '/room\s+booking\s+panels?/i'          => null,

        // ── Canonical CATEGORY names used as section headers (260817-r5e).
        // `Hardware` is the one proven in 21CQ30960 — it reached the RAMS
        // equipment schedule's "Room / Area" column. The rest are the
        // remaining EquipmentCategoryClassifier::CATEGORIES values that can
        // plausibly head a QW section. All anchored.
        //
        // `hardware`, `hardware supply only`, `option(s)` and `unknown` map
        // to null deliberately: they are grouping headers, and
        // hardware_supply_only / option carry commercial meaning that is the
        // PM's call at review, not a keyword's.
        '/^\s*hardware\s*$/i'                  => null,
        '/^\s*hardware\s+supply\s+only\s*$/i'  => null,
        '/^\s*cables?\s*$/i'                   => 'cables',
        '/^\s*service\s+contracts?\s*$/i'      => 'service_contracts',
        '/^\s*customer\s+supplied\s*$/i'       => 'customer_supplied',
        '/^\s*options?\s*$/i'                  => null,
        '/^\s*unknown\s*$/i'                   => null,
    ];

    /**
     * Is this section-header / area label a grouping rather than a room?
     *
     * An empty label is never a real room.
     */
    public static function isNonRoom(string $name): bool
    {
        if (trim($name) === '') {
            return true;
        }

        foreach (array_keys(self::PATTERNS) as $pattern) {
            if (preg_match($pattern, $name) === 1) {
                return true;
            }
        }

        return false;
    }
}
