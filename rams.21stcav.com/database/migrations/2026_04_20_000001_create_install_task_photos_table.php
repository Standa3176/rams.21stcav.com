<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-task photo attachments for install tasks (Phase 14).
 *
 * Mirrors site_survey_photos (D-09). Photos stored under
 * storage/app/private/task-photos/{project_id}/{task_id}/{uuid}.jpg and served
 * through TaskPhotoController::show() so they stay private.
 *
 * HEIC uploads are converted to JPEG at upload time by HeicImageConverter
 * (CONTEXT.md D-11: fail loudly if Imagick missing).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('install_task_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('install_task_id')
                  ->constrained('install_tasks')
                  ->cascadeOnDelete();
            $table->string('filename', 255);            // relative path under disk root, e.g. task-photos/42/77/{uuid}.jpg
            $table->string('original_name', 255);
            $table->string('mime_type', 50)->default('image/jpeg');
            $table->string('caption', 200)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('install_task_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('install_task_photos');
    }
};
