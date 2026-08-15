<?php

namespace App\Services;

use App\Models\DeviceLabelPhoto;
use App\Models\Project;
use App\Models\SiteSurvey;
use App\Models\SiteSurveyPhoto;
use App\Models\SiteSurveyRoom;
use App\Models\Worksheet;
use App\Models\WorksheetPhoto;
use App\Services\Imports\EquipmentCategoryClassifier;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * MiniOmBuilderService — pure data aggregator for the on-demand Mini O&M PDF
 * (260506-qa9). Bundles existing project data into a single array consumed by
 * resources/views/pdf/mini-om.blade.php.
 *
 * Pipeline position: between MiniOmController and the Blade renderer. NO DB
 * writes, no file writes, no HTTP, no AI — purely shape-and-collate. This
 * service is safe to call from any context (web request, queued job, tinker)
 * and is idempotent regardless of how many times it runs.
 *
 * Returned shape (consumed verbatim by pdf.mini-om):
 *
 *   [
 *     'project' => [
 *       'name' => string, 'ref' => string, 'client' => string, 'site' => string,
 *       'works_description' => string, 'lead_engineer' => string,
 *       'install_started' => ?Carbon, 'handover_date' => ?Carbon,
 *       'is_signed' => bool, 'generated_at' => Carbon,
 *     ],
 *     'cover'  => ['hero_photo_abs_path' => ?string],
 *     'rooms'  => [
 *       [
 *         'name' => string,
 *         'scope_sentence' => string,
 *         'assets' => [
 *           'confirmed' => [['manufacturer','model','part_number','serial','mac'], ...],
 *           'quoted'    => [['manufacturer','model','part_number','description','qty'], ...],
 *         ],
 *         'photos' => [
 *           'after'  => [['abs_path','caption'], ...],
 *           'before' => [['abs_path','caption'], ...],
 *         ],
 *         'signoff' => ['engineer' => ?string, 'client' => ?string, 'date' => ?Carbon],
 *       ], ...
 *     ],
 *     'asset_register' => ['confirmed' => [...], 'also_installed' => [...]],
 *     'support'        => [4 keys from config('rams.mini_om_support')],
 *     'company'        => [6 keys from config('rams.*')],
 *   ]
 *
 * D-LOCK references in inline comments map to PLAN.md must_haves.truths:
 *   D-LOCK-1 cover hero auto-pick
 *   D-LOCK-2 confirmed-first asset overlay
 *   D-LOCK-3 all rooms, never skip
 *   D-LOCK-6 graceful before/after pair
 */
class MiniOmBuilderService
{
    // ══════════════════════════════════════════════════════════════════════════
    // Public API
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Build the Mini O&M data array for a project. Eager-loads everything the
     * Blade needs so the render is N+1-safe.
     *
     * @return array{
     *     project: array,
     *     cover: array,
     *     rooms: array<int, array>,
     *     asset_register: array,
     *     support: array,
     *     company: array,
     * }
     */
    public function build(Project $project): array
    {
        // ── Eager load everything the Blade reads from $project ────────────
        $project->load([
            'owner',
            'latestPackage',
            'worksheets.photos',
            'worksheets.signoffs',
            'siteSurveys.rooms.photos',
        ]);

        // Single project-wide DeviceLabelPhoto query (NOT N+1 per room).
        $confirmedLabels = DeviceLabelPhoto::query()
            ->where('project_id', $project->id)
            ->where('confirmed', true)
            ->get();

        // Hardware-only filter for the asset views — hides test photos
        // and any orphan label captures whose part_number isn't in the
        // project's quoted hardware list. Empty array = no filter applied
        // (e.g. fresh project pre-quote-import).
        $hardwareParts = $project->hardwarePartNumbers();
        if (! empty($hardwareParts)) {
            $confirmedLabels = $confirmedLabels->filter(function (DeviceLabelPhoto $photo) use ($hardwareParts) {
                $part = strtolower(trim((string) (data_get($photo->ai_extracted, 'part_number') ?? '')));
                return $part !== '' && in_array($part, $hardwareParts, true);
            })->values();
        }

        $package      = $project->latestPackage;
        $latestSurvey = $this->latestActiveSurvey($project);

        if ($package === null) {
            Log::warning('MiniOmBuilderService: project has no latest package', [
                'project_id' => $project->id,
            ]);
        }

        return [
            'project'        => $this->buildProjectMeta($project),
            'cover'          => ['hero_photo_abs_path' => $this->pickCoverHeroPath($project)],
            'rooms'          => $this->buildRooms($project, $package, $latestSurvey, $confirmedLabels),
            'asset_register' => $this->buildAssetRegister($package, $confirmedLabels),
            'support'        => $this->buildSupportBlock(),
            'company'        => $this->buildCompanyBlock(),
        ];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Project meta
    // ══════════════════════════════════════════════════════════════════════════

    private function buildProjectMeta(Project $project): array
    {
        $package = $project->latestPackage;

        $worksDescription = (string) ($project->works_description
            ?? $package?->works_description
            ?? '');

        // Any worksheet signed = project signed (matches D-LOCK-5 "is_signed"
        // semantics — the cover pill turns green once any room is accepted).
        $isSigned = $project->worksheets->contains(
            fn (Worksheet $w) => $w->signoffs->isNotEmpty()
        );

        // Lead engineer — prefer the properly-typed name entered on the
        // project package's Programme block (where users type "Sonny Tanda")
        // over the raw account owner name (which is often a login handle
        // like "sonny"). Same fallback chain the RAMS builder uses
        // (RamsBuilderService.php: $leName = programme.lead_engineer_name
        // ?? project.lead_engineer ?? '').
        $latestPackage = $project->latestPackage
            ?? $project->packages()->latest('id')->first();
        $programmeLead = '';
        if ($latestPackage !== null) {
            $extracted = is_array($latestPackage->extracted_data)
                ? $latestPackage->extracted_data
                : [];
            $programmeLead = trim((string) ($extracted['programme']['lead_engineer_name'] ?? ''));
        }
        $ownerName = trim((string) ($project->owner?->name ?? ''));

        return [
            'name'              => (string) ($project->name ?? ''),
            'ref'               => (string) ($project->ref ?? ''),
            'client'            => (string) ($project->client_name ?? ''),
            'site'              => (string) ($project->site_address ?? ''),
            'works_description' => $worksDescription,
            'lead_engineer'     => $programmeLead !== '' ? $programmeLead : $ownerName,
            'install_started'   => $project->installation_started_at,
            'handover_date'     => $project->handover_date,
            'is_signed'         => $isSigned,
            'generated_at'      => now(),
        ];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Cover hero photo (D-LOCK-1)
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Pick the FIRST WorksheetPhoto across the project, ordered by
     * worksheet.created_at ASC then photo.sort_order/id (Worksheet::photos
     * already orders the inner collection). Returns the absolute path or null
     * when no worksheet photos exist.
     */
    private function pickCoverHeroPath(Project $project): ?string
    {
        $worksheets = $project->worksheets->sortBy('created_at')->values();

        foreach ($worksheets as $worksheet) {
            /** @var WorksheetPhoto|null $first */
            $first = $worksheet->photos->first();
            if ($first !== null) {
                return $first->absolutePath();
            }
        }

        return null;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Rooms (D-LOCK-3 — all rooms, never skip)
    // ══════════════════════════════════════════════════════════════════════════

    private function buildRooms(
        Project $project,
        $package,
        ?SiteSurvey $latestSurvey,
        $confirmedLabels,
    ): array {
        $roomNames = $this->roomNamesFromPackage($package);

        if (empty($roomNames)) {
            return [];
        }

        $rooms = [];
        foreach ($roomNames as $roomName) {
            $rooms[] = $this->buildOneRoom(
                $project,
                $package,
                $latestSurvey,
                $confirmedLabels,
                $roomName,
            );
        }

        return $rooms;
    }

    /**
     * Extract room names from $package->extracted_data['rooms'][*]['name'].
     * Falls back to array_keys($extracted_data['room_overviews']) when the
     * 'rooms' array is missing. Returns the canonical labels engineers see in
     * the rest of the app — preserves casing.
     *
     * @return array<int, string>
     */
    private function roomNamesFromPackage($package): array
    {
        if ($package === null) {
            return [];
        }

        $extracted = $package->extracted_data ?? [];

        // -- Primary: rooms[] array of {name, ...} entries --
        if (isset($extracted['rooms']) && is_array($extracted['rooms'])) {
            $names = [];
            foreach ($extracted['rooms'] as $idx => $row) {
                if (is_array($row) && isset($row['name']) && trim((string) $row['name']) !== '') {
                    $names[] = (string) $row['name'];
                } elseif (is_string($row) && trim($row) !== '') {
                    $names[] = $row;
                }
            }
            if (! empty($names)) {
                return $names;
            }
        }

        // -- Fallback: keys of room_overviews map --
        if (isset($extracted['room_overviews']) && is_array($extracted['room_overviews'])) {
            return array_values(array_filter(
                array_map('strval', array_keys($extracted['room_overviews'])),
                fn ($n) => trim($n) !== '',
            ));
        }

        return [];
    }

    private function buildOneRoom(
        Project $project,
        $package,
        ?SiteSurvey $latestSurvey,
        $confirmedLabels,
        string $roomName,
    ): array {
        $confirmed = $this->confirmedLabelsForRoom($confirmedLabels, $roomName);
        $quoted    = $this->quotedAssetsForRoom($package, $roomName);
        $quoted    = $this->dedupeQuoted($quoted, $confirmed);

        return [
            'name'           => $roomName,
            'scope_sentence' => $this->scopeSentenceForRoom($package, $roomName),
            'assets'         => [
                'confirmed' => $confirmed,
                'quoted'    => $quoted,
            ],
            'photos' => [
                'after'  => $this->worksheetPhotosForRoom($project, $roomName),
                'before' => $this->surveyPhotosForRoom($latestSurvey, $roomName),
            ],
            'signoff' => $this->signoffForRoom($project, $roomName),
        ];
    }

    // ── Scope sentence ────────────────────────────────────────────────────────

    private function scopeSentenceForRoom($package, string $roomName): string
    {
        if ($package === null) {
            return '';
        }

        $extracted = $package->extracted_data ?? [];

        // -- Primary: room_overviews keyed by room name --
        if (isset($extracted['room_overviews']) && is_array($extracted['room_overviews'])) {
            foreach ($extracted['room_overviews'] as $key => $val) {
                if (strtolower(trim((string) $key)) === strtolower(trim($roomName))) {
                    return is_string($val) ? trim($val) : '';
                }
            }
        }

        // -- Fallback 1: rooms[].overview --
        // -- Fallback 2: rooms[].works_summary --
        if (isset($extracted['rooms']) && is_array($extracted['rooms'])) {
            foreach ($extracted['rooms'] as $row) {
                if (! is_array($row)) continue;
                $rn = (string) ($row['name'] ?? '');
                if (strtolower(trim($rn)) !== strtolower(trim($roomName))) continue;

                if (! empty($row['overview']) && is_string($row['overview'])) {
                    return trim($row['overview']);
                }
                if (! empty($row['works_summary']) && is_string($row['works_summary'])) {
                    return trim($row['works_summary']);
                }
            }
        }

        return '';
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Confirmed labels (D-LOCK-2)
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Filter the project-wide confirmed-labels collection to a single room.
     *
     * @return array<int, array{manufacturer:string,model:string,part_number:string,serial:string,mac:string}>
     */
    private function confirmedLabelsForRoom($confirmedLabels, string $roomName): array
    {
        $rows = [];
        $needle = strtolower(trim($roomName));

        foreach ($confirmedLabels as $photo) {
            if (strtolower(trim((string) $photo->room_name)) !== $needle) continue;

            $rows[] = [
                'manufacturer' => (string) (data_get($photo->ai_extracted, 'manufacturer') ?? ''),
                'model'        => (string) (data_get($photo->ai_extracted, 'model') ?? ''),
                'part_number'  => (string) (data_get($photo->ai_extracted, 'part_number') ?? ''),
                'serial'       => (string) (data_get($photo->ai_extracted, 'serial_number') ?? ''),
                'mac'          => (string) (data_get($photo->ai_extracted, 'mac_address') ?? ''),
            ];
        }

        return $rows;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Quoted assets (D-LOCK-2 overlay)
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Filter $package->equipment_list rows to a single room, normalising the
     * shape to manufacturer/model/part_number/description/qty.
     */
    private function quotedAssetsForRoom($package, string $roomName): array
    {
        if ($package === null || ! is_array($package->equipment_list)) {
            return [];
        }

        $needle = strtolower(trim($roomName));
        $rows   = [];

        // The QuoteParser populates equipment_list with shape:
        //   ['quantity' => N, 'part_number' => '…', 'name' => '…',
        //    'area' => 'Room Name', 'category' => 'hardware|services|consumables']
        // Mini O&M lists physical assets — hardware AND hardware_supply_only
        // (client-owned kit 21CAV supplies but does not install, 260815-sup) —
        // so non-included lines (services like RAMS/INSTALL2/HANDOVER,
        // consumables like DELIVERY/CABLES, etc.) are filtered out here via
        // EquipmentCategoryClassifier::isOmIncludedCategory(). Engineers and
        // clients shouldn't see "RAMS" or "PROGRAMMING1" listed as installed
        // equipment.
        foreach ($package->equipment_list as $line) {
            if (! is_array($line)) continue;
            $category = strtolower(trim((string) ($line['category'] ?? 'hardware')));
            if (! EquipmentCategoryClassifier::isOmIncludedCategory($category)) continue;
            $lineRoom = strtolower(trim((string) ($line['area'] ?? '')));
            if ($lineRoom !== $needle) continue;

            $rows[] = [
                'manufacturer' => '',  // not present in equipment_list shape
                'model'        => '',  // not present in equipment_list shape
                'part_number'  => (string) ($line['part_number'] ?? ''),
                'description'  => (string) ($line['name'] ?? ''),
                'qty'          => (int) ($line['quantity'] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * Remove rows from $quoted that are already represented in $confirmed.
     * Match by part_number exact (when both sides have one) OR by lowercased
     * trimmed manufacturer+model concatenation.
     */
    private function dedupeQuoted(array $quoted, array $confirmed): array
    {
        if (empty($confirmed) || empty($quoted)) {
            return $quoted;
        }

        $confirmedParts = array_filter(array_map(
            fn ($r) => trim((string) ($r['part_number'] ?? '')),
            $confirmed,
        ));
        $confirmedMm = array_map(
            fn ($r) => strtolower(trim((string) ($r['manufacturer'] ?? '') . '|' . (string) ($r['model'] ?? ''))),
            $confirmed,
        );

        return array_values(array_filter($quoted, function ($q) use ($confirmedParts, $confirmedMm) {
            $part = trim((string) ($q['part_number'] ?? ''));
            if ($part !== '' && in_array($part, $confirmedParts, true)) {
                return false;
            }
            $mm = strtolower(trim((string) ($q['manufacturer'] ?? '') . '|' . (string) ($q['model'] ?? '')));
            if ($mm !== '|' && in_array($mm, $confirmedMm, true)) {
                return false;
            }
            return true;
        }));
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Photos (D-LOCK-6 — graceful before/after pair)
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * After photos = WorksheetPhoto rows whose room_name matches (lower-trim).
     * Walks all worksheets so a re-generated worksheet's photos still show.
     */
    private function worksheetPhotosForRoom(Project $project, string $roomName): array
    {
        $needle = strtolower(trim($roomName));
        $out    = [];

        foreach ($project->worksheets as $worksheet) {
            foreach ($worksheet->photos as $photo) {
                if (strtolower(trim((string) $photo->room_name)) !== $needle) continue;
                $out[] = [
                    'abs_path' => $photo->absolutePath(),
                    'caption'  => $photo->caption,
                ];
            }
        }

        return $out;
    }

    /**
     * Before photos = SiteSurveyPhoto rows from the latest non-superseded
     * SiteSurvey, keyed by SiteSurveyRoom name (lower-trim match).
     */
    private function surveyPhotosForRoom(?SiteSurvey $latestSurvey, string $roomName): array
    {
        if ($latestSurvey === null) {
            return [];
        }

        $needle = strtolower(trim($roomName));
        $out    = [];

        foreach ($latestSurvey->rooms as $room) {
            /** @var SiteSurveyRoom $room */
            if (strtolower(trim((string) $room->room_name)) !== $needle) continue;

            foreach ($room->photos as $photo) {
                /** @var SiteSurveyPhoto $photo */
                $out[] = [
                    'abs_path' => $photo->absolutePath(),
                    'caption'  => $photo->caption,
                ];
            }
        }

        return $out;
    }

    /**
     * Latest non-superseded SiteSurvey for the project, if any. Used as the
     * source of "before" photos.
     */
    private function latestActiveSurvey(Project $project): ?SiteSurvey
    {
        return $project->siteSurveys
            ->whereNull('superseded_at')
            ->sortByDesc('id')
            ->first();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Sign-off (per room)
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Find the most recent signoff for any worksheet associated with this
     * project, prioritising worksheets whose generated_data references the
     * room. Falls back to the project-level latest signoff so legacy single-
     * worksheet projects still surface acceptance info.
     */
    private function signoffForRoom(Project $project, string $roomName): array
    {
        // Default empty shell — Blade renders "Pending sign-off" when date is null.
        $empty = ['engineer' => null, 'client' => null, 'date' => null];

        $needle = strtolower(trim($roomName));

        // Prefer a worksheet whose generated_data['rooms'] mentions this room.
        // If found, return its latest signoff. Otherwise fall back to any
        // worksheet's latest signoff.
        $candidate = null;
        foreach ($project->worksheets as $worksheet) {
            $rooms = data_get($worksheet->generated_data, 'rooms', []);
            if (! is_array($rooms)) continue;
            foreach ($rooms as $r) {
                $rn = is_array($r) ? (string) ($r['name'] ?? '') : (string) $r;
                if (strtolower(trim($rn)) === $needle) {
                    $candidate = $worksheet;
                    break 2;
                }
            }
        }

        if ($candidate === null) {
            // Fallback: ANY worksheet for the project that has a signoff.
            $candidate = $project->worksheets->first(fn ($w) => $w->signoffs->isNotEmpty());
        }

        if ($candidate === null) {
            return $empty;
        }

        $signoff = $candidate->signoffs->first();
        if ($signoff === null) {
            return $empty;
        }

        return [
            'engineer' => $candidate->user?->name ?? null,
            'client'   => $signoff->client_name,
            'date'     => $signoff->signed_at,
        ];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Asset register (D-LOCK-2 project-wide)
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Project-wide asset register: every confirmed device label first, then
     * every quoted equipment line NOT already represented in confirmed.
     */
    private function buildAssetRegister($package, $confirmedLabels): array
    {
        // -- Confirmed: every DeviceLabelPhoto, ordered by room then manufacturer --
        $confirmed = [];
        foreach ($confirmedLabels as $photo) {
            $confirmed[] = [
                'room'         => (string) ($photo->room_name ?? ''),
                'manufacturer' => (string) (data_get($photo->ai_extracted, 'manufacturer') ?? ''),
                'model'        => (string) (data_get($photo->ai_extracted, 'model') ?? ''),
                'part_number'  => (string) (data_get($photo->ai_extracted, 'part_number') ?? ''),
                'serial'       => (string) (data_get($photo->ai_extracted, 'serial_number') ?? ''),
                'mac'          => (string) (data_get($photo->ai_extracted, 'mac_address') ?? ''),
            ];
        }
        usort($confirmed, function ($a, $b) {
            return strcmp(
                strtolower($a['room']) . '|' . strtolower($a['manufacturer']),
                strtolower($b['room']) . '|' . strtolower($b['manufacturer']),
            );
        });

        // -- Quoted: every HARDWARE (+ hardware_supply_only, 260815-sup) line
        //    NOT yet confirmed --
        // Same shape contract as quotedAssetsForRoom() — filter via
        // EquipmentCategoryClassifier::isOmIncludedCategory() and read the
        // QuoteParser-emitted keys (area/name/quantity/part_number).
        // Services and consumables (RAMS/INSTALL2/HANDOVER/DELIVERY/CABLES/etc) are
        // excluded because they aren't physical installed equipment.
        $alsoInstalled = [];
        if ($package !== null && is_array($package->equipment_list)) {
            foreach ($package->equipment_list as $line) {
                if (! is_array($line)) continue;
                $category = strtolower(trim((string) ($line['category'] ?? 'hardware')));
                if (! EquipmentCategoryClassifier::isOmIncludedCategory($category)) continue;
                $alsoInstalled[] = [
                    'room'         => (string) ($line['area'] ?? ''),
                    'manufacturer' => '',  // not in equipment_list shape
                    'model'        => '',  // not in equipment_list shape
                    'part_number'  => (string) ($line['part_number'] ?? ''),
                    'description'  => (string) ($line['name'] ?? ''),
                    'qty'          => (int) ($line['quantity'] ?? 0),
                ];
            }
        }
        $alsoInstalled = $this->dedupeQuoted($alsoInstalled, $confirmed);
        usort($alsoInstalled, function ($a, $b) {
            return strcmp(
                strtolower($a['room']) . '|' . strtolower($a['manufacturer']),
                strtolower($b['room']) . '|' . strtolower($b['manufacturer']),
            );
        });

        return [
            'confirmed'      => $confirmed,
            'also_installed' => $alsoInstalled,
        ];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Support + company blocks (D-LOCK-7 boilerplate from config)
    // ══════════════════════════════════════════════════════════════════════════

    private function buildSupportBlock(): array
    {
        $cfg = config('rams.mini_om_support', []);

        return [
            'support_phone'               => (string) ($cfg['support_phone'] ?? ''),
            'support_email'               => (string) ($cfg['support_email'] ?? ''),
            'warranty_terms'              => (string) ($cfg['warranty_terms'] ?? ''),
            'service_ticket_instructions' => (string) ($cfg['service_ticket_instructions'] ?? ''),
        ];
    }

    private function buildCompanyBlock(): array
    {
        return [
            'name'    => (string) config('rams.company_name', ''),
            'short'   => (string) config('rams.company_short', ''),
            'address' => (string) config('rams.company_address', ''),
            'phone'   => (string) config('rams.company_phone', ''),
            'email'   => (string) config('rams.company_email', ''),
            'website' => (string) config('rams.company_website', ''),
        ];
    }
}
