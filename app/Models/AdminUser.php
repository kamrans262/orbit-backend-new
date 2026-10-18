<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class AdminUser extends Authenticatable
{
    use HasUuids;

    protected $table = 'admin_users';
    protected $guarded = ['id'];
    protected $hidden = ['password', 'totp_secret'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'totp_secret' => 'encrypted', 'last_login_at' => 'datetime'];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(AdminRole::class, 'admin_user_roles', 'admin_user_id', 'admin_role_id');
    }

    public function canPerform(string $permission): bool
    {
        return $this->status === 'active' && $this->roles()->get()->contains(function (AdminRole $role) use ($permission): bool {
            return in_array('*', $role->permissions ?? [], true)
                || in_array($permission, $role->permissions ?? [], true);
        });
    }
}
