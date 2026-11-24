<?php
namespace Tests\Feature;
use App\Models\OrbitUser;
use App\Models\OrbitDevice;
use App\Modules\Identity\Services\TokenService;
use App\Models\AdminUser;
use App\Models\AdminRole;
use App\Modules\Config\Services\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrbitM22RelayTest extends TestCase
{
    use RefreshDatabase;
    private function member():array{
        $u=OrbitUser::create(['username'=>'relay_'.Str::random(12),'display_name'=>'Relay test','status'=>'active']);
        $d=OrbitDevice::create(['user_id'=>$u->id,'device_public_id'=>(string)Str::uuid(),
            'name'=>'Test device','platform'=>'android','public_key'=>base64_encode(random_bytes(32)),
            'trust_state'=>'trusted','attestation_state'=>'verified','approved_at'=>now()]);
        return [$u,$d,app(TokenService::class)->issue($u,$d,null)['access_token']];
    }
    private function admin(array $permissions):AdminUser{
        $role=AdminRole::create(['name'=>'Relay '.Str::uuid(),'permissions'=>$permissions]);
        $a=AdminUser::create(['name'=>'Relay operator','email'=>Str::uuid().'@example.test','password'=>'StrongTestPassword123!',
            'totp_secret'=>(new Totp())->generateSecret(),'status'=>'active']);
        $a->roles()->attach($role->id);return $a;
    }
    public function test_default_unavailable_and_no_unauthenticated_operations():void{
        $this->getJson('/api/v1/relay/capabilities')->assertUnauthorized();
        [$u,$d,$token]=$this->member();
        $this->withToken($token)->getJson('/api/v1/relay/capabilities')->assertOk()
            ->assertJsonPath('data.enabled',false)->assertJsonPath('data.content_types',[]);
        $this->postJson('/api/v1/relay/leases',[])->assertStatus(503);
        $this->postJson('/api/v1/relay/reconcile',[])->assertStatus(503);
        $this->getJson('/api/v1/relay/sync')->assertOk()->assertJsonPath('data.items',[]);
        $this->getJson('/api/v1/relay/sync?cursor=invalid')->assertUnprocessable();
        $this->assertDatabaseCount('relay_leases',0);
        $d->update(['trust_state'=>'pending']);
        $this->getJson('/api/v1/relay/capabilities')->assertForbidden();
    }
    public function test_admin_access_and_bounded_audited_configuration():void{
        $this->be($this->admin(['relay.read']),'admin');
        $this->get('/admin/relay')->assertOk()->assertSee('Orbit Relay');
        $this->get('/admin/relay/audits')->assertForbidden();
        $this->post('/admin/relay/settings',[])->assertForbidden();
        $this->be($this->admin(['relay.read','relay.manage','relay.audit']),'admin');
        $p=['enabled'=>1,'lease_seconds'=>300,'batch_limit'=>10,'frame_bytes'=>16384,'revision'=>1,'reason'=>'Review operational limits','confirm'=>1];
        $this->post('/admin/relay/settings',$p)->assertRedirect(route('orbit.admin.relay.settings'));
        $this->assertDatabaseHas('config_revisions',['resource_key'=>'relay.operations','version'=>2]);
        $this->post('/admin/relay/settings',$p)->assertStatus(409);
        $this->get('/admin/relay/audits')->assertOk()->assertSee('Review operational limits');
        $this->assertFalse(app(\App\Modules\Relay\Services\RelayPolicy::class)->capabilities()['enabled']);
    }
}
