<?php

namespace App\Services;

/**
 * HardwareClassificationService
 *
 * Single source of truth for "is this quote line an installable piece of
 * hardware (i.e. something that should appear in the RAMS scope-of-works
 * table), or is it a service / warranty / cable / labour / consumable?"
 *
 * Extracted from RamsController::patchRamsForDisplay() during H-09. The
 * sibling services (CableScheduleGeneratorService, WorksheetGeneratorService,
 * OmManualGeneratorService) keep their own keyword sets because they
 * classify for different purposes (cable-row generation, worksheet grouping,
 * O&M asset register) and the sets intentionally diverge in small ways.
 * This classifier serves ONLY the RAMS display path.
 *
 * All logic is deterministic regex + array-membership. No AI, no config.
 */
class HardwareClassificationService
{
    /**
     * Non-hardware keyword regex. Matches labour, services, warranties,
     * delivery, consumables, fixings, and RAMS/compliance doc line items.
     * Case-insensitive.
     */
    private const NON_HARDWARE_PATTERN =
        '/\b('
        . 'site\s+survey|engineering\s+team|av\s+team|installation\s+team'
        . '|project\s+management|programme\s+management'
        . '|configuration\s+service|commissioning\s+service'
        . '|extended\s+service|extended\s+warranty|poly\+'
        . '|support\s+plan|care\s+plan|maintenance\s+plan|\byear\s+warranty'
        . '|consumable|cable\s+tie|fixings?'
        . '|network\s+cable|patch\s+cable|patch\s+lead|snagless'
        . '|delivery|carriage|freight|shipping'
        . '|travel|mileage|per\s+vehicle|parking'
        . '|labour|man\s+day|man-day|off\s+site|on\s+site\s+management'
        . '|rams\b|risk\s+assessment|method\s+statement'
        . '|discount|credit|foc\b|free\s+of\s+charge'
        . '|\bvat\b|\btax\b'
        . ')/i';

    /**
     * Cable-product regex. Brand/length prefix allowed (e.g. "Lindy 10m Cat6 …").
     * Distinct from patch-cable (which is already in NON_HARDWARE_PATTERN) —
     * this catches bare cable products like "HDMI Cable", "Cat6 Cable" etc.
     */
    private const CABLE_PATTERN =
        '/\b(cat\d[ae]?|hdmi\s+cable|dp\s+cable|displayport\s+cable|usb\s+cable'
        . '|fibre\s+cable|fiber\s+cable|xlr\s+cable|dmx\s+cable|coax\s+cable'
        . '|ethernet\s+cable)\b/i';

    /** Categories that are definitively never hardware. */
    private const NON_HARDWARE_CATEGORIES = [
        'cables',
        'consumables',
        'services',
        'option',
        'labour',
    ];

    /**
     * True iff the item looks like installable hardware for the RAMS scope.
     *
     * @param string $name     Description / item name (required)
     * @param string $itemType Optional item_type field from ExtractQuoteJob —
     *                         trusted when set to 'hardware', 'consumable',
     *                         or 'professional_service'.
     * @param string $category Optional category field (lowercased before comparison)
     */
    public function isHardware(string $name, string $itemType = '', string $category = ''): bool
    {
        $nameStr = trim($name);
        if ($nameStr === '') {
            return false;
        }

        // Generic placeholder rows.
        if (preg_match('/^\s*additional\s*$/i', $nameStr)) {
            return false;
        }

        // item_type is the strongest signal when present — trust it.
        if ($itemType === 'consumable' || $itemType === 'professional_service') {
            return false;
        }
        if ($itemType === 'hardware') {
            return true;
        }

        // Category field.
        if (in_array(strtolower($category), self::NON_HARDWARE_CATEGORIES, true)) {
            return false;
        }

        // Keyword heuristic — non-hardware check then cable check.
        $lower = strtolower($nameStr);
        if (preg_match(self::NON_HARDWARE_PATTERN, $lower) || preg_match(self::CABLE_PATTERN, $nameStr)) {
            return false;
        }

        return true;
    }
}
