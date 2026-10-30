<?php
namespace Tests\Feature;

use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Models\OrbitDevice;
use App\Models\OrbitUser;
use App\Modules\Config\Services\Totp;
use App\Modules\Identity\Services\TokenService;
use App\Modules\Messaging\Services\MessagingEnvelopeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrbitMessagingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'orbit_messaging.enabled'=>true,
            // Unit tests use opaque bytes solely to verify server mailbox ACL.
            // This is NOT a Signal or cryptographic protocol certification.
            'orbit_messaging.e2ee_client_verified'=>true,
            'orbit_messaging.approved_protocols'=>['ratchet.v1'],
            'orbit_messaging.per_minute_send'=>200,
            'orbit_circle.enabled'=>true,
            'orbit_circle.consumer_enabled.message'=>true,
        ]);
    }
    private function person(string $label): array
    {
        $u=OrbitUser::create(['display_name'=>$label,'username'=>strtolower(Str::random(12)),
            'status'=>'active']);
        $d=OrbitDevice::create([
            'user_id'=>$u->id,'device_public_id'=>(string)Str::uuid(),
            'name'=>'Test phone','platform'=>'android',
            'public_key'=>base64_encode(random_bytes(32)),
            'trust_state'=>'trusted','attestation_state'=>'verified','approved_at'=>now(),
        ]);
        $token=app(TokenService::class)->issue($u,$d,null)['access_token'];
        return [$u,$token,$d];
    }
    private function privacy(OrbitUser $u,string $kind,bool $read=false): void
    {
        DB::table('orbit_user_preferences')->updateOrInsert(['user_id'=>$u->id],[
            'privacy'=>json_encode(['message_eligibility'=>$kind,
                'read_receipts_enabled'=>$read]),
            'notifications'=>'{}','created_at'=>now(),'updated_at'=>now(),
        ]);
    }
    private function friend(OrbitUser $a,OrbitUser $b): void
    {
        $ids=[$a->id,$b->id];sort($ids,SORT_STRING);
        DB::table('friendships')->insert(['user_low_id'=>$ids[0],
            'user_high_id'=>$ids[1],'created_at'=>now()]);
    }
    private function keys(string $token,OrbitDevice $device,bool $oneTime=false): array
    {
        $pair=sodium_crypto_sign_keypair();
        $public=base64_encode(sodium_crypto_sign_publickey($pair));
        $secret=sodium_crypto_sign_secretkey($pair);
        $prekey=random_bytes(32);
        $signature=sodium_crypto_sign_detached(
            'orbit-m13-signed-prekey-v1|'.$device->id.'|7|'.$prekey,$secret);
        $payload=[
            'identity_ed25519'=>$public,'signed_prekey_x25519'=>base64_encode($prekey),
            'signed_prekey_signature'=>base64_encode($signature),
            'signed_prekey_id'=>7,
            'one_time_prekeys'=>$oneTime?[
                ['key_id'=>101,'public_x25519'=>base64_encode(random_bytes(32))]
            ]:[],
        ];
        $this->withToken($token)->postJson('/api/v1/me/messaging/keys',$payload)
            ->assertOk()->assertJsonPath('data.registered',true);
        return $payload;
    }
    private function envelope(OrbitDevice $device): array
    {
        return [
            'device_id'=>$device->id,
            'ciphertext_b64'=>base64_encode(random_bytes(64)),
            'protocol_header_b64'=>base64_encode(random_bytes(64)),
        ];
    }
    private function payload(array $devices,int $epoch=1,?string $client=null): array
    {
        return [
            'client_id'=>$client??(string)Str::uuid(),
            'kind'=>'text','protocol'=>'ratchet.v1','group_epoch'=>$epoch,
            'envelopes'=>array_map(fn($d)=>$this->envelope($d),$devices),
        ];
    }
    private function setupDirect(): array
    {
        [$a,$ta,$da]=$this->person('A');
        [$b,$tb,$db]=$this->person('B');
        $this->privacy($a,'Friends');
        $this->privacy($b,'Friends');
        $this->friend($a,$b);
        $this->keys($ta,$da);
        $this->keys($tb,$db);
        $thread=$this->withToken($ta)->postJson('/api/v1/conversations/direct',[
            'recipient_id'=>$b->id])->assertOk()->json('data.id');
        return [$a,$ta,$da,$b,$tb,$db,$thread];
    }

    public function test_capabilities_trusted_session_and_no_fake_e2ee_or_push(): void
    {
        $this->getJson('/api/v1/messaging/capabilities')->assertUnauthorized();
        config(['orbit_messaging.e2ee_client_verified'=>false]);
        [$a,$ta,$device]=$this->person('A');
        $this->withToken($ta)->getJson('/api/v1/messaging/capabilities')->assertOk()
            ->assertJsonPath('data.available',true)
            ->assertJsonPath('data.https_ciphertext_mailbox',true)
            ->assertJsonPath('data.e2ee_client_verified',false)
            ->assertJsonPath('data.realtime_available',false)
            ->assertJsonPath('data.push_available',false);
        config(['orbit_messaging.enabled'=>false]);
        $this->withToken($ta)->getJson('/api/v1/messaging/capabilities')
            ->assertJsonPath('data.available',false);
        $this->withToken($ta)->postJson('/api/v1/me/messaging/keys',[])
            ->assertStatus(503);
        config(['orbit_messaging.enabled'=>true]);
        $device->update(['trust_state'=>'pending']);
        $this->withToken($ta)->getJson('/api/v1/me/messaging/devices')->assertForbidden();
    }

    public function test_signed_device_key_bundle_is_verified_and_private_keys_are_rejected(): void
    {
        [$a,$ta,$da]=$this->person('A');
        $pair=sodium_crypto_sign_keypair();
        $invalid=[
            'identity_ed25519'=>base64_encode(sodium_crypto_sign_publickey($pair)),
            'signed_prekey_x25519'=>base64_encode(random_bytes(32)),
            'signed_prekey_signature'=>base64_encode(random_bytes(64)),
            'signed_prekey_id'=>7,
        ];
        $this->withToken($ta)->postJson('/api/v1/me/messaging/keys',$invalid)
            ->assertUnprocessable();
        $good=$this->keys($ta,$da,true);
        $this->withToken($ta)->getJson('/api/v1/me/messaging/devices')
            ->assertJsonPath('data.devices.0.messaging_keys_registered',true);
        $this->withToken($ta)->postJson('/api/v1/me/messaging/keys',
            array_merge($good,['private_key'=>'secret']))->assertUnprocessable();
        // No private key is persisted, returned or exposed in admin operations.
        $this->assertDatabaseCount('messaging_device_keys',1);
        $this->assertDatabaseCount('messaging_one_time_prekeys',1);
    }

    public function test_key_directory_requires_current_mutual_privacy_and_claims_once(): void
    {
        [$a,$ta,$da]=$this->person('A');
        [$b,$tb,$db]=$this->person('B');
        $this->keys($tb,$db,true);
        $this->withToken($ta)->getJson('/api/v1/messaging/keys/'.$b->id)->assertNotFound();
        $this->privacy($a,'Friends');$this->privacy($b,'Friends');$this->friend($a,$b);
        $this->withToken($ta)->getJson('/api/v1/messaging/keys/'.$b->id)->assertOk()
            ->assertJsonPath('data.devices.0.one_time_prekey.key_id',101);
        $this->withToken($ta)->getJson('/api/v1/messaging/keys/'.$b->id)->assertOk()
            ->assertJsonPath('data.devices.0.one_time_prekey',null);
        DB::table('blocks')->insert([
            'blocker_id'=>$b->id,'blocked_id'=>$a->id,'created_at'=>now(),
        ]);
        $this->withToken($ta)->getJson('/api/v1/messaging/keys/'.$b->id)->assertNotFound();
        $this->withToken($tb)->getJson('/api/v1/messaging/keys/'.$a->id)->assertNotFound();
    }

    public function test_claiming_one_device_prekey_preserves_other_trusted_devices(): void
    {
        [$a,$ta,$da]=$this->person('Sender');
        [$b,$tb,$db]=$this->person('Multi Device Recipient');
        $this->privacy($a,'Friends');
        $this->privacy($b,'Friends');
        $this->friend($a,$b);
        $this->keys($tb,$db,true);

        $second=OrbitDevice::create([
            'user_id'=>$b->id,'device_public_id'=>(string)Str::uuid(),
            'name'=>'Second trusted phone','platform'=>'android',
            'public_key'=>base64_encode(random_bytes(32)),
            'trust_state'=>'trusted','attestation_state'=>'verified',
            'approved_at'=>now(),
        ]);
        $secondToken=app(TokenService::class)->issue($b,$second,null)
            ['access_token'];
        $this->keys($secondToken,$second,true);
        $path='/api/v1/messaging/keys/'.$b->id;

        $result=$this->withToken($ta)->getJson($path.
            '?claim_one_time_prekey=1&device_id='.$db->id)
            ->assertOk()->json('data.devices');
        $primary=collect($result)->firstWhere('device_id',$db->id);
        $other=collect($result)->firstWhere('device_id',$second->id);
        $this->assertSame(101,$primary['one_time_prekey']['key_id']);
        $this->assertNull($other['one_time_prekey']);
        $this->assertDatabaseHas('messaging_one_time_prekeys',[
            'device_id'=>$second->id,'key_id'=>101,'claimed_at'=>null,
        ]);

        $this->withToken($ta)->getJson($path.
            '?claim_one_time_prekey=1&device_id='.$second->id)->assertOk();
        $this->assertEquals(1,DB::table('messaging_one_time_prekeys')
            ->where('device_id',$second->id)
            ->whereNotNull('claimed_at')->count());
        $this->withToken($ta)->getJson($path.
            '?claim_one_time_prekey=1&device_id='.(string)Str::uuid())
            ->assertNotFound();
    }

    public function test_read_only_sender_identity_lookup_does_not_consume_one_time_prekeys(): void
    {
        [$a,$ta,$da]=$this->person('A');
        [$b,$tb,$db]=$this->person('B');
        $this->privacy($a,'Friends');
        $this->privacy($b,'Friends');
        $this->friend($a,$b);
        $this->keys($tb,$db,true);
        $path='/api/v1/messaging/keys/'.$b->id;
        $this->withToken($ta)->getJson($path.'?claim_one_time_prekey=0')->assertOk()
            ->assertJsonPath('data.devices.0.one_time_prekey',null);
        $this->assertDatabaseHas('messaging_one_time_prekeys',[
            'device_id'=>$db->id,'claimed_at'=>null,
        ]);
        $this->withToken($ta)->getJson($path)->assertOk()
            ->assertJsonPath('data.devices.0.one_time_prekey.key_id',101);
        $this->withToken($ta)->getJson($path.'?claim_one_time_prekey=0')->assertOk()
            ->assertJsonPath('data.devices.0.one_time_prekey',null);
    }

    public function test_direct_send_requires_exact_device_envelopes_is_idempotent_and_never_plaintext(): void
    {
        [$a,$ta,$da,$b,$tb,$db,$thread]=$this->setupDirect();
        $same=$this->withToken($ta)->postJson('/api/v1/conversations/direct',[
            'recipient_id'=>$b->id])->assertOk()->json('data.id');
        $this->assertSame($thread,$same);
        $client=(string)Str::uuid();
        $payload=$this->payload([$da,$db],1,$client);
        config(['orbit_messaging.e2ee_client_verified'=>false]);
        $this->withToken($ta)->postJson('/api/v1/conversations/'.$thread.'/envelopes',
            $payload)->assertStatus(503);
        config(['orbit_messaging.e2ee_client_verified'=>true]);
        $this->withToken($ta)->postJson('/api/v1/conversations/'.$thread.'/envelopes',
            array_merge($payload,['protocol'=>'unverified.v99']))->assertUnprocessable();
        $this->withToken($ta)->postJson('/api/v1/conversations/'.$thread.'/envelopes',
            array_merge($payload,['text'=>'secret']))->assertUnprocessable();
        $this->withToken($ta)->postJson('/api/v1/conversations/'.$thread.'/envelopes',
            array_merge($payload,['kind'=>'system']))->assertUnprocessable();
        $this->withToken($ta)->postJson('/api/v1/conversations/'.$thread.'/envelopes',
            $this->payload([$da],1))->assertStatus(409);
        $sent=$this->withToken($ta)->postJson('/api/v1/conversations/'.$thread.'/envelopes',
            $payload)->assertCreated()->assertJsonPath('data.accepted',true)
            ->assertJsonPath('data.delivered',false)->json('data.id');
        $this->withToken($ta)->postJson('/api/v1/conversations/'.$thread.'/envelopes',
            $payload)->assertOk()->assertJsonPath('data.id',$sent);
        $this->withToken($ta)->postJson('/api/v1/conversations/'.$thread.'/envelopes',
            $this->payload([$da,$db],1,$client))->assertStatus(409);
        $this->assertDatabaseCount('messaging_messages',1);
        $this->assertDatabaseCount('messaging_envelopes',2);
        $this->withToken($tb)->getJson('/api/v1/conversations/'.$thread.'/envelopes')
            ->assertOk()->assertJsonCount(1,'data.items')
            ->assertJsonPath('data.ciphertext_only',true)->assertDontSee('secret');
    }

    public function test_approved_recipient_without_keys_is_not_silently_dropped(): void
    {
        [$a,$ta,$da]=$this->person('A');
        [$b,$tb,$db]=$this->person('B');
        $this->privacy($a,'Friends');
        $this->privacy($b,'Friends');
        $this->friend($a,$b);
        $this->keys($ta,$da);
        $thread=$this->withToken($ta)->postJson('/api/v1/conversations/direct',[
            'recipient_id'=>$b->id])->assertOk()->json('data.id');
        $this->withToken($ta)->postJson('/api/v1/conversations/'.$thread.'/envelopes',
            $this->payload([$da]))->assertStatus(409);
        $this->keys($tb,$db);
        $this->withToken($ta)->postJson('/api/v1/conversations/'.$thread.'/envelopes',
            $this->payload([$da,$db]))->assertCreated();
        $this->assertDatabaseHas('messaging_envelopes',[
            'recipient_device_id'=>$db->id,'recipient_key_version'=>1,
        ]);
        // Another approved, unregistered device must not be silently missed.
        [$unused,$otherToken,$unusedDevice]=$this->person('Other');
        $newDevice=OrbitDevice::create([
            'user_id'=>$b->id,'device_public_id'=>(string)Str::uuid(),
            'name'=>'Second approved phone','platform'=>'android',
            'public_key'=>base64_encode(random_bytes(32)),
            'trust_state'=>'trusted','attestation_state'=>'verified','approved_at'=>now(),
        ]);
        $this->withToken($ta)->postJson('/api/v1/conversations/'.$thread.'/envelopes',
            $this->payload([$da,$db]))->assertStatus(409);
    }

    public function test_receipts_require_specific_recipient_device_dwell_and_privacy(): void
    {
        [$a,$ta,$da,$b,$tb,$db,$thread]=$this->setupDirect();
        $msg=$this->withToken($ta)->postJson('/api/v1/conversations/'.$thread.'/envelopes',
            $this->payload([$da,$db]))->assertCreated()->json('data.id');
        $env=DB::table('messaging_envelopes')->where('message_id',$msg)
            ->where('recipient_device_id',$db->id)->value('id');
        $this->withToken($ta)->postJson(
            '/api/v1/conversations/'.$thread.'/envelopes/'.$env.'/delivered',[])
            ->assertNotFound();
        $this->withToken($tb)->postJson(
            '/api/v1/conversations/'.$thread.'/envelopes/'.$env.'/delivered',[])
            ->assertOk()->assertJsonPath('data.delivered',true);
        $this->withToken($tb)->postJson(
            '/api/v1/conversations/'.$thread.'/envelopes/'.$env.'/read',
            ['visible_dwell_ms'=>600])->assertForbidden();
        $this->privacy($b,'Friends',true);
        $this->withToken($tb)->postJson(
            '/api/v1/conversations/'.$thread.'/envelopes/'.$env.'/read',
            ['visible_dwell_ms'=>599])->assertUnprocessable();
        $this->withToken($tb)->postJson(
            '/api/v1/conversations/'.$thread.'/envelopes/'.$env.'/read',
            ['visible_dwell_ms'=>600])->assertOk()->assertJsonPath('data.read',true);
        $this->withToken($ta)->getJson(
            '/api/v1/conversations/'.$thread.'/messages/'.$msg.'/receipts')
            ->assertOk()->assertJsonPath('data.delivered_devices',1)
            ->assertJsonPath('data.read_users',1);
    }

    public function test_circle_requires_individual_consent_and_rotates_epoch_after_membership_change(): void
    {
        [$a,$ta,$da]=$this->person('A');
        [$b,$tb,$db]=$this->person('B');
        $id=(string)Str::uuid();
        DB::table('circles')->insert(['id'=>$id,'owner_id'=>$a->id,
            'name'=>'Private Circle','revision'=>1,'created_at'=>now(),'updated_at'=>now()]);
        foreach([$a,$b] as $user)DB::table('circle_members')->insert([
            'circle_id'=>$id,'user_id'=>$user->id,'role'=>'member',
            'joined_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
        ]);
        $this->privacy($a,'My Circle');$this->privacy($b,'My Circle');
        $this->keys($ta,$da);$this->keys($tb,$db);
        $this->withToken($ta)->getJson('/api/v1/circles/'.$id.'/messages')
            ->assertNotFound();
        foreach([[$ta,$a],[$tb,$b]] as [$token,$member]){
            $this->withToken($token)->putJson('/api/v1/me/messaging/circles/'.$id.'/consent',
                ['enabled'=>true,'confirm'=>true])->assertOk()
                ->assertJsonPath('data.enabled',true);
        }
        $info=$this->withToken($ta)->getJson('/api/v1/circles/'.$id.'/messages/conversation')
            ->assertOk()->json('data');
        $msg=$this->withToken($ta)->postJson('/api/v1/circles/'.$id.'/messages',
            $this->payload([$da,$db],$info['group_epoch']))->assertCreated()
            ->json('data.id');
        // Global Ghost Mode suppresses typing but must not block ciphertext.
        $this->withToken($ta)->postJson('/api/v1/conversations/'.$info['id'].'/typing',[
            'active'=>true,
        ])->assertForbidden();
        $this->withToken($ta)->putJson('/api/v1/me/messaging/circles/'.$id.'/typing',[
            'enabled'=>false,
        ])->assertOk()->assertJsonPath('data.message_consent_unchanged',true);
        $this->withToken($tb)->getJson('/api/v1/circles/'.$id.'/messages')
            ->assertOk()->assertJsonCount(1,'data.items');
        DB::table('circle_members')->where('circle_id',$id)
            ->where('user_id',$b->id)->update(['role'=>'restricted']);
        $this->withToken($ta)->postJson('/api/v1/circles/'.$id.'/messages',
            $this->payload([$da,$db],$info['group_epoch']))->assertStatus(409);
        $this->withToken($tb)->getJson('/api/v1/circles/'.$id.'/messages')
            ->assertNotFound();
        $this->withToken($tb)->getJson('/api/v1/conversations/'.$info['id'].'/envelopes')
            ->assertNotFound();
        $this->withToken($ta)->putJson('/api/v1/me/messaging/circles/'.$id.'/consent',
            ['enabled'=>false])->assertOk()->assertJsonPath('data.enabled',false);
        $this->withToken($ta)->getJson('/api/v1/circles/'.$id.'/messages')
            ->assertNotFound();
    }

    public function test_sync_reporting_redaction_and_revoked_device_are_private(): void
    {
        [$a,$ta,$da,$b,$tb,$db,$thread]=$this->setupDirect();
        $msg=$this->withToken($ta)->postJson('/api/v1/conversations/'.$thread.'/envelopes',
            $this->payload([$da,$db]))->assertCreated()->json('data.id');
        $envelope=DB::table('messaging_envelopes')->where('message_id',$msg)
            ->where('recipient_device_id',$db->id)->value('id');
        $this->withToken($tb)->postJson('/api/v1/conversations/'.$thread.'/sync',[
            'delivered_envelope_ids'=>[$envelope]])->assertOk()->assertJsonCount(1,'data.items');
        $this->withToken($tb)->postJson('/api/v1/conversations/'.$thread.'/reports',[
            'message_id'=>$msg,'reason'=>'spam',
            'evidence_text'=>'Do not silently send plaintext!',
        ])->assertUnprocessable();
        $this->withToken($tb)->postJson('/api/v1/conversations/'.$thread.'/reports',[
            'message_id'=>$msg,'reason'=>'spam',
        ])->assertCreated()->assertJsonPath('data.private_message_content_shared',false);
        $this->withToken($tb)->deleteJson('/api/v1/conversations/'.$thread.'/messages/'.$msg)
            ->assertNotFound();
        $this->withToken($ta)->deleteJson('/api/v1/conversations/'.$thread.'/messages/'.$msg)
            ->assertOk()->assertJsonPath('data.redacted',true);
        $this->assertDatabaseCount('messaging_envelopes',0);
        $db->update(['revoked_at'=>now()]);
        // Revocation invalidates the bearer session at the identity middleware.
        $this->withToken($tb)->getJson('/api/v1/conversations/'.$thread.'/envelopes')
            ->assertUnauthorized();
    }

    public function test_prune_removes_expired_ciphertext_without_exposing_content(): void
    {
        [$a,$ta,$da,$b,$tb,$db,$thread]=$this->setupDirect();
        $this->withToken($ta)->postJson('/api/v1/conversations/'.$thread.'/envelopes',
            $this->payload([$da,$db]))->assertCreated();
        DB::table('messaging_messages')->update(['expires_at'=>now()->subMinute()]);
        $this->assertSame(1,app(MessagingEnvelopeService::class)->prune());
        $this->assertDatabaseCount('messaging_messages',0);
        $this->assertDatabaseCount('messaging_envelopes',0);
    }

    public function test_https_typing_is_ephemeral_throttled_and_permission_checked(): void
    {
        [$a,$ta,$da,$b,$tb,$db,$thread]=$this->setupDirect();
        $this->withToken($ta)->postJson('/api/v1/conversations/'.$thread.'/typing',[
            'active'=>true,
        ])->assertOk()->assertJsonPath('data.accepted',true)
            ->assertJsonPath('data.expires_in',5);
        $this->withToken($tb)->getJson('/api/v1/conversations/'.$thread.'/typing')
            ->assertOk()->assertJsonPath('data.typing_user_ids.0',$a->id)
            ->assertJsonPath('data.transport','foreground_https');
        $this->withToken($ta)->postJson('/api/v1/conversations/'.$thread.'/typing',[
            'active'=>true,
        ])->assertOk()->assertJsonPath('data.accepted',false);
        $this->withToken($ta)->postJson('/api/v1/conversations/'.$thread.'/typing',[
            'active'=>false,
        ])->assertOk()->assertJsonPath('data.expires_in',0);
        $this->withToken($tb)->getJson('/api/v1/conversations/'.$thread.'/typing')
            ->assertOk()->assertJsonCount(0,'data.typing_user_ids');
        $this->privacy($a,'Nobody');
        $this->withToken($tb)->getJson('/api/v1/conversations/'.$thread.'/typing')
            ->assertNotFound();
    }

    public function test_user_shared_report_evidence_is_encrypted_and_moderator_only(): void
    {
        [$a,$ta,$da,$b,$tb,$db,$thread]=$this->setupDirect();
        $msg=$this->withToken($ta)->postJson('/api/v1/conversations/'.$thread.'/envelopes',
            $this->payload([$da,$db]))->assertCreated()->json('data.id');
        $evidence='Reporter selected this message as abusive.';
        $endpoint='/api/v1/conversations/'.$thread.'/reports';
        $data=[
            'message_id'=>$msg,'reason'=>'abuse',
            'share_evidence'=>true,'evidence_text'=>$evidence,
        ];
        $this->withToken($tb)->postJson($endpoint,$data)->assertUnprocessable();
        $data['confirm_evidence_share']=true;
        $report=$this->withToken($tb)->postJson($endpoint,$data)->assertCreated()
            ->assertJsonPath('data.private_message_content_shared',true)
            ->assertDontSee($evidence)->json('data.report_id');
        $row=DB::table('messaging_reports')->where('id',$report)->first();
        $this->assertNotEquals($evidence,$row->evidence_enc);
        $this->assertTrue((bool)$row->evidence_shared);
        $role=AdminRole::create(['name'=>'Restricted evidence reviewer',
            'permissions'=>['config.manage']]);
        $admin=AdminUser::create([
            'name'=>'Evidence reviewer','email'=>'evidence@example.test',
            'password'=>'StrongPassword123!',
            'totp_secret'=>(new Totp())->generateSecret(),'status'=>'active',
        ]);
        $admin->roles()->attach($role->id);
        $this->actingAs($admin,'admin')->get('/admin/messaging')
            ->assertOk()->assertDontSee($evidence);
        $this->actingAs($admin,'admin')->post('/admin/messaging/reports/'.$report.'/evidence',
            ['confirm'=>true])->assertForbidden();
        $role->update(['permissions'=>['messages.moderate']]);
        $this->actingAs($admin,'admin')->get('/admin/messaging/reports')
            ->assertOk()->assertSee('User Messaging Reports')->assertDontSee($evidence);
        $this->actingAs($admin,'admin')->post('/admin/messaging/reports/'.$report.'/evidence',
            ['confirm'=>true])->assertOk()->assertSee($evidence);
        $this->assertDatabaseHas('messaging_report_audits',[
            'report_id'=>$report,'action'=>'view_user_evidence','admin_user_id'=>$admin->id,
        ]);
        $this->actingAs($admin,'admin')->post('/admin/messaging/reports/'.$report.'/action',
            ['action'=>'reviewed'])->assertRedirect();
        $this->assertDatabaseHas('messaging_reports',[
            'id'=>$report,'status'=>'reviewed','reviewed_by'=>$admin->id,
        ]);
    }

    public function test_envelope_pagination_prefereces_and_privacy_redaction(): void
    {
        [$a,$ta,$da,$b,$tb,$db,$thread]=$this->setupDirect();
        for($i=0;$i<3;$i++){
            $this->withToken($ta)->postJson('/api/v1/conversations/'.$thread.'/envelopes',
                $this->payload([$da,$db]))->assertCreated();
        }
        $page1=$this->withToken($tb)->getJson('/api/v1/conversations/'.$thread.
            '/envelopes?limit=1')->assertOk()->assertJsonCount(1,'data.items')->json('data');
        $this->assertNotNull($page1['next_cursor']);
        $page2=$this->withToken($tb)->getJson('/api/v1/conversations/'.$thread.
            '/envelopes?limit=1&cursor='.urlencode($page1['next_cursor']))
            ->assertOk()->assertJsonCount(1,'data.items')->json('data');
        $this->assertNotEquals($page1['items'][0]['message_id'],
            $page2['items'][0]['message_id']);
        $this->withToken($tb)->putJson('/api/v1/conversations/'.$thread.'/preferences',[
            'mute'=>true])->assertOk()->assertJsonPath('data.muted',true);
        $this->withToken($tb)->putJson('/api/v1/conversations/'.$thread.'/preferences',[
            'archive'=>true])->assertOk()->assertJsonPath('data.muted',true)
            ->assertJsonPath('data.archived',true);
        $this->withToken($tb)->postJson('/api/v1/conversations/'.$thread.'/leave')
            ->assertOk()->assertJsonPath('data.archived',true)
            ->assertJsonPath('data.left_circle',false);
        $this->privacy($b,'Nobody');
        $this->withToken($tb)->getJson('/api/v1/conversations/'.$thread.'/envelopes')
            ->assertNotFound();
    }

    public function test_message_media_recipient_keys_require_mutual_message_privacy(): void
    {
        [$a,$ta,$da,$b,$tb,$db,$thread]=$this->setupDirect();
        $route='/api/v1/media/recipient-keys';
        $input=[
            'purpose'=>'message','audience_kind'=>'selected_friends',
            'recipient_ids'=>[$b->id],
        ];
        $this->withToken($ta)->postJson($route,$input)->assertOk()
            ->assertJsonPath('data.recipients.0.recipient_id',$b->id)
            ->assertJsonPath('data.recipients.0.devices.0.device_id',$db->id);
        $this->privacy($b,'Nobody');
        $this->withToken($ta)->postJson($route,$input)->assertNotFound();
        // The media endpoint may never override explicit M13 denial merely
        // because the underlying social friendship still exists.
        $this->withToken($tb)->getJson('/api/v1/conversations/'.$thread.'/envelopes')
            ->assertNotFound();
    }

    public function test_admin_rbac_and_aggregate_only_review(): void
    {
        [$a,$ta,$da,$b,$tb,$db,$thread]=$this->setupDirect();
        $this->withToken($ta)->postJson('/api/v1/conversations/'.$thread.'/envelopes',
            $this->payload([$da,$db]))->assertCreated();
        $role=AdminRole::create(['name'=>'Messaging operations','permissions'=>['config.manage']]);
        $admin=AdminUser::create(['name'=>'Ops','email'=>'messaging@example.test',
            'password'=>'StrongPassword123!',
            'totp_secret'=>(new Totp())->generateSecret(),'status'=>'active']);
        $admin->roles()->attach($role->id);
        $this->actingAs($admin,'admin')->get('/admin/messaging')->assertOk()
            ->assertSee('Messaging Operations')->assertSee('Accepted encrypted sends')
            ->assertDontSee('ciphertext_b64')->assertDontSee('secret_key');
        $role->update(['permissions'=>['users.read']]);
        $this->actingAs($admin,'admin')->get('/admin/messaging')->assertForbidden();
    }
    public function test_redacted_envelopes_sync_opaque_device_scoped_deletion_receipts(): void
    {
        [$a,$ta,$da,$b,$tb,$db,$thread]=$this->setupDirect();
        $id=$this->withToken($ta)->postJson(
            '/api/v1/conversations/'.$thread.'/envelopes',
            $this->payload([$da,$db])
        )->assertCreated()->json('data.id');

        $this->withToken($tb)->getJson(
            '/api/v1/conversations/'.$thread.'/envelopes'
        )->assertOk()->assertJsonPath('data.items.0.message_id',$id);

        $this->withToken($ta)->deleteJson(
            '/api/v1/conversations/'.$thread.'/messages/'.$id
        )->assertOk()->assertJsonPath('data.redacted',true);

        $this->assertDatabaseHas('messaging_redactions',[
            'conversation_id'=>$thread,
            'message_id'=>$id,
            'recipient_device_id'=>$db->id,
        ]);
        $this->withToken($tb)->postJson(
            '/api/v1/conversations/'.$thread.'/sync',['limit'=>50]
        )->assertOk()->assertJsonCount(0,'data.items')
            ->assertJsonPath('data.redacted_message_ids.0',$id)
            ->assertDontSee('ciphertext_b64');

        // Revoked privacy denies the whole conversation, including tombstones.
        $this->privacy($b,'Nobody');
        $this->withToken($tb)->getJson(
            '/api/v1/conversations/'.$thread.'/envelopes'
        )->assertNotFound();
    }

    public function test_circle_sender_key_distribution_is_ciphertext_only_epoch_bound_and_idempotent(): void
    {
        // Focus on sender-key authorization and epoch revocation. These
        // repeated negative/positive calls must not accidentally consume
        // the unrelated shared HTTP throttle budget across feature tests.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        [$a,$ta,$da]=$this->person('Circle A');
        [$b,$tb,$db]=$this->person('Circle B');
        $circle=(string)Str::uuid();
        DB::table('circles')->insert([
            'id'=>$circle,'owner_id'=>$a->id,'name'=>'Sender Keys',
            'revision'=>1,'created_at'=>now(),'updated_at'=>now(),
        ]);
        foreach([$a,$b] as $user){
            DB::table('circle_members')->insert([
                'circle_id'=>$circle,'user_id'=>$user->id,'role'=>'member',
                'joined_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
            ]);
        }
        $this->privacy($a,'My Circle');
        $this->privacy($b,'My Circle');
        $this->keys($ta,$da);
        $this->keys($tb,$db);
        foreach([[$ta,$a],[$tb,$b]] as [$token,$member]){
            $this->withToken($token)->putJson(
                '/api/v1/me/messaging/circles/'.$circle.'/consent',
                ['enabled'=>true,'confirm'=>true]
            )->assertOk()->assertJsonPath('data.enabled',true);
        }
        $info=$this->withToken($ta)->getJson(
            '/api/v1/circles/'.$circle.'/messages/conversation'
        )->assertOk()->json('data');
        $route='/api/v1/conversations/'.$info['id'].'/sender-keys';
        $request=[
            'client_id'=>(string)Str::uuid(),
            'protocol'=>'ratchet.v1','group_epoch'=>$info['group_epoch'],
            'envelopes'=>[$this->envelope($da),$this->envelope($db)],
        ];
        config(['orbit_messaging.e2ee_client_verified'=>false]);
        $this->withToken($ta)->postJson($route,$request)->assertStatus(503);
        config(['orbit_messaging.e2ee_client_verified'=>true]);
        $this->withToken($ta)->postJson($route,
            array_merge($request,['protocol'=>'not-approved']))
            ->assertUnprocessable();
        $this->withToken($ta)->postJson($route,
            array_merge($request,['envelopes'=>[$this->envelope($da)]]))
            ->assertStatus(409);
        $sent=$this->withToken($ta)->postJson($route,$request)
            ->assertCreated()->assertJsonPath('data.stored',true)
            ->assertJsonPath('data.recipient_delivered',false)
            ->json('data.id');
        $this->withToken($ta)->postJson($route,$request)
            ->assertOk()->assertJsonPath('data.id',$sent);
        $this->assertDatabaseCount('messaging_sender_key_batches',1);
        $this->assertDatabaseCount('messaging_sender_key_envelopes',2);
        $this->withToken($tb)->getJson($route)->assertOk()
            ->assertJsonCount(1,'data.items')
            ->assertJsonPath('data.items.0.sender_device_id',$da->id)
            ->assertJsonPath('data.group_epoch',$info['group_epoch']);

        $this->withToken($ta)->postJson($route,[
            'client_id'=>(string)Str::uuid(),
            'protocol'=>'ratchet.v1','group_epoch'=>$info['group_epoch'],
            'envelopes'=>[$this->envelope($da),$this->envelope($db)],
        ])->assertCreated();
        $page1=$this->withToken($tb)->getJson($route.'?limit=1')
            ->assertOk()->assertJsonCount(1,'data.items')->json('data');
        $this->assertNotNull($page1['next_cursor']);
        $page2=$this->withToken($tb)->getJson($route.'?limit=1&cursor='.
            rawurlencode($page1['next_cursor']))->assertOk()
            ->assertJsonCount(1,'data.items')->json('data');
        $this->assertNotEquals($page1['items'][0]['id'],$page2['items'][0]['id']);
        $this->withToken($tb)->getJson($route.'?cursor=invalid')
            ->assertUnprocessable();

        // A roster change prevents old-epoch material from being replayed.
        DB::table('circle_members')->where('circle_id',$circle)
            ->where('user_id',$b->id)->update(['role'=>'restricted']);
        $this->withToken($tb)->getJson($route)->assertNotFound();
        $this->withToken($ta)->postJson($route,
            array_merge($request,['client_id'=>(string)Str::uuid()]))
            ->assertStatus(409);
        $this->withToken($ta)->getJson($route)->assertOk()
            ->assertJsonCount(0,'data.items');
    }

    public function test_encrypted_mailbox_and_sync_follow_the_authenticated_older_cursor(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        [$a,$ta,$da,$b,$tb,$db,$thread]=$this->setupDirect();
        $route='/api/v1/conversations/'.$thread;
        for($i=0;$i<4;$i++){
            $this->withToken($ta)->postJson($route.'/envelopes',
                $this->payload([$da,$db]))->assertCreated();
        }
        $page1=$this->withToken($tb)->getJson(
            $route.'/envelopes?limit=2')->assertOk()
            ->assertJsonCount(2,'data.items')->json('data');
        $this->assertNotNull($page1['next_cursor']);
        $page2=$this->withToken($tb)->getJson(
            $route.'/envelopes?limit=2&cursor='.
                rawurlencode($page1['next_cursor']))
            ->assertOk()->assertJsonCount(2,'data.items')->json('data');
        $ids1=array_column($page1['items'],'id');
        $ids2=array_column($page2['items'],'id');
        $this->assertCount(0,array_intersect($ids1,$ids2));
        $this->withToken($tb)->postJson($route.'/sync',[
            'limit'=>2,'cursor'=>$page1['next_cursor'],
        ])->assertOk()->assertJsonPath('data.items.0.id',$ids2[0]);
        $this->withToken($tb)->getJson(
            $route.'/envelopes?cursor=invalid')->assertUnprocessable();
        $this->withToken($tb)->postJson($route.'/sync',[
            'limit'=>2,'cursor'=>'invalid',
        ])->assertUnprocessable();
    }

    public function test_megolm_message_requires_current_sender_key_distribution_and_denies_old_epoch_retry(): void
    {
        [$a,$ta,$da]=$this->person('Alice Megolm');
        [$b,$tb,$db]=$this->person('Bob Megolm');
        $circle=(string)Str::uuid();
        DB::table('circles')->insert([
            'id'=>$circle,'owner_id'=>$a->id,'name'=>'Encrypted Circle',
            'revision'=>1,'created_at'=>now(),'updated_at'=>now(),
        ]);
        foreach([$a,$b] as $member){
            DB::table('circle_members')->insert([
                'circle_id'=>$circle,'user_id'=>$member->id,'role'=>'member',
                'joined_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
            ]);
        }
        $this->privacy($a,'My Circle');
        $this->privacy($b,'My Circle');
        $this->keys($ta,$da);
        $this->keys($tb,$db);
        foreach([[$ta,$a],[$tb,$b]] as [$token,$member]){
            $this->withToken($token)->putJson(
                '/api/v1/me/messaging/circles/'.$circle.'/consent',
                ['enabled'=>true,'confirm'=>true]
            )->assertOk();
        }
        $thread=$this->withToken($ta)->getJson(
            '/api/v1/circles/'.$circle.'/messages/conversation'
        )->assertOk()->json('data');
        config([
            'orbit_messaging.e2ee_client_verified'=>true,
            'orbit_messaging.approved_protocols'=>['orbit-m13-olm-megolm-v1'],
        ]);
        $protocol='orbit-m13-olm-megolm-v1';
        $message=array_merge(
            $this->payload([$da,$db],$thread['group_epoch']),
            ['protocol'=>$protocol]
        );
        $messageRoute='/api/v1/circles/'.$circle.'/messages';
        $distribution='/api/v1/conversations/'.$thread['id'].'/sender-keys';
        $this->withToken($ta)->postJson($messageRoute,$message)
            ->assertStatus(409);
        $this->withToken($ta)->postJson($distribution,[
            'client_id'=>(string)Str::uuid(),'protocol'=>$protocol,
            'group_epoch'=>$thread['group_epoch'],
            'envelopes'=>[$this->envelope($da),$this->envelope($db)],
        ])->assertCreated()->assertJsonPath('data.ciphertext_only',true);
        $this->withToken($ta)->postJson($messageRoute,$message)
            ->assertCreated();
        $this->withToken($ta)->postJson($messageRoute,$message)
            ->assertOk();
        DB::table('circle_members')->where('circle_id',$circle)
            ->where('user_id',$b->id)->update(['role'=>'restricted']);
        // The same request ID cannot replay a previously authorized group
        // envelope after the Circle group epoch is rotated.
        $this->withToken($ta)->postJson($messageRoute,$message)
            ->assertStatus(409);
        $this->withToken($tb)->getJson($distribution)
            ->assertNotFound();
    }

}
