<?php

namespace App\Services;

use App\Exceptions\CommissioningSignoffException;
use App\Models\CommissioningItem;
use App\Models\CommissioningSignoff;
use App\Models\InstallProgramme;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * CommissioningService — orchestrates commissioning signoff.
 *
 * Decision refs:
 *   - D-10 (preview → sign → finalise: this service owns the finalise half)
 *   - D-13 (zero items allowed to sign off)
 *   - D-15 (snapshot certification_text on signoff row)
 *   - D-16 (atomic: signoff → PDF → Project.status → Programme.status)
 *   - INST-05h (state-machine guard via canTransitionTo)
 *   - INST-05i (signoff is permanent; enforced via unique DB index + model)
 *
 * Transaction order (D-16):
 *   lockForUpdate(programme)
 *     → canTransitionTo check (INST-05h)
 *     → itemsStillPending check (D-13 allows zero items)
 *     → sanitise + validate signature base64 (T-16-07)
 *     → CommissioningSignoff::create (certification_text snapshotted here — D-15)
 *     → CommissioningPdfService::buildFinal (may throw; rolls back)
 *     → signoff.snagging_pdf_path updated
 *     → Project::update(status=STATUS_COMMISSIONING, commissioning_started_at=now)
 *     → InstallProgramme::update(status=STATUS_COMPLETE)
 *
 * ANY exception inside DB::transaction → full rollback (signoff absent, state
 * unchanged, orphan PDF file on disk is acceptable — artifact cleanup is
 * out-of-scope for the transaction boundary).
 */
class CommissioningService
{
    public function __construct(
        private readonly CommissioningPdfService $pdfService,
    ) {}

    /**
     * Finalise a programme's commissioning. Atomic all-or-nothing (D-16).
     *
     * @param array{client_name: string, client_role: string, client_company: string, signature_png_base64: string} $payload
     * @throws CommissioningSignoffException on pre-condition failure (→ 422)
     */
    public function finalise(InstallProgramme $programme, array $payload): CommissioningSignoff
    {
        return DB::transaction(function () use ($programme, $payload) {
            // Pitfall 7 — lock the programme row for the duration of the
            // transaction so two concurrent finalise requests serialise.
            $programme = InstallProgramme::where('id', $programme->id)
                ->lockForUpdate()
                ->firstOrFail();

            $project = $programme->project;

            // INST-05h + D-16 — state-machine gate BEFORE any writes.
            if (! $project->canTransitionTo(Project::STATUS_COMMISSIONING)) {
                throw CommissioningSignoffException::invalidStateTransition(
                    $project->status,
                    Project::STATUS_COMMISSIONING,
                );
            }

            // INST-05 gate — all items must be non-pending (zero items OK per D-13).
            $pendingCount = $programme->commissioningItems()
                ->where('status', CommissioningItem::STATUS_PENDING)
                ->count();
            if ($pendingCount > 0) {
                throw CommissioningSignoffException::itemsStillPending($pendingCount);
            }

            // Sanitise + validate the signature base64 (T-16-07).
            $cleanBase64 = $this->sanitiseBase64($payload['signature_png_base64']);
            $this->assertValidPngBase64($cleanBase64);

            // D-15 — snapshot certification text from config at sign time.
            $certificationText = config('commissioning.certification_text');

            // Attempt signoff insert — may collide on UNIQUE(install_programme_id).
            try {
                $signoff = CommissioningSignoff::create([
                    'install_programme_id'    => $programme->id,
                    'client_name'             => $payload['client_name'],
                    'client_role'             => $payload['client_role'],
                    'client_company'          => $payload['client_company'],
                    'signature_png_base64'    => $cleanBase64,
                    'certification_text'      => $certificationText,
                    'snagging_pdf_path'       => 'pending',   // updated after buildFinal
                    'signed_at'               => now(),
                    'signed_off_engineer_id'  => auth()->id(),
                ]);
            } catch (QueryException $e) {
                // 23000 = integrity constraint violation (MySQL unique).
                if ((string) $e->getCode() === '23000') {
                    throw CommissioningSignoffException::alreadySigned($programme->id);
                }
                throw $e;
            }

            // Regenerate PDF with signature embedded — may throw; rolls back.
            $finalFilename = $this->pdfService->buildFinal($programme, $signoff);
            $signoff->update(['snagging_pdf_path' => $finalFilename]);

            // Advance state (D-16).
            $project->update([
                'status'                   => Project::STATUS_COMMISSIONING,
                'commissioning_started_at' => now(),
            ]);

            $programme->update([
                'status' => InstallProgramme::STATUS_COMPLETE,
            ]);

            Log::info('CommissioningService: programme signed off', [
                'programme_id' => $programme->id,
                'project_id'   => $project->id,
                'signoff_id'   => $signoff->id,
                'engineer_id'  => auth()->id(),
                'client_name'  => $payload['client_name'],
            ]);

            return $signoff->fresh();
        });
    }

    /**
     * Strip data-URI prefix + all whitespace (Pitfall 5). Public for unit tests.
     *
     * The FormRequest regex accepts both `data:image/png;base64,<body>` and a
     * bare base64 body, and tolerates interior whitespace / newlines. The
     * service normalises here so the stored value is always the compact
     * base64 body — Blade re-concatenates the `data:image/png;base64,` prefix
     * at render time.
     */
    public function sanitiseBase64(string $raw): string
    {
        $raw = preg_replace('#^data:image/png;base64,#', '', $raw) ?? $raw;
        return preg_replace('/\s+/', '', $raw) ?? '';
    }

    /**
     * T-16-07 — validate that the base64 string decodes to real PNG bytes.
     * Checked AFTER sanitiseBase64 strips the data-URI prefix + whitespace.
     *
     * PNG signature: \x89 P N G \r \n \x1A \n (first 8 bytes of every PNG).
     *
     * @throws CommissioningSignoffException on decode failure or non-PNG payload
     */
    private function assertValidPngBase64(string $base64): void
    {
        $decoded = base64_decode($base64, true);
        if ($decoded === false || strlen($decoded) < 8) {
            throw new CommissioningSignoffException('Signature payload is not valid base64 PNG data.');
        }
        $pngSig = "\x89PNG\r\n\x1a\n";
        if (strncmp($decoded, $pngSig, 8) !== 0) {
            throw new CommissioningSignoffException('Signature payload is not a PNG image.');
        }
    }
}
