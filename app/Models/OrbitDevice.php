<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class OrbitDevice extends Model
{
    use HasUuids;
    protected $table = 'devices';
    protected $guarded = ['id'];
    protected $hidden = ['push_token_enc'];
    protected function casts(): array
    {
        return ['revoked_at' => 'datetime', 'approved_at' => 'datetime', 'last_seen_at' => 'datetime',
            'push_token_enc' => 'encrypted'];
    }
}
