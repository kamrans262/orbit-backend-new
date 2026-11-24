<?php

namespace App\Modules\Config\Services;

use App\Models\AdminUser;
use App\Models\FeatureFlag;
use App\Models\RemoteConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ConfigRegistry
{
    public function flagEnabled(FeatureFlag $flag, array $context): bool
    {
        if (!$flag->enabled || $flag->kill_switch || $flag->rollout_percentage <= 0) return false;
        foreach (['platforms' => 'platform', 'countries' => 'country', 'cohorts' => 'cohort'] as $field => $attribute) {
            $allowed = $flag->{$field} ?? [];
            if ($allowed && !in_array($context[$attribute] ?? '', $allowed, true)) return false;
        }
        if ($flag->min_version && version_compare($context['app_version'] ?? '0.0.0', $flag->min_version, '<')) return false;
        if ($flag->rollout_percentage >= 100) return true;
        // UI rollout only: clients cannot use this decision to bypass server authorization/entitlements.
        $subject = (string) ($context['subject'] ?? '');
        if ($subject === '') return false;
        $bucket = hexdec(substr(hash_hmac('sha256', $flag->key.'|'.$subject, config('app.key')), 0, 8)) % 10000;
        return $bucket < $flag->rollout_percentage * 100;
    }

    public function bootstrap(array $context): array
    {
        $flags = [];
        foreach (FeatureFlag::query()->orderBy('key')->get() as $flag) {
            $flags[$flag->key] = $this->flagEnabled($flag, $context);
        }
        $configs = RemoteConfig::query()->where('published', true)->where('client_visible', true)
            ->whereNotIn('key',['camera.operations','relay.operations','m24.operational_limits','m24.camera_tools'])->where('key','not like','m24.operations.%')->orderBy('key')->pluck('value', 'key')->all();
        $camera=app(\App\Modules\Camera\Services\CameraPolicy::class)->publicHints();
        if($camera!==null)$configs['camera.operations']=$camera;
        $operations=app(OperationalControls::class)->publicHints();
        if($operations!==null)$configs['m24.operational_limits']=$operations;
        $tools=app(\App\Modules\Camera\Services\CameraToolCatalog::class)->publicHints();
        if($tools!==null)$configs['m24.camera_tools']=$tools;
        return ['version' => 'v1', 'flags' => $flags, 'config' => $configs, 'generated_at' => now()->toIso8601String()];
    }

    public function saveFlag(array $input, AdminUser $actor, ?FeatureFlag $flag = null): FeatureFlag
    {
        return DB::transaction(function () use ($input, $actor, $flag): FeatureFlag {
            $flag ??= new FeatureFlag();
            if ($flag->exists) $flag = FeatureFlag::query()->whereKey($flag->id)->lockForUpdate()->firstOrFail();
            if(isset($input['expected_version']))abort_unless((int)$input['expected_version']===($flag->exists?(int)$flag->version:0),409,'Feature flag changed. Reload and review the latest revision.');
            $before = $flag->exists ? $flag->toArray() : null;
            $flag->fill([
                'key' => $input['key'],
                'description' => $input['description'] ?? null,
                'enabled' => (bool) ($input['enabled'] ?? false),
                'kill_switch' => (bool) ($input['kill_switch'] ?? false),
                'rollout_percentage' => (int) $input['rollout_percentage'],
                'platforms' => $input['platforms'] ?? [],
                'min_version' => $input['min_version'] ?? null,
                'countries' => $this->tokens($input['countries'] ?? ''),
                'cohorts' => $this->tokens($input['cohorts'] ?? ''),
                'version' => $flag->exists ? $flag->version + 1 : 1,
            ]);
            $flag->save();
            $this->record('flag', $flag->key, $flag->version, $before, $flag->toArray(), $actor, $input['reason']);
            return $flag;
        });
    }

    public function saveConfig(array $input, AdminUser $actor, ?RemoteConfig $config = null): RemoteConfig
    {
        return DB::transaction(function () use ($input, $actor, $config): RemoteConfig {
            $config ??= new RemoteConfig();
            if ($config->exists) $config = RemoteConfig::query()->whereKey($config->id)->lockForUpdate()->firstOrFail();
            if(isset($input['expected_version']))abort_unless((int)$input['expected_version']===($config->exists?(int)$config->version:0),409,'Configuration changed. Reload and review the latest revision.');
            $before = $config->exists ? $config->toArray() : null;
            $type = $input['value_type'];
            $schemaJson = $input['schema_json'] ?? json_encode(['type' => $type], JSON_THROW_ON_ERROR);
            [$value, $schema] = app(JsonSchemaGuard::class)->check($input['value_json'], $schemaJson, $type);
            $config->fill([
                'key' => $input['key'],
                'description' => $input['description'] ?? null,
                'value' => $value,
                'schema_json' => $schema,
                'value_type' => $type,
                'published' => (bool) ($input['published'] ?? false),
                'client_visible' => (bool) ($input['client_visible'] ?? false),
                'version' => $config->exists ? $config->version + 1 : 1,
            ]);
            $config->save();
            $this->record('remote', $config->key, $config->version, $before, $config->toArray(), $actor, $input['reason']);
            return $config;
        });
    }

    public function rollback(string $type, string $key, string $revisionId, AdminUser $actor, string $reason, ?int $expectedVersion=null): void
    {
        DB::transaction(function () use ($type, $key, $revisionId, $actor, $reason, $expectedVersion): void {
            $model = $type === 'flag' ? FeatureFlag::class : RemoteConfig::class;
            $record = $model::query()->where('key', $key)->lockForUpdate()->firstOrFail();
            if($expectedVersion!==null)abort_unless((int)$record->version===$expectedVersion,409,'Configuration changed. Reload before restoring.');
            $revision = DB::table('config_revisions')->where('id', $revisionId)
                ->where('resource_key', $key)->where('resource_type', $type)->first();
            if (!$revision) abort(404);
            $snapshot = json_decode($revision->snapshot, true);
            $before = $record->toArray();
            $fields = $type === 'flag'
                ? ['description', 'enabled', 'kill_switch', 'rollout_percentage', 'platforms', 'min_version', 'countries', 'cohorts']
                : ['description', 'value', 'schema_json', 'value_type', 'published', 'client_visible'];
            $record->fill(collect($snapshot)->only($fields)->all());
            $record->version += 1;
            $record->save();
            $this->record($type, $key, $record->version, $before, $record->toArray(), $actor, $reason, 'rollback');
        });
    }

    public function history(string $type, string $key)
    {
        return DB::table('config_revisions')->where('resource_type', $type)
            ->where('resource_key', $key)->orderByDesc('version')->limit(30)->get();
    }

    private function matchesType(mixed $value, string $type, string $json): bool
    {
        return match ($type) {
            'string' => is_string($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'array' => is_array($value) && str_starts_with($json, '['),
            'object' => is_array($value) && str_starts_with($json, '{'),
            default => false,
        };
    }

    private function tokens(string $csv): array
    {
        return array_values(array_unique(array_filter(array_map('trim', explode(',', $csv)))));
    }

    private function record(string $type, string $key, int $version, ?array $before, array $after, AdminUser $actor, string $reason, string $action = 'publish'): void
    {
        DB::table('config_revisions')->insert([
            'id' => (string) Str::uuid7(), 'resource_type' => $type, 'resource_key' => $key,
            'version' => $version, 'snapshot' => json_encode($after, JSON_THROW_ON_ERROR),
            'actor_id' => $actor->id, 'reason' => $reason, 'created_at' => now(),
        ]);
        $last = DB::table('admin_audit_log')->orderByDesc('created_at')->lockForUpdate()->first();
        $prev = $last?->event_hash;
        $beforeHash = $before === null ? null : hash('sha256', json_encode($before, JSON_THROW_ON_ERROR));
        $afterHash = hash('sha256', json_encode($after, JSON_THROW_ON_ERROR));
        $event = hash('sha256', implode('|', [$prev ?? '', $actor->id, $action, $type, $key, $beforeHash ?? '', $afterHash, $reason, $version]));
        DB::table('admin_audit_log')->insert([
            'id' => (string) Str::uuid7(), 'actor_id' => $actor->id,
            'action' => 'config.'.$action, 'target' => $type.':'.$key, 'reason' => $reason,
            'request_id' => request()->header('X-Request-ID', (string) Str::uuid7()),
            'ip_address' => request()->ip(), 'before_hash' => $beforeHash,
            'after_hash' => $afterHash, 'previous_hash' => $prev,
            'event_hash' => $event, 'created_at' => now(),
        ]);
    }
}
