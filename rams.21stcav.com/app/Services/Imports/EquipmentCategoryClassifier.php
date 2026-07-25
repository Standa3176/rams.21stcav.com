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
        foreach ([
            'install',
            'installation',
            'commission',
            'configuration',
            'programming',
            'labour',
            'support',
            'survey',
            'management',
            'training',
            'professional service',
            'onsite service',
            'on-site service',
            'handover',
            'delivery',
            'rack build',
        ] as $kw) {
            if (str_contains($text, $kw)) {
                return 'services';
            }
        }

        // ── Default ───────────────────────────────────────────────────
        return 'hardware';
    }
}
