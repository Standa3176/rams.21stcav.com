<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the worksheets table for the Worksheet document generator.
 *
 * Each worksheet is tied to a project and user. Generated content is stored
 * as JSON and the final DOCX filename is recorded after build completes.
 * Mirrors the om_manuals table structure for status tracking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worksheets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                  ->constrained('users')
                  ->cascadeOnDelete();

            $table->foreignId('project_id')
                  ->nullable()
                  ->constrained('projects')
                  ->nullOnDelete();

            $table->string('project_name', 200);
            $table->string('project_ref', 50)->nullable();
            $table->string('client_name', 150)->nullable();
            $table->string('site_address', 500)->nullable();

            // Pipeline status
            $table->string('status', 30)->default('pending');
            $table->string('error_message', 1000)->nullable();

            // Generated content (rooms[] array)
            $table->json('generated_data')->nullable();

            // Saved DOCX filename (relative — stored under worksheets/ disk path)
            $table->string('filename', 255)->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worksheets');
    }
};
