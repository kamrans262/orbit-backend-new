<?php
namespace Tests\Feature;

use App\Models\OrbitUser;
use App\Models\OrbitDevice;
use App\Modules\Identity\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrbitM10RealtimeTicketTest extends TestCase
{
    use RefreshDatabase;

    public function test_location_realtime_requires_verified_gateway_and_trusted_device(): void
    {
        config()->set('orbit_location.enabled',true);
        $user=OrbitUser::create(['display_name'=>'Realtime Test',
            'username'=>'realtime_'.Str::lower(Str::random(9)),'status'=>'active']);
        $device=OrbitDevice::create(['user_id'=>$user->id,
            'device_public_id'=>(string)Str::uuid(),'name'=>'Test phone',
            'platform'=>'android','public_key'=>base64_encode(random_bytes(32)),
            'trust_state'=>'trusted','attestation_state'=>'verified',
            'approved_at'=>now()]);
        $token=app(TokenService::class)->issue($user,$device,null)['access_token'];
        $this->withToken($token)->postJson('/api/v1/location/realtime/ticket')
            ->assertNotFound();
        config()->set('orbit_location.realtime_enabled',true);
        config()->set('orbit_location.realtime_verified',true);
        config()->set('orbit_location.gateway_secret',str_repeat('A',48));
        config()->set('orbit_location.realtime_store','array');
        config()->set('orbit_location.wss_url','wss://gateway.example.test/location');

        $grant=$this->withToken($token)->postJson('/api/v1/location/realtime/ticket')
            ->assertOk()->assertJsonPath('data.expires_in',60)->json('data');
        $this->assertSame('wss://gateway.example.test/location',$grant['url']);
        $this->postJson('/api/v1/location/realtime/introspect',[
            'ticket'=>$grant['ticket']])->assertForbidden();
        $viewer=$this->withHeaders(['X-Orbit-Location-Gateway'=>str_repeat('A',48)])
            ->postJson('/api/v1/location/realtime/introspect',[
                'ticket'=>$grant['ticket']])
            ->assertOk()->assertJsonPath('data.authorized',true)->json('data');
        $this->assertSame($user->id,$viewer['viewer_id']);
        $this->assertStringStartsWith('orbit:m10:viewer:',$viewer['channel']);
        $device->update(['trust_state'=>'pending']);
        $this->withHeaders(['X-Orbit-Location-Gateway'=>str_repeat('A',48)])
            ->postJson('/api/v1/location/realtime/introspect',[
                'ticket'=>$grant['ticket']])->assertForbidden();
    }
}
