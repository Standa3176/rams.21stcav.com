<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_surveys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('project_name');
            $table->string('project_ref')->nullable();
            $table->string('client_name')->nullable();
            $table->string('site_address')->nullable();
            $table->date('survey_date')->nullable();
            $table->string('surveyor_name')->nullable();
            $table->text('general_notes')->nullable();
            $table->timestamps();
        });

        Schema::create('site_survey_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_survey_id')->constrained()->cascadeOnDelete();
            $table->string('room_name');
            $table->string('room_ref')->nullable();
            $table->string('floor')->nullable();
            $table->text('av_requirements')->nullable();
            $table->boolean('has_power')->default(false);
            $table->boolean('has_network')->default(false);
            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_survey_rooms');
        Schema::dropIfExists('site_surveys');
    }
};
