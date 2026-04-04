<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('om_manuals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                  ->constrained()
                  ->cascadeOnDelete();
            $table->foreignId('rams_document_id')
                  ->nullable()
                  ->constrained('rams_documents')
                  ->nullOnDelete();

            // Project identity
            $table->string('project_name', 200);
            $table->string('project_ref',  50)->nullable();
            $table->string('client_name',  150)->nullable();
            $table->string('site_address', 500)->nullable();
            $table->string('source_filename', 255)->nullable();

            // Workflow status: extracted | draft | final
            $table->string('status', 30)->default('extracted');

            // Pass 1 output — room/equipment data reviewed by user
            $table->json('extracted_data')->nullable();

            // Pass 2 output — full generated O&M content
            $table->json('generated_data')->nullable();

            // Saved .docx filename (stored in storage/app/om-manuals/)
            $table->string('filename', 255)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('om_manuals');
    }
};
