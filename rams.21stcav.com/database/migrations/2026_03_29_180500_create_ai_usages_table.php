<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_usages')) {
            return;
        }

        Schema::create('ai_usages', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 50);
            $table->string('model', 100)->nullable();
            $table->string('prompt', 190)->nullable(); // prompt class or label
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->decimal('cost_usd', 12, 6)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['provider', 'created_at']);
            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usages');
    }
};
