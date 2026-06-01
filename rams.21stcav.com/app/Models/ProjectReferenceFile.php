<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Project-level engineer reference file (quick task 260601-r4c).
 *
 * Uploaded artifact (site plan PDF, CAD drawing, cable schedule XLSX,
 * method statement DOCX, etc.) attached to a Project so the on-site
 * engineer can open it from BOTH the public worksheet link AND the
 * public survey link. Distinct from generated documents and from
 * survey/worksheet photos — these are inputs to the install, not
 * outputs of the pipeline.
 *
 * Files live on disk under storage/app/documents/reference-files/
 * {project_id}/{ulid}-{sanitised}.{ext} via DocumentArtifactStorage
 * (TYPE_REFERENCE). The model's stored_path persists ONLY the
 * relative basename (per-project nested), NEVER an absolute path.
 */
class ProjectReferenceFile extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'label',
        'original_filename',
        'stored_path',
        'mime_type',
        'size_bytes',
        'uploaded_by_user_id',
        'uploaded_at',
    ];

    protected $casts = [
        'uploaded_at' => 'datetime',
        'size_bytes'  => 'integer',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function uploadedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
