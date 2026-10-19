<?php

namespace App\Modules\Profile\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\OrbitUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class ProfileSettingsController extends Controller
{
    /** Consent-safe defaults. These values do not start location sharing. */
    private const PRIVACY = [
        'location_visibility' => 'Nobody',
        'location_activity' => 'Off',
        'location_sharing_enabled' => false,
        'ghost_mode_enabled' => true,
    ];

    private const NOTIFICATIONS = [
        'push_notifications_enabled' => false,
        'ping_notifications_enabled' => false,
        'moments_notifications_enabled' => false,
        'circle_updates_enabled' => false,
        'sos_alerts_enabled' => true,
        'sound_enabled' => true,
        'vibration_enabled' => true,
    ];

    public function show(Request $request): JsonResponse
    {
        $user = $this->user($request);
        return $this->respond(['id' => $user->id, 'display_name' => $user->display_name,
            'username' => $user->username, 'bio' => $user->bio]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'display_name' => ['required', 'string', 'min:2', 'max:120'],
            'username' => ['required', 'string', 'min:3', 'max:32',
                'regex:/^[A-Za-z0-9._]+$/D',
                Rule::unique('orbit_users', 'username')->ignore($user->id)],
            'bio' => ['sometimes', 'nullable', 'string', 'max:280'],
        ]);
        // Uploads are not part of M3; never treat arbitrary client paths as URLs.
        $user->update($data);
        return $this->show($request);
    }

    public function notifications(Request $request): JsonResponse
    {
        return $this->respond($this->settings($request, 'notifications', self::NOTIFICATIONS));
    }

    public function updateNotifications(Request $request): JsonResponse
    {
        $rules = [];
        foreach (self::NOTIFICATIONS as $key => $_) {
            $rules[$key] = ['sometimes', 'required', 'boolean'];
        }
        $updates = $request->validate($rules);
        return $this->respond($this->saveSettings($request, 'notifications', self::NOTIFICATIONS, $updates));
    }

    public function privacy(Request $request): JsonResponse
    {
        return $this->respond($this->settings($request, 'privacy', self::PRIVACY));
    }

    public function updatePrivacy(Request $request): JsonResponse
    {
        $changes = $request->validate([
            'location_visibility' => ['sometimes', 'required', Rule::in(['My Circle', 'Friends', 'Selected People', 'Nobody'])],
            'location_activity' => ['sometimes', 'required', Rule::in(['Active', 'While moving', 'Off'])],
            'location_sharing_enabled' => ['sometimes', 'required', 'boolean'],
            'ghost_mode_enabled' => ['sometimes', 'required', 'boolean'],
        ]);
        return $this->respond($this->saveSettings($request, 'privacy', self::PRIVACY, $changes));
    }

    private function user(Request $request): OrbitUser
    {
        return OrbitUser::query()->whereKey($request->attributes->get('orbit_session')->user_id)
            ->where('status', 'active')->firstOrFail();
    }

    private function settings(Request $request, string $column, array $defaults): array
    {
        $record = DB::table('orbit_user_preferences')->where('user_id', $this->user($request)->id)->first();
        $stored = $record ? json_decode((string) $record->{$column}, true) : null;
        return array_replace($defaults, is_array($stored) ? array_intersect_key($stored, $defaults) : []);
    }

    private function saveSettings(Request $request, string $column, array $defaults, array $changes): array
    {
        $id = $this->user($request)->id;
        return DB::transaction(function () use ($id, $column, $defaults, $changes): array {
            $row = DB::table('orbit_user_preferences')->where('user_id', $id)
                ->lockForUpdate()->first();
            $prior = $row ? json_decode((string) $row->{$column}, true) : null;
            $saved = array_replace($defaults, is_array($prior) ? array_intersect_key($prior, $defaults) : [], $changes);
            if ($column === 'privacy' && $saved['ghost_mode_enabled']) {
                $saved['location_sharing_enabled'] = false;
            }
            if ($column === 'privacy' && $saved['location_visibility'] === 'Nobody') {
                $saved['location_sharing_enabled'] = false;
            }
            $value = json_encode($saved, JSON_THROW_ON_ERROR);
            if ($row) {
                DB::table('orbit_user_preferences')->where('user_id', $id)
                    ->update([$column => $value, 'updated_at' => now()]);
            } else {
                DB::table('orbit_user_preferences')->insert([
                    'user_id' => $id, $column => $value, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            return $saved;
        });
    }

    private function respond(array $data): JsonResponse
    {
        return response()->json(['data' => $data])->header('Cache-Control', 'no-store');
    }
}
