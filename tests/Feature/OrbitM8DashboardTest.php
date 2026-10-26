<?php

namespace Tests\Feature;

use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Models\OrbitDevice;
use App\Models\OrbitUser;
use App\Modules\Config\Services\Totp;
use App\Modules\Identity\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrbitM8DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('orbit_dashboard.enabled',true);
        config()->set('orbit_dashboard.smart_cards_enabled',true);
    }

    private function person(string $prefix): array
    {
        $user=OrbitUser::create([
            'username'=>$prefix.'_'.Str::lower(Str::random(8)),
            'display_name'=>'Private '.ucfirst($prefix),'status'=>'active',
        ]);
        $device=OrbitDevice::create([
            'user_id'=>$user->id,'device_public_id'=>(string)Str::uuid(),
            'name'=>'M8 test phone','platform'=>'android',
            'public_key'=>base64_encode(random_bytes(32)),
            'trust_state'=>'trusted','attestation_state'=>'verified','approved_at'=>now(),
        ]);
        return [$user,app(TokenService::class)->issue($user,$device,null)['access_token'],$device];
    }

    private function admin(array $permissions): AdminUser
    {
        $role=AdminRole::create(['name'=>'M8 '.Str::uuid(),'permissions'=>$permissions]);
        $admin=AdminUser::create(['name'=>'M8 Reviewer',
            'email'=>Str::uuid().'@example.test','password'=>'Secure123!password',
            'totp_secret'=>(new Totp())->generateSecret(),'status'=>'active']);
        $admin->roles()->attach($role->id);
        return $admin;
    }

    public function test_trusted_device_and_explicit_rollout_are_required(): void
    {
        [$user,$token,$device]=$this->person('alpha');
        $this->getJson('/api/v1/dashboard/summary')->assertUnauthorized();
        config()->set('orbit_dashboard.enabled',false);
        $this->withToken($token)->getJson('/api/v1/dashboard/summary')->assertNotFound();
        config()->set('orbit_dashboard.enabled',true);
        $this->withToken($token)->getJson('/api/v1/dashboard/summary')
            ->assertOk()->assertJsonPath('data.schema_version',1)
            ->assertJsonPath('data.greeting.display_name',$user->display_name)
            ->assertHeader('Cache-Control','private, no-store, max-age=0');
        $device->update(['trust_state'=>'pending']);
        $this->withToken($token)->getJson('/api/v1/dashboard/summary')->assertForbidden();
    }

    public function test_disabled_upstream_sections_are_not_fabricated_empty_data(): void
    {
        [$user,$token]=$this->person('alpha');
        config()->set('orbit_circle.enabled',false);
        config()->set('orbit_moments.enabled',false);
        $this->withToken($token)->getJson('/api/v1/dashboard/summary')->assertOk()
            ->assertJsonPath('data.sections.circles.state','disabled')
            ->assertJsonPath('data.sections.moments.state','disabled')
            ->assertJsonPath('data.sections.plans.state','disabled')
            ->assertJsonPath('data.capabilities.ping',false)
            ->assertJsonPath('data.capabilities.sos',false)
            ->assertJsonMissingPath('data.sections.location.items.0.coordinates');
    }

    public function test_actual_accepted_friends_not_strangers_and_blocks_revoke_visibility(): void
    {
        [$a,$ta]=$this->person('alpha');
        [$b,$tb]=$this->person('bravo');
        [$stranger]=$this->person('charlie');
        $requestId=$this->withToken($ta)->postJson('/api/v1/friends/requests',
            ['recipient_id'=>$b->id])->assertCreated()->json('data.id');
        $this->withToken($tb)->postJson('/api/v1/friends/requests/'.$requestId.'/accept')->assertOk();
        $res=$this->withToken($ta)->getJson('/api/v1/dashboard/summary')->assertOk()
            ->assertJsonPath('data.sections.people.items.0.id',$b->id)
            ->assertJsonPath('data.sections.people.items.0.presence',null)
            ->assertJsonMissing(['id'=>$stranger->id]);
        $this->withToken($ta)->postJson('/api/v1/me/blocks',['user_id'=>$b->id])->assertOk();
        $this->withToken($ta)->getJson('/api/v1/dashboard/summary')
            ->assertOk()->assertJsonCount(0,'data.sections.people.items');
    }

    public function test_circle_cards_use_real_m5_membership_and_preferences_are_account_scoped(): void
    {
        config()->set('orbit_circle.enabled',true);
        [$a,$ta]=$this->person('alpha');
        [$b,$tb]=$this->person('bravo');
        $id=$this->withToken($ta)->postJson('/api/v1/circles',
            ['name'=>'Family Circle'])->assertCreated()->json('data.id');
        $this->withToken($ta)->getJson('/api/v1/dashboard/summary')
            ->assertOk()->assertJsonPath('data.sections.circles.items.0.id',$id);
        $this->withToken($tb)->getJson('/api/v1/dashboard/summary')
            ->assertOk()->assertJsonCount(0,'data.sections.circles.items');
        $this->withToken($tb)->putJson('/api/v1/me/dashboard/preferences',
            ['circle_order'=>[$id]])->assertUnprocessable();
        $this->withToken($ta)->putJson('/api/v1/me/dashboard/preferences',
            ['circle_order'=>[$id]])->assertOk()->assertJsonPath('data.circle_order.0',$id);
        $this->withToken($ta)->putJson('/api/v1/me/dashboard/preferences',
            ['circle_order'=>[$id,$id]])->assertUnprocessable();
    }

    public function test_24_hour_card_dismissals_are_per_account_and_validated(): void
    {
        [$a,$ta]=$this->person('alpha');
        [$b,$tb]=$this->person('bravo');
        $path='/api/v1/me/dashboard/cards/shared_event/dismiss';
        $this->withToken($ta)->postJson($path)->assertOk()
            ->assertJsonPath('data.key','shared_event');
        $this->assertDatabaseHas('dashboard_card_dismissals',[
            'user_id'=>$a->id,'card_key'=>'shared_event',
        ]);
        $this->assertDatabaseMissing('dashboard_card_dismissals',[
            'user_id'=>$b->id,'card_key'=>'shared_event',
        ]);
        $this->withToken($ta)->postJson('/api/v1/me/dashboard/cards/private_data/dismiss')
            ->assertNotFound();
        $this->withToken($tb)->getJson('/api/v1/dashboard/summary')->assertOk();
    }

    public function test_admin_page_is_rbac_and_module_gated_with_no_private_data(): void
    {
        $this->get('/admin/home-dashboard')->assertRedirect(route('orbit.admin.login'));
        $this->be($this->admin(['users.read']),'admin');
        $this->get('/admin/home-dashboard')->assertForbidden();
        $this->be($this->admin(['config.manage']),'admin');
        $this->get('/admin/home-dashboard')->assertOk()
            ->assertSee('Home Dashboard')
            ->assertSee('No user names')
            ->assertDontSee('Private Alpha');
        config()->set('orbit_dashboard.enabled',false);
        $this->get('/admin/home-dashboard')->assertNotFound();
    }
}
