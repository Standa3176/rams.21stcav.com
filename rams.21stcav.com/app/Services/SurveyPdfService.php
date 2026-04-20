<?php

namespace App\Services;

use App\Models\SiteSurvey;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;

class SurveyPdfService
{
    private const TEAL     = '#007B8A';
    private const MID_GREY = '#666666';

    private const BLANK_LINE = '<span style="color:#bbb;">____________________________</span>';

    /**
     * Build a PDF summary of a completed site survey and return its absolute path.
     */
    public function buildSummary(SiteSurvey $survey): string
    {
        $survey->loadMissing('rooms.photos');

        $html = $this->renderSummaryHtml($survey);

        $pdf = $this->makeDompdf();
        $pdf->loadHtml($html);
        $pdf->render();

        $filename     = 'site_survey_' . $survey->id . '_' . now()->format('Ymd_His') . '.pdf';
        $storagePath  = 'site-surveys/' . $filename;
        $absolutePath = Storage::disk('local')->path($storagePath);

        $dir = dirname($absolutePath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($absolutePath, $pdf->output());

        $survey->update(['filename' => $filename]);

        return $absolutePath;
    }

    /**
     * Build a blank printable site survey form PDF and return its absolute path.
     */
    public function buildBlank(): string
    {
        $html = $this->renderBlankHtml();

        $pdf = $this->makeDompdf();
        $pdf->loadHtml($html);
        $pdf->render();

        $absolutePath = Storage::disk('local')->path('site-surveys/blank-survey-form.pdf');

        $dir = dirname($absolutePath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($absolutePath, $pdf->output());

        return $absolutePath;
    }

    /**
     * Build an in-memory printable Field Survey Form PDF pre-populated with
     * project/client/site header, planned works + planned quote kit, and a
     * per-room section with blank manual-fill areas for power / network /
     * access / notes / sign-off.
     *
     * Returns the raw PDF bytes — no disk write, no DB mutation. Used by the
     * public /survey/{token}/download-form endpoint so engineers can complete
     * the survey by hand on-site when the mobile wizard isn't viable.
     */
    public function buildFieldFormContents(SiteSurvey $survey): string
    {
        $survey->loadMissing(['rooms', 'project.latestPackage']);

        $pdf = $this->makeDompdf();
        $pdf->loadHtml($this->renderFieldFormHtml($survey));
        $pdf->render();

        return (string) $pdf->output();
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function makeDompdf(): Dompdf
    {
        $options = new Options();
        // DejaVu Sans ships with dompdf and supports ☐ ☑ ✓ unicode symbols.
        // Default Helvetica lacks those glyphs and renders them as '?'.
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);

        $pdf = new Dompdf($options);
        $pdf->setPaper('A4', 'portrait');

        return $pdf;
    }

    private function css(): string
    {
        return '
        <style>
            body  { font-family: "DejaVu Sans", Helvetica, Arial, sans-serif; font-size: 9pt; color: #222; margin: 0; padding: 0; }
            h1    { color: ' . self::TEAL . '; font-size: 16pt; margin-bottom: 2pt; }
            h2    { color: ' . self::TEAL . '; font-size: 11pt; border-bottom: 1.5pt solid ' . self::TEAL . '; padding-bottom: 3pt; margin-top: 14pt; margin-bottom: 6pt; }
            h3    { font-size: 9.5pt; color: #333; margin: 8pt 0 3pt; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 8pt; font-size: 8.5pt; }
            th    { background: ' . self::TEAL . '; color: #fff; padding: 4pt 6pt; text-align: left; }
            td    { padding: 4pt 6pt; border: 0.5pt solid #ccc; vertical-align: top; }
            tr:nth-child(even) td { background: #f0fbfc; }
            .label     { font-weight: bold; width: 30%; background: #f5f5f5; }
            .label-sm  { font-weight: bold; width: 22%; background: #f5f5f5; }
            .meta      { font-size: 8pt; color: ' . self::MID_GREY . '; margin-bottom: 10pt; }
            .page-break { page-break-before: always; }
            .field-box   { border: 0.5pt solid #bbb; min-height: 30pt; padding: 4pt; margin-bottom: 6pt; font-size: 8pt; color: #888; }
            .sketch-box  { border: 0.5pt solid #888; height: 140pt; padding: 4pt; margin-bottom: 8pt; font-size: 7.5pt; color: #999;
                           background-image: linear-gradient(#eee 1px, transparent 1px), linear-gradient(90deg, #eee 1px, transparent 1px);
                           background-size: 12pt 12pt; }
            .tick-list   { margin: 0; padding-left: 14pt; line-height: 1.35; }
            .tick-list li{ margin-bottom: 2pt; }
            .checkbox    { font-family: "DejaVu Sans"; }
            .inline-field{ display: inline-block; border-bottom: 0.5pt solid #999; min-width: 70pt; padding: 0 4pt; color: #333; }
            .footer      { position: fixed; bottom: 0; left: 0; right: 0; text-align: center; font-size: 7pt; color: ' . self::MID_GREY . '; border-top: 0.5pt solid #ddd; padding-top: 3pt; }
            .page-num    { position: fixed; bottom: 4pt; right: 10pt; font-size: 7pt; color: ' . self::MID_GREY . '; }
            .badge-yes   { color: #155724; font-weight: bold; }
            .badge-no    { color: #721c24; }
        </style>';
    }

    /** Dompdf script block for "Page X of Y" in the bottom-right corner. */
    private function pageNumberScript(): string
    {
        return '<script type="text/php">
            if (isset($pdf)) {
                $font = $fontMetrics->getFont("DejaVu Sans", "normal");
                $size = 7;
                $text = "Page {PAGE_NUM} of {PAGE_COUNT}";
                $pdf->page_text(520, 820, $text, $font, $size, array(0.4, 0.4, 0.4));
            }
        </script>';
    }

    private function yn(bool $val): string
    {
        return $val
            ? '<span class="badge-yes">Yes</span>'
            : '<span class="badge-no">No</span>';
    }

    /** Inline "write-on" slot for the blank form. Uses underline not em-dash. */
    private function blank(?string $value = null): string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? e($value) : self::BLANK_LINE;
    }

    /**
     * Room headings come from imported data and sometimes have unbalanced
     * parens (e.g. "Conference room (23) - Secondary (Right"). Auto-close
     * trailing unbalanced brackets so the PDF reads cleanly.
     */
    private function balanceParens(string $title): string
    {
        $open  = substr_count($title, '(');
        $close = substr_count($title, ')');

        if ($open > $close) {
            $title .= str_repeat(')', $open - $close);
        }

        return $title;
    }

    /**
     * Dedupe a narrative's first line when it repeats the room name — a data
     * quality issue in some imports that causes the PDF to print the heading
     * twice.
     */
    private function stripLeadingDuplicate(string $narrative, string $roomName): string
    {
        if ($narrative === '' || $roomName === '') {
            return $narrative;
        }

        $lines    = preg_split("/\r\n|\n|\r/", $narrative) ?: [];
        $target   = strtolower(trim($roomName));

        while (! empty($lines) && strtolower(trim($lines[0])) === $target) {
            array_shift($lines);
        }

        return implode("\n", $lines);
    }

    /** Render multi-line narrative as tick-list bullets for on-site verification. */
    private function narrativeAsTickList(string $narrative): string
    {
        $lines = array_values(array_filter(array_map('trim', preg_split("/\r\n|\n|\r/", $narrative) ?: [])));

        if (empty($lines)) {
            return '';
        }

        // Single line → plain paragraph (bullets would add visual noise).
        if (count($lines) === 1) {
            return '<p style="margin:0;">' . e($lines[0]) . '</p>';
        }

        $html = '<ul class="tick-list">';
        foreach ($lines as $line) {
            $html .= '<li><span class="checkbox">☐</span> ' . e($line) . '</li>';
        }
        $html .= '</ul>';

        return $html;
    }

    private function renderSummaryHtml(SiteSurvey $survey): string
    {
        $dateStr = $survey->survey_date ? $survey->survey_date->format('d/m/Y') : '—';

        $html = $this->css() . '
        <div class="footer">21st Century AV Ltd — Site Survey | ' . e($survey->project_name) . ' | Generated ' . now()->format('d/m/Y') . '</div>
        <h1>Site Survey Report</h1>
        <p class="meta">21st Century AV Ltd</p>

        <h2>Project Details</h2>
        <table>
            <tr><td class="label">Project Name</td><td>' . e($survey->project_name) . '</td></tr>
            <tr><td class="label">Project Ref</td><td>' . e($survey->project_ref ?? '—') . '</td></tr>
            <tr><td class="label">Client</td><td>' . e($survey->client_name ?? '—') . '</td></tr>
            <tr><td class="label">Site Address</td><td>' . e($survey->site_address ?? '—') . '</td></tr>
            <tr><td class="label">Surveyor</td><td>' . e($survey->surveyor_name ?? '—') . '</td></tr>
            <tr><td class="label">Survey Date</td><td>' . $dateStr . '</td></tr>
        </table>';

        if ($survey->general_notes) {
            $html .= '
        <h2>General Notes</h2>
        <p>' . nl2br(e($survey->general_notes)) . '</p>';
        }

        foreach ($survey->rooms as $room) {
            $title = 'Room: ' . $this->balanceParens((string) $room->room_name)
                   . ($room->floor ? ' (Floor: ' . e($room->floor) . ')' : '');

            $html .= '
        <h2>' . e($title) . '</h2>
        <table>
            <tr><td class="label">Room Ref</td><td>' . e($room->room_ref ?? '—') . '</td></tr>
            <tr><td class="label">Dimensions (W × D × H)</td><td>'
                . ($room->room_width_m  ? $room->room_width_m . 'm' : '—') . ' × '
                . ($room->room_depth_m  ? $room->room_depth_m . 'm' : '—') . ' × '
                . ($room->room_height_m ? $room->room_height_m . 'm' : '—') . '</td></tr>
            <tr><td class="label">Ceiling Type</td><td>' . e($room->ceiling_type ?? '—') . '</td></tr>
            <tr><td class="label">Ceiling Height</td><td>' . ($room->ceiling_height_m ? $room->ceiling_height_m . ' m' : '—') . '</td></tr>
            <tr><td class="label">Wall Material</td><td>' . e($room->wall_material ?? '—') . '</td></tr>
            <tr><td class="label">Floor Type</td><td>' . e($room->floor_type ?? '—') . '</td></tr>
            <tr><td class="label">Power Available</td><td>' . $this->yn((bool) $room->has_power) . '</td></tr>
            <tr><td class="label">Power Outlets</td><td>' . (int) $room->power_outlet_count . '</td></tr>
            <tr><td class="label">Additional Power Required</td><td>' . $this->yn((bool) $room->requires_additional_power) . '</td></tr>
            <tr><td class="label">Network Available</td><td>' . $this->yn((bool) $room->has_network) . '</td></tr>
            <tr><td class="label">Network Ports</td><td>' . (int) $room->network_port_count . '</td></tr>
            <tr><td class="label">Existing Cabling</td><td>' . e($room->existing_cabling ?? '—') . '</td></tr>
            <tr><td class="label">AV Requirements</td><td>' . nl2br(e($room->av_requirements ?? '—')) . '</td></tr>
            <tr><td class="label">Existing AV Equipment</td><td>' . nl2br(e($room->av_equipment_list ?? '—')) . '</td></tr>
            <tr><td class="label">Access / Hazard Notes</td><td>' . nl2br(e($room->access_notes ?? '—')) . '</td></tr>
            <tr><td class="label">Other Notes</td><td>' . nl2br(e($room->notes ?? '—')) . '</td></tr>
        </table>';

            if ($room->photos->isNotEmpty()) {
                $html .= '<h3>Photos (' . $room->photos->count() . ')</h3>';
                $html .= '<table><tr>';
                foreach ($room->photos as $photo) {
                    $path = Storage::disk('local')->path('survey-photos/' . $photo->filename);
                    if (file_exists($path)) {
                        $b64  = base64_encode(file_get_contents($path));
                        $mime = $photo->mime_type;
                        $html .= '<td style="width:33%;text-align:center;border:none;">'
                               . '<img src="data:' . $mime . ';base64,' . $b64 . '" style="max-width:100%;max-height:120pt;"/>'
                               . ($photo->caption ? '<br><small>' . e($photo->caption) . '</small>' : '')
                               . '</td>';
                    }
                }
                $html .= '</tr></table>';
            }
        }

        return $html;
    }

    private function renderBlankHtml(): string
    {
        $html = $this->css() . $this->pageNumberScript();
        $html .= '<div class="footer">21st Century AV Ltd — Site Survey Form | Confidential</div>';
        $html .= '<h1>Site Survey Form</h1>';
        $html .= '<p class="meta">21st Century AV Ltd — Complete one form per site visit. Return to office for processing.</p>';

        $html .= $this->renderHeaderMetaBlock(null);
        $html .= '<h2>General Notes</h2><div class="field-box">Write general site observations here…</div>';

        for ($i = 1; $i <= 4; $i++) {
            $html .= '<h2>Room / Area ' . $i . '</h2>';
            $html .= $this->renderBlankRoomBody();
            if ($i < 4) {
                $html .= '<hr style="border: none; border-top: 0.5pt dashed #ccc; margin: 8pt 0;">';
            }
        }

        $html .= '<div class="page-break"></div>' . $this->renderSignOffBlock();

        return $html;
    }

    /**
     * Field Survey Form — header + planned works + planned kit pre-populated
     * from the survey/project/package data. Each room gets a blank manual-fill
     * section covering the same ground as the wizard (power/network/access/
     * notes/sign-off) so engineers can complete on paper when offline.
     */
    private function renderFieldFormHtml(SiteSurvey $survey): string
    {
        $package = $survey->project?->latestPackage;

        $html  = $this->css();
        $html .= $this->pageNumberScript();
        $html .= '<div class="footer">21st Century AV Ltd — Field Survey Form | '
              . e($survey->project_name) . ' | Generated ' . now()->format('d/m/Y') . '</div>';
        $html .= '<h1>Field Survey Form</h1>';
        $html .= '<p class="meta">Complete by hand on-site. Return to office for processing into the digital survey.</p>';

        $html .= $this->renderHeaderMetaBlock($survey);

        // ── Planned AV works summary (project-level context) ─────────────────
        $worksDescription = $package->works_description ?? null;
        if (is_string($worksDescription) && trim($worksDescription) !== '') {
            $html .= '<h2>Planned AV Works — Project Overview</h2>';
            $html .= '<p style="font-size:8.5pt;">' . nl2br(e($worksDescription)) . '</p>';
        }

        // Consolidated Quote Kit dump removed — per-room kit below is what the
        // surveyor actually needs on-site. The project-level kit can be derived
        // from the digital package when processing the returned form.

        // ── Per-room manual-fill sections ────────────────────────────────────
        $rooms = $survey->rooms;
        if ($rooms->isEmpty()) {
            $html .= '<h2>Rooms</h2><p class="meta">No rooms pre-populated. Use the blank section below.</p>';
            $html .= $this->renderBlankRoomSection('Room / Area 1');
        } else {
            foreach ($rooms as $room) {
                $title = 'Room: ' . $this->balanceParens((string) ($room->room_name ?: 'Unnamed'))
                       . ($room->floor ? ' (Floor: ' . $room->floor . ')' : '');
                $html .= $this->renderFieldRoomSection($room, $title);
            }
        }

        // ── Sign-off page ────────────────────────────────────────────────────
        $html .= '<div class="page-break"></div>';
        $html .= $this->renderSignOffBlock();

        return $html;
    }

    /**
     * Top-of-form metadata: project + site + surveyor + access + PPE + rooms
     * expected. When a SiteSurvey is provided, known values pre-populate;
     * otherwise everything renders as a blank-line slot.
     */
    private function renderHeaderMetaBlock(?SiteSurvey $survey): string
    {
        $dateStr = $survey?->survey_date?->format('d/m/Y');

        $projectName = $survey?->project_name;
        $projectRef  = $survey?->project_ref;
        $client      = $survey?->client_name;
        $siteAddress = $survey?->site_address;
        $surveyor    = $survey?->surveyor_name;

        $contactName  = $survey?->site_contact_name;
        $contactPhone = $survey?->site_contact_phone;
        $siteContact  = trim(($contactName ?? '') . ($contactPhone ? ' (' . $contactPhone . ')' : ''));

        $html = '<h2>Project &amp; Site</h2><table>';
        $html .= '<tr><td class="label">Project Name</td><td>' . $this->blank($projectName) . '</td></tr>';
        $html .= '<tr><td class="label">Project Ref</td><td>' . $this->blank($projectRef) . '</td></tr>';
        $html .= '<tr><td class="label">Client</td><td>' . $this->blank($client) . '</td></tr>';
        $html .= '<tr><td class="label">Site Address</td><td>' . $this->blank($siteAddress) . '</td></tr>';
        $html .= '<tr><td class="label">Surveyor</td><td>' . $this->blank($surveyor) . '</td></tr>';
        $html .= '<tr><td class="label">Survey Date</td><td>' . $this->blank($dateStr) . '</td></tr>';
        $html .= '<tr><td class="label">Site Contact</td><td>' . $this->blank($siteContact) . '</td></tr>';
        $html .= '</table>';

        $html .= '<h2>Site Access &amp; Safety</h2><table>';
        $html .= '<tr><td class="label">On-site arrival time</td><td>' . self::BLANK_LINE . '</td>'
              . '<td class="label">Expected leave time</td><td>' . self::BLANK_LINE . '</td></tr>';
        $html .= '<tr><td class="label">Key holder / escort</td><td colspan="3">' . self::BLANK_LINE . '</td></tr>';
        $html .= '<tr><td class="label">Parking arrangement</td><td colspan="3">' . self::BLANK_LINE . '</td></tr>';
        $html .= '<tr><td class="label">Emergency contact</td><td colspan="3">' . self::BLANK_LINE . '</td></tr>';
        $html .= '<tr><td class="label">PPE required</td><td colspan="3">'
              . '☐ Hi-vis &nbsp; ☐ Hard hat &nbsp; ☐ Safety boots &nbsp; ☐ Safety glasses &nbsp; ☐ Sign-in at reception &nbsp; ☐ Other: ' . self::BLANK_LINE
              . '</td></tr>';
        $html .= '<tr><td class="label">Rooms expected</td><td>' . self::BLANK_LINE . ' of ' . self::BLANK_LINE . '</td>'
              . '<td class="label">Weather / conditions</td><td>' . self::BLANK_LINE . '</td></tr>';
        $html .= '</table>';

        return $html;
    }

    /**
     * Per-room block that surfaces planned AV works + quote kit for this
     * specific room (from SiteSurveyRoom) before the blank manual-fill grid,
     * so engineers see the scope context when completing on paper.
     */
    private function renderFieldRoomSection(\App\Models\SiteSurveyRoom $room, string $title): string
    {
        $html = '<h2>' . e($title) . '</h2>';

        $roomName       = (string) ($room->room_name ?? '');
        $avRequirements = trim((string) ($room->av_requirements ?? ''));
        $avEquipment    = trim((string) ($room->av_equipment_list ?? ''));

        // Strip leading duplicate of room name from narrative (data-quality fix).
        $avRequirements = trim($this->stripLeadingDuplicate($avRequirements, $roomName));

        if ($avRequirements !== '' || $avEquipment !== '') {
            $html .= '<table>';
            if ($avRequirements !== '') {
                $html .= '<tr><td class="label">Planned AV Works<br><span style="font-weight:normal;font-size:7.5pt;color:#888;">Tick each item as confirmed on-site</span></td>'
                       . '<td>' . $this->narrativeAsTickList($avRequirements) . '</td></tr>';
            }
            if ($avEquipment !== '') {
                $html .= '<tr><td class="label">Quote Kit</td><td>' . nl2br(e($avEquipment)) . '</td></tr>';
            }
            $html .= '</table>';
        }

        return $html . $this->renderBlankRoomBody();
    }

    /** One per-room block with blank manual-fill areas (power/network/access/notes). */
    private function renderBlankRoomSection(string $title): string
    {
        return '<h2>' . e($title) . '</h2>' . $this->renderBlankRoomBody();
    }

    /**
     * The blank-fill grid shared by the fallback (no DB rooms) and per-room
     * sections. Structured as labeled rows so every measurement / Y/N / free
     * text has its own writable slot — no cramming multiple fields per cell.
     */
    private function renderBlankRoomBody(): string
    {
        $line = self::BLANK_LINE;

        return '
        <table>
            <tr>
                <td class="label-sm">Room type / use</td><td colspan="3">' . $line . '</td>
            </tr>
            <tr>
                <td class="label-sm">Seating capacity</td><td>' . $line . '</td>
                <td class="label-sm">Viewing distance (m)</td><td>' . $line . '</td>
            </tr>
            <tr>
                <td class="label-sm">Width (m)</td><td>' . $line . '</td>
                <td class="label-sm">Depth (m)</td><td>' . $line . '</td>
            </tr>
            <tr>
                <td class="label-sm">Ceiling height (m)</td><td>' . $line . '</td>
                <td class="label-sm">Ceiling type</td>
                <td>☐ Concrete &nbsp; ☐ Suspended &nbsp; ☐ Plasterboard &nbsp; ☐ Open &nbsp; ☐ Other: ' . $line . '</td>
            </tr>
            <tr>
                <td class="label-sm">Wall construction</td>
                <td colspan="3">☐ Masonry &nbsp; ☐ Stud/Plasterboard &nbsp; ☐ Timber frame &nbsp; ☐ Glass partition &nbsp; ☐ Other: ' . $line . '</td>
            </tr>
            <tr>
                <td class="label-sm">Floor type</td>
                <td colspan="3">☐ Concrete &nbsp; ☐ Carpet &nbsp; ☐ Tiles &nbsp; ☐ Raised access &nbsp; ☐ Other: ' . $line . '</td>
            </tr>
            <tr>
                <td class="label-sm">Display mount height</td>
                <td>Centre from floor (mm): ' . $line . '</td>
                <td class="label-sm">Distance to furthest seat (m)</td><td>' . $line . '</td>
            </tr>
            <tr>
                <td class="label-sm">Power available</td><td>☐ Yes &nbsp; ☐ No</td>
                <td class="label-sm">Outlets at equipment wall</td><td>' . $line . '</td>
            </tr>
            <tr>
                <td class="label-sm">Nearest socket to display (m)</td><td>' . $line . '</td>
                <td class="label-sm">New socket required?</td><td>☐ Yes &nbsp; ☐ No</td>
            </tr>
            <tr>
                <td class="label-sm">Spare breaker capacity</td><td>☐ Yes &nbsp; ☐ No</td>
                <td class="label-sm">UPS / clean supply present?</td><td>☐ Yes &nbsp; ☐ No</td>
            </tr>
            <tr>
                <td class="label-sm">Network available</td><td>☐ Yes &nbsp; ☐ No</td>
                <td class="label-sm">Active ports</td><td>' . $line . '</td>
            </tr>
            <tr>
                <td class="label-sm">PoE switch present?</td><td>☐ Yes &nbsp; ☐ No</td>
                <td class="label-sm">Dedicated VLAN?</td><td>☐ Yes &nbsp; ☐ No</td>
            </tr>
            <tr>
                <td class="label-sm">Switch location</td><td colspan="3">' . $line . '</td>
            </tr>
            <tr>
                <td class="label-sm">Cable route</td>
                <td colspan="3">☐ Containment &nbsp; ☐ Floor boxes &nbsp; ☐ Ceiling void &nbsp; ☐ Surface trunking &nbsp; ☐ Underfloor void &nbsp; Est. run (m): ' . $line . '</td>
            </tr>
            <tr>
                <td class="label-sm">Access / hazards</td>
                <td colspan="3">☐ Working at height &nbsp; ☐ Out-of-hours &nbsp; ☐ Permits &nbsp; ☐ Manual handling &nbsp; ☐ Asbestos register checked &nbsp; ☐ Live working</td>
            </tr>
            <tr>
                <td class="label-sm">Existing AV to retain</td><td colspan="3">' . $line . '</td>
            </tr>
            <tr>
                <td class="label-sm">Existing AV to remove</td><td colspan="3">' . $line . '</td>
            </tr>
            <tr>
                <td class="label-sm">Photos captured (ref)</td><td colspan="3">' . $line . '</td>
            </tr>
            <tr>
                <td class="label-sm">Items not confirmed / blockers</td><td colspan="3">' . $line . '</td>
            </tr>
            <tr>
                <td class="label-sm">Notes</td><td colspan="3">' . $line . '</td>
            </tr>
        </table>
        <div style="font-size:7.5pt;color:#666;margin-bottom:2pt;">Sketch (mark doors, windows, power, display, rack — grid = 5mm):</div>
        <div class="sketch-box"></div>';
    }

    /**
     * Sign-off block — engineer/client signatures plus the "before you leave"
     * checklist so the surveyor catches gaps on-site rather than after travel.
     */
    private function renderSignOffBlock(): string
    {
        $line = self::BLANK_LINE;

        $html  = '<h2>Before you leave — checklist</h2>';
        $html .= '<table><tr><td style="font-size:8.5pt;">'
              . '☐ All rooms surveyed &nbsp; ☐ Every measurement captured &nbsp; ☐ Quote kit matches site (or deviations noted) &nbsp; ☐ Photos captured and labelled &nbsp; ☐ Access/hazard notes complete &nbsp; ☐ Client walk-through done &nbsp; ☐ Permits/PTWs returned'
              . '</td></tr></table>';

        $html .= '<h2>Deviations from planned scope</h2>';
        $html .= '<div class="field-box">Record any scope changes, missing items, or variations discovered on-site…</div>';

        $html .= '<h2>Next actions agreed with client</h2>';
        $html .= '<div class="field-box">Who is doing what by when…</div>';

        $html .= '<h2>Sign-off</h2><table>';
        $html .= '<tr><td class="label">Survey start time</td><td>' . $line . '</td>'
              . '<td class="label">Survey end time</td><td>' . $line . '</td></tr>';
        $html .= '<tr><td class="label">Accompanying personnel</td><td colspan="3">' . $line . '</td></tr>';
        $html .= '<tr><td class="label">Engineer name</td><td colspan="3">' . $line . '</td></tr>';
        $html .= '<tr><td class="label">Engineer signature</td><td colspan="3" style="height:40pt;"></td></tr>';
        $html .= '<tr><td class="label">Client name</td><td colspan="3">' . $line . '</td></tr>';
        $html .= '<tr><td class="label">Client signature</td><td colspan="3" style="height:40pt;"></td></tr>';
        $html .= '<tr><td class="label">Date</td><td>' . $line . '</td>'
              . '<td class="label">Form version</td><td>FSF-2.0 ' . now()->format('Y-m-d') . '</td></tr>';
        $html .= '</table>';

        return $html;
    }
}
