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
        $options->set('defaultFont', 'Helvetica');
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
            body  { font-family: Helvetica, Arial, sans-serif; font-size: 9pt; color: #222; margin: 0; padding: 0; }
            h1    { color: ' . self::TEAL . '; font-size: 16pt; margin-bottom: 2pt; }
            h2    { color: ' . self::TEAL . '; font-size: 11pt; border-bottom: 1.5pt solid ' . self::TEAL . '; padding-bottom: 3pt; margin-top: 14pt; margin-bottom: 6pt; }
            h3    { font-size: 9.5pt; color: #333; margin: 8pt 0 3pt; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 8pt; font-size: 8.5pt; }
            th    { background: ' . self::TEAL . '; color: #fff; padding: 4pt 6pt; text-align: left; }
            td    { padding: 4pt 6pt; border: 0.5pt solid #ccc; vertical-align: top; }
            tr:nth-child(even) td { background: #f0fbfc; }
            .label { font-weight: bold; width: 35%; background: #f5f5f5; }
            .meta  { font-size: 8pt; color: ' . self::MID_GREY . '; margin-bottom: 10pt; }
            .page-break { page-break-before: always; }
            .field-box { border: 0.5pt solid #bbb; min-height: 30pt; padding: 4pt; margin-bottom: 6pt; font-size: 8pt; color: #888; }
            .footer { position: fixed; bottom: 0; left: 0; right: 0; text-align: center; font-size: 7pt; color: ' . self::MID_GREY . '; border-top: 0.5pt solid #ddd; padding-top: 3pt; }
            .badge-yes { color: #155724; font-weight: bold; }
            .badge-no  { color: #721c24; }
        </style>';
    }

    private function yn(bool $val): string
    {
        return $val
            ? '<span class="badge-yes">Yes</span>'
            : '<span class="badge-no">No</span>';
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
            $html .= '
        <h2>Room: ' . e($room->room_name) . ($room->floor ? ' (Floor: ' . e($room->floor) . ')' : '') . '</h2>
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
        $html = $this->css() . '
        <div class="footer">21st Century AV Ltd — Site Survey Form | Confidential</div>
        <h1>Site Survey Form</h1>
        <p class="meta">21st Century AV Ltd — Complete one form per site visit. Return to office for processing.</p>

        <h2>Project Details</h2>
        <table>
            <tr><td class="label">Project Name</td><td></td></tr>
            <tr><td class="label">Project Ref</td><td></td></tr>
            <tr><td class="label">Client</td><td></td></tr>
            <tr><td class="label">Site Address</td><td></td></tr>
            <tr><td class="label">Surveyor Name</td><td></td></tr>
            <tr><td class="label">Survey Date</td><td></td></tr>
        </table>

        <h2>General Notes</h2>
        <div class="field-box">Write general site observations here...</div>';

        for ($i = 1; $i <= 4; $i++) {
            $html .= '
        <h2>Room / Area ' . $i . '</h2>
        <table>
            <tr><td class="label">Room Name</td><td></td><td class="label">Floor</td><td></td></tr>
            <tr><td class="label">Room Ref</td><td></td><td class="label">W &times; D &times; H (m)</td><td>&nbsp;&nbsp;&times;&nbsp;&nbsp;&times;&nbsp;&nbsp;</td></tr>
            <tr><td class="label">Ceiling Type</td>
                <td>&#9744; Concrete &nbsp; &#9744; Suspended &nbsp; &#9744; Plasterboard &nbsp; &#9744; Open &nbsp; &#9744; Other: ________</td>
                <td class="label">Ceiling Height (m)</td><td></td></tr>
            <tr><td class="label">Wall Material</td><td>&#9744; Brick &nbsp; &#9744; Plasterboard &nbsp; &#9744; Glass &nbsp; &#9744; Concrete &nbsp; &#9744; Other: ________</td>
                <td class="label">Floor Type</td><td>&#9744; Concrete &nbsp; &#9744; Carpet &nbsp; &#9744; Tiles &nbsp; &#9744; Raised &nbsp; &#9744; Other</td></tr>
            <tr><td class="label">Power Available</td><td>&#9744; Yes &nbsp; &#9744; No &nbsp;&nbsp; Outlets: ____</td>
                <td class="label">Additional Power Required</td><td>&#9744; Yes &nbsp; &#9744; No</td></tr>
            <tr><td class="label">Network Available</td><td>&#9744; Yes &nbsp; &#9744; No &nbsp;&nbsp; Ports: ____</td>
                <td class="label">Existing Cabling</td><td></td></tr>
            <tr><td class="label">AV Requirements</td><td colspan="3"></td></tr>
            <tr><td class="label">Existing AV Equipment</td><td colspan="3"></td></tr>
            <tr><td class="label">Access / Hazard Notes</td><td colspan="3"></td></tr>
            <tr><td class="label">Other Notes</td><td colspan="3"></td></tr>
        </table>';

            if ($i < 4) {
                $html .= '<hr style="border: none; border-top: 0.5pt dashed #ccc; margin: 8pt 0;">';
            }
        }

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
        $dateStr = $survey->survey_date ? $survey->survey_date->format('d/m/Y') : '—';
        $package = $survey->project?->latestPackage;

        $html  = $this->css();
        $html .= '<div class="footer">21st Century AV Ltd — Field Survey Form | '
              . e($survey->project_name) . ' | Generated ' . now()->format('d/m/Y') . '</div>';
        $html .= '<h1>Field Survey Form</h1>';
        $html .= '<p class="meta">Complete by hand on-site. Return to office for processing into the digital survey.</p>';

        // ── Project / client / site header ───────────────────────────────────
        $html .= '<h2>Project &amp; Site</h2>
        <table>
            <tr><td class="label">Project Name</td><td>' . e($survey->project_name) . '</td></tr>
            <tr><td class="label">Project Ref</td><td>' . e($survey->project_ref ?? '—') . '</td></tr>
            <tr><td class="label">Client</td><td>' . e($survey->client_name ?? '—') . '</td></tr>
            <tr><td class="label">Site Address</td><td>' . e($survey->site_address ?? '—') . '</td></tr>
            <tr><td class="label">Surveyor</td><td>' . e($survey->surveyor_name ?? '—') . '</td></tr>
            <tr><td class="label">Survey Date</td><td>' . $dateStr . '</td></tr>
            <tr><td class="label">Site Contact</td><td>' . e(trim(($survey->site_contact_name ?? '') . ' ' . ($survey->site_contact_phone ? '(' . $survey->site_contact_phone . ')' : ''))) . '</td></tr>
        </table>';

        // ── Planned AV works summary (if available) ──────────────────────────
        $worksDescription = $package->works_description ?? null;
        if (is_string($worksDescription) && trim($worksDescription) !== '') {
            $html .= '<h2>Planned AV Works</h2>';
            $html .= '<p>' . nl2br(e($worksDescription)) . '</p>';
        }

        // ── Planned quote kit list (if available) ────────────────────────────
        $equipment = is_array($package?->equipment_list) ? $package->equipment_list : [];
        if (! empty($equipment)) {
            $html .= '<h2>Planned Quote Kit</h2>';
            $html .= '<table>
                <tr><th style="width:12%;">Qty</th><th>Item</th><th style="width:30%;">Manufacturer / Model</th></tr>';
            foreach ($equipment as $item) {
                $qty          = is_array($item) ? ($item['quantity'] ?? $item['qty'] ?? '') : '';
                $description  = is_array($item) ? (string) ($item['description'] ?? $item['name'] ?? $item['item'] ?? '') : (string) $item;
                $manufacturer = is_array($item) ? trim((string) ($item['manufacturer'] ?? '') . ' ' . (string) ($item['model'] ?? '')) : '';
                $html .= '<tr>'
                       . '<td>' . e((string) $qty) . '</td>'
                       . '<td>' . e($description) . '</td>'
                       . '<td>' . e($manufacturer) . '</td>'
                       . '</tr>';
            }
            $html .= '</table>';
        }

        // ── Per-room manual-fill sections ────────────────────────────────────
        $rooms = $survey->rooms;
        if ($rooms->isEmpty()) {
            $html .= '<h2>Rooms</h2><p class="meta">No rooms pre-populated. Use the blank section below.</p>';
            $html .= $this->renderBlankRoomSection('Room / Area 1');
        } else {
            foreach ($rooms as $room) {
                $title = 'Room: ' . ($room->room_name ?: 'Unnamed') . ($room->floor ? ' (Floor: ' . $room->floor . ')' : '');
                $html .= $this->renderFieldRoomSection($room, $title);
            }
        }

        // ── Sign-off page ────────────────────────────────────────────────────
        $html .= '<div class="page-break"></div>';
        $html .= '<h2>Sign-off</h2>
        <table>
            <tr><td class="label">Engineer Name</td><td></td></tr>
            <tr><td class="label">Engineer Signature</td><td></td></tr>
            <tr><td class="label">Client Name</td><td></td></tr>
            <tr><td class="label">Client Signature</td><td></td></tr>
            <tr><td class="label">Date</td><td></td></tr>
        </table>';

        return $html;
    }

    /**
     * Per-room block that surfaces planned AV works + quote kit for this
     * specific room (from SiteSurveyRoom) before the blank manual-fill grid,
     * so engineers see the scope context when completing on paper.
     */
    private function renderFieldRoomSection(\App\Models\SiteSurveyRoom $room, string $title): string
    {
        $html  = '<h2>' . e($title) . '</h2>';

        $avRequirements  = trim((string) ($room->av_requirements ?? ''));
        $avEquipmentList = trim((string) ($room->av_equipment_list ?? ''));

        if ($avRequirements !== '' || $avEquipmentList !== '') {
            $html .= '<table>';
            if ($avRequirements !== '') {
                $html .= '<tr><td class="label">Planned AV Works</td><td>' . nl2br(e($avRequirements)) . '</td></tr>';
            }
            if ($avEquipmentList !== '') {
                $html .= '<tr><td class="label">Quote Kit</td><td>' . nl2br(e($avEquipmentList)) . '</td></tr>';
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

    /** The blank-fill grid shared by the fallback (no DB rooms) and per-room sections. */
    private function renderBlankRoomBody(): string
    {
        return '
        <table>
            <tr><td class="label">Room Type</td><td colspan="3"></td></tr>
            <tr><td class="label">W &times; D &times; H (m)</td><td>&nbsp;&nbsp;&times;&nbsp;&nbsp;&times;&nbsp;&nbsp;</td>
                <td class="label">Ceiling Height (m)</td><td></td></tr>
            <tr><td class="label">Power Available</td>
                <td>&#9744; Yes &nbsp; &#9744; No &nbsp;&nbsp; Outlets: ____ &nbsp; Spare capacity: &#9744; Y &nbsp; &#9744; N</td>
                <td class="label">Distance to Screen (m)</td><td></td></tr>
            <tr><td class="label">Network Available</td>
                <td>&#9744; Yes &nbsp; &#9744; No &nbsp;&nbsp; Ports: ____ &nbsp; VLAN: &#9744; Y &nbsp; &#9744; N</td>
                <td class="label">Switch Location</td><td></td></tr>
            <tr><td class="label">Cable Route</td><td colspan="3">&#9744; Containment &nbsp; &#9744; Floor boxes &nbsp; &#9744; Ceiling void &nbsp; &#9744; Surface trunking &nbsp; Est. distance: ____ m</td></tr>
            <tr><td class="label">Access / Hazards</td><td colspan="3">&#9744; Working at height &nbsp; &#9744; Out-of-hours &nbsp; &#9744; Permits &nbsp; &#9744; Manual handling</td></tr>
            <tr><td class="label">Notes</td><td colspan="3"></td></tr>
        </table>
        <div class="field-box">Additional notes / sketch…</div>';
    }
}
