<?php

namespace Tests\Feature\ProjectReferenceFiles;

use App\Models\Project;
use App\Models\ProjectReferenceFile;
use App\Services\DocumentArtifactStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Schema/relationship guard for project_reference_files (quick task 260601-r4c).
 *
 * Covers: column shape, Project::referenceFiles() hasMany relation,
 * DocumentArtifactStorage::TYPE_REFERENCE writePath resolution.
 */
class SchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_table_has_expected_columns(): void
    {
        $this->assertTrue(
            Schema::hasColumns('project_reference_files', [
                'id',
                'project_id',
                'label',
                'original_filename',
                'stored_path',
                'mime_type',
                'size_bytes',
                'uploaded_by_user_id',
                'uploaded_at',
                'created_at',
                'updated_at',
            ]),
            'project_reference_files is missing one or more expected columns'
        );
    }

    public function test_project_referenceFiles_relation_returns_hasMany(): void
    {
        $project = Project::factory()->create();

        ProjectReferenceFile::create([
            'project_id'        => $project->id,
            'label'             => 'Site plan',
            'original_filename' => 'site-plan.pdf',
            'stored_path'       => 'aaa.pdf',
            'mime_type'         => 'application/pdf',
            'size_bytes'        => 100,
            'uploaded_at'       => now(),
        ]);

        ProjectReferenceFile::create([
            'project_id'        => $project->id,
            'label'             => 'CAD',
            'original_filename' => 'cad.dwg',
            'stored_path'       => 'bbb.dwg',
            'mime_type'         => 'application/octet-stream',
            'size_bytes'        => 200,
            'uploaded_at'       => now(),
        ]);

        $this->assertSame(2, $project->refresh()->referenceFiles->count());
    }

    public function test_document_artifact_storage_writePath_resolves_TYPE_REFERENCE(): void
    {
        $storage = app(DocumentArtifactStorage::class);

        $this->assertContains(DocumentArtifactStorage::TYPE_REFERENCE, $storage->types());

        $path = $storage->writePath(DocumentArtifactStorage::TYPE_REFERENCE, 'sample.pdf');
        $this->assertStringContainsString('documents', str_replace('\\', '/', $path));
        $this->assertStringContainsString('reference-files', str_replace('\\', '/', $path));
        $this->assertDirectoryExists(dirname($path));
    }
}
