<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the three-layer data separation columns required by the review workflow.
 *
 * Column roles:
 *   extracted_data — machine-produced structured data from Phase A (parse/classify/risk)
 *   reviewed_data  — human-corrected version of extracted_data; source-of-truth for Phase B
 *   approved_at    — timestamp set when a user approves the record for generation
 *   approved_by    — FK to the user who approved (nullable; no constraint to stay portable)
 *
 * Design:
 *   - All columns are nullable so existing rows are unaffected.
 *   - Migration is idempotent: columns are only added if they do not already exist.
 *   - No columns are removed or renamed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rams_documents', function (Blueprint $table) {
            if (! Schema::hasColumn('rams_documents', 'extracted_data')) {
                $table->json('extracted_data')->nullable()->after('generated_data');
            }

            if (! Schema::hasColumn('rams_documents', 'reviewed_data')) {
                $table->json('reviewed_data')->nullable()->after('extracted_data');
            }

            if (! Schema::hasColumn('rams_documents', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('email_sent_at');
            }

            if (! Schema::hasColumn('rams_documents', 'approved_by')) {
                $table->unsignedBigInteger('approved_by')->nullable()->after('approved_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rams_documents', function (Blueprint $table) {
            $columns = ['extracted_data', 'reviewed_data', 'approved_at', 'approved_by'];

            foreach ($columns as $column) {
                if (Schema::hasColumn('rams_documents', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
