<?php
namespace Tests\Feature;
use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Models\OrbitDevice;
use App\Models\OrbitUser;
use App\Modules\Config\Services\Totp;
use App\Modules\Identity\Services\TokenService;
use App\Modules\Billing\Services\BillingProvider;
use App\Modules\Billing\Services\BillingPolicy;
use App\Modules\Billing\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrbitM19BillingTest extends TestCase{
    use RefreshDatabase;
    private $provider;
    protected function setUp():void{
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        config(['orbit_billing.sandbox_enabled'=>true,'orbit_billing.binding_key'=>base64_encode(str_repeat('K',32))]);
        $this->provider=new class implements BillingProvider{
            public array $evidence=[];public int $acks=0;public bool $failAck=false;public bool $unavailable=false;
            public function ready(string $provider):bool{return true;}
            public function reference(string $provider,string $proof):string{return $proof;}
            public function lookup(string $provider,string $reference):array{
                if($this->unavailable)abort(503);abort_unless(isset($this->evidence[$reference]),422);
                return $this->evidence[$reference]+['observed'=>now()->format('Y-m-d H:i:s.u')];
            }
            public function notice(string $provider,array $body,?string $bearer):array{
                abort_unless($bearer==='test-authenticated',401);
                return ['event'=>$body['event'],'reference'=>$body['reference']];
            }
            public function acknowledge(string $provider,string $reference,string $product):void{
                $this->acks++;if($this->failAck)abort(503);
            }
        };
        app()->instance(BillingProvider::class,$this->provider);
    }
    private function principal():array{
        $u=OrbitUser::create(['username'=>'billing_'.Str::random(10),'display_name'=>'Tester','status'=>'active']);
        $d=OrbitDevice::create(['user_id'=>$u->id,'device_public_id'=>(string)Str::uuid(),'name'=>'Test','platform'=>'android',
            'public_key'=>base64_encode(random_bytes(32)),'trust_state'=>'trusted','attestation_state'=>'verified','approved_at'=>now()]);
        return [$u,app(TokenService::class)->issue($u,$d,null)['access_token']];
    }
    private function mapping(string $tier='lite',string $provider='google'):string{
        $id=(string)Str::uuid();DB::table('billing_products')->insert(['id'=>$id,'tier'=>$tier,'provider'=>$provider,'environment'=>'sandbox',
            'product_id'=>'orbit.'.$tier,'base_plan_id'=>$provider==='google'?'monthly':'','interval'=>'month','enabled'=>true,'created_at'=>now(),'updated_at'=>now()]);return $id;
    }
    private function evidence($u,string $ref,string $tier='lite',string $provider='google',array $override=[]):void{
        $this->provider->evidence[$ref]=array_replace(['product'=>'orbit.'.$tier,'base'=>$provider==='google'?'monthly':'',
            'binding'=>app(BillingPolicy::class)->binding($u->id,$provider),'state'=>'active','starts'=>now()->subDay()->format('Y-m-d H:i:s'),
            'ends'=>now()->addMonth()->format('Y-m-d H:i:s'),'renew'=>true,'ack'=>$provider==='google'?'pending':'not_required','linked'=>null],$override);
    }
    private function verify(string $token,string $ref,array $extra=[]){
        return $this->withToken($token)->postJson('/api/v1/billing/verify',array_replace(['provider'=>'google','mode'=>'restore','proof'=>$ref,
            'client_request_id'=>(string)Str::uuid()],$extra));
    }
    public function test_disabled_billing_is_unknown_and_no_provider_work():void{
        [, $token]=$this->principal();$id=$this->mapping();config(['orbit_billing.sandbox_enabled'=>false]);
        $this->getJson('/api/v1/billing/entitlement')->assertUnauthorized();
        $this->withToken($token)->getJson('/api/v1/billing/entitlement')->assertOk()->assertJsonPath('data.state','unknown');
        $this->getJson('/api/v1/billing/catalog?provider=google')->assertOk()->assertJsonPath('data.purchase_enabled',false);
        $this->postJson('/api/v1/billing/purchase-context',['provider'=>'google','product_mapping_id'=>$id,'client_request_id'=>(string)Str::uuid()])->assertStatus(503);
        $this->assertSame(0,app(BillingService::class)->work()['processed']);
    }
    public function test_context_is_owner_bound_idempotent_and_prevents_overlapping_checkout():void{
        [$u,$token]=$this->principal();$id=$this->mapping();$plus=$this->mapping('plus');
        $body=['provider'=>'google','product_mapping_id'=>$id,'client_request_id'=>(string)Str::uuid()];
        $a=$this->withToken($token)->postJson('/api/v1/billing/purchase-context',$body)->assertOk()->json('data');
        $this->assertSame(app(BillingPolicy::class)->binding($u->id,'google'),$a['account_binding']);
        $this->postJson('/api/v1/billing/purchase-context',$body)->assertOk()->assertJsonPath('data.context_id',$a['context_id']);
        $this->postJson('/api/v1/billing/purchase-context',array_replace($body,['product_mapping_id'=>$plus]))->assertStatus(409);
        $this->postJson('/api/v1/billing/purchase-context',array_replace($body,['client_request_id'=>(string)Str::uuid()]))->assertStatus(409);
        $this->evidence($u,'token-good');
        $this->verify($token,'token-good',['mode'=>'purchase','context_id'=>$a['context_id']])->assertOk()->assertJsonPath('data.entitlement.tier','lite')->assertJsonPath('data.finish_permitted',true);
        $this->assertDatabaseCount('billing_subscriptions',1);
        $this->assertDatabaseHas('billing_subscriptions',['ack_state'=>'acknowledged']);
        $this->assertStringNotContainsString('token-good',DB::table('billing_subscriptions')->value('reference_cipher'));
    }
    public function test_restore_replay_wrong_account_and_request_binding():void{
        [$u,$token]=$this->principal();[, $other]=$this->principal();$this->mapping();$this->evidence($u,'original-token');
        $id=(string)Str::uuid();
        $this->verify($token,'original-token',['client_request_id'=>$id])->assertOk();
        $this->verify($token,'original-token',['client_request_id'=>$id])->assertOk();
        $this->evidence($u,'different-token');
        $this->verify($token,'different-token',['client_request_id'=>$id])->assertStatus(409);
        $this->verify($other,'original-token')->assertStatus(409);
        $this->assertDatabaseCount('billing_subscriptions',1);
        $this->getJson('/api/v1/billing/entitlement')->assertOk()->assertJsonPath('data.tier','free');
    }
    public function test_lifecycle_highest_tier_expiry_and_revocation():void{
        [$u,$token]=$this->principal();$this->mapping();$this->mapping('plus','apple');
        $this->evidence($u,'google-token');$this->evidence($u,'apple-original','plus','apple');
        $this->verify($token,'google-token')->assertOk();
        $this->verify($token,'apple-original',['provider'=>'apple'])->assertOk()->assertJsonPath('data.entitlement.tier','plus')->assertJsonPath('data.entitlement.capabilities.ad_free',false);
        $this->evidence($u,'apple-original','plus','apple',['state'=>'revoked']);
        $this->verify($token,'apple-original',['provider'=>'apple'])->assertOk()->assertJsonPath('data.entitlement.tier','lite');
        $this->travel(35)->days();
        $this->getJson('/api/v1/billing/entitlement')->assertOk()->assertJsonPath('data.tier','free');
    }
    public function test_pending_grace_hold_and_durable_ack_retry():void{
        [$u,$token]=$this->principal();$this->mapping();$this->evidence($u,'token-state','lite','google',['state'=>'pending','starts'=>null,'ends'=>null]);
        $this->verify($token,'token-state')->assertOk()->assertJsonPath('data.finish_permitted',false);
        $this->assertSame(0,$this->provider->acks);
        $this->evidence($u,'token-state','lite','google',['state'=>'grace']);$this->provider->failAck=true;
        $this->verify($token,'token-state')->assertOk()->assertJsonPath('data.entitlement.state','grace');
        $this->assertDatabaseHas('billing_subscriptions',['ack_state'=>'pending']);
        $this->provider->failAck=false;$this->travel(3)->minutes();
        $this->assertSame(1,app(BillingService::class)->work()['reconciled']);
        $this->assertDatabaseHas('billing_subscriptions',['ack_state'=>'acknowledged']);
        $this->evidence($u,'token-state','lite','google',['state'=>'hold']);
        $this->verify($token,'token-state')->assertOk()->assertJsonPath('data.entitlement.tier','free');
    }
    public function test_authenticated_notice_dedup_and_authoritative_reconciliation():void{
        [$u,$token]=$this->principal();$this->mapping();$this->evidence($u,'token-notice');
        $this->verify($token,'token-notice')->assertOk();
        $payload=['event'=>'notice-1','reference'=>'token-notice'];
        $this->withToken('bad')->postJson('/api/v1/billing/webhooks/google',$payload)->assertUnauthorized();
        $this->withToken('test-authenticated')->postJson('/api/v1/billing/webhooks/google',$payload)->assertNoContent();
        $this->postJson('/api/v1/billing/webhooks/google',$payload)->assertNoContent();
        $this->assertDatabaseCount('billing_events',2);
        $this->evidence($u,'token-notice','lite','google',['state'=>'expired']);
        $this->assertSame(1,app(BillingService::class)->work()['processed']);
        $this->withToken($token)->getJson('/api/v1/billing/entitlement')->assertOk()->assertJsonPath('data.state','expired');
        $this->assertDatabaseMissing('billing_events',['state'=>'queued']);
    }
    private function admin(array $permissions):AdminUser{
        $role=AdminRole::create(['name'=>'Billing '.Str::uuid(),'permissions'=>$permissions]);
        $a=AdminUser::create(['name'=>'Billing operator','email'=>Str::uuid().'@example.test','password'=>'StrongTestPassword123!','totp_secret'=>(new Totp())->generateSecret(),'status'=>'active']);
        $a->roles()->attach($role->id);return $a;
    }
    public function test_admin_permissions_and_audited_mapping():void{
        $this->get('/admin/billing/products')->assertRedirect(route('orbit.admin.login'));
        $this->be($this->admin(['billing.read']),'admin');
        $this->get('/admin/billing/products')->assertOk()->assertDontSee('Add Product');
        $this->get('/admin/billing/subscriptions')->assertOk();$this->get('/admin/billing/operations')->assertOk();
        $d=['revision'=>0,'tier'=>'lite','provider'=>'google','product_id'=>'orbit.lite','base_plan_id'=>'monthly','enabled'=>0,'reason'=>'Create approved test mapping','confirm'=>1];
        $this->post('/admin/billing/products',$d)->assertForbidden();
        $this->be($this->admin(['billing.read','billing.manage']),'admin');
        $this->post('/admin/billing/products',$d)->assertRedirect(route('orbit.admin.billing.products'));
        $this->post('/admin/billing/products',$d)->assertStatus(409);
        $this->assertDatabaseCount('billing_products',1);$this->assertDatabaseHas('admin_audit_log',['action'=>'billing.product.save']);
    }

    public function test_linked_replacement_does_not_resurrect_old_subscription():void{
        [$u,$token]=$this->principal();$this->mapping();$this->mapping('plus');
        $this->evidence($u,'old-token');$this->verify($token,'old-token')->assertOk();
        $this->evidence($u,'new-token','plus','google',['linked'=>'old-token']);
        $this->verify($token,'new-token')->assertOk()->assertJsonPath('data.entitlement.tier','plus');
        $this->assertDatabaseHas('billing_subscriptions',['state'=>'expired','lineage_hash'=>app(BillingPolicy::class)->digest('lineage:google','old-token')]);
        $this->verify($token,'old-token')->assertOk()->assertJsonPath('data.entitlement.tier','plus')->assertJsonPath('data.finish_permitted',false);
        $this->assertDatabaseCount('billing_subscriptions',2);
    }
    public function test_outage_does_not_extend_confirmed_entitlement():void{
        [$u,$token]=$this->principal();$this->mapping();$this->evidence($u,'outage-token');
        $original=$this->verify($token,'outage-token')->assertOk()->json('data.entitlement.effective_until');
        $this->provider->unavailable=true;
        $this->verify($token,'outage-token')->assertStatus(503);
        $this->getJson('/api/v1/billing/entitlement')->assertOk()->assertJsonPath('data.effective_until',$original);
        $this->travel(35)->days();$this->getJson('/api/v1/billing/entitlement')->assertOk()->assertJsonPath('data.tier','free');
    }

    public function test_management_providers_and_unique_eligible_accounts():void{
        [$u,$token]=$this->principal();$this->mapping();$this->mapping('plus','apple');
        $this->evidence($u,'google-token');$this->evidence($u,'apple-original','plus','apple');
        $this->verify($token,'google-token')->assertOk()->assertJsonPath('data.entitlement.management_providers',['google']);
        $this->verify($token,'apple-original',['provider'=>'apple'])->assertOk()->assertJsonPath('data.entitlement.management_providers',['apple','google']);
        $this->assertSame(['lite'=>0,'plus'=>1],app(BillingService::class)->subscriberCounts());
        $this->evidence($u,'apple-original','plus','apple',['state'=>'revoked']);
        $this->verify($token,'apple-original',['provider'=>'apple'])->assertOk()->assertJsonPath('data.entitlement.management_providers',['google']);
        $this->assertSame(['lite'=>1,'plus'=>0],app(BillingService::class)->subscriberCounts());
        $this->travel(35)->days();
        $this->getJson('/api/v1/billing/entitlement')->assertOk()->assertJsonPath('data.management_providers',[]);
        $this->assertSame(['lite'=>0,'plus'=>0],app(BillingService::class)->subscriberCounts());
    }
    public function test_unresolved_subscription_remains_visible_when_another_record_expires():void{
        [$u,$token]=$this->principal();$this->mapping();$this->mapping('plus','apple');
        $this->evidence($u,'pending-token','lite','google',['state'=>'pending','starts'=>null,'ends'=>null]);
        $this->verify($token,'pending-token')->assertOk();
        $this->evidence($u,'expired-apple','plus','apple',['state'=>'expired']);
        $this->verify($token,'expired-apple',['provider'=>'apple'])->assertOk()->assertJsonPath('data.entitlement.state','pending')->assertJsonPath('data.entitlement.management_providers',['google']);
    }
}
