<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Canonical record of UK manufacturer support details. Seeded with the
 * 14 brands the O&M generator currently encounters; extensible at runtime
 * via ManufacturerSupportResolverService when new brands appear.
 *
 * Phase 2 of the Tier 1 O&M Manual upgrade.
 */
class Manufacturer extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'support_phone',
        'support_email',
        'support_url',
        'warranty_years',
        'logo_path',
    ];

    protected $casts = [
        'warranty_years' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $model): void {
            if (empty($model->slug) && ! empty($model->name)) {
                $model->slug = Str::slug($model->name);
            }
        });
    }

    /**
     * Case- and punctuation-insensitive lookup. "Q-SYS", "q sys", "QSYS"
     * all resolve to the same slug, matching the seeded record.
     */
    public static function findByName(string $name): ?self
    {
        $slug = Str::slug($name);
        if ($slug === '') {
            return null;
        }
        return static::where('slug', $slug)->first();
    }
}
