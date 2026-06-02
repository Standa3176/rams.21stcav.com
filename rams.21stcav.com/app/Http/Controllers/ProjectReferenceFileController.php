<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectReferenceFile;
use App\Services\ProjectReferenceFileService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin-side controller for engineer reference files (quick task 260601-r4c).
 *
 * Three actions, all gated by `abort_unless(auth()->check(), 403)` per the
 * shared-workspace authorization model (260525-pyu/s8b — any authenticated
 * user, no per-project ownership / admin gate).
 *
 *  - store(Project)                       — POST /projects/{p}/reference-files
 *  - show(Project, ProjectReferenceFile)  — GET  /projects/{p}/reference-files/{f}
 *  - destroy(Project, ProjectReferenceFile) — DELETE /projects/{p}/reference-files/{f}
 *
 * Route uses ->scopeBindings() so {reference_file} is resolved against
 * $project->referenceFiles() — a mismatched project/file pair returns 404
 * from Laravel before this controller is even hit.
 */
class ProjectReferenceFileController extends Controller
{
    public function __construct(
        private readonly ProjectReferenceFileService $service,
    ) {}

    /**
     * POST /projects/{project}/reference-files
     */
    public function store(Request $request, Project $project)
    {
        abort_unless(auth()->check(), 403);

        // Single Laravel rule guards multipart presence + UploadedFile shape.
        // MIME / extension / size checks live inside the service so the
        // canonical rules are not split across controller + service.
        $request->validate([
            'file'  => ['required', 'file'],
            'label' => ['nullable', 'string', 'max:200'],
        ]);

        try {
            $row = $this->service->store(
                $request->file('file'),
                $project,
                auth()->user(),
                $request->input('label'),
            );
        } catch (ValidationException $e) {
            // Re-throw so Laravel's redirect-back-with-errors kicks in
            // (existing behaviour — no swallowing).
            throw $e;
        }

        return back()->with('success', 'File uploaded: ' . $row->original_filename);
    }

    /**
     * GET /projects/{project}/reference-files/{reference_file}
     */
    public function show(Project $project, ProjectReferenceFile $reference_file): Response
    {
        abort_unless(auth()->check(), 403);

        return $this->service->streamResponse($reference_file);
    }

    /**
     * DELETE /projects/{project}/reference-files/{reference_file}
     */
    public function destroy(Project $project, ProjectReferenceFile $reference_file)
    {
        abort_unless(auth()->check(), 403);

        $name = $reference_file->original_filename;
        $this->service->delete($reference_file);

        return back()->with('success', 'File deleted: ' . $name);
    }
}
