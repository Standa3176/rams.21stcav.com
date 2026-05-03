<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Engineer-captured photos of equipment labels — distinct from room install
 * photos. Each row links a photo of a serial / MAC / part-number sticker to
 * a specific device in the asset register, plus the AI-extracted values.
 *
 * - device_id is nullable so the photo can be captured before the engineer
 *   confirms which device row it belongs to.
 * - worksheet_id pins the capture to the visit it happened on.
 * - ai_extracted holds {part, serial, mac, model, confidence} returned by
 *   LabelExtractionPrompt; engineer can edit before save.
 * - confirmed flips true once engineer (or PM) has reviewed extraction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_label_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('worksheet_id')->nullable()->constrained()->nullOnDelete();

            $table->string('room_name')->nullable();
            $table->string('photo_path');                 // storage path
            $table->json('ai_extracted')->nullable();     // {part, serial, mac, model, confidence}
            $table->boolean('confirmed')->default(false);
            $table->timestamp('captured_at')->nullable();
            $table->string('captured_by')->nullable();    // engineer name or token-prefix

            $table->timestamps();

            $table->index(['project_id', 'device_id']);
            $table->index('worksheet_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_label_photos');
    }
};
