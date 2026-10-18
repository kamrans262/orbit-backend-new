<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

final class FeatureFlag extends Model
{
    use HasUuids;

    protected $guarded = ['id'];
    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'kill_switch' => 'boolean', 'rollout_percentage' => 'integer',
            'platforms' => 'array', 'countries' => 'array', 'cohorts' => 'array'];
    }
}
