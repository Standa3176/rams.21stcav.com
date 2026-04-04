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
}
