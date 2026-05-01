<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 17 — System Schematics + Shared Foundations.
 *
 * Single project_drawings table with a kind discriminator carrying every
 * drawing type that v1.3 produces (schematic / rack / floor_plan).
 * Phase 17 owns this shared foundation so Phases 18 (Rack Elevations),
 * 19 (Floor Plans / Konva), and 20 (Drawing Export + O&M Integration) ride
 * on it as pure additions — no second DDL pass.
 *
 * Why one table not three:
 *   - All three drawing kinds share the same lifecycle (draft → for_review →
 *     approved → superseded), the same versioning need (DRAW-24 R0/R1/R2…),
 *     the same generation source snapshot (source_data JSON), the same idempotent
 *     completion-email flow (NOTF-01), and the same DocumentArtifactStorage
 *     write/read convention (TYPE_DRAWING). Three near-identical tables would
 *     diverge over time exactly the way the four pre-H-07 document storage
 *     paths diverged. One table + kind discriminator + ProjectDrawing model
 *     constants keeps every drawing pipeline in lockstep.
 *
 * Versioning:
 *   - version is 1-indexed; UI label is "R" . (version - 1) so the very first
 *     row reads as R0 (matches AV industry convention).
 *   - superseded_by_id self-FK lets Plan 03's index page filter to current
 *     revisions only via whereNull('superseded_by_id').
 *   - Status flips to STATUS_SUPERSEDED on the prior row inside the same
 *     DB::transaction that creates the new row (DrawingService::regenerate +
 *     archivePrior — mirrors InstallProgrammeService Phase 12 precedent).
 *
 * Forward compat:
 *   - access_token (nullable, unique) is added now for v1.4 client portal
 *     token-gated routes; not exposed in any v1.3 route. Cheaper to land it
 *     here than to migrate the table again.
 *
 * @see DRAW-24 (revision tracking) and DRAW-25 (status state machine) in
 *      .planning/REQUIREMENTS.md.
 * @see app/Models/ProjectDrawing.php — KIND_* and STATUS_* constants validated
 *      against the kind / status varchar columns at the application layer.
 * @see app/Services/Drawings/DrawingService.php — regenerate() + archivePrior()
 *      orchestration that drives the superseded_by_id self-FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_drawings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();

            // Per-room drawings (Phase 17 schematics, Phase 19 floor plans).
            // Nullable for project-wide master schematic (planner discretion;
            // not used in Phase 17 v1 — see CONTEXT.md "Schematic granularity").
            $table->foreignId('site_survey_room_id')
                ->nullable()
                ->constrained('site_survey_rooms')
                ->nullOnDelete();

            // Kind discriminator. Stored as varchar(20), application-validated
            // against KIND_* constants on ProjectDrawing (matches install_tasks.status).
            $table->string('kind', 20);   // 'schematic' | 'rack' | 'floor_plan'

            // Phase 18 — set when kind=rack. Nullable for non-rack rows.
            $table->string('rack_label', 100)->nullable();

            // Versioning — DRAW-24 (R0/R1/R2…). version is 1-indexed; R0 == version 1.
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('superseded_by_id')
                ->nullable()
                ->constrained('project_drawings')
                ->nullOnDelete();

            // Generation source — JSON snapshot from ProjectDataService at
            // generation time. Lets a regen reproduce exactly the same SVG
            // even if canonical project data changed in the meantime.
            $table->json('source_data')->nullable();

            // Auto-generated SVG (D2 output for schematics, custom Blade builder
            // for racks). longText is plenty for SVG payloads we expect.
            $table->longText('generated_svg')->nullable();

            // Konva scene graph for user edits (Phase 19). MEDIUMTEXT (16 MB)
            // per PITFALLS.md MOD-05 — Konva scenes can grow large with many
            // nodes; longText would risk silent truncation on edge cases.
            $table->mediumText('canvas_state')->nullable();

            // Thumbnail PNG path (relative on documents disk —
            // e.g. drawings/thumbnails/schematic-42.png).
            $table->string('thumbnail_png_path', 500)->nullable();

            // Pipeline status — model defines constants
            // (STATUS_DRAFT/STATUS_FOR_REVIEW/STATUS_APPROVED/STATUS_SUPERSEDED/
            //  STATUS_GENERATING/STATUS_READY/STATUS_FAILED).
            $table->string('status', 20)->default('draft');
            $table->text('error_message')->nullable();

            // Stored export filename — current PDF filename for download.
            // SVG/PNG paths derive from this convention:
            //   drawings/{kind}-{drawingId}-v{version}-{ulid}.{format}
            $table->string('filename', 500)->nullable();

            // Idempotent notification timestamps (NOTF-01 / NOTF-04).
            // Set BEFORE send so a retry sees the timestamp populated and skips.
            $table->timestamp('completion_email_sent_at')->nullable();
            $table->timestamp('failed_email_sent_at')->nullable();

            // Forward-compat for v1.4 client portal (per ARCHITECTURE.md §6.4).
            // Nullable; not exposed in v1.3 routes.
            $table->string('access_token', 64)->nullable()->unique();

            $table->foreignId('generated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['project_id', 'kind']);
            $table->index(['project_id', 'site_survey_room_id', 'kind']);
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_drawings');
    }
};
