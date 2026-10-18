<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class UserIdentity extends Model
{
    use HasUuids;
    protected $guarded = ['id'];
    protected function casts(): array { return ['verified_at' => 'datetime']; }
}
