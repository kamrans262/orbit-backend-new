<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class OrbitUser extends Model
{
    use HasUuids;
    protected $table = 'orbit_users';
    protected $guarded = ['id'];
    protected $hidden = ['password_hash'];
    protected function casts(): array { return ['deletion_requested_at' => 'datetime']; }
    public function identities(): HasMany { return $this->hasMany(UserIdentity::class, 'user_id'); }
    public function devices(): HasMany { return $this->hasMany(OrbitDevice::class, 'user_id'); }
    public function sessions(): HasMany { return $this->hasMany(OrbitSession::class, 'user_id'); }
}
