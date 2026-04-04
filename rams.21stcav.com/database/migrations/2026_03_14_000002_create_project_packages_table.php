<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Project packages — the imported quote data and extracted scope.
 *
 * One project can have multiple packages (e.g. revised quotes).
 * Modules (RAMS, O&M, Survey) READ from this table; they never modify it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_packages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_id')
                  ->constrained()
                  ->cascadeOnDelete();

            $table->foreignId('user_id')
                  ->constrained()
                  ->cascadeOnDelete();

            // Source document
            $table->string('quote_filename', 255)->nullable();
            $table->string('quote_path', 500)->nullable();   // storage path

            // Structured data extracted from quote
            $table->json('extracted_data')->nullable();      // client, site, line items, rooms
            $table->json('equipment_list')->nullable();       // typed AV equipment
            $table->json('cable_list')->nullable();           // pre-detected cable runs

            // Scope summary
            $table->text('works_description')->nullable();
            $table->string('revision', 10)->default('A');

            // Status: pending | extracted | reviewed
            $table->string('status', 20)->default('pending');

            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_packages');
    }
};
