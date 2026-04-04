<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        try {
            if (! Schema::hasTable('ai_cache')) {
                Schema::create('ai_cache', function (Blueprint $table) {
                    $table->id();
                    $table->char('hash', 64)->unique();   // SHA-256 hex
                    $table->text('prompt');
                    $table->longText('response');
                    $table->string('model', 100)->nullable();
                    $table->timestamp('created_at')->nullable()->useCurrent();
                });
            }
        } catch (\Throwable $e) {
            // Ignore duplicate table errors in SQLite test runs
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_cache');
    }
};
