<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — training records.
 *
 * One row per end-user training session conducted at handover. The OM
 * "Training" section (Phase 5) lists these so the client has a record of
 * what was covered, who attended, and whether the session was signed off.
 *
 *   - attendees   — free-text list (names + roles, one per line OK)
 *   - topics      — free-text list of topics covered
 *   - signed_off  — boolean confirming a signature was captured on the day
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->text('attendees')->nullable();
            $table->date('date')->nullable();
            $table->text('topics')->nullable();
            $table->boolean('signed_off')->default(false);
            $table->timestamps();

            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_records');
    }
};
