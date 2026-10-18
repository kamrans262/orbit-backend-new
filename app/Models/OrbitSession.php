<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class OrbitSession extends Model
{
    use HasUuids;
    protected $table = 'orbit_sessions';
    protected $guarded = ['id'];
    protected $hidden = ['refresh_token_hash'];
    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'rotated_at' => 'datetime',
            'revoked_at' => 'datetime', 'last_used_at' => 'datetime', 'risk_metadata' => 'array'];
    }
}
