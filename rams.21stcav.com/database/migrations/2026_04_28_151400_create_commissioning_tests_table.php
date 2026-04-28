<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — commissioning test results.
 *
 * One row per commissioning check performed in a room (audio gain check,
 * dB sweep, video signal sync, mic gating threshold, etc.). The OM
 * "Commissioning Results" section (Phase 5) renders these as a table so
 * the client sees exactly what was tested, the measured value, and who
 * signed it off.
 *
 * Fields:
 *   - test_type     — short label e.g. "Audio gain", "Display sync"
 *   - result        — pass / fail / partial / na
 *   - value         — measured reading (free-text, e.g. "-12 dBu", "60 Hz")
 *   - signed_off_by — engineer name (free-text — no FK to users by design,
 *                     so the record survives staff changes)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commissioning_tests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('room')->nullable();
            $table->string('test_type');
            $table->string('result');                 // pass | fail | partial | na
            $table->string('value')->nullable();
            $table->string('signed_off_by')->nullable();
            $table->date('date')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'room']);
            $table->index('result');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commissioning_tests');
    }
};
