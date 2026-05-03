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
        'network_ssid',
        'network_vlan',
        'network_switch_port',
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
        'cable_route_from',
        'cable_route_to',
        'is_rack_room',
        'projection_throw_m',
        'viewing_distance_m',
        // Upgrade / strip-out
        'existing_condition',
        'items_to_remove',
        'items_to_retain',
        // Completion tracking (extended)
        'engineer_confirmed',
        'engineer_signature_name',
        // Wizard step fields
        'work_type',
        'access_issues',
        'working_at_height',
        'client_present',
        'hs_flags',
        'constraints_data',
        // Engineer-feedback room-level additions (quick task 260503-rgg)
        'mounting_heights',
        'work_at_height_methods',
        'cable_routes',
        'wall_construction',
        'wall_needs_reinforcement',
        'wall_needs_chase_out',
        'wall_needs_conduit',
        'table_info',
        'floor_box_info',
        'brackets_required',
    ];

    protected $casts = [
        'has_power'                  => 'boolean',
        'has_network'                => 'boolean',
        'requires_additional_power'  => 'boolean',
        'is_completed'               => 'boolean',
        'completed_at'               => 'datetime',
        'is_rack_room'               => 'boolean',
        'engineer_confirmed'         => 'boolean',
        'access_issues'              => 'boolean',
        'working_at_height'          => 'boolean',
        'client_present'             => 'boolean',
        'hs_flags'                   => 'array',
        'constraints_data'           => 'array',
        'projection_throw_m'         => 'decimal:2',
        'viewing_distance_m'         => 'decimal:2',
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
        // Engineer-feedback room-level additions (quick task 260503-rgg)
        'mounting_heights'           => 'array',
        'work_at_height_methods'     => 'array',
        'cable_routes'               => 'array',
        'wall_construction'          => 'array',
        'wall_needs_reinforcement'   => 'boolean',
        'wall_needs_chase_out'       => 'boolean',
        'wall_needs_conduit'         => 'boolean',
        'table_info'                 => 'array',
        'floor_box_info'             => 'array',
        'brackets_required'          => 'array',
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
