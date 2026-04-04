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

            if (! Schema::hasColumn('rams_documents', 'email_sent_at')) {
                $table->timestamp('email_sent_at')->nullable()->after('status');
            }

            if (! Schema::hasColumn('rams_documents', 'superseded_by_id')) {
                $table->foreignId('superseded_by_id')
                      ->nullable()
                      ->after('email_sent_at')
                      ->constrained('rams_documents')
                      ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('rams_documents', function (Blueprint $table) {
            if (Schema::hasColumn('rams_documents', 'superseded_by_id')) {
                $table->dropForeign(['superseded_by_id']);
                $table->dropColumn('superseded_by_id');
            }

            if (Schema::hasColumn('rams_documents', 'email_sent_at')) {
                $table->dropColumn('email_sent_at');
            }

            $table->enum('status', ['pending', 'complete', 'failed'])->default('pending')->change();
        });
    }
};
