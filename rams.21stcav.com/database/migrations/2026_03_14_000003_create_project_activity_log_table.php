<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable project activity log.
 *
 * Records every lifecycle transition and significant event.
 * Rows are append-only — never updated or soft-deleted.
 *
 * Action values:
 *   status_changed | project_created | project_reopened
 *   document_added | document_updated | note_added
 *   package_imported | package_reviewed
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_activity_log', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_id')
                  ->constrained()
                  ->cascadeOnDelete();

            $table->foreignId('user_id')
                  ->nullable()          // null = system action
                  ->constrained()
                  ->nullOnDelete();

            // Event type
            $table->string('action', 60)->index();

            // Lifecycle diff (populated on status_changed / reopened)
            $table->string('from_status', 30)->nullable();
            $table->string('to_status',   30)->nullable();

            // Human-readable description shown in the activity feed
            $table->string('description', 500);

            // Arbitrary payload (document IDs, metadata, etc.)
            $table->json('metadata')->nullable();

            // Append-only: only created_at, no updated_at
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_activity_log');
    }
};
