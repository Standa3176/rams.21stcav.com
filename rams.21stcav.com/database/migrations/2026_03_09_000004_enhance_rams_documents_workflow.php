<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expand the RAMS documents table for the full workflow:
 *
 *  - status: extend to include 'draft' | 'for_review' | 'approved' | 'superseded'
 *    (keeping 'pending' and 'failed' for the generation pipeline)
 *  - email_sent_at: tracks when the document was last emailed
 *  - superseded_by_id: self-reference for when a document is regenerated
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rams_documents', function (Blueprint $table) {
            // Widen the status column — SQLite/MySQL both handle this via string
            // (The enum change requires dropping & re-adding on MySQL; use string instead)
            $table->string('status')->default('draft')->change();

            $table->timestamp('email_sent_at')->nullable()->after('status');
            $table->foreignId('superseded_by_id')
                  ->nullable()
                  ->after('email_sent_at')
                  ->constrained('rams_documents')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rams_documents', function (Blueprint $table) {
            $table->dropForeign(['superseded_by_id']);
            $table->dropColumn(['email_sent_at', 'superseded_by_id']);
            $table->enum('status', ['pending', 'complete', 'failed'])->default('pending')->change();
        });
    }
};
