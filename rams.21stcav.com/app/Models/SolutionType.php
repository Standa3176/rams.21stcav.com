<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class SolutionType extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'survey_checklist',
        'install_method',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Returns the survey checklist as an array of trimmed lines.
     */
    public function checklistLines(): array
    {
        if (! $this->survey_checklist) {
            return [];
        }
        return array_values(array_filter(
            array_map('trim', explode("\n", $this->survey_checklist))
        ));
    }

    /**
     * Returns the install method as an array of trimmed lines.
     */
    public function methodLines(): array
    {
        if (! $this->install_method) {
            return [];
        }
        return array_values(array_filter(
            array_map('trim', explode("\n", $this->install_method))
        ));
    }
}
