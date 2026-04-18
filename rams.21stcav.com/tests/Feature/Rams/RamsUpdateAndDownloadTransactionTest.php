<?php

namespace Tests\Feature\Rams;

use App\Models\Project;
use App\Models\RamsDocument;
use App\Models\User;
use App\Services\RamsDocumentRendererService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class RamsUpdateAndDownloadTransactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_and_download_rolls_back_rams_and_project_when_render_fails(): void
    {
        $user = User::factory()->create();

        $project = Project::factory()->for($user, 'owner')->create([
            'name'         => 'Original Project Name',
            'ref'          => 'ORIG-REF-001',
            'client_name'  => 'Original Client',
            'site_address' => 'Original Site Address',
        ]);

        $initialGenerated = [
            'project' => [
                'name'         => 'Original Project Name',
                'ref'          => 'ORIG-REF-001',
                'client'       => 'Original Client',
                'site_address' => 'Original Site Address',
            ],
        ];

        $record = RamsDocument::create([
            'user_id'        => $user->id,
            'project_id'     => $project->id,
            'project_ref'    => 'ORIG-REF-001',
            'project_name'   => 'Original Project Name',
            'client_name'    => 'Original Client',
            'site_address'   => 'Original Site Address',
            'ai_provider'    => 'claude',
            'ai_model'       => 'claude-sonnet-4-6',
            'form_data'      => ['source' => 'manual_form'],
            'generated_data' => $initialGenerated,
            'reviewed_data'  => [],
            'filename'       => 'existing.docx',
            'status'         => RamsDocument::STATUS_FOR_REVIEW,
        ]);

        $this->mock(RamsDocumentRendererService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('render')
                ->once()
                ->andThrow(new \RuntimeException('forced render failure for transaction test'));
        });

        $response = $this->actingAs($user)
            ->from(route('rams.review', $record))
            ->post(route('rams.update-and-download', $record), [
                'project_name' => 'Updated Project Name',
                'project_ref'  => 'UPDATED-REF-001',
                'client_name'  => 'Updated Client',
                'site_address' => 'Updated Site Address',
            ]);

        $response->assertRedirect(route('rams.review', $record));
        $response->assertSessionHas('error');

        $record->refresh();
        $project->refresh();

        $this->assertSame('Original Project Name', $record->project_name);
        $this->assertSame('ORIG-REF-001', $record->project_ref);
        $this->assertSame('Original Client', $record->client_name);
        $this->assertSame('Original Site Address', $record->site_address);
        $this->assertSame($initialGenerated, $record->generated_data);
        $this->assertSame([], $record->reviewed_data);

        $this->assertSame('Original Project Name', $project->name);
        $this->assertSame('ORIG-REF-001', $project->ref);
        $this->assertSame('Original Client', $project->client_name);
        $this->assertSame('Original Site Address', $project->site_address);
    }
}
