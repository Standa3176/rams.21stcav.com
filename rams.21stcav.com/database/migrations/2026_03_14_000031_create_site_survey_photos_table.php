<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-room photo attachments for site surveys.
 *
 * Photos are stored on the local disk under storage/app/survey-photos/{uuid}.ext
 * and served through SiteSurveyController::servePhoto() so they stay private.
 *
 * sort_order allows manual reordering within a room's gallery.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_survey_photos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('site_survey_room_id')
                  ->constrained()
                  ->cascadeOnDelete();

            $table->string('filename', 255);          // UUID-based storage filename
            $table->string('original_name', 255);     // user's original filename
            $table->string('mime_type', 50)->default('image/jpeg');
            $table->string('caption', 200)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_survey_photos');
    }
};
