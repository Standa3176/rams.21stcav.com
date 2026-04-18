<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_edit_threads', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 32);
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('base_revision_id');
            $table->string('status', 16)->default('open');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['document_type', 'document_id'], 'document_edit_threads_doc_idx');
            $table->index('base_revision_id',               'document_edit_threads_base_rev_idx');
            $table->index('status',                         'document_edit_threads_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_edit_threads');
    }
};
