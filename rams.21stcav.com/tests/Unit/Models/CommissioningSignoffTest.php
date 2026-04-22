<?php

namespace Tests\Unit\Models;

use App\Models\CommissioningSignoff;
use App\Models\InstallProgramme;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

/**
 * INST-05i — CommissioningSignoff model contract.
 * Red until Plan 02 ships the model.
 */
class CommissioningSignoffTest extends TestCase
{
    use RefreshDatabase;

    public function test_fillable_fields(): void
    {
        $required = [
            'install_programme_id',
            'client_name',
            'client_role',
            'client_company',
            'signature_png_base64',
            'certification_text',
            'snagging_pdf_path',
            'signed_at',
            'signed_off_engineer_id',
        ];

        $fillable = (new CommissioningSignoff())->getFillable();

        foreach ($required as $field) {
            $this->assertContains($field, $fillable, "CommissioningSignoff::\$fillable must include '{$field}'.");
        }
    }

    public function test_casts_signed_at_to_datetime(): void
    {
        $signoff = new CommissioningSignoff();
        $casts = $signoff->getCasts();

        $this->assertArrayHasKey('signed_at', $casts);
        $this->assertSame('datetime', $casts['signed_at']);
    }

    public function test_programme_relationship_is_belongsTo(): void
    {
        $signoff = new CommissioningSignoff();
        $rel = $signoff->programme();

        $this->assertInstanceOf(BelongsTo::class, $rel);
        $this->assertSame(InstallProgramme::class, get_class($rel->getRelated()));
    }

    public function test_engineer_relationship_is_belongsTo_users(): void
    {
        $signoff = new CommissioningSignoff();
        $rel = $signoff->engineer();

        $this->assertInstanceOf(BelongsTo::class, $rel);
        $this->assertSame(User::class, get_class($rel->getRelated()));
    }

    public function test_no_soft_deletes(): void
    {
        $traits = (new ReflectionClass(CommissioningSignoff::class))->getTraitNames();

        $this->assertNotContains(
            SoftDeletes::class,
            $traits,
            'CommissioningSignoff must NOT use SoftDeletes — INST-05i requires the record to be permanent.',
        );
    }
}
