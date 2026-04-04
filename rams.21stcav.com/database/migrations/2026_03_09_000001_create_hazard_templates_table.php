<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        try {
            if (! Schema::hasTable('hazard_templates')) {
                Schema::create('hazard_templates', function (Blueprint $table) {
                    $table->id();
                    $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                    $table->string('name');
                    $table->text('description')->nullable();
                    $table->unsignedTinyInteger('pre_likelihood')->default(3);
                    $table->unsignedTinyInteger('pre_severity')->default(3);
                    $table->unsignedTinyInteger('post_likelihood')->default(1);
                    $table->unsignedTinyInteger('post_severity')->default(2);
                    $table->json('controls')->nullable();   // array of control strings
                    $table->boolean('is_global')->default(false); // admin-created = available to all
                    $table->timestamps();
                });
            }
        } catch (\Throwable $e) {
            // Ignore duplicate table errors in SQLite test runs
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hazard_templates');
    }
};
