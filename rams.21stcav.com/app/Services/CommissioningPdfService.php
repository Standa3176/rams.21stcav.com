<?php

namespace App\Services;

use App\Models\CommissioningItem;
use App\Models\CommissioningSignoff;
use App\Models\InstallProgramme;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * CommissioningPdfService — renders the snagging PDF via DomPDF.
 *
 * Two entry points per D-10:
 *   - buildPreview: engineer + client review before signing (no signature block)
 *   - buildFinal:   post-signature, embeds signature image via data-URI
 *
 * All writes go through DocumentArtifactStorage under TYPE_SNAGGING — H-07
 * convention; no storage_path() calls anywhere.
 *
 * Decision refs:
 *   - D-10 (preview → sign → final regeneration)
 *   - D-12 (fail items don't block; roll into "To Be Resolved" section)
 *   - D-13 (zero items produces an empty-state PDF; still valid)
 *   - D-15 (certification_text comes from signoff row, not config, when signoff present)
 *   - Pitfall 4 (defensive allowed_protocols override keeps data:// explicit)
 *   - Pitfall 9 (thumbnail sizing + constrained markup to stay under memory ceiling)
 */
class CommissioningPdfService
{
    public function __construct(
        private readonly DocumentArtifactStorage $artifacts,
    ) {}

    public function buildPreview(InstallProgramme $programme): string
    {
        return $this->render($programme, null, 'preview');
    }

    public function buildFinal(InstallProgramme $programme, CommissioningSignoff $signoff): string
    {
        return $this->render($programme, $signoff, 'final');
    }

    private function render(
        InstallProgramme $programme,
        ?CommissioningSignoff $signoff,
        string $suffix,
    ): string {
        $programme->load(['project']);

        $items = $programme->commissioningItems()
            ->orderBy('room_name')
            ->orderBy('equipment_name')
            ->orderBy('category')
            ->get();

        $rooms = $items->groupBy('room_name');
        $fails = $items->where('status', CommissioningItem::STATUS_FAIL)->values();

        $html = view('pdf.commissioning-snagging', [
            'programme'      => $programme,
            'project'        => $programme->project,
            'items'          => $items,
            'rooms'          => $rooms,
            'fails'          => $fails,
            'signoff'        => $signoff,
            'categoryLabels' => CommissioningItem::categoryLabels(),
        ])->render();

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);

        // Pitfall 4 — defence-in-depth override that still permits data: URIs.
        // Dompdf's default already allows data:// so this is belt-and-braces.
        $options->set('allowed_protocols', [
            'data://' => ['rules' => []],
            'file://' => ['rules' => []],
        ]);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = sprintf(
            'snagging_programme_%d_%s_%s.pdf',
            $programme->id,
            now()->format('Ymd_His'),
            $suffix,
        );

        $absolutePath = $this->artifacts->writePath(DocumentArtifactStorage::TYPE_SNAGGING, $filename);
        file_put_contents($absolutePath, $dompdf->output());

        return $filename;
    }
}
