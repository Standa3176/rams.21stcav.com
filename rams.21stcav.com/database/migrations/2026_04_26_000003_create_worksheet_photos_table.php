<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Photos captured per room on the public worksheet link.
 *
 * Mirrors site_survey_photos but scopes to worksheets. The room_name
 * column carries the canonical room name string from generated_data
 * because worksheets don't have a normalised rooms table — rooms live
 * inside the JSON generated_data blob.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worksheet_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worksheet_id')->constrained()->cascadeOnDelete();
            $table->string('room_name', 200);
            $table->string('filename', 255);          // UUID-based storage path
            $table->string('original_name', 255);
            $table->string('mime_type', 50)->default('image/jpeg');
            $table->string('caption', 200)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['worksheet_id', 'room_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worksheet_photos');
    }
};
