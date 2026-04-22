<?php

namespace Tests\Unit\Services;

use App\Models\CommissioningItem;
use App\Models\InstallProgramme;
use App\Models\InstallTask;
use App\Models\Project;
use App\Models\User;
use App\Services\CommissioningItemGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * INST-05b + D-02 (per-instance grain) + D-06 (case-insensitive match) +
 * D-07 (skip unmatched equipment).
 *
 * Red until Plan 02 ships CommissioningItemGenerator + config/commissioning.php.
 */
class CommissioningItemGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_matches_multiple_categories_per_equipment_instance(): void
    {
        // "Poly Studio X70" should match: display + audio + vtc + power → 4 items
        [$programme] = $this->scaffoldProgramme();
        InstallTask::factory()->create([
            'install_programme_id' => $programme->id,
            'equipment_name'       => 'Poly Studio X70',
            'room_name'            => 'Boardroom A',
            'status'               => InstallTask::STATUS_COMPLETE,
        ]);

        $created = app(CommissioningItemGenerator::class)->generate($programme);

        $this->assertGreaterThanOrEqual(4, $created, 'A VTC codec should match multiple categories.');

        $items = CommissioningItem::where('install_programme_id', $programme->id)->get();
        $cats = $items->pluck('category')->unique()->all();
        $this->assertContains('display', $cats);
        $this->assertContains('audio', $cats);
        $this->assertContains('vtc', $cats);
        $this->assertContains('power', $cats);
    }

    public function test_per_instance_grain_three_displays_generate_six_items(): void
    {
        // D-02: three identical "LG 75 Display" tasks → 3 display + 3 power = 6 items
        [$programme] = $this->scaffoldProgramme();

        InstallTask::factory()->count(3)->create([
            'install_programme_id' => $programme->id,
            'equipment_name'       => 'LG 75 Display',
            'status'               => InstallTask::STATUS_COMPLETE,
        ]);

        $created = app(CommissioningItemGenerator::class)->generate($programme);

        $this->assertSame(6, $created, 'Three displays must generate one item per category per instance.');
    }

    public function test_unmatched_equipment_generates_no_items(): void
    {
        // D-07
        [$programme] = $this->scaffoldProgramme();

        InstallTask::factory()->create([
            'install_programme_id' => $programme->id,
            'equipment_name'       => 'cable tray',
            'status'               => InstallTask::STATUS_COMPLETE,
        ]);

        $created = app(CommissioningItemGenerator::class)->generate($programme);

        $this->assertSame(0, $created);
    }

    public function test_case_insensitive_match(): void
    {
        // D-06
        [$programme] = $this->scaffoldProgramme();

        InstallTask::factory()->create([
            'install_programme_id' => $programme->id,
            'equipment_name'       => 'LG MONITOR',
            'status'               => InstallTask::STATUS_COMPLETE,
        ]);
        InstallTask::factory()->create([
            'install_programme_id' => $programme->id,
            'equipment_name'       => 'lg monitor',
            'status'               => InstallTask::STATUS_COMPLETE,
        ]);

        $created = app(CommissioningItemGenerator::class)->generate($programme);

        $this->assertGreaterThanOrEqual(2, $created, 'Case-insensitive match must fire on both casings.');
    }

    public function test_idempotent_when_items_already_exist(): void
    {
        [$programme] = $this->scaffoldProgramme();
        InstallTask::factory()->create([
            'install_programme_id' => $programme->id,
            'equipment_name'       => 'LG 75 Display',
            'status'               => InstallTask::STATUS_COMPLETE,
        ]);

        $svc = app(CommissioningItemGenerator::class);
        $svc->generate($programme);

        $firstCount = CommissioningItem::where('install_programme_id', $programme->id)->count();

        $createdSecondRun = $svc->generate($programme);

        $this->assertSame(0, $createdSecondRun, 'Second run must create nothing.');
        $this->assertSame(
            $firstCount,
            CommissioningItem::where('install_programme_id', $programme->id)->count(),
        );
    }

    public function test_items_record_install_task_id_for_traceability(): void
    {
        [$programme] = $this->scaffoldProgramme();
        $task = InstallTask::factory()->create([
            'install_programme_id' => $programme->id,
            'equipment_name'       => 'LG 75 Display',
            'status'               => InstallTask::STATUS_COMPLETE,
        ]);

        app(CommissioningItemGenerator::class)->generate($programme);

        $items = CommissioningItem::where('install_programme_id', $programme->id)->get();
        $this->assertGreaterThan(0, $items->count());
        foreach ($items as $item) {
            $this->assertSame($task->id, $item->install_task_id);
        }
    }

    /**
     * @return array{0: InstallProgramme}
     */
    private function scaffoldProgramme(): array
    {
        $user = User::factory()->create();
        $project = Project::factory()->create([
            'user_id' => $user->id,
            'status'  => Project::STATUS_INSTALLING,
        ]);
        $programme = InstallProgramme::factory()->create([
            'project_id' => $project->id,
            'status'     => InstallProgramme::STATUS_ACTIVE,
        ]);

        return [$programme];
    }
}
