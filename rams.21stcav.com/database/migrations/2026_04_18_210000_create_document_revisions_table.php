<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_revisions', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 32);
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('parent_revision_id')->nullable();
            $table->json('payload_snapshot');
            $table->string('artifact_filename', 255)->nullable();
            $table->text('change_summary')->nullable();
            $table->string('source', 16)->default('base');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['document_type', 'document_id'], 'document_revisions_doc_idx');
            $table->index('parent_revision_id',             'document_revisions_parent_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_revisions');
    }
};
