<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Audit CR-01 (2026-07-08) — backfill the M-06 sibling leak.
 *
 * PublicWorksheetController::uploadLabelPhoto was writing the first 8 hex
 * chars of the worksheet UUID token as device_label_photos.captured_by
 * (same pattern M-06 closed for markSurveyReviewed / markRoomComplete).
 * The column is never read by application code, so nulling out every
 * pre-fix value is safe and removes the leaked prefixes from the DB.
 *
 * New captures write `ip:… |actor:<sha256-slice>` per the controller fix.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Null out every legacy captured_by that doesn't already match the
        // new `ip:…|actor:…` shape. Idempotent — safe to re-run.
        DB::table('device_label_photos')
            ->whereNotNull('captured_by')
            ->where('captured_by', 'not like', 'ip:%|actor:%')
            ->update(['captured_by' => null]);
    }

    public function down(): void
    {
        // No reversal — we intentionally destroyed leaked bytes.
    }
};
