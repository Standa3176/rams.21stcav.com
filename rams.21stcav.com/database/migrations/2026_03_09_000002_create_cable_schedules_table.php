<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cable_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('project_name');
            $table->string('project_ref')->nullable();
            $table->string('client_name')->nullable();
            $table->string('source_filename')->nullable();  // original uploaded PDF name
            $table->string('status')->default('draft');     // draft | final
            $table->timestamps();
        });

        Schema::create('cable_schedule_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cable_schedule_id')->constrained()->cascadeOnDelete();
            $table->string('cable_id')->nullable();
            $table->string('from_location')->nullable();
            $table->string('to_location')->nullable();
            $table->string('cable_type')->nullable();
            $table->string('cores')->nullable();
            $table->decimal('approx_length_m', 8, 1)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cable_schedule_items');
        Schema::dropIfExists('cable_schedules');
    }
};
