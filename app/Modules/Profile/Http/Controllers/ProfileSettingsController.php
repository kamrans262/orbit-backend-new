<?php

namespace App\Modules\Profile\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\OrbitUser;
use App\Modules\Identity\Services\TokenService;
use App\Modules\Media\Services\MediaReferenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

final class ProfileSettingsController extends Controller
{
    /** Consent-safe defaults. These values do not start location sharing. */
    private const PRIVACY = [
        'location_visibility' => 'Nobody',
        'location_activity' => 'Off',
        'location_sharing_enabled' => false,
        'ghost_mode_enabled' => true,
        // Future consumers must enforce these policies server-side, never only in UI.
        'presence_visibility' => 'Nobody',
        'activity_visibility' => 'Nobody',
        'ping_eligibility' => 'Nobody',
        'message_eligibility' => 'Nobody',
        'moment_default_audience' => 'Nobody',
        'read_receipts_enabled' => false,
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
        // Return only an opaque identifier. Never expose a public image URL,
        // plaintext thumbnail, or key envelope in the M3 profile response.
        $avatar = config('orbit_media.enabled') === true
            && Schema::hasColumn('orbit_users', 'avatar_media_id')
                ? $user->avatar_media_id : null;
        return $this->respond(['id' => $user->id, 'display_name' => $user->display_name,
            'username' => $user->username, 'bio' => $user->bio,
            'avatar_media_id' => $avatar]);
    }

    public function update(Request $request, MediaReferenceService $references): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'display_name' => ['required', 'string', 'min:2', 'max:120'],
            'username' => ['required', 'string', 'min:3', 'max:32',
                'regex:/^[A-Za-z0-9._]+$/D',
                Rule::unique('orbit_users', 'username')->ignore($user->id)],
            'bio' => ['sometimes', 'nullable', 'string', 'max:280'],
            // Opaque M6 media UUID only; never accept a path, base64 body or URL.
            'avatar_media_id' => ['sometimes', 'nullable', 'uuid'],
        ]);
        $hasAvatar = array_key_exists('avatar_media_id', $data);
        $avatar = $hasAvatar ? $data['avatar_media_id'] : null;
        unset($data['avatar_media_id']);
        if ($hasAvatar) {
            abort_unless(config('orbit_media.enabled') === true
                && Schema::hasColumn('orbit_users','avatar_media_id'),503,
                'Private profile media is not enabled.');
            if ($avatar !== null) {
                // Even the owner cannot attach ciphertext without a verified
                // original-key envelope for the current trusted device.
                $device = $request->attributes->get('orbit_session')->device_id;
                $allowed = DB::table('media_objects as m')
                    ->join('media_device_key_envelopes as e','e.media_id','=','m.id')
                    ->join('media_audience_grants as g','g.id','=','e.grant_id')
                    ->where('m.id',$avatar)->where('m.owner_user_id',$user->id)
                    ->where('m.purpose','avatar')->where('m.kind','image')
                    ->where('m.state','ready')->whereNull('m.deleted_at')
                    ->where('e.recipient_device_id',$device)
                    ->where('e.variant','original')->whereNull('e.revoked_at')
                    ->where('g.recipient_user_id',$user->id)
                    ->where('g.audience_kind','only_me')->whereNull('g.revoked_at')
                    ->where(fn($q)=>$q->whereNull('g.expires_at')
                        ->orWhere('g.expires_at','>',now()))
                    ->exists();
                abort_unless($allowed,404,'Private avatar is unavailable.');
            }
        }
        $data['username'] = strtolower($data['username']);
        $session = $request->attributes->get('orbit_session');
        DB::transaction(function () use ($user,$session,$data,$hasAvatar,$avatar,$references): void {
            $locked = OrbitUser::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $locked->fill($data);
            $changedFields = array_intersect(array_keys($locked->getDirty()),
                ['display_name','username','bio']);
            $oldAvatar = $hasAvatar ? $locked->avatar_media_id : null;
            if ($hasAvatar && $avatar !== $oldAvatar) {
                if ($avatar !== null) {
                    $references->attach($avatar,$locked->id,'avatar',$locked->id);
                }
                $locked->avatar_media_id = $avatar;
            }
            $locked->save();
            if ($hasAvatar && $avatar !== $oldAvatar) {
                if ($oldAvatar !== null) {
                    $references->release($oldAvatar,$locked->id,'avatar',$locked->id);
                }
                $changedFields[]='avatar_media_id';
            }
            foreach ($changedFields as $field) {
                // Only names of changed fields in logs, never media IDs.
                app(TokenService::class)->event($locked->id,$session->device_id,
                    'settings.profile_updated',$field);
            }
        });
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
            'presence_visibility' => ['sometimes', 'required', Rule::in(['My Circle', 'Friends', 'Nobody'])],
            'activity_visibility' => ['sometimes', 'required', Rule::in(['My Circle', 'Friends', 'Nobody'])],
            'ping_eligibility' => ['sometimes', 'required', Rule::in(['My Circle', 'Friends', 'Nobody'])],
            'message_eligibility' => ['sometimes', 'required', Rule::in(['My Circle', 'Friends', 'Nobody'])],
            'moment_default_audience' => ['sometimes', 'required', Rule::in(['My Circle', 'Selected People', 'Nobody'])],
            'read_receipts_enabled' => ['sometimes', 'required', 'boolean'],
        ]);
        $saved=$this->saveSettings($request, 'privacy', self::PRIVACY, $changes);
        if (config('orbit_location.enabled') === true) {
            $owner=(string)$this->user($request)->id;
            // Ghost and any global opt-out permanently revoke prior GPS grants;
            // toggling sharing on later must NOT resurrect old consent.
            if ($saved['ghost_mode_enabled'] === true
                || $saved['location_sharing_enabled'] !== true
                || $saved['location_visibility'] === 'Nobody'
                || $saved['location_activity'] === 'Off') {
                app(\App\Modules\Location\Services\LocationService::class)->revoke($owner);
            }
            $publisher=app(\App\Modules\Location\Services\LocationRealtimePublisher::class);
            $publisher->invalidate($owner);
            $publisher->invalidateViewer($owner);
        }
        return $this->respond($saved);
    }

    /** Device-independent locale and IANA timezone preferences. */
    public function general(Request $request): JsonResponse
    {
        $row = DB::table('orbit_user_preferences')->where('user_id', $this->user($request)->id)->first();
        return $this->respond(['locale' => $row?->locale ?? 'en',
            'timezone' => $row?->timezone ?? 'UTC']);
    }

    public function updateGeneral(Request $request, TokenService $tokens): JsonResponse
    {
        $data = $request->validate([
            'locale' => ['sometimes', 'required', 'string', 'max:20', 'regex:/^[a-z]{2,3}(?:-[A-Z]{2})?$/D'],
            'timezone' => ['sometimes', 'required', 'timezone:all'],
        ]);
        $session = $request->attributes->get('orbit_session');
        DB::transaction(function () use ($session, $data, $tokens): void {
            DB::table('orbit_user_preferences')->insertOrIgnore([
                'user_id' => $session->user_id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($data !== []) {
                DB::table('orbit_user_preferences')->where('user_id', $session->user_id)
                    ->update([...$data, 'updated_at' => now()]);
                $tokens->event($session->user_id, $session->device_id,
                    'settings.general_updated', implode(',', array_keys($data)));
            }
        });
        return $this->general($request);
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
        return DB::transaction(function () use ($id, $column, $defaults, $changes, $request): array {
            // Two rapid first-time saves must not race to insert the same user.
            DB::table('orbit_user_preferences')->insertOrIgnore([
                'user_id' => $id, 'created_at' => now(), 'updated_at' => now(),
            ]);
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
            if ($changes !== []) {
                // A privacy change must invalidate previously authorized
                // social views before any future realtime/cache consumer
                // continues using stale relationship permissions.
                if ($column === 'privacy') {
                    if (config('orbit_presence.enabled') === true
                        && config('orbit_presence.realtime_enabled') === true) {
                        DB::afterCommit(static function () use ($id): void {
                            app(\App\Modules\Presence\Services\PresenceRealtimePublisher::class)
                                ->safelyClearUser($id);
                        });
                    }
                    DB::table('social_graph_revisions')->insertOrIgnore([
                        'user_id' => $id, 'revision' => 0, 'updated_at' => now(),
                    ]);
                    DB::table('social_graph_revisions')->where('user_id', $id)
                        ->increment('revision', 1, ['updated_at' => now()]);
                    DB::table('social_graph_outbox')->insert([
                        'id' => (string) \Illuminate\Support\Str::uuid7(),
                        'actor_id' => $id, 'subject_id' => null,
                        'event_type' => 'social.privacy_changed', 'created_at' => now(),
                    ]);
                }
                $session = $request->attributes->get('orbit_session');
                foreach (array_keys($changes) as $changedKey) {
                    // Single-key reason codes stay within the 80-character column.
                    // Never log secret values, coordinates or recipient identities.
                    app(TokenService::class)->event($id, $session->device_id,
                        $column === 'privacy' ? 'settings.privacy_updated' : 'settings.notifications_updated',
                        $changedKey);
                }
            }
            return $saved;
        });
    }

    private function respond(array $data): JsonResponse
    {
        return response()->json(['data' => $data])->header('Cache-Control', 'no-store');
    }
}
