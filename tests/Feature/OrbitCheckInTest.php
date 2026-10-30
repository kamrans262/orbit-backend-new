<?php

namespace Tests\Feature;

use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Models\OrbitDevice;
use App\Models\OrbitUser;
use App\Modules\Identity\Services\TokenService;
use App\Modules\Config\Services\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrbitCheckInTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'orbit_check_in.enabled'=>true,
            'orbit_check_in.sender_per_minute'=>100,
            'orbit_circle.enabled'=>true,
            'orbit_circle.consumer_enabled.check_in'=>false,
        ]);
    }

    private function person(string $name): array
    {
        $user=OrbitUser::create([
            'display_name'=>$name,'username'=>Str::lower(Str::random(14)),
            'status'=>'active',
        ]);
        $device=OrbitDevice::create([
            'user_id'=>$user->id,'device_public_id'=>(string)Str::uuid(),
            'name'=>'Test device','platform'=>'android',
            'public_key'=>base64_encode(random_bytes(32)),
            'trust_state'=>'trusted','attestation_state'=>'verified',
            'approved_at'=>now(),
        ]);
        $token=app(TokenService::class)->issue($user,$device,null)['access_token'];
        return [$user,$token,$device];
    }

    private function privacy(OrbitUser $user,string $visibility): void
    {
        DB::table('orbit_user_preferences')->updateOrInsert(['user_id'=>$user->id],[
            'privacy'=>json_encode(['activity_visibility'=>$visibility]),
            'notifications'=>'{}','created_at'=>now(),'updated_at'=>now(),
        ]);
    }

    private function friend(OrbitUser $a,OrbitUser $b): void
    {
        $ids=[$a->id,$b->id];
        sort($ids,SORT_STRING);
        DB::table('friendships')->insert([
            'user_low_id'=>$ids[0],'user_high_id'=>$ids[1],'created_at'=>now(),
        ]);
    }

    private function payload(array $audience,?string $client=null): array
    {
        return ['client_id'=>$client??(string)Str::uuid(),'type'=>'home',
            'audience'=>$audience];
    }

    private function onlyMe(): array
    {
        return [['type'=>'only_me']];
    }

    public function test_capabilities_are_authenticated_and_service_defaults_are_guarded(): void
    {
        $this->getJson('/api/v1/check-ins/capabilities')->assertUnauthorized();
        [$owner,$token,$device]=$this->person('A');
        $this->withToken($token)->getJson('/api/v1/check-ins/capabilities')->assertOk()
            ->assertJsonPath('data.available',true)->assertJsonPath('data.push_available',false);
        config(['orbit_check_in.enabled'=>false]);
        $this->withToken($token)->getJson('/api/v1/check-ins/capabilities')
            ->assertJsonPath('data.available',false);
        $this->withToken($token)->postJson('/api/v1/check-ins',$this->payload($this->onlyMe()))
            ->assertStatus(503);
        config(['orbit_check_in.enabled'=>true]);
        $device->update(['trust_state'=>'pending']);
        $this->withToken($token)->getJson('/api/v1/check-ins/mine')->assertForbidden();
    }

    public function test_only_me_never_appears_in_other_users_feed(): void
    {
        [$a,$ta]=$this->person('A');
        [$b,$tb]=$this->person('B');
        $record=$this->withToken($ta)->postJson('/api/v1/check-ins',
            $this->payload($this->onlyMe()))->assertCreated()
            ->assertHeader('Cache-Control')
            ->json('data.id');
        $this->withToken($tb)->getJson('/api/v1/check-ins/feed')->assertOk()
            ->assertJsonCount(0,'data.items');
        $this->withToken($tb)->getJson('/api/v1/check-ins/'.$record)->assertNotFound();
        $this->withToken($ta)->getJson('/api/v1/check-ins/'.$record)->assertOk();
    }

    public function test_friends_require_global_activity_permission_and_live_friendship(): void
    {
        [$a,$ta]=$this->person('A');
        [$b,$tb]=$this->person('B');
        $this->friend($a,$b);
        $scope=[['type'=>'selected_friend','id'=>$b->id]];
        $this->withToken($ta)->postJson('/api/v1/check-ins',$this->payload($scope))
            ->assertUnprocessable();
        $this->privacy($a,'Friends');
        $id=$this->withToken($ta)->postJson('/api/v1/check-ins',$this->payload($scope))
            ->assertCreated()->json('data.id');
        $this->withToken($tb)->getJson('/api/v1/check-ins/'.$id)->assertOk();
        DB::table('blocks')->insert(['blocker_id'=>$b->id,'blocked_id'=>$a->id,
            'created_at'=>now()]);
        $this->withToken($tb)->getJson('/api/v1/check-ins/'.$id)->assertNotFound();
        $this->withToken($tb)->getJson('/api/v1/check-ins/feed')->assertOk()
            ->assertJsonCount(0,'data.items');
        DB::table('blocks')->delete();
        $this->privacy($a,'Nobody');
        $this->withToken($tb)->getJson('/api/v1/check-ins/'.$id)->assertNotFound();
    }

    public function test_idempotency_detects_mismatched_retry(): void
    {
        [$a,$token]=$this->person('A');
        $payload=$this->payload($this->onlyMe());
        $id=$this->withToken($token)->postJson('/api/v1/check-ins',$payload)
            ->assertCreated()->json('data.id');
        $this->withToken($token)->postJson('/api/v1/check-ins',$payload)
            ->assertOk()->assertJsonPath('data.id',$id);
        $payload['type']='work';
        $this->withToken($token)->postJson('/api/v1/check-ins',$payload)->assertStatus(409);
        $this->assertDatabaseCount('checkins',1);
    }

    public function test_scope_change_revokes_old_revision_and_uses_live_grants(): void
    {
        [$a,$ta]=$this->person('A');
        [$b,$tb]=$this->person('B');
        [$c,$tc]=$this->person('C');
        $this->friend($a,$b);
        $this->friend($a,$c);
        $this->privacy($a,'Friends');
        $id=$this->withToken($ta)->postJson('/api/v1/check-ins',$this->payload([
            ['type'=>'selected_friend','id'=>$b->id],
        ]))->assertCreated()->json('data.id');
        $this->withToken($tb)->getJson('/api/v1/check-ins/'.$id)->assertOk();
        $this->withToken($tc)->getJson('/api/v1/check-ins/'.$id)->assertNotFound();
        $this->withToken($ta)->putJson('/api/v1/check-ins/'.$id.'/audience',[
            'expected_revision'=>1,'audience'=>[['type'=>'selected_friend','id'=>$c->id]],
        ])->assertOk()->assertJsonPath('data.audience_revision',2);
        $this->withToken($tb)->getJson('/api/v1/check-ins/'.$id)->assertNotFound();
        $this->withToken($tc)->getJson('/api/v1/check-ins/'.$id)->assertOk();
        $this->withToken($ta)->putJson('/api/v1/check-ins/'.$id.'/audience',[
            'expected_revision'=>1,'audience'=>$this->onlyMe(),
        ])->assertStatus(409);
    }

    public function test_expiry_withdrawal_custom_validation_and_location_refusal(): void
    {
        [$a,$ta]=$this->person('A');
        $this->withToken($ta)->postJson('/api/v1/check-ins',
            array_replace($this->payload($this->onlyMe()),['type'=>'custom','text'=>'   ']))
            ->assertUnprocessable();
        $this->withToken($ta)->postJson('/api/v1/check-ins',
            array_replace($this->payload($this->onlyMe()),['place_ref'=>'some-place']))
            ->assertUnprocessable();
        $this->withToken($ta)->postJson('/api/v1/check-ins',[
            'client_id'=>(string)Str::uuid(),'type'=>'home',
        ])->assertUnprocessable();
        $id=$this->withToken($ta)->postJson('/api/v1/check-ins',
            $this->payload($this->onlyMe()))->assertCreated()->json('data.id');
        $this->withToken($ta)->patchJson('/api/v1/check-ins/'.$id,[
            'expires_at'=>now()->addMinutes(30)->toIso8601String(),
        ])->assertOk();
        DB::table('checkins')->where('id',$id)->update(['expires_at'=>now()->subMinute()]);
        $this->withToken($ta)->getJson('/api/v1/check-ins/'.$id)->assertNotFound();
        $this->withToken($ta)->getJson('/api/v1/check-ins/mine')->assertOk()
            ->assertJsonPath('data.items.0.status','expired');
        $this->withToken($ta)->deleteJson('/api/v1/check-ins/'.$id)->assertNoContent();
        $this->withToken($ta)->deleteJson('/api/v1/check-ins/'.$id)->assertNoContent();
        $this->assertDatabaseCount('checkins',1);
    }

    public function test_circle_requires_reviewed_creator_permission_and_live_membership(): void
    {
        [$a,$ta]=$this->person('A');
        [$b,$tb]=$this->person('B');
        $circle=(string)Str::uuid();
        DB::table('circles')->insert([
            'id'=>$circle,'owner_id'=>$a->id,'name'=>'Private group',
            'revision'=>1,'created_at'=>now(),'updated_at'=>now(),
        ]);
        foreach ([$a,$b] as $user) DB::table('circle_members')->insert([
            'circle_id'=>$circle,'user_id'=>$user->id,'role'=>'member',
            'joined_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
        ]);
        $this->privacy($a,'My Circle');
        config(['orbit_circle.consumer_enabled.check_in'=>true]);
        $scope=[['type'=>'circle','id'=>$circle]];
        $this->withToken($ta)->postJson('/api/v1/check-ins',$this->payload($scope))
            ->assertUnprocessable();
        $this->withToken($ta)->putJson('/api/v1/me/check-ins/circles/'.$circle.'/consent',[
            'enabled'=>true,'confirm'=>true,
        ])->assertOk()->assertJsonPath('data.enabled',true);
        $id=$this->withToken($ta)->postJson('/api/v1/check-ins',$this->payload($scope))
            ->assertCreated()->json('data.id');
        $this->withToken($tb)->getJson('/api/v1/check-ins/'.$id)->assertOk();
        $this->withToken($ta)->putJson('/api/v1/me/check-ins/circles/'.$circle.'/consent',[
            'enabled'=>false,
        ])->assertOk()->assertJsonPath('data.enabled',false);
        $this->withToken($tb)->getJson('/api/v1/check-ins/'.$id)->assertNotFound();
    }

    public function test_new_friend_never_inherits_historical_audience_without_new_owner_revision(): void
    {
        [$a,$ta]=$this->person('A');
        [$b,$tb]=$this->person('B');
        $this->privacy($a,'Friends');
        $id=$this->withToken($ta)->postJson('/api/v1/check-ins',
            $this->payload([['type'=>'friends']]))->assertCreated()->json('data.id');
        $this->friend($a,$b);
        $this->withToken($tb)->getJson('/api/v1/check-ins/'.$id)->assertNotFound();
        $this->withToken($ta)->putJson('/api/v1/check-ins/'.$id.'/audience',[
            'expected_revision'=>1,'audience'=>[['type'=>'friends']],
        ])->assertOk()->assertJsonPath('data.audience_revision',2);
        $this->withToken($tb)->getJson('/api/v1/check-ins/'.$id)->assertOk();
    }

    public function test_check_in_operations_is_role_gated_and_discloses_no_private_data(): void
    {
        $role=AdminRole::create(['name'=>'Check-In operator','permissions'=>['config.manage']]);
        $admin=AdminUser::create([
            'name'=>'Operations','email'=>'checkin-operator@example.test',
            'password'=>'StrongPassword123!','totp_secret'=>(new Totp())->generateSecret(),
            'status'=>'active',
        ]);
        $admin->roles()->attach($role->id);
        $this->actingAs($admin,'admin')->get('/admin/check-in')->assertOk()
            ->assertSee('Check-In Operations')->assertSee('Accepted Check-Ins')
            ->assertSee('Rejected publishing requests')
            ->assertDontSee('recipient_user_id')->assertDontSee('place_ref');
        $role->update(['permissions'=>['users.read']]);
        $this->actingAs($admin,'admin')->get('/admin/check-in')->assertForbidden();
    }
}
