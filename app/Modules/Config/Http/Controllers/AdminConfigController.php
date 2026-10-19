<?php

namespace App\Modules\Config\Http\Controllers;

use App\Models\FeatureFlag;
use App\Models\RemoteConfig;
use App\Modules\Config\Services\ConfigRegistry;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

final class AdminConfigController extends Controller
{
    public function dashboard()
    {
        return view('orbit-admin.dashboard', [
            'flags' => FeatureFlag::query()->orderBy('key')->get(),
            'configs' => RemoteConfig::query()->orderBy('key')->get(),
            'audits' => \Illuminate\Support\Facades\DB::table('admin_audit_log')->orderByDesc('created_at')->limit(8)->get(),
        ]);
    }

    public function flagsIndex()
    {
        return response()->view('orbit-admin.flags-index', [
            'flags' => FeatureFlag::query()->orderBy('key')->paginate(25),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function configsIndex()
    {
        return response()->view('orbit-admin.configs-index', [
            'configs' => RemoteConfig::query()->orderBy('key')->paginate(25),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function flagForm(?FeatureFlag $flag = null)
    {
        return view('orbit-admin.flag', [
            'flag' => $flag,
            'revisions' => $flag?->exists ? app(ConfigRegistry::class)->history('flag', $flag->key) : collect(),
        ]);
    }

    public function configForm(?RemoteConfig $config = null)
    {
        return view('orbit-admin.remote', [
            'config' => $config,
            'revisions' => $config?->exists ? app(ConfigRegistry::class)->history('remote', $config->key) : collect(),
        ]);
    }

    public function storeFlag(Request $request, ConfigRegistry $registry, ?FeatureFlag $flag = null)
    {
        $data = $request->validate([
            'key' => ['required', 'regex:/^[a-z][a-z0-9_.-]*$/D', 'max:120',
                Rule::unique('feature_flags', 'key')->ignore($flag?->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'enabled' => ['nullable', 'boolean'], 'kill_switch' => ['nullable', 'boolean'],
            'rollout_percentage' => ['required', 'integer', 'between:0,100'],
            'platforms' => ['nullable', 'array'], 'platforms.*' => ['in:ios,android,web'],
            'min_version' => ['nullable', 'regex:/^\d+\.\d+\.\d+$/D'],
            'countries' => ['nullable', 'string', 'max:500', 'regex:/^[A-Z, ]*$/D'],
            'cohorts' => ['nullable', 'string', 'max:500', 'regex:/^[A-Za-z0-9_, -]*$/D'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        if ($flag?->exists) $data['key'] = $flag->key;
        $registry->saveFlag($data, auth()->guard('admin')->user(), $flag);
        return redirect()->route('orbit.admin.dashboard')->with('status', 'Feature flag revision saved.');
    }

    public function storeConfig(Request $request, ConfigRegistry $registry, ?RemoteConfig $config = null)
    {
        $data = $request->validate([
            'key' => ['required', 'regex:/^[a-z][a-z0-9_.-]*$/D', 'max:120',
                Rule::unique('remote_configs', 'key')->ignore($config?->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'value_json' => ['required', 'string', 'max:50000'],
            'value_type' => ['required', 'in:string,number,boolean,array,object'],
            'schema_json' => ['nullable', 'string', 'max:30000'],
            'published' => ['nullable', 'boolean'], 'client_visible' => ['nullable', 'boolean'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        if ($config?->exists) $data['key'] = $config->key;
        $registry->saveConfig($data, auth()->guard('admin')->user(), $config);
        return redirect()->route('orbit.admin.dashboard')->with('status', 'Remote configuration saved.');
    }

    public function rollback(Request $request, ConfigRegistry $registry, string $type, string $key)
    {
        abort_unless(in_array($type, ['flag', 'remote'], true), 404);
        $data = $request->validate([
            'revision' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'confirm' => ['accepted'],
        ]);
        $registry->rollback($type, $key, $data['revision'], auth()->guard('admin')->user(), $data['reason']);
        return redirect()->route('orbit.admin.dashboard')->with('status', 'A new revision was created from the selected snapshot.');
    }

    public function preview(Request $request, FeatureFlag $flag, ConfigRegistry $registry)
    {
        $data = $request->validate([
            'platform' => ['required', 'in:ios,android,web'],
            'app_version' => ['required', 'regex:/^\d+\.\d+\.\d+$/'],
            'country' => ['required', 'regex:/^[A-Z]{2}$/D'],
            'cohort' => ['nullable', 'string', 'max:64'],
            'subject' => ['nullable', 'uuid'],
        ]);
        return view('orbit-admin.preview', [
            'flag' => $flag, 'context' => $data, 'eligible' => $registry->flagEnabled($flag, $data),
        ]);
    }
}
