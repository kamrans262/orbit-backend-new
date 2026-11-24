<?php
namespace Tests\Feature;
use App\Models\OrbitUser;
use App\Models\RemoteConfig;
use App\Modules\Relay\Services\RelayPolicy;
use App\Modules\Relay\Services\RelayReconciliationService;
use App\Modules\Relay\Services\RelayMetrics;
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

    private function enable():string {
        $server=sodium_crypto_sign_keypair();
        config([
            'orbit_relay.enabled'=>true,'orbit_relay.security_reviewed'=>true,
            'orbit_relay.peer_transport_reviewed'=>true,
            'orbit_relay.signing_secret'=>base64_encode(sodium_crypto_sign_secretkey($server)),
            'orbit_messaging.enabled'=>true,'orbit_messaging.e2ee_client_verified'=>true,
            'orbit_messaging.approved_protocols'=>[RelayPolicy::MESSAGE_PROTOCOL],
        ]);
        $record=RemoteConfig::query()->where('key','relay.operations')->firstOrFail();
        $record->update(['value'=>['enabled'=>true,'lease_seconds'=>900,'batch_limit'=>20,'frame_bytes'=>32768]]);
        return sodium_crypto_sign_publickey($server);
    }
    private function keys(string $token,OrbitDevice $device,int $id=7):string {
        $pair=sodium_crypto_sign_keypair();
        $secret=sodium_crypto_sign_secretkey($pair);
        $prekey=random_bytes(32);
        $payload=[
            'identity_ed25519'=>base64_encode(sodium_crypto_sign_publickey($pair)),
            'signed_prekey_x25519'=>base64_encode($prekey),'signed_prekey_id'=>$id,
            'signed_prekey_signature'=>base64_encode(sodium_crypto_sign_detached(
                'orbit-m13-signed-prekey-v1|'.$device->id.'|'.$id.'|'.$prekey,$secret)),
            'one_time_prekeys'=>[],'confirm_key_rotation'=>$id!==7,
        ];
        $registered=app(\App\Modules\Messaging\Services\MessagingKeyService::class)
            ->register((string)$device->user_id,(string)$device->id,$payload);
        $this->assertTrue($registered['registered']);
        return $secret;
    }
    private function direct():array {
        $public=$this->enable();
        [$a,$da,$ta]=$this->member();[$b,$db,$tb]=$this->member();
        foreach([$a,$b] as $user) DB::table('orbit_user_preferences')->updateOrInsert(['user_id'=>$user->id],[
            'privacy'=>json_encode(['message_eligibility'=>'Friends','read_receipts_enabled'=>false]),
            'notifications'=>'{}','created_at'=>now(),'updated_at'=>now(),
        ]);
        $ids=[$a->id,$b->id];sort($ids,SORT_STRING);
        DB::table('friendships')->insert(['user_low_id'=>$ids[0],'user_high_id'=>$ids[1],'created_at'=>now()]);
        $this->keys($ta,$da);$peerSecret=$this->keys($tb,$db);
        $thread=$this->withToken($ta)->postJson('/api/v1/conversations/direct',['recipient_id'=>$b->id])
            ->assertOk()->json('data.id');
        return [$a,$da,$ta,$b,$db,$tb,$thread,$peerSecret,$public];
    }
    private function leasePayload(string $thread,OrbitDevice $peer):array {
        return ['scope_ref'=>$thread,'content_type'=>RelayPolicy::CONTENT,'peer_device_ref'=>$peer->id,
            'device_key_id'=>1,'protocol_version'=>RelayPolicy::PROTOCOL,'client_nonce'=>bin2hex(random_bytes(32))];
    }
    private function lease(string $token,string $thread,OrbitDevice $peer):array {
        return $this->withToken($token)->postJson('/api/v1/relay/leases',$this->leasePayload($thread,$peer))->assertOk()->json('data');
    }
    private function item(array $lease,string $thread,OrbitDevice $sender,OrbitDevice $peer,?string $secret=null):array {
        $source=['client_id'=>(string)Str::uuid(),'kind'=>'text','protocol'=>RelayPolicy::MESSAGE_PROTOCOL,'group_epoch'=>1,
            'envelopes'=>array_map(fn($device)=>['device_id'=>$device->id,'ciphertext_b64'=>base64_encode(random_bytes(64)),
                'protocol_header_b64'=>base64_encode(random_bytes(64))],[$sender,$peer])];
        $item=['lease_id'=>$lease['lease_id'],'source_client_id'=>$source['client_id'],'envelope_id'=>(string)Str::uuid(),
            'source_envelope'=>$source,'digest'=>RelayReconciliationService::digest($thread,$source['client_id'],$source)];
        if($secret!==null){
            $row=DB::table('relay_leases')->where('id',$lease['lease_id'])->first();
            $item['receipt']=['nonce'=>$item['envelope_id'],'received_at'=>now()->timestamp,
                'peer_digest'=>RelayReconciliationService::peerDigest($thread,$source['client_id'],$source,$peer->id)];
            $item['receipt']['signature_b64']=base64_encode(sodium_crypto_sign_detached(
                RelayReconciliationService::receiptInput($row,$item),$secret));
        }
        return $item;
    }
    private function batch(string $token,array $items) {
        return $this->withToken($token)->postJson('/api/v1/relay/reconcile',['batch_id'=>(string)Str::uuid(),'items'=>$items]);
    }
    public function test_signed_lease_has_bounded_scope_and_idempotent_nonce():void{
        [$a,$da,$ta,$b,$db,$tb,$thread,$secret,$public]=$this->direct();
        $this->withToken($ta)->getJson('/api/v1/relay/capabilities')->assertOk()->assertJsonPath('data.enabled',true)
            ->assertJsonPath('data.signing_key.public_ed25519',base64_encode($public));
        $p=$this->leasePayload($thread,$db);
        $lease=$this->postJson('/api/v1/relay/leases',$p)->assertOk()->json('data');
        [$body,$sig]=explode('.',$lease['lease']);
        $body=base64_decode(strtr($body,'-_','+/'));$sig=base64_decode(strtr($sig,'-_','+/'));
        $this->assertTrue(sodium_crypto_sign_verify_detached($sig,'orbit-relay-lease-v1|'.$body,$public));
        $this->assertFalse(sodium_crypto_sign_verify_detached($sig,'orbit-relay-lease-v1|'.$body.' ',$public));
        $claims=json_decode($body,true);
        $this->assertSame($da->id,$claims['sender_device']);$this->assertSame($db->id,$claims['peer_device']);
        $this->assertSame($thread,$claims['scope_ref']);
        $this->assertLessThanOrEqual(900,$claims['expires_at']-$claims['issued_at']);
        $this->assertArrayNotHasKey('session_id',$claims);
        $this->postJson('/api/v1/relay/leases',$p)->assertOk()->assertJsonPath('data.lease',$lease['lease']);
        $this->assertDatabaseCount('relay_leases',1);
        config(['orbit_relay.peer_transport_reviewed'=>false]);
        $this->getJson('/api/v1/relay/capabilities')->assertJsonPath('data.enabled',false);
        $this->postJson('/api/v1/relay/leases',$p)->assertStatus(503);
    }
    public function test_valid_receipt_reconciles_once_and_never_sets_read():void{
        [$a,$da,$ta,$b,$db,$tb,$thread,$secret]=$this->direct();
        $lease=$this->lease($ta,$thread,$db);
        $item=$this->item($lease,$thread,$da,$db,$secret);
        $response=$this->batch($ta,[$item])->assertOk()->assertJsonPath('data.results.0.status','accepted')
            ->assertJsonPath('data.results.0.peer_receipt_verified',true);
        $source=$response->json('data.results.0.source_ref');
        $this->batch($ta,[$item])->assertOk()->assertJsonPath('data.results.0.status','duplicate')
            ->assertJsonPath('data.results.0.source_ref',$source);
        $this->assertDatabaseCount('messaging_messages',1);
        $this->assertDatabaseCount('relay_reconciliations',1);
        $this->assertDatabaseCount('relay_receipts',1);
        $env=DB::table('messaging_envelopes')->where('message_id',$source)->where('recipient_device_id',$db->id)->first();
        $this->assertNotNull($env->delivered_at);$this->assertNull($env->read_at);
        $this->assertDatabaseHas('relay_daily_metrics',['outcome'=>'verified_receipt','total'=>1]);
        $this->assertDatabaseHas('relay_daily_metrics',['outcome'=>'duplicate','total'=>1]);
        $this->withToken($ta)->getJson('/api/v1/relay/sync')->assertJsonPath('data.items.0.envelope_id',$item['envelope_id']);
        $this->withToken($tb)->getJson('/api/v1/relay/sync')->assertJsonPath('data.items',[]);
    }
    public function test_forged_receipt_and_digest_mismatch_do_not_publish():void{
        [$a,$da,$ta,$b,$db,$tb,$thread,$secret]=$this->direct();
        $lease=$this->lease($ta,$thread,$db);
        $item=$this->item($lease,$thread,$da,$db,$secret);
        $item['receipt']['signature_b64']=base64_encode(random_bytes(64));
        $this->batch($ta,[$item])->assertOk()->assertJsonPath('data.results.0.status','rejected');
        $item=$this->item($lease,$thread,$da,$db);
        $item['digest']=str_repeat('0',64);
        $this->batch($ta,[$item])->assertOk()->assertJsonPath('data.results.0.reason_code','invalid_envelope');
        $this->assertDatabaseCount('messaging_messages',0);$this->assertDatabaseCount('relay_receipts',0);
        $this->assertDatabaseCount('relay_reconciliations',0);
    }
    public function test_existing_https_publication_and_relay_share_source_id():void{
        [$a,$da,$ta,$b,$db,$tb,$thread,$secret]=$this->direct();
        $lease=$this->lease($ta,$thread,$db);
        $item=$this->item($lease,$thread,$da,$db,$secret);
        // Call the same authoritative service used by the ordinary HTTPS route.
        $conversation=app(\App\Modules\Messaging\Services\MessagingConversationService::class)->get($thread,$a->id);
        [$sent,$created]=app(\App\Modules\Messaging\Services\MessagingEnvelopeService::class)
            ->send($conversation,$a->id,$da->id,$item['source_envelope']);
        $this->assertTrue($created);
        $this->batch($ta,[$item])->assertOk()->assertJsonPath('data.results.0.status','duplicate')
            ->assertJsonPath('data.results.0.source_ref',$sent['id']);
        $item['envelope_id']=(string)Str::uuid();unset($item['receipt']);
        $this->batch($ta,[$item])->assertOk()->assertJsonPath('data.results.0.reason_code','conflict_or_revoked');
        $this->assertDatabaseCount('messaging_messages',1);
    }
    public function test_expired_revoked_blocked_or_rotated_lease_cannot_publish():void{
        [$a,$da,$ta,$b,$db,$tb,$thread,$secret]=$this->direct();
        $expired=$this->lease($ta,$thread,$db);
        DB::table('relay_leases')->where('id',$expired['lease_id'])->update(['expires_at'=>now()->subSecond()]);
        $this->batch($ta,[$this->item($expired,$thread,$da,$db)])->assertOk()->assertJsonPath('data.results.0.status','expired');
        $revoked=$this->lease($ta,$thread,$db);
        $this->withToken($tb)->postJson('/api/v1/relay/leases/'.$revoked['lease_id'].'/revoke',['reason'=>'cancel'])->assertNotFound();
        $this->withToken($ta)->postJson('/api/v1/relay/leases/'.$revoked['lease_id'].'/revoke',['reason'=>'cancel'])->assertOk();
        $this->batch($ta,[$this->item($revoked,$thread,$da,$db)])->assertOk()->assertJsonPath('data.results.0.status','rejected');
        $rotated=$this->lease($ta,$thread,$db);
        $this->keys($tb,$db,8);
        $this->batch($ta,[$this->item($rotated,$thread,$da,$db)])->assertOk()->assertJsonPath('data.results.0.status','rejected');
        $blocked=$this->lease($ta,$thread,$db);
        DB::table('blocks')->insert(['blocker_id'=>$b->id,'blocked_id'=>$a->id,'created_at'=>now()]);
        $this->batch($ta,[$this->item($blocked,$thread,$da,$db)])->assertOk()->assertJsonPath('data.results.0.status','rejected');
        $this->withToken($ta)->postJson('/api/v1/relay/leases',$this->leasePayload($thread,$db))->assertNotFound();
        $this->assertDatabaseCount('messaging_messages',0);
    }
    public function test_new_session_wrong_peer_and_plaintext_are_rejected():void{
        [$a,$da,$ta,$b,$db,$tb,$thread,$secret]=$this->direct();
        $lease=$this->lease($ta,$thread,$db);
        $item=$this->item($lease,$thread,$da,$db);
        $this->batch($tb,[$item])->assertOk()->assertJsonPath('data.results.0.status','rejected');
        $fresh=app(TokenService::class)->issue($a,$da,null)['access_token'];
        $this->batch($fresh,[$item])->assertOk()->assertJsonPath('data.results.0.status','rejected');
        $item['source_envelope']['plaintext']='forbidden';
        $this->batch($ta,[$item])->assertUnprocessable();
        $this->assertDatabaseCount('messaging_messages',0);
    }
    public function test_missing_receipt_does_not_mark_delivery_and_prune_preserves_source():void{
        [$a,$da,$ta,$b,$db,$tb,$thread,$secret]=$this->direct();
        $lease=$this->lease($ta,$thread,$db);
        $item=$this->item($lease,$thread,$da,$db);
        $this->batch($ta,[$item])->assertOk()->assertJsonPath('data.results.0.peer_receipt_verified',false);
        $this->assertDatabaseCount('relay_receipts',0);
        $this->assertNull(DB::table('messaging_envelopes')->where('recipient_device_id',$db->id)->value('delivered_at'));
        DB::table('relay_leases')->where('id',$lease['lease_id'])->update(['expires_at'=>now()->subDays(8)]);
        $counts=app(RelayMetrics::class)->prune();
        $this->assertSame(1,$counts['leases']);
        $this->assertDatabaseCount('relay_reconciliations',0);
        $this->assertDatabaseCount('messaging_messages',1);
        $this->assertDatabaseCount('messaging_envelopes',2);
    }

    public function test_recipient_digest_mismatch_signed_by_real_peer_and_empty_receipt_fail():void{
        [$a,$da,$ta,$b,$db,$tb,$thread,$secret]=$this->direct();
        $lease=$this->lease($ta,$thread,$db);
        $item=$this->item($lease,$thread,$da,$db,$secret);
        $item['receipt']['peer_digest']=str_repeat('a',64);
        $row=DB::table('relay_leases')->where('id',$lease['lease_id'])->first();
        $item['receipt']['signature_b64']=base64_encode(sodium_crypto_sign_detached(
            RelayReconciliationService::receiptInput($row,$item),$secret));
        $this->batch($ta,[$item])->assertOk()->assertJsonPath('data.results.0.status','rejected');
        $item['receipt']=[];
        $this->batch($ta,[$item])->assertUnprocessable();
        $this->assertDatabaseCount('messaging_messages',0);
    }
    public function test_batch_isolates_item_failure_and_late_receipt_is_idempotent():void{
        [$a,$da,$ta,$b,$db,$tb,$thread,$secret]=$this->direct();
        $lease=$this->lease($ta,$thread,$db);
        $good=$this->item($lease,$thread,$da,$db,$secret);
        $unsigned=$good;unset($unsigned['receipt']);
        $bad=$this->item($lease,$thread,$da,$db);
        $bad['digest']=str_repeat('f',64);
        $this->batch($ta,[$bad,$unsigned])->assertOk()->assertJsonPath('data.results.0.status','rejected')
            ->assertJsonPath('data.results.1.status','accepted')->assertJsonPath('data.results.1.peer_receipt_verified',false);
        $this->batch($ta,[$good])->assertOk()->assertJsonPath('data.results.0.status','duplicate')
            ->assertJsonPath('data.results.0.peer_receipt_verified',true);
        $this->withToken($ta)->getJson('/api/v1/relay/sync')->assertJsonPath('data.items.0.peer_receipt_verified',true);
        $this->assertDatabaseCount('messaging_messages',1);$this->assertDatabaseCount('relay_receipts',1);
    }
    public function test_settings_revision_revokes_lease_without_disabling_messaging():void{
        [$a,$da,$ta,$b,$db,$tb,$thread,$secret]=$this->direct();
        $lease=$this->lease($ta,$thread,$db);
        $this->be($this->admin(['relay.read','relay.manage']),'admin');
        $this->post('/admin/relay/settings',['enabled'=>0,'lease_seconds'=>300,'batch_limit'=>10,'frame_bytes'=>16384,
            'revision'=>1,'reason'=>'Emergency Relay disable test','confirm'=>1])->assertRedirect(route('orbit.admin.relay.settings'));
        $this->assertDatabaseHas('relay_leases',['id'=>$lease['lease_id'],'reason'=>'policy_changed']);
        $this->assertTrue(config('orbit_messaging.enabled'));
        $this->withToken($ta)->getJson('/api/v1/relay/capabilities')->assertJsonPath('data.enabled',false);
        $this->batch($ta,[$this->item($lease,$thread,$da,$db)])->assertStatus(503);
        $this->assertDatabaseCount('messaging_messages',0);
    }

    public function test_relay_rate_limits_are_operation_and_device_scoped():void{
        [$a,$da,$ta,$b,$db,$tb,$thread,$secret]=$this->direct();
        $p=$this->leasePayload($thread,$db);
        for($i=0;$i<10;$i++){
            $this->withToken($ta)->postJson('/api/v1/relay/leases',$p)->assertOk();
        }
        $this->postJson('/api/v1/relay/leases',$p)->assertStatus(429);
        $this->getJson('/api/v1/relay/capabilities')->assertOk();
        $this->withToken($tb)->postJson('/api/v1/relay/leases',$this->leasePayload($thread,$da))->assertOk();
        $this->assertDatabaseCount('relay_leases',2);
    }
}
