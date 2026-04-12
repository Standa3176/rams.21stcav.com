<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_survey_room_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_survey_room_id')
                  ->constrained('site_survey_rooms')
                  ->cascadeOnDelete();
            $table->text('question');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->enum('answer', ['yes', 'no', 'other'])->nullable();
            $table->text('other_text')->nullable();
            $table->timestamps();
            $table->index('site_survey_room_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_survey_room_questions');
    }
};
