<?php

namespace Tests\Feature\Cable;

use App\Models\CableSchedule;
use App\Models\User;
use App\Services\PdfTextExtractorService;
use App\Services\QuoteLineExtractorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CableScheduleStoreDeterministicTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_generates_rows_deterministically_without_ai(): void
    {
        $user = User::factory()->create();

        $this->mock(PdfTextExtractorService::class, function ($mock): void {
            $mock->shouldReceive('extract')->once()->andReturn('mock quote text');
        });

        $this->mock(QuoteLineExtractorService::class, function ($mock): void {
            $mock->shouldReceive('extractEquipmentLines')->once()->andReturn([
                'Samsung QM85C 85" Commercial Display',
                'Additional',
                'Enhanced Connection Drawing',
                'Cat6 Cable Reel 305m',
                'Shure, Lavalier Mic.',
            ]);
        });

        $response = $this->actingAs($user)->post(route('cable-schedules.store'), [
            'project_name' => 'WD UK AV Refresh',
            'project_ref'  => '21CQ29437-11-OPS',
            'client_name'  => 'Western Digital UK Limited',
            'quote_pdf'    => UploadedFile::fake()->create('quote.pdf', 100, 'application/pdf'),
        ]);

        $schedule = CableSchedule::latest()->first();

        $this->assertNotNull($schedule);
        $response->assertRedirectToRoute('cable-schedules.edit', $schedule);
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('cable_schedules', [
            'id'           => $schedule->id,
            'user_id'      => $user->id,
            'project_ref'  => '21CQ29437-11-OPS',
            'status'       => CableSchedule::STATUS_DRAFT,
        ]);

        $schedule->load('items');
        $this->assertCount(2, $schedule->items, 'Only cable-relevant hardware lines should become rows.');

        $types = $schedule->items->pluck('cable_type')->all();
        $this->assertContains('HDMI 2.0', $types);
        $this->assertContains('Cat6 (Shure network)', $types);

        foreach ($schedule->items as $item) {
            $this->assertStringContainsString('Quote Line — ', $item->from_location);
            $this->assertNotSame('', (string) $item->cable_id);
            $this->assertNotSame('', (string) $item->to_location);
        }
    }

    public function test_store_returns_error_when_no_cable_relevant_lines_found(): void
    {
        $user = User::factory()->create();

        $this->mock(PdfTextExtractorService::class, function ($mock): void {
            $mock->shouldReceive('extract')->once()->andReturn('mock quote text');
        });

        $this->mock(QuoteLineExtractorService::class, function ($mock): void {
            $mock->shouldReceive('extractEquipmentLines')->once()->andReturn([
                'Additional',
                'Enhanced Connection Drawing',
                'Cat6 Cable Reel 305m',
            ]);
        });

        $response = $this->from(route('cable-schedules.create'))
            ->actingAs($user)
            ->post(route('cable-schedules.store'), [
                'project_name' => 'No Hardware Project',
                'project_ref'  => 'NO-HW-001',
                'client_name'  => 'Test Client',
                'quote_pdf'    => UploadedFile::fake()->create('quote.pdf', 100, 'application/pdf'),
            ]);

        $response->assertRedirect(route('cable-schedules.create'));
        $response->assertSessionHas('error');
        $this->assertDatabaseCount('cable_schedules', 0);
    }
}

