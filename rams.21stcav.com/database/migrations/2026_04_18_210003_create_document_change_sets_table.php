<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_change_sets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('thread_id');
            $table->string('document_type', 32);
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('base_revision_id');
            $table->string('status', 16)->default('proposed');
            $table->json('operations_json');
            $table->json('validation_errors')->nullable();
            $table->string('model_name', 128)->nullable();
            $table->timestamps();

            $table->index('thread_id',                      'document_change_sets_thread_idx');
            $table->index(['document_type', 'document_id'], 'document_change_sets_doc_idx');
            $table->index('status',                         'document_change_sets_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_change_sets');
    }
};
