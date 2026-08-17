<?php

declare(strict_types=1);

namespace App\Support\Rams;

use App\Support\Quote\NonRoomAreaLabels;

/**
 * Normalises raw quote line items for the RAMS equipment-schedule FALLBACK
 * path — the branch taken when `scope_items` (decommission / retained /
 * new_install) is entirely empty because the RAMS form's scope buckets were
 * never populated (`RamsFormRequest` declares them `nullable`).
 *
 * 260817-r5e Item 2. In 21CQ30960-OPS Rev 1.0 that fallback:
 *
 *   1. Banner-headed every row "NEW INSTALLATION" — including kit explicitly
 *      being RECOVERED from the Willen decommission. Telling an engineer to
 *      install equipment that is being reused from another room is worse
 *      than saying nothing.
 *   2. Emitted the 75" display + mount and the 55" twice, because the quote
 *      genuinely lists the same item under two areas.
 *   3. Printed "Hardware" — a category name — in the "Room / Area" column.
 *
 * All three came from the one branch passing raw rows straight through. This
 * class makes that path honest instead: the generator does NOT know the
 * activity here, so it does not claim one; identical items are grouped with
 * their quantities summed; and an `area` that is a grouping header rather
 * than a room is suppressed (Item 2b fixed the import that produced them —
 * this covers packages imported before that fix).
 *
 * Both renderers use it so the DOCX and the PDF cannot diverge:
 *   @see \App\Services\DocxBuilderService::buildScopeOfWorks
 *   @see resources/views/pdf/rams-v2.blade.php
 */
final class EquipmentScheduleFallback
{
    /**
     * Group-header text for the fallback block.
     *
     * Deliberately NOT an activity claim. The scope buckets are empty, so
     * nothing in the data says whether these items are being installed,
     * retained or stripped out.
     */
    public const SECTION_LABEL = 'EQUIPMENT SCHEDULE';

    /** Per-row Activity cell. Says "unknown" rather than asserting one. */
    public const ACTIVITY_LABEL = 'Not specified';

    /**
     * @param  array<int,mixed>  $lineItems  Raw `quote.line_items` rows
     *                                       ({sku, qty, description, room}).
     * @return list<array{activity:string,item:string,area:string,qty:string}>
     */
    public static function rows(array $lineItems): array
    {
        /** @var array<string,array{activity:string,item:string,area:string,qty:float,qty_raw:string,summable:bool}> $grouped */
        $grouped = [];

        foreach ($lineItems as $item) {
            if (! is_array($item)) {
                continue;
            }

            $description = trim((string) ($item['description'] ?? ''));
            if ($description === '') {
                continue; // a row with no item name tells an engineer nothing
            }

            $area = self::displayArea((string) ($item['room'] ?? ($item['area'] ?? '')));

            // Group on item + area. Two rows of the same item in DIFFERENT
            // rooms stay separate — that is real information. Two rows of the
            // same item in the same (or no) area are the duplicate.
            $key = mb_strtolower($description) . '||' . mb_strtolower($area);

            $rawQty  = trim((string) ($item['qty'] ?? ''));
            $numeric = is_numeric($rawQty);

            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'activity' => self::ACTIVITY_LABEL,
                    'item'     => $description,
                    'area'     => $area,
                    'qty'      => $numeric ? (float) $rawQty : 0.0,
                    'qty_raw'  => $rawQty,
                    'summable' => $numeric,
                ];
                continue;
            }

            // A duplicate. Sum quantities when every contributing row is
            // numeric; otherwise keep the first row's raw value rather than
            // invent a total.
            if ($numeric && $grouped[$key]['summable']) {
                $grouped[$key]['qty'] += (float) $rawQty;
            } else {
                $grouped[$key]['summable'] = false;
            }
        }

        $out = [];
        foreach ($grouped as $row) {
            $out[] = [
                'activity' => $row['activity'],
                'item'     => $row['item'],
                'area'     => $row['area'],
                'qty'      => $row['summable'] ? self::formatQty($row['qty']) : $row['qty_raw'],
            ];
        }

        return $out;
    }

    /**
     * Blank an `area` that is a QuoteWerks grouping header rather than a
     * physical room ("Hardware", "Professional Services", "Summary", …).
     * An empty cell is honest; a category name in a Room column is not.
     */
    public static function displayArea(string $area): string
    {
        $area = trim($area);

        return NonRoomAreaLabels::isNonRoom($area) ? '' : $area;
    }

    /** 2.0 → "2"; 2.5 → "2.5". */
    private static function formatQty(float $qty): string
    {
        if ($qty <= 0.0) {
            return '';
        }

        return abs($qty - round($qty)) < 0.0001
            ? (string) (int) round($qty)
            : rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');
    }
}
