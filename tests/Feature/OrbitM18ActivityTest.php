<?php

namespace Tests\Feature;

use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Models\OrbitDevice;
use App\Models\OrbitUser;
use App\Modules\Activity\Services\ActivityProjector;
use App\Modules\Config\Services\Totp;
use App\Modules\Identity\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrbitM18ActivityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        config()->set('orbit_activity.enabled', true);
        config()->set('orbit_moments.enabled', true);
        config()->set('orbit_media.enabled', true);
        config()->set('orbit_check_in.enabled', true);
    }

    private function principal(): array
    {
        $user = OrbitUser::create([
            'username' => 'm18_'.Str::random(12),
            'display_name' => 'Private member', 'status' => 'active',
        ]);
        $device = OrbitDevice::create([
            'user_id' => $user->id, 'device_public_id' => (string)Str::uuid(),
            'name' => 'M18 trusted device', 'platform' => 'android',
            'public_key' => base64_encode(random_bytes(32)),
            'trust_state' => 'trusted', 'attestation_state' => 'verified',
            'approved_at' => now(),
        ]);
        $token = app(TokenService::class)->issue($user, $device, null)['access_token'];
        return [$user,$device,$token];
    }

    private function moment(string $owner): string
    {
        $id=(string)Str::uuid();
        DB::table('moments')->insert([
            'id'=>$id,'creator_id'=>$owner,'client_id'=>(string)Str::uuid(),
            'request_key_hash'=>str_repeat('a',64),
            'request_payload_hash'=>str_repeat('b',64),
            'audience_kind'=>'only_me','state'=>'published',
            'published_at'=>now(),'rail_expires_at'=>now()->addDay(),
            'view_expires_at'=>now()->addDays(2),
            'purge_due_at'=>now()->addDays(7),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        return $id;
    }

    private function checkIn(string $owner): string
    {
        $id = (string)Str::uuid();
        DB::table('checkins')->insert([
            'id'=>$id,'creator_id'=>$owner,'client_id'=>(string)Str::uuid(),
            'payload_hash'=>str_repeat('c',64),'type'=>'custom',
            'text'=>'Private offline check-in',
            'audience_revision'=>1, 'created_at'=>now(), 'updated_at'=>now(),
        ]);
        DB::table('checkin_audience')->insert([
            'checkin_id'=>$id,'audience_revision'=>1,
            'audience_type'=>'only_me','audience_id'=>'',
        ]);
        return $id;
    }

    private function admin(array $permissions): AdminUser
    {
        $role = AdminRole::create([
            'name'=>'Activity inspection '.Str::uuid(), 'permissions'=>$permissions,
        ]);
        $admin = AdminUser::create([
            'name'=>'Activity operator', 'email'=>Str::uuid().'@example.test',
            'password'=>'StrongPassword123!',
            'totp_secret'=>(new Totp())->generateSecret(), 'status'=>'active',
        ]);
        $admin->roles()->attach($role->id);
        return $admin;
    }

    public function test_projector_only_indexes_allowed_sources_idempotently_without_content(): void
    {
        [$owner,$device,$token]=$this->principal();
        $this->moment($owner->id);
        $this->checkIn($owner->id);
        $result=app(ActivityProjector::class)->run(20);
        $this->assertSame(1,$result['moments_added']);
        $this->assertSame(1,$result['check_ins_added']);
        $this->assertSame(0,app(ActivityProjector::class)->run(20)['moments_added']);
        $this->assertDatabaseCount('activity_events',2);
        $columns=array_keys((array)DB::table('activity_events')->first());
        foreach (['text','content','ciphertext','location','recipient_id'] as $forbidden)
            $this->assertNotContains($forbidden,$columns);
        $response=$this->withToken($token)->getJson('/api/v1/activity/feed')
            ->assertOk()->assertJsonCount(2,'data.items');
        $response->assertDontSee('Private offline check-in');
        $response->assertDontSee('recipient_user_id');
    }

    public function test_outsiders_never_receive_private_moments_or_check_ins(): void
    {
        [$owner]=$this->principal();
        [$outsider,$otherDevice,$token]=$this->principal();
        $this->moment($owner->id);
        $this->checkIn($owner->id);
        app(ActivityProjector::class)->run(20);
        $this->withToken($token)->getJson('/api/v1/activity/feed')
            ->assertOk()->assertExactJson(['data'=>[
                'items'=>[],'next_cursor'=>null,
                'sync_mode'=>'authoritative_refresh',
            ]]);
        $id=DB::table('activity_events')->value('id');
        $this->withToken($token)->getJson('/api/v1/activity/items/'.$id)
            ->assertNotFound();
    }

    public function test_withdrawal_and_source_disable_hide_existing_references(): void
    {
        [$owner,$device,$token]=$this->principal();
        $id=$this->checkIn($owner->id);
        app(ActivityProjector::class)->run(20);
        $this->withToken($token)->getJson('/api/v1/activity/feed')
            ->assertOk()->assertJsonCount(1,'data.items');
        DB::table('checkins')->where('id',$id)->update(['deleted_at'=>now()]);
        $this->withToken($token)->getJson('/api/v1/activity/feed')
            ->assertOk()->assertJsonCount(0,'data.items');
        DB::table('checkins')->where('id',$id)->update(['deleted_at'=>null]);
        DB::table('activity_controls')->where('key','source.check_in')
            ->update(['enabled'=>false]);
        $this->withToken($token)->getJson('/api/v1/activity/feed')
            ->assertOk()->assertJsonCount(0,'data.items');
    }

    public function test_cursor_is_bound_to_viewer_and_changes_force_safe_refresh(): void
    {
        [$owner,$dev,$token]=$this->principal();
        $this->moment($owner->id);
        $this->checkIn($owner->id);
        app(ActivityProjector::class)->run(20);
        $cursor=$this->withToken($token)->getJson('/api/v1/activity/feed?limit=1')
            ->assertOk()->json('data.next_cursor');
        $this->assertNotNull($cursor);
        [$other,$d2,$otherToken]=$this->principal();
        $this->withToken($otherToken)->getJson('/api/v1/activity/feed?cursor='.urlencode($cursor))
            ->assertStatus(409);
        $this->withToken($token)->getJson('/api/v1/activity/changes')
            ->assertOk()->assertJsonPath('data.reset_required',true);
    }

    public function test_activity_admin_is_independent_permissioned_and_audited(): void
    {
        $this->get('/admin/activity')->assertRedirect(route('orbit.admin.login'));
        $this->be($this->admin(['users.read','config.manage']),'admin');
        $this->get('/admin/activity')->assertForbidden();
        $this->be($this->admin(['activity.read']),'admin');
        $this->get('/admin/activity')->assertOk()->assertSee('Activity Feed Management');
        $this->get('/admin/activity/sources')->assertOk();
        $this->post('/admin/activity/control',[
            'key'=>'source.moment','enabled'=>'0',
            'reason'=>'Confirm operation permissions','confirm'=>'1',
        ])->assertForbidden();
        $this->be($this->admin(['activity.read','activity.manage','activity.audit']),'admin');
        $this->post('/admin/activity/control',[
            'key'=>'source.moment','enabled'=>'0',
            'reason'=>'Testing controlled source disablement','confirm'=>'1',
        ])->assertRedirect();
        $this->assertDatabaseHas('activity_controls',[
            'key'=>'source.moment','enabled'=>0,
        ]);
        $this->assertDatabaseHas('activity_admin_audits',[
            'action'=>'source.changed','subject_key'=>'source.moment',
            'old_value'=>'enabled','new_value'=>'disabled',
        ]);
        $this->get('/admin/activity/audits')->assertOk()
            ->assertSee('Testing controlled source disablement');
    }

    public function test_projector_backfills_fresh_first_and_updates_source_revision(): void
    {
        [$owner,$dev,$token] = $this->principal();
        $earlier = $this->checkIn($owner->id);
        DB::table('checkins')->where('id',$earlier)->update([
            'created_at'=>now()->subDays(10)]);
        $latest = $this->checkIn($owner->id);
        $first = app(ActivityProjector::class)->run(1);
        $this->assertSame(1,$first['check_ins_added']);
        $this->assertDatabaseHas('activity_events',[
            'source_type'=>'check_in','source_id'=>$latest,
        ]);
        DB::table('checkins')->where('id',$latest)->update([
            'audience_revision'=>2,
        ]);
        app(ActivityProjector::class)->run(20);
        $this->assertDatabaseHas('activity_events',[
            'source_type'=>'check_in','source_id'=>$latest,
            'source_revision'=>2,
        ]);
    }

    public function test_cursor_does_not_embed_readable_account_id(): void
    {
        [$owner,$device,$token]=$this->principal();
        $this->moment($owner->id);
        $this->checkIn($owner->id);
        app(ActivityProjector::class)->run(10);
        $cursor=$this->withToken($token)
            ->getJson('/api/v1/activity/feed?limit=1')
            ->assertOk()->json('data.next_cursor');
        $encoded=explode('.', (string)$cursor)[0];
        $payload=base64_decode(strtr($encoded,'-_','+/'));
        $this->assertIsString($payload);
        $this->assertStringNotContainsString($owner->id,$payload);
        $this->withToken($token)
            ->getJson('/api/v1/activity/feed?limit=1&cursor='.urlencode($cursor))
            ->assertOk();
    }

    public function test_capabilities_and_auth_are_fail_closed(): void
    {
        $this->getJson('/api/v1/activity/feed')->assertUnauthorized();
        [$user,$device,$token]=$this->principal();
        $this->withToken($token)->getJson('/api/v1/activity/capabilities')
            ->assertOk()->assertJsonPath('data.sync_mode','authoritative_refresh');
        $device->update(['revoked_at'=>now()]);
        $this->withToken($token)->getJson('/api/v1/activity/feed')
            ->assertStatus(401);
    }
}
