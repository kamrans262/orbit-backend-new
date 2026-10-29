<?php

namespace Tests\Feature;

use App\Models\OrbitDevice;
use App\Models\OrbitUser;
use App\Modules\Identity\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrbitPingCircleTest extends TestCase
{
    use RefreshDatabase;

    private function identity(string $username): array
    {
        $user = OrbitUser::create(['display_name' => $username,
            'username' => Str::lower($username).Str::lower(Str::random(5)),
            'status' => 'active']);
        $device = OrbitDevice::create(['user_id' => $user->id,
            'device_public_id' => (string) Str::uuid(), 'name' => 'Test',
            'platform' => 'android', 'public_key' => base64_encode(random_bytes(32)),
            'trust_state' => 'trusted', 'attestation_state' => 'verified',
            'approved_at' => now()]);
        return [$user, app(TokenService::class)->issue($user, $device, null)['access_token']];
    }

    public function test_circle_pings_require_reviewed_subject_grant_and_active_membership(): void
    {
        config(['orbit_ping.enabled' => true, 'orbit_circle.enabled' => true,
            'orbit_circle.consumer_enabled.ping' => true]);
        [$sender, $token] = $this->identity('sender');
        [$recipient] = $this->identity('recipient');
        $circle = (string) Str::uuid();
        DB::table('circles')->insert([
            'id' => $circle, 'owner_id' => $sender->id, 'name' => 'Private Circle',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([$sender, $recipient] as $person) {
            DB::table('circle_members')->insert([
                'circle_id' => $circle, 'user_id' => $person->id,
                'role' => $person->id === $sender->id ? 'owner' : 'member',
                'joined_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('orbit_user_preferences')->insert([
            'user_id' => $recipient->id,
            'privacy' => json_encode(['ping_eligibility' => 'My Circle']),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $payload = ['client_id' => (string) Str::uuid(),
            'recipient_id' => $recipient->id, 'type' => 'hello',
            'context' => ['type' => 'circle', 'id' => $circle]];
        $this->withToken($token)->postJson('/api/v1/pings', $payload)->assertNotFound();
        DB::table('circle_permissions')->insert([
            'circle_id' => $circle, 'scope_key' => 'user:'.$recipient->id,
            'subject_user_id' => $recipient->id, 'permission_key' => 'ping',
            'enabled' => true, 'value_json' => json_encode([
                'reviewed' => true, 'audience' => 'circle', 'duration' => 'persistent',
            ]), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $id = $this->withToken($token)->postJson('/api/v1/pings', $payload)
            ->assertCreated()->json('data.id');
        DB::table('circles')->where('id', $circle)->update(['archived_at' => now()]);
        $this->withToken($token)->getJson('/api/v1/pings/'.$id)->assertNotFound();
        $this->withToken($token)->postJson('/api/v1/pings',
            array_replace($payload, ['client_id' => (string) Str::uuid()]))->assertNotFound();
    }
}
