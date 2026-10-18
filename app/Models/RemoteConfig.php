<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

final class RemoteConfig extends Model
{
    use HasUuids;

    protected $guarded = ['id'];
    protected function casts(): array { return ['value' => 'object', 'schema_json' => 'array', 'published' => 'boolean', 'client_visible' => 'boolean']; }
}
