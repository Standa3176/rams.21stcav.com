<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rams_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                  ->constrained()
                  ->cascadeOnDelete();
            $table->string('project_ref')->nullable();
            $table->string('project_name');
            $table->string('client_name');
            $table->string('site_address');
            $table->string('ai_provider');
            $table->string('ai_model');
            $table->json('form_data');
            $table->json('generated_data');
            $table->string('filename');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rams_documents');
    }
};
