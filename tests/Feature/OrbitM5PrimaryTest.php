<?php

namespace Tests\Feature;

use App\Models\OrbitUser;
use App\Models\OrbitDevice;
use App\Modules\Identity\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrbitM5PrimaryTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        config()->set('orbit_circle.enabled',true);
        config()->set('orbit_circle.archive_enabled',true);
    }
    private function identity(string $name): array
    {
        $user=OrbitUser::create(['display_name'=>$name,
            'username'=>$name.'_'.Str::random(8),'status'=>'active']);
        $d=OrbitDevice::create([
            'user_id'=>$user->id,'device_public_id'=>(string) Str::uuid(),
            'name'=>'test','platform'=>'android','public_key'=>base64_encode(random_bytes(32)),
            'trust_state'=>'trusted','attestation_state'=>'verified','approved_at'=>now(),
        ]);
        return [$user,app(TokenService::class)->issue($user,$d,null)['access_token']];
    }

    public function test_primary_requires_membership_and_falls_back_from_archived_group(): void
    {
        [$a,$ta]=$this->identity('alpha');
        [$b,$tb]=$this->identity('bravo');
        $first=$this->withToken($ta)->postJson('/api/v1/circles',['name'=>'First'])
            ->assertCreated()->json('data.id');
        $second=$this->withToken($ta)->postJson('/api/v1/circles',['name'=>'Second'])
            ->assertCreated()->json('data.id');
        $this->withToken($tb)->putJson('/api/v1/me/circles/primary',
            ['circle_id'=>$second])->assertNotFound();
        $this->withToken($ta)->putJson('/api/v1/me/circles/primary',
            ['circle_id'=>$second])->assertOk()
            ->assertJsonPath('data.circle_id',$second);
        $this->withToken($ta)->getJson('/api/v1/me/circles/primary')
            ->assertJsonPath('data.fallback',false);
        $this->withToken($ta)->postJson('/api/v1/circles/'.$second.'/archive',
            ['confirmation'=>'ARCHIVE'])->assertOk();
        $this->withToken($ta)->getJson('/api/v1/me/circles/primary')
            ->assertJsonPath('data.circle_id',$first)
            ->assertJsonPath('data.fallback',true);
        $this->withToken($ta)->putJson('/api/v1/me/circles/primary',
            ['circle_id'=>null])->assertOk();
        $this->withToken($ta)->getJson('/api/v1/me/circles/primary')
            ->assertJsonPath('data.circle_id',$first);
    }
}
