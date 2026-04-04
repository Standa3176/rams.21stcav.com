<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the project_quotes table.
 *
 * Each row represents one uploaded quote PDF version linked to a Project.
 * Multiple versions can exist for the same project (version_number increments).
 *
 * Relationships:
 *   project_quotes.project_id → projects.id (nullOnDelete for safety)
 *   project_quotes.uploaded_by → users.id   (nullOnDelete — user can be deleted)
 *
 * Constraints follow the style of 2026_03_14_000020 (nullable FK, nullOnDelete).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_quotes', function (Blueprint $table) {
            $table->id();

            // Project link — nullable so orphaned rows survive project deletion
            $table->foreignId('project_id')
                  ->nullable()
                  ->constrained('projects')
                  ->nullOnDelete();

            // Uploader — nullable so rows survive user deletion
            $table->foreignId('uploaded_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            // File identity
            $table->string('original_filename', 500);
            $table->string('stored_filename', 1000);  // absolute path, like rams_documents.filename

            // Parsed quote fields (snapshot at upload time)
            $table->string('quote_reference', 100)->nullable()->index();
            $table->date('quote_date')->nullable();
            $table->string('client_name', 255)->nullable();
            $table->string('site_name', 255)->nullable();
            $table->text('site_address')->nullable();

            // Full parsed snapshot for auditability / replay
            $table->json('parsed_snapshot')->nullable();

            // Version sequence within this project
            $table->unsignedSmallInteger('version_number')->default(1);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_quotes');
    }
};
