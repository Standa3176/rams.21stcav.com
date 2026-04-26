<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the `worksheet_signoffs` table — append-only client acceptance log
 * for each worksheet's public sign-off page.
 *
 * Differs from the commissioning_signoffs precedent in two important ways:
 *  - NO unique constraint on worksheet_id: clients can sign multiple times
 *    (e.g. a snag-list resignoff after remedials). Each row is preserved.
 *  - NO softDeletes: append-only audit trail. Records are permanent.
 *
 * The signature column stores raw base64 PNG (no `data:` prefix) so the same
 * convention as CommissioningSignoff applies: data-uri concatenation happens
 * in the model accessor, never in the database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worksheet_signoffs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('worksheet_id')
                  ->constrained('worksheets')
                  ->cascadeOnDelete();

            // Client-facing metadata captured at sign time.
            $table->string('client_name', 200);

            // Raw base64 PNG bytes — NO `data:image/png;base64,` prefix.
            // longText covers iPad Retina captures (40-60KB).
            $table->longText('signature_png_base64');

            // When ticked the client is signing despite outstanding items;
            // the comments column then holds the snag list / acceptance notes.
            $table->boolean('signed_with_comments')->default(false);
            $table->text('comments')->nullable();

            $table->timestamp('signed_at');

            // Best-effort audit trail — no PII enforcement.
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 500)->nullable();

            $table->timestamps();
            // NO softDeletes — this table is the permanent audit record.

            $table->index('worksheet_id');
            $table->index('signed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worksheet_signoffs');
    }
};
