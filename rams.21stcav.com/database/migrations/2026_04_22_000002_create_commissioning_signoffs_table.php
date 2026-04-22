<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the commissioning_signoffs table (INST-05i + D-11 + D-15 + D-16).
 *
 * One row per install_programme, enforced by a UNIQUE index at the DB level
 * (Pitfall 7 race guard — a second concurrent finalise attempt fails on the
 * constraint rather than silently producing duplicate signoffs).
 *
 * Row is PERMANENT per INST-05i — no softDeletes, and the downstream service
 * layer refuses updates to the signature/client/PDF columns once a row exists
 * for the programme. certification_text (D-15) stores the legal wording the
 * client signed at the moment of sign-off so later config changes cannot
 * retroactively alter an audit record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commissioning_signoffs', function (Blueprint $table) {
            $table->id();

            // Pitfall 7 — one signoff per programme, enforced at DB level
            // so a race (two finalise requests in flight) cannot insert a
            // duplicate.
            $table->foreignId('install_programme_id')
                  ->unique()
                  ->constrained('install_programmes')
                  ->cascadeOnDelete();

            // D-11 — client metadata captured on the sign screen.
            $table->string('client_name',    200);
            $table->string('client_role',    200);
            $table->string('client_company', 200);

            // INST-05f — "signature stored as base64 PNG".
            // longText because iPad Retina PNGs can reach 40-60KB.
            $table->longText('signature_png_base64');

            // D-15 — immutable snapshot of the legal wording the client
            // signed. Decoupled from config/commissioning.php so future
            // wording changes do not rewrite history.
            $table->longText('certification_text');

            // DocumentArtifactStorage filename (TYPE_SNAGGING), not abs path.
            $table->string('snagging_pdf_path', 500);

            $table->timestamp('signed_at');

            // Dual-attestation — engineer FK + client_name freetext.
            // nullOnDelete so audit trail survives if the engineer user is
            // later removed from the system.
            $table->foreignId('signed_off_engineer_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            $table->timestamps();
            // NO softDeletes — INST-05i permanence.

            $table->index('signed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commissioning_signoffs');
    }
};
