<?php

namespace Tests\Feature;

use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Models\OrbitDevice;
use App\Models\OrbitUser;
use App\Modules\Config\Services\Totp;
use App\Modules\Identity\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrbitPingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['orbit_ping.enabled' => true, 'orbit_ping.sender_per_minute' => 12,
            'orbit_ping.pair_per_hour' => 6]);
    }

    private function person(string $name): array
    {
        $user = OrbitUser::create([
            'display_name' => $name, 'username' => Str::lower(Str::random(14)),
            'status' => 'active',
        ]);
        $device = OrbitDevice::create([
            'user_id' => $user->id, 'device_public_id' => (string) Str::uuid(),
            'name' => 'Test device', 'platform' => 'android',
            'public_key' => base64_encode(random_bytes(32)), 'trust_state' => 'trusted',
            'attestation_state' => 'verified', 'approved_at' => now(),
        ]);
        $token = app(TokenService::class)->issue($user, $device, null)['access_token'];
        return [$user, $token, $device];
    }

    private function consent(OrbitUser $user, string $audience, bool $receipts = false): void
    {
        DB::table('orbit_user_preferences')->updateOrInsert(['user_id' => $user->id], [
            'privacy' => json_encode(['ping_eligibility' => $audience,
                'read_receipts_enabled' => $receipts]),
            'notifications' => json_encode(['ping_notifications_enabled' => false]),
            'updated_at' => now(), 'created_at' => now(),
        ]);
    }

    private function friends(OrbitUser $a, OrbitUser $b): void
    {
        $ids = [$a->id, $b->id];
        sort($ids, SORT_STRING);
        DB::table('friendships')->insert([
            'user_low_id' => $ids[0], 'user_high_id' => $ids[1], 'created_at' => now(),
        ]);
    }

    private function payload(OrbitUser $recipient, ?string $clientId = null): array
    {
        return ['client_id' => $clientId ?? (string) Str::uuid(),
            'recipient_id' => $recipient->id,
            'context' => ['type' => 'friend'], 'type' => 'hello'];
    }

    public function test_only_trusted_devices_and_opted_in_recipients_can_receive_pings(): void
    {
        [$a, $ta, $device] = $this->person('A');
        [$b] = $this->person('B');
        $this->friends($a, $b);
        $payload = $this->payload($b);
        $this->postJson('/api/v1/pings', $payload)->assertUnauthorized();
        $this->withToken($ta)->postJson('/api/v1/pings', $payload)
            ->assertNotFound()->assertHeader('X-Orbit-Request-ID');
        $this->consent($b, 'Friends');
        $this->withToken($ta)->postJson('/api/v1/pings', $payload)->assertCreated()
            ->assertHeader('X-Orbit-Request-ID')->assertJsonPath('data.status', 'accepted');
        $this->assertDatabaseHas('ping_operational_events',[
            'action'=>'send','status_code'=>404,'reason_code'=>'unavailable',
        ]);
        $device->update(['trust_state' => 'pending']);
        $this->withToken($ta)->getJson('/api/v1/pings/outbox')->assertForbidden();
    }

    public function test_idempotency_and_payload_mismatch_are_enforced(): void
    {
        [$a, $ta] = $this->person('A');
        [$b] = $this->person('B');
        $this->friends($a, $b);
        $this->consent($b, 'Friends');
        $payload = $this->payload($b);
        $id = $this->withToken($ta)->postJson('/api/v1/pings', $payload)
            ->assertCreated()->json('data.id');
        $this->withToken($ta)->postJson('/api/v1/pings', $payload)
            ->assertOk()->assertJsonPath('data.id', $id);
        $this->withToken($ta)->postJson('/api/v1/pings',
            array_replace($payload, ['type' => 'love']))->assertStatus(409);
        $this->assertDatabaseCount('pings', 1);
    }

    public function test_delivered_seen_and_hey_reply_are_recipient_owned_and_monotonic(): void
    {
        [$a, $ta] = $this->person('A');
        [$b, $tb] = $this->person('B');
        [$c, $tc] = $this->person('C');
        $this->friends($a, $b);
        $this->consent($b, 'Friends', false);
        $id = $this->withToken($ta)->postJson('/api/v1/pings', $this->payload($b))
            ->assertCreated()->json('data.id');
        $this->withToken($ta)->postJson('/api/v1/pings/'.$id.'/delivered')->assertNotFound();
        $this->withToken($tc)->getJson('/api/v1/pings/'.$id)->assertNotFound();
        $this->withToken($tb)->postJson('/api/v1/pings/'.$id.'/delivered')
            ->assertOk()->assertJsonPath('data.status', 'delivered');
        $this->withToken($tb)->postJson('/api/v1/pings/'.$id.'/delivered')->assertOk();
        $this->withToken($tb)->postJson('/api/v1/pings/'.$id.'/seen')
            ->assertOk()->assertJsonPath('data.status', 'seen');
        $this->withToken($ta)->getJson('/api/v1/pings/'.$id)
            ->assertOk()->assertJsonPath('data.status', 'delivered')
            ->assertJsonPath('data.seen_at', null);
        $this->consent($b, 'Friends', true);
        $this->withToken($ta)->getJson('/api/v1/pings/'.$id)
            ->assertOk()->assertJsonPath('data.status', 'seen');
        $reply = ['type' => 'hey', 'client_reply_id' => (string) Str::uuid()];
        $this->withToken($tb)->postJson('/api/v1/pings/'.$id.'/respond', $reply)
            ->assertOk()->assertJsonPath('data.type', 'hey');
        $this->withToken($tb)->postJson('/api/v1/pings/'.$id.'/respond', $reply)->assertOk();
        $this->withToken($tb)->postJson('/api/v1/pings/'.$id.'/respond', [
            'type' => 'hey', 'client_reply_id' => (string) Str::uuid(),
        ])->assertStatus(409);
        $this->assertDatabaseCount('ping_receipts', 2);
        $this->assertDatabaseCount('ping_responses', 1);
    }

    public function test_block_or_privacy_revocation_hides_existing_pings(): void
    {
        [$a, $ta] = $this->person('A');
        [$b, $tb] = $this->person('B');
        $this->friends($a, $b);
        $this->consent($b, 'Friends');
        $id = $this->withToken($ta)->postJson('/api/v1/pings', $this->payload($b))
            ->assertCreated()->json('data.id');
        DB::table('blocks')->insert([
            'blocker_id' => $b->id, 'blocked_id' => $a->id, 'created_at' => now(),
        ]);
        $this->withToken($tb)->getJson('/api/v1/pings/inbox')
            ->assertOk()->assertJsonCount(0, 'data.items');
        $this->withToken($ta)->getJson('/api/v1/pings/'.$id)->assertNotFound();
        $this->withToken($ta)->postJson('/api/v1/pings', $this->payload($b))->assertNotFound();
        DB::table('blocks')->delete();
        $this->consent($b, 'Nobody');
        $this->withToken($ta)->getJson('/api/v1/pings/'.$id)->assertNotFound();
    }

    public function test_custom_length_and_rate_limit_are_enforced(): void
    {
        [$a, $ta] = $this->person('A');
        [$b] = $this->person('B');
        $this->friends($a, $b);
        $this->consent($b, 'Friends');
        $this->withToken($ta)->postJson('/api/v1/pings', array_replace(
            $this->payload($b), ['type' => 'custom', 'custom_text' => str_repeat('a', 161)]
        ))->assertUnprocessable();
        $this->withToken($ta)->postJson('/api/v1/pings', array_replace(
            $this->payload($b), ['type' => 'custom', 'custom_text' => '  ']
        ))->assertUnprocessable();
        for ($i = 0; $i < 6; $i++) {
            $this->withToken($ta)->postJson('/api/v1/pings', $this->payload($b))
                ->assertCreated();
        }
        $this->withToken($ta)->postJson('/api/v1/pings', $this->payload($b))
            ->assertStatus(429);
    }

    public function test_disabled_sends_do_not_erase_history(): void
    {
        [$a, $ta] = $this->person('A');
        [$b] = $this->person('B');
        $this->friends($a, $b);
        $this->consent($b, 'Friends');
        $this->withToken($ta)->postJson('/api/v1/pings', $this->payload($b))->assertCreated();
        config(['orbit_ping.enabled' => false]);
        $this->withToken($ta)->postJson('/api/v1/pings', $this->payload($b))
            ->assertStatus(503);
        $this->withToken($ta)->getJson('/api/v1/pings/outbox')
            ->assertOk()->assertJsonCount(1, 'data.items');
        $this->withToken($ta)->getJson('/api/v1/pings/capabilities')
            ->assertOk()->assertJsonPath('data.available', false);
    }

    public function test_ping_operations_is_role_restricted_and_content_free(): void
    {
        $role = AdminRole::create(['name' => 'Ping operator', 'permissions' => ['config.manage']]);
        $admin = AdminUser::create(['name' => 'Operations', 'email' => 'ping-admin@example.test',
            'password' => 'StrongPassword123!', 'totp_secret' => (new Totp())->generateSecret(),
            'status' => 'active']);
        $admin->roles()->attach($role->id);
        $this->actingAs($admin, 'admin')->get('/admin/ping')->assertOk()
            ->assertSee('Ping Operations')->assertSee('Accepted requests')
            ->assertDontSee('custom_text');
        $role->update(['permissions' => ['users.read']]);
        $this->actingAs($admin, 'admin')->get('/admin/ping')->assertForbidden();
    }
}
