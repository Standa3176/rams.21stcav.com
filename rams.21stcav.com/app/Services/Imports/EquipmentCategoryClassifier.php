<?php

declare(strict_types=1);

namespace App\Services\Imports;

/**
 * EquipmentCategoryClassifier — canonical vocabulary bucketing for equipment
 * rows regardless of import source (QuoteWerks SQL, PDF parse, manual entry).
 *
 * The 7 canonical values match what the review UI dropdown offers
 * (resources/views/project-packages/review.blade.php ~line 2211-2217):
 *
 *   hardware, cables, consumables, services, service_contracts,
 *   customer_supplied, option
 *
 * Both the QW importer (QuoteWerksImportService::buildExtractedData) and
 * the on-save allowlist (ProjectPackageReviewController::normaliseEquipmentCategory)
 * MUST agree on this set. Previous fabricated values (`display`, `audio`,
 * `camera`, `cable`, `mounting`, `signal_distribution`, `control`, `furniture`,
 * `service`, `other`) were silently defaulted to `hardware` on save because
 * they weren't in the allowlist — that flattened every QW-imported package
 * into a single "Hardware" bucket. Fixed 260725-qw3.
 *
 * NOT to be confused with App\Services\EquipmentClassifierService, which
 * produces RAMS activity keys (`display_installation`, `ceiling_works`, etc.)
 * for method-statement + risk-assessment generation. Different purpose,
 * different vocab, different consumers.
 *
 * @see \App\Http\Controllers\ProjectPackageReviewController::normaliseEquipmentCategory
 * @see \App\Core\Modules\QuoteImport\QuoteWerksImportService::buildExtractedData
 */
class EquipmentCategoryClassifier
{
    /** The 7 canonical category values. */
    public const CATEGORIES = [
        'hardware',
        'cables',
        'consumables',
        'services',
        'service_contracts',
        'customer_supplied',
        'option',
    ];

    /**
     * Classify a single equipment row into one of the 7 canonical categories.
     *
     * If the incoming row already carries a canonical `category`, return it
     * verbatim (respects manual dropdown selections on save). Otherwise fall
     * to a priority-ordered keyword decision tree (specific matches first,
     * broader last, `hardware` default).
     *
     * Accepted keys on $item (any/all optional): `category`, `name`,
     * `description`, `part_number`.
     *
     * @param  array<string,mixed>  $item  Equipment row.
     * @return string                       One of self::CATEGORIES.
     */
    public function classify(array $item): string
    {
        // 1. Explicit-category short-circuit — respects manual dropdown picks
        //    and pre-classified rows on re-save. Only fall to keyword matching
        //    when the caller didn't (or couldn't) set a valid canonical value.
        $rawCat = strtolower(trim((string) ($item['category'] ?? '')));
        if (in_array($rawCat, self::CATEGORIES, true)) {
            return $rawCat;
        }

        // 2. Build a lowercase haystack from every text field the item carries.
        //    Empty strings collapse to a single space — harmless for str_contains.
        $text = strtolower(trim(implode(' ', [
            (string) ($item['name']        ?? ''),
            (string) ($item['description'] ?? ''),
            (string) ($item['part_number'] ?? ''),
        ])));

        // 3. Priority-ordered decision tree — specific matches FIRST so a
        //    "Cat6 warranty extension" doesn't get miscategorised as `cables`.

        // ── option ─────────────────────────────────────────────────────
        foreach (['optional', 'option'] as $kw) {
            if (str_contains($text, $kw)) {
                return 'option';
            }
        }

        // ── customer_supplied ─────────────────────────────────────────
        foreach ([
            'existing',
            'client supplied',
            'customer supplied',
            '**client supplied**',
            'client-supplied',
            'customer-supplied',
            'byo',
            'byod',
        ] as $kw) {
            if (str_contains($text, $kw)) {
                return 'customer_supplied';
            }
        }

        // ── service_contracts ─────────────────────────────────────────
        foreach ([
            'warranty',
            'care pack',
            'carepack',
            'coverplus',
            'assurcare',
            'prosupport',
            'service plan',
            'extended service',
            'swap out',
            'year warranty',
        ] as $kw) {
            if (str_contains($text, $kw)) {
                return 'service_contracts';
            }
        }

        // ── consumables ───────────────────────────────────────────────
        foreach ([
            'consumable',
            'fixing',
            'fastener',
            'rawlplug',
            'anchor',
            'screw',
            'bolt',
            'tape',
            'label',
            'cleat',
            'tie',
            'strap',
        ] as $kw) {
            if (str_contains($text, $kw)) {
                return 'consumables';
            }
        }

        // ── cables ────────────────────────────────────────────────────
        foreach ([
            'cable',
            'cat5',
            'cat6',
            'cat6a',
            'cat7',
            'cat8',
            'hdmi cable',
            'sdi',
            'utp',
            'ftp',
            'stp',
            'patch lead',
            'patch cable',
            'fibre',
            'fiber optic',
            'rg6',
            'rg59',
            'trunking',
            'conduit',
        ] as $kw) {
            if (str_contains($text, $kw)) {
                return 'cables';
            }
        }

        // ── services ──────────────────────────────────────────────────
        //
        // Two-pass matching. Rationale: bare substring matching against the
        // full description was catching product marketing copy ("supports
        // single or dual video displays" → 'support' → services; "confirmed
        // during the site survey" → 'survey' → services). Fixed 260725-fx2.
        //
        // Pass (a): known QW/21CAV services SKU tokens on the PART NUMBER
        // field only. These are canonical entries in the 21CAV product
        // catalogue and never appear in genuine hardware SKUs. Substring
        // match is safe here — SKUs are short + specific.
        $partOnly = strtolower(trim((string) ($item['part_number'] ?? '')));
        foreach ([
            'install',            // INSTALL2 (21CAV engineering labour)
            'programming',        // PROGRAMMING1
            'projectmanagement',  // PROJECTMANAGEMENT
            'projmanoff',         // PROJMANOFFHALF
            'handover',           // HANDOVER
            'rackbuild',          // RACKBUILDON
            'delivery',           // DELIVERY (21CAV / Midwich SKU)
            'ssv',                // SSVOTHER (site survey)
            'guidevelopment',     // GUIDEVELOPMENT
            'configuration',      // CONFIGURATION
            'commissioning',      // Crestron IV-PROSERVICE-1B etc when descr uses this
            'proservice',         // IV-PROSERVICE-1B
        ] as $pn) {
            if ($pn !== '' && str_contains($partOnly, $pn)) {
                return 'services';
            }
        }

        // Special-case: RAMS as an exact / word-boundary part_number match
        // (avoids matching product SKUs that happen to contain "rams" as
        // a substring — e.g. a hypothetical "GRAMS-42").
        if (preg_match('/\brams\b/i', $partOnly)) {
            return 'services';
        }

        // Pass (b): word-boundary matches on the full text for unambiguous
        // multi-word service phrases + long-form single words. Word-bounded
        // to prevent "supports" / "installer" / "management console" etc.
        foreach ([
            'installation',
            'commissioning',
            'labour',
            'professional service',
            'onsite service',
            'on-site service',
            'handover',
            'rack build',
        ] as $kw) {
            if (preg_match('/\b' . preg_quote($kw, '/') . '\b/i', $text)) {
                return 'services';
            }
        }

        // ── Default ───────────────────────────────────────────────────
        return 'hardware';
    }
}
