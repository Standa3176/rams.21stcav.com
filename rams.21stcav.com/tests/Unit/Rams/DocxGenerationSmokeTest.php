<?php

namespace Tests\Unit\Rams;

use App\Models\RamsDocument;
use App\Models\User;
use App\Services\WordDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smoke test: verifies WordDocumentService::build() completes without
 * throwing and writes a non-empty .docx file to storage/app/rams/.
 */
class DocxGenerationSmokeTest extends TestCase
{
    use RefreshDatabase;

    private string $generatedPath = '';
    private int    $userId        = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // FK constraints are enforced in SQLite (foreign_key_constraints = true).
        // Create a real user so RamsDocument.user_id satisfies the constraint.
        $this->userId = User::factory()->create()->id;
    }

    protected function tearDown(): void
    {
        // Clean up the generated file so we don't leave test artifacts on disk
        if ($this->generatedPath !== '' && file_exists($this->generatedPath)) {
            @unlink($this->generatedPath);
        }

        parent::tearDown();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function minimalData(): array
    {
        return [
            'project' => [
                'ref'               => 'SMOKE-001',
                'name'              => 'Smoke Test Project',
                'client'            => 'Test Client Ltd',
                'site_address'      => '1 Test Lane, London',
                'works_description' => 'Smoke test AV installation.',
                'document_status'   => 'For Construction',
                'doc_author'        => 'PHPUnit',
                'date'              => 'January 2025',
            ],
            'hazards' => [
                [
                    'id'              => 1,
                    'hazard'          => 'Electrocution',
                    'persons_at_risk' => ['21CAV Staff'],
                    'pre_likelihood'  => 2,
                    'pre_severity'    => 5,
                    'controls'        => ['Isolate circuits before working.', 'Test dead before touching.'],
                    'post_likelihood' => 1,
                    'post_severity'   => 5,
                ],
            ],
            'ppe' => [
                'Safety Boots (steel toe cap)',
                'Hi-Visibility Vest',
                'Safety Glasses',
                'Latex / Nitrile Gloves',
            ],
            'persons_at_risk' => ['21CAV Staff', 'Client Staff'],
            'method_statement' => [
                'phases' => [
                    [
                        'title' => 'Phase 1: Pre-Works Planning',
                        'steps' => [
                            'Review RAMS with all team members.',
                            'Conduct site induction.',
                            'Inspect all tools and equipment.',
                        ],
                    ],
                ],
            ],
            'team'       => [],
            'quote'      => [],
            'classified' => [
                'activities'        => ['display_installation'],
                'categories'        => [],
                'summary'           => 'Display installation',
                'heavy_items'       => [],
                'drilling_required' => false,
            ],
        ];
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_build_creates_docx_file_on_disk(): void
    {
        $record = RamsDocument::create([
            'user_id'      => $this->userId,
            'project_ref'  => 'SMOKE-001',
            'project_name' => 'Smoke Test Project',
            'client_name'  => 'Test Client Ltd',
            'site_address' => '1 Test Lane, London',
            'ai_provider'  => 'claude',
            'ai_model'     => 'claude-sonnet-4-6',
            'form_data'    => [],
            'filename'     => 'smoke-test-' . time() . '.docx',
            'status'       => RamsDocument::STATUS_FOR_REVIEW,
        ]);

        /** @var WordDocumentService $service */
        $service = app(WordDocumentService::class);

        $path = $service->build($this->minimalData(), $record);

        $this->generatedPath = $path;

        $this->assertFileExists($path, 'DOCX file was not written to disk.');
        $this->assertGreaterThan(5_000, filesize($path), 'DOCX file is suspiciously small — likely empty or truncated.');
    }

    public function test_build_returns_absolute_path(): void
    {
        $record = RamsDocument::create([
            'user_id'      => $this->userId,
            'project_ref'  => 'SMOKE-002',
            'project_name' => 'Path Check',
            'client_name'  => 'Test Client',
            'site_address' => '2 Test Lane',
            'ai_provider'  => 'claude',
            'ai_model'     => 'claude-sonnet-4-6',
            'form_data'    => [],
            'filename'     => 'smoke-path-' . time() . '.docx',
            'status'       => RamsDocument::STATUS_FOR_REVIEW,
        ]);

        /** @var WordDocumentService $service */
        $service = app(WordDocumentService::class);

        $path = $service->build($this->minimalData(), $record);

        $this->generatedPath = $path;

        $this->assertStringEndsWith('.docx', $path);
        $this->assertStringContainsString('rams', $path);
    }

    public function test_build_handles_empty_method_statement_phases(): void
    {
        $data                                      = $this->minimalData();
        $data['method_statement']['phases']        = [];

        $record = RamsDocument::create([
            'user_id'      => $this->userId,
            'project_ref'  => 'SMOKE-003',
            'project_name' => 'Empty Phases Test',
            'client_name'  => 'Test Client',
            'site_address' => '3 Test Lane',
            'ai_provider'  => 'claude',
            'ai_model'     => 'claude-sonnet-4-6',
            'form_data'    => [],
            'filename'     => 'smoke-empty-phases-' . time() . '.docx',
            'status'       => RamsDocument::STATUS_FOR_REVIEW,
        ]);

        /** @var WordDocumentService $service */
        $service = app(WordDocumentService::class);

        // Should not throw — WordDocumentService renders gracefully with no phases
        $path = $service->build($data, $record);

        $this->generatedPath = $path;
        $this->assertFileExists($path);
    }
}
