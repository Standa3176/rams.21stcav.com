<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Project-level engineer reference files (quick task 260601-r4c).
 *
 * Distinct from generated documents (RAMS / O&M / Worksheet / Cable) and
 * distinct from survey/worksheet photos. These are UPLOADED artifacts —
 * site plans (PDF), CAD drawings (DWG/DXF), cable schedules (XLSX/XLS),
 * method statements (DOCX/DOC), data sheets (CSV) — that the engineer
 * needs in their hand on the day of install.
 *
 * Files stored under storage/app/documents/reference-files/{project_id}/
 * via DocumentArtifactStorage::TYPE_REFERENCE (H-07). The basename only
 * is persisted in stored_path; the per-project subdir is enforced by the
 * service layer (ProjectReferenceFileService).
 *
 * - project_id FK cascadeOnDelete: file rows are deleted with the project
 *   (mirrors device_label_photos precedent — child of Project).
 * - uploaded_by_user_id nullOnDelete: preserves audit trail when a user
 *   is removed from the system; row stays, only the link goes null.
 * - stored_path is 500 chars (not the usual 255) because the service
 *   composes "{ulid}-{sanitised-100-chars}.{ext}" which can push past
 *   255 when combined with deep extensions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_reference_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('label', 200)->nullable();
            $table->string('original_filename', 255);
            $table->string('stored_path', 500); // BASENAME on disk (NOT a hand-built absolute path)
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('uploaded_at');
            $table->timestamps();

            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_reference_files');
    }
};
