<?php

namespace Tests\Unit\Models;

use App\Models\CommissioningItem;
use App\Models\InstallProgramme;
use App\Models\InstallTask;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

/**
 * INST-05e — CommissioningItem model contract: constants, fillables,
 * relationships, soft deletes.
 *
 * Red until Plan 02 ships the model.
 */
class CommissioningItemTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_constants(): void
    {
        $this->assertSame('pending', CommissioningItem::STATUS_PENDING);
        $this->assertSame('pass', CommissioningItem::STATUS_PASS);
        $this->assertSame('fail', CommissioningItem::STATUS_FAIL);
        $this->assertSame('na', CommissioningItem::STATUS_NA);
    }

    public function test_category_constants(): void
    {
        $expected = ['power', 'display', 'audio', 'vtc', 'control', 'network', 'cabling'];

        foreach ($expected as $cat) {
            $constName = 'CATEGORY_' . strtoupper($cat);
            $this->assertTrue(
                defined(CommissioningItem::class . '::' . $constName),
                "CommissioningItem::{$constName} must exist.",
            );
            $this->assertSame($cat, constant(CommissioningItem::class . '::' . $constName));
        }
    }

    public function test_mass_assignable_fields(): void
    {
        $required = [
            'install_programme_id',
            'install_task_id',
            'equipment_name',
            'room_name',
            'category',
            'status',
            'evidence_photo_path',
            'notes',
            'signed_off_by',
            'signed_off_at',
        ];

        $fillable = (new CommissioningItem())->getFillable();

        foreach ($required as $field) {
            $this->assertContains($field, $fillable, "CommissioningItem::\$fillable must include '{$field}'.");
        }
    }

    public function test_soft_delete_trait_present(): void
    {
        $traits = (new ReflectionClass(CommissioningItem::class))->getTraitNames();
        $this->assertContains(SoftDeletes::class, $traits);
    }

    public function test_programme_relationship_is_belongsTo(): void
    {
        $item = new CommissioningItem();
        $rel = $item->programme();

        $this->assertInstanceOf(BelongsTo::class, $rel);
        $this->assertSame(InstallProgramme::class, get_class($rel->getRelated()));
    }

    public function test_installTask_relationship_is_belongsTo(): void
    {
        $item = new CommissioningItem();
        $rel = $item->installTask();

        $this->assertInstanceOf(BelongsTo::class, $rel);
        $this->assertSame(InstallTask::class, get_class($rel->getRelated()));
    }
}
