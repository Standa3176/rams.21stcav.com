<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — Tier 1 OM appendices.
 *
 * Holds references to drawings (Appendix A) and user guides (Appendix B)
 * uploaded against a project. The OM template registers these in the
 * Drawings / User Guides sections (Phase 5) and includes file paths for
 * link-out — it does NOT embed the files.
 *
 * type values used by the generator:
 *   - 'drawing'    → counted toward the "≥ 1 drawing required" rule (Phase 6)
 *   - 'user_guide' → optional
 *   - 'other'      → reserved for future
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appendices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('type');                       // drawing | user_guide | other
            $table->string('title');
            $table->string('file_path')->nullable();
            $table->string('reference_number')->nullable();
            $table->string('revision')->nullable();
            $table->date('date')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appendices');
    }
};
