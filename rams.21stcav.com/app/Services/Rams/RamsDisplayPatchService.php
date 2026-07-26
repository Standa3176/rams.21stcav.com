<?php

namespace App\Services\Rams;

use App\Models\Project;
use App\Models\RamsDocument;
use App\Services\HardwareClassificationService;

/**
 * RamsDisplayPatchService
 *
 * Extracted from RamsController::patchRamsForDisplay() during H-09. Applies
 * transient display patches to a RamsDocument so that both the review form
 * and the PDF download always reflect current project data, personnel,
 * contacts, and dates — without persisting anything to the DB.
 *
 * Mutates $rams in-place (model attributes only; no save/update is triggered).
 * Callers MUST NOT save() the document after this method runs: the mutations
 * are synthesized data intended solely for rendering.
 *
 * Six responsibilities, kept together because they interact with shared
 * intermediate state ($p, $gd, $rd):
 *
 *   1. Live project sync — fresh name/client/site/ref from the Project record
 *   2. Model-column fallback for any remaining empty fields
 *   3. Personnel resolution chain (programme → reviewed_data → owner → form_data)
 *   4. Client-contact inference (site_contact → site_logistics → package)
 *   5. Package-driven scope rebuild + hardware filter + room normalisation
 *   6. reviewed_data default seeding (exclusions, scope_traceability, etc)
 */
class RamsDisplayPatchService
{
    public function __construct(
        private readonly HardwareClassificationService $classifier = new HardwareClassificationService(),
    ) {}

    /** Apply all display patches to $rams in-place. */
    public function patch(RamsDocument $rams): void
    {
        $gd = $rams->generated_data ?? [];
        $p  = $gd['project'] ?? [];

        // 1. Overwrite stale strings with live Project record values.
        $liveProject = null;
        if ($rams->project_id) {
            $liveProject = Project::with(['owner', 'latestPackage'])->find($rams->project_id);
            if ($liveProject) {
                $p = array_merge($p, array_filter([
                    'name'         => $liveProject->name,
                    'client'       => $liveProject->client_name,
                    'site_address' => $liveProject->site_address,
                    'ref'          => $liveProject->ref,
                ], fn ($v) => $v !== null && $v !== ''));
            }
        }

        // 2. Fall back to model columns for any field still empty.
        $p['name']         = ($p['name']         ?? '') ?: ($rams->project_name ?? '');
        $p['client']       = ($p['client']        ?? '') ?: ($rams->client_name  ?? '');
        $p['site_address'] = ($p['site_address']  ?? '') ?: ($rams->site_address ?? '');
        $p['ref']          = ($p['ref']           ?? '') ?: ($rams->project_ref  ?? '');

        // 3. Personnel from reviewed_data['programme'].
        $rd   = $rams->reviewed_data ?? [];
        $prog = $rd['programme']     ?? [];

        // Last-resort PM: fall back to the project owner (the person who created the project).
        // Strip " - Company Name" suffix that some users append to their registered name.
        $ownerName = $liveProject?->owner?->name ?? '';
        if ($ownerName && str_contains($ownerName, ' - ')) {
            $ownerName = trim(explode(' - ', $ownerName, 2)[0]);
        }

        // Always re-resolve project_manager from live sources so stale generated_data
        // (which may contain a client email from the original form) is overwritten.
        // Priority: programme (review form) → reviewed_data → project owner → form_data last resort.
        // form_data['project_manager'] is intentionally lowest priority because it is frequently
        // populated with client contact data rather than the 21CAV PM's name.
        $p['project_manager'] = ($prog['project_manager_name'] ?? '')
            ?: ($rd['project']['project_manager'] ?? '')
            ?: $ownerName;
        if (empty($p['project_manager'])) {
            $formPm = $rams->form_data['project_manager'] ?? '';
            // Only use form_data PM if it is not an email address (email = likely client data)
            if ($formPm && ! filter_var($formPm, FILTER_VALIDATE_EMAIL)) {
                $p['project_manager'] = $formPm;
            }
        }

        // doc_author drives "Prepared By" / "Author" on the cover and Document Control table.
        // Three cases force a re-resolve to the 21CAV project_manager:
        //   a) doc_author empty
        //   b) doc_author is an email address (client data leak)
        //   c) doc_author matches the client contact name — happens when the
        //      quote parser falls back to deriving prepared_by from the client's
        //      SHIPEMAIL local part (e.g. jamesscarlett@... → "James Scarlett",
        //      who is the client, not the 21CAV author).
        $authorNorm = strtolower(trim((string) ($p['doc_author'] ?? '')));
        $clientNorm = strtolower(trim((string) ($p['client_contact_name'] ?? '')));
        $authorIsClient = $authorNorm !== '' && $clientNorm !== '' && $authorNorm === $clientNorm;

        if (empty($p['doc_author'])
            || filter_var($p['doc_author'], FILTER_VALIDATE_EMAIL)
            || $authorIsClient
        ) {
            $p['doc_author'] = $p['project_manager'] ?: $ownerName;
        }
        if (empty($p['lead_engineer'])) {
            $p['lead_engineer'] = ($prog['lead_engineer_name'] ?? '')
                ?: ($rd['project']['lead_engineer']   ?? '')
                ?: ($rams->form_data['lead_engineer'] ?? '');
        }
        if (empty($p['additional_engineers'])) {
            $addEngs = $prog['additional_engineers'] ?? [];
            $p['additional_engineers'] = (is_array($addEngs) && count($addEngs) > 0)
                ? implode(', ', array_filter(array_map('trim', $addEngs)))
                : ($rams->form_data['additional_engineers'] ?? '');
        }
        if (empty($p['project_manager_phone'])) {
            $p['project_manager_phone'] = $prog['project_manager_phone'] ?? '';
        }
        if (empty($p['programmer'])) {
            $p['programmer'] = ($prog['programmer_name'] ?? '')
                ?: ($rd['project']['programmer'] ?? '')
                ?: ($rams->form_data['programmer'] ?? '');
        }

        // 4. Client contact — checked in priority order:
        //    a) generated_data['project']['site_contact']  (saved by updateAndDownload form field)
        //    b) reviewed_data['site_logistics'] sub-keys   (normaliseSiteLogistics keys: contact_name/phone/email)
        //    c) package extracted_data client contact fields
        $sl = $rd['site_logistics'] ?? [];
        if (empty($p['client_contact_name'])) {
            $p['client_contact_name'] = ($p['site_contact']   ?? '')   // form field "Site Contact"
                ?: ($sl['contact_name']  ?? '');
        }
        if (empty($p['client_contact_email'])) {
            $p['client_contact_email'] = $sl['contact_email'] ?? '';
        }
        if (empty($p['client_contact_phone'])) {
            $p['client_contact_phone'] = $sl['contact_phone'] ?? '';
        }

        // 260726-fx5: reverse-mirror to the form's `site_contact` field so the
        // RAMS review form pre-fills instead of forcing PMs to retype the
        // client contact on every new RAMS. Blank pre-fix because the resolved
        // value landed in `client_contact_name` but the form reads `site_contact`.
        if (empty($p['site_contact'])) {
            $p['site_contact'] = $p['client_contact_name'] ?? '';
        }

        // 5. Planned dates and times from reviewed_data['programme'].
        foreach (['planned_start_date', 'planned_end_date', 'planned_start_time', 'planned_end_time'] as $f) {
            if (empty($p[$f])) {
                $p[$f] = $prog[$f] ?? '';
            }
        }

        // 260726-fx5: programme → project mirror for the form's Site Vehicles
        // field. Programme stores an array; the form field is a textarea, so
        // join with newlines. Falls through to '' when neither has data.
        if (empty($p['site_vehicles'])) {
            $vehicles = $prog['site_vehicles'] ?? '';
            $p['site_vehicles'] = is_array($vehicles)
                ? implode("\n", array_filter(array_map('trim', $vehicles)))
                : (string) $vehicles;
        }

        // 260726-fx5: auto-derive subtitle from client + first line of site
        // address when the PM hasn't set one. Skips derivation if both pieces
        // are missing (avoids a naked " | AV Installation" subtitle).
        if (empty($p['subtitle'])) {
            $siteFirstLine = trim((string) strtok((string) ($p['site_address'] ?? ''), "\r\n"));
            $client        = trim((string) ($p['client'] ?? ''));
            $parts         = array_values(array_filter([$siteFirstLine, $client, 'AV Installation']));
            if (count($parts) > 1) {
                $p['subtitle'] = implode(' | ', $parts);
            }
        }

        $gd['project']        = $p;

        // 5b. Patch scope_items and rooms_text from the project's latest package when empty.
        //     This fixes RAMS created before quote data was available, or via buildFromForm.
        $pkg = $liveProject?->latestPackage ?? null;

        if ($pkg) {
            // Rooms — patch rooms_text from package extracted rooms list when blank.
            // Filter out financial/pricing lines and service-category lines that the
            // parser sometimes misidentifies as rooms (e.g. "Professional Services").
            if (empty($p['rooms_text'])) {
                $pkgRooms = array_filter(
                    $pkg->extracted_data['rooms'] ?? [],
                    function ($r) {
                        if (! $r || strlen($r) >= 80) {
                            return false;
                        }
                        // Financial / pricing lines
                        if (preg_match('/discount|credit|vat|total|labour|delivery|carriage|price|\bfoc\b/i', $r)) {
                            return false;
                        }
                        // Lines starting with a currency symbol or digit
                        if (preg_match('/^\s*[\d£$€]/', $r)) {
                            return false;
                        }
                        // Service-category lines (e.g. "Professional Services", "Support Services")
                        // Exclude if the string ends with "Services" or "Service" and contains no
                        // room-like keyword (meeting, room, office, suite, lab, studio, etc.)
                        if (preg_match('/\bservices?\b/i', $r)
                            && ! preg_match('/\b(room|office|suite|meeting|conference|board|reception|lobby|studio|lab|kitchen|breakout|training|hall|space|area)\b/i', $r)
                        ) {
                            return false;
                        }
                        return true;
                    }
                );
                if (count($pkgRooms) > 0) {
                    $p['rooms_text'] = implode(', ', array_values($pkgRooms));
                }
            }

            // ── Scope items ────────────────────────────────────────────────────────
            // Hardware filter — applied both when building from package AND when
            // filtering items already in generated_data (post-regen copies may still
            // contain warranties/cables).
            $isHardware = fn (string $nameStr, string $itemType = '', string $category = '')
                => $this->classifier->isHardware($nameStr, $itemType, $category);

            // Always rebuild new_install from package — this is transient display only,
            // so using the package as the canonical source ensures room/area data is
            // always current. Decommission and retained are preserved from existing data.
            $pkgExtracted = $pkg->extracted_data ?? [];
            // Prefer extracted_data['equipment'] — this is the user-reviewed list
            // which includes the area/room assignments entered in the package review.
            // hardware_list is built by ExtractQuoteJob from raw AI output and lacks
            // room data. Fall back to hardware_list → equipment_list for older packages.
            $rawEquip = ! empty($pkgExtracted['equipment'])
                ? $pkgExtracted['equipment']
                : (! empty($pkgExtracted['hardware_list'])
                    ? $pkgExtracted['hardware_list']
                    : ($pkg->equipment_list ?? []));

            if (is_array($rawEquip) && count($rawEquip) > 0) {
                $newInstall   = [];
                $decommission = [];
                $retained     = [];
                foreach ($rawEquip as $e) {
                    if (! is_array($e)) {
                        continue;
                    }
                    $name    = $e['description'] ?? ($e['item_name'] ?? ($e['name'] ?? ($e['model'] ?? ($e['item'] ?? ''))));
                    $qty     = $e['qty']         ?? ($e['quantity']  ?? '');
                    $room    = $e['location']    ?? ($e['room']      ?? ($e['area'] ?? ''));
                    $notes   = $e['notes'] ?? '';
                    $nameStr = trim((string) $name);
                    $iType   = $e['item_type'] ?? '';
                    $cat     = strtolower((string) ($e['category'] ?? ''));

                    if (! $isHardware($nameStr, $iType, $cat)) {
                        continue;
                    }

                    // 260726-rf2: extract parenthesised room suffix — QW quotes
                    // often end scheduling-panel lines with "(Vanilla)" / "(Poppy)"
                    // etc. where the parens name the room the panel belongs to.
                    // Only run when room is empty (don't override an explicit area).
                    $roomStr = trim((string) $room);
                    if ($roomStr === '' && preg_match('/\s*\(([^()]{2,60})\)\s*$/', $nameStr, $m)) {
                        $extractedRoom = trim($m[1]);
                        // Sanity guard: room-ish names only. Reject if it looks like
                        // a spec suffix (contains digits+units, colons, or product tokens).
                        if (! preg_match('/\d|:|kg|mm|hz|watt|w\b|v\b|amp|version|rev\b/i', $extractedRoom)) {
                            $roomStr = strtoupper($extractedRoom);
                            $nameStr = trim(preg_replace('/\s*\([^()]{2,60}\)\s*$/', '', $nameStr));
                        }
                    }

                    // 260726-rf2: segregate decommission + retained items from
                    // the "NEW INSTALLATION" bucket. Pre-fix every "Existing X —
                    // deinstall and return to client" row landed under NEW which
                    // was factually wrong on the RAMS document.
                    $nameLower  = strtolower($nameStr);
                    $notesLower = strtolower(trim((string) $notes));
                    $haystack   = $nameLower . ' ' . $notesLower;

                    $mapped = ['item_name' => $nameStr, 'qty' => $qty, 'room' => $roomStr, 'notes' => $notes];

                    // "to be retained" wins over "deinstall" — retain-for-reuse
                    // is an explicit signal that overrides the "Existing " prefix
                    // shared by both classes.
                    if (str_contains($haystack, 'retained') || str_contains($haystack, 'to be retained')) {
                        $retained[] = $mapped;
                    } elseif (
                        str_starts_with($nameLower, 'existing ')
                        || str_contains($haystack, 'deinstall')
                        || str_contains($haystack, 'de-install')
                        || str_contains($haystack, 'return to client')
                        || str_contains($haystack, 'removal')
                    ) {
                        $decommission[] = $mapped;
                    } else {
                        $newInstall[] = $mapped;
                    }
                }
                $totalMapped = count($newInstall) + count($decommission) + count($retained);
                if ($totalMapped > 0) {
                    $gd['scope_items']['new_install']  = $newInstall;
                    $gd['scope_items']['decommission'] = ! empty($decommission)
                        ? $decommission
                        : ($gd['scope_items']['decommission'] ?? []);
                    $gd['scope_items']['retained']     = ! empty($retained)
                        ? $retained
                        : ($gd['scope_items']['retained']     ?? []);
                }
            } elseif (empty($gd['scope_items']['new_install'])) {
                $gd['scope_items']['decommission'] = $gd['scope_items']['decommission'] ?? [];
                $gd['scope_items']['retained']     = $gd['scope_items']['retained']     ?? [];
            }

            // Always filter new_install for hardware-only — this catches items that
            // were already in generated_data (e.g. copied during regen) and may include
            // warranties, cables, services, or generic placeholder rows.
            if (! empty($gd['scope_items']['new_install'])) {
                $gd['scope_items']['new_install'] = array_values(array_filter(
                    $gd['scope_items']['new_install'],
                    function ($item) use ($isHardware) {
                        if (! is_array($item)) {
                            return false;
                        }
                        $nameStr = trim((string) ($item['item_name'] ?? ($item['name'] ?? ($item['description'] ?? ''))));
                        return $isHardware($nameStr);
                    }
                ));

                // Normalize room field — clear any value that is a substring of the item
                // name (or vice-versa), which indicates stale data where a description
                // fragment was incorrectly stored as the room.
                $gd['scope_items']['new_install'] = array_map(function ($item) {
                    $room = trim((string) ($item['room'] ?? ''));
                    $name = trim((string) ($item['item_name'] ?? ''));
                    if ($room !== '' && $name !== '') {
                        $roomLower = strtolower($room);
                        $nameLower = strtolower($name);
                        if (str_contains($nameLower, $roomLower) || str_contains($roomLower, $nameLower)) {
                            $item['room'] = '';
                        }
                    }
                    return $item;
                }, $gd['scope_items']['new_install']);
            }
            // ─────────────────────────────────────────────────────────────────────────

            // Client contact from package extracted_data when still blank.
            if (empty($p['client_contact_name'])) {
                $p['client_contact_name'] = $pkg->extracted_data['client_contact_name'] ?? '';
            }
            if (empty($p['client_contact_email'])) {
                $p['client_contact_email'] = $pkg->extracted_data['client_contact_email'] ?? '';
            }
            if (empty($p['client_contact_phone'])) {
                $p['client_contact_phone'] = $pkg->extracted_data['client_contact_phone'] ?? '';
            }
        }

        $gd['project']        = $p;
        $rams->generated_data = $gd; // transient

        // 6. Pre-fill reviewed_data sub-keys with defaults when not yet saved.
        if (empty($rd['scope_traceability'])) {
            $lineItems = $gd['quote']['line_items'] ?? [];
            if (is_array($lineItems) && count($lineItems) > 0) {
                $rd['scope_traceability'] = array_values(array_map(fn ($li) => [
                    'quote_item'    => ($li['description'] ?? ''),
                    'rams_activity' => '',
                    'room'          => ($li['room'] ?? ''),
                    'notes'         => '',
                ], $lineItems));
            }
        }
        if (! isset($rd['exclusions'])) {
            $rd['exclusions'] = [
                'No structural works',
                'No core drilling unless explicitly scoped',
                'No containment beyond surface trunking',
                'No decorative making good after cable routes',
                'No IT network provision unless scoped',
            ];
        }
        $rd['client_responsibilities_expanded'] = $rd['client_responsibilities_expanded'] ?? [];
        $rd['decommissioning']                  = $rd['decommissioning']                  ?? [];
        $rd['commissioning_criteria']           = $rd['commissioning_criteria']           ?? [];

        // 260726-fx5: prior-RAMS auto-carry. Site emergency (nearest hospital,
        // fire wardens, first aiders, defibrillator, isolation switch, etc.)
        // and CDM duty-holder rows are per-project constants that PMs currently
        // retype every RAMS revision. Seed empty blocks from the most recent
        // completed RAMS on the same project so revisions only need edits, not
        // re-entry. Non-destructive — only fills blanks; transient (no save).
        //
        // Order by `updated_at` — `generated_at` lives inside the JSON
        // generated_data column, not as a DB column, so it can't be an ORDER BY
        // target. A completed RAMS's updated_at is the write that flipped it to
        // status=completed, which is a fair completion-time proxy.
        if ($rams->project_id && (empty($rd['site_emergency']) || empty($rd['cdm']))) {
            $prior = RamsDocument::query()
                ->where('project_id', $rams->project_id)
                ->where('id', '!=', $rams->id)
                ->where('status', RamsDocument::STATUS_COMPLETED)
                ->orderByDesc('updated_at')
                ->first();
            if ($prior) {
                $priorRd = $prior->reviewed_data ?? [];
                if (empty($rd['site_emergency']) && ! empty($priorRd['site_emergency'])) {
                    $rd['site_emergency'] = $priorRd['site_emergency'];
                }
                if (empty($rd['cdm']) && ! empty($priorRd['cdm'])) {
                    $rd['cdm'] = $priorRd['cdm'];
                }
            }
        }

        $rams->reviewed_data = $rd; // transient
    }
}
