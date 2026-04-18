<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_edit_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('thread_id');
            $table->string('role', 16);
            $table->longText('content');
            $table->json('operations_json')->nullable();
            $table->timestamps();

            $table->index('thread_id', 'document_edit_messages_thread_idx');
            $table->index('role',      'document_edit_messages_role_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_edit_messages');
    }
};
