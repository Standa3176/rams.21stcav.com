<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Core projects table.
 *
 * Lifecycle states (status column):
 *   quote_imported → survey_pending → engineering → installing
 *   → commissioning → handover → completed → archived
 *
 * Resume support: previous_status / reopened_at / reopened_by / reopen_reason
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();

            // Owner
            $table->foreignId('user_id')
                  ->constrained()
                  ->cascadeOnDelete();

            // Identity
            $table->string('name', 200);
            $table->string('ref',  50)->nullable()->index();
            $table->string('client_name', 150);
            $table->string('site_address', 500);
            $table->text('works_description')->nullable();

            // Lifecycle
            $table->string('status', 30)->default('quote_imported')->index();

            // Resume support
            $table->string('previous_status', 30)->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->foreignId('reopened_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->text('reopen_reason')->nullable();

            // Milestone timestamps
            $table->timestamp('survey_started_at')->nullable();
            $table->timestamp('engineering_started_at')->nullable();
            $table->timestamp('installation_started_at')->nullable();
            $table->timestamp('commissioning_started_at')->nullable();
            $table->timestamp('handover_started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('archived_at')->nullable();

            // Metadata
            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
