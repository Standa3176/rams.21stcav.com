<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Phase 46.4 Plan 03 Task 1 — the per-project ASSET REGISTER (D-05).
 *
 * ── THE CAPTURE ALREADY EXISTED. THIS IS THE LIST ───────────────────────────
 *
 * Since Phase 4, an engineer photographs an equipment label, the OCR extracts
 * part / serial / MAC, somebody confirms it, and the values are written onto a
 * `Device` row. Every piece of that works. What has never existed is a screen
 * where the OFFICE CAN READ IT. D-05, verbatim from the user: "serial number
 * capture for asset list etc." — build the surface, do not rebuild the capture.
 *
 * ── WHAT IS ON THE REGISTER, AND WHY NOT EVERYTHING ─────────────────────────
 *
 * A device qualifies when it carries a non-empty `serial_number`, `mac_address`
 * or `asset_tag`, OR when it has a CONFIRMED `DeviceLabelPhoto`.
 *
 * A device with none of those has not been visited. Listing it would turn the
 * register into a second copy of the bill of materials, which the office
 * already has on the quote — and the one thing this screen is for is telling
 * the office WHAT IS ACTUALLY ON THE WALL.
 *
 * ── ONE QUERY, TWO OUTPUTS ──────────────────────────────────────────────────
 *
 * `assetRows()` is private and both actions go through it. A screen and a CSV
 * that each built their own query would drift, and the first anyone would know
 * is an office reconciling a spreadsheet against a screen that disagrees.
 *
 * ⚠️ THE SELECT IS EXPLICIT, AND THAT IS THE POINT — same mechanism as
 * `AllocatedEngineers`: a column that was never fetched cannot be rendered by
 * accident, not by a `@json`, not by a `dd()`, not by a future `->toArray()`.
 *
 * ⚠️ `device_label_photos.captured_by` IS NEVER SELECTED AND NEVER RENDERED.
 * It holds `ip:…|actor:<sha256 slice>` and once held the leading hex of a
 * worksheet UUID token; `2026_07_08_170000_backfill_device_label_photos_
 * captured_by_leak.php` had to null every legacy value. The label-photo
 * relation is touched here ONLY through `whereHas` and a `withCount` — neither
 * of which hydrates a photo row at all.
 *
 * ── THIS IS NOT THE O&M ASSET TEMPLATE ──────────────────────────────────────
 *
 * `OmManualController::downloadAssetTemplate` exports EVERY device with blank
 * fill-in columns, as the round-trip import template for an O&M manual. This
 * exports only what was captured, read-only, for reconciliation. Different
 * question, different audience; deliberately not merged.
 *
 * ⚠️ FLAG 5 (carried from planning): this screen's SHAPE was Claude's
 * discretion. It has no D-number and the user has not seen it. It is therefore
 * written to be RIPPED OUT CHEAPLY — one controller, one Blade, two routes,
 * one query method, no new model code, no new service, no new CSS file, no new
 * package, nothing else in the app referring to it except a single anchor on
 * the worksheet show page. Deleting those four things removes the feature
 * completely. Checkpoint step 1 of plan 46.4-07 asks the user directly.
 *
 * @see resources/views/projects/asset-list.blade.php
 * @see tests/Feature/Assets/ProjectAssetListTest.php
 * @see .planning/phases/46.4-engineer-link-install-capture/46.4-CONTEXT.md (D-05)
 */
class ProjectAssetListController extends Controller
{
    /**
     * The CSV column set. A constant so the header row and the body can never
     * fall out of step — the same reason ASSET_CSV_HEADERS is a constant in
     * OmManualController.
     */
    private const CSV_HEADERS = [
        'Room',
        'Description',
        'Manufacturer',
        'Model',
        'Part No',
        'Qty',
        'Serial Number',
        'MAC Address',
        'Asset Tag',
    ];

    // =========================================================================
    // INDEX
    // =========================================================================

    public function index(Project $project): View
    {
        abort_unless(auth()->check(), 403); // Shared workspace — same guard as the sibling project screens.

        $rows = $this->assetRows($project);

        // Grouped in the controller so the Blade only renders. A room that is
        // NULL or blank on a device becomes a NAMED group, never an empty
        // heading the office has to guess at.
        $byRoom = $rows->groupBy(
            static fn (Device $device): string => trim((string) $device->room_name) !== ''
                ? (string) $device->room_name
                : 'Room not recorded'
        );

        return view('projects.asset-list', compact('project', 'rows', 'byRoom'));
    }

    // =========================================================================
    // EXPORT
    // =========================================================================

    /**
     * The same rows the screen shows, as CSV, so the list can sit beside the
     * quote in a spreadsheet. Native `fputcsv` — no package is installed for
     * this, and none should be.
     */
    public function export(Project $project): StreamedResponse
    {
        abort_unless(auth()->check(), 403);

        $rows = $this->assetRows($project);

        $slug  = str($project->ref ?? $project->name ?? 'project')->slug()->toString();
        $fname = "asset-register-{$slug}.csv";

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');

            // UTF-8 BOM so Excel on Windows reads the encoding correctly —
            // same reason OmManualController writes one.
            fwrite($out, "\xEF\xBB\xBF");

            // Explicit $escape='' — PHP 8.4 deprecates the implicit "\" and
            // 8.5 makes it fatal. Precedent: BuildCableScheduleJob.
            $csvArgs = [',', '"', ''];

            fputcsv($out, self::CSV_HEADERS, ...$csvArgs);

            foreach ($rows as $device) {
                fputcsv($out, [
                    (string) ($device->room_name     ?? ''),
                    (string) ($device->description   ?? ''),
                    (string) ($device->manufacturer  ?? ''),
                    (string) ($device->model         ?? ''),
                    (string) ($device->part_no       ?? ''),
                    (string) ($device->qty           ?? ''),
                    (string) ($device->serial_number ?? ''),
                    (string) ($device->mac_address   ?? ''),
                    (string) ($device->asset_tag     ?? ''),
                ], ...$csvArgs);
            }

            fclose($out);
        }, $fname, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $fname . '"',
        ]);
    }

    // =========================================================================
    // THE ONE QUERY
    // =========================================================================

    /**
     * Every device on this project that somebody has actually captured on
     * site. Read by BOTH actions — see the class docblock.
     *
     * @return Collection<int, Device>
     */
    private function assetRows(Project $project): Collection
    {
        return Device::query()
            ->where('project_id', $project->id)
            // ⚠️ Explicit projection. `device_label_photos.captured_by` is not
            // reachable from here at all: the label-photo relation is used
            // only in a whereHas/withCount, which hydrate no photo row.
            ->select([
                'id',
                'project_id',
                'room_name',
                'description',
                'model',
                'manufacturer',
                'part_no',
                'qty',
                'serial_number',
                'mac_address',
                'asset_tag',
            ])
            ->withCount([
                'labelPhotos as confirmed_label_photo_count' => static fn (Builder $q) => $q->where('confirmed', true),
            ])
            ->where(function (Builder $query): void {
                $query
                    ->where(fn (Builder $q) => $q->whereNotNull('serial_number')->where('serial_number', '<>', ''))
                    ->orWhere(fn (Builder $q) => $q->whereNotNull('mac_address')->where('mac_address', '<>', ''))
                    ->orWhere(fn (Builder $q) => $q->whereNotNull('asset_tag')->where('asset_tag', '<>', ''))
                    ->orWhereHas('labelPhotos', fn (Builder $q) => $q->where('confirmed', true));
            })
            ->orderBy('room_name')
            ->orderBy('description')
            ->orderBy('id')
            ->get();
    }
}
