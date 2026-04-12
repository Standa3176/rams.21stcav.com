<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SiteSurveyRoom extends Model
{
    protected $fillable = [
        'site_survey_id',
        'room_name',
        'room_ref',
        'floor',
        'area_type',
        'space_type',
        // Dimensions
        'room_width_m',
        'room_depth_m',
        'room_height_m',
        // Structure
        'ceiling_type',
        'ceiling_height_m',
        'wall_material',
        'floor_type',
        // AV requirements
        'av_requirements',
        'av_equipment_list',
        // Services
        'has_power',
        'has_network',
        'power_outlet_count',
        'network_port_count',
        'existing_cabling',
        'requires_additional_power',
        // Access
        'access_notes',
        'notes',
        'sort_order',
        // Completion tracking
        'is_completed',
        'completed_at',
        // PA system
        'speaker_count',
        'speaker_type',
        'speaker_mounting',
        'bg_noise_db',
        // Digital signage
        'display_size_in',
        'display_orient',
        'display_mounting',
        // Infrastructure
        'rack_unit_space',
        'cable_route_desc',
        // Upgrade / strip-out
        'existing_condition',
        'items_to_remove',
        'items_to_retain',
    ];

    protected $casts = [
        'has_power'                  => 'boolean',
        'has_network'                => 'boolean',
        'requires_additional_power'  => 'boolean',
        'is_completed'               => 'boolean',
        'completed_at'               => 'datetime',
        'room_width_m'               => 'decimal:2',
        'room_depth_m'               => 'decimal:2',
        'room_height_m'              => 'decimal:2',
        'ceiling_height_m'           => 'decimal:2',
        'power_outlet_count'         => 'integer',
        'network_port_count'         => 'integer',
        'speaker_count'              => 'integer',
        'bg_noise_db'                => 'integer',
        'display_size_in'            => 'decimal:1',
        'rack_unit_space'            => 'integer',
    ];

    public function survey(): BelongsTo
    {
        return $this->belongsTo(SiteSurvey::class, 'site_survey_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(SiteSurveyPhoto::class)->orderBy('sort_order');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(SiteSurveyRoomQuestion::class, 'site_survey_room_id')->orderBy('sort_order');
    }
}
